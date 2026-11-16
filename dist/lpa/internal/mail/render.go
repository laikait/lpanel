package mail

import (
	"fmt"
	"path"
	"strings"
)

// Catch-all actions besides forwarding to an address.
const (
	Refuse  = ":fail:"      // unknown addresses are refused while the sender is still connected (the default)
	Discard = ":blackhole:" // mail to unknown addresses is accepted and thrown away
)

// header starts every file the agent writes, so a person reading it knows
// not to change it by hand.
func header(what, domain, username string) string {
	return fmt.Sprintf("# %s of %s, for %s. Written by lpa; changes here are replaced.\n", what, domain, username)
}

// ownerFile says which account a mail domain belongs to, and whether it is
// suspended (Exim: the checks on submission, and which DKIM key signs).
func ownerFile(d Domain, username string, suspended bool) []byte {
	s := header("The owner", d.Name, username) + "owner: " + username + "\n"
	if suspended {
		s += "suspended: yes\n"
	}
	return []byte(s)
}

// mailboxesFile lists the domain's mailboxes, one local part a line, for
// Exim's lsearch (it only asks whether a key is there).
func mailboxesFile(d Domain, username string) []byte {
	var b strings.Builder
	b.WriteString(header("Mailboxes", d.Name, username))
	for _, m := range d.Mailboxes {
		b.WriteString(m.Local + ": " + username + "\n")
	}
	return []byte(b.String())
}

// forwardsFile is Exim's redirect data for each address with forwarders,
// and for "*" (the catch-all) unless unknown addresses are refused. Every
// value was checked: plain addresses, or ":blackhole:".
func forwardsFile(d Domain, username string) []byte {
	var b strings.Builder
	b.WriteString(header("Forwarders", d.Name, username))
	for _, f := range d.Forwarders {
		b.WriteString(f.Local + ": " + strings.Join(f.To, ", ") + "\n")
	}
	if d.Catchall != "" && d.Catchall != Refuse {
		b.WriteString("*: " + d.Catchall + "\n")
	}
	return []byte(b.String())
}

// passwdFile is Dovecot's passwd-file for the domain: each mailbox's
// password hash, the account's uid and gid, its home inside the account's
// own home, and its quota. A suspended account's mailboxes still receive
// mail but cannot log in to IMAP or POP3 ("nologin"); Exim refuses their
// mail itself, from the owner file.
func passwdFile(d Domain, username string, uid, gid int, home string, suspended bool) []byte {
	var b strings.Builder
	b.WriteString(header("Mailbox logins", d.Name, username))
	for _, m := range d.Mailboxes {
		fmt.Fprintf(&b, "%s:{BLF-CRYPT}%s:%d:%d::%s::", m.Local, m.Hash, uid, gid, mailboxHome(home, d.Name, m.Local))
		var extra []string
		if m.QuotaMB > 0 {
			extra = append(extra, fmt.Sprintf("userdb_quota_rule=*:storage=%dM", m.QuotaMB))
		}
		if suspended {
			extra = append(extra, "nologin", "reason=Suspended")
		}
		b.WriteString(strings.Join(extra, " ") + "\n")
	}
	return []byte(b.String())
}

// mailboxHome is where a mailbox's mail is kept: inside the account's own
// home, so it counts against the account's disk space.
func mailboxHome(home, domain, local string) string {
	return path.Join(home, "mail", domain, local)
}

// sieveScript is a mailbox's autoresponder as a Sieve script, run before
// any script of the owner's own. Every string is quoted here.
func sieveScript(d Domain, m Mailbox) []byte {
	r := m.Autoresponder
	addr := m.Local + "@" + d.Name
	var b strings.Builder
	b.WriteString("# The autoresponder of " + addr + ". Written by lpa; changes here are replaced.\n")
	b.WriteString(`require ["vacation", "date", "relational"];` + "\n")
	var when []string
	if r.Start != "" {
		when = append(when, `currentdate :value "ge" "date" `+sieveString(r.Start))
	}
	if r.End != "" {
		when = append(when, `currentdate :value "le" "date" `+sieveString(r.End))
	}
	indent := ""
	if len(when) > 0 {
		b.WriteString("if allof (" + strings.Join(when, ", ") + ") {\n")
		indent = "    "
	}
	from := addr
	if name := cleanName(r.FromName); name != "" {
		from = `"` + name + `" <` + addr + `>`
	}
	fmt.Fprintf(&b, "%svacation :days %d :subject %s :from %s :addresses [%s]\n%s    %s;\n",
		indent, r.Days, sieveString(r.Subject), sieveString(from), sieveString(addr), indent, sieveString(body(r.Body)))
	if len(when) > 0 {
		b.WriteString("}\n")
	}
	return []byte(b.String())
}

// sieveString quotes s as a Sieve string: backslash and double quote are
// escaped, and newlines become CRLF as the language wants.
func sieveString(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	s = strings.ReplaceAll(s, `"`, `\"`)
	s = strings.ReplaceAll(s, "\n", "\r\n")
	return `"` + s + `"`
}

// cleanName keeps a display name from closing its quotes or its address.
func cleanName(s string) string {
	return strings.TrimSpace(strings.Map(func(r rune) rune {
		switch r {
		case '"', '\\', '<', '>', '\r', '\n':
			return -1
		}
		return r
	}, s))
}

func body(s string) string {
	return strings.ReplaceAll(s, "\r\n", "\n")
}
