// Package authlog reads the server's logs for failed sign-ins, so the panel's
// brute-force blocker can count them by address.
//
// The agent only reads and reports: which addresses failed how many times, on
// which service, since the place the panel says it stopped last time (a
// cursor per source). It blocks nothing and keeps nothing; the panel decides
// and tells the firewall. A cursor is a file, a position and a hash of the
// file's first bytes, so a rotated or replaced log is noticed without
// needing inode numbers.
//
// Sources (a file may be missing: the service is not installed or logs
// elsewhere, and it is reported as missing, not as an error):
//
//	ssh   /var/log/secure       sshd "Failed password/publickey/... for USER from IP"
//	ftp   /var/log/messages     pure-ftpd "(USER@IP) [WARNING] Authentication failed"
//	mail  /var/log/maillog      Dovecot "...-login: ...auth failed, N attempts...rip=IP"
//	smtp  /var/log/exim/mainlog Exim "login authenticator failed for (host) [IP]"
//
// The first scan of a source starts at the end of the file, so old history
// never blocks anyone the moment the feature is turned on.
package authlog

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"net/netip"
	"os"
	"regexp"
	"sort"
	"strconv"
)

// MaxRead is the most of one log read in one scan; more than that waiting is
// skipped (the cursor moves to the end), as a flood is already clear by then.
const MaxRead = 8 << 20

// MaxAddresses caps the addresses reported per scan and source.
const MaxAddresses = 2000

// Source is one log and how to read a failure out of its lines.
type Source struct {
	Name    string
	File    string
	Pattern *regexp.Regexp // the first group is the address; an optional second is a count of attempts
}

// Cursor is where a source was read up to.
type Cursor struct {
	Offset int64  `json:"offset"`
	Head   string `json:"head"` // sha256 (hex) of the file's first bytes: it changes when the log is replaced
}

// Failure is the failed sign-ins of one address on one service.
type Failure struct {
	IP      string `json:"ip"`
	Service string `json:"service"`
	Count   int    `json:"count"`
}

// Args are what authlog.scan is given.
type Args struct {
	// Cursors by source name; a source with none starts at the end of its log.
	Cursors map[string]Cursor `json:"cursors"`
}

// Result is what a scan found.
type Result struct {
	Failures []Failure         `json:"failures"`
	Cursors  map[string]Cursor `json:"cursors"`
	Missing  []string          `json:"missing"`
	Lines    int               `json:"lines"`
}

// Scanner reads the logs.
type Scanner struct {
	Sources []Source
}

// Default is the scanner for the logs AlmaLinux 9 and the installer's setup write.
func Default() *Scanner {
	return &Scanner{Sources: []Source{
		{Name: "ssh", File: "/var/log/secure", Pattern: regexp.MustCompile(`sshd(?:-session)?\[\d+\]: Failed (?:password|publickey|keyboard-interactive/pam|none) for (?:invalid user )?.+? from ([0-9a-fA-F:.]+) port \d+`)},
		{Name: "ftp", File: "/var/log/messages", Pattern: regexp.MustCompile(`pure-ftpd: \([^)@]*@([0-9a-fA-F:.]+)\) \[WARNING\] Authentication failed`)},
		{Name: "mail", File: "/var/log/maillog", Pattern: regexp.MustCompile(`(?:imap|pop3|submission|managesieve)-login: .*auth failed(?:, (\d+) attempts?)?.*?\brip=([0-9a-fA-F:.]+)`)},
		{Name: "smtp", File: "/var/log/exim/mainlog", Pattern: regexp.MustCompile(`login authenticator failed for .*?\[([0-9a-fA-F:.]+)\]`)},
	}}
}

// Scan reads every source from its cursor.
func (s *Scanner) Scan(ctx context.Context, a Args) (Result, error) {
	out := Result{Failures: []Failure{}, Cursors: map[string]Cursor{}, Missing: []string{}}

	for _, src := range s.Sources {
		if err := ctx.Err(); err != nil {
			return out, err
		}

		cur, ok := a.Cursors[src.Name]
		next, found, lines, err := scanOne(src, cur, ok)

		if errors.Is(err, os.ErrNotExist) {
			out.Missing = append(out.Missing, src.Name)
			continue
		}

		if err != nil {
			return out, err
		}

		out.Cursors[src.Name] = next.cursor
		out.Lines += lines

		for _, f := range next.sorted(src.Name, found) {
			out.Failures = append(out.Failures, f)
		}
	}

	return out, nil
}

type scanned struct{ cursor Cursor }

func (s scanned) sorted(service string, found map[string]int) []Failure {
	ips := make([]string, 0, len(found))
	for ip := range found {
		ips = append(ips, ip)
	}
	sort.Slice(ips, func(i, j int) bool {
		if found[ips[i]] != found[ips[j]] {
			return found[ips[i]] > found[ips[j]]
		}
		return ips[i] < ips[j]
	})
	if len(ips) > MaxAddresses {
		ips = ips[:MaxAddresses]
	}
	out := make([]Failure, 0, len(ips))
	for _, ip := range ips {
		out = append(out, Failure{IP: ip, Service: service, Count: found[ip]})
	}
	return out
}

func scanOne(src Source, cur Cursor, had bool) (scanned, map[string]int, int, error) {
	f, err := os.Open(src.File)
	if err != nil {
		return scanned{}, nil, 0, err
	}
	defer f.Close()

	st, err := f.Stat()
	if err != nil {
		return scanned{}, nil, 0, err
	}

	head, err := headOf(f)
	if err != nil {
		return scanned{}, nil, 0, err
	}

	size := st.Size()
	end := scanned{cursor: Cursor{Offset: size, Head: head}}

	// Never read before: start at the end. Replaced or cut: start at the top.
	if !had {
		return end, map[string]int{}, 0, nil
	}

	start := cur.Offset
	if cur.Head != head || start > size || start < 0 {
		start = 0
	}

	if size-start > MaxRead {
		start = size - MaxRead
	}

	if start == size {
		return end, map[string]int{}, 0, nil
	}

	buf := make([]byte, size-start)
	n, err := f.ReadAt(buf, start)
	if err != nil && !errors.Is(err, io.EOF) {
		return scanned{}, nil, 0, err
	}
	buf = buf[:n]

	// Stop at the last whole line, so a line still being written is read next time.
	last := lastNewline(buf)
	if last < 0 {
		return scanned{cursor: Cursor{Offset: start, Head: head}}, map[string]int{}, 0, nil
	}
	buf = buf[:last+1]
	end.cursor.Offset = start + int64(len(buf))

	found := map[string]int{}
	lines := 0

	for len(buf) > 0 {
		i := indexNewline(buf)
		line := buf[:i]
		buf = buf[i+1:]
		lines++

		m := src.Pattern.FindSubmatch(line)
		if m == nil {
			continue
		}

		count := 1
		ip := string(m[1])

		// A pattern with two groups gives an optional count first, then the address.
		if len(m) == 3 {
			ip = string(m[2])
			if len(m[1]) > 0 {
				if c, err := strconv.Atoi(string(m[1])); err == nil && c > 0 && c <= 100 {
					count = c
				}
			}
		}

		addr, err := netip.ParseAddr(ip)
		if err != nil || addr.IsLoopback() || addr.IsUnspecified() || addr.IsMulticast() {
			continue
		}
		found[addr.Unmap().String()] += count
	}

	return end, found, lines, nil
}

func headOf(f *os.File) (string, error) {
	b := make([]byte, 256)
	n, err := f.ReadAt(b, 0)
	if err != nil && !errors.Is(err, io.EOF) {
		return "", err
	}
	b = b[:n]
	// The first line only: it never changes once written, however the file grows.
	if i := bytes.IndexByte(b, 10); i >= 0 {
		b = b[:i]
	}
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:8]), nil
}

func lastNewline(b []byte) int {
	for i := len(b) - 1; i >= 0; i-- {
		if b[i] == '\n' {
			return i
		}
	}
	return -1
}

func indexNewline(b []byte) int {
	for i := range b {
		if b[i] == '\n' {
			return i
		}
	}
	return len(b)
}
