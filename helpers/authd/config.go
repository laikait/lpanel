package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"net/netip"
	"os"
	"regexp"
	"strings"
	"time"
)

// Config is /etc/lpanel/authd.json. Every key is optional; unknown keys are an error.
type Config struct {
	// Users whose password may be checked. Anyone else is refused before PAM is asked.
	Users []string `json:"users"`
	// AllowFrom limits each user to these addresses or CIDRs. A user with no entry may log in from anywhere.
	AllowFrom map[string][]string `json:"allow_from"`
	// ClientUser is the only local user that may talk to the socket.
	ClientUser string `json:"client_user"`
	// PAMService is the /etc/pam.d file used.
	PAMService      string  `json:"pam_service"`
	Lockout         Lockout `json:"lockout"`
	GlobalPerMinute int     `json:"global_per_minute"`

	allowFrom map[string][]netip.Prefix
}

// Lockout is the strict per-address lockout (ADR-0010).
type Lockout struct {
	Failures      int `json:"failures"`
	WindowSeconds int `json:"window_seconds"`
	BlockSeconds  int `json:"block_seconds"`
}

// Window is how far back failures count.
func (l Lockout) Window() time.Duration { return time.Duration(l.WindowSeconds) * time.Second }

// Block is how long a locked address waits.
func (l Lockout) Block() time.Duration { return time.Duration(l.BlockSeconds) * time.Second }

// Defaults is the configuration with no file: root only, 5 failures in 15 minutes block for 15 minutes.
func Defaults() Config {
	return Config{
		Users:           []string{"root"},
		ClientUser:      "lpanel",
		PAMService:      "lpanel",
		Lockout:         Lockout{Failures: 5, WindowSeconds: 900, BlockSeconds: 900},
		GlobalPerMinute: 30,
	}
}

var (
	userPattern    = regexp.MustCompile(`^[a-z_][a-z0-9_-]{0,31}$`)
	servicePattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)
)

// LoadConfig reads path over the defaults. A missing file is the defaults.
func LoadConfig(path string) (Config, error) {
	c := Defaults()
	data, err := os.ReadFile(path)
	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return c, err
	}
	if err == nil {
		dec := json.NewDecoder(bytes.NewReader(data))
		dec.DisallowUnknownFields()
		if err := dec.Decode(&c); err != nil {
			return c, fmt.Errorf("%s: %w", path, err)
		}
	}
	return c, c.prepare()
}

func (c *Config) prepare() error {
	if len(c.Users) == 0 {
		return errors.New("users must name at least one user")
	}
	for _, u := range c.Users {
		if !userPattern.MatchString(u) {
			return fmt.Errorf("users: %q is not a user name", u)
		}
	}
	if !userPattern.MatchString(c.ClientUser) {
		return fmt.Errorf("client_user: %q is not a user name", c.ClientUser)
	}
	if !servicePattern.MatchString(c.PAMService) {
		return fmt.Errorf("pam_service: %q is not a service name", c.PAMService)
	}
	l := c.Lockout
	if l.Failures < 1 || l.Failures > 100 || l.WindowSeconds < 1 || l.BlockSeconds < 1 || l.BlockSeconds > 86400 {
		return errors.New("lockout: failures 1..100, window_seconds >= 1, block_seconds 1..86400")
	}
	if c.GlobalPerMinute < 1 || c.GlobalPerMinute > 1000 {
		return errors.New("global_per_minute must be 1..1000")
	}

	c.allowFrom = map[string][]netip.Prefix{}
	for user, entries := range c.AllowFrom {
		if !c.checks(user) {
			return fmt.Errorf("allow_from: %q is not in users", user)
		}
		for _, e := range entries {
			p, err := parsePrefix(e)
			if err != nil {
				return fmt.Errorf("allow_from.%s: %q: %w", user, e, err)
			}
			c.allowFrom[user] = append(c.allowFrom[user], p)
		}
	}
	return nil
}

func parsePrefix(s string) (netip.Prefix, error) {
	if strings.Contains(s, "/") {
		p, err := netip.ParsePrefix(s)
		return p.Masked(), err
	}
	a, err := netip.ParseAddr(s)
	if err != nil {
		return netip.Prefix{}, err
	}
	return netip.PrefixFrom(a.Unmap(), a.Unmap().BitLen()), nil
}

// checks reports whether user is one whose password may be checked.
func (c *Config) checks(user string) bool {
	for _, u := range c.Users {
		if u == user {
			return true
		}
	}
	return false
}

// allowed reports whether user may log in from ip.
func (c *Config) allowed(user string, ip netip.Addr) bool {
	prefixes, limited := c.allowFrom[user]
	if !limited || len(prefixes) == 0 {
		return true
	}
	for _, p := range prefixes {
		if p.Contains(ip.Unmap()) {
			return true
		}
	}
	return false
}
