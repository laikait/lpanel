//go:build !linux

package web

import (
	"os"
	"path/filepath"
)

// makeDirs on other systems is for development only: it makes the folders
// with no ownership and no protection against links. The agent runs on
// Linux (docroot_linux.go).
func makeDirs(home string, docroots []string, _, _, _ int) ([]string, error) {
	for _, d := range append([]string{"tmp", "logs"}, docroots...) {
		if err := os.MkdirAll(filepath.Join(home, filepath.FromSlash(d)), 0o755); err != nil {
			return nil, err
		}
	}
	return nil, nil
}
