package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/mysql"
)

// DB adds the database operations.
//
//	db.apply       {username, databases, users, hosts, suspended}  an account's whole database state
//	db.drop        {username, name}                                drop one database and its data
//	db.rename      {username, from, to}                            move a database's tables to a new name
//	db.sizes       {usernames?}                                    read-only: each database's size in bytes
//	db.sso         {username, databases}                           a fresh phpMyAdmin login for the account
//	db.sso_revoke  {username}                                      remove that login
//	db.remove      {username}                                      drop every database and user of an account
//	db.info        {}                                              read-only: MariaDB's version and state
//	db.processes   {}                                              read-only: its connections
//	db.kill        {id}                                            end one of an account's connections
func DB(r *Registry, m *mysql.Manager) {
	r.Add(Op{
		Name: "db.apply", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mysql.ApplyArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
	r.Add(Op{
		Name: "db.drop", Timeout: 5 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mysql.NameArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Drop(ctx, a)
		},
	})
	r.Add(Op{
		Name: "db.rename", Timeout: 5 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mysql.RenameArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Rename(ctx, a)
		},
	})
	r.Add(Op{
		Name: "db.sizes", ReadOnly: true, Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mysql.SizesArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Sizes(a)
		},
	})
	r.Add(Op{
		Name: "db.sso", Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mysql.SSOArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.SSO(ctx, a)
		},
	})
	r.Add(Op{
		Name: "db.sso_revoke", Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mysql.UserArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.SSORevoke(ctx, a)
		},
	})
	r.Add(Op{
		Name: "db.remove", Timeout: 5 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mysql.UserArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Remove(ctx, a)
		},
	})
	r.Add(Op{
		Name: "db.info", ReadOnly: true, Timeout: 10 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Info(ctx), nil
		},
	})
	r.Add(Op{
		Name: "db.processes", ReadOnly: true, Timeout: 10 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			list, err := m.Processes(ctx)
			if err != nil {
				return nil, err
			}
			return map[string]any{"processes": list}, nil
		},
	})
	r.Add(Op{
		Name: "db.kill", Timeout: 10 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a mysql.KillArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Kill(ctx, a)
		},
	})
}
