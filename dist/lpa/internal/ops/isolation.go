package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/isolation"
)

// Isolation adds the account isolation operations.
//
//	isolation.info   {}                  read-only: whether /proc hides other users' processes, the link protections
//	isolation.apply  {hidepid, links}    both settings, whole
func Isolation(r *Registry, m *isolation.Manager) {
	r.Add(Op{
		Name: "isolation.info", ReadOnly: true, Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Info(ctx), nil
		},
	})
	r.Add(Op{
		Name: "isolation.apply", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a isolation.Settings
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
}
