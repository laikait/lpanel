package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/services"
)

// Services adds the service operations.
//
//	services.status  {}                 read-only: every service LPanel manages that is installed, how it is doing
//	services.act     {unit, action}     start, stop, restart, reload, enable or disable one of them (within what the unit allows)
func Services(r *Registry, m *services.Manager) {
	r.Add(Op{
		Name: "services.status", ReadOnly: true, Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			list, err := m.List(ctx)
			if err != nil {
				return nil, err
			}
			return map[string]any{"services": list}, nil
		},
	})
	r.Add(Op{
		Name: "services.act", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a services.ActArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Act(ctx, a)
		},
	})
}
