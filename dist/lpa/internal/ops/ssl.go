package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/certs"
)

// SSL adds the certificate operations.
//
//	ssl.issue        {username, names, authorities, zerossl_eab}  in the background: a site's certificate from an authority
//	ssl.install      {username, key, chain}                       a certificate the owner brings (key optional after ssl.csr)
//	ssl.csr          {username, names, key_type, country, …}      a key kept here, and a request for a commercial certificate
//	ssl.host_issue   {names, authorities, zerossl_eab}            in the background: the hostname's certificate, installed
//	ssl.host_install {key, chain}                                 a hostname certificate the administrator brings
//	ssl.host_info    {}                                           read-only: the hostname certificate installed now
//
// A site serves a stored certificate once web.apply names its id.
func SSL(r *Registry, m *certs.Manager) {
	r.Add(Op{
		Name: "ssl.issue", Async: true, Timeout: 10 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a certs.IssueArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Issue(ctx, a)
		},
	})
	r.Add(Op{
		Name: "ssl.install", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a certs.InstallArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.Install(ctx, a)
		},
	})
	r.Add(Op{
		Name: "ssl.csr", Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a certs.CSRArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.CSR(ctx, a)
		},
	})
	r.Add(Op{
		Name: "ssl.host_issue", Async: true, Timeout: 10 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a certs.HostIssueArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.HostIssue(ctx, a)
		},
	})
	r.Add(Op{
		Name: "ssl.host_install", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a certs.HostInstallArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.HostInstall(ctx, a)
		},
	})
	r.Add(Op{
		Name: "ssl.host_info", ReadOnly: true, Timeout: 10 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.HostInfo(ctx)
		},
	})
}
