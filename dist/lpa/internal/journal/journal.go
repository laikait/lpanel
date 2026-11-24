// Package journal makes operations that change the system run exactly once.
//
// Every such request's id gets one file, claimed with O_EXCL before the work
// starts. A retry with the same id gets the stored outcome instead of running
// again; the same id with different work is refused. An entry still marked
// running when the agent starts was cut off by a crash or restart, so it
// becomes "interrupted": the change may or may not have happened, and the
// panel decides what to do.
//
// Read-only operations are not journalled: repeating them is harmless.
package journal

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"time"

	"lpa/internal/fsutil"
)

// States of an entry.
const (
	Running     = "running"
	Done        = "done"
	Failed      = "failed"
	Interrupted = "interrupted"
)

// Entry is one request's record.
type Entry struct {
	ID         string          `json:"id"`
	Op         string          `json:"op"`
	ArgsSHA256 string          `json:"args_sha256"`
	State      string          `json:"state"`
	Started    time.Time       `json:"started"`
	Finished   *time.Time      `json:"finished,omitempty"`
	Result     json.RawMessage `json:"result,omitempty"`
	ErrCode    string          `json:"error_code,omitempty"`
	ErrMessage string          `json:"error_message,omitempty"`
}

// ErrExists means the id already has an entry; Claim returns it alongside.
var ErrExists = errors.New("journal: id already claimed")

// Journal is a directory of entries, one JSON file per id.
type Journal struct {
	Dir string
	Now func() time.Time
}

// Open prepares the directory (0700, root's) and marks entries left running
// by a previous process as interrupted.
func Open(dir string) (*Journal, error) {
	if err := fsutil.EnsureDir(dir, 0o700); err != nil {
		return nil, err
	}
	j := &Journal{Dir: dir, Now: time.Now}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	for _, e := range entries {
		if filepath.Ext(e.Name()) != ".json" {
			continue
		}
		entry, err := j.Get(e.Name()[:len(e.Name())-5])
		if err != nil || entry.State != Running {
			continue
		}
		if err := j.finish(entry, Interrupted, nil, "interrupted", "the agent stopped while this ran"); err != nil {
			return nil, err
		}
	}
	return j, nil
}

func (j *Journal) path(id string) string { return filepath.Join(j.Dir, id+".json") }

// Claim records that work with this id starts now. If the id was claimed
// before, it returns the existing entry and ErrExists.
func (j *Journal) Claim(id, op, argsSHA256 string) (*Entry, error) {
	entry := &Entry{ID: id, Op: op, ArgsSHA256: argsSHA256, State: Running, Started: j.Now().UTC()}
	data, err := json.Marshal(entry)
	if err != nil {
		return nil, err
	}
	// The entry appears whole or not at all: written to a file of its own,
	// then linked to its name, which fails if the name is taken. (Creating
	// the name first and writing after would let a retry of the same id,
	// arriving between the two, read an empty file.)
	tmp, err := os.CreateTemp(j.Dir, "."+id+".claim-*")
	if err != nil {
		return nil, err
	}
	name := tmp.Name()
	defer os.Remove(name)
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return nil, err
	}
	if err := tmp.Chmod(0o600); err != nil && runtime.GOOS != "windows" {
		tmp.Close()
		return nil, err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return nil, err
	}
	if err := tmp.Close(); err != nil {
		return nil, err
	}
	if err := os.Link(name, j.path(id)); err != nil {
		if errors.Is(err, fs.ErrExist) {
			existing, getErr := j.Get(id)
			if getErr != nil {
				return nil, getErr
			}
			return existing, ErrExists
		}
		return nil, err
	}
	return entry, nil
}

// Succeed stores the result of a claimed entry.
func (j *Journal) Succeed(entry *Entry, result any) error {
	raw, err := json.Marshal(result)
	if err != nil {
		return err
	}
	return j.finish(entry, Done, raw, "", "")
}

// Fail stores the error of a claimed entry.
func (j *Journal) Fail(entry *Entry, code, message string) error {
	return j.finish(entry, Failed, nil, code, message)
}

func (j *Journal) finish(entry *Entry, state string, result json.RawMessage, code, message string) error {
	now := j.Now().UTC()
	entry.State, entry.Finished, entry.Result, entry.ErrCode, entry.ErrMessage = state, &now, result, code, message
	data, err := json.Marshal(entry)
	if err != nil {
		return err
	}
	return fsutil.WriteFileAtomic(j.path(entry.ID), data, 0o600)
}

// Get reads an entry; fs.ErrNotExist when there is none.
func (j *Journal) Get(id string) (*Entry, error) {
	data, err := os.ReadFile(j.path(id))
	if err != nil {
		return nil, err
	}
	var entry Entry
	if err := json.Unmarshal(data, &entry); err != nil {
		return nil, fmt.Errorf("journal entry %s: %w", id, err)
	}
	return &entry, nil
}

// Prune removes finished entries older than age. Running ones stay.
func (j *Journal) Prune(age time.Duration) (int, error) {
	entries, err := os.ReadDir(j.Dir)
	if err != nil {
		return 0, err
	}
	cutoff, removed := j.Now().Add(-age), 0
	for _, e := range entries {
		if filepath.Ext(e.Name()) != ".json" {
			continue
		}
		entry, err := j.Get(e.Name()[:len(e.Name())-5])
		if err != nil || entry.State == Running || entry.Finished == nil || entry.Finished.After(cutoff) {
			continue
		}
		if os.Remove(j.path(entry.ID)) == nil {
			removed++
		}
	}
	return removed, nil
}

// Summary is an entry without its arguments and result, which can hold
// secrets: what the Admin panel's task viewer shows.
type Summary struct {
	ID         string     `json:"id"`
	Op         string     `json:"op"`
	State      string     `json:"state"`
	Started    time.Time  `json:"started"`
	Finished   *time.Time `json:"finished,omitempty"`
	ErrCode    string     `json:"error_code,omitempty"`
	ErrMessage string     `json:"error_message,omitempty"`
}

// ScanMax is the most entries List looks at (the newest files first).
const ScanMax = 2000

// List returns the newest entries, at most limit, optionally only those in
// one state ("" is any). Newest first.
func (j *Journal) List(state string, limit int) ([]Summary, error) {
	files, err := os.ReadDir(j.Dir)
	if err != nil {
		return nil, err
	}
	type file struct {
		id  string
		mod time.Time
	}
	var all []file
	for _, f := range files {
		if filepath.Ext(f.Name()) != ".json" {
			continue
		}
		info, err := f.Info()
		if err != nil {
			continue
		}
		all = append(all, file{f.Name()[:len(f.Name())-5], info.ModTime()})
	}
	sort.Slice(all, func(a, b int) bool { return all[a].mod.After(all[b].mod) })
	if len(all) > ScanMax {
		all = all[:ScanMax]
	}
	out := []Summary{}
	for _, f := range all {
		e, err := j.Get(f.id)
		if err != nil || (state != "" && e.State != state) {
			continue
		}
		out = append(out, Summary{ID: e.ID, Op: e.Op, State: e.State, Started: e.Started, Finished: e.Finished, ErrCode: e.ErrCode, ErrMessage: e.ErrMessage})
		if len(out) >= limit {
			break
		}
	}
	return out, nil
}
