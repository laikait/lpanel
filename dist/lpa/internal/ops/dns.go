package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/dns"
)

// DNS adds the DNS operations.
//
//	dns.apply   {username, zone, soa, records}  a zone's whole state (username empty: a zone of the server's own)
//	dns.remove  {username, zone}                delete a zone
//	dns.info    {}                              read-only: PowerDNS's version and state
//	dns.lookup  {hosts, zones}                  read-only: what the world's DNS says (addresses; nameservers)
//
// account.terminate removes the account's zones itself.
func DNS(r *Registry, m *dns.Manager) {
	r.Add(Op{
		Name: "dns.apply", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a dns.ApplyArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
	r.Add(Op{
		Name: "dns.remove", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a dns.ZoneArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Remove(ctx, a)
		},
	})
	r.Add(Op{
		Name: "dns.info", ReadOnly: true, Timeout: 10 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Info(ctx), nil
		},
	})
	r.Add(Op{
		Name: "dns.lookup", ReadOnly: true, Timeout: 20 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a dns.LookupArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Lookup(ctx, a)
		},
	})
}
