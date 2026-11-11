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
//	web.traffic {}                                  bytes per account and day, from the access logs
//	php.info    {}                                  read-only: every PHP version and its extensions
//	php.install {version}                           in the background: a version, with the default extensions
//	php.remove  {version}                           in the background: refused while sites use it
//	php.extensions {version, install, remove}       in the background
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

	// It changes only the agent's own record of how far it has read, and
	// answers running totals, so it may be repeated freely.
	r.Add(Op{
		Name: "web.traffic", ReadOnly: true, Timeout: 5 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Traffic(ctx)
		},
	})
	r.Add(Op{
		Name: "php.info", ReadOnly: true, Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.PHPInfo(ctx)
		},
	})
	// dnf takes minutes: these run in the background (op.status).
	r.Add(Op{
		Name: "php.install", Async: true, Timeout: 30 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a web.PHPArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.InstallPHP(ctx, a)
		},
	})
	r.Add(Op{
		Name: "php.remove", Async: true, Timeout: 30 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a web.PHPArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.RemovePHP(ctx, a)
		},
	})
	r.Add(Op{
		Name: "php.extensions", Async: true, Timeout: 30 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a web.ExtensionArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.PHPExtensions(ctx, a)
		},
	})
}
