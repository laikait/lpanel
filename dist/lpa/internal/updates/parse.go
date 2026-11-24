package updates

import (
	"regexp"
	"sort"
	"strings"
)

// The query formats the agent asks dnf and rpm for: one package per line,
// fields split by "|", which no package name, version or repository id holds.
const (
	repoqueryFormat = "%{name}|%{arch}|%{evr}|%{repoid}"
	rpmFormat       = "%{NAME}|%{ARCH}|%{EPOCHNUM}:%{VERSION}-%{RELEASE}\\n"
)

// Package is one update dnf offers.
type Package struct {
	Name string `json:"name"`
	Arch string `json:"arch"`
	// From is the installed version, To the one offered ([epoch:]version-release).
	From string `json:"from"`
	To   string `json:"to"`
	Repo string `json:"repo"`
	// Advisory is the erratum that brings it (ALSA-2026:1234), when the
	// repository publishes errata; Kind is security, bugfix or enhancement;
	// Severity is Critical, Important, Moderate or Low for a security one.
	Advisory string `json:"advisory"`
	Kind     string `json:"kind"`
	Severity string `json:"severity"`
}

// Security reports whether a security erratum brings the update.
func (p Package) Security() bool { return p.Kind == "security" }

// key is how a package is told apart from another of the same name.
func key(name, arch string) string { return name + "." + arch }

// parseRepoquery reads `dnf repoquery --upgrades --qf repoqueryFormat`.
func parseRepoquery(out string) []Package {
	var list []Package
	seen := map[string]bool{}
	for _, line := range strings.Split(out, "\n") {
		f := strings.Split(strings.TrimSpace(line), "|")
		if len(f) != 4 || !nameRule.MatchString(f[0]) || f[1] == "" || f[2] == "" {
			continue
		}
		k := key(f[0], f[1])
		if seen[k] {
			continue
		}
		seen[k] = true
		list = append(list, Package{Name: f[0], Arch: f[1], To: f[2], Repo: f[3]})
	}
	sort.Slice(list, func(i, j int) bool { return key(list[i].Name, list[i].Arch) < key(list[j].Name, list[j].Arch) })
	return list
}

// parseInstalled reads `rpm -qa --qf rpmFormat`: name.arch to its versions
// (more than one for a kernel), an epoch of 0 left out as dnf does.
func parseInstalled(out string) map[string][]string {
	res := map[string][]string{}
	for _, line := range strings.Split(out, "\n") {
		f := strings.Split(strings.TrimSpace(line), "|")
		if len(f) != 3 || f[0] == "" {
			continue
		}
		k := key(f[0], f[1])
		res[k] = append(res[k], strings.TrimPrefix(f[2], "0:"))
	}
	for k := range res {
		sort.Strings(res[k])
	}
	return res
}

// advisory is one line of `dnf updateinfo list`.
type advisory struct{ ID, Kind, Severity string }

// "ALSA-2026:1234 Important/Sec. openssl-libs-1:3.0.7-27.el9.x86_64"
var updateinfoLine = regexp.MustCompile(`^(\S+)\s+(\S+)\s+(\S+)$`)

// parseUpdateinfo reads `dnf updateinfo list --updates`: name.arch to the
// most serious erratum that brings an update of it.
func parseUpdateinfo(out string) map[string]advisory {
	rank := map[string]int{"Critical": 4, "Important": 3, "Moderate": 2, "Low": 1}
	res := map[string]advisory{}
	for _, line := range strings.Split(out, "\n") {
		g := updateinfoLine.FindStringSubmatch(strings.TrimSpace(line))
		if g == nil {
			continue
		}
		name, arch, ok := splitNEVRA(g[3])
		if !ok {
			continue
		}
		a := advisory{ID: g[1]}
		switch t := g[2]; {
		case strings.HasSuffix(t, "/Sec."):
			a.Kind = "security"
			a.Severity = strings.TrimSuffix(t, "/Sec.")
			if _, known := rank[a.Severity]; !known {
				a.Severity = ""
			}
		case t == "bugfix", t == "enhancement", t == "newpackage":
			a.Kind = t
		default:
			continue
		}
		k := key(name, arch)
		old, had := res[k]
		better := !had || (a.Kind == "security" && old.Kind != "security") ||
			(a.Kind == "security" && old.Kind == "security" && rank[a.Severity] > rank[old.Severity])
		if better {
			res[k] = a
		}
	}
	return res
}

// splitNEVRA takes name-[epoch:]version-release.arch apart, keeping the name
// and the architecture.
func splitNEVRA(s string) (name, arch string, ok bool) {
	dot := strings.LastIndex(s, ".")
	if dot <= 0 {
		return "", "", false
	}
	arch, rest := s[dot+1:], s[:dot]
	rel := strings.LastIndex(rest, "-")
	if rel <= 0 {
		return "", "", false
	}
	ver := strings.LastIndex(rest[:rel], "-")
	if ver <= 0 {
		return "", "", false
	}
	return rest[:ver], arch, true
}

// parseNeedsRestarting reads `dnf needs-restarting -s`: the units whose
// processes still run old code.
func parseNeedsRestarting(out string) []string {
	var units []string
	seen := map[string]bool{}
	for _, line := range strings.Split(out, "\n") {
		u := strings.TrimSpace(line)
		if unitRule.MatchString(u) && !seen[u] {
			seen[u] = true
			units = append(units, u)
		}
	}
	sort.Strings(units)
	return units
}

// parseReboot reads `dnf needs-restarting -r`: the list of what was updated
// since the server started that only a reboot brings into use.
func parseReboot(out string) []string {
	var why []string
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "* ") {
			why = append(why, strings.TrimSpace(strings.TrimPrefix(line, "* ")))
		}
	}
	return why
}

// trimVersion turns "8.3.14-1.el9.remi" into "8.3.14" (and "1:2.4" into "2.4").
func trimVersion(evr string) string {
	if _, v, ok := strings.Cut(evr, ":"); ok {
		evr = v
	}
	v, _, _ := strings.Cut(evr, "-")
	return v
}
