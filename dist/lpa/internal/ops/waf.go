package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/web"
)

// WAF adds the web application firewall operations (ModSecurity, in Apache).
//
//	waf.info      {}                     read-only: is it loaded, the rule set's version, the paranoia level
//	waf.settings  {paranoia}             how strict the Core Rule Set is, 1 to 4 (global)
//	waf.events    {cursors}              read-only: what the rules said about requests, by account, since each cursor
//
// A site's own mode and excluded rules are part of web.apply.
func WAF(r *Registry, m *web.Manager) {
	r.Add(Op{
		Name: "waf.info", ReadOnly: true, Timeout: 30 * time.Second,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a struct{}
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.WAFInfo(ctx)
		},
	})
	r.Add(Op{
		Name: "waf.settings", Timeout: 2 * time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a web.WAFSettings
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.SetWAF(ctx, a)
		},
	})
	r.Add(Op{
		Name: "waf.events", ReadOnly: true, Timeout: time.Minute,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a web.WAFEventsArgs
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return m.WAFEvents(ctx, a)
		},
	})
}
