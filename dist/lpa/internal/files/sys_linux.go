package files

import (
	"bytes"
	"context"
	"errors"
	"io/fs"
	"os"
	"os/exec"
	"syscall"
)

// noFollow opens a staged file without following a link, and without
// blocking on a pipe someone put in its place.
const noFollow = syscall.O_NOFOLLOW | syscall.O_NONBLOCK

func setUmask() { syscall.Umask(0o022) }

// ownerOf is a file's owner's uid.
func ownerOf(info fs.FileInfo) (uint32, bool) {
	st, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return 0, false
	}
	return st.Uid, true
}

func isQuota(err error) bool {
	return errors.Is(err, syscall.EDQUOT) || errors.Is(err, syscall.ENOSPC)
}

// links is a file's number of hard links.
func links(info fs.FileInfo) (uint64, bool) {
	st, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return 0, false
	}
	return uint64(st.Nlink), true
}

// allocated is the disk space a file takes (what quotas count), not its size.
func allocated(info fs.FileInfo) int64 {
	if st, ok := info.Sys().(*syscall.Stat_t); ok {
		return st.Blocks * 512
	}
	return info.Size()
}

// Exec starts a worker as the account's user: the agent's own binary with
// the one argument "files-worker", no environment beyond a fixed one, and
// the staged files (if any) as descriptors 3 and 4. It is killed with the
// agent, and when ctx ends.
func (m *Manager) execWorker(ctx context.Context, uid, gid int, home string, req []byte, in, out *os.File) ([]byte, error) {
	cmd := exec.CommandContext(ctx, m.Self, "files-worker")
	cmd.Env = []string{"PATH=/usr/bin:/bin", "LANG=C.UTF-8", "HOME=" + home}
	cmd.Dir = "/"
	cmd.Stdin = bytes.NewReader(req)
	var stdout, stderr limited
	stdout.max, stderr.max = 16<<20, 64<<10
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	// Descriptor 3 is always the input slot and 4 the output, even when only
	// the output is given.
	if in != nil || out != nil {
		devnull, err := os.Open(os.DevNull)
		if err != nil {
			return nil, err
		}
		defer devnull.Close()
		a, b := in, out
		if a == nil {
			a = devnull
		}
		if b == nil {
			b = devnull
		}
		cmd.ExtraFiles = []*os.File{a, b}
	}
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Credential: &syscall.Credential{Uid: uint32(uid), Gid: uint32(gid), Groups: []uint32{uint32(gid)}},
		Setsid:     true,
		Pdeathsig:  syscall.SIGKILL,
	}
	err := cmd.Run()
	if ctx.Err() != nil {
		return nil, ctx.Err()
	}
	if err != nil {
		return nil, workerFailed(err, stderr.String())
	}
	return stdout.Bytes(), nil
}
