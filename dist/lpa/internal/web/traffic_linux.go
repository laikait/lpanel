package web

import (
	"fmt"
	"os"
	"syscall"
)

// fileKey is a log file's identity: its device and inode, which a rename
// keeps.
func fileKey(path string, fi os.FileInfo) string {
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		return fmt.Sprintf("%d:%d", st.Dev, st.Ino)
	}
	return path
}
