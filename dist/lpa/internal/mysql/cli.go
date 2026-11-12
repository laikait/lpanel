package mysql

import (
	"context"
	"fmt"
	"os"
	"strings"

	"lpa/internal/sysexec"
)

// InputRunner runs a tool with text on its standard input (sysexec.Exec).
type InputRunner interface {
	RunInput(ctx context.Context, input, tool string, args ...string) (int, string, error)
}

// CLI runs SQL through MariaDB's own client, as root over the local socket.
type CLI struct {
	Exec   InputRunner
	Socket string
}

// Run implements Client.
func (c CLI) Run(ctx context.Context, sql string) (string, error) {
	socket := c.Socket
	if socket == "" {
		socket = "/var/lib/mysql/mysql.sock"
	}
	// --no-defaults: no option file (root's ~/.my.cnf included) may change
	// where or how the client connects.
	code, out, err := c.Exec.RunInput(ctx, sql, "mariadb",
		"--no-defaults", "--protocol=socket", "--socket="+socket, "--user=root",
		"--batch", "--skip-column-names", "--default-character-set=utf8mb4")
	if err != nil {
		return "", err
	}
	if code != 0 {
		if out == "" {
			out = "no output"
		}
		return "", fmt.Errorf("mariadb exited with %d: %s", code, strings.TrimSpace(out))
	}
	return out, nil
}

// Installed implements Client.
func (c CLI) Installed() bool {
	path, ok := sysexec.Path("mariadb")
	if !ok {
		return false
	}
	_, err := os.Stat(path)
	return err == nil
}
