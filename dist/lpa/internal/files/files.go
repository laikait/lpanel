// Package files is the file manager's side of the agent: listing, reading,
// writing, moving, copying, deleting, compressing and extracting files in a
// hosting account's home, and measuring what takes its space.
//
// The agent never touches an account's files as root. Each operation runs
// in a short-lived worker, the agent's own binary started as the account's
// user ("lpa files-worker"), which opens the home as an os.Root: every path
// is resolved beneath the home, and a link that leads out of it is refused.
// The kernel then applies the account's own permissions and disk quota to
// everything the worker does, as it would to the account's own SSH session or
// PHP.
//
// File contents never travel in a request (requests are small, signed and
// journalled). The panel puts what it uploads or saves in a staging folder
// only it and root can open; the agent opens that file and hands the worker
// the open descriptor, never the path. Downloads go the other way.
package files

import (
	"errors"
	"fmt"
	"path"
	"strings"
	"unicode/utf8"

	"lpa/internal/protocol"
)

// Error codes particular to files. The panel shows each one differently.
const (
	CodeMissing   = "files_missing"   // no such file or folder
	CodeExists    = "files_exists"    // something of that name is already there
	CodeDenied    = "files_denied"    // the account's own permissions refuse it
	CodeQuota     = "files_quota"     // the account is out of disk space or files
	CodeOutside   = "files_outside"   // a link leads out of the home
	CodeType      = "files_type"      // a file where a folder is needed, or the reverse
	CodeTooBig    = "files_too_big"   // larger than the operation takes
	CodeBinary    = "files_binary"    // not text, so not for the editor
	CodeChanged   = "files_changed"   // changed since it was opened
	CodeProtected = "files_protected" // one of the folders the server keeps
	CodeArchive   = "files_archive"   // not an archive this reads, or a broken one
	CodeStaged    = "files_staged"    // the staged upload is missing or not a plain file
	CodeInto      = "files_into"      // a folder cannot go into itself
)

// Limits.
const (
	// MaxPath is the longest path, in bytes (Linux's PATH_MAX).
	MaxPath = 4096
	// MaxName is the longest name of one file or folder (NAME_MAX).
	MaxName = 255
	// MaxEdit is the largest file the editor opens or saves.
	MaxEdit = 4 << 20
	// MaxUpload is the largest upload the agent takes.
	MaxUpload = 1 << 30
	// MaxDownload is the largest file it hands out.
	MaxDownload = 2 << 30
	// MaxList is the most entries a folder listing returns.
	MaxList = 10000
	// MaxPaths is the most paths one operation takes.
	MaxPaths = 1000
	// MaxExtract bounds what one archive may unpack to: total bytes and
	// entries, so a small archive cannot fill the disk by itself.
	MaxExtract        = 8 << 30
	MaxExtractEntries = 200000
)

// Protected are the folders at the top of the home that the server made and
// keeps: their contents are the owner's, but they themselves cannot be
// deleted, renamed, moved or have their permissions changed here.
var Protected = map[string]bool{"public_html": true, "mail": true, "tmp": true, "logs": true}

func badArgs(format string, a ...any) error {
	return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf(format, a...))
}

// Clean checks a path within the home and returns it in canonical form: ""
// or "/" is the home itself ("."), a leading "/" is the home, and nothing may
// climb above it.
func Clean(p string) (string, error) {
	if len(p) > MaxPath {
		return "", badArgs("a path is longer than %d bytes", MaxPath)
	}
	if !utf8.ValidString(p) || strings.ContainsRune(p, 0) {
		return "", badArgs("a path is not valid text")
	}
	c := path.Clean("/" + p) // a rooted Clean cannot climb above "/"
	if strings.Contains("/"+p+"/", "/../") {
		return "", badArgs("a path may not contain \"..\"")
	}
	c = strings.TrimPrefix(c, "/")
	if c == "" {
		return ".", nil
	}
	for _, part := range strings.Split(c, "/") {
		if len(part) > MaxName {
			return "", badArgs("a name is longer than %d bytes", MaxName)
		}
	}
	return c, nil
}

// CheckName checks a name the owner gives a new file or folder: one name, not
// a path, with no control characters.
func CheckName(name string) error {
	switch {
	case name == "" || name == "." || name == "..":
		return badArgs("give a name")
	case len(name) > MaxName:
		return badArgs("a name is longer than %d bytes", MaxName)
	case !utf8.ValidString(name):
		return badArgs("a name is not valid text")
	case strings.ContainsRune(name, '/'):
		return badArgs("a name may not contain \"/\"")
	}
	for _, r := range name {
		if r < 0x20 || r == 0x7f {
			return badArgs("a name may not contain control characters")
		}
	}
	return nil
}

// cleanNew cleans a path whose last part is a name being made.
func cleanNew(p string) (string, error) {
	c, err := Clean(p)
	if err != nil {
		return "", err
	}
	if c == "." {
		return "", badArgs("give a name")
	}
	if err := CheckName(path.Base(c)); err != nil {
		return "", err
	}
	return c, nil
}

// cleanAll cleans a list of existing paths: at least one, at most MaxPaths,
// none of them the home.
func cleanAll(ps []string) ([]string, error) {
	if len(ps) == 0 {
		return nil, badArgs("choose at least one file or folder")
	}
	if len(ps) > MaxPaths {
		return nil, badArgs("at most %d files or folders at once", MaxPaths)
	}
	out := make([]string, 0, len(ps))
	seen := map[string]bool{}
	for _, p := range ps {
		c, err := Clean(p)
		if err != nil {
			return nil, err
		}
		if c == "." {
			return nil, badArgs("the home folder itself cannot be chosen")
		}
		if !seen[c] {
			seen[c] = true
			out = append(out, c)
		}
	}
	return out, nil
}

// protected refuses a path that is one of the server's own folders.
func protected(p string) error {
	if Protected[p] {
		return protocol.Fail(CodeProtected, p+" is one of the folders the server keeps; what is in it can be changed, but not the folder itself")
	}
	return nil
}

// Mode reads a permission mode such as "0644" or "755": the nine permission
// bits only, never setuid, setgid or sticky.
func Mode(s string) (uint32, error) {
	if len(s) == 4 && s[0] == '0' {
		s = s[1:]
	}
	if len(s) == 0 || len(s) > 3 || strings.Trim(s, "01234567") != "" {
		return 0, badArgs("permissions must be three octal digits such as 644 or 755")
	}
	var m uint32
	for _, c := range s {
		m = m*8 + uint32(c-'0')
	}
	return m, nil
}

// ArchiveFormat names the archive format of a file by its name, or "".
func ArchiveFormat(name string) string {
	n := strings.ToLower(name)
	switch {
	case strings.HasSuffix(n, ".zip"):
		return "zip"
	case strings.HasSuffix(n, ".tar.gz"), strings.HasSuffix(n, ".tgz"):
		return "tar.gz"
	case strings.HasSuffix(n, ".tar"):
		return "tar"
	}
	return ""
}

func failInto(p string) error {
	return protocol.Fail(CodeInto, p+" cannot go into itself")
}

// errStop ends a walk early (a limit was reached).
var errStop = errors.New("stop")
