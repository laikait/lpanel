package mail

import (
	"context"
	"net"
	"testing"
)

// fakeDNS answers from tables; a name not in them is "not found".
type fakeDNS struct {
	txt   map[string][]string
	mx    map[string][]*net.MX
	host  map[string][]string
	addr  map[string][]string
	broke map[string]bool
}

func (f *fakeDNS) lookups() *lookups {
	nf := func(name string) error { return &net.DNSError{Err: "no such host", Name: name, IsNotFound: true} }
	fail := func(name string) error {
		return &net.DNSError{Err: "server misbehaving", Name: name, IsTemporary: true}
	}
	return &lookups{
		txt: func(_ context.Context, n string) ([]string, error) {
			if f.broke[n] {
				return nil, fail(n)
			}
			if v, ok := f.txt[n]; ok {
				return v, nil
			}
			return nil, nf(n)
		},
		mx: func(_ context.Context, n string) ([]*net.MX, error) {
			if v, ok := f.mx[n]; ok {
				return v, nil
			}
			return nil, nf(n)
		},
		host: func(_ context.Context, n string) ([]string, error) {
			if f.broke[n] {
				return nil, fail(n)
			}
			if v, ok := f.host[n]; ok {
				return v, nil
			}
			return nil, nf(n)
		},
		addr: func(_ context.Context, ip string) ([]string, error) {
			if v, ok := f.addr[ip]; ok {
				return v, nil
			}
			return nil, nf(ip)
		},
	}
}

func TestChecksReadEveryRecordAndEvaluateSPF(t *testing.T) {
	f := newFixture(t)
	dns := &fakeDNS{
		txt: map[string][]string{
			"example.com":                     {"google-site-verification=x", "v=spf1 a mx include:_spf.provider.test ~all"},
			"_spf.provider.test":              {"v=spf1 ip4:198.51.100.0/24 -all"},
			"_dmarc.example.com":              {"v=DMARC1; p=none"},
			"default._domainkey.example.com":  {"v=DKIM1; k=rsa; p=MIIB"},
			"elsewhere.test":                  {"v=spf1 ip4:192.0.2.1 -all"},
			"twice.test":                      {"v=spf1 -all", "v=spf1 +all"},
			"loop.test":                       {"v=spf1 include:loop.test -all"},
			"redirected.test":                 {"v=spf1 redirect=_spf.provider.test"},
			"widenet.test":                    {"v=spf1 a:mail.widenet.test/24 -all"},
			"3.113.0.203.zen.spamhaus.org":    nil,
			"default._domainkey.nokey.test":   {"k=rsa; p=MIIB"},
			"nokey.test":                      {"v=spf1 ip4:203.0.113.3 -all"},
			"_dmarc.nokey.test":               {"v=DMARC1; p=reject"},
			"_spf.unused.test":                {"v=spf1 -all"},
			"spf-host.test":                   {"v=spf1 mx -all"},
			"mail.example.com.not-a-txt.test": {"x"},
		},
		mx: map[string][]*net.MX{
			"example.com":   {{Host: "mx2.backup.test.", Pref: 20}, {Host: "mail.example.com.", Pref: 10}},
			"spf-host.test": {{Host: "mail.example.com.", Pref: 10}},
		},
		host: map[string][]string{
			"example.com":                        {"203.0.113.3"},
			"mail.example.com":                   {"203.0.113.3"},
			"mx2.backup.test":                    {"192.0.2.9"},
			"server.example.com":                 {"203.0.113.3"},
			"mail.widenet.test":                  {"203.0.113.200"},
			"3.113.0.203.bl.spamcop.net":         {"127.0.0.2"},
			"3.113.0.203.b.barracudacentral.org": {"127.255.255.254"},
		},
		addr:  map[string][]string{"203.0.113.3": {"server.example.com."}},
		broke: map[string]bool{"broken.test": true, "3.113.0.203.psbl.surriel.com": true},
	}
	f.m.Lookups = dns.lookups()
	f.m.LocalIPs = func() []string { return []string{"127.0.0.1", "10.0.0.5", "203.0.113.3"} }

	res, err := f.m.Check(context.Background(), CheckArgs{
		Domains: []string{"example.com", "elsewhere.test", "twice.test", "loop.test", "redirected.test", "widenet.test", "nokey.test", "spf-host.test", "broken.test", "none.test"},
		IP:      "203.0.113.3", Hostname: "server.example.com", Lists: true,
	})
	if err != nil {
		t.Fatal(err)
	}
	ex := res.Domains["example.com"]
	if ex.SPF != "v=spf1 a mx include:_spf.provider.test ~all" || ex.SPFCount != 1 || ex.DMARC != "v=DMARC1; p=none" || ex.DKIM != "v=DKIM1; k=rsa; p=MIIB" {
		t.Fatalf("records: %+v", ex)
	}
	if len(ex.MX) != 2 || ex.MX[0].Host != "mail.example.com" || !ex.MX[0].Here || ex.MX[1].Here {
		t.Fatalf("mx: %+v", ex.MX)
	}
	for domain, want := range map[string]string{
		"example.com":     "pass",      // a
		"elsewhere.test":  "fail",      // -all, another address
		"twice.test":      "permerror", // two records
		"loop.test":       "permerror", // more than ten lookups
		"redirected.test": "fail",      // the provider's -all
		"widenet.test":    "pass",      // a/24
		"nokey.test":      "pass",      // ip4
		"spf-host.test":   "pass",      // mx
		"none.test":       "none",
	} {
		if got := res.Domains[domain].SPFResult["203.0.113.3"]; got != want {
			t.Errorf("%s: SPF %s, want %s", domain, got, want)
		}
	}
	if res.Domains["nokey.test"].DKIM != "k=rsa; p=MIIB" {
		t.Errorf("a key without its version tag: %q", res.Domains["nokey.test"].DKIM)
	}
	if res.Domains["broken.test"].Error == "" {
		t.Error("a domain whose DNS fails has no error")
	}

	ip := res.IPs["203.0.113.3"]
	if ip.Private || len(ip.PTR) != 1 || ip.PTR[0] != "server.example.com" || !ip.Forward {
		t.Fatalf("ptr: %+v", ip)
	}
	for list, want := range map[string]string{
		"zen.spamhaus.org": "clear", "bl.spamcop.net": "listed", "b.barracudacentral.org": "unknown",
		"psbl.surriel.com": "unknown", "bl.mailspike.net": "clear",
	} {
		if ip.Lists[list] != want {
			t.Errorf("%s: %s, want %s", list, ip.Lists[list], want)
		}
	}
}

func TestChecksOfTheServersOwnAddresses(t *testing.T) {
	f := newFixture(t)
	f.m.Lookups = (&fakeDNS{}).lookups()
	f.m.LocalIPs = func() []string { return []string{"127.0.0.1", "::1", "fe80::1", "10.0.0.5"} }
	res, err := f.m.Check(context.Background(), CheckArgs{Lists: true})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.IPs) != 1 || !res.IPs["10.0.0.5"].Private || len(res.IPs["10.0.0.5"].Lists) != 0 {
		t.Fatalf("%+v", res.IPs)
	}
	for name, a := range map[string]CheckArgs{
		"a broken domain": {Domains: []string{"no domain"}},
		"a broken ip":     {IP: "300.1.1.1"},
		"a broken host":   {Hostname: "-x"},
		"too many":        {Domains: make([]string, MaxCheckDomains+1)},
	} {
		if _, err := f.m.Check(context.Background(), a); code(err) != "bad_args" {
			t.Errorf("%s: %v", name, err)
		}
	}
}
