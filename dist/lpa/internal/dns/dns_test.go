package dns

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"sort"
	"strings"
	"testing"
	"time"

	"lpa/internal/protocol"
)

// fakePDNS is PowerDNS's API over a map: enough to create, read, patch,
// list and delete zones.
type fakePDNS struct {
	t      *testing.T
	zones  map[string]*fakeZone // "example.test." →
	writes []string             // "PATCH example.test." …
	reject string               // a PATCH or POST naming this is refused
}

type fakeZone struct {
	account string
	sets    map[string]fakeSet // "www.example.test./A" →
}

type fakeSet struct {
	ttl      int
	contents []string
}

func (f *fakePDNS) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Header.Get("X-API-Key") != "secret" {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	path := strings.TrimPrefix(r.URL.Path, "/api/v1/servers/localhost")
	body, _ := io.ReadAll(r.Body)
	if f.reject != "" && strings.Contains(string(body), f.reject) {
		w.WriteHeader(http.StatusUnprocessableEntity)
		_, _ = w.Write([]byte(`{"error": "Record ` + f.reject + ` is not valid"}`))
		return
	}
	reply := func(v any) { _ = json.NewEncoder(w).Encode(v) }
	apply := func(z *fakeZone, sets []map[string]any) {
		for _, s := range sets {
			key := s["name"].(string) + "/" + s["type"].(string)
			if s["changetype"] == "DELETE" {
				delete(z.sets, key)
				continue
			}
			var contents []string
			for _, rec := range s["records"].([]any) {
				contents = append(contents, rec.(map[string]any)["content"].(string))
			}
			z.sets[key] = fakeSet{ttl: int(s["ttl"].(float64)), contents: contents}
		}
	}
	switch {
	case path == "" && r.Method == http.MethodGet:
		reply(map[string]any{"version": "4.9.4", "daemon_type": "authoritative"})
	case path == "/zones" && r.Method == http.MethodGet:
		list := []map[string]any{}
		for name, z := range f.zones {
			list = append(list, map[string]any{"id": name, "name": name, "account": z.account})
		}
		reply(list)
	case path == "/zones" && r.Method == http.MethodPost:
		var doc struct {
			Name    string           `json:"name"`
			Account string           `json:"account"`
			Kind    string           `json:"kind"`
			RRSets  []map[string]any `json:"rrsets"`
		}
		_ = json.Unmarshal(body, &doc)
		z := &fakeZone{account: doc.Account, sets: map[string]fakeSet{}}
		apply(z, doc.RRSets)
		f.zones[doc.Name] = z
		f.writes = append(f.writes, "POST "+doc.Name+" "+doc.Kind)
		w.WriteHeader(http.StatusCreated)
		reply(map[string]any{"name": doc.Name})
	case strings.HasPrefix(path, "/zones/"):
		name, _ := url.PathUnescape(strings.TrimPrefix(path, "/zones/"))
		z := f.zones[name]
		if z == nil {
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte(`{"error": "Not Found"}`))
			return
		}
		switch r.Method {
		case http.MethodGet:
			sets := []map[string]any{}
			for key, s := range z.sets {
				i := strings.LastIndex(key, "/")
				recs := []map[string]any{}
				for _, c := range s.contents {
					recs = append(recs, map[string]any{"content": c, "disabled": false})
				}
				sets = append(sets, map[string]any{"name": key[:i], "type": key[i+1:], "ttl": s.ttl, "records": recs})
			}
			reply(map[string]any{"id": name, "name": name, "account": z.account, "rrsets": sets})
		case http.MethodPatch:
			var doc struct {
				RRSets []map[string]any `json:"rrsets"`
			}
			_ = json.Unmarshal(body, &doc)
			apply(z, doc.RRSets)
			f.writes = append(f.writes, "PATCH "+name)
			w.WriteHeader(http.StatusNoContent)
		case http.MethodDelete:
			delete(f.zones, name)
			f.writes = append(f.writes, "DELETE "+name)
			w.WriteHeader(http.StatusNoContent)
		}
	default:
		w.WriteHeader(http.StatusNotFound)
	}
}

func (f *fakePDNS) get(zone, key string) string {
	z := f.zones[zone+"."]
	if z == nil {
		return "<no zone>"
	}
	c := append([]string{}, z.sets[key].contents...)
	sort.Strings(c)
	return strings.Join(c, " | ")
}

var day = time.Date(2026, 11, 15, 10, 0, 0, 0, time.UTC)

func setup(t *testing.T) (*Manager, *fakePDNS) {
	t.Helper()
	f := &fakePDNS{t: t, zones: map[string]*fakeZone{}}
	srv := httptest.NewServer(f)
	t.Cleanup(srv.Close)
	m := &Manager{
		API: srv.URL, Key: "secret", Now: func() time.Time { return day }, RetryWait: time.Millisecond,
		Owner: func(name string) (int, int, string, error) {
			if name == "ghost" {
				return 0, 0, "", protocol.Fail("not_ours", "no such account")
			}
			return 1000, 1000, "/home/" + name, nil
		},
		// The world's DNS reaches this server: lookups read the fake itself.
		LookupTXT: func(_ context.Context, name string) ([]string, error) {
			var out []string
			for _, z := range f.zones {
				for _, c := range z.sets[name+"./TXT"].contents {
					out = append(out, strings.Trim(c, `"`))
				}
			}
			return out, nil
		},
		LookupHost: func(_ context.Context, name string) ([]string, error) {
			return map[string][]string{"ns1.host.test": {"192.0.2.10"}, "ns1.away.test": {"198.51.100.1"}}[name], nil
		},
		LookupNS: func(_ context.Context, name string) ([]string, error) {
			return map[string][]string{"shop.test": {"ns1.host.test", "ns2.host.test"}}[name], nil
		},
		LocalIPs: func() []string { return []string{"127.0.0.1", "192.0.2.10"} },
	}
	m.Defaults()
	return m, f
}

func soa() SOA { return SOA{Primary: "ns1.host.test", Mail: "hostmaster.shop.test"} }

func base() []Record {
	return []Record{
		{Name: "shop.test", Type: "NS", TTL: 3600, Content: "ns1.host.test"},
		{Name: "shop.test", Type: "NS", TTL: 3600, Content: "ns2.host.test."},
		{Name: "shop.test", Type: "A", TTL: 300, Content: "192.0.2.10"},
		{Name: "www.shop.test", Type: "CNAME", TTL: 300, Content: "shop.test"},
		{Name: "shop.test", Type: "AAAA", TTL: 300, Content: "2001:0DB8:0000:0000:0000:0000:0000:0010"},
		{Name: "shop.test", Type: "MX", TTL: 300, Content: "10 mail.shop.test"},
		{Name: "shop.test", Type: "TXT", TTL: 300, Content: `v=spf1 a mx ~all`},
		{Name: "_sip._tcp.shop.test", Type: "SRV", TTL: 300, Content: "10 5 5060 sip.shop.test"},
		{Name: "shop.test", Type: "CAA", TTL: 300, Content: `0 issue "letsencrypt.org"`},
		{Name: "*.shop.test", Type: "A", TTL: 300, Content: "192.0.2.10"},
	}
}

func code(err error) string {
	var p *protocol.Error
	if errors.As(err, &p) {
		return p.Code
	}
	if err != nil {
		return err.Error()
	}
	return ""
}

func TestApplyCreatesTheZoneInPowerDNSsForm(t *testing.T) {
	m, f := setup(t)
	res, err := m.Apply(context.Background(), ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: base()})
	if err != nil || !res.Created || !res.Changed || res.Serial != 2026111501 {
		t.Fatalf("res=%+v err=%v", res, err)
	}
	if f.zones["shop.test."].account != "shop1" || f.writes[0] != "POST shop.test. Native" {
		t.Fatalf("zone: %+v, writes: %v", f.zones["shop.test."], f.writes)
	}
	for key, want := range map[string]string{
		"shop.test./NS":            "ns1.host.test. | ns2.host.test.",
		"shop.test./A":             "192.0.2.10",
		"shop.test./AAAA":          "2001:db8::10",
		"www.shop.test./CNAME":     "shop.test.",
		"shop.test./MX":            "10 mail.shop.test.",
		"shop.test./TXT":           `"v=spf1 a mx ~all"`,
		"_sip._tcp.shop.test./SRV": "10 5 5060 sip.shop.test.",
		"shop.test./CAA":           `0 issue "letsencrypt.org"`,
		"*.shop.test./A":           "192.0.2.10",
		"shop.test./SOA":           "ns1.host.test. hostmaster.shop.test. 2026111501 10800 3600 604800 3600",
	} {
		if got := f.get("shop.test", key); got != want {
			t.Errorf("%s = %q, want %q", key, got, want)
		}
	}
}

func TestApplyTheSameStateAgainChangesNothing(t *testing.T) {
	m, f := setup(t)
	a := ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: base()}
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	res, err := m.Apply(context.Background(), a)
	if err != nil || res.Changed || res.Serial != 2026111501 || len(f.writes) != 1 {
		t.Fatalf("res=%+v err=%v writes=%v", res, err, f.writes)
	}
}

func TestApplyChangesOnlyWhatDiffersAndRaisesTheSerial(t *testing.T) {
	m, f := setup(t)
	a := ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: base()}
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	// A record type the agent does not manage is someone else's to keep.
	f.zones["shop.test."].sets["_443._tcp.shop.test./TLSA"] = fakeSet{ttl: 300, contents: []string{"3 1 1 abcd"}}

	a.Records = append(base()[:3], Record{Name: "blog.shop.test", Type: "A", TTL: 600, Content: "192.0.2.11"})
	res, err := m.Apply(context.Background(), a)
	if err != nil || !res.Changed || res.Created || res.Serial != 2026111502 {
		t.Fatalf("res=%+v err=%v", res, err)
	}
	if got := f.get("shop.test", "blog.shop.test./A"); got != "192.0.2.11" {
		t.Errorf("the new record: %q", got)
	}
	for _, gone := range []string{"www.shop.test./CNAME", "shop.test./MX", "shop.test./TXT", "*.shop.test./A"} {
		if _, ok := f.zones["shop.test."].sets[gone]; ok {
			t.Errorf("%s is still there", gone)
		}
	}
	if got := f.get("shop.test", "_443._tcp.shop.test./TLSA"); got != "3 1 1 abcd" {
		t.Errorf("the TLSA record was touched: %q", got)
	}
	// The next day's first change starts that day's serials.
	m.Now = func() time.Time { return day.Add(24 * time.Hour) }
	a.Records = base()
	if res, _ := m.Apply(context.Background(), a); res.Serial != 2026111601 {
		t.Errorf("serial = %d", res.Serial)
	}
	// A new SOA alone is a change too.
	a.SOA.Primary = "ns9.host.test"
	if res, _ := m.Apply(context.Background(), a); !res.Changed || !strings.HasPrefix(f.get("shop.test", "shop.test./SOA"), "ns9.host.test. hostmaster.shop.test. 2026111602 ") {
		t.Errorf("soa = %s", f.get("shop.test", "shop.test./SOA"))
	}
}

func TestTextCannotBreakOutOfItsRecord(t *testing.T) {
	m, f := setup(t)
	long := strings.Repeat("k", 300)
	recs := append(base()[:2],
		Record{Name: "a.shop.test", Type: "TXT", TTL: 300, Content: `evil" "v=spf1 +all`},
		Record{Name: "b.shop.test", Type: "TXT", TTL: 300, Content: `back\slash and tab	and é`},
		Record{Name: "c.shop.test", Type: "TXT", TTL: 300, Content: long},
	)
	if _, err := m.Apply(context.Background(), ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: recs}); err != nil {
		t.Fatal(err)
	}
	if got := f.get("shop.test", "a.shop.test./TXT"); got != `"evil\" \"v=spf1 +all"` {
		t.Errorf("a = %s", got)
	}
	if got := f.get("shop.test", "b.shop.test./TXT"); got != `"back\\slash and tab\009and \195\169"` {
		t.Errorf("b = %s", got)
	}
	if got := f.get("shop.test", "c.shop.test./TXT"); got != `"`+strings.Repeat("k", 255)+`" "`+strings.Repeat("k", 45)+`"` {
		t.Errorf("c = %s", got)
	}
}

func TestApplyRefusesWhatAZoneMayNotHold(t *testing.T) {
	ns := base()[:2]
	cases := map[string][]Record{
		"no nameserver":        {{Name: "shop.test", Type: "A", TTL: 300, Content: "192.0.2.1"}},
		"another zone's name":  append(ns, Record{Name: "www.other.test", Type: "A", TTL: 300, Content: "192.0.2.1"}),
		"a look-alike suffix":  append(ns, Record{Name: "evilshop.test", Type: "A", TTL: 300, Content: "192.0.2.1"}),
		"SOA from the panel":   append(ns, Record{Name: "shop.test", Type: "SOA", TTL: 300, Content: "a. b. 1 1 1 1 1"}),
		"an unknown type":      append(ns, Record{Name: "shop.test", Type: "LUA", TTL: 300, Content: `A "ifportup(443, {'192.0.2.1'})"`}),
		"not an address":       append(ns, Record{Name: "shop.test", Type: "A", TTL: 300, Content: "192.0.2.999"}),
		"v6 as A":              append(ns, Record{Name: "shop.test", Type: "A", TTL: 300, Content: "::1"}),
		"v4 as AAAA":           append(ns, Record{Name: "shop.test", Type: "AAAA", TTL: 300, Content: "192.0.2.1"}),
		"CNAME at the top":     append(ns, Record{Name: "shop.test", Type: "CNAME", TTL: 300, Content: "other.test"}),
		"CNAME beside others":  append(ns, Record{Name: "w.shop.test", Type: "CNAME", TTL: 300, Content: "other.test"}, Record{Name: "w.shop.test", Type: "A", TTL: 300, Content: "192.0.2.1"}),
		"two CNAMEs":           append(ns, Record{Name: "w.shop.test", Type: "CNAME", TTL: 300, Content: "a.test"}, Record{Name: "w.shop.test", Type: "CNAME", TTL: 300, Content: "b.test"}),
		"two names in a CNAME": append(ns, Record{Name: "w.shop.test", Type: "CNAME", TTL: 300, Content: "a.test b.test"}),
		"MX without priority":  append(ns, Record{Name: "shop.test", Type: "MX", TTL: 300, Content: "mail.shop.test"}),
		"SRV port too high":    append(ns, Record{Name: "_x._tcp.shop.test", Type: "SRV", TTL: 300, Content: "1 1 70000 a.test"}),
		"CAA with a quote":     append(ns, Record{Name: "shop.test", Type: "CAA", TTL: 300, Content: `0 issue "a" 0 issue "b"`}),
		"a TTL too short":      append(ns, Record{Name: "shop.test", Type: "A", TTL: 5, Content: "192.0.2.1"}),
		"a star in the middle": append(ns, Record{Name: "a.*.shop.test", Type: "A", TTL: 300, Content: "192.0.2.1"}),
		"upper case":           append(ns, Record{Name: "WWW.shop.test", Type: "A", TTL: 300, Content: "192.0.2.1"}),
		"an empty TXT":         append(ns, Record{Name: "shop.test", Type: "TXT", TTL: 300, Content: " "}),
		"wildcard nameservers": append(ns, Record{Name: "*.shop.test", Type: "NS", TTL: 300, Content: "ns.evil.test"}),
	}
	for name, recs := range cases {
		m, f := setup(t)
		_, err := m.Apply(context.Background(), ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: recs})
		if code(err) != protocol.CodeBadArgs {
			t.Errorf("%s: err = %v", name, err)
		}
		if len(f.writes) > 0 {
			t.Errorf("%s: PowerDNS was written to: %v", name, f.writes)
		}
	}
	m, _ := setup(t)
	for name, a := range map[string]ApplyArgs{
		"not a zone":     {Username: "shop1", Zone: "localhost", SOA: soa(), Records: ns},
		"not an account": {Username: "ghost", Zone: "shop.test", SOA: soa(), Records: ns},
		"a bad account":  {Username: "Shop1", Zone: "shop.test", SOA: soa(), Records: ns},
		"no SOA":         {Username: "shop1", Zone: "shop.test", Records: ns},
	} {
		if _, err := m.Apply(context.Background(), a); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestAZoneIsOnlyItsOwnAccounts(t *testing.T) {
	m, f := setup(t)
	a := ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: base()}
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	other := a
	other.Username = "shop2"
	if _, err := m.Apply(context.Background(), other); code(err) != CodeNotOwner {
		t.Errorf("another account's apply: %v", err)
	}
	other.Username = ""
	if _, err := m.Apply(context.Background(), other); code(err) != CodeNotOwner {
		t.Errorf("the server's apply: %v", err)
	}
	if _, err := m.Remove(context.Background(), ZoneArgs{Username: "shop2", Zone: "shop.test"}); code(err) != CodeNotOwner {
		t.Errorf("another account's remove: %v", err)
	}
	if len(f.writes) != 1 {
		t.Fatalf("writes: %v", f.writes)
	}
	res, err := m.Remove(context.Background(), ZoneArgs{Username: "shop1", Zone: "shop.test"})
	if err != nil || !res["removed"] || len(f.zones) != 0 {
		t.Fatalf("res=%v err=%v", res, err)
	}
	if res, err := m.Remove(context.Background(), ZoneArgs{Username: "shop1", Zone: "shop.test"}); err != nil || res["removed"] {
		t.Fatalf("a zone that is gone: res=%v err=%v", res, err)
	}
}

func TestRemoveForTakesOnlyTheAccountsZones(t *testing.T) {
	m, f := setup(t)
	for zone, user := range map[string]string{"shop.test": "shop1", "more.test": "shop1", "other.test": "shop2", "host.test": ""} {
		recs := []Record{{Name: zone, Type: "NS", TTL: 3600, Content: "ns1.host.test"}}
		if _, err := m.Apply(context.Background(), ApplyArgs{Username: user, Zone: zone, SOA: soa(), Records: recs}); err != nil {
			t.Fatal(err)
		}
	}
	if err := m.RemoveFor(context.Background(), "shop1"); err != nil {
		t.Fatal(err)
	}
	if len(f.zones) != 2 || f.zones["other.test."] == nil || f.zones["host.test."] == nil {
		t.Fatalf("zones left: %v", f.zones)
	}
}

func TestPowerDNSRefusingIsReportedInItsWords(t *testing.T) {
	m, f := setup(t)
	f.reject = "192.0.2.10"
	_, err := m.Apply(context.Background(), ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: base()})
	if code(err) != CodeRejected || !strings.Contains(err.Error(), "is not valid") {
		t.Fatalf("err = %v", err)
	}
	m.Key = "wrong"
	if _, err := m.Apply(context.Background(), ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: base()}); code(err) != CodeUnavailable {
		t.Fatalf("a wrong key: %v", err)
	}
}

func TestWithoutADNSServer(t *testing.T) {
	m := &Manager{}
	m.Defaults()
	if _, err := m.Apply(context.Background(), ApplyArgs{Username: "shop1", Zone: "shop.test"}); code(err) != CodeOff {
		t.Errorf("apply: %v", err)
	}
	if err := m.RemoveFor(context.Background(), "shop1"); err != nil {
		t.Errorf("terminating an account must still work: %v", err)
	}
	if info := m.Info(context.Background()); info.Enabled {
		t.Errorf("info = %+v", info)
	}
}

func TestACheckValueSitsBesideTheOwnersRecordsAndGoesAgain(t *testing.T) {
	m, f := setup(t)
	recs := append(base(), Record{Name: "_acme-challenge.shop.test", Type: "TXT", TTL: 300, Content: "the-owners-own-value"})
	a := ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: recs}
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Present(context.Background(), "shop2", "_acme-challenge.shop.test", "x"); code(err) != CodeNotOwner {
		t.Fatalf("another account's check in this zone: %v", err)
	}
	if _, err := m.Present(context.Background(), "shop1", "_acme-challenge.nowhere.test", "x"); code(err) != CodeNoZone {
		t.Fatalf("a name no zone holds: %v", err)
	}
	cleanup, err := m.Present(context.Background(), "shop1", "_acme-challenge.shop.test", "digest-1")
	if err != nil {
		t.Fatal(err)
	}
	if got := f.get("shop.test", "_acme-challenge.shop.test./TXT"); got != `"digest-1" | "the-owners-own-value"` {
		t.Fatalf("while held: %s", got)
	}
	if !strings.Contains(f.get("shop.test", "shop.test./SOA"), " 2026111502 ") {
		t.Errorf("the serial did not move: %s", f.get("shop.test", "shop.test./SOA"))
	}
	// The panel changes the zone meanwhile: the held value stays.
	a.Records = append(a.Records, Record{Name: "new.shop.test", Type: "A", TTL: 300, Content: "192.0.2.12"})
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if got := f.get("shop.test", "_acme-challenge.shop.test./TXT"); got != `"digest-1" | "the-owners-own-value"` {
		t.Fatalf("after an apply: %s", got)
	}
	cleanup()
	if got := f.get("shop.test", "_acme-challenge.shop.test./TXT"); got != `"the-owners-own-value"` {
		t.Fatalf("after the check: %s", got)
	}
	// With nothing of the owner's there, the record goes entirely.
	cleanup, _ = m.Present(context.Background(), "shop1", "_acme-challenge.www.shop.test", "digest-2")
	cleanup()
	if _, ok := f.zones["shop.test."].sets["_acme-challenge.www.shop.test./TXT"]; ok {
		t.Fatal("the check's record is still there")
	}
}

func TestDelegatedLooksItsOwnRecordUpTheWayAnyoneWould(t *testing.T) {
	m, f := setup(t)
	if _, err := m.Apply(context.Background(), ApplyArgs{Username: "shop1", Zone: "shop.test", SOA: soa(), Records: base()}); err != nil {
		t.Fatal(err)
	}
	why, err := m.Delegated(context.Background(), "shop1", "shop.test")
	if err != nil || why != "" {
		t.Fatalf("why=%q err=%v", why, err)
	}
	for key := range f.zones["shop.test."].sets {
		if strings.HasPrefix(key, "_lpanel-") {
			t.Errorf("the probe's record was left behind: %s", key)
		}
	}
	// The registry points elsewhere: nobody finds the record.
	m.LookupTXT = func(context.Context, string) ([]string, error) { return nil, errors.New("NXDOMAIN") }
	if why, err := m.Delegated(context.Background(), "shop1", "shop.test"); err != nil || !strings.Contains(why, "registry") {
		t.Fatalf("why=%q err=%v", why, err)
	}
	if why, _ := m.Delegated(context.Background(), "shop1", "elsewhere.test"); !strings.Contains(why, "not on this server") {
		t.Fatalf("no zone: %q", why)
	}
}

func TestLookupSaysWhereNamesLead(t *testing.T) {
	m, _ := setup(t)
	res, err := m.Lookup(context.Background(), LookupArgs{Hosts: []string{"ns1.host.test", "ns1.away.test", "ns1.none.test"}, Zones: []string{"shop.test", "none.test"}})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Hosts["ns1.host.test"].Here || res.Hosts["ns1.away.test"].Here || len(res.Hosts["ns1.none.test"].Addresses) != 0 {
		t.Errorf("hosts = %+v", res.Hosts)
	}
	if strings.Join(res.Zones["shop.test"], ",") != "ns1.host.test,ns2.host.test" || len(res.Zones["none.test"]) != 0 {
		t.Errorf("zones = %+v", res.Zones)
	}
	if _, err := m.Lookup(context.Background(), LookupArgs{Hosts: []string{"bad host"}}); code(err) != protocol.CodeBadArgs {
		t.Errorf("a bad name: %v", err)
	}
}
