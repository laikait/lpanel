package mail

import (
	"fmt"
	"path"
	"strconv"
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

// SpamHeader carries the spam filter's score in tenths, never below 0 (Exim
// adds it to mail from outside, and removes any copy the sender wrote).
const SpamHeader = "X-LPanel-Spam-Score"

// sieveScript is what a mailbox does with mail on arrival, as a Sieve script
// run before any script of the owner's own, in this order: the account's
// blocked senders are thrown away; spam is deleted or filed in Junk (unless
// its sender is allowed); then the mailbox's own filters; then its
// autoresponder. It is nil when there is nothing to do. Every string is
// quoted here.
func sieveScript(d Domain, m Mailbox, spam Spam) []byte {
	if !spam.any() && len(m.Filters) == 0 && m.Autoresponder == nil {
		return nil
	}
	addr := m.Local + "@" + d.Name
	var b strings.Builder
	b.WriteString("# What " + addr + " does with mail on arrival. Written by lpa; changes here are replaced.\n")
	b.WriteString(`require ["fileinto", "mailbox", "copy", "relational", "comparator-i;ascii-numeric", "vacation", "date"];` + "\n")
	if t := senderTest(spam.Block); t != "" {
		b.WriteString("\n# Blocked senders.\nif " + t + " {\n    discard;\n    stop;\n}\n")
	}
	if spam.Junk > 0 || spam.Delete > 0 {
		b.WriteString("\n# Spam.\n")
		indent := ""
		if t := senderTest(spam.Allow); t != "" {
			b.WriteString("if not " + t + " {\n")
			indent = "    "
		}
		score := func(min int, then string) {
			fmt.Fprintf(&b, "%sif header :value \"ge\" :comparator \"i;ascii-numeric\" %s %s {\n%s    %s\n%s    stop;\n%s}\n",
				indent, sieveString(SpamHeader), sieveString(strconv.Itoa(min)), indent, then, indent, indent)
		}
		if spam.Delete > 0 {
			score(spam.Delete, "discard;")
		}
		if spam.Junk > 0 {
			score(spam.Junk, `fileinto :create "Junk";`)
		}
		if indent != "" {
			b.WriteString("}\n")
		}
	}
	if len(m.Filters) > 0 {
		b.WriteString("\n# The mailbox's filters.\n")
		for _, f := range m.Filters {
			b.WriteString("if " + filterTest(f) + " {\n")
			switch f.Action {
			case FilterFolder:
				b.WriteString("    fileinto :create " + sieveString(f.Target) + ";\n")
			case FilterForward:
				b.WriteString("    redirect :copy " + sieveString(f.Target) + ";\n")
			case FilterDiscard:
				b.WriteString("    discard;\n")
			}
			if f.Stop {
				b.WriteString("    stop;\n")
			}
			b.WriteString("}\n")
		}
	}
	if m.Autoresponder != nil {
		b.WriteString("\n# The autoresponder.\n")
		b.WriteString(vacation(addr, m.Autoresponder))
	}
	return []byte(b.String())
}

// senderTest matches mail from any of the senders (addresses, or "@domain"),
// or is "" when there are none.
func senderTest(senders []string) string {
	var addrs, domains []string
	for _, s := range senders {
		if d, ok := strings.CutPrefix(s, "@"); ok {
			domains = append(domains, sieveString(d))
		} else {
			addrs = append(addrs, sieveString(s))
		}
	}
	var tests []string
	if len(addrs) > 0 {
		tests = append(tests, `address :all :is ["from", "sender"] [`+strings.Join(addrs, ", ")+`]`)
	}
	if len(domains) > 0 {
		tests = append(tests, `address :domain :is ["from", "sender"] [`+strings.Join(domains, ", ")+`]`)
	}
	switch len(tests) {
	case 0:
		return ""
	case 1:
		return tests[0]
	}
	return "anyof (" + strings.Join(tests, ", ") + ")"
}

// filterTest is a filter's test. "is" compares whole addresses (or the whole
// subject); the others look inside the header as written, display names
// included. Matching ignores case.
func filterTest(f Filter) string {
	headers := map[string]string{"from": `"from"`, "to": `["to", "cc"]`, "subject": `"subject"`}[f.Field]
	if f.Match == "is" {
		if f.Field == "subject" {
			return "header :is " + headers + " " + sieveString(f.Value)
		}
		return "address :all :is " + headers + " " + sieveString(f.Value)
	}
	switch f.Match {
	case "begins":
		return "header :matches " + headers + " " + sieveString(wildcardQuote(f.Value)+"*")
	case "ends":
		return "header :matches " + headers + " " + sieveString("*"+wildcardQuote(f.Value))
	}
	return "header :contains " + headers + " " + sieveString(f.Value)
}

// wildcardQuote keeps * and ? in what a filter looks for from being read as
// wildcards by :matches.
func wildcardQuote(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	s = strings.ReplaceAll(s, `*`, `\*`)
	return strings.ReplaceAll(s, `?`, `\?`)
}

// vacation is an autoresponder's Sieve: once per sender in r.Days days,
// between its dates when it has them.
func vacation(addr string, r *Autoresponder) string {
	var b strings.Builder
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
	return b.String()
}

// accountFile is the account's own settings for Exim: its sending limit.
func accountFile(username string, limit int) []byte {
	return []byte(fmt.Sprintf("# The mail settings of %s. Written by lpa; changes here are replaced.\nlimit: %d\n", username, limit))
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
