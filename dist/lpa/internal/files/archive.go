package files

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"errors"
	"io"
	"io/fs"
	"os"
	"path"
	"strings"
	"time"

	"lpa/internal/protocol"
)

// Packed is files.compress's answer.
type Packed struct {
	Path  string `json:"path"`
	Size  int64  `json:"size"`
	Items int    `json:"items"`
}

// packer writes one archive's entries.
type packer interface {
	dir(name string, info fs.FileInfo) error
	file(name string, info fs.FileInfo, r io.Reader) error
	link(name string, info fs.FileInfo, target string) error
	close() error
}

func (w *worker) compress(a CompressArgs) (any, error) {
	format, err := a.check()
	if err != nil {
		return nil, err
	}
	for _, p := range a.Paths {
		if _, err := w.root.Lstat(p); err != nil {
			return nil, mapErr(err, p)
		}
	}
	f, err := w.root.OpenFile(a.To, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
	if err != nil {
		return nil, mapErr(err, a.To)
	}
	ok := false
	defer func() {
		if !ok {
			f.Close()
			_ = w.root.Remove(a.To)
		}
	}()
	var pk packer
	switch format {
	case "zip":
		pk = &zipPacker{w: zip.NewWriter(f)}
	case "tar.gz":
		gz := gzip.NewWriter(f)
		pk = &tarPacker{w: tar.NewWriter(gz), gz: gz}
	default:
		pk = &tarPacker{w: tar.NewWriter(f)}
	}
	parent := path.Dir(a.Paths[0])
	items := 0
	for _, p := range a.Paths {
		n, err := w.pack(pk, p, strings.TrimPrefix(p, parent+"/"), a.To, 0)
		items += n
		if err != nil {
			return nil, err
		}
	}
	if err := pk.close(); err != nil {
		return nil, mapErr(err, a.To)
	}
	if err := f.Close(); err != nil {
		return nil, mapErr(err, a.To)
	}
	ok = true
	info, err := w.root.Lstat(a.To)
	if err != nil {
		return nil, mapErr(err, a.To)
	}
	return Packed{Path: a.To, Size: info.Size(), Items: items}, nil
}

// pack adds p to the archive as name, folders with what is in them. The
// archive being written is left out.
func (w *worker) pack(pk packer, p, name, archive string, depth int) (int, error) {
	if p == archive {
		return 0, nil
	}
	if depth > 256 {
		return 0, protocol.Fail(CodeTooBig, "/"+p+" is nested too deep to compress")
	}
	info, err := w.root.Lstat(p)
	if err != nil {
		return 0, mapErr(err, p)
	}
	switch {
	case info.IsDir():
		if err := pk.dir(name, info); err != nil {
			return 0, mapErr(err, archive)
		}
		dir, err := w.root.Open(p)
		if err != nil {
			return 0, mapErr(err, p)
		}
		defer dir.Close()
		n := 1
		for {
			des, err := dir.ReadDir(1024)
			for _, de := range des {
				m, perr := w.pack(pk, join(p, de.Name()), name+"/"+de.Name(), archive, depth+1)
				n += m
				if perr != nil {
					return n, perr
				}
			}
			if errors.Is(err, io.EOF) {
				break
			}
			if err != nil {
				return n, mapErr(err, p)
			}
		}
		return n, nil
	case info.Mode().IsRegular():
		f, err := w.root.Open(p)
		if err != nil {
			return 0, mapErr(err, p)
		}
		defer f.Close()
		if err := pk.file(name, info, f); err != nil {
			return 0, mapErr(err, archive)
		}
		return 1, nil
	case info.Mode()&fs.ModeSymlink != 0:
		target, err := w.root.Readlink(p)
		if err != nil {
			return 0, mapErr(err, p)
		}
		if err := pk.link(name, info, target); err != nil {
			return 0, mapErr(err, archive)
		}
		return 1, nil
	}
	return 0, nil
}

type zipPacker struct{ w *zip.Writer }

func (z *zipPacker) dir(name string, info fs.FileInfo) error {
	h, err := zip.FileInfoHeader(info)
	if err != nil {
		return err
	}
	h.Name = name + "/"
	_, err = z.w.CreateHeader(h)
	return err
}

func (z *zipPacker) file(name string, info fs.FileInfo, r io.Reader) error {
	h, err := zip.FileInfoHeader(info)
	if err != nil {
		return err
	}
	h.Name = name
	h.Method = zip.Deflate
	out, err := z.w.CreateHeader(h)
	if err != nil {
		return err
	}
	_, err = io.Copy(out, r)
	return err
}

// Zip files carry no links that every unzip understands; they are left out.
func (z *zipPacker) link(string, fs.FileInfo, string) error { return nil }

func (z *zipPacker) close() error { return z.w.Close() }

type tarPacker struct {
	w  *tar.Writer
	gz *gzip.Writer
}

func (t *tarPacker) header(name string, info fs.FileInfo, link string) (*tar.Header, error) {
	h, err := tar.FileInfoHeader(info, link)
	if err != nil {
		return nil, err
	}
	h.Name = name
	// Owners mean nothing on another server; the account's names are not shared.
	h.Uid, h.Gid, h.Uname, h.Gname = 0, 0, "", ""
	h.Format = tar.FormatPAX
	h.AccessTime, h.ChangeTime = time.Time{}, time.Time{}
	return h, nil
}

func (t *tarPacker) dir(name string, info fs.FileInfo) error {
	h, err := t.header(name+"/", info, "")
	if err != nil {
		return err
	}
	return t.w.WriteHeader(h)
}

func (t *tarPacker) file(name string, info fs.FileInfo, r io.Reader) error {
	h, err := t.header(name, info, "")
	if err != nil {
		return err
	}
	if err := t.w.WriteHeader(h); err != nil {
		return err
	}
	// A file that grows while it is read is cut at the size in its header.
	_, err = io.Copy(t.w, io.LimitReader(r, h.Size))
	return err
}

func (t *tarPacker) link(name string, info fs.FileInfo, target string) error {
	h, err := t.header(name, info, target)
	if err != nil {
		return err
	}
	return t.w.WriteHeader(h)
}

func (t *tarPacker) close() error {
	if err := t.w.Close(); err != nil {
		return err
	}
	if t.gz != nil {
		return t.gz.Close()
	}
	return nil
}

// Unpacked is files.extract's answer.
type Unpacked struct {
	To      string `json:"to"`
	Files   int    `json:"files"`
	Dirs    int    `json:"dirs"`
	Bytes   int64  `json:"bytes"`
	Skipped int    `json:"skipped"`
}

// entryName makes an archive entry's name a path inside the folder being
// extracted to, or "" when it would climb out of it.
func entryName(name string) string {
	name = strings.ReplaceAll(name, "\\", "/")
	c := path.Clean("/" + name)
	if strings.Contains("/"+name+"/", "/../") || c == "/" {
		return ""
	}
	c = strings.TrimPrefix(c, "/")
	if len(c) > MaxPath-256 || !validNames(c) {
		return ""
	}
	return c
}

func validNames(p string) bool {
	for _, part := range strings.Split(p, "/") {
		if CheckName(part) != nil {
			return false
		}
	}
	return true
}

type extractor struct {
	w   *worker
	to  string
	out Unpacked
}

func (w *worker) extract(a ExtractArgs) (any, error) {
	format, err := a.check()
	if err != nil {
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
	if a.To != "." {
		if err := w.root.MkdirAll(a.To, 0o755); err != nil {
			return nil, mapErr(err, a.To)
		}
	}
	if err := w.isDir(a.To); err != nil {
		return nil, err
	}
	x := &extractor{w: w, to: a.To, out: Unpacked{To: a.To}}
	switch format {
	case "zip":
		err = x.zip(f, info.Size())
	case "tar.gz":
		gz, gerr := gzip.NewReader(f)
		if gerr != nil {
			return nil, protocol.Fail(CodeArchive, "/"+a.Path+" is not a gzip-compressed tar archive")
		}
		err = x.tar(gz)
	default:
		err = x.tar(f)
	}
	if err != nil {
		return x.out, err
	}
	return x.out, nil
}

func (x *extractor) count() error {
	if x.out.Files+x.out.Dirs+x.out.Skipped >= MaxExtractEntries {
		return protocol.Fail(CodeTooBig, "the archive has more than 200,000 entries")
	}
	return nil
}

func (x *extractor) mkdir(name string, mode fs.FileMode) error {
	p := join(x.to, name)
	if info, err := x.w.root.Stat(p); err == nil {
		if !info.IsDir() {
			x.out.Skipped++
		}
		return nil
	}
	if err := x.w.root.MkdirAll(p, mode.Perm()|0o700); err != nil {
		return mapErr(err, p)
	}
	x.out.Dirs++
	return nil
}

// file writes one entry; one that is already there is left as it is.
func (x *extractor) file(name string, mode fs.FileMode, r io.Reader) error {
	p := join(x.to, name)
	if dir := path.Dir(name); dir != "." {
		if err := x.w.root.MkdirAll(join(x.to, dir), 0o755); err != nil {
			return mapErr(err, p)
		}
	}
	f, err := x.w.root.OpenFile(p, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode.Perm()|0o600)
	if errors.Is(err, fs.ErrExist) {
		x.out.Skipped++
		return nil
	}
	if err != nil {
		return mapErr(err, p)
	}
	left := MaxExtract - x.out.Bytes
	n, err := io.Copy(f, io.LimitReader(r, left+1))
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	x.out.Bytes += n
	if err == nil && n > left {
		err = protocol.Fail(CodeTooBig, "the archive unpacks to more than 8 GB")
	}
	if err != nil {
		_ = x.w.root.Remove(p)
		var pe *protocol.Error
		if errors.As(err, &pe) {
			return pe
		}
		if errors.Is(err, zip.ErrChecksum) || errors.Is(err, io.ErrUnexpectedEOF) || errors.Is(err, gzip.ErrChecksum) {
			return protocol.Fail(CodeArchive, "the archive is damaged at "+name)
		}
		return mapErr(err, p)
	}
	x.out.Files++
	return nil
}

func (x *extractor) zip(f *os.File, size int64) error {
	zr, err := zip.NewReader(f, size)
	// An entry that would climb out is refused one by one below (entryName),
	// whatever GODEBUG says about such names.
	if err != nil && !(errors.Is(err, zip.ErrInsecurePath) && zr != nil) {
		return protocol.Fail(CodeArchive, "this is not a zip archive, or it is damaged")
	}
	for _, e := range zr.File {
		if err := x.count(); err != nil {
			return err
		}
		name := entryName(e.Name)
		mode := e.Mode()
		switch {
		case name == "":
			x.out.Skipped++
		case mode.IsDir():
			if err := x.mkdir(name, mode); err != nil {
				return err
			}
		case mode.IsRegular():
			r, err := e.Open()
			if err != nil {
				return protocol.Fail(CodeArchive, "the archive is damaged at "+e.Name)
			}
			err = x.file(name, mode, r)
			r.Close()
			if err != nil {
				return err
			}
		default:
			x.out.Skipped++ // links and anything else are not made
		}
	}
	return nil
}

func (x *extractor) tar(r io.Reader) error {
	tr := tar.NewReader(r)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil && !(errors.Is(err, tar.ErrInsecurePath) && h != nil) {
			return protocol.Fail(CodeArchive, "this is not a tar archive, or it is damaged")
		}
		if err := x.count(); err != nil {
			return err
		}
		name := entryName(h.Name)
		mode := fs.FileMode(h.Mode).Perm()
		switch {
		case name == "":
			x.out.Skipped++
		case h.Typeflag == tar.TypeDir:
			if err := x.mkdir(name, mode); err != nil {
				return err
			}
		case h.Typeflag == tar.TypeReg:
			if err := x.file(name, mode, tr); err != nil {
				return err
			}
		default:
			x.out.Skipped++ // links, devices and the rest are not made
		}
	}
}
