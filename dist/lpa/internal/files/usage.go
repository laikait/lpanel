package files

import (
	"sort"
	"time"
)

// MaxUsageEntries bounds how many files and folders one usage walk counts.
const MaxUsageEntries = 3_000_000

// UsageEntry is one file or folder in a folder, with the space it takes and,
// for a folder, how many files and folders are in it.
type UsageEntry struct {
	Name  string `json:"name"`
	Type  string `json:"type"`
	Bytes int64  `json:"bytes"`
	Items int64  `json:"items"`
}

// Usage is files.usage's answer: the disk space a folder takes, what in it
// takes it (largest first), and whether the walk stopped early.
type Usage struct {
	Path      string       `json:"path"`
	Bytes     int64        `json:"bytes"`
	Items     int64        `json:"items"`
	Entries   []UsageEntry `json:"entries"`
	Truncated bool         `json:"truncated"`
}

type walk struct {
	w     *worker
	items int64
	stop  bool
}

func (w *worker) usage(a PathArgs) (any, error) {
	if err := a.check(); err != nil {
		return nil, err
	}
	if err := w.isDir(a.Path); err != nil {
		return nil, err
	}
	dir, err := w.root.Open(a.Path)
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	des, err := dir.ReadDir(-1)
	dir.Close()
	if err != nil {
		return nil, mapErr(err, a.Path)
	}
	k := &walk{w: w}
	out := Usage{Path: a.Path, Entries: []UsageEntry{}}
	for _, de := range des {
		info, err := de.Info()
		if err != nil {
			continue
		}
		e := UsageEntry{Name: de.Name(), Type: typeOf(info.Mode()), Bytes: allocated(info)}
		if info.IsDir() && !k.stop {
			b, n := k.size(join(a.Path, de.Name()), 0)
			e.Bytes += b
			e.Items = n
		}
		k.items++
		out.Bytes += e.Bytes
		out.Items += 1 + e.Items
		out.Entries = append(out.Entries, e)
	}
	sort.SliceStable(out.Entries, func(i, j int) bool { return out.Entries[i].Bytes > out.Entries[j].Bytes })
	if len(out.Entries) > MaxList {
		out.Entries = out.Entries[:MaxList]
	}
	out.Truncated = k.stop
	return out, nil
}

// size adds up what is inside folder p, without following links. Folders it
// cannot read count as empty; the walk stops at the time budget or after
// MaxUsageEntries.
func (k *walk) size(p string, depth int) (bytes, items int64) {
	if depth > 512 || k.stop {
		return 0, 0
	}
	dir, err := k.w.root.Open(p)
	if err != nil {
		return 0, 0
	}
	defer dir.Close()
	for !k.stop {
		des, err := dir.ReadDir(1024)
		for _, de := range des {
			k.items++
			if k.items > MaxUsageEntries || (!k.w.deadline.IsZero() && k.items%1024 == 0 && time.Now().After(k.w.deadline)) {
				k.stop = true
				break
			}
			info, ierr := de.Info()
			if ierr != nil {
				continue
			}
			items++
			bytes += allocated(info)
			if info.IsDir() {
				b, n := k.size(join(p, de.Name()), depth+1)
				bytes += b
				items += n
			}
		}
		if err != nil {
			break // the end, or a folder that cannot be read further: it counts as what was read
		}
	}
	return bytes, items
}
