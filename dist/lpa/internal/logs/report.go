package logs

import (
	"bufio"
	"compress/gzip"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"lpa/internal/protocol"
)

// CodeGoAccess is the error when the statistics cannot be made.
const CodeGoAccess = "logs_goaccess"

const (
	// maxInput caps the log text handed to GoAccess, newest kept.
	maxInput = 256 << 20
	// topItems is how many entries each list keeps.
	topItems = 10
	// cacheAge is how long a report is reused when not asked to be made again.
	cacheAge = 30 * time.Minute
	// maxLineScan is the longest log line looked at.
	maxLineScan = 1 << 20
)

// goaccessFormat reads the access log the web servers write (the "lpanel"
// format: vhost, address, user, [time], "request", status, bytes sent, bytes
// received, "referrer", "agent").
var goaccessFormat = []string{
	`--log-format=%v %h %^ %e [%d:%t %^] "%r" %s %b %^ "%R" "%u"`,
	"--date-format=%d/%b/%Y",
	"--time-format=%T",
}

var accessName = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}\.access\.log(\.[0-9]+)?(\.gz)?$`)

// ReportArgs asks for the statistics of an account's sites, or of all of them.
type ReportArgs struct {
	// User is an account; empty is every account (Admin).
	User string `json:"user,omitempty"`
	// Days is how far back (1 to 90, default 30).
	Days int `json:"days,omitempty"`
	// Refresh makes a new report even if a recent one is kept.
	Refresh bool `json:"refresh,omitempty"`
}

// Item is one line of a top list.
type Item struct {
	Name     string `json:"name"`
	Hits     int64  `json:"hits"`
	Visitors int64  `json:"visitors"`
	Bytes    int64  `json:"bytes"`
}

// Day is a day of visits.
type Day struct {
	Date     string `json:"date"`
	Hits     int64  `json:"hits"`
	Visitors int64  `json:"visitors"`
	Bytes    int64  `json:"bytes"`
}

// Report is the statistics: GoAccess's counts, trimmed to what the panel shows.
type Report struct {
	Scope string `json:"scope"`
	Days  int    `json:"days"`
	Made  string `json:"made"`
	// Truncated is true when there was more log than is looked at (the oldest was left out).
	Truncated bool   `json:"truncated"`
	Requests  int64  `json:"requests"`
	Valid     int64  `json:"valid"`
	Failed    int64  `json:"failed"`
	Visitors  int64  `json:"visitors"`
	Bandwidth int64  `json:"bandwidth"`
	Daily     []Day  `json:"daily"`
	Pages     []Item `json:"pages"`
	NotFound  []Item `json:"not_found"`
	Referrers []Item `json:"referrers"`
	Hosts     []Item `json:"hosts"`
	Browsers  []Item `json:"browsers"`
	Systems   []Item `json:"systems"`
	Status    []Item `json:"status"`
	Sites     []Item `json:"sites"`
}

type cached struct {
	Report Report `json:"report"`
}

// Report makes the statistics with GoAccess from the access logs: the lines of
// the last Days days of one account's logs (or all), in a file only the agent
// can read, for as long as GoAccess takes.
func (m *Manager) Report(ctx context.Context, a ReportArgs) (Report, error) {
	if a.Days == 0 {
		a.Days = 30
	}

	if a.Days < 1 || a.Days > 90 {
		return Report{}, protocol.Fail(protocol.CodeBadArgs, "days is 1 to 90")
	}

	scope := "all"
	if a.User != "" {
		if !ValidUser(a.User) {
			return Report{}, protocol.Fail(protocol.CodeBadArgs, "that is not an account name")
		}

		scope = a.User
	}

	if m.AccessDir == nil {
		return Report{}, protocol.Fail(CodeGoAccess, "the web server's logs are not set up")
	}

	cache := filepath.Join(m.State, fmt.Sprintf("report-%s-%d.json", scope, a.Days))
	if !a.Refresh {
		if data, err := os.ReadFile(cache); err == nil {
			var c cached
			if json.Unmarshal(data, &c) == nil && c.Report.Scope == scope && c.Report.Days == a.Days {
				if made, err := time.Parse(time.RFC3339, c.Report.Made); err == nil && m.Now().Sub(made) >= 0 && m.Now().Sub(made) < cacheAge {
					return c.Report, nil
				}
			}
		}
	}

	if err := os.MkdirAll(m.State, 0o700); err != nil {
		return Report{}, protocol.Fail(CodeGoAccess, "the statistics could not be made")
	}

	input, err := os.CreateTemp(m.State, "access-*.log")
	if err != nil {
		return Report{}, protocol.Fail(CodeGoAccess, "the statistics could not be made")
	}

	defer os.Remove(input.Name())

	out := input.Name() + ".json"

	defer os.Remove(out)

	lines, truncated, err := m.collect(input, scope, a.Days)
	if cerr := input.Close(); err == nil {
		err = cerr
	}

	if err != nil {
		return Report{}, protocol.Fail(CodeGoAccess, "the logs could not be read")
	}

	rep := Report{Scope: scope, Days: a.Days, Made: m.Now().UTC().Format(time.RFC3339), Truncated: truncated}

	if lines > 0 {
		args := append([]string{"-f", input.Name()}, goaccessFormat...)
		args = append(args, "--no-global-config", "-o", out)

		code, output, rerr := m.Run.Run(ctx, "goaccess", args...)
		if rerr != nil {
			return Report{}, protocol.Fail(CodeGoAccess, "GoAccess is not installed or could not run")
		}

		if code != 0 {
			return Report{}, protocol.Fail(CodeGoAccess, "GoAccess failed: "+trimMessage(output))
		}

		data, rerr := os.ReadFile(out)
		if rerr != nil {
			return Report{}, protocol.Fail(CodeGoAccess, "GoAccess gave no report")
		}

		if err := parseReport(data, &rep); err != nil {
			return Report{}, protocol.Fail(CodeGoAccess, "GoAccess's report could not be read: "+err.Error())
		}
	}

	if data, err := json.Marshal(cached{Report: rep}); err == nil {
		_ = os.WriteFile(cache, data, 0o600)
	}

	return rep, nil
}

// collect copies the lines of the last days days of the access logs to w, the
// oldest first, and says how many. When there is more than maxInput the oldest
// are left out.
func (m *Manager) collect(w io.Writer, scope string, days int) (int, bool, error) {
	pattern := "*.access.log*"
	if scope != "all" {
		pattern = scope + ".access.log*"
	}

	paths, _ := filepath.Glob(filepath.Join(m.AccessDir(), pattern))

	type f struct {
		path string
		mod  time.Time
	}

	var files []f

	for _, p := range paths {
		st, err := os.Stat(p)
		if err != nil || !st.Mode().IsRegular() || !accessName.MatchString(filepath.Base(p)) {
			continue
		}

		files = append(files, f{p, st.ModTime()})
	}

	// Oldest first, so the lines come in time order; a cap drops from the front.
	sort.Slice(files, func(i, j int) bool { return files[i].mod.Before(files[j].mod) })

	today := m.Now().UTC()
	cutoff := time.Date(today.Year(), today.Month(), today.Day(), 0, 0, 0, 0, time.UTC).AddDate(0, 0, -(days - 1))

	// Newest files first for the cap: take what fits, then write in order.
	var kept [][]byte

	var size int64

	truncated := false

	for i := len(files) - 1; i >= 0; i-- {
		data, err := readLines(files[i].path, cutoff)
		if err != nil {
			continue
		}

		if size+int64(len(data)) > maxInput {
			truncated = true

			break
		}

		size += int64(len(data))
		kept = append(kept, data)
	}

	count := 0

	for i := len(kept) - 1; i >= 0; i-- {
		if _, err := w.Write(kept[i]); err != nil {
			return 0, false, err
		}

		count += strings.Count(string(kept[i]), "\n")
	}

	return count, truncated, nil
}

// readLines is the lines of a log (plain or gzipped) that are of cutoff's day or later.
func readLines(path string, cutoff time.Time) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	var r io.Reader = f

	if strings.HasSuffix(path, ".gz") {
		z, err := gzip.NewReader(f)
		if err != nil {
			return nil, err
		}
		defer z.Close()

		r = z
	}

	var out []byte

	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 64<<10), maxLineScan)

	for sc.Scan() {
		line := sc.Bytes()
		if lineDay(line).Before(cutoff) {
			continue
		}

		out = append(out, line...)
		out = append(out, '\n')
	}

	if err := sc.Err(); err != nil {
		return out, nil // a line too long ends the file: what was read counts
	}

	return out, nil
}

// lineDay is the day in a line's [02/Jan/2006:15:04:05 +0000]; a line without
// one is of no day (the zero time), and left out.
func lineDay(line []byte) time.Time {
	i := strings.Index(string(line[:min(len(line), 400)]), " [")
	if i < 0 || i+13 > len(line) {
		return time.Time{}
	}

	t, err := time.Parse("02/Jan/2006", string(line[i+2:i+13]))
	if err != nil {
		return time.Time{}
	}

	return t
}

func trimMessage(s string) string {
	s = strings.Join(strings.Fields(s), " ")
	if len(s) > 200 {
		s = s[:200]
	}

	return s
}

// ---- GoAccess's JSON --------------------------------------------------------

func parseReport(data []byte, r *Report) error {
	var doc map[string]json.RawMessage
	if err := json.Unmarshal(data, &doc); err != nil {
		return err
	}

	var general map[string]any
	if raw, ok := doc["general"]; ok {
		_ = json.Unmarshal(raw, &general)
	}

	r.Requests = num(general["total_requests"])
	r.Valid = num(general["valid_requests"])
	r.Failed = num(general["failed_requests"])
	r.Visitors = num(general["unique_visitors"])
	r.Bandwidth = num(general["bandwidth"])

	for _, e := range entries(doc["visitors"]) {
		r.Daily = append(r.Daily, Day{Date: day(e.Name), Hits: e.Hits, Visitors: e.Visitors, Bytes: e.Bytes})
	}

	sort.Slice(r.Daily, func(i, j int) bool { return r.Daily[i].Date < r.Daily[j].Date })

	r.Pages = top(entries(doc["requests"]))
	r.NotFound = top(entries(doc["not_found"]))
	r.Referrers = top(entries(doc["referring_sites"]))
	r.Hosts = top(entries(doc["hosts"]))
	r.Browsers = top(entries(doc["browsers"]))
	r.Systems = top(entries(doc["os"]))
	r.Status = top(entries(doc["status_codes"]))
	r.Sites = top(entries(doc["vhosts"]))

	return nil
}

// entries reads one panel: {"data": [{"data": name, "hits": {"count": n}, ...}]}.
func entries(raw json.RawMessage) []Item {
	if raw == nil {
		return nil
	}

	var p struct {
		Data []map[string]any `json:"data"`
	}

	if json.Unmarshal(raw, &p) != nil {
		return nil
	}

	out := make([]Item, 0, len(p.Data))

	for _, e := range p.Data {
		name, _ := e["data"].(string)
		if name == "" {
			continue
		}

		out = append(out, Item{Name: clean(name), Hits: num(e["hits"]), Visitors: num(e["visitors"]), Bytes: num(e["bytes"])})
	}

	return out
}

// top keeps the busiest few.
func top(items []Item) []Item {
	sort.SliceStable(items, func(i, j int) bool { return items[i].Hits > items[j].Hits })

	if len(items) > topItems {
		items = items[:topItems]
	}

	return items
}

// num reads a number that GoAccess gives either plainly or as {"count": n}.
func num(v any) int64 {
	switch t := v.(type) {
	case float64:
		return int64(t)
	case map[string]any:
		return num(t["count"])
	}

	return 0
}

// day turns GoAccess's 20261124 into 2026-11-24.
func day(s string) string {
	if len(s) == 8 {
		return s[:4] + "-" + s[4:6] + "-" + s[6:]
	}

	return s
}
