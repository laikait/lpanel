package files

import (
	"path"
	"regexp"
	"strings"
)

// Every operation's arguments. The agent checks them before starting a
// worker, and the worker checks them again; check() also puts each path in
// its canonical form.

// stagedRule is a staged file's name: 64 hex digits the panel chose.
var stagedRule = regexp.MustCompile(`^[0-9a-f]{64}$`)

func checkStaged(token string) error {
	if !stagedRule.MatchString(token) {
		return badArgs("a staged file is named by 64 hex digits")
	}
	return nil
}

// PathArgs name one path: files.list, files.read, files.mkdir, files.create,
// files.usage.
type PathArgs struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

func (a *PathArgs) check() (err error) {
	a.Path, err = Clean(a.Path)
	return err
}

func (a *PathArgs) checkNew() (err error) {
	a.Path, err = cleanNew(a.Path)
	return err
}

// WriteArgs are files.write's: the staged content replaces the file's.
// Expect, when set, is the SHA-256 the file had when it was opened; a file
// that has changed since is left alone. Create means the file must be new.
type WriteArgs struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Staged   string `json:"staged"`
	Expect   string `json:"expect"`
	Create   bool   `json:"create"`
}

var shaRule = regexp.MustCompile(`^[0-9a-f]{64}$`)

func (a *WriteArgs) check() (err error) {
	if a.Path, err = cleanNew(a.Path); err != nil {
		return err
	}
	if a.Expect != "" && !shaRule.MatchString(a.Expect) {
		return badArgs("expect is a SHA-256 in hex")
	}
	if a.Expect != "" && a.Create {
		return badArgs("a new file has nothing to expect")
	}
	return checkStaged(a.Staged)
}

// PathsArgs name several paths: files.delete.
type PathsArgs struct {
	Username string   `json:"username"`
	Paths    []string `json:"paths"`
}

func (a *PathsArgs) check() (err error) {
	if a.Paths, err = cleanAll(a.Paths); err != nil {
		return err
	}
	for _, p := range a.Paths {
		if err := protected(p); err != nil {
			return err
		}
	}
	return nil
}

// MoveArgs move or copy paths into a folder: files.move, files.copy.
type MoveArgs struct {
	Username string   `json:"username"`
	Paths    []string `json:"paths"`
	To       string   `json:"to"`
}

func (a *MoveArgs) check(moving bool) (err error) {
	if a.Paths, err = cleanAll(a.Paths); err != nil {
		return err
	}
	if a.To, err = Clean(a.To); err != nil {
		return err
	}
	for _, p := range a.Paths {
		if moving {
			if err := protected(p); err != nil {
				return err
			}
		}
		if a.To == p || strings.HasPrefix(a.To, p+"/") {
			return failInto(p)
		}
	}
	return nil
}

// RenameArgs rename one path; To may be in another folder.
type RenameArgs struct {
	Username string `json:"username"`
	From     string `json:"from"`
	To       string `json:"to"`
}

func (a *RenameArgs) check() (err error) {
	if a.From, err = Clean(a.From); err != nil {
		return err
	}
	if a.From == "." {
		return badArgs("the home folder itself cannot be renamed")
	}
	if err := protected(a.From); err != nil {
		return err
	}
	if a.To, err = cleanNew(a.To); err != nil {
		return err
	}
	if strings.HasPrefix(a.To, a.From+"/") {
		return failInto(a.From)
	}
	return nil
}

// ChmodArgs set the permissions of paths (not recursively).
type ChmodArgs struct {
	Username string   `json:"username"`
	Paths    []string `json:"paths"`
	Mode     string   `json:"mode"`
}

func (a *ChmodArgs) check() (mode uint32, err error) {
	if a.Paths, err = cleanAll(a.Paths); err != nil {
		return 0, err
	}
	for _, p := range a.Paths {
		if err := protected(p); err != nil {
			return 0, err
		}
	}
	return Mode(a.Mode)
}

// CompressArgs pack paths, all in one folder, into a new archive.
type CompressArgs struct {
	Username string   `json:"username"`
	Paths    []string `json:"paths"`
	To       string   `json:"to"`
}

func (a *CompressArgs) check() (format string, err error) {
	if a.Paths, err = cleanAll(a.Paths); err != nil {
		return "", err
	}
	parent := path.Dir(a.Paths[0])
	for _, p := range a.Paths {
		if path.Dir(p) != parent {
			return "", badArgs("everything compressed together must be in one folder")
		}
	}
	if a.To, err = cleanNew(a.To); err != nil {
		return "", err
	}
	format = ArchiveFormat(a.To)
	if format == "" {
		return "", badArgs("an archive's name ends in .zip, .tar.gz, .tgz or .tar")
	}
	return format, nil
}

// ExtractArgs unpack an archive into a folder (made when missing).
type ExtractArgs struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	To       string `json:"to"`
}

func (a *ExtractArgs) check() (format string, err error) {
	if a.Path, err = Clean(a.Path); err != nil {
		return "", err
	}
	if a.To, err = Clean(a.To); err != nil {
		return "", err
	}
	format = ArchiveFormat(a.Path)
	if format == "" {
		return "", badArgs("only .zip, .tar.gz, .tgz and .tar archives are extracted")
	}
	return format, nil
}

// UploadArgs put a staged upload into a folder under a name.
type UploadArgs struct {
	Username  string `json:"username"`
	Dir       string `json:"dir"`
	Name      string `json:"name"`
	Staged    string `json:"staged"`
	Overwrite bool   `json:"overwrite"`
}

func (a *UploadArgs) check() (err error) {
	if a.Dir, err = Clean(a.Dir); err != nil {
		return err
	}
	if err := CheckName(a.Name); err != nil {
		return err
	}
	if len(a.Dir)+1+len(a.Name) > MaxPath {
		return badArgs("a path is longer than %d bytes", MaxPath)
	}
	return checkStaged(a.Staged)
}

// target is the upload's path in the home.
func (a *UploadArgs) target() string {
	if a.Dir == "." {
		return a.Name
	}
	return a.Dir + "/" + a.Name
}

// DownloadArgs copy a file into a staged file the panel made.
type DownloadArgs struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Into     string `json:"into"`
}

func (a *DownloadArgs) check() (err error) {
	if a.Path, err = Clean(a.Path); err != nil {
		return err
	}
	if a.Path == "." {
		return badArgs("choose a file")
	}
	return checkStaged(a.Into)
}
