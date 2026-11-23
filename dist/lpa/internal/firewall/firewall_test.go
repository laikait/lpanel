package firewall

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"testing"
	"time"

	"lpa/internal/protocol"
)

// fakeFirewalld keeps what firewall-cmd would: per zone, the ports and the
// rich rules.
type fakeFirewalld struct {
	running bool
	ports   map[string]map[string]bool
	rich    map[string]map[string]bool
	reloads int
	calls   []string
	// services are the zone's services, and what each opens.
	services map[string][]string
	enabled  map[string]bool
}

func newFake() *fakeFirewalld {
	return &fakeFirewalld{
		running:  true,
		ports:    map[string]map[string]bool{"public": {"7443/tcp": true, "80/tcp": true}},
		rich:     map[string]map[string]bool{"public": {}},
		services: map[string][]string{"ssh": {"22/tcp"}, "http": {"80/tcp"}, "https": {"443/tcp"}, "ftp": {"21/tcp"}, "dhcpv6-client": {"546/udp"}},
		enabled:  map[string]bool{"ssh": true, "https": true, "dhcpv6-client": true},
	}
}

func (f *fakeFirewalld) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	f.calls = append(f.calls, tool+" "+strings.Join(args, " "))
	if tool != "firewall-cmd" {
		return 127, "", errors.New("not firewall-cmd")
	}
	zone := "public"
	var op, svc string
	for _, a := range args {
		switch {
		case strings.HasPrefix(a, "--zone="):
			zone = strings.TrimPrefix(a, "--zone=")
		case a == "--permanent":
		case strings.HasPrefix(a, "--service="):
			svc = strings.TrimPrefix(a, "--service=")
		default:
			op = a
		}
	}
	if f.ports[zone] == nil {
		f.ports[zone] = map[string]bool{}
	}
	if f.rich[zone] == nil {
		f.rich[zone] = map[string]bool{}
	}
	keys := func(m map[string]bool) string {
		var k []string
		for s := range m {
			k = append(k, s)
		}
		sort.Strings(k)
		return strings.Join(k, "\n")
	}
	switch {
	case op == "--state":
		if f.running {
			return 0, "running\n", nil
		}
		return 252, "not running\n", nil
	case op == "--version":
		return 0, "1.3.4\n", nil
	case op == "--get-default-zone":
		return 0, "public\n", nil
	case op == "--get-active-zones":
		return 0, "public\n  interfaces: eth0\n", nil
	case op == "--list-services":
		var names []string
		for n := range f.services {
			if f.enabled[n] {
				names = append(names, n)
			}
		}
		sort.Strings(names)
		return 0, strings.Join(names, " ") + "\n", nil
	case op == "--get-ports" && svc != "":
		return 0, strings.Join(f.services[svc], " ") + "\n", nil
	case strings.HasPrefix(op, "--remove-service="):
		delete(f.enabled, strings.TrimPrefix(op, "--remove-service="))
		return 0, "success\n", nil
	case op == "--list-ports":
		return 0, strings.ReplaceAll(keys(f.ports[zone]), "\n", " ") + "\n", nil
	case op == "--list-rich-rules":
		return 0, keys(f.rich[zone]) + "\n", nil
	case op == "--reload":
		f.reloads++
		return 0, "success\n", nil
	case strings.HasPrefix(op, "--add-port="):
		f.ports[zone][strings.TrimPrefix(op, "--add-port=")] = true
		return 0, "success\n", nil
	case strings.HasPrefix(op, "--remove-port="):
		delete(f.ports[zone], strings.TrimPrefix(op, "--remove-port="))
		return 0, "success\n", nil
	case strings.HasPrefix(op, "--add-rich-rule="):
		f.rich[zone][strings.TrimPrefix(op, "--add-rich-rule=")] = true
		return 0, "success\n", nil
	case strings.HasPrefix(op, "--remove-rich-rule="):
		delete(f.rich[zone], strings.TrimPrefix(op, "--remove-rich-rule="))
		return 0, "success\n", nil
	case strings.HasPrefix(op, "--query-rich-rule="):
		if f.rich[zone][strings.TrimPrefix(op, "--query-rich-rule=")] {
			return 0, "yes\n", nil
		}
		return 1, "no\n", nil
	}
	return 2, "unknown " + op, nil
}

type fixture struct {
	m       *Manager
	f       *fakeFirewalld
	fetched []string
}

func newFixture(t *testing.T) *fixture {
	t.Helper()
	fx := &fixture{f: newFake()}
	dir := t.TempDir()
	fx.m = &Manager{
		Enabled: true, Run: fx.f, Dir: filepath.Join(dir, "state"), IPSets: filepath.Join(dir, "ipsets"),
		Now: func() time.Time { return time.Date(2026, 11, 23, 0, 0, 0, 0, time.UTC) },
		Fetch: func(_ context.Context, url string) ([]byte, error) {
			fx.fetched = append(fx.fetched, url)
			switch {
			case strings.Contains(url, "bad"):
				return []byte("<html>not a list</html>"), nil
			case strings.Contains(url, "zz"):
				return nil, errors.New("the server said 404")
			case strings.Contains(url, "ipv6"):
				return []byte("2001:db8::/32\n2001:db9::/32\n"), nil
			}
			return []byte("203.0.113.0/24\n198.51.100.0/24\n"), nil
		},
	}
	fx.m.Defaults()
	return fx
}

func code(err error) string {
	var e *protocol.Error
	if errors.As(err, &e) {
		return e.Code
	}
	return ""
}

func TestInfoReadsPortsAndRules(t *testing.T) {
	fx := newFixture(t)
	fx.f.rich["public"][`rule family="ipv4" source address="203.0.113.7" port port="3306" protocol="tcp" accept`] = true

	info := fx.m.Info(context.Background())
	if !info.Running || info.Zone != "public" || info.Version != "1.3.4" {
		t.Fatalf("info = %+v", info)
	}
	got := map[string]PortRule{}
	for _, p := range info.Ports {
		got[p.Port+"/"+p.Proto] = p
	}
	if len(got["22/tcp"].Sources) != 0 || got["22/tcp"].Via != "ssh" || got["3306/tcp"].Sources[0] != "203.0.113.7" || got["7443/tcp"].Via != "" {
		t.Fatalf("ports = %+v", info.Ports)
	}
	if info.Rules {
		t.Fatal("rules reported before they exist")
	}
}

func TestInfoWhenNotRunning(t *testing.T) {
	fx := newFixture(t)
	fx.f.running = false
	info := fx.m.Info(context.Background())
	if info.Running || len(info.Ports) != 0 {
		t.Fatalf("info = %+v", info)
	}
	fx.m.Enabled = false
	if _, err := fx.m.Port(context.Background(), PortArgs{Action: "open", Port: "8080", Proto: "tcp"}); code(err) != CodeOff {
		t.Fatalf("port with firewalld off: %v", err)
	}
}

func TestPortOpenLimitClose(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()

	if _, err := fx.m.Port(ctx, PortArgs{Action: "open", Port: "8080", Proto: "tcp"}); err != nil {
		t.Fatal(err)
	}
	if !fx.f.ports["public"]["8080/tcp"] {
		t.Fatal("8080 not opened")
	}

	// Limited to two addresses: the plain port goes, a rule each comes.
	r, err := fx.m.Port(ctx, PortArgs{Action: "open", Port: "8080", Proto: "tcp", Sources: []string{"203.0.113.7", "2001:db8::/48", "203.0.113.7"}})
	if err != nil {
		t.Fatal(err)
	}
	if fx.f.ports["public"]["8080/tcp"] || len(r.Sources) != 2 {
		t.Fatalf("ports %v, result %+v", fx.f.ports["public"], r)
	}
	if !fx.f.rich["public"][`rule family="ipv4" source address="203.0.113.7" port port="8080" protocol="tcp" accept`] ||
		!fx.f.rich["public"][`rule family="ipv6" source address="2001:db8::/48" port port="8080" protocol="tcp" accept`] {
		t.Fatalf("rich rules = %v", fx.f.rich["public"])
	}

	// Back to everyone: the rules go.
	if _, err := fx.m.Port(ctx, PortArgs{Action: "open", Port: "8080", Proto: "tcp"}); err != nil {
		t.Fatal(err)
	}
	if len(fx.f.rich["public"]) != 0 || !fx.f.ports["public"]["8080/tcp"] {
		t.Fatalf("after reopening: %v %v", fx.f.ports["public"], fx.f.rich["public"])
	}

	if _, err := fx.m.Port(ctx, PortArgs{Action: "close", Port: "8080", Proto: "tcp"}); err != nil {
		t.Fatal(err)
	}
	if fx.f.ports["public"]["8080/tcp"] || fx.f.reloads == 0 {
		t.Fatalf("not closed: %v (reloads %d)", fx.f.ports["public"], fx.f.reloads)
	}
}

func TestKeptPortsAreNeverClosedButCanBeLimited(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()

	for _, port := range []string{"22", "7443", "7000-7500"} {
		if _, err := fx.m.Port(ctx, PortArgs{Action: "close", Port: port, Proto: "tcp"}); code(err) != CodeKept {
			t.Errorf("closing %s: %v", port, err)
		}
	}
	if !fx.f.enabled["ssh"] {
		t.Fatal("ssh was touched")
	}
	if _, err := fx.m.Port(ctx, PortArgs{Action: "open", Port: "7443", Proto: "tcp", Sources: []string{"203.0.113.7"}}); err != nil {
		t.Fatalf("limiting 7443: %v", err)
	}
	if fx.f.ports["public"]["7443/tcp"] {
		t.Fatal("7443 is still open to everyone")
	}
}

func TestPortArgumentsAreChecked(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()
	for _, a := range []PortArgs{
		{Action: "open", Port: "0", Proto: "tcp"},
		{Action: "open", Port: "70000", Proto: "tcp"},
		{Action: "open", Port: "80; reboot", Proto: "tcp"},
		{Action: "open", Port: "500-100", Proto: "tcp"},
		{Action: "open", Port: "80", Proto: "icmp"},
		{Action: "flush", Port: "80", Proto: "tcp"},
		{Action: "open", Port: "80", Proto: "tcp", Sources: []string{"not an address"}},
		{Action: "open", Port: "80", Proto: "tcp", Sources: []string{"0.0.0.0/0"}},
		{Action: "open", Port: "80", Proto: "tcp", Sources: []string{`1.2.3.4" accept rule x="`}},
	} {
		if _, err := fx.m.Port(ctx, a); code(err) != protocol.CodeBadArgs {
			t.Errorf("%+v: %v", a, err)
		}
	}
	if len(fx.f.calls) > 0 {
		t.Fatalf("firewall-cmd ran for bad arguments: %v", fx.f.calls)
	}
}

func TestListsWriteSetsAndRulesOnce(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()

	res, err := fx.m.SetLists(ctx, ListsArgs{
		Allow: []string{"198.51.100.9", "10.0.0.0/8"},
		Deny:  []string{"203.0.113.0/24", "203.0.113.5", "2001:db8::/32", "203.0.113.0/24"},
	})
	if err != nil {
		t.Fatal(err)
	}
	if res.Allow != 2 || res.Deny != 4-1 {
		t.Fatalf("result = %+v", res)
	}

	deny4, _ := os.ReadFile(filepath.Join(fx.m.IPSets, "lpanel-deny.xml"))
	deny6, _ := os.ReadFile(filepath.Join(fx.m.IPSets, "lpanel-deny6.xml"))
	for want, got := range map[string]string{
		`<entry>203.0.113.0/24</entry>`: string(deny4), `<entry>203.0.113.5</entry>`: string(deny4),
		`family" value="inet6"`: string(deny6), `<entry>2001:db8::/32</entry>`: string(deny6),
	} {
		if !strings.Contains(strings.ReplaceAll(got, `name="family" value`, `family" value`), want) {
			t.Errorf("sets lack %s:\n%s\n%s", want, deny4, deny6)
		}
	}
	for _, name := range []string{"lpanel-allow", "lpanel-allow6", "lpanel-deny", "lpanel-deny6", "lpanel-cc", "lpanel-cc6"} {
		if _, err := os.Stat(filepath.Join(fx.m.IPSets, name+".xml")); err != nil {
			t.Errorf("no set %s", name)
		}
	}
	if len(fx.f.rich["public"]) != 6 {
		t.Fatalf("rules = %v", fx.f.rich["public"])
	}
	for _, want := range []string{
		`rule family="ipv4" priority="-300" source ipset="lpanel-allow" accept`,
		`rule family="ipv4" priority="-200" source ipset="lpanel-deny" drop`,
		`rule family="ipv6" priority="-100" source ipset="lpanel-cc6" drop`,
	} {
		if !fx.f.rich["public"][want] {
			t.Errorf("no rule %s", want)
		}
	}
	if !fx.m.Info(ctx).Rules {
		t.Fatal("info does not see the rules")
	}

	// The same lists again change nothing and reload nothing.
	reloads := fx.f.reloads
	if _, err := fx.m.SetLists(ctx, ListsArgs{Allow: []string{"10.0.0.0/8", "198.51.100.9"}, Deny: []string{"2001:db8::/32", "203.0.113.5", "203.0.113.0/24"}}); err != nil {
		t.Fatal(err)
	}
	if fx.f.reloads != reloads {
		t.Fatalf("reloaded again: %d -> %d", reloads, fx.f.reloads)
	}
	if info := fx.m.Info(ctx); len(info.Allow) != 2 || len(info.Deny) != 3 {
		t.Fatalf("state = %+v", info)
	}
}

func TestListsRefuseWhatLocksTheServerOut(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()
	for _, deny := range []string{"127.0.0.1", "127.0.0.0/8", "::1", "0.0.0.0/0", "::/0", "0.0.0.0/4", "128.0.0.0/1"} {
		if _, err := fx.m.SetLists(ctx, ListsArgs{Deny: []string{deny}}); code(err) != protocol.CodeBadArgs {
			t.Errorf("deny %s: %v", deny, err)
		}
	}
	if _, err := fx.m.SetLists(ctx, ListsArgs{Allow: []string{"0.0.0.0/0"}}); code(err) != protocol.CodeBadArgs {
		t.Errorf("allow everyone: %v", err)
	}
	// A network that contains the loopback is refused too.
	if _, err := fx.m.SetLists(ctx, ListsArgs{Deny: []string{"127.0.0.0/8"}}); err == nil {
		t.Error("loopback network accepted")
	}
	if len(fx.f.rich["public"]) != 0 {
		t.Fatalf("a rule was added: %v", fx.f.rich["public"])
	}
}

func TestListsHaveALimit(t *testing.T) {
	fx := newFixture(t)
	var many []string
	for i := 0; i < MaxListEntries+1; i++ {
		many = append(many, "10."+strconv.Itoa(i/65536)+"."+strconv.Itoa((i/256)%256)+"."+strconv.Itoa(i%256))
	}
	if _, err := fx.m.SetLists(context.Background(), ListsArgs{Deny: many}); code(err) != CodeTooMany {
		t.Fatalf("err = %v", err)
	}
}

func TestCountriesFetchOnceAndBlock(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()

	res, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"ru", "CN", "RU"}})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(res.Codes, ",") != "CN,RU" || res.V4 != 4 || res.V6 != 4 || len(res.Fetched) != 2 {
		t.Fatalf("result = %+v", res)
	}
	if len(fx.fetched) != 4 {
		t.Fatalf("fetched %v", fx.fetched)
	}
	cc, _ := os.ReadFile(filepath.Join(fx.m.IPSets, "lpanel-cc.xml"))
	if strings.Count(string(cc), "<entry>") != 4 || !strings.Contains(string(cc), "203.0.113.0/24") {
		t.Fatalf("set = %s", cc)
	}
	if fx.fetched[0] != "https://www.ipdeny.com/ipblocks/data/aggregated/cn-aggregated.zone" {
		t.Fatalf("url = %s", fx.fetched[0])
	}

	// Cached lists are not fetched again, unless asked.
	if _, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"CN"}}); err != nil {
		t.Fatal(err)
	}
	if len(fx.fetched) != 4 {
		t.Fatalf("fetched again: %v", fx.fetched)
	}
	if res, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"CN"}, Refresh: true}); err != nil || len(res.Fetched) != 1 || len(fx.fetched) != 6 {
		t.Fatalf("refresh: %+v %v %v", res, err, fx.fetched)
	}
	info := fx.m.Info(ctx)
	if strings.Join(info.Countries, ",") != "CN" || len(info.Cached) != 2 || info.Cached[0].V4 != 2 || info.Cached[0].Fetched == "" {
		t.Fatalf("info = %+v", info)
	}

	// None unblocks everything.
	if _, err := fx.m.SetCountries(ctx, CountriesArgs{}); err != nil {
		t.Fatal(err)
	}
	cc, _ = os.ReadFile(filepath.Join(fx.m.IPSets, "lpanel-cc.xml"))
	if strings.Contains(string(cc), "<entry>") {
		t.Fatalf("still blocking: %s", cc)
	}
}

func TestACountryThatCannotBeFetchedChangesNothing(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()
	if _, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"CN"}}); err != nil {
		t.Fatal(err)
	}
	if _, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"CN", "ZZ"}}); code(err) != CodeFetch {
		t.Fatalf("err = %v", err)
	}
	if info := fx.m.Info(ctx); strings.Join(info.Countries, ",") != "CN" {
		t.Fatalf("state changed: %+v", info)
	}
	// A page that is not a list is refused, and not cached.
	fx.m.Fetch = func(context.Context, string) ([]byte, error) { return []byte("<html>blocked</html>"), nil }
	if _, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"FR"}}); code(err) != CodeFetch {
		t.Fatalf("html: %v", err)
	}
	if fx.m.have("FR") {
		t.Fatal("a bad list was cached")
	}
}

func TestCountryCodesAreChecked(t *testing.T) {
	fx := newFixture(t)
	for _, c := range []string{"", "CHN", "1A", "C/N", "../etc"} {
		if _, err := fx.m.SetCountries(context.Background(), CountriesArgs{Codes: []string{c}}); code(err) != protocol.CodeBadArgs {
			t.Errorf("%q: %v", c, err)
		}
	}
	if len(fx.fetched) != 0 {
		t.Fatalf("fetched for a bad code: %v", fx.fetched)
	}
}

func TestParseNetworks(t *testing.T) {
	if got, err := parseNetworks([]byte("10.1.2.3/8\n\n 192.0.2.0/24 \n"), false); err != nil || strings.Join(got, ",") != "10.0.0.0/8,192.0.2.0/24" {
		t.Fatalf("v4 = %v %v", got, err)
	}
	if _, err := parseNetworks([]byte("2001:db8::/32\n"), false); err == nil {
		t.Fatal("a v6 list taken for v4")
	}
	if _, err := parseNetworks([]byte("10.0.0.0/8\nnot a network\n"), false); err == nil {
		t.Fatal("a bad line accepted")
	}
	if _, err := parseNetworks([]byte("\n\n"), false); err == nil {
		t.Fatal("an empty list accepted")
	}
}

func TestHTTPFetchOnlyTalksToTheListSite(t *testing.T) {
	if _, err := httpFetch(context.Background(), "https://evil.example/x"); err == nil {
		t.Fatal("fetched a stranger's URL")
	}
	if _, err := httpFetch(context.Background(), "http://www.ipdeny.com/x"); err == nil {
		t.Fatal("fetched over plain HTTP")
	}
}

func TestProtectedAddressesAreNeverShutOut(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()
	me := []string{"203.0.113.7"}

	// Denying a network with my address in it.
	if _, err := fx.m.SetLists(ctx, ListsArgs{Deny: []string{"203.0.113.0/24"}, Protect: me}); code(err) != CodeLockout {
		t.Fatalf("deny: %v", err)
	}
	// ...unless the allow list names me.
	if _, err := fx.m.SetLists(ctx, ListsArgs{Allow: me, Deny: []string{"203.0.113.0/24"}, Protect: me}); err != nil {
		t.Fatalf("allowed: %v", err)
	}
	// A country that has my address (the fake list holds 203.0.113.0/24).
	if _, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"CN"}, Protect: []string{"203.0.113.99"}}); code(err) != CodeLockout {
		t.Fatalf("country: %v", err)
	}
	if info := fx.m.Info(ctx); len(info.Countries) != 0 {
		t.Fatalf("countries set despite the refusal: %+v", info)
	}
	if _, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"CN"}, Protect: me}); err != nil {
		t.Fatalf("my address is allowed, so the country may be blocked: %v", err)
	}
	if _, err := fx.m.SetCountries(ctx, CountriesArgs{Codes: []string{"CN"}, Protect: []string{"192.0.2.1"}}); err != nil {
		t.Fatalf("an address outside the country: %v", err)
	}
	// Limiting a port the panel uses to addresses without mine.
	if _, err := fx.m.Port(ctx, PortArgs{Action: "open", Port: "7443", Proto: "tcp", Sources: []string{"198.51.100.1"}, Protect: me}); code(err) != CodeLockout {
		t.Fatalf("port: %v", err)
	}
	if !fx.f.ports["public"]["7443/tcp"] {
		t.Fatal("7443 changed despite the refusal")
	}
	if _, err := fx.m.Port(ctx, PortArgs{Action: "open", Port: "7443", Proto: "tcp", Sources: []string{"198.51.100.1", "203.0.113.0/24"}, Protect: me}); err != nil {
		t.Fatalf("port with my network: %v", err)
	}
	// A port nobody needs to reach the panel by has no such rule.
	if _, err := fx.m.Port(ctx, PortArgs{Action: "open", Port: "3306", Proto: "tcp", Sources: []string{"198.51.100.1"}, Protect: me}); err != nil {
		t.Fatalf("3306: %v", err)
	}
	if _, err := fx.m.SetLists(ctx, ListsArgs{Protect: []string{"nope"}}); code(err) != protocol.CodeBadArgs {
		t.Fatalf("bad protect: %v", err)
	}
}

func TestLimitingAServicesPortKeepsTheServicesOtherPorts(t *testing.T) {
	fx := newFixture(t)
	ctx := context.Background()
	fx.f.services["mail"] = []string{"25/tcp", "587/tcp"}
	fx.f.enabled["mail"] = true

	if _, err := fx.m.Port(ctx, PortArgs{Action: "open", Port: "22", Proto: "tcp", Sources: []string{"203.0.113.7"}, Protect: []string{"203.0.113.7"}}); err != nil {
		t.Fatal(err)
	}
	if fx.f.enabled["ssh"] || !fx.f.enabled["https"] {
		t.Fatalf("services = %v", fx.f.enabled)
	}
	if fx.f.ports["public"]["22/tcp"] || !fx.f.rich["public"][`rule family="ipv4" source address="203.0.113.7" port port="22" protocol="tcp" accept`] {
		t.Fatalf("22 is not limited: %v %v", fx.f.ports["public"], fx.f.rich["public"])
	}

	// Closing one of a service's two ports leaves the other open.
	if _, err := fx.m.Port(ctx, PortArgs{Action: "close", Port: "587", Proto: "tcp"}); err != nil {
		t.Fatal(err)
	}
	if fx.f.enabled["mail"] || fx.f.ports["public"]["587/tcp"] || !fx.f.ports["public"]["25/tcp"] {
		t.Fatalf("mail: services %v ports %v", fx.f.enabled, fx.f.ports["public"])
	}
}
