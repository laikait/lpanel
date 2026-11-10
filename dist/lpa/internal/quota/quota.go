// Package quota sets and reads kernel disk quotas for users (ADR-0008).
//
// The kernel enforces them: a hosting account that reaches its limit gets
// "Disk quota exceeded" from every program, PHP and mail delivery included,
// with no panel process involved.
package quota

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"path"
	"strings"
)

// Limits for one user. Zero means unlimited.
type Limits struct {
	BlocksKiB uint64 `json:"blocks_kib"`
	Inodes    uint64 `json:"inodes"`
}

// Usage is what a user has used, and the limits in force.
type Usage struct {
	UsedBytes  uint64 `json:"used_bytes"`
	UsedInodes uint64 `json:"used_inodes"`
	LimitKiB   uint64 `json:"limit_kib"`
	InodeLimit uint64 `json:"inode_limit"`
}

// ErrOff means quotas are switched off in the agent's configuration.
var ErrOff = errors.New("disk quotas are switched off on this server")

// ErrNotEnabled means the filesystem holding the homes has no user quotas.
var ErrNotEnabled = errors.New("the filesystem holding the homes does not have user quotas switched on (ADR-0008)")

// Quotas sets and reads the quotas of the filesystem holding dir.
type Quotas interface {
	Set(dir string, uid int, l Limits) error
	Get(dir string, uid int) (Usage, error)
}

// Off is used when the configuration says quota "off": nothing is set and
// usage is unknown.
type Off struct{}

// Set implements Quotas.
func (Off) Set(string, int, Limits) error { return nil }

// Get implements Quotas.
func (Off) Get(string, int) (Usage, error) { return Usage{}, ErrOff }

// Kernel uses quotactl(2).
type Kernel struct {
	// Mounts is the mount table to read; /proc/self/mounts when empty.
	Mounts string
}

// mountOf finds the block device and mount point of the filesystem that holds
// dir: the longest mount point that is dir or one of its parents.
func mountOf(table, dir string) (device, mount string, err error) {
	if table == "" {
		table = "/proc/self/mounts"
	}
	f, err := os.Open(table)
	if err != nil {
		return "", "", err
	}
	defer f.Close()
	dir = path.Clean(dir)
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 2 {
			continue
		}
		mp := unescape(fields[1])
		if (dir == mp || mp == "/" || strings.HasPrefix(dir, mp+"/")) && len(mp) >= len(mount) {
			device, mount = unescape(fields[0]), mp
		}
	}
	if err := sc.Err(); err != nil {
		return "", "", err
	}
	if mount == "" {
		return "", "", fmt.Errorf("no filesystem holds %s", dir)
	}
	return device, mount, nil
}

// unescape undoes the octal escapes the mount table uses for spaces and tabs.
func unescape(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+3 < len(s) {
			var c byte
			if _, err := fmt.Sscanf(s[i+1:i+4], "%03o", &c); err == nil {
				b.WriteByte(c)
				i += 3
				continue
			}
		}
		b.WriteByte(s[i])
	}
	return b.String()
}
