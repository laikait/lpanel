package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/ftp"
)

// FTP adds the FTP operations.
//
//	ftp.apply     {username, users, suspended}                              an account's whole list of FTP logins
//	ftp.settings  {tls, passive_from, passive_to, passive_ip, max_clients, max_per_ip}  pure-ftpd.conf, whole
//	ftp.info      {}                                                        read-only: Pure-FTPd's state and its sessions
//	ftp.kill      {pid, username?}                                          end one session
func FTP(r *Registry, m *ftp.Manager) {
	r.Add(Op{
		Name: "ftp.apply", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a ftp.ApplyArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Apply(ctx, a)
		},
	})
	r.Add(Op{
		Name: "ftp.settings", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a ftp.SettingsArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Settings(ctx, a)
		},
	})
	r.Add(Op{
		Name: "ftp.info", ReadOnly: true, Timeout: 15 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Info(ctx), nil
		},
	})
	r.Add(Op{
		Name: "ftp.kill", Timeout: 15 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a ftp.KillArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Kill(ctx, a)
		},
	})
}
