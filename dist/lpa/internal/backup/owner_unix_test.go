//go:build unix

package backup

import (
	"os"
	"syscall"
)

func owner(fi os.FileInfo) (int, int) {
	st := fi.Sys().(*syscall.Stat_t)
	return int(st.Uid), int(st.Gid)
}
