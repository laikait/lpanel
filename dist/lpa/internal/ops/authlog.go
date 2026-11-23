package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/authlog"
)

// AuthLog adds the sign-in log reader.
//
//	authlog.scan  {cursors}   read-only: failed sign-ins (ssh, ftp, mail, smtp) by address since each cursor
func AuthLog(r *Registry, s *authlog.Scanner) {
	r.Add(Op{
		Name: "authlog.scan", ReadOnly: true, Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a authlog.Args
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return s.Scan(ctx, a)
		},
	})
}
