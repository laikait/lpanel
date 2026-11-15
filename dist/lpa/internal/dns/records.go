package dns

import (
	"fmt"
	"net"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"lpa/internal/protocol"
)

// Types are the record types a zone may hold through the agent. SOA is made
// by the agent itself.
var Types = []string{"A", "AAAA", "CNAME", "MX", "TXT", "SRV", "CAA", "NS", "PTR"}

// Limits on what a zone may hold.
const (
	MinTTL     = 60
	MaxTTL     = 604800
	MaxRecords = 5000
	MaxTXT     = 4096
)

var (
	hostLabel = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	// Record names may also carry _ (_dmarc, _sip._tcp).
	nameLabel = regexp.MustCompile(`^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$`)
	caaTag    = regexp.MustCompile(`^[a-z0-9]{1,15}$`)
)

// Record is one record as the panel sends it: names without the final dot,
// TXT as plain text, priorities at the start of the content ("10 mail.example.com").
type Record struct {
	Name    string `json:"name"`
	Type    string `json:"type"`
	TTL     int    `json:"ttl"`
	Content string `json:"content"`
}

// rrset is every record of one name and type, as PowerDNS keeps them.
type rrset struct {
	Name     string // with the final dot
	Type     string
	TTL      int
	Contents []string // PowerDNS's form, sorted
}

func bad(format string, args ...any) error {
	return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf(format, args...))
}

// CheckZone says whether name can be a zone: a host name of two or more
// labels, lowercase, without the final dot.
func CheckZone(name string) error {
	if len(name) > 253 || !strings.Contains(name, ".") {
		return bad("%q is not a zone name", name)
	}
	for _, l := range strings.Split(name, ".") {
		if !hostLabel.MatchString(l) {
			return bad("%q is not a zone name", name)
		}
	}
	return nil
}

// checkHost checks a host name a record points at, and returns it with the
// final dot.
func checkHost(host string) (string, bool) {
	host = strings.TrimSuffix(host, ".")
	if host == "" || len(host) > 253 {
		return "", false
	}
	for _, l := range strings.Split(host, ".") {
		if !nameLabel.MatchString(l) {
			return "", false
		}
	}
	return host + ".", true
}

// checkName checks a record's name: the zone itself or a name under it; *
// only as the first label.
func checkName(name, zone string) (string, error) {
	if name != zone && !strings.HasSuffix(name, "."+zone) {
		return "", bad("%q is not in the zone %s", name, zone)
	}
	if len(name) > 253 {
		return "", bad("the name %q is too long", name)
	}
	if name != zone {
		labels := strings.Split(strings.TrimSuffix(name, "."+zone), ".")
		for i, l := range labels {
			if l == "*" && i == 0 {
				continue
			}
			if !nameLabel.MatchString(l) {
				return "", bad("%q is not a record name", name)
			}
		}
	}
	return name + ".", nil
}

func number(s string, max int) (int, bool) {
	if s == "" || len(s) > 5 || strings.Trim(s, "0123456789") != "" {
		return 0, false
	}
	n, _ := strconv.Atoi(s)
	return n, n <= max
}

// canonical checks a record's content and returns it the way PowerDNS
// stores it.
func canonical(typ, content string) (string, error) {
	content = strings.TrimSpace(content)
	fields := strings.Fields(content)
	switch typ {
	case "A":
		ip := net.ParseIP(content)
		if ip == nil || ip.To4() == nil || strings.Contains(content, ":") {
			return "", bad("%q is not an IPv4 address", content)
		}
		return ip.To4().String(), nil
	case "AAAA":
		ip := net.ParseIP(content)
		if ip == nil || !strings.Contains(content, ":") {
			return "", bad("%q is not an IPv6 address", content)
		}
		return ip.String(), nil
	case "CNAME", "NS", "PTR":
		host, ok := checkHost(content)
		if !ok || len(fields) != 1 {
			return "", bad("%q is not a host name", content)
		}
		return host, nil
	case "MX":
		if len(fields) != 2 {
			return "", bad("an MX record is a priority and a host name, not %q", content)
		}
		prio, ok := number(fields[0], 65535)
		host, ok2 := checkHost(fields[1])
		if fields[1] == "." {
			host, ok2 = ".", true // "no mail here" (RFC 7505)
		}
		if !ok || !ok2 {
			return "", bad("an MX record is a priority and a host name, not %q", content)
		}
		return fmt.Sprintf("%d %s", prio, host), nil
	case "SRV":
		if len(fields) != 4 {
			return "", bad("an SRV record is priority, weight, port and target, not %q", content)
		}
		var n [3]int
		for i := 0; i < 3; i++ {
			v, ok := number(fields[i], 65535)
			if !ok {
				return "", bad("an SRV record is priority, weight, port and target, not %q", content)
			}
			n[i] = v
		}
		host, ok := checkHost(fields[3])
		if fields[3] == "." {
			host, ok = ".", true
		}
		if !ok {
			return "", bad("%q is not a host name", fields[3])
		}
		return fmt.Sprintf("%d %d %d %s", n[0], n[1], n[2], host), nil
	case "CAA":
		parts := strings.SplitN(content, " ", 3)
		if len(parts) != 3 {
			return "", bad("a CAA record is flags, a tag and a value, not %q", content)
		}
		flags, ok := number(parts[0], 255)
		value := strings.TrimSpace(parts[2])
		if len(value) >= 2 && strings.HasPrefix(value, `"`) && strings.HasSuffix(value, `"`) {
			value = value[1 : len(value)-1]
		}
		if !ok || !caaTag.MatchString(parts[1]) || value == "" || len(value) > 255 || !plain(value) || strings.ContainsAny(value, `"\`) {
			return "", bad("a CAA record is flags, a tag and a value, not %q", content)
		}
		return fmt.Sprintf(`%d %s "%s"`, flags, parts[1], value), nil
	case "TXT":
		if content == "" || len(content) > MaxTXT {
			return "", bad("a TXT record holds 1 to %d characters", MaxTXT)
		}
		return quoteTXT(content), nil
	}
	return "", bad("%q is not a record type a zone may hold", typ)
}

// plain: printable ASCII only.
func plain(s string) bool {
	for i := 0; i < len(s); i++ {
		if s[i] < 0x20 || s[i] > 0x7e {
			return false
		}
	}
	return true
}

// quoteTXT is text as a TXT record's content: quoted strings of at most 255
// bytes, with " and \ escaped and every other byte outside printable ASCII
// as \DDD. Nothing of the text can end a string or start another.
func quoteTXT(text string) string {
	var out []string
	for len(text) > 0 {
		n := len(text)
		if n > 255 {
			n = 255
		}
		var b strings.Builder
		b.WriteByte('"')
		for i := 0; i < n; i++ {
			c := text[i]
			switch {
			case c == '"' || c == '\\':
				b.WriteByte('\\')
				b.WriteByte(c)
			case c < 0x20 || c > 0x7e:
				fmt.Fprintf(&b, `\%03d`, c)
			default:
				b.WriteByte(c)
			}
		}
		b.WriteByte('"')
		out = append(out, b.String())
		text = text[n:]
	}
	return strings.Join(out, " ")
}

// build checks a zone's records and groups them the way PowerDNS keeps
// them. The zone needs a nameserver, and a name with a CNAME holds nothing
// else.
func build(zone string, records []Record) (map[string]rrset, error) {
	if len(records) > MaxRecords {
		return nil, bad("a zone holds at most %d records", MaxRecords)
	}
	known := map[string]bool{}
	for _, t := range Types {
		known[t] = true
	}
	sets := map[string]rrset{}
	types := map[string]map[string]bool{} // name → types there
	for _, r := range records {
		if !known[r.Type] {
			return nil, bad("%q is not a record type a zone may hold", r.Type)
		}
		name, err := checkName(r.Name, zone)
		if err != nil {
			return nil, err
		}
		if r.TTL < MinTTL || r.TTL > MaxTTL {
			return nil, bad("%s %s: a TTL is %d to %d seconds", r.Name, r.Type, MinTTL, MaxTTL)
		}
		content, err := canonical(r.Type, r.Content)
		if err != nil {
			return nil, err
		}
		if r.Type == "CNAME" && r.Name == zone {
			return nil, bad("the zone's own name cannot be a CNAME")
		}
		if r.Type == "NS" && strings.HasPrefix(r.Name, "*.") {
			return nil, bad("a wildcard cannot hold NS records")
		}
		key := name + "/" + r.Type
		set, ok := sets[key]
		if !ok {
			set = rrset{Name: name, Type: r.Type, TTL: r.TTL}
		}
		// One TTL for every record of a name and type: the lowest given.
		if r.TTL < set.TTL {
			set.TTL = r.TTL
		}
		dup := false
		for _, c := range set.Contents {
			dup = dup || c == content
		}
		if !dup {
			set.Contents = append(set.Contents, content)
		}
		sets[key] = set
		if types[name] == nil {
			types[name] = map[string]bool{}
		}
		types[name][r.Type] = true
	}
	for name, t := range types {
		if t["CNAME"] && len(t) > 1 {
			return nil, bad("%s is a CNAME, so it cannot hold other records", strings.TrimSuffix(name, "."))
		}
	}
	for key, set := range sets {
		if set.Type == "CNAME" && len(set.Contents) > 1 {
			return nil, bad("%s can be a CNAME for one name only", strings.TrimSuffix(set.Name, "."))
		}
		sort.Strings(set.Contents)
		sets[key] = set
	}
	if _, ok := sets[zone+"./NS"]; !ok {
		return nil, bad("the zone %s needs at least one NS record of its own", zone)
	}
	return sets, nil
}
