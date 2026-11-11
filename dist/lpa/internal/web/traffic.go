package web

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// Bandwidth: web.traffic reads each account's access log (written in the
// "lpanel" format, which ends with %O and %I, bytes sent and received) and
// counts the bytes per account and day.
//
// The agent remembers how far it has read each log file, by the file's
// identity (device and inode), not its name. logrotate renames
// user.access.log to user.access.log.1 and leaves it uncompressed until the
// next day (delaycompress), so the rest of a rotated file is still read, and
// nothing is counted twice.
//
// The answer is the running total of every day still kept, not what is new
// since the last call. The panel stores it as it comes, so asking twice, or
// losing an answer, changes nothing.

// trafficDays is how many days of totals the agent keeps.
const trafficDays = 62

// trafficChunk caps how much of the logs one call reads, so a call always
// finishes in time; the rest is read by the next.
const trafficChunk = 512 << 20

// Traffic is web.traffic's answer: bytes per account per day ("2026-11-11").
type Traffic struct {
	Accounts map[string]map[string]int64 `json:"accounts"`
	// More is true when there was more to read than one call reads.
	More bool `json:"more"`
}

type trafficState struct {
	// Files are how far each log has been read, by fileKey.
	Files map[string]int64 `json:"files"`
	// Days are the totals: account → day → bytes.
	Days map[string]map[string]int64 `json:"days"`
}

// Traffic reads what the access logs gained since the last call.
func (m *Manager) Traffic(ctx context.Context) (Traffic, error) {
	if err := m.on(); err != nil {
		return Traffic{}, err
	}
	m.trafficMu.Lock()
	defer m.trafficMu.Unlock()

	st, err := m.loadTraffic()
	if err != nil {
		return Traffic{}, err
	}
	seen := map[string]int64{}
	budget := int64(trafficChunk)
	more := false

	logs, _ := filepath.Glob(filepath.Join(m.LogDir, "*.access.log.1"))
	current, _ := filepath.Glob(filepath.Join(m.LogDir, "*.access.log"))
	// The rotated file first: it holds the older lines.
	for _, path := range append(logs, current...) {
		if ctx.Err() != nil {
			return Traffic{}, ctx.Err()
		}
		user := strings.TrimSuffix(strings.TrimSuffix(filepath.Base(path), ".1"), ".access.log")
		if !userRule.MatchString(user) {
			continue
		}
		fi, err := os.Stat(path)
		if err != nil {
			continue
		}
		key := fileKey(path, fi)
		offset := st.Files[key]
		if fi.Size() < offset {
			offset = 0 // truncated in place: start again
		}
		read, err := countLog(path, offset, budget, st.add(user))
		if err != nil {
			return Traffic{}, err
		}
		budget -= read
		if offset+read < fi.Size() && budget <= 0 {
			more = true
		}
		seen[key] = offset + read
	}
	// Files no longer there are forgotten; so are days too old to matter.
	st.Files = seen
	cutoff := time.Now().AddDate(0, 0, -trafficDays).Format("2006-01-02")
	for user, days := range st.Days {
		for day := range days {
			if day < cutoff {
				delete(days, day)
			}
		}
		if len(days) == 0 {
			delete(st.Days, user)
		}
	}
	if err := m.saveTraffic(st); err != nil {
		return Traffic{}, err
	}
	return Traffic{Accounts: st.Days, More: more}, nil
}

// forgetTraffic drops an account's totals and its logs: a new account with
// the same name starts from nothing.
func (m *Manager) forgetTraffic(username string) error {
	m.trafficMu.Lock()
	defer m.trafficMu.Unlock()
	for _, suffix := range []string{".access.log", ".error.log"} {
		matches, _ := filepath.Glob(filepath.Join(m.LogDir, username+suffix+"*"))
		for _, p := range matches {
			if err := os.Remove(p); err != nil && !errors.Is(err, os.ErrNotExist) {
				return err
			}
		}
	}
	st, err := m.loadTraffic()
	if err != nil {
		return err
	}
	if _, ok := st.Days[username]; !ok {
		return nil
	}
	delete(st.Days, username)
	return m.saveTraffic(st)
}

func (st *trafficState) add(user string) func(day string, n int64) {
	return func(day string, n int64) {
		if st.Days[user] == nil {
			st.Days[user] = map[string]int64{}
		}
		st.Days[user][day] += n
	}
}

func (m *Manager) loadTraffic() (*trafficState, error) {
	st := &trafficState{Files: map[string]int64{}, Days: map[string]map[string]int64{}}
	data, err := os.ReadFile(m.TrafficState)
	if errors.Is(err, os.ErrNotExist) {
		return st, nil
	}
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(data, st); err != nil {
		// A damaged record would stop metering for good; start again.
		m.Log.Warn("the traffic record could not be read; counting starts again", "err", err)
		return &trafficState{Files: map[string]int64{}, Days: map[string]map[string]int64{}}, nil
	}
	if st.Files == nil {
		st.Files = map[string]int64{}
	}
	if st.Days == nil {
		st.Days = map[string]map[string]int64{}
	}
	return st, nil
}

func (m *Manager) saveTraffic(st *trafficState) error {
	data, err := json.Marshal(st)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(m.TrafficState), 0o700); err != nil {
		return err
	}
	return writeAtomic(m.TrafficState, data, 0o600)
}

// countLog reads whole lines of path from offset, at most limit bytes, and
// returns how many bytes it consumed. A last line without its newline is
// being written: it is left for the next call.
func countLog(path string, offset, limit int64, add func(day string, n int64)) (int64, error) {
	f, err := os.Open(path)
	if errors.Is(err, os.ErrNotExist) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	defer f.Close()
	if _, err := f.Seek(offset, io.SeekStart); err != nil {
		return 0, err
	}
	r := bufio.NewReaderSize(f, 64<<10)
	var read int64
	for read < limit {
		line, err := r.ReadString('\n')
		if err != nil {
			if errors.Is(err, io.EOF) {
				break // a partial line, or nothing: not consumed
			}
			return read, err
		}
		read += int64(len(line))
		if day, n, ok := parseLogLine(line); ok {
			add(day, n)
		}
	}
	return read, nil
}

var months = map[string]string{
	"Jan": "01", "Feb": "02", "Mar": "03", "Apr": "04", "May": "05", "Jun": "06",
	"Jul": "07", "Aug": "08", "Sep": "09", "Oct": "10", "Nov": "11", "Dec": "12",
}

// parseLogLine reads the day and the bytes (sent plus received) from a line
// of the "lpanel" format:
//
//	%v %h %l %u [%t] "%r" %>s %O %I "%{Referer}i" "%{User-Agent}i"
//
// The request is quoted by Apache with any quote inside escaped (\"), so it
// is skipped by looking for the first unescaped quote.
func parseLogLine(line string) (string, int64, bool) {
	open := strings.IndexByte(line, '[')
	if open < 0 || len(line) < open+12 {
		return "", 0, false
	}
	// [11/Nov/2026:11:58:05 +0000]
	t := line[open+1:]
	mon, ok := months[t[3:min(6, len(t))]]
	if !ok || t[2] != '/' || len(t) < 11 || t[6] != '/' {
		return "", 0, false
	}
	day := t[7:11] + "-" + mon + "-" + t[0:2]
	rest := t[strings.IndexByte(t, ']')+1:]
	q := strings.IndexByte(rest, '"')
	if q < 0 {
		return "", 0, false
	}
	i := q + 1
	for ; i < len(rest); i++ {
		if rest[i] == '\\' {
			i++
			continue
		}
		if rest[i] == '"' {
			break
		}
	}
	if i >= len(rest) {
		return "", 0, false
	}
	fields := strings.Fields(rest[i+1:])
	if len(fields) < 3 {
		return "", 0, false
	}
	var total int64
	for _, f := range fields[1:3] {
		if f == "-" {
			continue
		}
		n, err := strconv.ParseInt(f, 10, 64)
		if err != nil || n < 0 {
			return "", 0, false
		}
		total += n
	}
	return day, total, true
}
