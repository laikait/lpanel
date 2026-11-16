package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/mail"
)

// Mail adds the mail operations.
//
//	mail.apply  {username, suspended, domains}  an account's whole mail state
//	mail.purge  {username, domain, local}       delete a mailbox's mail (or a domain's), once no longer set up
//	mail.usage  {username}                      read-only: each mailbox's size
//	mail.settings {smarthost}                   the server's relay host for outgoing mail ("" for none)
//	mail.info   {}                              read-only: Exim's and Dovecot's state, the queue
//
// account.terminate removes the account's mail files itself.
func Mail(r *Registry, m *mail.Manager) {
	r.Add(Op{
		Name: "mail.apply", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mail.ApplyArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
	r.Add(Op{
		Name: "mail.purge", Timeout: 5 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mail.PurgeArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Purge(ctx, a)
		},
	})
	r.Add(Op{
		Name: "mail.usage", ReadOnly: true, Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mail.UsageArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Usage(ctx, a)
		},
	})
	r.Add(Op{
		Name: "mail.settings", Timeout: 15 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mail.SettingsArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Settings(ctx, a)
		},
	})
	r.Add(Op{
		Name: "mail.info", ReadOnly: true, Timeout: 15 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Info(ctx), nil
		},
	})
}
