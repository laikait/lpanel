package web

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"time"
)

// httpProbe asks Apache on this machine for host and checks that the answer
// carries revision. Any status will do: an empty site answers 403, a
// suspended one 503, and both prove the new configuration is live.
func httpProbe(ctx context.Context, host, revision string) error {
	client := &http.Client{
		Timeout: 3 * time.Second,
		Transport: &http.Transport{
			DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
				var d net.Dialer
				return d.DialContext(ctx, "tcp", "127.0.0.1:80")
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
	if got := resp.Header.Get("X-LPanel-Revision"); got != revision {
		return fmt.Errorf("answered with revision %q, not %q", got, revision)
	}
	return nil
}
