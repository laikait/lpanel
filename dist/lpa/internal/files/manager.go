package files

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Manager carries out file operations for the panel.
type Manager struct {
	// Owner gives an account's uid, gid and home, refusing any user the
	// agent did not make (accounts.Manager.Owner).
	Owner func(name string) (uid, gid int, home string, err error)
	// Staging is the panel's staging folder: uploads and saved files it
	// hands over, and downloads it collects.
	Staging string
	// Self is the agent's own binary, started as a worker.
	Self string
	// Spawn runs one worker request; nil starts a real worker process as the
	// account's user. Tests replace it with Work in the same process.
	Spawn func(ctx context.Context, uid, gid int, home string, req []byte, in, out *os.File) ([]byte, error)
	// SELinux is true on a host with SELinux on: what is moved into a folder
	// with labels of its own (public_html, mail…) gets that folder's labels.
	SELinux bool
	Run     sysexec.Runner
	Log     *slog.Logger
	// UsageBudget is how long a usage walk may take before it answers with
	// what it has.
	UsageBudget time.Duration
}

// Defaults fills what was left empty.
func (m *Manager) Defaults() {
	if m.Staging == "" {
		m.Staging = "/var/lib/lpanel/files"
	}
	if m.UsageBudget == 0 {
		m.UsageBudget = 60 * time.Second
	}
	if m.Log == nil {
		m.Log = slog.Default()
	}
}

// limited is a buffer that keeps at most max bytes of a worker's output and
// drops the rest, so a worker cannot fill the agent's memory.
type limited struct {
	bytes.Buffer
	max int
}

func (l *limited) Write(p []byte) (int, error) {
	if room := l.max - l.Len(); room > 0 {
		if len(p) > room {
			l.Buffer.Write(p[:room])
		} else {
			l.Buffer.Write(p)
		}
	}
	return len(p), nil
}

func workerFailed(err error, stderr string) error {
	if s := strings.TrimSpace(stderr); s != "" {
		return fmt.Errorf("the file worker failed: %v: %s", err, s)
	}
	return fmt.Errorf("the file worker failed: %v", err)
}

// call runs one operation in a worker as the account's user. args has been
// checked already; the worker checks it again.
func (m *Manager) call(ctx context.Context, username, op string, args any, in, out *os.File, budget time.Duration) (json.RawMessage, error) {
	uid, gid, home, err := m.Owner(username)
	if err != nil {
		return nil, err
	}
	raw, err := json.Marshal(args)
	if err != nil {
		return nil, err
	}
	req, err := json.Marshal(Request{Op: op, Home: home, Args: raw, In: in != nil, Out: out != nil, Budget: budget})
	if err != nil {
		return nil, err
	}
	spawn := m.Spawn
	if spawn == nil {
		spawn = m.execWorker
	}
	data, err := spawn(ctx, uid, gid, home, req, in, out)
	if err != nil {
		return nil, err
	}
	var reply Reply
	if err := json.Unmarshal(data, &reply); err != nil {
		return nil, fmt.Errorf("the file worker answered with something that is not a reply: %v", err)
	}
	if reply.Error != nil {
		return nil, reply.Error
	}
	return reply.Result, nil
}

// staged opens one of the panel's staged files, by name only. The folder
// belongs to the panel, so nothing in it is trusted: the name is 64 hex
// digits, a link is not followed, and only a plain file with one name that
// root does not own is taken (a hard link to a system file cannot pass).
func (m *Manager) staged(token string, write bool) (*os.File, error) {
	if err := checkStaged(token); err != nil {
		return nil, err
	}
	flags := os.O_RDONLY
	if write {
		flags = os.O_WRONLY | os.O_TRUNC
	}
	f, err := os.OpenFile(filepath.Join(m.Staging, token), flags|noFollow, 0)
	if err != nil {
		return nil, protocol.Fail(CodeStaged, "the staged file is missing")
	}
	info, err := f.Stat()
	if err == nil && !info.Mode().IsRegular() {
		err = errors.New("not a plain file")
	}
	if err == nil {
		if n, ok := links(info); ok && n != 1 {
			err = errors.New("more than one name")
		}
		if owner, ok := ownerOf(info); ok && owner == 0 {
			err = errors.New("owned by root")
		}
	}
	if err != nil {
		f.Close()
		return nil, protocol.Fail(CodeStaged, "the staged file is not one the panel made ("+err.Error()+")")
	}
	return f, nil
}

func decodeAs[T any](raw json.RawMessage) (T, error) {
	var out T
	err := json.Unmarshal(raw, &out)
	return out, err
}

// List lists a folder.
func (m *Manager) List(ctx context.Context, a PathArgs) (Listing, error) {
	if err := a.check(); err != nil {
		return Listing{}, err
	}
	raw, err := m.call(ctx, a.Username, "list", a, nil, nil, 0)
	if err != nil {
		return Listing{}, err
	}
	return decodeAs[Listing](raw)
}

// Read reads a file for the editor.
func (m *Manager) Read(ctx context.Context, a PathArgs) (Content, error) {
	if err := a.check(); err != nil {
		return Content{}, err
	}
	raw, err := m.call(ctx, a.Username, "read", a, nil, nil, 0)
	if err != nil {
		return Content{}, err
	}
	return decodeAs[Content](raw)
}

// Write replaces a file's content with a staged file.
func (m *Manager) Write(ctx context.Context, a WriteArgs) (Written, error) {
	if err := a.check(); err != nil {
		return Written{}, err
	}
	in, err := m.staged(a.Staged, false)
	if err != nil {
		return Written{}, err
	}
	defer in.Close()
	raw, err := m.call(ctx, a.Username, "write", a, in, nil, 0)
	if err != nil {
		return Written{}, err
	}
	return decodeAs[Written](raw)
}

// Mkdir makes a folder.
func (m *Manager) Mkdir(ctx context.Context, a PathArgs) (map[string]string, error) {
	if err := a.checkNew(); err != nil {
		return nil, err
	}
	raw, err := m.call(ctx, a.Username, "mkdir", a, nil, nil, 0)
	if err != nil {
		return nil, err
	}
	return decodeAs[map[string]string](raw)
}

// Create makes an empty file.
func (m *Manager) Create(ctx context.Context, a PathArgs) (map[string]string, error) {
	if err := a.checkNew(); err != nil {
		return nil, err
	}
	raw, err := m.call(ctx, a.Username, "create", a, nil, nil, 0)
	if err != nil {
		return nil, err
	}
	return decodeAs[map[string]string](raw)
}

// Delete removes files and folders, with everything in them.
func (m *Manager) Delete(ctx context.Context, a PathsArgs) (Changed, error) {
	if err := a.check(); err != nil {
		return Changed{}, err
	}
	raw, err := m.call(ctx, a.Username, "delete", a, nil, nil, 0)
	if err != nil {
		return Changed{}, err
	}
	return decodeAs[Changed](raw)
}

// Move moves files and folders into a folder.
func (m *Manager) Move(ctx context.Context, a MoveArgs) (Changed, error) {
	if err := a.check(true); err != nil {
		return Changed{}, err
	}
	raw, err := m.call(ctx, a.Username, "move", a, nil, nil, 0)
	if err != nil {
		return Changed{}, err
	}
	out, err := decodeAs[Changed](raw)
	if err == nil && out.Count > 0 {
		for _, p := range a.Paths {
			m.relabel(ctx, a.Username, p, join(a.To, path.Base(p)))
		}
	}
	return out, err
}

// Rename renames a file or folder, or moves it to another path.
func (m *Manager) Rename(ctx context.Context, a RenameArgs) (map[string]string, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	raw, err := m.call(ctx, a.Username, "rename", a, nil, nil, 0)
	if err != nil {
		return nil, err
	}
	m.relabel(ctx, a.Username, a.From, a.To)
	return decodeAs[map[string]string](raw)
}

// Copy copies files and folders into a folder.
func (m *Manager) Copy(ctx context.Context, a MoveArgs) (Changed, error) {
	if err := a.check(false); err != nil {
		return Changed{}, err
	}
	raw, err := m.call(ctx, a.Username, "copy", a, nil, nil, 0)
	if err != nil {
		return Changed{}, err
	}
	return decodeAs[Changed](raw)
}

// Chmod sets permissions.
func (m *Manager) Chmod(ctx context.Context, a ChmodArgs) (Changed, error) {
	if _, err := a.check(); err != nil {
		return Changed{}, err
	}
	raw, err := m.call(ctx, a.Username, "chmod", a, nil, nil, 0)
	if err != nil {
		return Changed{}, err
	}
	return decodeAs[Changed](raw)
}

// Compress packs files and folders into a new archive.
func (m *Manager) Compress(ctx context.Context, a CompressArgs) (Packed, error) {
	if _, err := a.check(); err != nil {
		return Packed{}, err
	}
	raw, err := m.call(ctx, a.Username, "compress", a, nil, nil, 0)
	if err != nil {
		return Packed{}, err
	}
	return decodeAs[Packed](raw)
}

// Extract unpacks an archive into a folder.
func (m *Manager) Extract(ctx context.Context, a ExtractArgs) (Unpacked, error) {
	if _, err := a.check(); err != nil {
		return Unpacked{}, err
	}
	raw, err := m.call(ctx, a.Username, "extract", a, nil, nil, 0)
	if err != nil {
		return Unpacked{}, err
	}
	return decodeAs[Unpacked](raw)
}

// Upload puts a staged upload into a folder.
func (m *Manager) Upload(ctx context.Context, a UploadArgs) (Written, error) {
	if err := a.check(); err != nil {
		return Written{}, err
	}
	in, err := m.staged(a.Staged, false)
	if err != nil {
		return Written{}, err
	}
	defer in.Close()
	raw, err := m.call(ctx, a.Username, "upload", a, in, nil, 0)
	if err != nil {
		return Written{}, err
	}
	return decodeAs[Written](raw)
}

// Download copies a file into a staged file the panel made, for it to send.
func (m *Manager) Download(ctx context.Context, a DownloadArgs) (Downloaded, error) {
	if err := a.check(); err != nil {
		return Downloaded{}, err
	}
	out, err := m.staged(a.Into, true)
	if err != nil {
		return Downloaded{}, err
	}
	defer out.Close()
	raw, err := m.call(ctx, a.Username, "download", a, nil, out, 0)
	if err != nil {
		return Downloaded{}, err
	}
	return decodeAs[Downloaded](raw)
}

// Usage measures what in a folder takes the account's space.
func (m *Manager) Usage(ctx context.Context, a PathArgs) (Usage, error) {
	if err := a.check(); err != nil {
		return Usage{}, err
	}
	raw, err := m.call(ctx, a.Username, "usage", a, nil, nil, m.UsageBudget)
	if err != nil {
		return Usage{}, err
	}
	return decodeAs[Usage](raw)
}

// relabel gives what was moved into one of the folders with labels of its
// own (public_html, tmp, logs, mail) that folder's labels, since a move
// keeps the labels a file had where it was. restorecon labels by the path's
// real location, so a link swapped in meanwhile only gets the labels its own
// place should have.
func (m *Manager) relabel(ctx context.Context, username, from, to string) {
	if !m.SELinux {
		return
	}
	top := func(p string) string { return strings.SplitN(p, "/", 2)[0] }
	if !Protected[top(to)] || top(from) == top(to) {
		return
	}
	_, _, home, err := m.Owner(username)
	if err != nil {
		return
	}
	if err := sysexec.Must(ctx, m.Run, "restorecon", "-R", filepath.Join(home, filepath.FromSlash(to))); err != nil {
		m.Log.Warn("could not relabel moved files", "username", username, "path", to, "err", err)
	}
}
