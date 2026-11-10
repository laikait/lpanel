// Package sysinfo reads what the machine is and how it is doing, from /proc,
// /etc/os-release and statfs. It runs nothing.
//
// Every path is read under Root, so tests point it at a fixture tree.
package sysinfo

import (
	"bufio"
	"bytes"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

// FSStats is what statfs says about one filesystem.
type FSStats struct {
	TotalBytes  uint64
	FreeBytes   uint64
	AvailBytes  uint64
	TotalInodes uint64
	FreeInodes  uint64
	Device      uint64 // to fold bind mounts of one filesystem into one entry
}

// Reader reads from Root ("/" in production).
type Reader struct {
	Root   string
	Statfs func(path string) (FSStats, error)
}

// New reads the real machine.
func New() *Reader { return &Reader{Root: "/", Statfs: statfs} }

func (r *Reader) read(path string) ([]byte, error) {
	return os.ReadFile(filepath.Join(r.Root, filepath.FromSlash(path)))
}

func (r *Reader) line(path string) (string, error) {
	b, err := r.read(path)
	return strings.TrimSpace(string(b)), err
}

// OSRelease is the part of os-release the panel uses.
type OSRelease struct {
	ID         string `json:"id"`
	VersionID  string `json:"version_id"`
	Codename   string `json:"version_codename"`
	PrettyName string `json:"pretty_name"`
}

// OSRelease reads /etc/os-release, falling back to /usr/lib/os-release.
func (r *Reader) OSRelease() (OSRelease, error) {
	data, err := r.read("etc/os-release")
	if err != nil {
		data, err = r.read("usr/lib/os-release")
	}
	if err != nil {
		return OSRelease{}, err
	}

	values := map[string]string{}
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		key, value, ok := strings.Cut(strings.TrimSpace(sc.Text()), "=")
		if !ok || strings.HasPrefix(key, "#") {
			continue
		}
		if unq, err := strconv.Unquote(value); err == nil {
			value = unq
		} else {
			value = strings.Trim(value, `'`)
		}
		values[key] = value
	}
	rel := OSRelease{ID: values["ID"], VersionID: values["VERSION_ID"], Codename: values["VERSION_CODENAME"], PrettyName: values["PRETTY_NAME"]}
	if rel.ID == "" {
		return rel, errors.New("os-release has no ID")
	}
	return rel, nil
}

// Kernel is the running kernel's release string.
func (r *Reader) Kernel() (string, error) { return r.line("proc/sys/kernel/osrelease") }

// Hostname is the kernel's host name.
func (r *Reader) Hostname() (string, error) { return r.line("proc/sys/kernel/hostname") }

// MachineID is /etc/machine-id, which callers hash before it leaves the machine.
func (r *Reader) MachineID() (string, error) { return r.line("etc/machine-id") }

// CPUCount counts processors in /proc/cpuinfo.
func (r *Reader) CPUCount() (int, error) {
	data, err := r.read("proc/cpuinfo")
	if err != nil {
		return 0, err
	}
	n := 0
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		if key, _, ok := strings.Cut(sc.Text(), ":"); ok && strings.TrimSpace(key) == "processor" {
			n++
		}
	}
	if n == 0 {
		return 0, errors.New("no processors listed in /proc/cpuinfo")
	}
	return n, nil
}

// Memory is in bytes.
type Memory struct {
	TotalBytes     uint64 `json:"total_bytes"`
	AvailableBytes uint64 `json:"available_bytes"`
	SwapTotalBytes uint64 `json:"swap_total_bytes"`
	SwapFreeBytes  uint64 `json:"swap_free_bytes"`
}

// Memory reads /proc/meminfo.
func (r *Reader) Memory() (Memory, error) {
	data, err := r.read("proc/meminfo")
	if err != nil {
		return Memory{}, err
	}
	kb := map[string]uint64{}
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		key, rest, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		fields := strings.Fields(rest)
		if len(fields) == 0 {
			continue
		}
		if v, err := strconv.ParseUint(fields[0], 10, 64); err == nil {
			kb[key] = v
		}
	}
	if _, ok := kb["MemTotal"]; !ok {
		return Memory{}, errors.New("/proc/meminfo has no MemTotal")
	}
	return Memory{
		TotalBytes:     kb["MemTotal"] * 1024,
		AvailableBytes: kb["MemAvailable"] * 1024,
		SwapTotalBytes: kb["SwapTotal"] * 1024,
		SwapFreeBytes:  kb["SwapFree"] * 1024,
	}, nil
}

// Load is the load average.
type Load struct {
	One     float64 `json:"one"`
	Five    float64 `json:"five"`
	Fifteen float64 `json:"fifteen"`
}

// Load reads /proc/loadavg.
func (r *Reader) Load() (Load, error) {
	s, err := r.line("proc/loadavg")
	if err != nil {
		return Load{}, err
	}
	f := strings.Fields(s)
	if len(f) < 3 {
		return Load{}, errors.New("/proc/loadavg is malformed")
	}
	var v [3]float64
	for i := range v {
		if v[i], err = strconv.ParseFloat(f[i], 64); err != nil {
			return Load{}, fmt.Errorf("/proc/loadavg: %w", err)
		}
	}
	return Load{v[0], v[1], v[2]}, nil
}

// UptimeSeconds reads /proc/uptime.
func (r *Reader) UptimeSeconds() (int64, error) {
	s, err := r.line("proc/uptime")
	if err != nil {
		return 0, err
	}
	f := strings.Fields(s)
	if len(f) == 0 {
		return 0, errors.New("/proc/uptime is empty")
	}
	v, err := strconv.ParseFloat(f[0], 64)
	return int64(v), err
}

// Filesystem types worth reporting. Everything else (proc, tmpfs, overlay,
// squashfs snaps, ...) is not where customer data lives.
var diskTypes = map[string]bool{"ext2": true, "ext3": true, "ext4": true, "xfs": true, "btrfs": true, "zfs": true, "f2fs": true}

// Disk is one real filesystem.
type Disk struct {
	Mount       string `json:"mount"`
	FSType      string `json:"fs_type"`
	TotalBytes  uint64 `json:"total_bytes"`
	AvailBytes  uint64 `json:"available_bytes"`
	UsedBytes   uint64 `json:"used_bytes"`
	TotalInodes uint64 `json:"total_inodes"`
	FreeInodes  uint64 `json:"free_inodes"`
}

// Disks lists real filesystems from /proc/self/mounts, one entry per device:
// a bind mount of a filesystem already listed is folded into the entry with
// the shortest mount point.
func (r *Reader) Disks() ([]Disk, error) {
	data, err := r.read("proc/self/mounts")
	if err != nil {
		return nil, err
	}

	byDevice := map[uint64]Disk{}
	var problems []error
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) < 3 || !diskTypes[f[2]] {
			continue
		}
		mount := unescapeMount(f[1])
		st, err := r.Statfs(filepath.Join(r.Root, filepath.FromSlash(mount)))
		if err != nil {
			problems = append(problems, fmt.Errorf("statfs %s: %w", mount, err))
			continue
		}
		d := Disk{
			Mount: mount, FSType: f[2],
			TotalBytes: st.TotalBytes, AvailBytes: st.AvailBytes, UsedBytes: st.TotalBytes - st.FreeBytes,
			TotalInodes: st.TotalInodes, FreeInodes: st.FreeInodes,
		}
		if prev, ok := byDevice[st.Device]; !ok || len(mount) < len(prev.Mount) {
			byDevice[st.Device] = d
		}
	}

	disks := make([]Disk, 0, len(byDevice))
	for _, d := range byDevice {
		disks = append(disks, d)
	}
	sort.Slice(disks, func(a, b int) bool { return disks[a].Mount < disks[b].Mount })
	if len(disks) == 0 && len(problems) > 0 {
		return nil, errors.Join(problems...)
	}
	return disks, nil
}

// unescapeMount decodes the octal escapes /proc/self/mounts uses for spaces, tabs, newlines and backslashes.
func unescapeMount(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+3 < len(s) {
			if v, err := strconv.ParseUint(s[i+1:i+4], 8, 8); err == nil {
				b.WriteByte(byte(v))
				i += 3
				continue
			}
		}
		b.WriteByte(s[i])
	}
	return b.String()
}
