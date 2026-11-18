package backup

import (
	"bytes"
	"context"
	"fmt"
	"os/exec"
)

// Exec runs the real programs: only the two paths the Manager names, with
// the environment it builds, never through a shell.
type Exec struct{}

// Run implements Commander.
func (Exec) Run(ctx context.Context, c Command) (string, string, int, error) {
	cmd := exec.CommandContext(ctx, c.Path, c.Args...)
	cmd.Env = c.Env
	cmd.Dir = "/"
	var out, errOut bytes.Buffer
	cmd.Stdout = &out
	if c.Stdout != nil {
		cmd.Stdout = c.Stdout
	}
	cmd.Stderr = &errOut
	if c.Stdin != nil {
		cmd.Stdin = c.Stdin
	}
	err := cmd.Run()
	if exit, ok := err.(*exec.ExitError); ok && ctx.Err() == nil && exit.ExitCode() >= 0 {
		return out.String(), errOut.String(), exit.ExitCode(), nil
	}
	if err != nil {
		return out.String(), errOut.String(), -1, fmt.Errorf("%s: %w", c.Path, err)
	}
	return out.String(), errOut.String(), 0, nil
}
