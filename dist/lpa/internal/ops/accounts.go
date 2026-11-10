package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/accounts"
)

// Accounts adds the hosting account operations.
//
//	account.create     {username, shell, quota}  make the user, home and quota
//	account.modify     {username, shell?, quota?}
//	account.suspend    {username}                expire it and end its processes
//	account.unsuspend  {username}
//	account.terminate  {username}                async: remove the user and home
//	account.info       {usernames?}              read-only: accounts and disk use
func Accounts(r *Registry, m *accounts.Manager) {
	r.Add(Op{
		Name: "account.create", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a accounts.CreateArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Create(ctx, a)
		},
	})
	r.Add(Op{
		Name: "account.modify", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a accounts.ModifyArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Modify(ctx, a)
		},
	})
	r.Add(Op{
		Name: "account.suspend", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a accounts.NameArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Suspend(ctx, a)
		},
	})
	r.Add(Op{
		Name: "account.unsuspend", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a accounts.NameArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Unsuspend(ctx, a)
		},
	})
	// A home can hold millions of files, so removal runs in the background.
	r.Add(Op{
		Name: "account.terminate", Async: true, Timeout: time.Hour,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a accounts.NameArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Terminate(ctx, a)
		},
	})
	r.Add(Op{
		Name: "account.info", ReadOnly: true, Timeout: 30 * time.Second,
		Run: func(_ context.Context, args json.RawMessage) (any, error) {
			var a accounts.InfoArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Info(a)
		},
	})
}
