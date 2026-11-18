package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/sshkeys"
)

// SSH adds the SSH key operation.
//
//	ssh.apply  {username, keys: [{type, key, comment}]}  an account's whole list of keys
func SSH(r *Registry, m *sshkeys.Manager) {
	r.Add(Op{
		Name: "ssh.apply", Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a sshkeys.ApplyArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
}
