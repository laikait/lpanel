//go:build !linux

package web

import "os"

// fileKey is the file's path where there are no inodes to go by (the
// agent's tests on Windows; the agent itself only runs on Linux).
func fileKey(path string, _ os.FileInfo) string { return path }
