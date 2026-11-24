package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/cron"
)

// Cron adds the scheduled jobs operation.
//
//	cron.apply  {username, suspended, jobs}   an account's whole list of jobs (none, or suspended: the file goes)
func Cron(r *Registry, m *cron.Manager) {
	r.Add(Op{
		Name: "cron.apply", Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a cron.Args
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
}
