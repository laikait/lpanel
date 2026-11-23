package web

import (
	"strings"
	"testing"
)

func TestProgramsAScriptStartsUseTheAccountsOwnTemporaryFolder(t *testing.T) {
	m, _, _, _ := setup(t)
	applied(t, m, wafSite(nil))
	pool := read(t, m.poolFile("83", "shop1"))
	for _, k := range []string{"TMP", "TMPDIR", "TEMP"} {
		want := "env[" + k + "] = " + strings.TrimSuffix(strings.Split(strings.Split(pool, "chdir = ")[1], "\n")[0], "/") + "/tmp"
		if !strings.Contains(pool, want) {
			t.Fatalf("missing %q:\n%s", want, pool)
		}
	}
}
