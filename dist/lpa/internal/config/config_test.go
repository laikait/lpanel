package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoad(t *testing.T) {
	dir := t.TempDir()
	write := func(body string) string {
		p := filepath.Join(dir, "lpa.json")
		if err := os.WriteFile(p, []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		return p
	}

	missing, err := Load(filepath.Join(dir, "absent.json"))
	if err != nil || missing != Defaults() {
		t.Fatalf("a missing file should mean defaults: %+v %v", missing, err)
	}

	cfg, err := Load(write(`{"max_running": 3}`))
	if err != nil || cfg.MaxRunning != 3 || cfg.Socket != Defaults().Socket {
		t.Fatalf("got %+v %v", cfg, err)
	}

	for name, body := range map[string]string{
		"typo":          `{"max_runing": 3}`,
		"relative path": `{"socket": "lpa.sock"}`,
		"root as panel": `{"panel_user": "root"}`,
		"homes in /":    `{"homes_base": "/"}`,
		"quota maybe":   `{"quota": "maybe"}`,
	} {
		if _, err := Load(write(body)); err == nil {
			t.Errorf("%s was accepted", name)
		}
	}
}
