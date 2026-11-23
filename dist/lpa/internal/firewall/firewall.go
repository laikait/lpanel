// Package firewall manages the server's firewall through firewalld.
//
// What the panel manages:
//
//   - ports: opened to everyone, or only to some addresses (a rich rule per
//     address), closed again; the ports the panel and SSH are reached on
//     (Keep) can be limited but never closed;
//   - an allow list and a deny list of addresses and networks;
//   - blocked countries, from the country address lists ipdeny.com
//     publishes, fetched here and cached.
//
// The lists are firewalld IP sets the agent writes (one file per set and
// family in /etc/firewalld/ipsets), and three rich rules in each active zone
// point at them, with priorities that decide who wins: the allow list first
// (-300), then the deny list (-200), then the blocked countries (-100). All
// three run before the zone's own port rules, so an allowed address is never
// blocked by a country, and a denied one reaches nothing.
//
// The agent keeps what the panel asked for (state.json) next to the country
// lists it fetched, and writes the sets from those, so the same call twice
// does the same, and the files can always be rebuilt.
package firewall

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"lpa/internal/fsutil"
	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Error codes the panel can act on.
const (
	CodeOff     = "firewall_off"     // the server has no firewalld (lpa.json "firewall" is "off", or it is not running)
	CodeKept    = "firewall_kept"    // a port the panel and SSH use cannot be closed
	CodeFetch   = "firewall_fetch"   // a country's list could not be fetched
	CodeTooMany = "firewall_toomany" // more addresses than the sets hold
	CodeLockout = "firewall_lockout" // the change would shut out an address that must stay reachable
)

// Limits on what the panel may send.
const (
	MaxListEntries = 10000  // addresses in the allow list, and in the deny list
	MaxCountries   = 60     // countries blocked at once
	MaxSetEntries  = 250000 // networks in the countries' set, per family
	maxFetch       = 4 << 20
)

// Names of the sets, and the priorities of their rules.
const (
	setAllow = "lpanel-allow"
	setDeny  = "lpanel-deny"
	setCC    = "lpanel-cc"
)

var (
	portRule    = regexp.MustCompile(`^([0-9]{1,5})(-([0-9]{1,5}))?$`)
	countryRule = regexp.MustCompile(`^[A-Z]{2}$`)
	// firewalld writes a port rule's rich rule in this one form.
	richPort = regexp.MustCompile(`^rule family="(ipv4|ipv6)" source address="([^"]+)" port port="([0-9]+(?:-[0-9]+)?)" protocol="(tcp|udp)" accept$`)
	versionR = regexp.MustCompile(`^[0-9][0-9A-Za-z.+-]{0,30}$`)
)

// Manager manages firewalld.
type Manager struct {
	Enabled bool
	Run     sysexec.Runner
	// Dir keeps state.json and the countries' lists; IPSets is firewalld's
	// folder for IP sets.
	Dir    string
	IPSets string
	// Keep are ports that may be limited to some addresses but never closed.
	Keep []int
	// Fetch gets a country's list; tests replace it.
	Fetch func(ctx context.Context, url string) ([]byte, error)
	Now   func() time.Time
	Log   *slog.Logger

	mu sync.Mutex
}

// Defaults fills what is not set.
func (m *Manager) Defaults() {
	if m.Dir == "" {
		m.Dir = "/var/lib/lpa/firewall"
	}
	if m.IPSets == "" {
		m.IPSets = "/etc/firewalld/ipsets"
	}
	if m.Keep == nil {
		m.Keep = []int{22, 7443, 7444}
	}
	if m.Fetch == nil {
		m.Fetch = httpFetch
	}
	if m.Now == nil {
		m.Now = time.Now
	}
	if m.Log == nil {
		m.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
}

// State is what the panel asked for.
type State struct {
	Allow     []string `json:"allow"`
	Deny      []string `json:"deny"`
	Countries []string `json:"countries"`
}

// PortRule is an open port: to everyone (no Sources) or to some addresses.
type PortRule struct {
	Port    string   `json:"port"`
	Proto   string   `json:"proto"`
	Sources []string `json:"sources"`
	// Via names the firewalld service that opens the port ("ssh"), when it
	// is not opened on its own; changing the port turns the service's ports
	// into plain ones first.
	Via string `json:"via"`
}

// CountryData says what is cached for one country.
type CountryData struct {
	Code    string `json:"code"`
	V4      int    `json:"v4"`
	V6      int    `json:"v6"`
	Fetched string `json:"fetched"`
}

// Info is the firewall's state, read-only.
type Info struct {
	Enabled bool   `json:"enabled"`
	Running bool   `json:"running"`
	Version string `json:"version"`
	Zone    string `json:"zone"`
	// Zones are the zones the rules are put in: the default and the active ones.
	Zones     []string      `json:"zones"`
	Ports     []PortRule    `json:"ports"`
	Services  []string      `json:"services"`
	Allow     []string      `json:"allow"`
	Deny      []string      `json:"deny"`
	Countries []string      `json:"countries"`
	Cached    []CountryData `json:"cached"`
	// Rules says the three rules that point at the sets are in place.
	Rules bool  `json:"rules"`
	Keep  []int `json:"keep"`
}

// parseAddrs reads the addresses that must stay reachable.
func parseAddrs(in []string) ([]netip.Addr, error) {
	out := []netip.Addr{}
	for _, s := range in {
		a, err := netip.ParseAddr(strings.TrimSpace(s))
		if err != nil {
			return nil, bad(fmt.Sprintf("%q is not an address", s))
		}
		out = append(out, a.Unmap())
	}
	return out, nil
}

// within says whether an address is in one of the entries (addresses or
// networks, as text).
func within(entries []string, ip netip.Addr) bool {
	for _, e := range entries {
		if p, err := netip.ParsePrefix(e); err == nil {
			if p.Contains(ip) {
				return true
			}
		} else if a, err := netip.ParseAddr(e); err == nil && a == ip {
			return true
		}
	}
	return false
}

// keepsPort says whether a range holds a port the panel or SSH use.
func (m *Manager) keepsPort(from, to int) bool {
	for _, k := range m.Keep {
		if k >= from && k <= to {
			return true
		}
	}
	return false
}

// off is the error when there is no firewalld to talk to.
func (m *Manager) off(ctx context.Context) error {
	if !m.Enabled {
		return protocol.Fail(CodeOff, "this server has no firewalld (lpa.json \"firewall\" is off)")
	}
	if code, _, err := m.Run.Run(ctx, "firewall-cmd", "--state"); err != nil || code != 0 {
		return protocol.Fail(CodeOff, "firewalld is not running")
	}
	return nil
}

// cmd runs firewall-cmd and fails with its words.
func (m *Manager) cmd(ctx context.Context, args ...string) (string, error) {
	code, out, err := m.Run.Run(ctx, "firewall-cmd", args...)
	if err != nil {
		return "", protocol.Fail(protocol.CodeFailed, "firewall-cmd could not run: "+err.Error())
	}
	if code != 0 {
		return out, protocol.Fail(protocol.CodeFailed, "firewall-cmd "+strings.Join(args, " ")+": "+strings.TrimSpace(firstLine(out)))
	}
	return out, nil
}

func firstLine(s string) string {
	s = strings.TrimSpace(s)
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return s[:i]
	}
	return s
}

// zones are the zones the rules go in: the default and every active one.
func (m *Manager) zones(ctx context.Context) ([]string, string, error) {
	def, err := m.cmd(ctx, "--get-default-zone")
	if err != nil {
		return nil, "", err
	}
	def = strings.TrimSpace(def)
	zones := []string{def}
	out, err := m.cmd(ctx, "--get-active-zones")
	if err != nil {
		return nil, "", err
	}
	for _, line := range strings.Split(out, "\n") {
		// A zone's name is on a line of its own; its interfaces and sources
		// are indented under it.
		if line == "" || line[0] == ' ' || line[0] == '\t' {
			continue
		}
		name := strings.TrimSpace(line)
		if zoneName.MatchString(name) && name != def {
			zones = append(zones, name)
		}
	}
	if !zoneName.MatchString(def) {
		return nil, "", protocol.Fail(protocol.CodeFailed, "firewalld's default zone has an odd name")
	}
	return zones, def, nil
}

var zoneName = regexp.MustCompile(`^[A-Za-z0-9_-]{1,40}$`)

// Info reads the firewall.
func (m *Manager) Info(ctx context.Context) Info {
	m.mu.Lock()
	defer m.mu.Unlock()

	info := Info{Enabled: m.Enabled, Keep: m.Keep, Ports: []PortRule{}, Services: []string{}, Zones: []string{}, Cached: []CountryData{}}
	st := m.state()
	info.Allow, info.Deny, info.Countries = st.Allow, st.Deny, st.Countries
	info.Cached = m.cached()

	if m.off(ctx) != nil {
		return info
	}
	info.Running = true

	if out, err := m.cmd(ctx, "--version"); err == nil && versionR.MatchString(strings.TrimSpace(out)) {
		info.Version = strings.TrimSpace(out)
	}
	zones, def, err := m.zones(ctx)
	if err != nil {
		return info
	}
	info.Zone, info.Zones = def, zones

	if out, err := m.cmd(ctx, "--permanent", "--zone="+def, "--list-services"); err == nil {
		info.Services = strings.Fields(out)
	}
	ports, _ := m.ports(ctx, def)
	info.Ports = ports
	info.Rules = m.rulesPresent(ctx, def)
	return info
}

// ports reads a zone's open ports: the plain ones, and the rich rules that
// open one to some addresses.
func (m *Manager) ports(ctx context.Context, zone string) ([]PortRule, error) {
	all := map[string]*PortRule{}
	key := func(port, proto string) string { return port + "/" + proto }

	out, err := m.cmd(ctx, "--permanent", "--zone="+zone, "--list-ports")
	if err != nil {
		return []PortRule{}, err
	}
	for _, f := range strings.Fields(out) {
		port, proto, ok := strings.Cut(f, "/")
		if !ok {
			continue
		}
		all[key(port, proto)] = &PortRule{Port: port, Proto: proto, Sources: []string{}}
	}
	svcs, err := m.serviceList(ctx, zone)
	if err != nil {
		return []PortRule{}, err
	}
	for _, svc := range svcs {
		for _, f := range svc.ports {
			port, proto, _ := strings.Cut(f, "/")
			if all[key(port, proto)] == nil {
				all[key(port, proto)] = &PortRule{Port: port, Proto: proto, Sources: []string{}, Via: svc.name}
			}
		}
	}

	out, err = m.cmd(ctx, "--permanent", "--zone="+zone, "--list-rich-rules")
	if err != nil {
		return []PortRule{}, err
	}
	for _, line := range strings.Split(out, "\n") {
		g := richPort.FindStringSubmatch(strings.TrimSpace(line))
		if g == nil {
			continue
		}
		k := key(g[3], g[4])
		if all[k] == nil {
			all[k] = &PortRule{Port: g[3], Proto: g[4], Sources: []string{}}
		}
		all[k].Sources = append(all[k].Sources, g[2])
	}

	rules := make([]PortRule, 0, len(all))
	for _, r := range all {
		sort.Strings(r.Sources)
		rules = append(rules, *r)
	}
	sort.Slice(rules, func(i, j int) bool {
		a, _ := strconv.Atoi(strings.SplitN(rules[i].Port, "-", 2)[0])
		b, _ := strconv.Atoi(strings.SplitN(rules[j].Port, "-", 2)[0])
		if a != b {
			return a < b
		}
		return rules[i].Proto < rules[j].Proto
	})
	return rules, nil
}

type service struct {
	name  string
	ports []string // "22/tcp", "30000-30100/tcp"
}

// serviceList reads the zone's services and the ports each one opens.
func (m *Manager) serviceList(ctx context.Context, zone string) ([]service, error) {
	out, err := m.cmd(ctx, "--permanent", "--zone="+zone, "--list-services")
	if err != nil {
		return nil, err
	}
	var list []service
	for _, name := range strings.Fields(out) {
		if !zoneName.MatchString(name) {
			continue
		}
		ports, err := m.cmd(ctx, "--permanent", "--service="+name, "--get-ports")
		if err != nil {
			return nil, err
		}
		list = append(list, service{name: name, ports: strings.Fields(ports)})
	}
	return list, nil
}

// unbundle takes a service out of the zone when it opens a port in the
// range, and opens its ports one by one instead, so this one can then be
// limited or closed without the others being lost.
func (m *Manager) unbundle(ctx context.Context, zone string, from, to int, proto string) error {
	svcs, err := m.serviceList(ctx, zone)
	if err != nil {
		return err
	}
	for _, svc := range svcs {
		hit := false
		for _, f := range svc.ports {
			spec, p, _ := strings.Cut(f, "/")
			if _, a, b, err := parsePort(spec); err == nil && p == proto && a <= to && b >= from {
				hit = true
			}
		}
		if !hit {
			continue
		}
		if _, err := m.cmd(ctx, "--permanent", "--zone="+zone, "--remove-service="+svc.name); err != nil {
			return err
		}
		for _, f := range svc.ports {
			if _, err := m.cmd(ctx, "--permanent", "--zone="+zone, "--add-port="+f); err != nil {
				return err
			}
		}
		m.Log.Info("service opened as plain ports", "service", svc.name, "ports", svc.ports)
	}
	return nil
}

// PortArgs open or close a port.
type PortArgs struct {
	// Action is "open" (also to change who may reach it) or "close".
	Action string `json:"action"`
	// Port is a number or a range, "30000-30100".
	Port  string `json:"port"`
	Proto string `json:"proto"`
	// Sources limits who may reach it, addresses or networks; none is everyone.
	Sources []string `json:"sources"`
	// Protect are addresses that must still reach the port afterwards (the
	// administrator's own, when the port is one the panel or SSH use): a
	// limit that leaves them out is refused.
	Protect []string `json:"protect"`
}

// PortResult says how the port is now.
type PortResult struct {
	Port    string   `json:"port"`
	Proto   string   `json:"proto"`
	Sources []string `json:"sources"`
	Closed  bool     `json:"closed"`
}

func bad(msg string) error { return protocol.Fail(protocol.CodeBadArgs, msg) }

// parsePort checks a port or range, returning it as firewalld writes it, and
// the first and last port.
func parsePort(s string) (string, int, int, error) {
	g := portRule.FindStringSubmatch(s)
	if g == nil {
		return "", 0, 0, bad("a port is a number, or a range such as 30000-30100")
	}
	from, _ := strconv.Atoi(g[1])
	to := from
	if g[3] != "" {
		to, _ = strconv.Atoi(g[3])
	}
	if from < 1 || to > 65535 || from > to {
		return "", 0, 0, bad("ports run from 1 to 65535")
	}
	if g[3] != "" {
		return fmt.Sprintf("%d-%d", from, to), from, to, nil
	}
	return strconv.Itoa(from), from, to, nil
}

// Port opens, limits or closes a port in the default zone.
func (m *Manager) Port(ctx context.Context, a PortArgs) (PortResult, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	if a.Action != "open" && a.Action != "close" {
		return PortResult{}, bad("action is open or close")
	}
	if a.Proto != "tcp" && a.Proto != "udp" {
		return PortResult{}, bad("proto is tcp or udp")
	}
	port, from, to, err := parsePort(a.Port)
	if err != nil {
		return PortResult{}, err
	}
	sources, err := normalize(a.Sources, 1, 1)
	if err != nil {
		return PortResult{}, err
	}
	if len(sources) > 200 {
		return PortResult{}, bad("at most 200 addresses for one port")
	}
	if err := m.off(ctx); err != nil {
		return PortResult{}, err
	}
	protect, err := parseAddrs(a.Protect)
	if err != nil {
		return PortResult{}, err
	}
	if a.Action == "open" && len(sources) > 0 && m.keepsPort(from, to) {
		for _, ip := range protect {
			if !within(sources, ip) {
				return PortResult{}, protocol.Fail(CodeLockout, fmt.Sprintf("your address %s would no longer reach port %s: add it to the addresses", ip, port))
			}
		}
	}
	if a.Action == "close" {
		for _, k := range m.Keep {
			if k >= from && k <= to {
				return PortResult{}, protocol.Fail(CodeKept, fmt.Sprintf("port %d is how the panel and SSH are reached: limit it to some addresses instead of closing it", k))
			}
		}
	}

	_, def, err := m.zones(ctx)
	if err != nil {
		return PortResult{}, err
	}
	zone := "--zone=" + def
	spec := port + "/" + a.Proto

	if err := m.unbundle(ctx, def, from, to, a.Proto); err != nil {
		return PortResult{}, err
	}

	// Start from nothing for this port, then add what is wanted: a plain
	// port, or a rule per address. The permanent rules come first, so a
	// failure part-way leaves the live firewall as it was.
	current, err := m.ports(ctx, def)
	if err != nil {
		return PortResult{}, err
	}
	for _, r := range current {
		if r.Port != port || r.Proto != a.Proto {
			continue
		}
		if len(r.Sources) == 0 {
			if _, err := m.cmd(ctx, "--permanent", zone, "--remove-port="+spec); err != nil {
				return PortResult{}, err
			}
		}
		for _, s := range r.Sources {
			if _, err := m.cmd(ctx, "--permanent", zone, "--remove-rich-rule="+portRich(s, port, a.Proto)); err != nil {
				return PortResult{}, err
			}
		}
	}
	if a.Action == "open" {
		if len(sources) == 0 {
			if _, err := m.cmd(ctx, "--permanent", zone, "--add-port="+spec); err != nil {
				return PortResult{}, err
			}
		}
		for _, s := range sources {
			if _, err := m.cmd(ctx, "--permanent", zone, "--add-rich-rule="+portRich(s, port, a.Proto)); err != nil {
				return PortResult{}, err
			}
		}
	}
	if _, err := m.cmd(ctx, "--reload"); err != nil {
		return PortResult{}, err
	}
	m.Log.Info("port changed", "action", a.Action, "port", spec, "sources", len(sources))
	return PortResult{Port: port, Proto: a.Proto, Sources: sources, Closed: a.Action == "close"}, nil
}

// portRich is the rich rule that opens a port to one address.
func portRich(source, port, proto string) string {
	family := "ipv4"
	if strings.Contains(source, ":") {
		family = "ipv6"
	}
	return fmt.Sprintf(`rule family="%s" source address="%s" port port="%s" protocol="%s" accept`, family, source, port, proto)
}

// normalize checks addresses and networks, and returns each in its
// canonical form (a single host without its /32 or /128), sorted and without
// repeats. The shortest prefixes allowed are minV4 and minV6, so one entry
// cannot be the whole Internet.
func normalize(in []string, minV4, minV6 int) ([]string, error) {
	seen := map[string]bool{}
	out := make([]string, 0, len(in))
	for _, raw := range in {
		s := strings.TrimSpace(raw)
		if s == "" {
			continue
		}
		notOne := bad(fmt.Sprintf("%q is not an address or a network", raw))
		var p netip.Prefix
		if strings.Contains(s, "/") {
			var err error
			if p, err = netip.ParsePrefix(s); err != nil {
				return nil, notOne
			}
		} else {
			a, err := netip.ParseAddr(s)
			if err != nil {
				return nil, notOne
			}
			p = netip.PrefixFrom(a, a.BitLen())
		}
		if p.Addr().Is4In6() || p.Addr().Zone() != "" {
			return nil, notOne
		}
		p = p.Masked()
		if min := map[bool]int{true: minV4, false: minV6}[p.Addr().Is4()]; p.Bits() < min {
			return nil, bad(fmt.Sprintf("%q is too wide a network (at least /%d)", raw, min))
		}
		text := p.String()
		if p.Bits() == p.Addr().BitLen() {
			text = p.Addr().String()
		}
		if !seen[text] {
			seen[text] = true
			out = append(out, text)
		}
	}
	sort.Strings(out)
	return out, nil
}

// ListsArgs are the allow list and the deny list, whole.
type ListsArgs struct {
	Allow []string `json:"allow"`
	Deny  []string `json:"deny"`
	// Protect are addresses that must stay reachable: a deny list that
	// would block one (and the allow list does not name it) is refused.
	Protect []string `json:"protect"`
}

// Lists is what the sets hold now.
type Lists struct {
	Allow int `json:"allow"`
	Deny  int `json:"deny"`
}

// loopback is never denied, from here or by a network that contains it.
var loopbacks = []netip.Prefix{netip.MustParsePrefix("127.0.0.0/8"), netip.MustParsePrefix("::1/128")}

func overlapsLoopback(entry string) bool {
	p, err := netip.ParsePrefix(entry)
	if err != nil {
		a, err2 := netip.ParseAddr(entry)
		if err2 != nil {
			return false
		}
		p = netip.PrefixFrom(a, a.BitLen())
	}
	for _, l := range loopbacks {
		if l.Overlaps(p) {
			return true
		}
	}
	return false
}

// SetLists replaces the allow and deny lists.
func (m *Manager) SetLists(ctx context.Context, a ListsArgs) (Lists, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	allow, err := normalize(a.Allow, 8, 16)
	if err != nil {
		return Lists{}, err
	}
	deny, err := normalize(a.Deny, 8, 16)
	if err != nil {
		return Lists{}, err
	}
	if len(allow) > MaxListEntries || len(deny) > MaxListEntries {
		return Lists{}, protocol.Fail(CodeTooMany, fmt.Sprintf("each list holds at most %d entries", MaxListEntries))
	}
	for _, d := range deny {
		if overlapsLoopback(d) {
			return Lists{}, bad(fmt.Sprintf("%s includes this server's own loopback address, which is never blocked", d))
		}
	}
	protect, err := parseAddrs(a.Protect)
	if err != nil {
		return Lists{}, err
	}
	for _, ip := range protect {
		if within(deny, ip) && !within(allow, ip) {
			return Lists{}, protocol.Fail(CodeLockout, fmt.Sprintf("your address %s is in the deny list: that would shut you out", ip))
		}
	}
	if err := m.off(ctx); err != nil {
		return Lists{}, err
	}

	st := m.state()
	st.Allow, st.Deny = allow, deny
	if err := m.apply(ctx, st); err != nil {
		return Lists{}, err
	}
	return Lists{Allow: len(allow), Deny: len(deny)}, nil
}

// CountriesArgs are the countries to block, whole.
type CountriesArgs struct {
	Codes []string `json:"codes"`
	// Refresh fetches every list again, even one that is cached.
	Refresh bool `json:"refresh"`
	// Protect are addresses that must stay reachable: blocking a country
	// that contains one (unless the allow or deny lists decide it) is refused.
	Protect []string `json:"protect"`
}

// CountriesResult says what is blocked.
type CountriesResult struct {
	Codes   []string `json:"codes"`
	V4      int      `json:"v4"`
	V6      int      `json:"v6"`
	Fetched []string `json:"fetched"`
}

// SetCountries blocks these countries (and no others), fetching the lists
// that are missing.
func (m *Manager) SetCountries(ctx context.Context, a CountriesArgs) (CountriesResult, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	seen := map[string]bool{}
	codes := []string{}
	for _, c := range a.Codes {
		c = strings.ToUpper(strings.TrimSpace(c))
		if !countryRule.MatchString(c) {
			return CountriesResult{}, bad(fmt.Sprintf("%q is not a two-letter country code", c))
		}
		if !seen[c] {
			seen[c] = true
			codes = append(codes, c)
		}
	}
	sort.Strings(codes)
	if len(codes) > MaxCountries {
		return CountriesResult{}, protocol.Fail(CodeTooMany, fmt.Sprintf("at most %d countries at once", MaxCountries))
	}
	if err := m.off(ctx); err != nil {
		return CountriesResult{}, err
	}

	fetched := []string{}
	for _, c := range codes {
		if !a.Refresh && m.have(c) {
			continue
		}
		if err := m.fetch(ctx, c); err != nil {
			return CountriesResult{}, err
		}
		fetched = append(fetched, c)
	}

	st := m.state()
	st.Countries = codes
	v4, v6, err := m.countryEntries(codes)
	if err != nil {
		return CountriesResult{}, err
	}
	protect, err := parseAddrs(a.Protect)
	if err != nil {
		return CountriesResult{}, err
	}
	for _, ip := range protect {
		if (within(v4, ip) || within(v6, ip)) && !within(st.Allow, ip) {
			return CountriesResult{}, protocol.Fail(CodeLockout, fmt.Sprintf("your address %s is in a country you chose: that would shut you out (add it to the allow list first)", ip))
		}
	}
	if err := m.apply(ctx, st); err != nil {
		return CountriesResult{}, err
	}
	return CountriesResult{Codes: codes, V4: len(v4), V6: len(v6), Fetched: fetched}, nil
}

func (m *Manager) countryPath(code, family string) string {
	return filepath.Join(m.Dir, "countries", code+"."+family)
}

func (m *Manager) have(code string) bool {
	_, e4 := os.Stat(m.countryPath(code, "v4"))
	_, e6 := os.Stat(m.countryPath(code, "v6"))
	return e4 == nil && e6 == nil
}

// fetch gets a country's networks (IPv4 and IPv6) and caches them.
func (m *Manager) fetch(ctx context.Context, code string) error {
	urls := map[string]string{
		"v4": "https://www.ipdeny.com/ipblocks/data/aggregated/" + strings.ToLower(code) + "-aggregated.zone",
		"v6": "https://www.ipdeny.com/ipv6/ipaddress-blocks/" + strings.ToLower(code) + ".zone",
	}
	got := map[string][]byte{}
	for fam, url := range urls {
		body, err := m.Fetch(ctx, url)
		if err != nil {
			return protocol.Fail(CodeFetch, fmt.Sprintf("the list for %s could not be fetched: %v", code, err))
		}
		list, err := parseNetworks(body, fam == "v6")
		if err != nil {
			return protocol.Fail(CodeFetch, fmt.Sprintf("the list for %s is not a list of networks: %v", code, err))
		}
		got[fam] = []byte(strings.Join(list, "\n") + "\n")
	}
	for fam, data := range got {
		if err := fsutil.EnsureDir(filepath.Dir(m.countryPath(code, fam)), 0o700); err != nil {
			return err
		}
		if err := fsutil.WriteFileAtomic(m.countryPath(code, fam), data, 0o600); err != nil {
			return err
		}
	}
	return nil
}

// parseNetworks reads a list of networks, one a line; a line that is not one
// ends the whole list as bad, so a page of HTML is never taken for a list.
func parseNetworks(body []byte, v6 bool) ([]string, error) {
	out := []string{}
	for _, line := range strings.Split(string(body), "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		p, err := netip.ParsePrefix(line)
		if err != nil {
			return nil, fmt.Errorf("%q", truncate(line, 40))
		}
		if p.Addr().Is6() != v6 {
			return nil, fmt.Errorf("%q is the wrong family", line)
		}
		out = append(out, p.Masked().String())
	}
	if len(out) == 0 {
		return nil, errors.New("it is empty")
	}
	return out, nil
}

func truncate(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}

// httpFetch gets one of the two address-list pages, over HTTPS.
func httpFetch(ctx context.Context, url string) ([]byte, error) {
	if !strings.HasPrefix(url, "https://www.ipdeny.com/") {
		return nil, errors.New("not an address list")
	}
	ctx, cancel := context.WithTimeout(ctx, 45*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "lpa")
	resp, err := (&http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}).Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("the server said %d", resp.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxFetch+1))
	if err != nil {
		return nil, err
	}
	if len(body) > maxFetch {
		return nil, errors.New("the list is too large")
	}
	return body, nil
}

// countryEntries joins the cached lists of the blocked countries.
func (m *Manager) countryEntries(codes []string) ([]string, []string, error) {
	read := func(code, fam string) ([]string, error) {
		b, err := os.ReadFile(m.countryPath(code, fam))
		if err != nil {
			return nil, err
		}
		return strings.Fields(string(b)), nil
	}
	var v4, v6 []string
	for _, c := range codes {
		a, err := read(c, "v4")
		if err != nil {
			return nil, nil, err
		}
		b, err := read(c, "v6")
		if err != nil {
			return nil, nil, err
		}
		v4, v6 = append(v4, a...), append(v6, b...)
	}
	if len(v4) > MaxSetEntries || len(v6) > MaxSetEntries {
		return nil, nil, protocol.Fail(CodeTooMany, "those countries have more networks than the firewall's set holds")
	}
	return v4, v6, nil
}

// cached lists what has been fetched.
func (m *Manager) cached() []CountryData {
	out := []CountryData{}
	files, _ := filepath.Glob(filepath.Join(m.Dir, "countries", "??.v4"))
	for _, f := range files {
		code := strings.TrimSuffix(filepath.Base(f), ".v4")
		if !countryRule.MatchString(code) || !m.have(code) {
			continue
		}
		d := CountryData{Code: code}
		if b, err := os.ReadFile(f); err == nil {
			d.V4 = len(strings.Fields(string(b)))
		}
		if b, err := os.ReadFile(m.countryPath(code, "v6")); err == nil {
			d.V6 = len(strings.Fields(string(b)))
		}
		if st, err := os.Stat(f); err == nil {
			d.Fetched = st.ModTime().UTC().Format(time.RFC3339)
		}
		out = append(out, d)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Code < out[j].Code })
	return out
}

// state reads state.json (empty when there is none).
func (m *Manager) state() State {
	st := State{Allow: []string{}, Deny: []string{}, Countries: []string{}}
	b, err := os.ReadFile(filepath.Join(m.Dir, "state.json"))
	if err == nil && json.Unmarshal(b, &st) == nil {
		if st.Allow == nil {
			st.Allow = []string{}
		}
		if st.Deny == nil {
			st.Deny = []string{}
		}
		if st.Countries == nil {
			st.Countries = []string{}
		}
	}
	return st
}

// apply writes the sets for a state, makes sure the rules that use them are
// in the zones, reloads firewalld when anything changed, and only then keeps
// the state.
func (m *Manager) apply(ctx context.Context, st State) error {
	cc4, cc6, err := m.countryEntries(st.Countries)
	if err != nil {
		return err
	}
	split := func(in []string) (v4, v6 []string) {
		for _, e := range in {
			if strings.Contains(e, ":") {
				v6 = append(v6, e)
			} else {
				v4 = append(v4, e)
			}
		}
		return
	}
	al4, al6 := split(st.Allow)
	de4, de6 := split(st.Deny)

	sets := []struct {
		name, family string
		entries      []string
		max          int
	}{
		{setAllow, "inet", al4, 65536}, {setAllow + "6", "inet6", al6, 65536},
		{setDeny, "inet", de4, 65536}, {setDeny + "6", "inet6", de6, 65536},
		{setCC, "inet", cc4, MaxSetEntries + 1}, {setCC + "6", "inet6", cc6, MaxSetEntries + 1},
	}
	if err := fsutil.EnsureDir(m.IPSets, 0o750); err != nil {
		return err
	}
	changed := false
	for _, s := range sets {
		path := filepath.Join(m.IPSets, s.name+".xml")
		data := ipsetXML(s.name, s.family, s.entries, s.max)
		if old, err := os.ReadFile(path); err == nil && bytes.Equal(old, data) {
			continue
		}
		if err := fsutil.WriteFileAtomic(path, data, 0o640); err != nil {
			return err
		}
		changed = true
	}
	if changed {
		// The rules below name the sets, so firewalld must know them first.
		if _, err := m.cmd(ctx, "--reload"); err != nil {
			return err
		}
	}

	zones, _, err := m.zones(ctx)
	if err != nil {
		return err
	}
	added := false
	for _, z := range zones {
		for _, r := range managedRules() {
			code, _, err := m.Run.Run(ctx, "firewall-cmd", "--permanent", "--zone="+z, "--query-rich-rule="+r)
			if err != nil {
				return protocol.Fail(protocol.CodeFailed, err.Error())
			}
			if code == 0 {
				continue
			}
			if _, err := m.cmd(ctx, "--permanent", "--zone="+z, "--add-rich-rule="+r); err != nil {
				return err
			}
			added = true
		}
	}
	if added {
		if _, err := m.cmd(ctx, "--reload"); err != nil {
			return err
		}
	}

	b, _ := json.MarshalIndent(st, "", "  ")
	if err := fsutil.EnsureDir(m.Dir, 0o700); err != nil {
		return err
	}
	if err := fsutil.WriteFileAtomic(filepath.Join(m.Dir, "state.json"), append(b, '\n'), 0o600); err != nil {
		return err
	}
	m.Log.Info("firewall lists applied", "allow", len(st.Allow), "deny", len(st.Deny), "countries", len(st.Countries), "reloaded", changed || added)
	return nil
}

// managedRules are the rules that point at the sets, in priority order.
func managedRules() []string {
	rule := func(family string, prio int, set, verdict string) string {
		return fmt.Sprintf(`rule family="%s" priority="%d" source ipset="%s" %s`, family, prio, set, verdict)
	}
	return []string{
		rule("ipv4", -300, setAllow, "accept"), rule("ipv6", -300, setAllow+"6", "accept"),
		rule("ipv4", -200, setDeny, "drop"), rule("ipv6", -200, setDeny+"6", "drop"),
		rule("ipv4", -100, setCC, "drop"), rule("ipv6", -100, setCC+"6", "drop"),
	}
}

func (m *Manager) rulesPresent(ctx context.Context, zone string) bool {
	for _, r := range managedRules() {
		code, _, err := m.Run.Run(ctx, "firewall-cmd", "--permanent", "--zone="+zone, "--query-rich-rule="+r)
		if err != nil || code != 0 {
			return false
		}
	}
	return true
}

// ipsetXML is firewalld's file for one set.
func ipsetXML(name, family string, entries []string, max int) []byte {
	var b bytes.Buffer
	fmt.Fprintf(&b, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<ipset type=\"hash:net\">\n  <short>%s</short>\n  <description>Kept by the LPanel agent; changes made here are overwritten.</description>\n", name)
	fmt.Fprintf(&b, "  <option name=\"family\" value=\"%s\"/>\n  <option name=\"maxelem\" value=\"%d\"/>\n", family, max)
	for _, e := range entries {
		fmt.Fprintf(&b, "  <entry>%s</entry>\n", e)
	}
	b.WriteString("</ipset>\n")
	return b.Bytes()
}
