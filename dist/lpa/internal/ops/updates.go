package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/updates"
)

// Updates adds the update operations.
//
//	updates.check          {}                          async: refresh the repositories, what can be updated (packages, security errata, PHP), reboot and restarts needed, LPanel's latest release
//	updates.apply          {scope, packages?, exclude?} async: update all, security, php or the named packages; restart what runs old code
//	updates.reboot         {cancel?}                   reboot a minute from now, or call that off
//	updates.panel          {version}                   async: download and check that release of LPanel and start its upgrade (backup, install, rollback on failure)
//	updates.panel_status   {}                          read-only: how the last upgrade went, the end of its log
func Updates(r *Registry, m *updates.Manager) {
	r.Add(Op{
		Name: "updates.check", Async: true, Timeout: 20 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Check(ctx)
		},
	})
	r.Add(Op{
		Name: "updates.apply", Async: true, Timeout: 2 * time.Hour,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a updates.ApplyArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
	r.Add(Op{
		Name: "updates.reboot", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a updates.RebootArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.ScheduleReboot(ctx, a)
		},
	})
	r.Add(Op{
		Name: "updates.panel", Async: true, Timeout: 30 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a updates.UpgradeArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Upgrade(ctx, a)
		},
	})
	r.Add(Op{
		Name: "updates.panel_status", ReadOnly: true, Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.UpgradeStatus(ctx), nil
		},
	})
}
