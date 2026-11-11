package web

import (
	"errors"
	"strings"
	"syscall"

	"lpa/internal/protocol"
)

const dirFlags = syscall.O_RDONLY | syscall.O_DIRECTORY | syscall.O_NOFOLLOW | syscall.O_CLOEXEC

// makeDirs makes an account's tmp and logs folders and its document roots.
//
// The home belongs to the account, which can change anything in it at any
// moment. So the walk uses directory descriptors: each step is opened with
// O_NOFOLLOW relative to the one before, a link anywhere on the way is
// refused rather than followed, and what gets chowned is exactly the folder
// just opened.
//
// A document root's top folder (public_html) gets the web server's group and
// g+rx, so Apache can read static files while other accounts cannot. Deeper
// folders are left as they are, or made 0755 for the account. It returns the
// folders it made, for restorecon.
func makeDirs(home string, docroots []string, uid, gid, webGID int) ([]string, error) {
	hfd, err := syscall.Open(home, dirFlags, 0)
	if err != nil {
		return nil, refuse(err, "the home folder")
	}
	defer syscall.Close(hfd)
	var st syscall.Stat_t
	if err := syscall.Fstat(hfd, &st); err != nil {
		return nil, err
	}
	if int(st.Uid) != uid {
		return nil, protocol.Fail(CodeDocroot, "the home folder does not belong to the account")
	}
	var made []string

	for _, d := range []struct {
		name string
		mode uint32
	}{{"tmp", 0o700}, {"logs", 0o750}} {
		fd, created, err := child(hfd, d.name, d.mode, uid, gid)
		if err != nil {
			return nil, refuse(err, d.name)
		}
		syscall.Close(fd)
		if created {
			made = append(made, home+"/"+d.name)
		}
	}

	for _, rel := range docroots {
		dirs, err := makeRoot(hfd, home, rel, uid, gid, webGID)
		made = append(made, dirs...)
		if err != nil {
			return made, err
		}
	}
	return made, nil
}

func makeRoot(hfd int, home, rel string, uid, gid, webGID int) (made []string, err error) {
	parts := strings.Split(rel, "/")
	fd, created, err := child(hfd, parts[0], 0o750, uid, webGID)
	if err != nil {
		return nil, refuse(err, "the document root "+rel)
	}
	if created {
		made = append(made, home+"/"+parts[0])
	}
	defer func() { syscall.Close(fd) }()
	if !created {
		var st syscall.Stat_t
		if err := syscall.Fstat(fd, &st); err != nil {
			return made, err
		}
		if int(st.Uid) != uid {
			return made, protocol.Fail(CodeDocroot, parts[0]+" does not belong to the account")
		}
		if int(st.Gid) != webGID {
			if err := syscall.Fchown(fd, -1, webGID); err != nil {
				return made, err
			}
		}
		if st.Mode&0o050 != 0o050 {
			if err := syscall.Fchmod(fd, (st.Mode&0o7777)|0o050); err != nil {
				return made, err
			}
		}
	}
	path := home + "/" + parts[0]
	for _, name := range parts[1:] {
		next, created, err := child(fd, name, 0o755, uid, gid)
		if err != nil {
			return made, refuse(err, "the document root "+rel)
		}
		syscall.Close(fd)
		fd = next
		path += "/" + name
		if created {
			made = append(made, path)
		}
	}
	return made, nil
}

// child opens dir/name without following a link, making it first (owned by
// uid:gid, with mode) when it is not there.
func child(dir int, name string, mode uint32, uid, gid int) (fd int, created bool, err error) {
	if err := syscall.Mkdirat(dir, name, 0o700); err == nil {
		created = true
	} else if !errors.Is(err, syscall.EEXIST) {
		return -1, false, err
	}
	fd, err = syscall.Openat(dir, name, dirFlags, 0)
	if err != nil {
		return -1, false, err
	}
	if created {
		if err := syscall.Fchown(fd, uid, gid); err != nil {
			syscall.Close(fd)
			return -1, false, err
		}
		if err := syscall.Fchmod(fd, mode); err != nil {
			syscall.Close(fd)
			return -1, false, err
		}
	}
	return fd, created, nil
}

func refuse(err error, what string) error {
	if errors.Is(err, syscall.ELOOP) || errors.Is(err, syscall.ENOTDIR) {
		return protocol.Fail(CodeDocroot, what+" crosses something that is not a plain folder (a link or a file), so it was left alone")
	}
	return err
}
