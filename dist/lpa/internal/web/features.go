package web

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// The per-site features an owner sets in the User panel. Everything here
// ends up inside the account's own VirtualHost, so each value is checked
// against a rule that leaves no room for a quote, a space, a newline or a
// directive of its own.

// Redirect sends a path, and everything under it, elsewhere (mod_alias
// Redirect: the rest of the path is kept).
type Redirect struct {
	Path string `json:"path"`
	To   string `json:"to"`
	Code int    `json:"code"`
}

// Protected is a folder that asks for a user name and password.
type Protected struct {
	// Path is relative to the site's folder; "" protects the whole site.
	Path  string `json:"path"`
	Realm string `json:"realm"`
	Users []User `json:"users"`
}

// User is one login of a protected folder. Hash is bcrypt ($2y$), made by
// the panel; the agent never sees the password.
type User struct {
	Name string `json:"name"`
	Hash string `json:"hash"`
}

// Hotlink refuses files of these kinds to pages on other sites.
type Hotlink struct {
	Extensions []string `json:"extensions"`
	// Allow lists other hosts that may show the files; the site's own names
	// always may.
	Allow []string `json:"allow"`
}

var (
	urlPathRule   = regexp.MustCompile(`^/[A-Za-z0-9._~/%+@!$&()*,;=:-]{0,500}$`)
	targetRule    = regexp.MustCompile(`^https?://[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?(/[A-Za-z0-9._~/%?#&=+@!$()*,;:-]*)?$`)
	errorPageRule = regexp.MustCompile(`^/[A-Za-z0-9._/-]{1,200}$`)
	realmRule     = regexp.MustCompile(`^[A-Za-z0-9 ._-]{1,60}$`)
	loginRule     = regexp.MustCompile(`^[A-Za-z0-9._-]{1,32}$`)
	bcryptRule    = regexp.MustCompile(`^\$2y\$(0[4-9]|1[0-4])\$[./A-Za-z0-9]{53}$`)
	extensionRule = regexp.MustCompile(`^[a-z0-9]{1,8}$`)
)

// ErrorCodes are the statuses a site may have its own page for.
var ErrorCodes = []string{"400", "401", "403", "404", "500", "503"}

// phpSettings are the php.ini directives an owner may set, each with the
// rule its value must match, and whether it is an On/Off flag.
var phpSettings = map[string]struct {
	rule *regexp.Regexp
	flag bool
}{
	"memory_limit":           {regexp.MustCompile(`^([1-9][0-9]{1,4}M|[1-9]G)$`), false},
	"upload_max_filesize":    {regexp.MustCompile(`^([1-9][0-9]{0,4}M|[1-9]G)$`), false},
	"post_max_size":          {regexp.MustCompile(`^([1-9][0-9]{0,4}M|[1-9]G)$`), false},
	"max_execution_time":     {regexp.MustCompile(`^[0-9]{1,4}$`), false},
	"max_input_time":         {regexp.MustCompile(`^(-1|[0-9]{1,4})$`), false},
	"max_input_vars":         {regexp.MustCompile(`^[1-9][0-9]{2,5}$`), false},
	"session.gc_maxlifetime": {regexp.MustCompile(`^[1-9][0-9]{1,6}$`), false},
	"date.timezone":          {regexp.MustCompile(`^[A-Za-z_]{1,32}(/[A-Za-z0-9_+-]{1,32}){0,2}$`), false},
	"error_reporting":        {regexp.MustCompile(`^E_ALL( & ~E_(DEPRECATED|NOTICE|STRICT|WARNING))*$`), false},
	"display_errors":         {regexp.MustCompile(`^(On|Off)$`), true},
	"log_errors":             {regexp.MustCompile(`^(On|Off)$`), true},
	"allow_url_fopen":        {regexp.MustCompile(`^(On|Off)$`), true},
	"short_open_tag":         {regexp.MustCompile(`^(On|Off)$`), true},
	"output_buffering":       {regexp.MustCompile(`^(On|Off|[0-9]{1,6})$`), false},
}

func (s *Site) checkFeatures() error {
	switch s.Canonical {
	case "", "bare":
	case "www":
		if !containsString(s.Aliases, "www."+s.Name) {
			return badArgs("%s has no www. name to send visitors to", s.Name)
		}
	default:
		return badArgs("canonical must be \"\", \"www\" or \"bare\", not %q", s.Canonical)
	}
	if len(s.Redirects) > 100 {
		return badArgs("%s has more than 100 redirects", s.Name)
	}
	seen := map[string]bool{}
	for _, r := range s.Redirects {
		if !urlPathRule.MatchString(r.Path) || strings.Contains(r.Path, "/../") || strings.HasSuffix(r.Path, "/..") {
			return badArgs("redirect path %q must start with / and hold only URL characters", r.Path)
		}
		if seen[r.Path] {
			return badArgs("%s redirects %s twice", s.Name, r.Path)
		}
		seen[r.Path] = true
		if len(r.To) > 2048 || !targetRule.MatchString(r.To) {
			return badArgs("redirect target %q must be an http:// or https:// address", r.To)
		}
		if r.Code != 301 && r.Code != 302 {
			return badArgs("redirect code must be 301 or 302, not %d", r.Code)
		}
	}
	for code, page := range s.ErrorPages {
		if !containsString(ErrorCodes, code) {
			return badArgs("there is no error page for %q; only %s", code, strings.Join(ErrorCodes, ", "))
		}
		if !errorPageRule.MatchString(page) || strings.Contains(page, "..") {
			return badArgs("error page %q must be a path on the site such as /errors/404.html", page)
		}
	}
	if len(s.Protected) > 50 {
		return badArgs("%s has more than 50 protected folders", s.Name)
	}
	paths := map[string]bool{}
	for _, p := range s.Protected {
		if p.Path != "" {
			if err := checkRelative(p.Path); err != nil {
				return err
			}
		}
		if paths[p.Path] {
			return badArgs("%s protects %q twice", s.Name, p.Path)
		}
		paths[p.Path] = true
		if !realmRule.MatchString(p.Realm) {
			return badArgs("the name shown at the password prompt %q may hold letters, digits, spaces, dots, dashes and underscores", p.Realm)
		}
		if len(p.Users) > 100 {
			return badArgs("a protected folder has at most 100 users")
		}
		logins := map[string]bool{}
		for _, u := range p.Users {
			if !loginRule.MatchString(u.Name) || logins[u.Name] {
				return badArgs("user name %q is not valid or appears twice", u.Name)
			}
			logins[u.Name] = true
			if !bcryptRule.MatchString(u.Hash) {
				return badArgs("the password of %s must be a bcrypt ($2y$) hash", u.Name)
			}
		}
	}
	if h := s.Hotlink; h != nil {
		if len(h.Extensions) == 0 || len(h.Extensions) > 20 {
			return badArgs("hotlink protection needs 1 to 20 file kinds")
		}
		for _, e := range h.Extensions {
			if !extensionRule.MatchString(e) {
				return badArgs("file kind %q must be letters and digits, such as jpg", e)
			}
		}
		if len(h.Allow) > 50 {
			return badArgs("hotlink protection allows at most 50 other sites")
		}
		for _, host := range h.Allow {
			if err := checkHost(host, false); err != nil {
				return err
			}
		}
	}
	return nil
}

// checkRelative accepts a folder below a site's folder: plain names only.
func checkRelative(rel string) error {
	parts := strings.Split(rel, "/")
	if len(parts) > 8 {
		return badArgs("folder %q is more than 8 deep", rel)
	}
	for _, p := range parts {
		if !partRule.MatchString(p) || p == "." || p == ".." {
			return badArgs("folder %q may only hold letters, digits, dots, dashes and underscores", rel)
		}
	}
	return nil
}

func checkPHPSettings(all map[string]map[string]string) error {
	for version, settings := range all {
		if !versionRule.MatchString(version) {
			return badArgs("PHP version %q is not two digits such as \"83\"", version)
		}
		for k, v := range settings {
			rule, ok := phpSettings[k]
			if !ok {
				return badArgs("php.ini setting %q cannot be changed here", k)
			}
			if !rule.rule.MatchString(v) {
				return badArgs("php.ini %s = %q is not an allowed value", k, v)
			}
		}
	}
	return nil
}

func containsString(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}

// htpasswdFile is where a protected folder's logins live. The name comes
// from the account and the folder, so the same folder keeps its file.
func (m *Manager) htpasswdFile(username, siteName, path string) string {
	sum := sha256.Sum256([]byte(siteName + "\x00" + path))
	return filepath.Join(m.htpasswdDir(), username+"-"+hex.EncodeToString(sum[:])[:12]+".htpasswd")
}

func renderHtpasswd(p Protected) []byte {
	var b strings.Builder
	for _, u := range p.Users {
		b.WriteString(u.Name + ":" + u.Hash + "\n")
	}
	return []byte(b.String())
}

// hostPattern is a regular expression for a host name, with "*." standing
// for any subdomain.
func hostPattern(name string) string {
	if rest, ok := strings.CutPrefix(name, "*."); ok {
		return `([^/.]+\.)+` + regexp.QuoteMeta(rest)
	}
	return regexp.QuoteMeta(name)
}

// renderFeatures writes a site's features into its VirtualHost, before its
// folder: redirects first (they answer before anything else), then the www.
// choice, hotlink protection and error pages.
func renderFeatures(b *strings.Builder, s Site) {
	if len(s.Redirects) > 0 {
		// Longest path first, so /shop/old wins over /shop.
		rs := append([]Redirect(nil), s.Redirects...)
		sort.SliceStable(rs, func(i, j int) bool { return len(rs[i].Path) > len(rs[j].Path) })
		for _, r := range rs {
			fmt.Fprintf(b, "    Redirect %d \"%s\" \"%s\"\n", r.Code, r.Path, r.To)
		}
	}
	switch s.Canonical {
	case "www":
		b.WriteString("    RewriteEngine On\n")
		fmt.Fprintf(b, "    RewriteCond %%{HTTP_HOST} ^%s$ [NC]\n", regexp.QuoteMeta(s.Name))
		fmt.Fprintf(b, "    RewriteRule ^ %%{REQUEST_SCHEME}://www.%s%%{REQUEST_URI} [R=301,L]\n", s.Name)
	case "bare":
		b.WriteString("    RewriteEngine On\n")
		fmt.Fprintf(b, "    RewriteCond %%{HTTP_HOST} ^www\\.%s$ [NC]\n", regexp.QuoteMeta(s.Name))
		fmt.Fprintf(b, "    RewriteRule ^ %%{REQUEST_SCHEME}://%s%%{REQUEST_URI} [R=301,L]\n", s.Name)
	}
	if h := s.Hotlink; h != nil {
		b.WriteString("    RewriteEngine On\n")
		b.WriteString("    RewriteCond %{HTTP_REFERER} !^$\n")
		for _, host := range append(append([]string{s.Name}, s.Aliases...), h.Allow...) {
			fmt.Fprintf(b, "    RewriteCond %%{HTTP_REFERER} !^https?://%s(:[0-9]+)?(/|$) [NC]\n", hostPattern(host))
		}
		fmt.Fprintf(b, "    RewriteRule \\.(%s)$ - [F,NC]\n", strings.Join(h.Extensions, "|"))
	}
	codes := make([]string, 0, len(s.ErrorPages))
	for c := range s.ErrorPages {
		codes = append(codes, c)
	}
	sort.Strings(codes)
	for _, c := range codes {
		fmt.Fprintf(b, "    ErrorDocument %s %s\n", c, s.ErrorPages[c])
	}
}

// renderProtected writes the protected folders. They come after the site's
// own <Directory>: of two sections for one path the later one wins, and the
// site's "Require all granted" must not undo a password.
func (m *Manager) renderProtected(b *strings.Builder, a ApplyArgs, s Site, root string) {
	for _, p := range s.Protected {
		dir := root
		if p.Path != "" {
			dir += "/" + p.Path
		}
		fmt.Fprintf(b, "    <Directory \"%s\">\n", dir)
		b.WriteString("        AuthType Basic\n")
		fmt.Fprintf(b, "        AuthName \"%s\"\n", p.Realm)
		fmt.Fprintf(b, "        AuthUserFile \"%s\"\n", m.htpasswdFile(a.Username, s.Name, p.Path))
		b.WriteString("        Require valid-user\n")
		b.WriteString("    </Directory>\n")
	}
}

// renderPHPSettings writes the owner's php.ini choices into a pool.
// php_value, not php_admin_value: a site may still change them with
// ini_set, as on any host.
func renderPHPSettings(b *strings.Builder, settings map[string]string) {
	keys := make([]string, 0, len(settings))
	for k := range settings {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		kind := "php_value"
		if phpSettings[k].flag {
			kind = "php_flag"
		}
		v := settings[k]
		if _, err := strconv.Atoi(v); err != nil && kind == "php_value" && strings.ContainsAny(v, " &~") {
			v = "\"" + v + "\""
		}
		fmt.Fprintf(b, "%s[%s] = %s\n", kind, k, v)
	}
}
