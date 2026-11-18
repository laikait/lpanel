package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/backup"
)

// Backup adds the backup operations.
//
//	backup.destination         {id, type, path|host,port,user|endpoint,scheme,region,bucket,prefix, access_key?, secret_key?}
//	                           checks a destination, keeps it (secrets with the agent) and makes its repository
//	backup.destination_remove  {id}                       forgets a destination; what is there stays
//	backup.run                 {destination, accounts: [{username, meta}], keep: {daily, weekly, monthly}, prune}
//	                           background: one snapshot per account (home, database dumps, meta), then retention
//	backup.status              {}                         read-only: the run going on now
//	backup.snapshots           {destination, username?}   read-only: snapshots, newest first
//	backup.files               {destination, snapshot, username, path}      read-only: a folder of an account's snapshot
//	backup.meta                {destination, snapshot}                      read-only: the snapshot's lpanel.json
//	backup.restore             {destination, snapshot, username, paths, databases, folder}  background: put them back
//	backup.download            {destination, snapshot, username, path | database}  background: an archive in ~/lpanel-backups
func Backup(r *Registry, m *backup.Manager) {
	r.Add(Op{
		Name: "backup.destination", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a backup.DestinationArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.SetDestination(ctx, a)
		},
	})
	r.Add(Op{
		Name: "backup.destination_remove", Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a backup.RemoveArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.RemoveDestination(ctx, a)
		},
	})
	r.Add(Op{
		Name: "backup.run", Async: true, Timeout: 24 * time.Hour,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a backup.RunArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Run(ctx, a)
		},
	})
	r.Add(Op{
		Name: "backup.status", ReadOnly: true, Timeout: 10 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Progress(), nil
		},
	})
	r.Add(Op{
		Name: "backup.snapshots", ReadOnly: true, Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a backup.SnapshotArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Snapshots(ctx, a)
		},
	})
	r.Add(Op{
		Name: "backup.files", ReadOnly: true, Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a backup.FilesArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Files(ctx, a)
		},
	})
	r.Add(Op{
		Name: "backup.meta", ReadOnly: true, Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a backup.MetaArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Meta(ctx, a)
		},
	})
	r.Add(Op{
		Name: "backup.restore", Async: true, Timeout: 12 * time.Hour,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a backup.RestoreArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Restore(ctx, a)
		},
	})
	r.Add(Op{
		Name: "backup.download", Async: true, Timeout: 12 * time.Hour,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a backup.DownloadArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Download(ctx, a)
		},
	})
}
