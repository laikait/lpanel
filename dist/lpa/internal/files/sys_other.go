//go:build !linux

package files

import (
	"context"
	"errors"
	"io/fs"
	"os"
)

const noFollow = 0

func setUmask() {}

func ownerOf(fs.FileInfo) (uint32, bool) { return 0, false }

func isQuota(error) bool { return false }

func links(fs.FileInfo) (uint64, bool) { return 0, false }

func allocated(info fs.FileInfo) int64 { return info.Size() }

func (m *Manager) execWorker(context.Context, int, int, string, []byte, *os.File, *os.File) ([]byte, error) {
	return nil, errors.New("file workers run on Linux only")
}
