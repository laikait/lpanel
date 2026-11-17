package files

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"io/fs"
	"os"
	"path"
	"sort"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"

	"lpa/internal/protocol"
)

// Request is what the agent sends a worker on its standard input.
type Request struct {
	Op   string          `json:"op"`
	Home string          `json:"home"`
	Args json.RawMessage `json:"args"`
	// In and Out say whether the worker was given a staged file to read
	// (descriptor 3) or to write (descriptor 4).
	In  bool `json:"in"`
	Out bool `json:"out"`
	// Budget is how long a walk (usage) may take before it answers with what
	// it has; zero means no limit.
	Budget time.Duration `json:"budget"`
}

// Reply is what a worker writes to its standard output.
type Reply struct {
	Result json.RawMessage `json:"result,omitempty"`
	Error  *protocol.Error `json:"error,omitempty"`
}

// MaxRequest bounds what a worker reads from the agent.
const MaxRequest = 1 << 20

// WorkerMain is "lpa files-worker": it runs as the account's user, reads one
// Request, does it, and writes one Reply. It always exits 0 once it has
// written a reply; anything else means it could not.
func WorkerMain(stdin io.Reader, stdout io.Writer) int {
	// The agent runs with umask 0077; the account's files get the usual modes.
	setUmask()
	var req Request
	data, err := io.ReadAll(io.LimitReader(stdin, MaxRequest))
	if err == nil {
		err = json.Unmarshal(data, &req)
	}
	var in, out *os.File
	if req.In {
		in = os.NewFile(3, "staged-in")
	}
	if req.Out {
		out = os.NewFile(4, "staged-out")
	}
	var result any
	if err == nil {
		result, err = Work(req, in, out)
	}
	if err := json.NewEncoder(stdout).Encode(MakeReply(result, err)); err != nil {
		return 1
	}
	return 0
}

// MakeReply is the reply for an outcome of Work.
func MakeReply(result any, err error) Reply {
	reply := Reply{}
	if err != nil {
		var pe *protocol.Error
		if !errors.As(err, &pe) {
			pe = protocol.Fail(protocol.CodeFailed, err.Error())
		}
		reply.Error = pe
	} else if reply.Result, err = json.Marshal(result); err != nil {
		reply.Error = protocol.Fail(protocol.CodeFailed, err.Error())
	}
	return reply
}

// Work does one request inside the home. The agent's tests call it directly;
// on a server it runs in a worker, as the account's user.
func Work(req Request, in, out *os.File) (any, error) {
	root, err := os.OpenRoot(req.Home)
	if err != nil {
		return nil, mapErr(err, "the home folder")
	}
	defer root.Close()
	w := &worker{root: root, in: in, out: out}
	if req.Budget > 0 {
		w.deadline = time.Now().Add(req.Budget)
	}
	return w.do(req.Op, req.Args)
}

type worker struct {
	root     *os.Root
	in, out  *os.File
	deadline time.Time
}

func decode[T any](raw json.RawMessage) (T, error) {
	var a T
	if err := json.Unmarshal(raw, &a); err != nil {
		return a, badArgs("arguments: %v", err)
	}
	return a, nil
}

func (w *worker) do(op string, raw json.RawMessage) (any, error) {
	switch op {
	case "list", "read", "mkdir", "create", "usage":
		a, err := decode[PathArgs](raw)
		if err != nil {
			return nil, err
		}
		switch op {
		case "list":
			return w.list(a)
		case "read":
			return w.read(a)
		case "usage":
			return w.usage(a)
		case "mkdir":
			return w.mkdir(a)
		default:
			return w.create(a)
		}
	case "write":
		a, err := decode[WriteArgs](raw)
		if err != nil {
			return nil, err
		}
		return w.write(a)
	case "delete":
		a, err := decode[PathsArgs](raw)
		if err != nil {
			return nil, err
		}
		return w.delete(a)
	case "move", "copy":
		a, err := decode[MoveArgs](raw)
		if err != nil {
			return nil, err
		}
		if op == "move" {
			return w.move(a)
		}
		return w.copy(a)
	case "rename":
		a, err := decode[RenameArgs](raw)
		if err != nil {
			return nil, err
		}
		return w.rename(a)
	case "chmod":
		a, err := decode[ChmodArgs](raw)
		if err != nil {
			return nil, err
		}
		return w.chmod(a)
	case "compress":
		a, err := decode[CompressArgs](raw)
		if err != nil {
			return nil, err
		}
		return w.compress(a)
	case "extract":
		a, err := decode[ExtractArgs](raw)
		if err != nil {
			return nil, err
		}
		return w.extract(a)
	case "upload":
		a, err := decode[UploadArgs](raw)
		if err != nil {
			return nil, err
		}
		return w.upload(a)
	case "download":
		a, err := decode[DownloadArgs](raw)
		if err != nil {
			return nil, err
		}
		return w.download(a)
	}
	return nil, badArgs("no file operation called %q", op)
}

// mapErr turns a filesystem error into one the panel can explain, naming the
// path as the owner sees it.
func mapErr(err error, p string) error {
	if err == nil {
		return nil
	}
	var pe *protocol.Error
	if errors.As(err, &pe) {
		return pe
	}
	shown := "/" + strings.TrimPrefix(p, ".")
	if p == "." {
		shown = "the home folder"
	}
	switch {
	case strings.Contains(err.Error(), "escapes from parent") || errors.Is(err, syscall.ELOOP):
		return protocol.Fail(CodeOutside, shown+" leads out of the home folder through a link")
	case errors.Is(err, fs.ErrNotExist):
		return protocol.Fail(CodeMissing, shown+" is not there")
	case errors.Is(err, fs.ErrExist):
		return protocol.Fail(CodeExists, shown+" is already there")
	case errors.Is(err, fs.ErrPermission):
		return protocol.Fail(CodeDenied, "the account's permissions do not allow that on "+shown)
	case isQuota(err):
		return protocol.Fail(CodeQuota, "the account is out of disk space (or of files) for "+shown)
	case errors.Is(err, syscall.ENOTDIR):
		return protocol.Fail(CodeType, "part of "+shown+" is a file, not a folder")
	case errors.Is(err, syscall.EISDIR):
		return protocol.Fail(CodeType, shown+" is a folder")
	case errors.Is(err, syscall.ENOTEMPTY):
		return protocol.Fail(CodeExists, shown+" is a folder that is not empty")
	}
	return protocol.Fail(protocol.CodeFailed, shown+": "+err.Error())
}

func join(dir, name string) string {
	if dir == "." || dir == "" {
		return name
	}
	return dir + "/" + name
}

func typeOf(m fs.FileMode) string {
	switch {
	case m.IsDir():
		return "dir"
	case m.IsRegular():
		return "file"
	case m&fs.ModeSymlink != 0:
		return "link"
	}
	return "other"
}

func perm(m fs.FileMode) string {
	return "0" + string([]byte{'0' + byte(m.Perm()>>6&7), '0' + byte(m.Perm()>>3&7), '0' + byte(m.Perm()&7)})
}

// Entry is one file or folder in a listing.
type Entry struct {
	Name  string `json:"name"`
	Type  string `json:"type"`
	Size  int64  `json:"size"`
	Mode  string `json:"mode"`
	MTime int64  `json:"mtime"`
	// Target is a link's target, and To what it leads to: "dir", "file",
	// "" when it leads nowhere, "outside" when it leads out of the home.
	Target string `json:"target,omitempty"`
	To     string `json:"to,omitempty"`
}

// Listing is files.list's answer.
type Listing struct {
	Path      string  `json:"path"`
	Mode      string  `json:"mode"`
	Entries   []Entry `json:"entries"`
	Truncated bool    `json:"truncated"`
}

func (w *worker) list(a PathArgs) (any, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	dir, err := w.root.Open(a.Path)
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	defer dir.Close()
	info, err := dir.Stat()
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	if !info.IsDir() {
		return nil, protocol.Fail(CodeType, "/"+a.Path+" is a file, not a folder")
	}
	des, err := dir.ReadDir(MaxList + 1)
	if err != nil && !errors.Is(err, io.EOF) {
		return nil, mapErr(err, a.Path)
	}
	out := Listing{Path: a.Path, Mode: perm(info.Mode()), Entries: []Entry{}}
	if len(des) > MaxList {
		des, out.Truncated = des[:MaxList], true
	}
	for _, de := range des {
		fi, err := de.Info()
		if err != nil {
			continue // removed while listing
		}
		e := Entry{Name: de.Name(), Type: typeOf(fi.Mode()), Size: fi.Size(), Mode: perm(fi.Mode()), MTime: fi.ModTime().Unix()}
		if e.Type == "dir" {
			e.Size = 0
		}
		if e.Type == "link" {
			p := join(a.Path, de.Name())
			e.Target, _ = w.root.Readlink(p)
			if to, err := w.root.Stat(p); err == nil {
				e.To = typeOf(to.Mode())
			} else if strings.Contains(err.Error(), "escapes from parent") {
				e.To = "outside"
			}
		}
		out.Entries = append(out.Entries, e)
	}
	sort.Slice(out.Entries, func(i, j int) bool {
		di := out.Entries[i].Type == "dir" || out.Entries[i].To == "dir"
		dj := out.Entries[j].Type == "dir" || out.Entries[j].To == "dir"
		if di != dj {
			return di
		}
		li, lj := strings.ToLower(out.Entries[i].Name), strings.ToLower(out.Entries[j].Name)
		if li != lj {
			return li < lj
		}
		return out.Entries[i].Name < out.Entries[j].Name
	})
	return out, nil
}

// Content is files.read's answer.
type Content struct {
	Path    string `json:"path"`
	Size    int64  `json:"size"`
	MTime   int64  `json:"mtime"`
	Mode    string `json:"mode"`
	SHA256  string `json:"sha256"`
	Binary  bool   `json:"binary"`
	Content string `json:"content"`
}

func (w *worker) read(a PathArgs) (any, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	f, err := w.root.Open(a.Path)
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	if !info.Mode().IsRegular() {
		return nil, protocol.Fail(CodeType, "/"+a.Path+" is not a file")
	}
	if info.Size() > MaxEdit {
		return nil, protocol.Fail(CodeTooBig, "/"+a.Path+" is larger than the editor opens (4 MB); download it instead")
	}
	data, err := io.ReadAll(io.LimitReader(f, MaxEdit+1))
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	if len(data) > MaxEdit {
		return nil, protocol.Fail(CodeTooBig, "/"+a.Path+" is larger than the editor opens (4 MB); download it instead")
	}
	sum := sha256.Sum256(data)
	c := Content{Path: a.Path, Size: int64(len(data)), MTime: info.ModTime().Unix(), Mode: perm(info.Mode()), SHA256: hex.EncodeToString(sum[:])}
	if !utf8.Valid(data) || bytes.IndexByte(data, 0) >= 0 {
		c.Binary = true
	} else {
		c.Content = string(data)
	}
	return c, nil
}

// Written is the answer of files.write and files.upload.
type Written struct {
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256,omitempty"`
}

func (w *worker) write(a WriteArgs) (any, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	if w.in == nil {
		return nil, protocol.Fail(CodeStaged, "no staged content was given")
	}
	data, err := io.ReadAll(io.LimitReader(w.in, MaxEdit+1))
	if err != nil {
		return nil, err
	}
	if len(data) > MaxEdit {
		return nil, protocol.Fail(CodeTooBig, "the editor saves at most 4 MB")
	}
	mode := fs.FileMode(0o644)
	inPlace := false
	if info, err := w.root.Lstat(a.Path); err == nil {
		if a.Create {
			return nil, mapErr(fs.ErrExist, a.Path)
		}
		target := info
		if info.Mode()&fs.ModeSymlink != 0 {
			// Saving through a link changes what it leads to, not the link.
			if target, err = w.root.Stat(a.Path); err != nil {
				return nil, mapErr(err, a.Path)
			}
			inPlace = true
		}
		if !target.Mode().IsRegular() {
			return nil, protocol.Fail(CodeType, "/"+a.Path+" is not a file")
		}
		if hard, ok := links(target); ok && hard > 1 {
			inPlace = true // keep every name of a hard-linked file the same file
		}
		mode = target.Mode().Perm()
		if a.Expect != "" {
			cur, err := w.root.ReadFile(a.Path)
			if err != nil {
				return nil, mapErr(err, a.Path)
			}
			if sum := sha256.Sum256(cur); hex.EncodeToString(sum[:]) != a.Expect {
				return nil, protocol.Fail(CodeChanged, "/"+a.Path+" has changed since it was opened; open it again to see the changes")
			}
		}
	} else if !errors.Is(err, fs.ErrNotExist) {
		return nil, mapErr(err, a.Path)
	} else if a.Expect != "" {
		return nil, protocol.Fail(CodeChanged, "/"+a.Path+" was removed since it was opened")
	}
	if inPlace {
		f, err := w.root.OpenFile(a.Path, os.O_WRONLY|os.O_TRUNC, 0)
		if err != nil {
			return nil, mapErr(err, a.Path)
		}
		if _, err := f.Write(data); err != nil {
			f.Close()
			return nil, mapErr(err, a.Path)
		}
		if err := f.Close(); err != nil {
			return nil, mapErr(err, a.Path)
		}
	} else if _, err := w.replace(a.Path, mode, bytes.NewReader(data), MaxEdit, !a.Create); err != nil {
		return nil, err
	}
	sum := sha256.Sum256(data)
	return Written{Path: a.Path, Size: int64(len(data)), SHA256: hex.EncodeToString(sum[:])}, nil
}

// replace writes r to p through a temporary file beside it, renamed over p
// at the end, so p is always either the old file or the whole new one. When
// overwrite is false, p must not exist at the end either.
func (w *worker) replace(p string, mode fs.FileMode, r io.Reader, limit int64, overwrite bool) (int64, error) {
	var rnd [8]byte
	_, _ = rand.Read(rnd[:])
	tmp := join(path.Dir(p), ".lpanel-"+hex.EncodeToString(rnd[:])+".part")
	f, err := w.root.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return 0, mapErr(err, path.Dir(p))
	}
	done := false
	defer func() {
		if !done {
			_ = w.root.Remove(tmp)
		}
	}()
	n, err := io.Copy(f, io.LimitReader(r, limit+1))
	if err == nil && n > limit {
		err = protocol.Fail(CodeTooBig, "/"+p+" is larger than allowed")
	}
	if err == nil {
		err = f.Chmod(mode)
	}
	if err == nil {
		err = f.Sync()
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return 0, mapErr(err, p)
	}
	if !overwrite {
		if _, err := w.root.Lstat(p); err == nil {
			return 0, mapErr(fs.ErrExist, p)
		}
	}
	if err := w.root.Rename(tmp, p); err != nil {
		return 0, mapErr(err, p)
	}
	done = true
	return n, nil
}

func (w *worker) mkdir(a PathArgs) (any, error) {
	if err := a.checkNew(); err != nil {
		return nil, err
	}
	if err := w.root.Mkdir(a.Path, 0o755); err != nil {
		return nil, mapErr(err, a.Path)
	}
	return map[string]string{"path": a.Path}, nil
}

func (w *worker) create(a PathArgs) (any, error) {
	if err := a.checkNew(); err != nil {
		return nil, err
	}
	f, err := w.root.OpenFile(a.Path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	if err := f.Close(); err != nil {
		return nil, mapErr(err, a.Path)
	}
	return map[string]string{"path": a.Path}, nil
}

// Changed counts what an operation changed.
type Changed struct {
	Count int `json:"count"`
	// Skipped counts what was left alone (already gone, a link where
	// permissions do not apply, an archive entry that was not taken).
	Skipped int `json:"skipped"`
}

func (w *worker) delete(a PathsArgs) (any, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	out := Changed{}
	for _, p := range a.Paths {
		if _, err := w.root.Lstat(p); errors.Is(err, fs.ErrNotExist) {
			out.Skipped++
			continue
		}
		if err := w.root.RemoveAll(p); err != nil {
			return out, mapErr(err, p)
		}
		out.Count++
	}
	return out, nil
}

// isDir reports whether p is (or leads to) a folder.
func (w *worker) isDir(p string) error {
	info, err := w.root.Stat(p)
	if err != nil {
		return mapErr(err, p)
	}
	if !info.IsDir() {
		return protocol.Fail(CodeType, "/"+p+" is a file, not a folder")
	}
	return nil
}

// targets works out where each path goes in folder to, refusing the whole
// operation before anything is done when one of them is taken.
func (w *worker) targets(paths []string, to string) (map[string]string, error) {
	if err := w.isDir(to); err != nil {
		return nil, err
	}
	out := map[string]string{}
	for _, p := range paths {
		if _, err := w.root.Lstat(p); err != nil {
			return nil, mapErr(err, p)
		}
		t := join(to, path.Base(p))
		if t == p {
			continue // already there
		}
		if _, err := w.root.Lstat(t); err == nil {
			return nil, mapErr(fs.ErrExist, t)
		}
		out[p] = t
	}
	return out, nil
}

func (w *worker) move(a MoveArgs) (any, error) {
	if err := a.check(true); err != nil {
		return nil, err
	}
	t, err := w.targets(a.Paths, a.To)
	if err != nil {
		return nil, err
	}
	out := Changed{Skipped: len(a.Paths) - len(t)}
	for _, p := range a.Paths {
		if to, ok := t[p]; ok {
			if err := w.root.Rename(p, to); err != nil {
				return out, mapErr(err, p)
			}
			out.Count++
		}
	}
	return out, nil
}

func (w *worker) rename(a RenameArgs) (any, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	if _, err := w.root.Lstat(a.From); err != nil {
		return nil, mapErr(err, a.From)
	}
	if a.From == a.To {
		return map[string]string{"from": a.From, "to": a.To}, nil
	}
	if err := w.isDir(path.Dir(a.To)); err != nil {
		return nil, err
	}
	if _, err := w.root.Lstat(a.To); err == nil {
		return nil, mapErr(fs.ErrExist, a.To)
	}
	if err := w.root.Rename(a.From, a.To); err != nil {
		return nil, mapErr(err, a.From)
	}
	return map[string]string{"from": a.From, "to": a.To}, nil
}

func (w *worker) copy(a MoveArgs) (any, error) {
	if err := a.check(false); err != nil {
		return nil, err
	}
	t, err := w.targets(a.Paths, a.To)
	if err != nil {
		return nil, err
	}
	out := Changed{Skipped: len(a.Paths) - len(t)}
	for _, p := range a.Paths {
		if to, ok := t[p]; ok {
			n, err := w.copyTree(p, to, 0)
			out.Count += n
			if err != nil {
				return out, err
			}
		}
	}
	return out, nil
}

// copyTree copies p to the new path to: folders with what is in them, files,
// and links as links. It returns how many it made.
func (w *worker) copyTree(p, to string, depth int) (int, error) {
	if depth > 256 {
		return 0, protocol.Fail(CodeTooBig, "/"+p+" is nested too deep to copy")
	}
	info, err := w.root.Lstat(p)
	if err != nil {
		return 0, mapErr(err, p)
	}
	switch {
	case info.IsDir():
		if err := w.root.Mkdir(to, info.Mode().Perm()|0o700); err != nil {
			return 0, mapErr(err, to)
		}
		dir, err := w.root.Open(p)
		if err != nil {
			return 1, mapErr(err, p)
		}
		defer dir.Close()
		n := 1
		for {
			des, err := dir.ReadDir(1024)
			for _, de := range des {
				m, cerr := w.copyTree(join(p, de.Name()), join(to, de.Name()), depth+1)
				n += m
				if cerr != nil {
					return n, cerr
				}
			}
			if errors.Is(err, io.EOF) || (err == nil && len(des) == 0) {
				break
			}
			if err != nil {
				return n, mapErr(err, p)
			}
		}
		_ = w.root.Chmod(to, info.Mode().Perm())
		return n, nil
	case info.Mode().IsRegular():
		src, err := w.root.Open(p)
		if err != nil {
			return 0, mapErr(err, p)
		}
		defer src.Close()
		dst, err := w.root.OpenFile(to, os.O_WRONLY|os.O_CREATE|os.O_EXCL, info.Mode().Perm())
		if err != nil {
			return 0, mapErr(err, to)
		}
		_, err = io.Copy(dst, src)
		if cerr := dst.Close(); err == nil {
			err = cerr
		}
		if err != nil {
			_ = w.root.Remove(to)
			return 0, mapErr(err, to)
		}
		return 1, nil
	case info.Mode()&fs.ModeSymlink != 0:
		target, err := w.root.Readlink(p)
		if err != nil {
			return 0, mapErr(err, p)
		}
		if err := w.root.Symlink(target, to); err != nil {
			return 0, mapErr(err, to)
		}
		return 1, nil
	}
	return 0, nil // devices, sockets and pipes are not copied
}

func (w *worker) chmod(a ChmodArgs) (any, error) {
	mode, err := a.check()
	if err != nil {
		return nil, err
	}
	out := Changed{}
	for _, p := range a.Paths {
		info, err := w.root.Lstat(p)
		if err != nil {
			return out, mapErr(err, p)
		}
		if info.Mode()&fs.ModeSymlink != 0 {
			out.Skipped++ // a link has no permissions of its own
			continue
		}
		if err := w.root.Chmod(p, fs.FileMode(mode)); err != nil {
			return out, mapErr(err, p)
		}
		out.Count++
	}
	return out, nil
}

func (w *worker) upload(a UploadArgs) (any, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	if w.in == nil {
		return nil, protocol.Fail(CodeStaged, "no staged upload was given")
	}
	if err := w.isDir(a.Dir); err != nil {
		return nil, err
	}
	p := a.target()
	mode := fs.FileMode(0o644)
	if info, err := w.root.Lstat(p); err == nil {
		if !a.Overwrite {
			return nil, mapErr(fs.ErrExist, p)
		}
		if !info.Mode().IsRegular() {
			return nil, protocol.Fail(CodeType, "/"+p+" is not a file, so an upload cannot replace it")
		}
		mode = info.Mode().Perm()
	}
	n, err := w.replace(p, mode, w.in, MaxUpload, a.Overwrite)
	if err != nil {
		return nil, err
	}
	return Written{Path: p, Size: n}, nil
}

// Downloaded is files.download's answer.
type Downloaded struct {
	Name string `json:"name"`
	Size int64  `json:"size"`
}

func (w *worker) download(a DownloadArgs) (any, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	if w.out == nil {
		return nil, protocol.Fail(CodeStaged, "nowhere was given to put the download")
	}
	f, err := w.root.Open(a.Path)
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	if !info.Mode().IsRegular() {
		return nil, protocol.Fail(CodeType, "/"+a.Path+" is not a file; compress a folder to download it")
	}
	if info.Size() > MaxDownload {
		return nil, protocol.Fail(CodeTooBig, "/"+a.Path+" is larger than 2 GB")
	}
	n, err := io.Copy(w.out, io.LimitReader(f, MaxDownload+1))
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	if n > MaxDownload {
		return nil, protocol.Fail(CodeTooBig, "/"+a.Path+" is larger than 2 GB")
	}
	return Downloaded{Name: path.Base(a.Path), Size: n}, nil
}
