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
//	mail.settings {smarthost, user, password, keep_password}  the relay host for outgoing mail ("" for none) and its login
//	mail.filtering {spam, virus, reject}        Rspamd and ClamAV on or off, the score that refuses mail
//	mail.info   {}                              read-only: the mail servers' state, the queue, filtering
//	mail.queue  {search, limit}                 read-only: the messages in Exim's queue
//	mail.queue_view {id}                        read-only: one queued message and its log
//	mail.queue_act {action, ids}                deliver, remove, freeze, thaw (ids) or flush (all)
//	mail.track  {username, search, status, days, limit}  read-only: what became of each message, from Exim's log
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

	add := func(name string, readOnly bool, timeout time.Duration, run func(context.Context, json.RawMessage) (any, error)) {
		r.Add(Op{Name: name, ReadOnly: readOnly, Timeout: timeout, Run: run})
	}
	add("mail.filtering", false, 2*time.Minute, func(ctx context.Context, args json.RawMessage) (any, error) {
		var a mail.FilteringArgs
		if err := Decode(args, &a); err != nil {
			return nil, err
		}
		return m.Filtering(ctx, a)
	})
	add("mail.queue", true, 30*time.Second, func(ctx context.Context, args json.RawMessage) (any, error) {
		var a mail.QueueArgs
		if err := Decode(args, &a); err != nil {
			return nil, err
		}
		return m.Queue(ctx, a)
	})
	add("mail.queue_view", true, 30*time.Second, func(ctx context.Context, args json.RawMessage) (any, error) {
		var a mail.QueueViewArgs
		if err := Decode(args, &a); err != nil {
			return nil, err
		}
		return m.QueueMessage(ctx, a)
	})
	add("mail.queue_act", false, time.Minute, func(ctx context.Context, args json.RawMessage) (any, error) {
		var a mail.QueueActArgs
		if err := Decode(args, &a); err != nil {
			return nil, err
		}
		return m.QueueAct(ctx, a)
	})
	add("mail.track", true, time.Minute, func(ctx context.Context, args json.RawMessage) (any, error) {
		var a mail.TrackArgs
		if err := Decode(args, &a); err != nil {
			return nil, err
		}
		return m.Track(ctx, a)
	})
}
