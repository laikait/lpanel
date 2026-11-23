package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/firewall"
)

// Firewall adds the firewall operations.
//
//	firewall.info       {}                          read-only: firewalld's state, ports, lists, blocked countries
//	firewall.port       {action, port, proto, sources}   open, limit to some addresses, or close a port
//	firewall.lists      {allow, deny}               the allow list and the deny list, whole
//	firewall.countries  {codes, refresh}            the countries blocked, whole (fetches their lists)
func Firewall(r *Registry, m *firewall.Manager) {
	r.Add(Op{
		Name: "firewall.info", ReadOnly: true, Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Info(ctx), nil
		},
	})
	r.Add(Op{
		Name: "firewall.port", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a firewall.PortArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Port(ctx, a)
		},
	})
	r.Add(Op{
		Name: "firewall.lists", Timeout: 3 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a firewall.ListsArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.SetLists(ctx, a)
		},
	})
	r.Add(Op{
		Name: "firewall.countries", Timeout: 10 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a firewall.CountriesArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.SetCountries(ctx, a)
		},
	})
}
