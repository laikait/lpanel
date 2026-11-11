package web

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

const rpmList = "bash\nphp83-php-cli\nphp83-php-common\nphp83-php-fpm\nphp83-php-intl\nphp83-php-pecl-zip\nphp83-runtime\nphp82-php-fpm\nphp82-php-gd\nphpmyadmin\n"

func TestPHPInfoListsTheCatalogueWithWhatIsInstalled(t *testing.T) {
	m, run, _, _ := setup(t)
	run.out["rpm -qa"] = rpmList

	info, err := m.PHPInfo(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(info.Versions) != len(PHPCatalogue) || !slices.Contains(info.Extensions, "redis") || !slices.Contains(info.Defaults, "zip") {
		t.Fatalf("%+v", info)
	}
	byVersion := map[string]PHPState{}
	for _, v := range info.Versions {
		byVersion[v.Version] = v
	}
	if v := byVersion["83"]; !v.Installed || !v.Panel || !slices.Equal(v.Extensions, []string{"intl", "zip"}) || !slices.Contains(v.Locked, "mysqlnd") {
		t.Fatalf("8.3: %+v", v)
	}
	if v := byVersion["82"]; !v.Installed || v.Panel || !slices.Equal(v.Extensions, []string{"gd"}) || len(v.Locked) != 0 {
		t.Fatalf("8.2: %+v", v)
	}
	if v := byVersion["84"]; v.Installed || len(v.Extensions) != 0 {
		t.Fatalf("8.4: %+v", v)
	}
}

func TestInstallingAVersionTakesItsDefaultsAndEmptiesTheSamplePool(t *testing.T) {
	m, run, _, root := setup(t)
	www := filepath.Join(m.PHPConf, "php84", "php-fpm.d", "www.conf")
	run.after = func(call string) {
		if strings.HasPrefix(call, "dnf ") {
			// What the packages would put there.
			os.MkdirAll(filepath.Dir(www), 0o755)
			os.WriteFile(www, []byte("[www]\nuser = apache\n"), 0o644)
			bin := filepath.Join(root, "opt", "php84", "root", "usr", "sbin")
			os.MkdirAll(bin, 0o755)
			os.WriteFile(filepath.Join(bin, "php-fpm"), nil, 0o755)
		}
	}

	st, err := m.InstallPHP(context.Background(), PHPArgs{Version: "84"})
	if err != nil || !st.Installed {
		t.Fatalf("%+v %v", st, err)
	}
	want := "dnf -y -q --setopt=install_weak_deps=False install php84-php-fpm php84-php-cli php84-php-common php84-php-bcmath php84-php-gd"
	if !strings.HasPrefix(run.calls[0], want) || !strings.Contains(run.calls[0], "php84-php-pecl-zip") {
		t.Fatalf("%v", run.calls)
	}
	if data, _ := os.ReadFile(www); strings.Contains(string(data), "[www]") {
		t.Fatal("the sample pool would run as apache")
	}
}

func TestPHPVersionsAndExtensionsComeOnlyFromTheCatalogue(t *testing.T) {
	m, run, _, _ := setup(t)
	ctx := context.Background()
	for name, err := range map[string]error{
		"a version not offered":  func() error { _, err := m.InstallPHP(ctx, PHPArgs{Version: "56"}); return err }(),
		"a version with a space": func() error { _, err := m.InstallPHP(ctx, PHPArgs{Version: "8 3"}); return err }(),
		"an option for dnf":      func() error { _, err := m.InstallPHP(ctx, PHPArgs{Version: "--best"}); return err }(),
		"an unknown extension": func() error {
			_, err := m.PHPExtensions(ctx, ExtensionArgs{Version: "82", Install: []string{"php82-php-evil"}})
			return err
		}(),
		"both ways": func() error {
			_, err := m.PHPExtensions(ctx, ExtensionArgs{Version: "82", Install: []string{"gd"}, Remove: []string{"gd"}})
			return err
		}(),
	} {
		if code(err) != protocol.CodeBadArgs {
			t.Errorf("%s: %v", name, err)
		}
	}
	if run.ran("dnf") != 0 {
		t.Fatalf("dnf ran: %v", run.calls)
	}
}

func TestThePanelsOwnPHPAndAVersionInUseStay(t *testing.T) {
	m, run, _, _ := setup(t)
	ctx := context.Background()
	run.out["rpm -qa"] = rpmList

	if _, err := m.RemovePHP(ctx, PHPArgs{Version: "83"}); code(err) != CodePanelPHP {
		t.Fatalf("the panel's PHP: %v", err)
	}
	if _, err := m.PHPExtensions(ctx, ExtensionArgs{Version: "83", Remove: []string{"mysqlnd"}}); code(err) != CodePanelPHP {
		t.Fatalf("the panel's extension: %v", err)
	}
	if _, err := m.Apply(ctx, shop("82")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.RemovePHP(ctx, PHPArgs{Version: "82"}); code(err) != CodeInUse {
		t.Fatalf("in use: %v", err)
	}
	if run.ran("dnf") != 0 {
		t.Fatalf("dnf ran: %v", run.calls)
	}

	// Once no site uses it, it goes: exactly its own packages.
	if _, err := m.Remove(ctx, NameArgs{Username: "shop1"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.RemovePHP(ctx, PHPArgs{Version: "82"}); err != nil {
		t.Fatal(err)
	}
	if run.ran("dnf -y -q --setopt=install_weak_deps=False remove php82-php-fpm php82-php-gd") != 1 || run.ran("systemctl disable --now --quiet php82-php-fpm") != 1 {
		t.Fatalf("%v", run.calls)
	}
}

func TestExtensionsReloadThePHPThatSitesUse(t *testing.T) {
	m, run, _, _ := setup(t)
	ctx := context.Background()
	run.out["rpm -qa"] = rpmList
	if _, err := m.Apply(ctx, shop("82")); err != nil {
		t.Fatal(err)
	}
	before := run.ran("systemctl reload-or-restart php82-php-fpm")

	if _, err := m.PHPExtensions(ctx, ExtensionArgs{Version: "82", Install: []string{"redis", "soap"}, Remove: []string{"gd"}}); err != nil {
		t.Fatal(err)
	}
	if run.ran("dnf -y -q --setopt=install_weak_deps=False install php82-php-pecl-redis6 php82-php-soap") != 1 ||
		run.ran("dnf -y -q --setopt=install_weak_deps=False remove php82-php-gd") != 1 ||
		run.ran("systemctl reload-or-restart php82-php-fpm") != before+1 {
		t.Fatalf("%v", run.calls)
	}

	// A version no site uses is left alone; one not installed is refused.
	if _, err := m.PHPExtensions(ctx, ExtensionArgs{Version: "83", Install: []string{"redis"}}); err != nil || run.ran("systemctl reload-or-restart php83") != 0 {
		t.Fatalf("%v %v", err, run.calls)
	}
	if _, err := m.PHPExtensions(ctx, ExtensionArgs{Version: "84", Install: []string{"redis"}}); code(err) != CodeNotInstalled {
		t.Fatal(err)
	}
}

func TestADNFFailureSaysWhy(t *testing.T) {
	m, run, _, _ := setup(t)
	run.fail["dnf"] = "Error: Unable to find a match: php84-php-pecl-imagick-im7"

	_, err := m.InstallPHP(context.Background(), PHPArgs{Version: "84"})
	if code(err) != CodeDNF || !strings.Contains(err.Error(), "Unable to find a match") {
		t.Fatal(err)
	}
}
