// Package dns keeps zones in PowerDNS, the server's authoritative DNS
// server, through its HTTP API on localhost. Only the agent holds the API
// key; the panel never reaches PowerDNS itself (ADR-0007).
//
// The panel sends a zone's whole state at once (Apply): every record, and
// what its SOA says. The agent checks each record itself, puts it in
// PowerDNS's form, and changes only what differs, so the same state again
// changes nothing, not even the serial.
//
// Each zone carries its account's name in PowerDNS ("account"; empty for a
// zone of the server's own). A zone is only ever changed or removed for the
// account it belongs to.
package dns

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"lpa/internal/protocol"
)

// Error codes.
const (
	CodeOff         = "dns_off"         // the agent is configured without a DNS server
	CodeUnavailable = "dns_unavailable" // PowerDNS does not answer
	CodeRejected    = "dns_rejected"    // PowerDNS refused the change; nothing changed
	CodeNotOwner    = "dns_not_owner"   // the zone belongs to another account
	CodeNoZone      = "dns_no_zone"     // no zone of the account holds the name
)

var accountRule = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)

// SOA is what a zone's SOA record says, apart from its serial.
type SOA struct {
	// Primary is the zone's first nameserver; Mail the hostmaster's address
	// in DNS form (hostmaster.example.com).
	Primary string `json:"primary"`
	Mail    string `json:"mail"`
	Refresh int    `json:"refresh"`
	Retry   int    `json:"retry"`
	Expire  int    `json:"expire"`
	Minimum int    `json:"minimum"`
}

// ApplyArgs is a zone's whole state.
type ApplyArgs struct {
	// Username is the account the zone belongs to; empty for a zone of the
	// server's own.
	Username string   `json:"username"`
	Zone     string   `json:"zone"`
	SOA      SOA      `json:"soa"`
	Records  []Record `json:"records"`
}

// ApplyResult says what happened.
type ApplyResult struct {
	Zone    string `json:"zone"`
	Created bool   `json:"created"`
	Changed bool   `json:"changed"`
	Serial  uint32 `json:"serial"`
}

// ZoneArgs names a zone.
type ZoneArgs struct {
	Username string `json:"username"`
	Zone     string `json:"zone"`
}

// Manager carries out the DNS operations.
type Manager struct {
	// API is PowerDNS's API, such as http://127.0.0.1:8081; empty turns the
	// DNS operations off.
	API string
	Key string
	// Resolver, when set, is the DNS server ("127.0.0.1:53") the agent's own
	// lookups go to instead of the system's.
	Resolver string
	HTTP     *http.Client
	Owner    func(name string) (uid, gid int, home string, err error)
	Log      *slog.Logger
	Now      func() time.Time

	// LookupTXT, LookupNS and LookupHost are the lookups the checks use;
	// tests replace them.
	LookupTXT  func(ctx context.Context, name string) ([]string, error)
	LookupNS   func(ctx context.Context, name string) ([]string, error)
	LookupHost func(ctx context.Context, name string) ([]string, error)
	LocalIPs   func() []string
	// RetryWait is the pause between lookups in Delegated (a second).
	RetryWait time.Duration

	mu sync.Mutex
	// held are TXT values the agent itself put in a zone for a certificate
	// check, by record name, beside the zone's own values there (base).
	// Apply keeps them while they are held.
	held map[string]*heldSet
}

type heldSet struct {
	base   []string // the zone's own TXT contents at the name
	ttl    int
	values []string // the agent's, quoted
}

// Defaults fills in what main and tests leave empty.
func (m *Manager) Defaults() {
	if m.HTTP == nil {
		m.HTTP = &http.Client{Timeout: 20 * time.Second}
	}
	if m.Log == nil {
		m.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	if m.Now == nil {
		m.Now = time.Now
	}
	r := net.DefaultResolver
	if m.Resolver != "" {
		addr := m.Resolver
		r = &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: 5 * time.Second}).DialContext(ctx, network, addr)
		}}
	}
	if m.LookupTXT == nil {
		m.LookupTXT = r.LookupTXT
	}
	if m.LookupNS == nil {
		m.LookupNS = func(ctx context.Context, name string) ([]string, error) {
			list, err := r.LookupNS(ctx, name)
			var out []string
			for _, ns := range list {
				out = append(out, strings.ToLower(strings.TrimSuffix(ns.Host, ".")))
			}
			sort.Strings(out)
			return out, err
		}
	}
	if m.LookupHost == nil {
		m.LookupHost = r.LookupHost
	}
	if m.LocalIPs == nil {
		m.LocalIPs = localIPs
	}
	m.held = map[string]*heldSet{}
}

// On says whether the agent has a DNS server to configure.
func (m *Manager) On() bool { return m.API != "" }

func (m *Manager) on() error {
	if !m.On() {
		return protocol.Fail(CodeOff, "this server's agent is set up without a DNS server")
	}
	return nil
}

func (m *Manager) account(username string) error {
	if username == "" {
		return nil
	}
	if !accountRule.MatchString(username) {
		return bad("%q is not an account name", username)
	}
	if m.Owner != nil {
		if _, _, _, err := m.Owner(username); err != nil {
			return err
		}
	}
	return nil
}

// Apply brings a zone in step with its state.
func (m *Manager) Apply(ctx context.Context, a ApplyArgs) (ApplyResult, error) {
	res := ApplyResult{Zone: a.Zone}
	if err := m.on(); err != nil {
		return res, err
	}
	if err := CheckZone(a.Zone); err != nil {
		return res, err
	}
	if err := m.account(a.Username); err != nil {
		return res, err
	}
	soa, err := a.SOA.content(a.Zone)
	if err != nil {
		return res, err
	}
	want, err := build(a.Zone, a.Records)
	if err != nil {
		return res, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	// Values held for a certificate check stay until it is over.
	for name, h := range m.held {
		if name == a.Zone+"." || strings.HasSuffix(name, "."+a.Zone+".") {
			key := name + "/TXT"
			set, ok := want[key]
			if !ok {
				set = rrset{Name: name, Type: "TXT", TTL: MinTTL}
			}
			h.base, h.ttl = set.Contents, set.TTL
			set.Contents = merge(set.Contents, h.values)
			want[key] = set
		}
	}

	zone, found, err := m.get(ctx, a.Zone)
	if err != nil {
		return res, err
	}
	if !found {
		serial := nextSerial(0, m.Now())
		body := map[string]any{
			"name": a.Zone + ".", "kind": "Native", "account": a.Username, "nameservers": []string{},
			"soa_edit_api": "", "dnssec": false, "api_rectify": true,
			"rrsets": append(changes(want, nil), soaChange(a.Zone+".", soa, serial)),
		}
		if err := m.call(ctx, http.MethodPost, "/zones", body, nil); err != nil {
			return res, err
		}
		res.Created, res.Changed, res.Serial = true, true, serial
		m.Log.Info("zone created", "zone", a.Zone, "username", a.Username, "records", len(a.Records))
		return res, nil
	}
	if zone.Account != a.Username {
		return res, protocol.Fail(CodeNotOwner, fmt.Sprintf("the zone %s belongs to %s", a.Zone, ownerName(zone.Account)))
	}
	have := map[string]rrset{}
	var haveSOA string
	for _, s := range zone.RRSets {
		set := rrset{Name: strings.ToLower(s.Name), Type: s.Type, TTL: s.TTL}
		for _, r := range s.Records {
			set.Contents = append(set.Contents, r.Content)
		}
		sort.Strings(set.Contents)
		if s.Type == "SOA" {
			if len(set.Contents) == 1 {
				haveSOA = set.Contents[0]
			}
			continue
		}
		have[set.Name+"/"+set.Type] = set
	}
	patch := changes(want, have)
	cur, curRest := splitSOA(haveSOA)
	res.Serial = cur
	if len(patch) == 0 && curRest == soa {
		return res, nil
	}
	res.Serial = nextSerial(cur, m.Now())
	patch = append(patch, soaChange(a.Zone+".", soa, res.Serial))
	if err := m.call(ctx, http.MethodPatch, "/zones/"+zoneID(a.Zone), map[string]any{"rrsets": patch}, nil); err != nil {
		return res, err
	}
	res.Changed = true
	m.Log.Info("zone changed", "zone", a.Zone, "username", a.Username, "rrsets", len(patch)-1, "serial", res.Serial)
	return res, nil
}

// Remove deletes a zone of the account. A zone that is not there is fine.
func (m *Manager) Remove(ctx context.Context, a ZoneArgs) (map[string]bool, error) {
	if err := m.on(); err != nil {
		return nil, err
	}
	if err := CheckZone(a.Zone); err != nil {
		return nil, err
	}
	if a.Username != "" && !accountRule.MatchString(a.Username) {
		return nil, bad("%q is not an account name", a.Username)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	zone, found, err := m.get(ctx, a.Zone)
	if err != nil {
		return nil, err
	}
	if !found {
		return map[string]bool{"removed": false}, nil
	}
	if zone.Account != a.Username {
		return nil, protocol.Fail(CodeNotOwner, fmt.Sprintf("the zone %s belongs to %s", a.Zone, ownerName(zone.Account)))
	}
	if err := m.call(ctx, http.MethodDelete, "/zones/"+zoneID(a.Zone), nil, nil); err != nil {
		return nil, err
	}
	m.Log.Info("zone removed", "zone", a.Zone, "username", a.Username)
	return map[string]bool{"removed": true}, nil
}

// RemoveFor deletes every zone of an account; account.terminate runs it
// before the account's user goes. Without a DNS server it does nothing.
func (m *Manager) RemoveFor(ctx context.Context, username string) error {
	if !m.On() || !accountRule.MatchString(username) {
		return nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	var zones []zoneInfo
	if err := m.call(ctx, http.MethodGet, "/zones", nil, &zones); err != nil {
		return err
	}
	for _, z := range zones {
		if z.Account != username {
			continue
		}
		if err := m.call(ctx, http.MethodDelete, "/zones/"+url.PathEscape(z.ID), nil, nil); err != nil {
			return err
		}
		m.Log.Info("zone removed", "zone", z.Name, "username", username)
	}
	return nil
}

// Info describes the DNS server.
type Info struct {
	Enabled bool   `json:"enabled"`
	Running bool   `json:"running"`
	Version string `json:"version"`
	Zones   int    `json:"zones"`
	Error   string `json:"error,omitempty"`
}

// Info asks PowerDNS about itself.
func (m *Manager) Info(ctx context.Context) Info {
	info := Info{Enabled: m.On()}
	if !info.Enabled {
		return info
	}
	var server struct {
		Version string `json:"version"`
	}
	if err := m.call(ctx, http.MethodGet, "", nil, &server); err != nil {
		info.Error = err.Error()
		return info
	}
	info.Running, info.Version = true, server.Version
	var zones []zoneInfo
	if err := m.call(ctx, http.MethodGet, "/zones", nil, &zones); err == nil {
		info.Zones = len(zones)
	}
	return info
}

// LookupArgs asks what the world's DNS says about some names.
type LookupArgs struct {
	// Hosts are names whose addresses are wanted; Zones are names whose
	// nameservers are.
	Hosts []string `json:"hosts"`
	Zones []string `json:"zones"`
}

// HostAnswer is a name's addresses, and whether one is this server's.
type HostAnswer struct {
	Addresses []string `json:"addresses"`
	Here      bool     `json:"here"`
}

// LookupResult is Lookup's answer.
type LookupResult struct {
	Hosts map[string]HostAnswer `json:"hosts"`
	Zones map[string][]string   `json:"zones"`
	Local []string              `json:"local"`
}

// Lookup resolves names the way any resolver would: whether a nameserver's
// name leads to this server (its glue is in place), and which nameservers a
// domain is delegated to at its registry.
func (m *Manager) Lookup(ctx context.Context, a LookupArgs) (LookupResult, error) {
	res := LookupResult{Hosts: map[string]HostAnswer{}, Zones: map[string][]string{}, Local: m.LocalIPs()}
	if len(a.Hosts)+len(a.Zones) > 40 {
		return res, bad("at most 40 names at once")
	}
	for _, n := range append(append([]string{}, a.Hosts...), a.Zones...) {
		if err := CheckZone(n); err != nil {
			return res, err
		}
	}
	local := map[string]bool{}
	for _, ip := range res.Local {
		local[ip] = true
	}
	var wg sync.WaitGroup
	var mu sync.Mutex
	for _, h := range a.Hosts {
		wg.Add(1)
		go func(h string) {
			defer wg.Done()
			cctx, cancel := context.WithTimeout(ctx, 6*time.Second)
			defer cancel()
			addrs, _ := m.LookupHost(cctx, h)
			ans := HostAnswer{Addresses: []string{}}
			for _, ip := range addrs {
				ans.Addresses = append(ans.Addresses, ip)
				ans.Here = ans.Here || local[ip]
			}
			sort.Strings(ans.Addresses)
			mu.Lock()
			res.Hosts[h] = ans
			mu.Unlock()
		}(h)
	}
	for _, z := range a.Zones {
		wg.Add(1)
		go func(z string) {
			defer wg.Done()
			cctx, cancel := context.WithTimeout(ctx, 6*time.Second)
			defer cancel()
			ns, _ := m.LookupNS(cctx, z)
			if ns == nil {
				ns = []string{}
			}
			mu.Lock()
			res.Zones[z] = ns
			mu.Unlock()
		}(z)
	}
	wg.Wait()
	return res, nil
}

// ---------------------------------------------------------------------------
// Certificate checks (DNS-01)

// Present puts a TXT value at name, in the zone of the account that holds
// it, beside whatever is there, and returns how to take it away again.
func (m *Manager) Present(ctx context.Context, username, name, value string) (func(), error) {
	if err := m.on(); err != nil {
		return nil, err
	}
	if !accountRule.MatchString(username) {
		return nil, bad("%q is not an account name", username)
	}
	if _, ok := checkHost(name); !ok || !plain(value) || value == "" || len(value) > 255 || strings.ContainsAny(value, `"\`) {
		return nil, bad("not a name and value for a check")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	zone, err := m.zoneOf(ctx, username, name)
	if err != nil {
		return nil, err
	}
	fq := name + "."
	h := m.held[fq]
	if h == nil {
		h = &heldSet{ttl: MinTTL}
		for _, set := range zone.RRSets {
			if strings.EqualFold(set.Name, fq) && set.Type == "TXT" {
				h.ttl = set.TTL
				for _, r := range set.Records {
					h.base = append(h.base, r.Content)
				}
			}
		}
		m.held[fq] = h
	}
	quoted := quoteTXT(value)
	h.values = merge(h.values, []string{quoted})
	if err := m.writeHeld(ctx, zone, fq); err != nil {
		m.release(fq, quoted)
		return nil, err
	}
	return func() {
		cctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
		defer cancel()
		m.mu.Lock()
		defer m.mu.Unlock()
		if err := m.writeHeld(cctx, zone, fq, quoted); err != nil {
			m.Log.Warn("could not remove a certificate check's record", "name", name, "err", err)
		}
		m.release(fq, quoted)
	}, nil
}

// Delegated says whether the world's DNS reaches this server for names of
// the account's zone holding name: a record of a random name is put there
// and looked up the way anyone would. The answer is a reason when it is not.
func (m *Manager) Delegated(ctx context.Context, username, name string) (string, error) {
	if !m.On() {
		return "this server has no DNS server, which a wildcard certificate needs", nil
	}
	m.mu.Lock()
	_, err := m.zoneOf(ctx, username, name)
	m.mu.Unlock()
	var p *protocol.Error
	if errors.As(err, &p) && p.Code == CodeNoZone {
		return "its DNS zone is not on this server, which a wildcard certificate needs", nil
	}
	if err != nil {
		return "", err
	}
	buf := make([]byte, 10)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	probe, value := "_lpanel-"+hex.EncodeToString(buf)+"."+name, hex.EncodeToString(buf)
	cleanup, err := m.Present(ctx, username, probe, value)
	if err != nil {
		return "", err
	}
	defer cleanup()
	for try := 0; try < 3; try++ {
		cctx, cancel := context.WithTimeout(ctx, 8*time.Second)
		got, _ := m.LookupTXT(cctx, probe)
		cancel()
		for _, g := range got {
			if g == value {
				return "", nil
			}
		}
		select {
		case <-ctx.Done():
			return "", ctx.Err()
		case <-time.After(m.retryWait()):
		}
	}
	return "its nameservers at the registry are not this server's, so the check for a wildcard certificate cannot be answered here", nil
}

func (m *Manager) retryWait() time.Duration {
	if m.RetryWait > 0 {
		return m.RetryWait
	}
	return time.Second
}

func (m *Manager) release(fq, quoted string) {
	h := m.held[fq]
	if h == nil {
		return
	}
	var left []string
	for _, v := range h.values {
		if v != quoted {
			left = append(left, v)
		}
	}
	h.values = left
	if len(left) == 0 {
		delete(m.held, fq)
	}
}

// writeHeld writes the TXT records at fq: the zone's own and those held
// now, without the ones named in without.
func (m *Manager) writeHeld(ctx context.Context, zone zoneData, fq string, without ...string) error {
	h := m.held[fq]
	if h == nil {
		return nil
	}
	var values []string
	for _, v := range h.values {
		drop := false
		for _, w := range without {
			drop = drop || v == w
		}
		if !drop {
			values = append(values, v)
		}
	}
	contents := merge(h.base, values)
	change := map[string]any{"name": fq, "type": "TXT", "changetype": "DELETE"}
	if len(contents) > 0 {
		recs := make([]map[string]any, len(contents))
		for i, c := range contents {
			recs[i] = map[string]any{"content": c, "disabled": false}
		}
		change = map[string]any{"name": fq, "type": "TXT", "ttl": h.ttl, "changetype": "REPLACE", "records": recs}
	}
	patch := []any{change}
	// The serial moves with every change, as it does in Apply.
	if fresh, found, err := m.get(ctx, strings.TrimSuffix(zone.Name, ".")); err == nil && found {
		if cur, rest := splitSOA(soaOf(fresh)); rest != "" {
			patch = append(patch, soaChange(fresh.Name, rest, nextSerial(cur, m.Now())))
		}
	}
	return m.call(ctx, http.MethodPatch, "/zones/"+url.PathEscape(zone.ID), map[string]any{"rrsets": patch}, nil)
}

// zoneOf finds the account's zone that holds name: the longest one.
func (m *Manager) zoneOf(ctx context.Context, username, name string) (zoneData, error) {
	labels := strings.Split(name, ".")
	for i := 0; i+1 < len(labels); i++ {
		candidate := strings.Join(labels[i:], ".")
		if CheckZone(candidate) != nil {
			continue
		}
		zone, found, err := m.get(ctx, candidate)
		if err != nil {
			return zoneData{}, err
		}
		if found {
			if zone.Account != username {
				return zoneData{}, protocol.Fail(CodeNotOwner, fmt.Sprintf("the zone %s belongs to %s", candidate, ownerName(zone.Account)))
			}
			return zone, nil
		}
	}
	return zoneData{}, protocol.Fail(CodeNoZone, "no zone on this server holds "+name)
}

// ---------------------------------------------------------------------------
// PowerDNS's API

type zoneInfo struct {
	ID      string `json:"id"`
	Name    string `json:"name"`
	Account string `json:"account"`
}

type zoneData struct {
	ID      string `json:"id"`
	Name    string `json:"name"`
	Account string `json:"account"`
	RRSets  []struct {
		Name    string `json:"name"`
		Type    string `json:"type"`
		TTL     int    `json:"ttl"`
		Records []struct {
			Content string `json:"content"`
		} `json:"records"`
	} `json:"rrsets"`
}

func zoneID(zone string) string { return url.PathEscape(zone + ".") }

func ownerName(account string) string {
	if account == "" {
		return "the server"
	}
	return "another account"
}

func (m *Manager) get(ctx context.Context, zone string) (zoneData, bool, error) {
	var data zoneData
	err := m.call(ctx, http.MethodGet, "/zones/"+zoneID(zone), nil, &data)
	var nf notFound
	if errors.As(err, &nf) {
		return data, false, nil
	}
	return data, err == nil, err
}

type notFound struct{}

func (notFound) Error() string { return "not found" }

// call makes one API request. A refusal by PowerDNS is dns_rejected with
// its own words; not reaching it is dns_unavailable.
func (m *Manager) call(ctx context.Context, method, path string, body, into any) error {
	var reader io.Reader
	if body != nil {
		data, err := json.Marshal(body)
		if err != nil {
			return err
		}
		reader = bytes.NewReader(data)
	}
	req, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(m.API, "/")+"/api/v1/servers/localhost"+path, reader)
	if err != nil {
		return err
	}
	req.Header.Set("X-API-Key", m.Key)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := m.HTTP.Do(req)
	if err != nil {
		return protocol.Fail(CodeUnavailable, "PowerDNS does not answer: "+err.Error())
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
	switch {
	case resp.StatusCode >= 200 && resp.StatusCode < 300:
		if into != nil && len(data) > 0 {
			if err := json.Unmarshal(data, into); err != nil {
				return protocol.Fail(CodeUnavailable, "PowerDNS's answer was not understood: "+err.Error())
			}
		}
		return nil
	case resp.StatusCode == http.StatusNotFound,
		resp.StatusCode == http.StatusUnprocessableEntity && method == http.MethodGet && bytes.Contains(data, []byte("Could not find domain")):
		return notFound{}
	case resp.StatusCode == http.StatusUnauthorized:
		return protocol.Fail(CodeUnavailable, "PowerDNS refused the agent's API key")
	}
	var problem struct {
		Error string `json:"error"`
	}
	_ = json.Unmarshal(data, &problem)
	if problem.Error == "" {
		problem.Error = fmt.Sprintf("HTTP %d", resp.StatusCode)
	}
	return protocol.Fail(CodeRejected, "PowerDNS refused it: "+problem.Error)
}

// changes is what must be replaced or deleted to turn have into want.
// Record types the agent does not manage are left alone.
func changes(want, have map[string]rrset) []any {
	managed := map[string]bool{}
	for _, t := range Types {
		managed[t] = true
	}
	keys := make([]string, 0, len(want))
	for k := range want {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	out := []any{}
	for _, k := range keys {
		w := want[k]
		if h, ok := have[k]; ok && h.TTL == w.TTL && strings.Join(h.Contents, "\n") == strings.Join(w.Contents, "\n") {
			continue
		}
		recs := make([]map[string]any, len(w.Contents))
		for i, c := range w.Contents {
			recs[i] = map[string]any{"content": c, "disabled": false}
		}
		out = append(out, map[string]any{"name": w.Name, "type": w.Type, "ttl": w.TTL, "changetype": "REPLACE", "records": recs})
	}
	gone := make([]string, 0)
	for k, h := range have {
		if _, ok := want[k]; !ok && managed[h.Type] {
			gone = append(gone, k)
		}
	}
	sort.Strings(gone)
	for _, k := range gone {
		out = append(out, map[string]any{"name": have[k].Name, "type": have[k].Type, "changetype": "DELETE"})
	}
	return out
}

func merge(a, b []string) []string {
	seen := map[string]bool{}
	out := []string{}
	for _, v := range append(append([]string{}, a...), b...) {
		if !seen[v] {
			seen[v] = true
			out = append(out, v)
		}
	}
	sort.Strings(out)
	return out
}

// ---------------------------------------------------------------------------
// SOA

// content checks the SOA and returns it without a serial: "primary. mail.
// refresh retry expire minimum".
func (s SOA) content(zone string) (string, error) {
	primary, ok := checkHost(s.Primary)
	mail, ok2 := checkHost(s.Mail)
	if !ok || !ok2 {
		return "", bad("the zone's SOA needs a primary nameserver and a hostmaster name")
	}
	for _, v := range []*int{&s.Refresh, &s.Retry, &s.Expire, &s.Minimum} {
		if *v < 0 || *v > 2419200 {
			return "", bad("an SOA timer is 0 to 2419200 seconds")
		}
	}
	def := func(v, d int) int {
		if v == 0 {
			return d
		}
		return v
	}
	return fmt.Sprintf("%s %s %d %d %d %d", primary, mail, def(s.Refresh, 10800), def(s.Retry, 3600), def(s.Expire, 604800), def(s.Minimum, 3600)), nil
}

// splitSOA takes a stored SOA apart: its serial, and the rest in content's
// form.
func splitSOA(stored string) (uint32, string) {
	f := strings.Fields(stored)
	if len(f) != 7 {
		return 0, ""
	}
	serial, _ := strconv.ParseUint(f[2], 10, 32)
	return uint32(serial), strings.ToLower(f[0]) + " " + strings.ToLower(f[1]) + " " + strings.Join(f[3:], " ")
}

func withSerial(rest string, serial uint32) string {
	f := strings.Fields(rest)
	if len(f) != 6 {
		return rest
	}
	return fmt.Sprintf("%s %s %d %s", f[0], f[1], serial, strings.Join(f[2:], " "))
}

func soaChange(fq, rest string, serial uint32) map[string]any {
	return map[string]any{
		"name": fq, "type": "SOA", "ttl": 3600, "changetype": "REPLACE",
		"records": []map[string]any{{"content": withSerial(rest, serial), "disabled": false}},
	}
}

func soaOf(z zoneData) string {
	for _, s := range z.RRSets {
		if s.Type == "SOA" && len(s.Records) == 1 {
			return s.Records[0].Content
		}
	}
	return ""
}

// nextSerial is a serial after cur: today's date and a two-digit count
// (2026111501), or one more than cur when that would not be higher.
func nextSerial(cur uint32, now time.Time) uint32 {
	y, mo, d := now.UTC().Date()
	today := uint32(y*1000000+int(mo)*10000+d*100) + 1
	if today > cur {
		return today
	}
	return cur + 1
}

func localIPs() []string {
	var out []string
	addrs, _ := net.InterfaceAddrs()
	for _, a := range addrs {
		if n, ok := a.(*net.IPNet); ok {
			out = append(out, n.IP.String())
		}
	}
	return out
}
