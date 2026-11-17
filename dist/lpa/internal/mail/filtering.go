package mail

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Spam and virus filtering: Exim asks Rspamd about every message from
// outside (the "filter" flag file turns that on), and Rspamd asks ClamAV
// when virus scanning is on. Rspamd's own settings live in the installer's
// files in /etc/rspamd/local.d; the agent writes only the two below.
//
//	actions.conf    the score at which mail is refused
//	antivirus.conf  ClamAV, or nothing

// FilteringArgs are the server's filtering settings.
type FilteringArgs struct {
	Spam bool `json:"spam"`
	// Virus needs Spam: Rspamd is what asks ClamAV.
	Virus bool `json:"virus"`
	// Reject is the score (tenths) at which mail from outside is refused
	// while the sender is still connected.
	Reject int `json:"reject"`
}

// Rejection scores, in tenths.
const (
	MinReject     = 50
	MaxReject     = 1000
	DefaultReject = 150
)

// Filtering turns spam and virus filtering on or off. Each service is
// started before Exim is told to use it, and Exim stops using it before it
// stops.
func (m *Manager) Filtering(ctx context.Context, a FilteringArgs) (map[string]any, error) {
	if !m.Enabled {
		return nil, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	if a.Virus && !a.Spam {
		return nil, badArgs("virus scanning needs spam filtering on (Rspamd asks ClamAV)")
	}
	if a.Reject == 0 {
		a.Reject = DefaultReject
	}
	if a.Reject < MinReject || a.Reject > MaxReject {
		return nil, badArgs("the score that refuses mail must be %d to %d (tenths)", MinReject, MaxReject)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	exim, _, err := m.groups()
	if err != nil {
		return nil, err
	}
	if err := m.dir(m.EximDir, 0o750, exim); err != nil {
		return nil, err
	}
	flag := filepath.Join(m.EximDir, "filter")
	if !a.Spam {
		if err := os.RemoveAll(flag); err != nil {
			return nil, err
		}
	}
	if err := m.dir(m.RspamdDir, 0o755, 0); err != nil {
		return nil, err
	}
	c1, err := m.writeFile(filepath.Join(m.RspamdDir, "actions.conf"), actionsConf(a.Reject), 0o644, 0)
	if err != nil {
		return nil, err
	}
	c2, err := m.writeFile(filepath.Join(m.RspamdDir, "antivirus.conf"), antivirusConf(a.Virus), 0o644, 0)
	if err != nil {
		return nil, err
	}

	units := func(verb string, names ...string) error {
		return sysexec.Must(ctx, m.Run, "systemctl", append([]string{verb, "--now", "--quiet"}, names...)...)
	}
	if a.Virus {
		// clamd waits for freshclam's first download (its unit restarts
		// until the signatures are there).
		if err := units("enable", "clamav-freshclam", "clamd@scan"); err != nil {
			return nil, err
		}
	} else if err := units("disable", "clamd@scan", "clamav-freshclam"); err != nil {
		return nil, err
	}
	if a.Spam {
		if err := units("enable", "rspamd"); err != nil {
			return nil, err
		}
		if c1 || c2 {
			if err := sysexec.Must(ctx, m.Run, "systemctl", "reload-or-restart", "rspamd"); err != nil {
				return nil, err
			}
		}
		if _, err := m.writeFile(flag, []byte("on\n"), 0o640, exim); err != nil {
			return nil, err
		}
	} else if err := units("disable", "rspamd"); err != nil {
		return nil, err
	}
	m.Log.Info("mail filtering set", "spam", a.Spam, "virus", a.Virus, "reject", a.Reject)
	return map[string]any{"spam": a.Spam, "virus": a.Virus, "reject": a.Reject}, nil
}

// actionsConf is Rspamd's actions: mail is refused at reject; filing spam
// is each account's (by the score Exim adds), so Rspamd adds nothing and
// greylists nothing.
func actionsConf(reject int) []byte {
	return []byte(fmt.Sprintf(`# Written by lpa; changes here are replaced (Admin panel, Email).
reject = %d.%d;
add_header = null;
rewrite_subject = null;
greylist = null;
`, reject/10, reject%10))
}

// antivirusConf is ClamAV, over its socket, refusing what it finds; or
// nothing when virus scanning is off.
func antivirusConf(on bool) []byte {
	if !on {
		return []byte("# Written by lpa; changes here are replaced. Virus scanning is off.\n")
	}
	return []byte(`# Written by lpa; changes here are replaced (Admin panel, Email).
clamav {
  type = "clamav";
  servers = "/run/clamd.scan/clamd.sock";
  action = "reject";
  message = "This message carries a virus (${VIRUS})";
  scan_mime_parts = true;
  scan_text_mime = false;
  max_size = 20971520;
  symbol = "CLAM_VIRUS";
}
`)
}

// filteringInfo reads the filtering state for Info.
func (m *Manager) filteringInfo(ctx context.Context, info *Info) {
	info.Filtering.Spam = exists(filepath.Join(m.EximDir, "filter"))
	if data, err := os.ReadFile(filepath.Join(m.RspamdDir, "antivirus.conf")); err == nil {
		info.Filtering.Virus = strings.Contains(string(data), "clamav {")
	}
	info.Filtering.Reject = DefaultReject
	if data, err := os.ReadFile(filepath.Join(m.RspamdDir, "actions.conf")); err == nil {
		var whole, tenth int
		for _, line := range strings.Split(string(data), "\n") {
			if _, err := fmt.Sscanf(line, "reject = %d.%d;", &whole, &tenth); err == nil {
				info.Filtering.Reject = whole*10 + tenth
			}
		}
	}
	active := func(unit string) bool {
		code, _, err := m.Run.Run(ctx, "systemctl", "is-active", "--quiet", unit)
		return err == nil && code == 0
	}
	info.Rspamd.Running = active("rspamd")
	info.ClamAV.Running = active("clamd@scan")
	if code, out, err := m.Run.Run(ctx, "rspamd", "--version"); err == nil && code == 0 {
		// "Rspamd daemon version 4.3.0"
		if f := strings.Fields(out); len(f) > 0 {
			info.Rspamd.Version = f[len(f)-1]
		}
	}
	if code, out, err := m.Run.Run(ctx, "clamd", "--version"); err == nil && code == 0 {
		// "ClamAV 1.4.6/27000/Mon Nov 16 08:00:00 2026" (the signatures after the slash)
		first, _, _ := strings.Cut(strings.TrimSpace(out), "\n")
		if f := strings.Fields(first); len(f) >= 2 {
			version, signatures, _ := strings.Cut(f[1], "/")
			info.ClamAV.Version = version
			info.ClamAV.Signatures = signatures
		}
	}
	if entries, err := os.ReadDir(m.ClamDB); err == nil {
		for _, e := range entries {
			switch filepath.Ext(e.Name()) {
			case ".cvd", ".cld", ".hdb", ".ndb", ".hsb":
				info.ClamAV.Ready = true
			}
		}
	}
}
