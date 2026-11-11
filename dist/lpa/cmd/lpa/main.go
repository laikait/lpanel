// Command lpa is LPanel's privileged agent. It runs as root, listens on a
// Unix socket, and carries out the panel's signed requests from a fixed list
// of operations (design/ARCHITECTURE.md, ADR-0003).
//
//	lpa serve [-config /etc/lpa/lpa.json]   run the agent (systemd does this)
//	lpa facts                                print this server's facts as JSON
//	lpa ops                                  list the operations it knows
//	lpa version                              print the version
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"

	"lpa/internal/accounts"
	"lpa/internal/config"
	"lpa/internal/journal"
	"lpa/internal/ops"
	"lpa/internal/peer"
	"lpa/internal/protocol"
	"lpa/internal/quota"
	"lpa/internal/server"
	"lpa/internal/sysexec"
	"lpa/internal/sysinfo"
	"lpa/internal/web"
)

// version is set at build time: -ldflags "-X main.version=0.1.0".
var version = "0.0.0-dev"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	var err error
	switch os.Args[1] {
	case "serve":
		err = serve(os.Args[2:])
	case "facts":
		err = printJSON(ops.ReadFacts(sysinfo.New(), version, time.Now()))
	case "ops":
		fmt.Println(strings.Join(registry(config.Defaults(), nil, time.Now()).Names(), "\n"))
	case "version", "-v", "--version":
		fmt.Println(version)
	default:
		usage()
		os.Exit(2)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "lpa:", err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, "usage: lpa serve [-config path] | facts | ops | version")
}

func registry(cfg config.Config, log *slog.Logger, started time.Time) *ops.Registry {
	if log == nil {
		log = slog.New(slog.NewJSONHandler(os.Stderr, nil))
	}
	var quotas quota.Quotas = quota.Kernel{}
	if cfg.Quota == "off" {
		quotas = quota.Off{}
	}
	reg := ops.NewRegistry()
	ops.System(reg, sysinfo.New(), version, started)
	acc := &accounts.Manager{
		HomesBase: cfg.HomesBase, Files: accounts.SystemFiles, Run: sysexec.Exec{}, Quota: quotas, Log: log,
		SELinux: selinuxOn(),
	}
	sites := &web.Manager{
		Server: cfg.Web, Run: sysexec.Exec{}, Log: log, Owner: acc.Owner, SELinux: acc.SELinux,
		PanelPHP: cfg.PanelPHP, TrafficState: filepath.Join(filepath.Dir(cfg.JournalDir), "traffic.json"),
	}
	sites.Defaults()
	acc.BeforeRemove = sites.RemoveFor
	ops.Accounts(reg, acc)
	ops.Web(reg, sites)
	return reg
}

func serve(args []string) error {
	fs := flag.NewFlagSet("serve", flag.ContinueOnError)
	path := fs.String("config", config.Default, "configuration file")
	if err := fs.Parse(args); err != nil {
		return err
	}
	log := slog.New(slog.NewJSONHandler(os.Stderr, nil))

	cfg, err := config.Load(*path)
	if err != nil {
		return err
	}
	if os.Geteuid() != 0 {
		return errors.New("lpa serve must run as root")
	}
	panel, err := user.Lookup(cfg.PanelUser)
	if err != nil {
		return fmt.Errorf("panel user %q: %w", cfg.PanelUser, err)
	}
	panelUID, _ := strconv.Atoi(panel.Uid)
	panelGID, _ := strconv.Atoi(panel.Gid)

	keyText, err := os.ReadFile(cfg.PanelKeyFile)
	if err != nil {
		return fmt.Errorf("panel key: %w (the installer writes it with: lpanel agent:keygen)", err)
	}
	key, err := protocol.ParsePublicKey(strings.TrimSpace(string(keyText)))
	if err != nil {
		return fmt.Errorf("%s: %w", cfg.PanelKeyFile, err)
	}

	j, err := journal.Open(cfg.JournalDir)
	if err != nil {
		return fmt.Errorf("journal: %w", err)
	}
	keep := time.Duration(cfg.JournalDays) * 24 * time.Hour
	if n, err := j.Prune(keep); err == nil && n > 0 {
		log.Info("pruned the journal", "removed", n)
	}

	ln, err := listen(cfg.Socket, panelGID)
	if err != nil {
		return err
	}

	srv := &server.Server{
		Registry: registry(cfg, log, time.Now()), Journal: j, PanelKey: key,
		AllowedUIDs: []int{panelUID}, PeerUID: peer.UID, Now: time.Now, Log: log,
		MaxRunning: cfg.MaxRunning,
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	go func() {
		ticker := time.NewTicker(time.Hour)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				ln.Close()
				return
			case <-ticker.C:
				_, _ = j.Prune(keep)
			}
		}
	}()

	log.Info("lpa started", "version", version, "socket", cfg.Socket, "panel_uid", panelUID)
	err = srv.Serve(ln)

	// Let background operations finish so their journal entries are not left
	// "running"; systemd's TimeoutStopSec bounds how long this may take.
	done := make(chan struct{})
	go func() { srv.Wait(); close(done) }()
	select {
	case <-done:
	case <-time.After(60 * time.Second):
		log.Warn("stopped with operations still running; they will show as interrupted")
	}
	log.Info("lpa stopped")
	return err
}

func printJSON(v any) error {
	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", "  ")
	return enc.Encode(v)
}

// selinuxOn reports whether SELinux is on (enforcing or permissive), from the
// kernel's own selinuxfs.
func selinuxOn() bool {
	_, err := os.Stat("/sys/fs/selinux/enforce")
	return err == nil
}
