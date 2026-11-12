package web

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"strconv"
	"time"
)

// httpProbe asks each server of the stack on this machine for host and
// checks that the answer carries revision. Any status will do: an empty site
// answers 403, a suspended one 503, and both prove the new configuration is
// live.
//
// Alone, Apache or nginx answers on :80 with X-LPanel-Revision. In front of
// Apache, nginx answers on :80 with X-LPanel-Front, and Apache is asked
// directly on its backend port: a site nginx redirects never reaches Apache.
func (m *Manager) httpProbe(ctx context.Context, host, revision string) error {
	if m.Server == "hybrid" {
		if err := probeOne(ctx, "127.0.0.1:"+strconv.Itoa(m.BackendPort), "X-LPanel-Revision", host, revision); err != nil {
			return fmt.Errorf("Apache: %w", err)
		}
		if err := probeOne(ctx, "127.0.0.1:80", "X-LPanel-Front", host, revision); err != nil {
			return fmt.Errorf("nginx: %w", err)
		}
		return nil
	}
	return probeOne(ctx, "127.0.0.1:80", "X-LPanel-Revision", host, revision)
}

func probeOne(ctx context.Context, addr, header, host, revision string) error {
	client := &http.Client{
		Timeout: 3 * time.Second,
		Transport: &http.Transport{
			DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
				var d net.Dialer
				return d.DialContext(ctx, "tcp", addr)
			},
			DisableKeepAlives: true,
		},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodHead, "http://"+host+"/", nil)
	if err != nil {
		return err
	}
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	resp.Body.Close()
	if got := resp.Header.Get(header); got != revision {
		return fmt.Errorf("answered with %s %q, not %q", header, got, revision)
	}
	return nil
}
