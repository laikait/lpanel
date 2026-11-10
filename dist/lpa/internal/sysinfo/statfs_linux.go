package sysinfo

import "syscall"

func statfs(path string) (FSStats, error) {
	var s syscall.Statfs_t
	if err := syscall.Statfs(path, &s); err != nil {
		return FSStats{}, err
	}
	var st syscall.Stat_t
	if err := syscall.Stat(path, &st); err != nil {
		return FSStats{}, err
	}
	bs := uint64(s.Bsize)
	return FSStats{
		TotalBytes:  s.Blocks * bs,
		FreeBytes:   s.Bfree * bs,
		AvailBytes:  s.Bavail * bs,
		TotalInodes: s.Files,
		FreeInodes:  s.Ffree,
		Device:      uint64(st.Dev),
	}, nil
}
