package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/web"
)

// Web adds the web server operations.
//
//	web.apply   {username, suspended, sites, pool}  an account's whole web state
//	web.remove  {username}                          its sites and pools, gone
//	web.info    {}                                  read-only: server and PHP versions
func Web(r *Registry, m *web.Manager) {
	r.Add(Op{
		Name: "web.apply", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a web.ApplyArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
	r.Add(Op{
		Name: "web.remove", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a web.NameArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Remove(ctx, a)
		},
	})
	r.Add(Op{
		Name: "web.info", ReadOnly: true, Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Info(ctx)
		},
	})
}
