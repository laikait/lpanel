// Package sysexec runs the system's own administration tools (useradd,
// usermod, pkill…) for operations that need them.
//
// It is not a way to run arbitrary commands: callers name a tool from Tools,
// arguments are passed as a list (never through a shell), and the environment
// is replaced with a fixed minimal one, so nothing from the agent's own
// environment or the panel's arguments can change which program runs.
package sysexec

import (
	"bytes"
	"context"
	"fmt"
	"os/exec"
	"regexp"
	"strings"
)

// Tools are the only programs operations may run, by absolute path.
var Tools = map[string]string{
	"useradd": "/usr/sbin/useradd",
	"usermod": "/usr/sbin/usermod",
	"userdel": "/usr/sbin/userdel",
	"pkill":   "/usr/bin/pkill",
	// Gives files the labels the SELinux policy says they should have.
	"restorecon": "/usr/sbin/restorecon",
	// The web server: config tests, and reloads through systemd.
	"httpd":     "/usr/sbin/httpd",
	"systemctl": "/usr/bin/systemctl",
	"nginx":     "/usr/sbin/nginx",
	// PHP versions and extensions from Remi's repository. Package names are
	// made by the agent from its own catalogue, never taken from the panel.
	"dnf": "/usr/bin/dnf",
	"rpm": "/usr/bin/rpm",
}

// phpFPM is the one family of tools with a version in the name: "php-fpm83"
// is Remi's PHP 8.3 FPM. The version is two digits, so the path cannot be
// steered anywhere else.
var phpFPM = regexp.MustCompile(`^php-fpm([5-8][0-9])$`)

// PHPRoot is where Remi installs each PHP version (tests change it).
var PHPRoot = "/opt/remi"

// Path is the absolute path of an allowed tool.
func Path(tool string) (string, bool) {
	if p, ok := Tools[tool]; ok {
		return p, true
	}
	if m := phpFPM.FindStringSubmatch(tool); m != nil {
		return PHPRoot + "/php" + m[1] + "/root/usr/sbin/php-fpm", true
	}
	return "", false
}

// env is the whole environment a tool sees.
var env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C", "LC_ALL=C"}

// Runner runs one tool. Tests replace it to see what would have run.
type Runner interface {
	// Run returns the tool's exit code and its output. err is set only when
	// the tool could not be run at all (missing, killed, timed out).
	Run(ctx context.Context, tool string, args ...string) (code int, output string, err error)
}

// Exec runs the real tools.
type Exec struct{}

// Run implements Runner.
func (Exec) Run(ctx context.Context, tool string, args ...string) (int, string, error) {
	path, ok := Path(tool)
	if !ok {
		return -1, "", fmt.Errorf("sysexec: %q is not an allowed tool", tool)
	}
	cmd := exec.CommandContext(ctx, path, args...)
	cmd.Env = env
	cmd.Dir = "/"
	var out bytes.Buffer
	cmd.Stdout = &out
	cmd.Stderr = &out
	err := cmd.Run()
	text := strings.TrimSpace(out.String())
	if exit, ok := err.(*exec.ExitError); ok && ctx.Err() == nil && exit.ExitCode() >= 0 {
		return exit.ExitCode(), text, nil
	}
	if err != nil {
		return -1, text, fmt.Errorf("%s: %w", tool, err)
	}
	return 0, text, nil
}

// Must runs a tool and turns a non-zero exit into an error carrying its output.
func Must(ctx context.Context, r Runner, tool string, args ...string) error {
	code, out, err := r.Run(ctx, tool, args...)
	if err != nil {
		return err
	}
	if code != 0 {
		if out == "" {
			out = "no output"
		}
		return fmt.Errorf("%s exited with %d: %s", tool, code, out)
	}
	return nil
}
