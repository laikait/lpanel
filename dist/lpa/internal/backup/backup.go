// Package backup backs accounts up with restic, to destinations the
// administrator sets up: a local folder, an SFTP server, any S3-compatible
// store (AWS, Wasabi, Backblaze's S3 API, MinIO…) or Backblaze B2.
//
// The agent alone holds what opens a destination: its credentials and the
// repository's password (random, made when the destination is added) live
// in Dir (/etc/lpa/backup, 0700 root). The panel sends a destination's
// secrets once and keeps only what it shows; it never reads them back.
//
// A run backs each account up in turn, as one snapshot tagged with the
// account: its home (files, mail, logs; not tmp/), a dump of each of its
// databases and the panel's description of the account (lpanel.json, for a
// restore on another server), staged in StageDir and removed afterwards.
// Old snapshots are forgotten per account by the retention the panel sends.
package backup

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/ssh"

	"lpa/internal/protocol"
)

// Error codes the panel shows.
const (
	CodeOff           = "backup_off"            // restic is not installed
	CodeNoDestination = "backup_no_destination" // no such destination
	CodeUnreachable   = "backup_unreachable"    // the destination did not answer, or refused the credentials
	CodeWrongPassword = "backup_wrong_password" // a repository is there, but not ours
	CodeBusy          = "backup_busy"           // another run is going on
)

// Destination types.
const (
	Local = "local"
	SFTP  = "sftp"
	S3    = "s3"
	B2    = "b2"
)

var (
	idRule       = regexp.MustCompile(`^d[0-9]{1,9}$`)
	userRule     = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)
	hostRule     = regexp.MustCompile(`^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$`)
	loginRule    = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}$`)
	bucketRule   = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$`)
	b2BucketRule = regexp.MustCompile(`^[A-Za-z0-9-]{6,50}$`)
	regionRule   = regexp.MustCompile(`^[a-z0-9-]{0,40}$`)
	prefixRule   = regexp.MustCompile(`^[A-Za-z0-9._/-]{0,200}$`)
	secretRule   = regexp.MustCompile(`^[\x21-\x7e]{1,256}$`)
	dbRule       = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}_[a-z0-9_]{1,48}$`)
)

// Destination is where backups go. Secrets are taken when the panel sends
// them and kept by the agent; Settings answers never carry them.
type Destination struct {
	ID       string `json:"id"`
	Type     string `json:"type"`
	Path     string `json:"path,omitempty"`     // local: a folder; sftp: a folder on the server
	Host     string `json:"host,omitempty"`     // sftp
	Port     int    `json:"port,omitempty"`     // sftp
	User     string `json:"user,omitempty"`     // sftp
	Endpoint string `json:"endpoint,omitempty"` // s3: host[:port]
	Scheme   string `json:"scheme,omitempty"`   // s3: https (default) or http
	Region   string `json:"region,omitempty"`   // s3
	Bucket   string `json:"bucket,omitempty"`   // s3, b2
	Prefix   string `json:"prefix,omitempty"`   // s3, b2: a folder in the bucket
	// AccessKey/SecretKey are S3's; for B2 they are the key id and key.
	AccessKey string `json:"access_key,omitempty"`
	SecretKey string `json:"secret_key,omitempty"`
}

func bad(format string, a ...any) error {
	return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf(format, a...))
}

func cleanPath(p string) (string, bool) {
	if !strings.HasPrefix(p, "/") || strings.ContainsAny(p, " \t\n\r'\"\\:") || len(p) > 400 {
		return "", false
	}
	for _, part := range strings.Split(p, "/") {
		if part == ".." || part == "." {
			return "", false
		}
	}
	c := filepath.Clean(p)
	return c, c != "/"
}

// check validates a destination; secrets may be missing when they are
// kept from before (has says so).
func (d *Destination) check(has func(field string) bool) error {
	if !idRule.MatchString(d.ID) {
		return bad("the destination id is not one")
	}
	need := func(field, value string) error {
		if value == "" && !has(field) {
			return bad("%s is needed", field)
		}
		if value != "" && !secretRule.MatchString(value) {
			return bad("%s has characters it may not have", field)
		}
		return nil
	}
	switch d.Type {
	case Local:
		p, ok := cleanPath(d.Path)
		if !ok {
			return bad("the folder must be an absolute path, not /, without spaces or ..")
		}
		for _, inside := range []string{"/home", "/etc", "/proc", "/sys", "/dev", "/usr", "/var/lib/mysql", "/run", "/boot"} {
			if p == inside || strings.HasPrefix(p+"/", inside+"/") {
				return bad("backups cannot go in %s", inside)
			}
		}
		d.Path = p
	case SFTP:
		p, ok := cleanPath(d.Path)
		if !ok || !hostRule.MatchString(d.Host) || !loginRule.MatchString(d.User) {
			return bad("an SFTP destination needs a host, a user and an absolute folder")
		}
		if d.Port == 0 {
			d.Port = 22
		}
		if d.Port < 1 || d.Port > 65535 {
			return bad("the port must be 1 to 65535")
		}
		d.Path = p
	case S3:
		host, port, _ := strings.Cut(d.Endpoint, ":")
		if !hostRule.MatchString(host) || !bucketRule.MatchString(d.Bucket) || !regionRule.MatchString(d.Region) || !prefixRule.MatchString(d.Prefix) {
			return bad("an S3 destination needs an endpoint host, a bucket and a plain folder")
		}
		if port != "" {
			if n, err := strconv.Atoi(port); err != nil || n < 1 || n > 65535 {
				return bad("the endpoint's port must be 1 to 65535")
			}
		}
		if d.Scheme == "" {
			d.Scheme = "https"
		}
		if d.Scheme != "https" && d.Scheme != "http" {
			return bad("the scheme is https or http")
		}
		if err := need("access_key", d.AccessKey); err != nil {
			return err
		}
		if err := need("secret_key", d.SecretKey); err != nil {
			return err
		}
	case B2:
		if !b2BucketRule.MatchString(d.Bucket) || !prefixRule.MatchString(d.Prefix) {
			return bad("a B2 destination needs a bucket and a plain folder")
		}
		if err := need("access_key", d.AccessKey); err != nil {
			return err
		}
		if err := need("secret_key", d.SecretKey); err != nil {
			return err
		}
	default:
		return bad("the type is local, sftp, s3 or b2")
	}
	d.Prefix = strings.Trim(d.Prefix, "/")
	if strings.Contains(d.Prefix, "..") {
		return bad("the folder may not contain ..")
	}
	return nil
}

// Repository is restic's name for the destination.
func (d Destination) Repository() string {
	switch d.Type {
	case Local:
		return d.Path
	case SFTP:
		return "sftp:" + d.User + "@" + d.Host + ":" + d.Path
	case S3:
		r := "s3:" + d.Scheme + "://" + d.Endpoint + "/" + d.Bucket
		if d.Prefix != "" {
			r += "/" + d.Prefix
		}
		return r
	case B2:
		return "b2:" + d.Bucket + ":" + d.Prefix
	}
	return ""
}

// Public is what the panel may see of a destination.
func (d Destination) Public() Destination {
	d.AccessKey, d.SecretKey = "", ""
	return d
}

// Commander runs restic and mariadb-dump; tests replace it.
type Commander interface {
	Run(ctx context.Context, c Command) (stdout, stderr string, code int, err error)
}

// Command is one program run. Stdout, when set, takes the output instead
// of it being returned.
type Command struct {
	Path   string
	Args   []string
	Env    []string
	Stdout io.Writer
	Stdin  io.Reader
}

// Manager does the backup operations.
type Manager struct {
	// Dir holds destinations, repository passwords and the SFTP key (0700).
	Dir string
	// StageDir holds an account's database dumps and description while it
	// is backed up; CacheDir is restic's cache.
	StageDir, CacheDir string
	// RestoreDir holds what restic restores before it is put in the home.
	RestoreDir string
	// Restic, Dump and Client are restic, mariadb-dump and mariadb.
	Restic, Dump, Client string
	Cmd                  Commander
	// SELinux: restored files get the policy's labels (restorecon), not
	// just their parent folder's.
	SELinux bool
	Owner                func(name string) (uid, gid int, home string, err error)
	// Databases lists an account's databases (by its prefix).
	Databases func(ctx context.Context, username string) ([]string, error)
	Log       *slog.Logger
	Now       func() time.Time

	run      sync.Mutex
	restore  sync.Mutex
	mu       sync.Mutex
	progress Status
}

// Defaults fills in what main and tests leave empty.
func (m *Manager) Defaults() {
	set := func(p *string, v string) {
		if *p == "" {
			*p = v
		}
	}
	set(&m.Dir, "/etc/lpa/backup")
	set(&m.StageDir, "/var/lib/lpa/backup")
	set(&m.CacheDir, "/var/cache/lpa/restic")
	set(&m.Restic, "/usr/bin/restic")
	set(&m.Dump, "/usr/bin/mariadb-dump")
	set(&m.Client, "/usr/bin/mariadb")
	set(&m.RestoreDir, "/var/lib/lpa/restore")
	if m.Log == nil {
		m.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	if m.Now == nil {
		m.Now = time.Now
	}
	if m.Cmd == nil {
		m.Cmd = Exec{}
	}
}

func (m *Manager) installed() error {
	if _, err := os.Stat(m.Restic); err != nil {
		return protocol.Fail(CodeOff, "restic is not installed on this server")
	}
	return nil
}

func (m *Manager) destFile(id string) string { return filepath.Join(m.Dir, "destinations", id+".json") }
func (m *Manager) passFile(id string) string { return filepath.Join(m.Dir, "destinations", id+".pass") }
func (m *Manager) keyFile() string           { return filepath.Join(m.Dir, "id_ed25519") }
func (m *Manager) knownHosts() string        { return filepath.Join(m.Dir, "known_hosts") }

func (m *Manager) load(id string) (Destination, error) {
	if !idRule.MatchString(id) {
		return Destination{}, bad("the destination id is not one")
	}
	data, err := os.ReadFile(m.destFile(id))
	if errors.Is(err, fs.ErrNotExist) {
		return Destination{}, protocol.Fail(CodeNoDestination, "there is no destination "+id)
	}
	if err != nil {
		return Destination{}, err
	}
	var d Destination
	if err := json.Unmarshal(data, &d); err != nil {
		return Destination{}, fmt.Errorf("destination %s: %w", id, err)
	}
	return d, nil
}

// env is restic's whole environment for a destination.
func (m *Manager) env(d Destination) []string {
	env := []string{
		"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C", "LC_ALL=C", "HOME=/root",
		"RESTIC_REPOSITORY=" + d.Repository(),
		"RESTIC_PASSWORD_FILE=" + m.passFile(d.ID),
		"RESTIC_CACHE_DIR=" + m.CacheDir,
	}
	switch d.Type {
	case S3:
		env = append(env, "AWS_ACCESS_KEY_ID="+d.AccessKey, "AWS_SECRET_ACCESS_KEY="+d.SecretKey)
		if d.Region != "" {
			env = append(env, "AWS_DEFAULT_REGION="+d.Region)
		}
	case B2:
		env = append(env, "B2_ACCOUNT_ID="+d.AccessKey, "B2_ACCOUNT_KEY="+d.SecretKey)
	}
	return env
}

// restic runs restic against a destination.
func (m *Manager) restic(ctx context.Context, d Destination, stdout io.Writer, args ...string) (string, string, int, error) {
	full := []string{}
	if d.Type == SFTP {
		full = append(full, "-o", "sftp.command=ssh -i "+m.keyFile()+" -p "+strconv.Itoa(d.Port)+
			" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new"+
			" -o UserKnownHostsFile="+m.knownHosts()+" "+d.User+"@"+d.Host+" -s sftp")
	}
	full = append(full, args...)
	return m.Cmd.Run(ctx, Command{Path: m.Restic, Args: full, Env: m.env(d), Stdout: stdout})
}

// failure turns restic's answer into an error the panel can show.
func failure(what string, code int, stderr string) error {
	msg := lastLine(stderr)
	switch code {
	case 12:
		return protocol.Fail(CodeWrongPassword, "a repository is already there, and LPanel's password does not open it")
	case 10:
		return protocol.Fail(CodeUnreachable, "there is no repository at the destination")
	}
	if strings.Contains(stderr, "wrong password") {
		return protocol.Fail(CodeWrongPassword, "a repository is already there, and LPanel's password does not open it")
	}
	return protocol.Fail(CodeUnreachable, fmt.Sprintf("%s: %s", what, msg))
}

func lastLine(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		if l := strings.TrimSpace(lines[i]); l != "" {
			if len(l) > 300 {
				l = l[:300]
			}
			return l
		}
	}
	return "no output"
}

// ---------------------------------------------------------------------------
// Destinations

// DestinationArgs are backup.destination's: a new or changed destination.
// Empty secrets keep the ones the agent has.
type DestinationArgs struct {
	Destination
	// Password opens a repository another server made (its
	// `lpa backup-password`): taken only for a destination added now, never
	// kept in the destination's description.
	Password string `json:"password,omitempty"`
}

// DestinationResult says the destination works.
type DestinationResult struct {
	Destination Destination `json:"destination"`
	Created     bool        `json:"created"` // a new repository was made
	PublicKey   string      `json:"public_key,omitempty"`
	Snapshots   int         `json:"snapshots"`
}

// SetDestination checks a destination, keeps it, and makes its repository
// when there is none yet. A destination that does not answer is not kept.
func (m *Manager) SetDestination(ctx context.Context, a DestinationArgs) (DestinationResult, error) {
	if err := m.installed(); err != nil {
		return DestinationResult{}, err
	}
	d := a.Destination
	old, oldErr := m.load(d.ID)
	if err := d.check(func(field string) bool { return oldErr == nil && old.Type == d.Type }); err != nil {
		return DestinationResult{}, err
	}
	if oldErr == nil && old.Type == d.Type {
		if d.AccessKey == "" {
			d.AccessKey = old.AccessKey
		}
		if d.SecretKey == "" {
			d.SecretKey = old.SecretKey
		}
	}
	if !m.run.TryLock() {
		return DestinationResult{}, protocol.Fail(CodeBusy, "a backup run is going on; try again when it ends")
	}
	defer m.run.Unlock()
	if err := os.MkdirAll(filepath.Join(m.Dir, "destinations"), 0o700); err != nil {
		return DestinationResult{}, err
	}
	out := DestinationResult{}
	if d.Type == SFTP {
		pub, err := m.ensureKey()
		if err != nil {
			return DestinationResult{}, err
		}
		out.PublicKey = pub
	}
	if d.Type == Local {
		if err := os.MkdirAll(d.Path, 0o700); err != nil {
			return DestinationResult{}, bad("the folder cannot be made: %v", err)
		}
	}
	// The repository's password is made once and never changes: a
	// destination that is changed (a new key, a moved folder) still opens
	// what was written before. A new destination may bring the password of
	// a repository another server made.
	_, statErr := os.Stat(m.destFile(d.ID))
	fresh := errors.Is(statErr, fs.ErrNotExist)
	if a.Password != "" {
		if !secretRule.MatchString(a.Password) {
			return DestinationResult{}, bad("a repository password is printable characters, no spaces")
		}
		if !fresh {
			return DestinationResult{}, bad("this destination has its password already; add the repository as a new destination")
		}
		if err := writeFile(m.passFile(d.ID), []byte(a.Password+"\n"), 0o600); err != nil {
			return DestinationResult{}, err
		}
	} else if _, err := os.Stat(m.passFile(d.ID)); errors.Is(err, fs.ErrNotExist) {
		b := make([]byte, 32)
		if _, err := rand.Read(b); err != nil {
			return DestinationResult{}, err
		}
		if err := writeFile(m.passFile(d.ID), []byte(hex.EncodeToString(b)+"\n"), 0o600); err != nil {
			return DestinationResult{}, err
		}
	}
	kept := false
	if fresh {
		// A new destination that does not work leaves nothing behind.
		defer func() {
			if !kept {
				os.Remove(m.passFile(d.ID))
			}
		}()
	}
	_, stderr, code, err := m.restic(ctx, d, nil, "cat", "config", "--no-lock")
	if err != nil {
		return DestinationResult{}, err
	}
	if code != 0 && code != 10 && a.Password != "" && (code == 12 || strings.Contains(stderr, "wrong password")) {
		return DestinationResult{}, protocol.Fail(CodeWrongPassword, "that password does not open the repository there")
	}
	if (code == 10 || strings.Contains(stderr, "does not exist")) && a.Password != "" {
		return DestinationResult{}, protocol.Fail(CodeUnreachable, "there is no repository there for that password to open")
	}
	switch {
	case code == 0:
	case code == 10 || strings.Contains(stderr, "does not exist") || strings.Contains(stderr, "Is there a repository at the following location"):
		if _, stderr, code, err := m.restic(ctx, d, nil, "init"); err != nil {
			return DestinationResult{}, err
		} else if code != 0 {
			return DestinationResult{}, failure("restic could not make the repository", code, stderr)
		}
		out.Created = true
	default:
		return DestinationResult{}, failure("the destination did not answer", code, stderr)
	}
	if err := writeJSON(m.destFile(d.ID), d); err != nil {
		return DestinationResult{}, err
	}
	kept = true
	if list, err := m.snapshots(ctx, d, ""); err == nil {
		out.Snapshots = len(list)
	}
	out.Destination = d.Public()
	m.Log.Info("backup destination set", "id", d.ID, "type", d.Type, "created", out.Created)
	return out, nil
}

// RemoveArgs name a destination.
type RemoveArgs struct {
	ID string `json:"id"`
}

// RemoveDestination forgets a destination's credentials. What was backed up
// there stays there; its password goes too, so the panel says to keep it
// (lpa backup-password) first.
func (m *Manager) RemoveDestination(ctx context.Context, a RemoveArgs) (map[string]bool, error) {
	if _, err := m.load(a.ID); err != nil {
		return nil, err
	}
	if !m.run.TryLock() {
		return nil, protocol.Fail(CodeBusy, "a backup run is going on; try again when it ends")
	}
	defer m.run.Unlock()
	for _, f := range []string{m.destFile(a.ID), m.passFile(a.ID)} {
		if err := os.Remove(f); err != nil && !errors.Is(err, fs.ErrNotExist) {
			return nil, err
		}
	}
	return map[string]bool{"removed": true}, nil
}

// Password is a destination's repository password, for root's own records
// (`lpa backup-password <id>`); no operation returns it.
func (m *Manager) Password(id string) (string, error) {
	if _, err := m.load(id); err != nil {
		return "", err
	}
	b, err := os.ReadFile(m.passFile(id))
	return strings.TrimSpace(string(b)), err
}

// ensureKey makes the agent's SSH key for SFTP destinations, once.
func (m *Manager) ensureKey() (string, error) {
	if data, err := os.ReadFile(m.keyFile() + ".pub"); err == nil {
		return strings.TrimSpace(string(data)), nil
	}
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", err
	}
	block, err := ssh.MarshalPrivateKey(priv, "lpanel-backup")
	if err != nil {
		return "", err
	}
	sshPub, err := ssh.NewPublicKey(pub)
	if err != nil {
		return "", err
	}
	line := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(sshPub))) + " lpanel-backup"
	if err := writeFile(m.keyFile(), pem.EncodeToMemory(block), 0o600); err != nil {
		return "", err
	}
	if err := writeFile(m.keyFile()+".pub", []byte(line+"\n"), 0o644); err != nil {
		return "", err
	}
	return line, nil
}

// ---------------------------------------------------------------------------
// Runs

// Retention is how many snapshots of each account to keep.
type Retention struct {
	Daily   int `json:"daily"`
	Weekly  int `json:"weekly"`
	Monthly int `json:"monthly"`
}

// RunAccount is one account to back up, with the panel's description of it.
type RunAccount struct {
	Username string          `json:"username"`
	Meta     json.RawMessage `json:"meta"`
}

// RunArgs are backup.run's.
type RunArgs struct {
	Destination string       `json:"destination"`
	Accounts    []RunAccount `json:"accounts"`
	Keep        Retention    `json:"keep"`
	// Prune frees the space forgotten snapshots took (slow; weekly).
	Prune bool `json:"prune"`
}

// AccountResult is one account's outcome.
type AccountResult struct {
	Username  string  `json:"username"`
	OK        bool    `json:"ok"`
	Snapshot  string  `json:"snapshot,omitempty"`
	Error     string  `json:"error,omitempty"`
	Warning   string  `json:"warning,omitempty"`
	Files     int64   `json:"files"`
	Bytes     int64   `json:"bytes"`
	Added     int64   `json:"added"`
	Databases int     `json:"databases"`
	Seconds   float64 `json:"seconds"`
	Forgotten int     `json:"forgotten"`
}

// RunResult is backup.run's answer.
type RunResult struct {
	Accounts   []AccountResult `json:"accounts"`
	OK         int             `json:"ok"`
	Failed     int             `json:"failed"`
	Pruned     bool            `json:"pruned"`
	PruneError string          `json:"prune_error,omitempty"`
}

// Status is backup.status's answer: the run going on now, if any.
type Status struct {
	Running     bool      `json:"running"`
	Destination string    `json:"destination,omitempty"`
	Current     string    `json:"current,omitempty"`
	Done        int       `json:"done"`
	Total       int       `json:"total"`
	StartedAt   time.Time `json:"started_at,omitzero"`
	Installed   bool      `json:"installed"`
}

// MaxMeta is the largest account description the panel may send.
const MaxMeta = 4 << 20

// Run backs the accounts up, one after another. One account failing does
// not stop the others.
func (m *Manager) Run(ctx context.Context, a RunArgs) (RunResult, error) {
	if err := m.installed(); err != nil {
		return RunResult{}, err
	}
	d, err := m.load(a.Destination)
	if err != nil {
		return RunResult{}, err
	}
	if len(a.Accounts) == 0 {
		return RunResult{}, bad("no accounts to back up")
	}
	seen := map[string]bool{}
	for _, acct := range a.Accounts {
		if !userRule.MatchString(acct.Username) || seen[acct.Username] {
			return RunResult{}, bad("%q is not an account, or is given twice", acct.Username)
		}
		seen[acct.Username] = true
		if len(acct.Meta) > MaxMeta || (len(acct.Meta) > 0 && !json.Valid(acct.Meta)) {
			return RunResult{}, bad("the description of %s is not JSON of at most 4 MB", acct.Username)
		}
	}
	if a.Keep.Daily < 0 || a.Keep.Weekly < 0 || a.Keep.Monthly < 0 || a.Keep.Daily+a.Keep.Weekly+a.Keep.Monthly == 0 {
		return RunResult{}, bad("keep at least one snapshot")
	}
	if !m.run.TryLock() {
		return RunResult{}, protocol.Fail(CodeBusy, "another backup run is going on")
	}
	defer m.run.Unlock()
	m.setProgress(Status{Running: true, Destination: d.ID, Total: len(a.Accounts), StartedAt: m.Now()})
	defer m.setProgress(Status{})

	out := RunResult{Accounts: []AccountResult{}}
	for i, acct := range a.Accounts {
		m.setProgress(Status{Running: true, Destination: d.ID, Current: acct.Username, Done: i, Total: len(a.Accounts), StartedAt: m.progressStart()})
		r := m.account(ctx, d, acct, a.Keep)
		if r.OK {
			out.OK++
		} else {
			out.Failed++
		}
		out.Accounts = append(out.Accounts, r)
		if ctx.Err() != nil {
			break
		}
	}
	if a.Prune && out.OK > 0 && ctx.Err() == nil {
		if _, stderr, code, err := m.restic(ctx, d, nil, "prune"); err != nil || code != 0 {
			out.PruneError = lastLine(stderr)
			if err != nil {
				out.PruneError = err.Error()
			}
		} else {
			out.Pruned = true
		}
	}
	m.Log.Info("backup run finished", "destination", d.ID, "ok", out.OK, "failed", out.Failed)
	return out, nil
}

func (m *Manager) setProgress(s Status) {
	m.mu.Lock()
	m.progress = s
	m.mu.Unlock()
}

func (m *Manager) progressStart() time.Time {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.progress.StartedAt
}

// Progress is the run going on now.
func (m *Manager) Progress() Status {
	m.mu.Lock()
	s := m.progress
	m.mu.Unlock()
	_, err := os.Stat(m.Restic)
	s.Installed = err == nil
	return s
}

// account backs one account up: dumps, description, snapshot, retention.
func (m *Manager) account(ctx context.Context, d Destination, acct RunAccount, keep Retention) AccountResult {
	start := m.Now()
	r := AccountResult{Username: acct.Username}
	fail := func(err error) AccountResult {
		r.Error = err.Error()
		r.Seconds = m.Now().Sub(start).Seconds()
		m.Log.Warn("account backup failed", "username", acct.Username, "err", err)
		return r
	}
	_, _, home, err := m.Owner(acct.Username)
	if err != nil {
		return fail(err)
	}
	stage := filepath.Join(m.StageDir, acct.Username)
	if err := os.RemoveAll(stage); err != nil {
		return fail(err)
	}
	if err := os.MkdirAll(filepath.Join(stage, "databases"), 0o700); err != nil {
		return fail(err)
	}
	defer os.RemoveAll(stage)

	meta := acct.Meta
	if len(meta) == 0 {
		meta = json.RawMessage("{}")
	}
	if err := writeFile(filepath.Join(stage, "lpanel.json"), meta, 0o600); err != nil {
		return fail(err)
	}
	dbs, err := m.Databases(ctx, acct.Username)
	if err != nil {
		return fail(fmt.Errorf("listing databases: %w", err))
	}
	for _, db := range dbs {
		if !dbRule.MatchString(db) || !strings.HasPrefix(db, acct.Username+"_") {
			continue
		}
		if err := m.dump(ctx, db, filepath.Join(stage, "databases", db+".sql")); err != nil {
			return fail(err)
		}
		r.Databases++
	}

	args := []string{"backup", "--json", "--host", "lpanel", "--tag", "lpanel", "--tag", "account:" + acct.Username,
		"--exclude", filepath.Join(home, "tmp"), "--exclude-caches",
		home, stage}
	stdout, stderr, code, err := m.restic(ctx, d, nil, args...)
	if err != nil {
		return fail(err)
	}
	// 3: some files could not be read; the snapshot was still made.
	if code != 0 && code != 3 {
		return fail(failure("restic backup", code, stderr))
	}
	if code == 3 {
		r.Warning = "some files could not be read: " + lastLine(stderr)
	}
	sum, ok := summary(stdout)
	if !ok {
		return fail(errors.New("restic gave no summary"))
	}
	r.OK, r.Snapshot, r.Files, r.Bytes, r.Added = true, sum.SnapshotID, sum.Files, sum.Bytes, sum.Added

	if n, err := m.forget(ctx, d, acct.Username, keep); err != nil {
		r.Warning = strings.TrimSpace(r.Warning + " old snapshots were not forgotten: " + err.Error())
	} else {
		r.Forgotten = n
	}
	r.Seconds = m.Now().Sub(start).Seconds()
	return r
}

// dump writes one database's dump into a file, as root over MariaDB's socket.
func (m *Manager) dump(ctx context.Context, db, path string) error {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	_, stderr, code, err := m.Cmd.Run(ctx, Command{
		Path: m.Dump,
		Args: []string{"--single-transaction", "--quick", "--routines", "--triggers", "--events", "--hex-blob",
			"--default-character-set=utf8mb4", "--databases", db},
		Env:    []string{"PATH=/usr/sbin:/usr/bin", "LANG=C", "HOME=/root"},
		Stdout: f,
	})
	if cerr := f.Close(); err == nil && cerr != nil {
		err = cerr
	}
	if err != nil {
		return fmt.Errorf("dumping %s: %w", db, err)
	}
	if code != 0 {
		return fmt.Errorf("dumping %s: %s", db, lastLine(stderr))
	}
	return nil
}

type backupSummary struct {
	SnapshotID string `json:"snapshot_id"`
	Files      int64  `json:"total_files_processed"`
	Bytes      int64  `json:"total_bytes_processed"`
	Added      int64  `json:"data_added"`
}

// summary finds restic's closing summary among its JSON lines.
func summary(stdout string) (backupSummary, bool) {
	lines := strings.Split(stdout, "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		var msg struct {
			Type string `json:"message_type"`
			backupSummary
		}
		if json.Unmarshal([]byte(lines[i]), &msg) == nil && msg.Type == "summary" && msg.SnapshotID != "" {
			return msg.backupSummary, true
		}
	}
	return backupSummary{}, false
}

// forget drops an account's snapshots beyond the retention.
func (m *Manager) forget(ctx context.Context, d Destination, username string, keep Retention) (int, error) {
	args := []string{"forget", "--json", "--host", "lpanel", "--tag", "account:" + username, "--group-by", "host,tags"}
	if keep.Daily > 0 {
		args = append(args, "--keep-daily", strconv.Itoa(keep.Daily))
	}
	if keep.Weekly > 0 {
		args = append(args, "--keep-weekly", strconv.Itoa(keep.Weekly))
	}
	if keep.Monthly > 0 {
		args = append(args, "--keep-monthly", strconv.Itoa(keep.Monthly))
	}
	stdout, stderr, code, err := m.restic(ctx, d, nil, args...)
	if err != nil {
		return 0, err
	}
	if code != 0 {
		return 0, errors.New(lastLine(stderr))
	}
	var groups []struct {
		Remove []json.RawMessage `json:"remove"`
	}
	n := 0
	if json.Unmarshal([]byte(stdout), &groups) == nil {
		for _, g := range groups {
			n += len(g.Remove)
		}
	}
	return n, nil
}

// ---------------------------------------------------------------------------
// Snapshots

// SnapshotArgs are backup.snapshots': a destination, and an account or all.
type SnapshotArgs struct {
	Destination string `json:"destination"`
	Username    string `json:"username"`
}

// Snapshot is one backup of one account.
type Snapshot struct {
	ID       string    `json:"id"`
	Short    string    `json:"short_id"`
	Time     time.Time `json:"time"`
	Username string    `json:"username"`
	Files    int64     `json:"files"`
	Bytes    int64     `json:"bytes"`
}

// Snapshots lists a destination's snapshots, newest first.
func (m *Manager) Snapshots(ctx context.Context, a SnapshotArgs) ([]Snapshot, error) {
	if err := m.installed(); err != nil {
		return nil, err
	}
	if a.Username != "" && !userRule.MatchString(a.Username) {
		return nil, bad("%q is not an account name", a.Username)
	}
	d, err := m.load(a.Destination)
	if err != nil {
		return nil, err
	}
	return m.snapshots(ctx, d, a.Username)
}

func (m *Manager) snapshots(ctx context.Context, d Destination, username string) ([]Snapshot, error) {
	tag := "lpanel"
	if username != "" {
		tag = "account:" + username
	}
	stdout, stderr, code, err := m.restic(ctx, d, nil, "snapshots", "--json", "--no-lock", "--host", "lpanel", "--tag", tag)
	if err != nil {
		return nil, err
	}
	if code != 0 {
		return nil, failure("restic snapshots", code, stderr)
	}
	var raw []struct {
		ID      string    `json:"id"`
		Short   string    `json:"short_id"`
		Time    time.Time `json:"time"`
		Tags    []string  `json:"tags"`
		Summary *struct {
			Files int64 `json:"total_files_processed"`
			Bytes int64 `json:"total_bytes_processed"`
		} `json:"summary"`
	}
	if err := json.Unmarshal([]byte(stdout), &raw); err != nil {
		return nil, fmt.Errorf("restic snapshots: %w", err)
	}
	out := make([]Snapshot, 0, len(raw))
	for i := len(raw) - 1; i >= 0; i-- {
		s := raw[i]
		snap := Snapshot{ID: s.ID, Short: s.Short, Time: s.Time}
		for _, t := range s.Tags {
			if u, ok := strings.CutPrefix(t, "account:"); ok {
				snap.Username = u
			}
		}
		if s.Summary != nil {
			snap.Files, snap.Bytes = s.Summary.Files, s.Summary.Bytes
		}
		out = append(out, snap)
	}
	return out, nil
}

// ---------------------------------------------------------------------------

func writeJSON(path string, v any) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	return writeFile(path, append(data, '\n'), 0o600)
}

// writeFile replaces path with data (root's), through a temporary file.
func writeFile(path string, data []byte, perm os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer os.Remove(name)
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := os.Chmod(name, perm); err != nil {
		return err
	}
	return os.Rename(name, path)
}
