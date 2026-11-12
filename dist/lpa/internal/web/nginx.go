package web

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"lpa/internal/protocol"
)

// nginx, alone (lpa.json "web": "nginx") or in front of Apache ("hybrid").
//
// Alone, nginx serves each site from its folder and passes PHP to the
// account's pool; there is no .htaccess, so the site's features are written
// into its server block: the www. choice, redirects and hotlink protection
// as rewrite-phase rules (so they apply to every location), error pages, and
// protected folders as locations with auth_basic.
//
// In front of Apache, nginx serves static files itself and passes everything
// else to Apache on 127.0.0.1, which runs PHP and reads .htaccess as it does
// alone. The www. choice, redirects and hotlink protection apply in nginx
// too, so a static file obeys them; a protected folder is passed to Apache
// whole, so its password stays Apache's to check.

// staticKinds are the files nginx serves itself in front of Apache.
const staticKinds = "css|js|mjs|map|jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|woff|woff2|ttf|otf|eot|mp4|webm|mp3|ogg|wav|pdf|txt|csv|zip|gz|tgz|rar|7z"

// renderNginxInclude is /etc/nginx/conf.d/lpanel.conf, inside nginx's http
// block: the log format, a default site for names nobody serves, and every
// account's file.
func renderNginxInclude(sites string) []byte {
	var b strings.Builder
	b.WriteString("# " + banner + "\n\n")
	// $bytes_sent and $request_length are bytes sent and received, for
	// bandwidth; the line has the same shape as Apache's "lpanel" format.
	b.WriteString(`log_format lpanel '$host $remote_addr - $remote_user [$time_local] "$request" $status $bytes_sent $request_length "$http_referer" "$http_user_agent"';` + "\n\n")
	b.WriteString("server_names_hash_bucket_size 128;\nserver_names_hash_max_size 16384;\n")
	// PHP's own post_max_size decides how large an upload may be.
	b.WriteString("client_max_body_size 0;\n")
	// nginx has no escape for "$" in a string; owners' redirect addresses
	// may hold one.
	b.WriteString("geo $lpanel_dollar { default \"$\"; }\n\n")
	b.WriteString("# For Apache behind nginx: who asked, and how.\n")
	b.WriteString("proxy_set_header Host $host;\nproxy_set_header X-Real-IP $remote_addr;\n")
	// Set, not appended: Apache trusts 127.0.0.1, so a visitor's own
	// X-Forwarded-For must never reach it.
	b.WriteString("proxy_set_header X-Forwarded-For $remote_addr;\nproxy_set_header X-Forwarded-Proto $scheme;\n\n")
	b.WriteString("# The first site answers names that no account serves: a 404,\n# never some customer's site.\n")
	b.WriteString("server {\n    listen 80 default_server;\n    listen [::]:80 default_server;\n    server_name _;\n    access_log off;\n    return 404;\n}\n\n")
	b.WriteString("include " + sites + "/*.conf;\n")
	return []byte(b.String())
}

// renderNginxSites is one account's nginx file: a server block per site,
// with @REVISION@ where the revision goes.
func (m *Manager) renderNginxSites(a ApplyArgs, home string) string {
	hybrid := m.Server == "hybrid"
	header := "X-LPanel-Revision"
	if hybrid {
		header = "X-LPanel-Front"
	}
	backend := "http://127.0.0.1:" + strconv.Itoa(m.BackendPort)
	// Absolute: nginx -t over a test configuration elsewhere resolves
	// relative includes from there.
	etc := filepath.ToSlash(filepath.Dir(filepath.Dir(m.NginxInclude)))
	var b strings.Builder
	for i, s := range a.Sites {
		if i > 0 {
			b.WriteString("\n")
		}
		b.WriteString("server {\n    listen 80;\n    listen [::]:80;\n")
		fmt.Fprintf(&b, "    server_name %s;\n", strings.Join(append([]string{s.Name}, s.Aliases...), " "))
		fmt.Fprintf(&b, "    add_header %s \"@REVISION@\" always;\n", header)
		fmt.Fprintf(&b, "    access_log %s/%s.access.log lpanel;\n", m.NginxLogDir, a.Username)
		fmt.Fprintf(&b, "    error_log %s/%s.error.log;\n", m.NginxLogDir, a.Username)
		if a.Suspended || a.Limited {
			why := "This site is suspended."
			if !a.Suspended {
				why = "This site has used its bandwidth for this month."
			}
			fmt.Fprintf(&b, "    default_type text/plain;\n    return 503 \"%s\";\n}\n", why)
			continue
		}
		root := home + "/" + s.Docroot
		fmt.Fprintf(&b, "    root \"%s\";\n", root)
		b.WriteString("    index index.php index.html index.htm;\n")
		// As Apache's SymLinksIfOwnerMatch: a link is followed only to a
		// file of the link's owner.
		b.WriteString("    disable_symlinks if_not_owner from=$document_root;\n")
		renderNginxRules(&b, s)
		whole := false
		for _, p := range s.Protected {
			whole = whole || p.Path == ""
		}

		if hybrid {
			// Protected folders, and the whole site when it is one, are
			// Apache's: it checks the password.
			for _, p := range s.Protected {
				if p.Path != "" {
					fmt.Fprintf(&b, "    location ^~ /%s/ {\n        proxy_pass %s;\n    }\n", p.Path, backend)
				}
			}
			fmt.Fprintf(&b, "    location ~ /\\. {\n        proxy_pass %s;\n    }\n", backend)
			if !whole {
				fmt.Fprintf(&b, "    location ~* \\.(%s)$ {\n        try_files $uri @apache;\n    }\n", staticKinds)
			}
			fmt.Fprintf(&b, "    location / {\n        proxy_pass %s;\n    }\n", backend)
			fmt.Fprintf(&b, "    location @apache {\n        proxy_pass %s;\n    }\n", backend)
			b.WriteString("}\n")
			continue
		}

		renderNginxErrorPages(&b, s)
		socket := m.socket(s.PHP, a.Username)
		for _, p := range s.Protected {
			file := m.htpasswdFile(a.Username, s.Name, p.Path)
			if p.Path == "" {
				fmt.Fprintf(&b, "    auth_basic \"%s\";\n    auth_basic_user_file \"%s\";\n", p.Realm, file)
				continue
			}
			fmt.Fprintf(&b, "    location ^~ /%s/ {\n", p.Path)
			fmt.Fprintf(&b, "        auth_basic \"%s\";\n        auth_basic_user_file \"%s\";\n", p.Realm, file)
			nginxInner(&b, "        ", socket, etc)
			b.WriteString("    }\n")
		}
		nginxInner(&b, "    ", socket, etc)
		// No .htaccess, so an app's own addresses (/shop/cart) go to its
		// index.php, as its rewrite rules would have sent them. A missing
		// file with an extension (/x.html, /x.png) stays a 404, so the
		// site's own error page still shows.
		b.WriteString("    location / {\n        try_files $uri $uri/ @app;\n    }\n")
		b.WriteString("    location @app {\n        if ($uri ~ \"\\.[A-Za-z0-9]+$\") {\n            return 404;\n        }\n        rewrite ^ /index.php last;\n    }\n")
		b.WriteString("}\n")
	}
	return b.String()
}

// nginxInner writes what every place serving the site's files needs: no
// hidden files (.htaccess, .git, .env; .well-known stays reachable), and
// PHP through the account's pool. It is repeated inside each protected
// folder, because a prefix location there keeps the outer regex ones from
// applying.
func nginxInner(b *strings.Builder, in, socket, etc string) {
	fmt.Fprintf(b, "%slocation ~ /\\.(?!well-known/) {\n%s    deny all;\n%s}\n", in, in, in)
	fmt.Fprintf(b, "%slocation ~ \\.php$ {\n", in)
	fmt.Fprintf(b, "%s    try_files $uri =404;\n", in)
	fmt.Fprintf(b, "%s    include %s/fastcgi_params;\n", in, etc)
	fmt.Fprintf(b, "%s    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n", in)
	fmt.Fprintf(b, "%s    fastcgi_pass unix:%s;\n", in, socket)
	fmt.Fprintf(b, "%s}\n", in)
}

// renderNginxRules writes the www. choice, the redirects and hotlink
// protection, in the server's rewrite phase, in that order (as Apache's
// mod_rewrite runs before mod_alias).
func renderNginxRules(b *strings.Builder, s Site) {
	switch s.Canonical {
	case "www":
		fmt.Fprintf(b, "    if ($host = \"%s\") {\n        return 301 $scheme://www.%s$request_uri;\n    }\n", s.Name, s.Name)
	case "bare":
		fmt.Fprintf(b, "    if ($host = \"www.%s\") {\n        return 301 $scheme://%s$request_uri;\n    }\n", s.Name, s.Name)
	}
	if len(s.Redirects) > 0 {
		// Longest path first, so /shop/old wins over /shop.
		rs := append([]Redirect(nil), s.Redirects...)
		sort.SliceStable(rs, func(i, j int) bool { return len(rs[i].Path) > len(rs[j].Path) })
		for _, r := range rs {
			fmt.Fprintf(b, "    rewrite \"%s\" \"%s$1\" %s;\n", redirectPattern(r.Path), nginxLiteral(r.To), map[int]string{301: "permanent", 302: "redirect"}[r.Code])
		}
	}
	if h := s.Hotlink; h != nil {
		// valid_referers marks a foreign page; the file kind is checked
		// separately, since nginx's "if" takes one condition.
		hosts := append(append([]string{s.Name}, s.Aliases...), h.Allow...)
		fmt.Fprintf(b, "    valid_referers none %s;\n", strings.Join(hosts, " "))
		b.WriteString("    set $lpanel_hotlink \"\";\n")
		b.WriteString("    if ($invalid_referer) {\n        set $lpanel_hotlink \"x\";\n    }\n")
		fmt.Fprintf(b, "    if ($uri ~* \"\\.(%s)$\") {\n        set $lpanel_hotlink \"${lpanel_hotlink}y\";\n    }\n", strings.Join(h.Extensions, "|"))
		b.WriteString("    if ($lpanel_hotlink = \"xy\") {\n        return 403;\n    }\n")
	}
}

func renderNginxErrorPages(b *strings.Builder, s Site) {
	codes := make([]string, 0, len(s.ErrorPages))
	for c := range s.ErrorPages {
		codes = append(codes, c)
	}
	sort.Strings(codes)
	for _, c := range codes {
		fmt.Fprintf(b, "    error_page %s %s;\n", c, s.ErrorPages[c])
	}
}

// redirectPattern matches a path as Apache's Redirect does: the path itself
// and everything under it (whole segments: /old, not /older), capturing the
// rest, which the target keeps.
func redirectPattern(path string) string {
	if path == "/" {
		return "^/(.*)$"
	}
	if strings.HasSuffix(path, "/") {
		return "^" + regexp.QuoteMeta(path) + "(.*)$"
	}
	return "^" + regexp.QuoteMeta(path) + "(/.*)?$"
}

// nginxLiteral makes s safe inside a double-quoted nginx string: a "$" would
// start a variable, so it becomes the one variable that holds a "$". The
// redirect rule already keeps quotes, backslashes, braces and spaces out.
func nginxLiteral(s string) string {
	return strings.ReplaceAll(s, "$", "${lpanel_dollar}")
}

// testNginx runs nginx -t over a test configuration: nginx's own, with
// LPanel's include naming a copy of the sites folder that holds the
// candidates and copies of every other account's file.
func (m *Manager) testNginx(ctx context.Context, todo []file) error {
	stage, err := os.MkdirTemp(filepath.Dir(m.NginxSites), ".stage-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(stage)
	sites := filepath.Join(stage, "sites")
	if err := os.Mkdir(sites, 0o755); err != nil {
		return err
	}
	mine := map[string]file{}
	for _, f := range todo {
		if f.server == "nginx" {
			mine[filepath.Base(f.path)] = f
		}
	}
	live, err := filepath.Glob(filepath.Join(m.NginxSites, "*.conf"))
	if err != nil {
		return err
	}
	for _, p := range live {
		if _, ok := mine[filepath.Base(p)]; ok {
			continue
		}
		content, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		if err := os.WriteFile(filepath.Join(sites, filepath.Base(p)), content, 0o644); err != nil {
			return err
		}
	}
	for name, f := range mine {
		if f.content == nil {
			continue
		}
		if err := os.WriteFile(filepath.Join(sites, name), f.content, 0o644); err != nil {
			return err
		}
	}
	// The server's own nginx.conf, whole, with its conf.d replaced by a copy
	// in which LPanel's include names the staged sites: so anything broken
	// elsewhere in nginx's configuration stops the change too, as httpd -t
	// does for Apache. Without the installer's nginx.conf, a minimal one.
	confd := filepath.Dir(m.NginxInclude)
	etc := filepath.Dir(confd)
	stageConfd := filepath.Join(stage, "conf.d")
	if err := os.Mkdir(stageConfd, 0o755); err != nil {
		return err
	}
	others, _ := filepath.Glob(filepath.Join(confd, "*.conf"))
	for _, p := range others {
		if filepath.Base(p) == filepath.Base(m.NginxInclude) {
			continue
		}
		content, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		if err := os.WriteFile(filepath.Join(stageConfd, filepath.Base(p)), content, 0o644); err != nil {
			return err
		}
	}
	include := filepath.Join(stageConfd, filepath.Base(m.NginxInclude))
	if err := os.WriteFile(include, renderNginxInclude(sites), 0o644); err != nil {
		return err
	}
	conf := fmt.Sprintf("error_log stderr;\nevents {}\nhttp {\n    include %s/mime.types;\n    include %s/*.conf;\n}\n", filepath.ToSlash(etc), filepath.ToSlash(stageConfd))
	liveConfd := filepath.ToSlash(confd) + "/*.conf"
	if real, err := os.ReadFile(filepath.Join(etc, "nginx.conf")); err == nil && strings.Contains(string(real), liveConfd) {
		conf = strings.ReplaceAll(string(real), liveConfd, filepath.ToSlash(stageConfd)+"/*.conf")
	}
	main := filepath.Join(stage, "nginx.conf")
	if err := os.WriteFile(main, []byte(conf), 0o644); err != nil {
		return err
	}
	code, out, err := m.Run.Run(ctx, "nginx", "-t", "-q", "-c", main)
	if err != nil {
		return err
	}
	if code != 0 {
		return protocol.Fail(CodeRejected, "nginx refused the configuration: "+lastLines(out))
	}
	return nil
}
