package backup

// Restores (M8b): what a snapshot holds, its account's description, and
// putting files, mailboxes and databases back, or into an archive the owner
// downloads.
//
// A snapshot belongs to the account its tag names, and every operation that
// takes an account checks that tag: an account is only ever given back its
// own data. restic restores into RestoreDir (0700 root), never into a home,
// and the files are then copied into the home through an os.Root opened on
// it. The owner controls everything in the home, so the copy never follows a
// link out of it, replaces links met on the way instead of writing through
// them, and writes each file under a new name before renaming it over the
// old one (a hard link the owner made is replaced, not written into).

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"lpa/internal/protocol"
)

// CodeNotInSnapshot: the file, folder or database is not in that snapshot.
const CodeNotInSnapshot = "backup_not_in_snapshot"

var snapshotRule = regexp.MustCompile(`^[0-9a-f]{8,64}$`)

// snapInfo is one snapshot as restore needs it: whose it is and where its
// two folders were on the server that made it.
type snapInfo struct {
	Snapshot
	Home  string // the account's home then
	Stage string // its databases and lpanel.json
}

// find looks a snapshot up; username, when given, must be the one it is of.
func (m *Manager) find(ctx context.Context, d Destination, id, username string) (snapInfo, error) {
	if !snapshotRule.MatchString(id) {
		return snapInfo{}, bad("%q is not a snapshot id", id)
	}
	stdout, stderr, code, err := m.restic(ctx, d, nil, "snapshots", "--json", "--no-lock", id)
	if err != nil {
		return snapInfo{}, err
	}
	if code != 0 {
		if strings.Contains(stderr, "no matching ID") || strings.Contains(stderr, "not found") {
			return snapInfo{}, protocol.Fail(CodeNotInSnapshot, "there is no snapshot "+id)
		}
		return snapInfo{}, failure("restic snapshots", code, stderr)
	}
	var raw []struct {
		ID    string    `json:"id"`
		Short string    `json:"short_id"`
		Time  time.Time `json:"time"`
		Host  string    `json:"hostname"`
		Tags  []string  `json:"tags"`
		Paths []string  `json:"paths"`
	}
	if err := json.Unmarshal([]byte(stdout), &raw); err != nil || len(raw) != 1 {
		return snapInfo{}, protocol.Fail(CodeNotInSnapshot, "there is no snapshot "+id)
	}
	s := raw[0]
	info := snapInfo{Snapshot: Snapshot{ID: s.ID, Short: s.Short, Time: s.Time}}
	lpanel := false
	for _, t := range s.Tags {
		if t == "lpanel" {
			lpanel = true
		}
		if u, ok := strings.CutPrefix(t, "account:"); ok {
			info.Username = u
		}
	}
	if !lpanel || s.Host != "lpanel" || !userRule.MatchString(info.Username) || len(s.Paths) != 2 {
		return snapInfo{}, protocol.Fail(CodeNotInSnapshot, "snapshot "+id+" is not an account's backup")
	}
	if username != "" && info.Username != username {
		return snapInfo{}, protocol.Fail(CodeNotInSnapshot, "snapshot "+id+" is not "+username+"'s")
	}
	// The stage is the folder named after the account under lpa's backup
	// folder (this server's, or the default one on the server that made it).
	for _, p := range s.Paths {
		if p == filepath.Join(m.StageDir, info.Username) || strings.HasSuffix(p, "/lpa/backup/"+info.Username) {
			info.Stage = p
		} else {
			info.Home = p
		}
	}
	if info.Stage == "" || info.Home == "" || !filepath.IsAbs(info.Home) {
		return snapInfo{}, protocol.Fail(CodeNotInSnapshot, "snapshot "+id+" is not an account's backup")
	}
	return info, nil
}

// relPath checks a path inside a home, as the panel gives it ("" or "." is
// the whole home), and gives it cleaned. Patterns restic would read as globs
// are refused rather than escaped.
func relPath(p string) (string, error) {
	p = strings.TrimPrefix(strings.TrimSpace(p), "/")
	if p == "" || p == "." {
		return "", nil
	}
	clean := path.Clean(p)
	if clean == ".." || strings.HasPrefix(clean, "../") || len(clean) > 1024 || strings.ContainsAny(clean, "*?[]\\\x00\n\r") {
		return "", bad("%q is not a path in the home", p)
	}
	return clean, nil
}

func databaseOf(username, db string) error {
	if !dbRule.MatchString(db) || !strings.HasPrefix(db, username+"_") {
		return bad("%q is not one of %s's databases", db, username)
	}
	return nil
}

// ---------------------------------------------------------------------------
// What a snapshot holds

// FilesArgs are backup.files': a folder of a snapshot, by its path in the home.
type FilesArgs struct {
	Destination string `json:"destination"`
	Snapshot    string `json:"snapshot"`
	Username    string `json:"username"`
	Path        string `json:"path"`
}

// FileEntry is one thing in a snapshot's folder.
type FileEntry struct {
	Name  string    `json:"name"`
	Type  string    `json:"type"` // file, dir or symlink
	Size  int64     `json:"size"`
	Mtime time.Time `json:"mtime"`
}

// FilesResult is a folder of a snapshot; at the top of the home it also
// names the databases the snapshot holds.
type FilesResult struct {
	Snapshot  Snapshot    `json:"snapshot"`
	Path      string      `json:"path"`
	Entries   []FileEntry `json:"entries"`
	Databases []string    `json:"databases"`
	Truncated bool        `json:"truncated"`
}

// MaxEntries is the most a folder listing gives.
const MaxEntries = 5000

// Files lists one folder of an account's snapshot.
func (m *Manager) Files(ctx context.Context, a FilesArgs) (FilesResult, error) {
	d, info, err := m.open(ctx, a.Destination, a.Snapshot, a.Username, true)
	if err != nil {
		return FilesResult{}, err
	}
	rel, err := relPath(a.Path)
	if err != nil {
		return FilesResult{}, err
	}
	out := FilesResult{Snapshot: info.Snapshot, Path: rel, Entries: []FileEntry{}, Databases: []string{}}
	dir := filepath.Join(info.Home, rel)
	nodes, err := m.ls(ctx, d, info.ID, dir)
	if err != nil {
		return FilesResult{}, err
	}
	found := false
	for _, n := range nodes {
		if n.Path == dir {
			found = n.Type == "dir"
			continue
		}
		if filepath.Dir(n.Path) != dir || (rel == "" && n.Name == "tmp") {
			continue
		}
		found = true
		if len(out.Entries) == MaxEntries {
			out.Truncated = true
			continue
		}
		out.Entries = append(out.Entries, FileEntry{Name: n.Name, Type: n.Type, Size: n.Size, Mtime: n.Mtime})
	}
	if !found && rel != "" {
		return FilesResult{}, protocol.Fail(CodeNotInSnapshot, rel+" is not a folder in that snapshot")
	}
	sort.Slice(out.Entries, func(i, j int) bool {
		ei, ej := out.Entries[i], out.Entries[j]
		if (ei.Type == "dir") != (ej.Type == "dir") {
			return ei.Type == "dir"
		}
		return ei.Name < ej.Name
	})
	if rel == "" {
		out.Databases, err = m.databasesIn(ctx, d, info)
		if err != nil {
			return FilesResult{}, err
		}
	}
	return out, nil
}

type lsNode struct {
	Name  string    `json:"name"`
	Type  string    `json:"type"`
	Path  string    `json:"path"`
	Size  int64     `json:"size"`
	Mtime time.Time `json:"mtime"`
}

// ls is restic's listing of one folder (not below it).
func (m *Manager) ls(ctx context.Context, d Destination, id, dir string) ([]lsNode, error) {
	stdout, stderr, code, err := m.restic(ctx, d, nil, "ls", "--json", "--no-lock", id, dir)
	if err != nil {
		return nil, err
	}
	if code != 0 {
		return nil, failure("restic ls", code, stderr)
	}
	nodes := []lsNode{}
	for _, line := range strings.Split(stdout, "\n") {
		var n struct {
			lsNode
			StructType  string `json:"struct_type"`
			MessageType string `json:"message_type"`
		}
		if json.Unmarshal([]byte(line), &n) != nil || (n.StructType != "node" && n.MessageType != "node") {
			continue
		}
		nodes = append(nodes, n.lsNode)
	}
	return nodes, nil
}

func (m *Manager) databasesIn(ctx context.Context, d Destination, info snapInfo) ([]string, error) {
	dir := filepath.Join(info.Stage, "databases")
	nodes, err := m.ls(ctx, d, info.ID, dir)
	if err != nil {
		return nil, err
	}
	out := []string{}
	for _, n := range nodes {
		db, ok := strings.CutSuffix(n.Name, ".sql")
		if ok && filepath.Dir(n.Path) == dir && databaseOf(info.Username, db) == nil {
			out = append(out, db)
		}
	}
	sort.Strings(out)
	return out, nil
}

// open checks the arguments every restore operation shares.
func (m *Manager) open(ctx context.Context, dest, snapshot, username string, needUser bool) (Destination, snapInfo, error) {
	if err := m.installed(); err != nil {
		return Destination{}, snapInfo{}, err
	}
	if (needUser || username != "") && !userRule.MatchString(username) {
		return Destination{}, snapInfo{}, bad("%q is not an account name", username)
	}
	d, err := m.load(dest)
	if err != nil {
		return Destination{}, snapInfo{}, err
	}
	info, err := m.find(ctx, d, snapshot, username)
	return d, info, err
}

// MetaArgs are backup.meta's.
type MetaArgs struct {
	Destination string `json:"destination"`
	Snapshot    string `json:"snapshot"`
}

// MetaResult is the panel's description of the account, as it was saved.
type MetaResult struct {
	Snapshot Snapshot        `json:"snapshot"`
	Meta     json.RawMessage `json:"meta"`
}

// Meta reads a snapshot's lpanel.json, for the panel to make the account
// again (on this server after it was terminated, or on another).
func (m *Manager) Meta(ctx context.Context, a MetaArgs) (MetaResult, error) {
	d, info, err := m.open(ctx, a.Destination, a.Snapshot, "", false)
	if err != nil {
		return MetaResult{}, err
	}
	var buf limitedBuffer
	buf.max = MaxMeta
	_, stderr, code, err := m.restic(ctx, d, &buf, "dump", "--no-lock", info.ID, filepath.Join(info.Stage, "lpanel.json"))
	if err != nil {
		return MetaResult{}, err
	}
	if code != 0 || buf.over {
		return MetaResult{}, protocol.Fail(CodeNotInSnapshot, "the snapshot's description cannot be read: "+lastLine(stderr))
	}
	if !json.Valid(buf.Bytes()) {
		return MetaResult{}, protocol.Fail(CodeNotInSnapshot, "the snapshot's description is not JSON")
	}
	return MetaResult{Snapshot: info.Snapshot, Meta: json.RawMessage(bytes.Clone(buf.Bytes()))}, nil
}

type limitedBuffer struct {
	bytes.Buffer
	max  int
	over bool
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	if b.Len()+len(p) > b.max {
		b.over = true
		return 0, errors.New("too large")
	}
	return b.Buffer.Write(p)
}

// ---------------------------------------------------------------------------
// Putting things back

// RestoreArgs are backup.restore's. Paths are in the home ("" is all of
// it): a folder, a file, a mailbox's folder. Databases are the account's.
type RestoreArgs struct {
	Destination string   `json:"destination"`
	Snapshot    string   `json:"snapshot"`
	Username    string   `json:"username"`
	Paths       []string `json:"paths"`
	Databases   []string `json:"databases"`
	// Folder puts the files in restored-<time>/ in the home instead of
	// where they were.
	Folder bool `json:"folder"`
}

// RestoreResult says what was put back.
type RestoreResult struct {
	Files     int64    `json:"files"`
	Bytes     int64    `json:"bytes"`
	Skipped   int      `json:"skipped"`
	Folder    string   `json:"folder,omitempty"`
	Databases []string `json:"databases"`
	Warnings  []string `json:"warnings"`
}

// MaxRestorePaths is the most paths one restore takes.
const MaxRestorePaths = 100

// Restore puts files and databases from an account's snapshot back. Files
// that are there now and not in the snapshot stay; those in both are
// replaced by the snapshot's.
func (m *Manager) Restore(ctx context.Context, a RestoreArgs) (RestoreResult, error) {
	d, info, err := m.open(ctx, a.Destination, a.Snapshot, a.Username, true)
	if err != nil {
		return RestoreResult{}, err
	}
	if len(a.Paths) == 0 && len(a.Databases) == 0 {
		return RestoreResult{}, bad("nothing to restore")
	}
	if len(a.Paths) > MaxRestorePaths || len(a.Databases) > MaxRestorePaths {
		return RestoreResult{}, bad("at most %d paths and %d databases at a time", MaxRestorePaths, MaxRestorePaths)
	}
	paths := []string{}
	for _, p := range a.Paths {
		rel, err := relPath(p)
		if err != nil {
			return RestoreResult{}, err
		}
		paths = append(paths, rel)
	}
	for _, db := range a.Databases {
		if err := databaseOf(a.Username, db); err != nil {
			return RestoreResult{}, err
		}
	}
	uid, gid, home, err := m.Owner(a.Username)
	if err != nil {
		return RestoreResult{}, err
	}
	if !m.restore.TryLock() {
		return RestoreResult{}, protocol.Fail(CodeBusy, "another restore is going on; try again when it ends")
	}
	defer m.restore.Unlock()

	staging, err := m.staging(a.Username)
	if err != nil {
		return RestoreResult{}, err
	}
	defer os.RemoveAll(staging)
	out := RestoreResult{Databases: []string{}, Warnings: []string{}}

	if len(paths) > 0 {
		args := []string{"restore", "--no-lock", info.ID, "--target", staging}
		for _, rel := range paths {
			args = append(args, "--include", filepath.Join(info.Home, rel))
		}
		if _, stderr, code, err := m.restic(ctx, d, nil, args...); err != nil {
			return RestoreResult{}, err
		} else if code != 0 {
			return RestoreResult{}, failure("restic restore", code, stderr)
		}
		root, err := os.OpenRoot(home)
		if err != nil {
			return RestoreResult{}, err
		}
		defer root.Close()
		p := &placer{root: root, uid: uid, gid: gid}
		base := "."
		if a.Folder {
			base = "restored-" + m.Now().Format("20060102-150405")
			out.Folder = base
		}
		for _, rel := range paths {
			src := filepath.Join(staging, info.Home, rel)
			if _, err := os.Lstat(src); err != nil {
				return RestoreResult{}, protocol.Fail(CodeNotInSnapshot, nonEmpty(rel, "the home")+" is not in that snapshot")
			}
			if err := p.place(src, path.Join(base, rel)); err != nil {
				return RestoreResult{}, fmt.Errorf("putting %s back: %w", nonEmpty(rel, "the home"), err)
			}
		}
		out.Files, out.Bytes, out.Skipped, out.Warnings = p.files, p.bytes, p.skipped, append(out.Warnings, p.warnings...)
		if m.SELinux {
			// By the path's real place: a link swapped in meanwhile only
			// gets the labels its own place should have.
			for _, rel := range paths {
				target := filepath.Join(home, filepath.FromSlash(path.Join(base, rel)))
				if _, stderr, code, err := m.Cmd.Run(ctx, Command{Path: "/usr/sbin/restorecon", Args: []string{"-R", target}, Env: []string{"PATH=/usr/sbin:/usr/bin", "LANG=C"}}); err != nil || code != 0 {
					out.Warnings = append(out.Warnings, "labels not restored on "+nonEmpty(path.Join(base, rel), "the home")+": "+lastLine(stderr))
				}
			}
		}
	}

	for _, db := range a.Databases {
		if err := m.restoreDatabase(ctx, d, info, staging, db); err != nil {
			return out, err
		}
		out.Databases = append(out.Databases, db)
	}
	m.Log.Info("restored", "username", a.Username, "snapshot", info.Short, "files", out.Files, "databases", len(out.Databases))
	return out, nil
}

func nonEmpty(s, otherwise string) string {
	if s == "" {
		return otherwise
	}
	return s
}

func (m *Manager) staging(username string) (string, error) {
	if err := os.MkdirAll(m.RestoreDir, 0o700); err != nil {
		return "", err
	}
	return os.MkdirTemp(m.RestoreDir, username+"-")
}

// restoreDatabase loads a database's dump from the snapshot, as root over
// MariaDB's socket. The dump names its database (made if it is missing) and
// drops and makes each of its tables; tables made since stay.
func (m *Manager) restoreDatabase(ctx context.Context, d Destination, info snapInfo, staging, db string) error {
	file := filepath.Join(staging, "db-"+db+".sql")
	f, err := os.OpenFile(file, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	_, stderr, code, err := m.restic(ctx, d, f, "dump", "--no-lock", info.ID, filepath.Join(info.Stage, "databases", db+".sql"))
	if cerr := f.Close(); err == nil && cerr != nil {
		err = cerr
	}
	if err != nil {
		return err
	}
	if code != 0 {
		return protocol.Fail(CodeNotInSnapshot, "database "+db+" is not in that snapshot")
	}
	in, err := os.Open(file)
	if err != nil {
		return err
	}
	defer in.Close()
	_, stderr, code, err = m.Cmd.Run(ctx, Command{
		Path:  m.Client,
		Args:  []string{"--default-character-set=utf8mb4", "--binary-mode"},
		Env:   []string{"PATH=/usr/sbin:/usr/bin", "LANG=C", "HOME=/root"},
		Stdin: in,
	})
	if err != nil {
		return fmt.Errorf("loading %s: %w", db, err)
	}
	if code != 0 {
		return fmt.Errorf("loading %s: %s", db, lastLine(stderr))
	}
	return nil
}

// placer copies what restic restored into a home, as its owner's.
type placer struct {
	root         *os.Root
	uid, gid     int
	files, bytes int64
	skipped      int
	warnings     []string
}

func (p *placer) warn(format string, a ...any) {
	if len(p.warnings) < 20 {
		p.warnings = append(p.warnings, fmt.Sprintf(format, a...))
	}
}

func (p *placer) own(name string) error {
	if err := p.root.Lchown(name, p.uid, p.gid); err != nil && os.Geteuid() == 0 {
		return err
	}
	return nil
}

// mkdirs makes each folder of dir that is missing, as the owner's. A link
// where a folder goes is replaced by the folder, so nothing below is
// written through a link.
func (p *placer) mkdirs(dir string) error {
	dir = path.Clean(dir)
	if dir == "." {
		return nil
	}
	cur := ""
	for _, part := range strings.Split(dir, "/") {
		cur = path.Join(cur, part)
		fi, err := p.root.Lstat(cur)
		switch {
		case err == nil && fi.IsDir():
			continue
		case err == nil && fi.Mode()&fs.ModeSymlink != 0:
			if err := p.root.Remove(cur); err != nil {
				return err
			}
		case err == nil:
			return fmt.Errorf("%s is a file where the snapshot has a folder", cur)
		case !errors.Is(err, fs.ErrNotExist):
			return err
		}
		if err := p.root.Mkdir(cur, 0o755); err != nil {
			return err
		}
		if err := p.own(cur); err != nil {
			return err
		}
	}
	return nil
}

// place copies src (a file or a folder in the staging folder) to dst in the home.
func (p *placer) place(src, dst string) error {
	return filepath.WalkDir(src, func(s string, e fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(src, s)
		if err != nil {
			return err
		}
		t := path.Join(dst, filepath.ToSlash(rel))
		info, err := e.Info()
		if err != nil {
			return err
		}
		switch {
		case e.IsDir():
			if t == "." {
				return nil // the home itself keeps its owner and mode
			}
			if err := p.mkdirs(t); err != nil {
				return err
			}
			return p.root.Chmod(t, info.Mode().Perm())
		case info.Mode().IsRegular():
			return p.file(s, t, info)
		case info.Mode()&fs.ModeSymlink != 0:
			return p.link(s, t)
		default:
			p.skipped++
			return nil
		}
	})
}

func (p *placer) file(src, t string, info fs.FileInfo) error {
	if err := p.mkdirs(path.Dir(t)); err != nil {
		return err
	}
	if fi, err := p.root.Lstat(t); err == nil && fi.IsDir() {
		p.skipped++
		p.warn("%s is a folder now; the file was not put back", t)
		return nil
	}
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	tmp := path.Join(path.Dir(t), ".lpanel-restore-"+randomName())
	out, err := p.root.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return err
	}
	n, err := io.Copy(out, in)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err == nil {
		err = p.own(tmp)
	}
	if err == nil {
		err = p.root.Chmod(tmp, info.Mode().Perm())
	}
	if err == nil {
		err = p.root.Chtimes(tmp, info.ModTime(), info.ModTime())
	}
	if err == nil {
		err = p.root.Rename(tmp, t)
	}
	if err != nil {
		p.root.Remove(tmp)
		return err
	}
	p.files++
	p.bytes += n
	return nil
}

func (p *placer) link(src, t string) error {
	target, err := os.Readlink(src)
	if err != nil {
		return err
	}
	if err := p.mkdirs(path.Dir(t)); err != nil {
		return err
	}
	if fi, err := p.root.Lstat(t); err == nil {
		if fi.IsDir() {
			p.skipped++
			p.warn("%s is a folder now; the link was not put back", t)
			return nil
		}
		if err := p.root.Remove(t); err != nil {
			return err
		}
	}
	if err := p.root.Symlink(target, t); err != nil {
		return err
	}
	p.files++
	return p.own(t)
}

func randomName() string {
	b := make([]byte, 8)
	rand.Read(b)
	return hex.EncodeToString(b)
}

// ---------------------------------------------------------------------------
// Downloads

// DownloadArgs are backup.download's: the whole snapshot, a path in the
// home, or one database.
type DownloadArgs struct {
	Destination string `json:"destination"`
	Snapshot    string `json:"snapshot"`
	Username    string `json:"username"`
	Path        string `json:"path"`
	Database    string `json:"database"`
}

// DownloadResult names the archive, in the home.
type DownloadResult struct {
	File  string `json:"file"`
	Bytes int64  `json:"bytes"`
}

// DownloadFolder is where archives go, in the home.
const DownloadFolder = "lpanel-backups"

// Download writes an archive of what the snapshot holds into the home
// (lpanel-backups/, the owner's): a .tar.gz of the snapshot or a path in
// it, or a database's dump as .sql.gz. The owner takes it from there with
// the file manager, FTP or SFTP; it counts towards the account's disk.
func (m *Manager) Download(ctx context.Context, a DownloadArgs) (DownloadResult, error) {
	d, info, err := m.open(ctx, a.Destination, a.Snapshot, a.Username, true)
	if err != nil {
		return DownloadResult{}, err
	}
	rel, err := relPath(a.Path)
	if err != nil {
		return DownloadResult{}, err
	}
	stamp := info.Time.UTC().Format("20060102-150405")
	var name string
	var args []string
	switch {
	case a.Database != "":
		if err := databaseOf(a.Username, a.Database); err != nil {
			return DownloadResult{}, err
		}
		name = a.Database + "-" + stamp + ".sql.gz"
		args = []string{"dump", "--no-lock", info.ID, filepath.Join(info.Stage, "databases", a.Database+".sql")}
	case rel == "":
		// Everything: the home and the stage (databases, description).
		name = a.Username + "-" + stamp + ".tar.gz"
		args = []string{"dump", "--no-lock", "--archive", "tar", info.ID, "/"}
	default:
		slug := strings.Trim(regexp.MustCompile(`[^A-Za-z0-9._-]+`).ReplaceAllString(rel, "-"), "-.")
		if len(slug) > 60 {
			slug = slug[:60]
		}
		name = a.Username + "-" + stamp + "-" + slug + ".tar.gz"
		args = []string{"dump", "--no-lock", "--archive", "tar", info.ID, filepath.Join(info.Home, rel)}
	}
	uid, gid, home, err := m.Owner(a.Username)
	if err != nil {
		return DownloadResult{}, err
	}
	if !m.restore.TryLock() {
		return DownloadResult{}, protocol.Fail(CodeBusy, "another restore is going on; try again when it ends")
	}
	defer m.restore.Unlock()

	root, err := os.OpenRoot(home)
	if err != nil {
		return DownloadResult{}, err
	}
	defer root.Close()
	p := &placer{root: root, uid: uid, gid: gid}
	if err := p.mkdirs(DownloadFolder); err != nil {
		return DownloadResult{}, err
	}
	if err := root.Chmod(DownloadFolder, 0o700); err != nil {
		return DownloadResult{}, err
	}
	tmp := path.Join(DownloadFolder, ".lpanel-download-"+randomName())
	f, err := root.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return DownloadResult{}, err
	}
	defer root.Remove(tmp)
	counter := &countWriter{w: f}
	gz := gzip.NewWriter(counter)
	_, stderr, code, err := m.restic(ctx, d, gz, args...)
	if gerr := gz.Close(); err == nil {
		err = gerr
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return DownloadResult{}, err
	}
	if code != 0 {
		if strings.Contains(stderr, "not found") || strings.Contains(stderr, "cannot find") || strings.Contains(stderr, "no such") {
			return DownloadResult{}, protocol.Fail(CodeNotInSnapshot, "that is not in the snapshot")
		}
		return DownloadResult{}, failure("restic dump", code, stderr)
	}
	if err := p.own(tmp); err != nil {
		return DownloadResult{}, err
	}
	final := path.Join(DownloadFolder, name)
	if err := root.Rename(tmp, final); err != nil {
		return DownloadResult{}, err
	}
	m.Log.Info("backup download made", "username", a.Username, "snapshot", info.Short, "file", final)
	return DownloadResult{File: final, Bytes: counter.n}, nil
}

type countWriter struct {
	w io.Writer
	n int64
}

func (c *countWriter) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.n += int64(n)
	return n, err
}
