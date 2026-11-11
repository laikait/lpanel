package web

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// PHP versions and their extensions, installed from Remi's repository with
// dnf. The panel names a version and extensions from the catalogue below;
// the agent turns them into package names itself, so nothing the panel sends
// ever reaches dnf as a package name or an option.

// PHPCatalogue is every version the panel may install, oldest first.
var PHPCatalogue = []string{"74", "80", "81", "82", "83", "84", "85"}

// phpExtensions maps an extension's name to its package, without the
// "phpXY-" prefix.
var phpExtensions = map[string]string{
	"apcu":      "php-pecl-apcu",
	"bcmath":    "php-bcmath",
	"gd":        "php-gd",
	"gmp":       "php-gmp",
	"imagick":   "php-pecl-imagick-im7",
	"intl":      "php-intl",
	"ioncube":   "php-ioncube-loader",
	"ldap":      "php-ldap",
	"mbstring":  "php-mbstring",
	"memcached": "php-pecl-memcached",
	"mysqlnd":   "php-mysqlnd",
	"opcache":   "php-opcache",
	"pdo":       "php-pdo",
	"pgsql":     "php-pgsql",
	"process":   "php-process",
	"redis":     "php-pecl-redis6",
	"soap":      "php-soap",
	"sodium":    "php-sodium",
	"tidy":      "php-tidy",
	"xml":       "php-xml",
	"zip":       "php-pecl-zip",
}

// phpBase is what every version has; it is not an extension one can remove.
var phpBase = []string{"php-fpm", "php-cli", "php-common"}

// phpDefaults come with a newly installed version, as the installer's do.
var phpDefaults = []string{"bcmath", "gd", "intl", "mbstring", "mysqlnd", "opcache", "process", "xml", "zip"}

// panelExtensions are what the panel itself runs on, in its own version:
// they cannot be removed from it. pdo is here because mysqlnd needs it.
var panelExtensions = []string{"intl", "mbstring", "mysqlnd", "opcache", "pdo", "process", "sodium", "xml"}

var rpmPHP = regexp.MustCompile(`^php([5-8][0-9])-(php-[a-z0-9-]+)$`)

// Codes of PHP operations the panel tells apart.
const (
	CodePanelPHP     = "panel_php"     // the panel itself needs it
	CodeInUse        = "in_use"        // sites still use this version
	CodeNotInstalled = "not_installed" // that version is not installed
	CodeDNF          = "dnf_failed"    // dnf could not do it (no network, no such package…)
)

// PHPArgs names one version.
type PHPArgs struct {
	Version string `json:"version"`
}

// ExtensionArgs installs and removes extensions of one version.
type ExtensionArgs struct {
	Version string   `json:"version"`
	Install []string `json:"install"`
	Remove  []string `json:"remove"`
}

// PHPState is one version as php.info sees it.
type PHPState struct {
	Version    string   `json:"version"`
	Installed  bool     `json:"installed"`
	Extensions []string `json:"extensions"`
	Pools      int      `json:"pools"`
	Running    bool     `json:"running"`
	// Panel is true for the version the panel runs on; Locked are the
	// extensions that cannot be removed from it.
	Panel  bool     `json:"panel"`
	Locked []string `json:"locked"`
}

// PHPInfo is php.info's answer: every version of the catalogue and the
// extensions one may choose from.
type PHPInfo struct {
	Versions   []PHPState `json:"versions"`
	Extensions []string   `json:"extensions"`
	Defaults   []string   `json:"defaults"`
}

// PHPInfo describes every version of the catalogue.
func (m *Manager) PHPInfo(ctx context.Context) (PHPInfo, error) {
	pkgs, err := m.phpPackages(ctx)
	if err != nil {
		return PHPInfo{}, err
	}
	info := PHPInfo{Extensions: extensionNames(), Defaults: phpDefaults}
	installed := m.installed()
	for _, v := range PHPCatalogue {
		info.Versions = append(info.Versions, m.phpState(ctx, v, installed, pkgs))
	}
	return info, nil
}

// InstallPHP installs a version with the default extensions. Installing one
// that is already there adds whatever of those it lacks.
func (m *Manager) InstallPHP(ctx context.Context, a PHPArgs) (PHPState, error) {
	if err := m.phpOn(a.Version); err != nil {
		return PHPState{}, err
	}
	m.pkgMu.Lock()
	defer m.pkgMu.Unlock()

	names := make([]string, 0, len(phpBase)+len(phpDefaults))
	for _, p := range phpBase {
		names = append(names, "php"+a.Version+"-"+p)
	}
	for _, e := range phpDefaults {
		names = append(names, "php"+a.Version+"-"+phpExtensions[e])
	}
	if err := m.dnf(ctx, "install", names); err != nil {
		return PHPState{}, err
	}
	// Remi's sample pool runs as apache; LPanel's pools are each account's.
	// Emptied rather than removed, so a package update keeps it empty.
	www := filepath.Join(m.PHPConf, "php"+a.Version, "php-fpm.d", "www.conf")
	if data, err := os.ReadFile(www); err == nil && strings.Contains(string(data), "[www]") {
		if err := writeAtomic(www, []byte("; Emptied by LPanel: every pool here is an account's, written by the agent.\n"), 0o644); err != nil {
			return PHPState{}, err
		}
	}
	return m.stateOf(ctx, a.Version)
}

// RemovePHP removes a version and its extensions. It is refused while any
// account's pool uses it, and for the version the panel runs on.
func (m *Manager) RemovePHP(ctx context.Context, a PHPArgs) (PHPState, error) {
	if err := m.phpOn(a.Version); err != nil {
		return PHPState{}, err
	}
	if a.Version == m.PanelPHP {
		return PHPState{}, protocol.Fail(CodePanelPHP, "the panel itself runs on PHP "+label(a.Version))
	}
	m.pkgMu.Lock()
	defer m.pkgMu.Unlock()
	// No site may start using it while it goes.
	m.mu.Lock()
	defer m.mu.Unlock()

	if pools, _ := filepath.Glob(filepath.Join(m.PHPConf, "php"+a.Version, "php-fpm.d", "lpanel-*.conf")); len(pools) > 0 {
		return PHPState{}, protocol.Fail(CodeInUse, fmt.Sprintf("%d accounts' sites still use PHP %s", len(pools), label(a.Version)))
	}
	pkgs, err := m.phpPackages(ctx)
	if err != nil {
		return PHPState{}, err
	}
	var names []string
	for _, p := range pkgs[a.Version] {
		names = append(names, "php"+a.Version+"-"+p)
	}
	if len(names) > 0 {
		// Not running anything by now; stopped so dnf does not have to.
		m.Run.Run(ctx, "systemctl", "disable", "--now", "--quiet", phpService(a.Version))
		if err := m.dnf(ctx, "remove", names); err != nil {
			return PHPState{}, err
		}
	}
	return m.stateOf(ctx, a.Version)
}

// PHPExtensions installs and removes extensions of an installed version,
// then reloads that version's PHP-FPM if any site uses it.
func (m *Manager) PHPExtensions(ctx context.Context, a ExtensionArgs) (PHPState, error) {
	if err := m.phpOn(a.Version); err != nil {
		return PHPState{}, err
	}
	for _, list := range [][]string{a.Install, a.Remove} {
		for _, e := range list {
			if _, ok := phpExtensions[e]; !ok {
				return PHPState{}, protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not an extension the panel offers", e))
			}
			if slices.Contains(a.Install, e) && slices.Contains(a.Remove, e) {
				return PHPState{}, protocol.Fail(protocol.CodeBadArgs, e+" is both installed and removed")
			}
		}
	}
	if a.Version == m.PanelPHP {
		for _, e := range a.Remove {
			if slices.Contains(panelExtensions, e) {
				return PHPState{}, protocol.Fail(CodePanelPHP, "the panel itself needs "+e+" in PHP "+label(a.Version))
			}
		}
	}
	if !slices.Contains(m.installed(), a.Version) {
		return PHPState{}, protocol.Fail(CodeNotInstalled, "PHP "+label(a.Version)+" is not installed")
	}
	m.pkgMu.Lock()
	defer m.pkgMu.Unlock()

	pkg := func(list []string) []string {
		out := make([]string, 0, len(list))
		for _, e := range list {
			out = append(out, "php"+a.Version+"-"+phpExtensions[e])
		}
		return out
	}
	if len(a.Install) > 0 {
		if err := m.dnf(ctx, "install", pkg(a.Install)); err != nil {
			return PHPState{}, err
		}
	}
	if len(a.Remove) > 0 {
		if err := m.dnf(ctx, "remove", pkg(a.Remove)); err != nil {
			return PHPState{}, err
		}
	}
	m.mu.Lock()
	pools, _ := filepath.Glob(filepath.Join(m.PHPConf, "php"+a.Version, "php-fpm.d", "lpanel-*.conf"))
	var err error
	if len(pools) > 0 {
		err = sysexec.Must(ctx, m.Run, "systemctl", "reload-or-restart", phpService(a.Version))
	}
	m.mu.Unlock()
	if err != nil {
		return PHPState{}, err
	}
	return m.stateOf(ctx, a.Version)
}

// -----------------------------------------------------------------------------

func (m *Manager) phpOn(version string) error {
	if err := m.on(); err != nil {
		return err
	}
	if !slices.Contains(PHPCatalogue, version) {
		return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not a PHP version the panel offers", version))
	}
	return nil
}

func (m *Manager) stateOf(ctx context.Context, version string) (PHPState, error) {
	pkgs, err := m.phpPackages(ctx)
	if err != nil {
		return PHPState{}, err
	}
	return m.phpState(ctx, version, m.installed(), pkgs), nil
}

func (m *Manager) phpState(ctx context.Context, v string, installed []string, pkgs map[string][]string) PHPState {
	st := PHPState{Version: v, Installed: slices.Contains(installed, v), Extensions: []string{}, Locked: []string{}, Panel: v == m.PanelPHP}
	for _, p := range pkgs[v] {
		for name, pkg := range phpExtensions {
			if pkg == p {
				st.Extensions = append(st.Extensions, name)
			}
		}
	}
	sort.Strings(st.Extensions)
	if st.Panel {
		st.Locked = panelExtensions
	}
	if st.Installed {
		pools, _ := filepath.Glob(filepath.Join(m.PHPConf, "php"+v, "php-fpm.d", "lpanel-*.conf"))
		st.Pools = len(pools)
		st.Running = m.active(ctx, phpService(v))
	}
	return st
}

// phpPackages lists the installed packages of each PHP version, without
// the "phpXY-" prefix: "83" → ["php-cli", "php-gd", …].
func (m *Manager) phpPackages(ctx context.Context) (map[string][]string, error) {
	code, out, err := m.Run.Run(ctx, "rpm", "-qa", "--qf", "%{NAME}\\n")
	if err != nil || code != 0 {
		return nil, fmt.Errorf("rpm could not list packages: %v %s", err, lastLines(out))
	}
	pkgs := map[string][]string{}
	for _, line := range strings.Split(out, "\n") {
		if mm := rpmPHP.FindStringSubmatch(strings.TrimSpace(line)); mm != nil {
			pkgs[mm[1]] = append(pkgs[mm[1]], mm[2])
		}
	}
	for v := range pkgs {
		sort.Strings(pkgs[v])
	}
	return pkgs, nil
}

func (m *Manager) dnf(ctx context.Context, verb string, names []string) error {
	args := []string{"-y", "-q", "--setopt=install_weak_deps=False", verb}
	args = append(args, names...)
	code, out, err := m.Run.Run(ctx, "dnf", args...)
	if err != nil {
		return err
	}
	if code != 0 {
		return protocol.Fail(CodeDNF, "dnf "+verb+" failed: "+lastLines(out))
	}
	return nil
}

func extensionNames() []string {
	names := make([]string, 0, len(phpExtensions))
	for n := range phpExtensions {
		names = append(names, n)
	}
	sort.Strings(names)
	return names
}

// label turns "83" into "8.3".
func label(v string) string {
	if len(v) == 2 {
		return v[:1] + "." + v[1:]
	}
	return v
}
