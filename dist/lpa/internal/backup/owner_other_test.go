//go:build !unix

package backup

import "os"

func owner(os.FileInfo) (int, int) { return -1, -1 }
