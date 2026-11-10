//go:build !linux

package sysinfo

import "errors"

func statfs(string) (FSStats, error) {
	return FSStats{}, errors.New("statfs is only implemented on Linux")
}
