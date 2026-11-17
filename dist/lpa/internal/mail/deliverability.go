package mail

import (
	"context"
	"errors"
	"net"
	"net/netip"
	"sort"
	"strings"
	"sync"
	"time"
)

// Deliverability: what the world's DNS says about the account's mail
// domains and this server's address, for the checks page (mail.check). The
// agent only gathers and evaluates; the panel says what each finding means.

// Blocklists are the DNS blocklists the server's address is looked up in.
var Blocklists = []string{"zen.spamhaus.org", "bl.spamcop.net", "b.barracudacentral.org", "psbl.surriel.com", "bl.mailspike.net"}

// MaxCheckDomains caps one check.
const MaxCheckDomains = 40

// CheckArgs name what to check.
type CheckArgs struct {
	Domains []string `json:"domains"`
	// IP is the address mail leaves from (the server's public IPv4 or IPv6);
	// empty checks the server's own public addresses.
	IP string `json:"ip"`
	// Hostname is the name the server greets with (its PTR should match).
	Hostname string `json:"hostname"`
	// Lists looks the address up in the blocklists too.
	Lists bool `json:"lists"`
}

// CheckResult is what was found.
type CheckResult struct {
	Domains map[string]DomainCheck `json:"domains"`
	IPs     map[string]IPCheck     `json:"ips"`
}

// DomainCheck is one domain's records as the world sees them.
type DomainCheck struct {
	// SPF is the domain's SPF record ("" for none), and what it says of
	// each address checked (pass, fail, softfail, neutral, none,
	// permerror, temperror).
	SPF       string            `json:"spf"`
	SPFCount  int               `json:"spf_count"`
	SPFResult map[string]string `json:"spf_result"`
	DMARC     string            `json:"dmarc"`
	DKIM      string            `json:"dkim"`
	MX        []MXCheck         `json:"mx"`
	// Error is set when the domain's DNS could not be asked at all.
	Error string `json:"error,omitempty"`
}

// MXCheck is one of a domain's mail servers.
type MXCheck struct {
	Host      string   `json:"host"`
	Pref      int      `json:"pref"`
	Addresses []string `json:"addresses"`
	Here      bool     `json:"here"`
}

// IPCheck is what the world's DNS says of one of the server's addresses.
type IPCheck struct {
	Private bool `json:"private"`
	// PTR is its reverse name, and Forward whether that name leads back to
	// the address (forward-confirmed reverse DNS).
	PTR     []string `json:"ptr"`
	Forward bool     `json:"forward"`
	// Lists holds each blocklist's answer: "listed", "clear", or "unknown"
	// (the list refused to answer this resolver, or could not be reached).
	Lists map[string]string `json:"lists"`
}

func (m *Manager) resolver() *net.Resolver {
	if m.Resolver == "" {
		return net.DefaultResolver
	}
	addr := m.Resolver
	return &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
		return (&net.Dialer{Timeout: 5 * time.Second}).DialContext(ctx, network, addr)
	}}
}

// Check looks everything up at once, each lookup with its own short limit.
func (m *Manager) Check(ctx context.Context, a CheckArgs) (CheckResult, error) {
	res := CheckResult{Domains: map[string]DomainCheck{}, IPs: map[string]IPCheck{}}
	if len(a.Domains) > MaxCheckDomains {
		return res, badArgs("at most %d domains at once", MaxCheckDomains)
	}
	for _, d := range a.Domains {
		if err := checkDomain(d); err != nil {
			return res, err
		}
	}
	if a.Hostname != "" {
		if err := checkDomain(a.Hostname); err != nil {
			return res, err
		}
	}
	var ips []netip.Addr
	if a.IP != "" {
		ip, err := netip.ParseAddr(a.IP)
		if err != nil {
			return res, badArgs("%q is not an IP address", a.IP)
		}
		ips = append(ips, ip.Unmap())
	} else {
		for _, s := range m.localIPs() {
			if ip, err := netip.ParseAddr(s); err == nil && !ip.IsLoopback() && !ip.IsLinkLocalUnicast() {
				ips = append(ips, ip.Unmap())
			}
		}
	}
	local := map[string]bool{}
	for _, s := range m.localIPs() {
		local[s] = true
	}
	for _, ip := range ips {
		local[ip.String()] = true
	}
	r := m.lookups()
	var mu sync.Mutex
	var wg sync.WaitGroup
	for _, d := range a.Domains {
		wg.Add(1)
		go func(d string) {
			defer wg.Done()
			c := m.checkDomain(ctx, r, d, ips, local)
			mu.Lock()
			res.Domains[d] = c
			mu.Unlock()
		}(d)
	}
	for _, ip := range ips {
		wg.Add(1)
		go func(ip netip.Addr) {
			defer wg.Done()
			c := m.checkIP(ctx, r, ip, a.Lists)
			mu.Lock()
			res.IPs[ip.String()] = c
			mu.Unlock()
		}(ip)
	}
	wg.Wait()
	return res, nil
}

// lookups are the DNS questions the checks ask (tests replace them).
type lookups struct {
	txt  func(ctx context.Context, name string) ([]string, error)
	mx   func(ctx context.Context, name string) ([]*net.MX, error)
	host func(ctx context.Context, name string) ([]string, error)
	addr func(ctx context.Context, ip string) ([]string, error)
}

func (m *Manager) lookups() lookups {
	if m.Lookups != nil {
		return *m.Lookups
	}
	r := m.resolver()
	return lookups{txt: r.LookupTXT, mx: r.LookupMX, host: r.LookupHost, addr: r.LookupAddr}
}

func (m *Manager) localIPs() []string {
	if m.LocalIPs != nil {
		return m.LocalIPs()
	}
	var out []string
	addrs, _ := net.InterfaceAddrs()
	for _, a := range addrs {
		if n, ok := a.(*net.IPNet); ok {
			out = append(out, n.IP.String())
		}
	}
	return out
}

func short(ctx context.Context) (context.Context, context.CancelFunc) {
	return context.WithTimeout(ctx, 6*time.Second)
}

// notFound is a name or record that is not there (not a failure to ask).
func notFound(err error) bool {
	var de *net.DNSError
	return errors.As(err, &de) && de.IsNotFound
}

func (m *Manager) checkDomain(ctx context.Context, r lookups, d string, ips []netip.Addr, local map[string]bool) DomainCheck {
	c := DomainCheck{SPFResult: map[string]string{}, MX: []MXCheck{}}
	tag := func(name, prefix string) (string, int, error) {
		cctx, cancel := short(ctx)
		defer cancel()
		txts, err := r.txt(cctx, name)
		if err != nil && !notFound(err) {
			return "", 0, err
		}
		found, n := "", 0
		for _, t := range txts {
			if strings.HasPrefix(strings.ToLower(strings.TrimSpace(t)), prefix) {
				if n == 0 {
					found = t
				}
				n++
			}
		}
		return found, n, nil
	}
	spf, n, err := tag(d, "v=spf1")
	if err != nil {
		c.Error = err.Error()
		return c
	}
	c.SPF, c.SPFCount = spf, n
	c.DMARC, _, _ = tag("_dmarc."+d, "v=dmarc1")
	c.DKIM, _, _ = tag(DKIMSelector+"._domainkey."+d, "v=dkim1")
	if c.DKIM == "" {
		// A key published without its version tag is still a key.
		c.DKIM, _, _ = tag(DKIMSelector+"._domainkey."+d, "")
	}
	for _, ip := range ips {
		e := &spfEval{r: r, ctx: ctx}
		c.SPFResult[ip.String()] = e.check(ip, d, 0)
	}
	cctx, cancel := short(ctx)
	mxs, _ := r.mx(cctx, d)
	cancel()
	for _, mx := range mxs {
		host := strings.ToLower(strings.TrimSuffix(mx.Host, "."))
		cctx, cancel := short(ctx)
		addrs, _ := r.host(cctx, host)
		cancel()
		sort.Strings(addrs)
		entry := MXCheck{Host: host, Pref: int(mx.Pref), Addresses: append([]string{}, addrs...)}
		for _, a := range addrs {
			entry.Here = entry.Here || local[a]
		}
		c.MX = append(c.MX, entry)
	}
	sort.SliceStable(c.MX, func(i, j int) bool { return c.MX[i].Pref < c.MX[j].Pref })
	return c
}

func (m *Manager) checkIP(ctx context.Context, r lookups, ip netip.Addr, lists bool) IPCheck {
	c := IPCheck{PTR: []string{}, Lists: map[string]string{}}
	if ip.IsPrivate() || ip.IsLoopback() || !ip.IsGlobalUnicast() {
		c.Private = true
		return c
	}
	cctx, cancel := short(ctx)
	names, _ := r.addr(cctx, ip.String())
	cancel()
	for _, n := range names {
		n = strings.ToLower(strings.TrimSuffix(n, "."))
		c.PTR = append(c.PTR, n)
		cctx, cancel := short(ctx)
		addrs, _ := r.host(cctx, n)
		cancel()
		for _, a := range addrs {
			if p, err := netip.ParseAddr(a); err == nil && p.Unmap() == ip {
				c.Forward = true
			}
		}
	}
	if !lists || !ip.Is4() {
		return c
	}
	o := ip.As4()
	rev := strings.Join([]string{itoa(o[3]), itoa(o[2]), itoa(o[1]), itoa(o[0])}, ".")
	var mu sync.Mutex
	var wg sync.WaitGroup
	for _, list := range Blocklists {
		wg.Add(1)
		go func(list string) {
			defer wg.Done()
			cctx, cancel := short(ctx)
			defer cancel()
			addrs, err := r.host(cctx, rev+"."+list)
			state := "clear"
			switch {
			case err != nil && !notFound(err):
				state = "unknown"
			case err == nil && len(addrs) > 0:
				state = "listed"
				for _, a := range addrs {
					// 127.255.255.x: the list refuses this resolver (such
					// as a public one), not a listing.
					if strings.HasPrefix(a, "127.255.255.") || !strings.HasPrefix(a, "127.") {
						state = "unknown"
					}
				}
			}
			mu.Lock()
			c.Lists[list] = state
			mu.Unlock()
		}(list)
	}
	wg.Wait()
	return c
}

func itoa(b byte) string {
	const digits = "0123456789"
	if b < 10 {
		return digits[b : b+1]
	}
	if b < 100 {
		return digits[b/10:b/10+1] + digits[b%10:b%10+1]
	}
	return digits[b/100:b/100+1] + digits[b/10%10:b/10%10+1] + digits[b%10:b%10+1]
}

// spfEval evaluates SPF (RFC 7208) for one address, closely enough for a
// checks page: ip4, ip6, a, mx, include, redirect, all and exists, with the
// limit of ten lookups. Macros are not expanded (a term that has one is a
// permerror's "neutral" here: such records are rare and their result cannot
// be known without a message).
type spfEval struct {
	r       lookups
	ctx     context.Context
	queries int
}

const maxSPFQueries = 10

func (e *spfEval) lookup() bool {
	e.queries++
	return e.queries <= maxSPFQueries
}

func (e *spfEval) check(ip netip.Addr, domain string, depth int) string {
	if depth > maxSPFQueries {
		return "permerror"
	}
	cctx, cancel := short(e.ctx)
	txts, err := e.r.txt(cctx, domain)
	cancel()
	if err != nil && !notFound(err) {
		return "temperror"
	}
	var record string
	count := 0
	for _, t := range txts {
		if l := strings.ToLower(strings.TrimSpace(t)); l == "v=spf1" || strings.HasPrefix(l, "v=spf1 ") {
			record = l
			count++
		}
	}
	if count == 0 {
		return "none"
	}
	if count > 1 {
		return "permerror"
	}
	var redirect string
	for _, term := range strings.Fields(record)[1:] {
		if strings.Contains(term, "%") {
			return "neutral"
		}
		if v, ok := strings.CutPrefix(term, "redirect="); ok {
			redirect = v
			continue
		}
		if strings.Contains(term, "=") {
			continue // another modifier (exp=)
		}
		qual := "pass"
		switch term[0] {
		case '+':
			term = term[1:]
		case '-':
			qual, term = "fail", term[1:]
		case '~':
			qual, term = "softfail", term[1:]
		case '?':
			qual, term = "neutral", term[1:]
		}
		match, res := e.match(ip, domain, term, depth)
		if res != "" {
			return res
		}
		if match {
			return qual
		}
	}
	if redirect != "" {
		if !e.lookup() {
			return "permerror"
		}
		res := e.check(ip, redirect, depth+1)
		if res == "none" {
			return "permerror"
		}
		return res
	}
	return "neutral"
}

// match says whether a mechanism matches; a non-empty result ends the check.
func (e *spfEval) match(ip netip.Addr, domain, term string, depth int) (bool, string) {
	name, cidr, _ := strings.Cut(term, "/")
	mech, arg, _ := strings.Cut(name, ":")
	target := domain
	if arg != "" {
		target = arg
	}
	bits4, bits6 := 32, 128
	if cidr != "" {
		// a/24, a//64 or a/24//64
		parts := strings.SplitN(cidr, "//", 2)
		if parts[0] != "" {
			bits4 = atoiDefault(parts[0], 32)
		}
		if len(parts) == 2 {
			bits6 = atoiDefault(parts[1], 128)
		}
		if strings.HasPrefix(cidr, "/") {
			bits6 = atoiDefault(strings.TrimPrefix(cidr, "/"), 128)
		}
	}
	inNet := func(a string) bool {
		p, err := netip.ParseAddr(a)
		if err != nil || p.Is4() != ip.Is4() {
			return false
		}
		bits := bits6
		if p.Is4() {
			bits = bits4
		}
		pre, err := p.Prefix(bits)
		return err == nil && pre.Contains(ip)
	}
	switch mech {
	case "all":
		return true, ""
	case "ip4", "ip6":
		if arg == "" {
			return false, "permerror"
		}
		pre, err := netip.ParsePrefix(term[len(mech)+1:])
		if err != nil {
			p, err2 := netip.ParseAddr(arg)
			if err2 != nil {
				return false, "permerror"
			}
			return p.Unmap() == ip, ""
		}
		return pre.Contains(ip), ""
	case "a":
		if !e.lookup() {
			return false, "permerror"
		}
		cctx, cancel := short(e.ctx)
		addrs, _ := e.r.host(cctx, target)
		cancel()
		for _, a := range addrs {
			if inNet(a) {
				return true, ""
			}
		}
		return false, ""
	case "mx":
		if !e.lookup() {
			return false, "permerror"
		}
		cctx, cancel := short(e.ctx)
		mxs, _ := e.r.mx(cctx, target)
		cancel()
		for i, mx := range mxs {
			if i >= 10 {
				return false, "permerror"
			}
			cctx, cancel := short(e.ctx)
			addrs, _ := e.r.host(cctx, strings.TrimSuffix(mx.Host, "."))
			cancel()
			for _, a := range addrs {
				if inNet(a) {
					return true, ""
				}
			}
		}
		return false, ""
	case "include":
		if arg == "" || !e.lookup() {
			return false, "permerror"
		}
		switch e.check(ip, arg, depth+1) {
		case "pass":
			return true, ""
		case "temperror":
			return false, "temperror"
		case "permerror", "none":
			return false, "permerror"
		}
		return false, ""
	case "exists":
		if arg == "" || !e.lookup() {
			return false, "permerror"
		}
		cctx, cancel := short(e.ctx)
		addrs, _ := e.r.host(cctx, arg)
		cancel()
		return len(addrs) > 0, ""
	case "ptr":
		// Discouraged and slow; counted as not matching.
		return false, ""
	}
	return false, "permerror"
}

func atoiDefault(s string, def int) int {
	n := 0
	for _, c := range s {
		if c < '0' || c > '9' {
			return def
		}
		n = n*10 + int(c-'0')
	}
	if s == "" {
		return def
	}
	return n
}
