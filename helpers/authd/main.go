// Command lpanel-authd checks the system password of an allowlisted user
// (normally only root) for the LPanel login page, over a local socket only the
// panel may use. It is the one root process of the panel; see
// design/adr/0010-root-login-via-authd.md.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"os/user"
	"strconv"
	"syscall"
	"time"
)

func main() {
	configPath := flag.String("config", "/etc/lpanel/authd.json", "configuration file")
	listen := flag.String("listen", "", "socket path to create (for tests; production uses socket activation)")
	flag.Parse()

	log := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	if err := run(*configPath, *listen, log); err != nil {
		log.Error("lpanel-authd stopped", "err", err)
		os.Exit(1)
	}
}

func run(configPath, listen string, log *slog.Logger) error {
	cfg, err := LoadConfig(configPath)
	if err != nil {
		return err
	}
	client, err := user.Lookup(cfg.ClientUser)
	if err != nil {
		return fmt.Errorf("client_user %q: %w", cfg.ClientUser, err)
	}
	uid, err := strconv.ParseUint(client.Uid, 10, 32)
	if err != nil {
		return err
	}

	ln, err := listener(listen)
	if err != nil {
		return err
	}
	s := &Server{
		Config:    cfg,
		Verifier:  newPAMVerifier(cfg.PAMService),
		ClientUID: uint32(uid),
		PeerUID:   peerUID,
		Now:       time.Now,
		Log:       log,
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer stop()
	go func() { <-ctx.Done(); ln.Close() }()

	log.Info("lpanel-authd ready", "users", cfg.Users, "client_user", cfg.ClientUser, "pam_service", cfg.PAMService)
	return s.Serve(ln)
}

// listener takes the socket systemd passed (LISTEN_FDS), or creates path for tests.
func listener(path string) (net.Listener, error) {
	if path != "" {
		_ = os.Remove(path)
		ln, err := net.Listen("unix", path)
		if err != nil {
			return nil, err
		}
		return ln, os.Chmod(path, 0o660)
	}
	if os.Getenv("LISTEN_PID") != strconv.Itoa(os.Getpid()) || os.Getenv("LISTEN_FDS") != "1" {
		return nil, errors.New("not started by lpanel-authd.socket and no --listen given")
	}
	os.Unsetenv("LISTEN_PID")
	os.Unsetenv("LISTEN_FDS")
	os.Unsetenv("LISTEN_FDNAMES")
	f := os.NewFile(3, "lpanel-authd.socket")
	defer f.Close()
	return net.FileListener(f)
}
