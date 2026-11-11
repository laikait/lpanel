// Package config reads /etc/lpa/lpa.json.
package config

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path"
	"strings"
)

// Default is where the installer writes the configuration.
const Default = "/etc/lpa/lpa.json"

// Config is the agent's configuration. Every field has a default.
type Config struct {
	// Socket is the Unix socket the panel connects to.
	Socket string `json:"socket"`
	// PanelUser is the account the panel runs as; only its uid is answered.
	PanelUser string `json:"panel_user"`
	// PanelKeyFile holds the panel's Ed25519 public key, base64.
	PanelKeyFile string `json:"panel_key_file"`
	// JournalDir holds one file per operation that changed the system.
	JournalDir string `json:"journal_dir"`
	// JournalDays is how long finished entries are kept.
	JournalDays int `json:"journal_days"`
	// MaxRunning caps operations running at once.
	MaxRunning int `json:"max_running"`
	// HomesBase is the folder every hosting account's home is made in.
	HomesBase string `json:"homes_base"`
	// Quota is "on" (disk quotas are set and must work; ADR-0008) or "off"
	// (containers and test machines only: the installer's --allow-no-quota).
	Quota string `json:"quota"`
	// Web is the web server the agent configures for accounts' sites:
	// "apache", or "off" (no web operations).
	Web string `json:"web"`
	// PanelPHP is the Remi PHP version the panel itself runs on ("83"). It
	// cannot be removed, nor the extensions the panel needs.
	PanelPHP string `json:"panel_php"`
}

// Defaults are the values a fresh install uses.
func Defaults() Config {
	return Config{
		Socket:       "/run/lpa/lpa.sock",
		PanelUser:    "lpanel",
		PanelKeyFile: "/etc/lpa/panel.pub",
		JournalDir:   "/var/lib/lpa/journal",
		JournalDays:  30,
		MaxRunning:   8,
		HomesBase:    "/home",
		Quota:        "on",
		Web:          "apache",
		PanelPHP:     "83",
	}
}

// Load reads file over the defaults. A missing file means all defaults;
// an unknown key is an error, so a typo is not silently ignored.
func Load(file string) (Config, error) {
	cfg := Defaults()
	data, err := os.ReadFile(file)
	if errors.Is(err, os.ErrNotExist) {
		return cfg, nil
	}
	if err != nil {
		return cfg, err
	}
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&cfg); err != nil {
		return cfg, fmt.Errorf("%s: %w", file, err)
	}
	return cfg, cfg.validate()
}

func (c Config) validate() error {
	for name, p := range map[string]string{"socket": c.Socket, "panel_key_file": c.PanelKeyFile, "journal_dir": c.JournalDir, "homes_base": c.HomesBase} {
		if !path.IsAbs(p) {
			return fmt.Errorf("%s must be an absolute path, not %q", name, p)
		}
	}
	if c.PanelUser == "" || c.PanelUser == "root" {
		return errors.New("panel_user must name the panel's own unprivileged account")
	}
	if path.Clean(c.HomesBase) == "/" {
		return errors.New("homes_base must not be /")
	}
	if c.Quota != "on" && c.Quota != "off" {
		return fmt.Errorf("quota must be \"on\" or \"off\", not %q", c.Quota)
	}
	if c.Web != "apache" && c.Web != "off" {
		return fmt.Errorf("web must be \"apache\" or \"off\", not %q", c.Web)
	}
	if len(c.PanelPHP) != 2 || c.PanelPHP < "50" || c.PanelPHP > "89" || strings.Trim(c.PanelPHP, "0123456789") != "" {
		return fmt.Errorf("panel_php must be two digits such as \"83\", not %q", c.PanelPHP)
	}
	if c.MaxRunning < 1 || c.JournalDays < 1 {
		return errors.New("max_running and journal_days must be at least 1")
	}
	return nil
}
