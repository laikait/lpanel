package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/logs"
)

// Logs adds the log operations.
//
//	logs.sources {user?}                       read-only: the logs that can be read (the system's, or one account's own)
//	logs.read    {source, user?, lines?, filter?}  read-only: the latest lines of one, optionally only those with some text
//	logs.report  {user?, days?, refresh?}      GoAccess's statistics of the access logs of an account, or of all
func Logs(r *Registry, m *logs.Manager) {
	r.Add(Op{
		Name: "logs.sources", ReadOnly: true, Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct {
				User string `json:"user"`
			}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			list, err := m.Sources(ctx, a.User)
			if err != nil {
				return nil, err
			}
			return map[string]any{"sources": list}, nil
		},
	})
	r.Add(Op{
		Name: "logs.read", ReadOnly: true, Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a logs.ReadArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Read(ctx, a)
		},
	})
	// It writes only a report of its own, kept for a few minutes, so it may
	// be repeated freely.
	r.Add(Op{
		Name: "logs.report", ReadOnly: true, Timeout: 10 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a logs.ReportArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Report(ctx, a)
		},
	})
}
