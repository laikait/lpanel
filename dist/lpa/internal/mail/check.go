package mail

import (
	"errors"
	"fmt"
	"regexp"
	"strings"
	"unicode/utf8"

	"lpa/internal/protocol"
)

// Limits on what an account's mail state may hold.
const (
	MaxDomains        = 500
	MaxMailboxes      = 5000 // in one domain
	MaxForwarders     = 5000 // addresses with forwarders, in one domain
	MaxTargets        = 50   // addresses one address forwards to
	MaxQuotaMB        = 1 << 20
	MaxSubject        = 200
	MaxBody           = 10000
	MaxFromName       = 100
	MaxResponderDays  = 30
	MaxFilters        = 50        // filters of one mailbox
	MaxFilterValue    = 200       // characters a filter looks for
	MaxFolder         = 64        // characters of a folder's name
	MaxSenders        = 500       // allowed, and blocked, senders of an account
	MaxLimit          = 1_000_000 // recipients an hour
	MaxScore          = 1000      // spam scores, ×10
	maxLocal          = 64
	maxDomain         = 253
	maxForeignAddress = 254
)

var (
	accountRule = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)
	// A local part of one of the account's own addresses: lowercase letters,
	// digits, and . _ - inside. Never "+", which separates a tag
	// (info+news@example.com reaches info@example.com).
	localRule = regexp.MustCompile(`^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$`)
	labelRule = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	// bcrypt, as PHP's password_hash and Dovecot's BLF-CRYPT write it.
	hashRule = regexp.MustCompile(`^\$2[aby]\$(0[4-9]|[12][0-9]|3[01])\$[./A-Za-z0-9]{53}$`)
	// A foreign address's local part: a narrow set that can never be read
	// by Exim as a file, a pipe, an include or a second address.
	foreignLocalRule = regexp.MustCompile(`^[A-Za-z0-9._%=+-]{1,64}$`)
	dateRule         = regexp.MustCompile(`^20[0-9]{2}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$`)
)

func badArgs(format string, a ...any) error {
	return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf(format, a...))
}

func checkAccount(name string) error {
	if !accountRule.MatchString(name) {
		return badArgs("username %q is not 2–16 lowercase letters and digits", name)
	}
	return nil
}

// checkDomain accepts a lowercase domain name with at least two labels.
func checkDomain(name string) error {
	if len(name) > maxDomain || !strings.Contains(name, ".") {
		return badArgs("%q is not a domain name", name)
	}
	for _, label := range strings.Split(name, ".") {
		if !labelRule.MatchString(label) {
			return badArgs("%q is not a domain name", name)
		}
	}
	return nil
}

func checkLocal(local string) error {
	if len(local) > maxLocal || !localRule.MatchString(local) || strings.Contains(local, "..") {
		return badArgs("%q is not a local part (lowercase letters, digits, and . _ - inside)", local)
	}
	return nil
}

// checkAddress accepts an address mail may be forwarded to, and returns it
// with its domain in lowercase.
func checkAddress(addr string) (string, error) {
	at := strings.LastIndexByte(addr, '@')
	if at <= 0 || len(addr) > maxForeignAddress {
		return "", badArgs("%q is not an email address", addr)
	}
	local, domain := addr[:at], strings.ToLower(addr[at+1:])
	if !foreignLocalRule.MatchString(local) || strings.HasPrefix(local, ".") || strings.HasSuffix(local, ".") || strings.Contains(local, "..") {
		return "", badArgs("%q is not an email address this server forwards to", addr)
	}
	if err := checkDomain(domain); err != nil {
		return "", badArgs("%q is not an email address", addr)
	}
	return local + "@" + domain, nil
}

// checkText accepts printable UTF-8 text up to max characters; newlines
// only where multiline says.
func checkText(field, s string, max int, multiline bool) error {
	if !utf8.ValidString(s) || utf8.RuneCountInString(s) > max {
		return badArgs("%s must be UTF-8 text of at most %d characters", field, max)
	}
	for _, r := range s {
		if r == '\n' && multiline || r == '\t' {
			continue
		}
		if r < 0x20 || r == 0x7f {
			return badArgs("%s holds a control character", field)
		}
	}
	return nil
}

// check validates an account's whole mail state, and puts addresses in
// their one form.
func (a *ApplyArgs) check() error {
	if err := checkAccount(a.Username); err != nil {
		return err
	}
	if len(a.Domains) > MaxDomains {
		return badArgs("an account has at most %d mail domains", MaxDomains)
	}
	if a.Limit < 0 || a.Limit > MaxLimit {
		return badArgs("the sending limit must be 0 to %d recipients an hour", MaxLimit)
	}
	if err := a.Spam.check(); err != nil {
		return err
	}
	seen := map[string]bool{}
	for i := range a.Domains {
		d := &a.Domains[i]
		if err := checkDomain(d.Name); err != nil {
			return err
		}
		if seen[d.Name] {
			return badArgs("%s is listed twice", d.Name)
		}
		seen[d.Name] = true
		if err := d.check(); err != nil {
			return err
		}
	}
	return nil
}

func (d *Domain) check() error {
	switch d.Catchall {
	case "", Refuse, Discard:
	default:
		addr, err := checkAddress(d.Catchall)
		if err != nil {
			return err
		}
		d.Catchall = addr
	}
	if len(d.Mailboxes) > MaxMailboxes || len(d.Forwarders) > MaxForwarders {
		return badArgs("%s has too many mailboxes or forwarders", d.Name)
	}
	boxes := map[string]bool{}
	for i := range d.Mailboxes {
		b := &d.Mailboxes[i]
		if err := checkLocal(b.Local); err != nil {
			return err
		}
		if boxes[b.Local] {
			return badArgs("%s@%s is listed twice", b.Local, d.Name)
		}
		boxes[b.Local] = true
		if !hashRule.MatchString(b.Hash) {
			return badArgs("the password of %s@%s is not a bcrypt hash", b.Local, d.Name)
		}
		if b.QuotaMB < 0 || b.QuotaMB > MaxQuotaMB {
			return badArgs("the quota of %s@%s must be 0 to %d MB", b.Local, d.Name, MaxQuotaMB)
		}
		if r := b.Autoresponder; r != nil {
			if err := r.check(); err != nil {
				return err
			}
		}
		if len(b.Filters) > MaxFilters {
			return badArgs("%s@%s has more than %d filters", b.Local, d.Name, MaxFilters)
		}
		for j := range b.Filters {
			if err := b.Filters[j].check(); err != nil {
				var pe *protocol.Error
				if errors.As(err, &pe) {
					return badArgs("a filter of %s@%s: %s", b.Local, d.Name, pe.Message)
				}
				return err
			}
		}
	}
	fwd := map[string]bool{}
	for i := range d.Forwarders {
		f := &d.Forwarders[i]
		if err := checkLocal(f.Local); err != nil {
			return err
		}
		if fwd[f.Local] {
			return badArgs("%s@%s has its forwarders listed twice", f.Local, d.Name)
		}
		fwd[f.Local] = true
		if len(f.To) == 0 || len(f.To) > MaxTargets {
			return badArgs("%s@%s forwards to 1 to %d addresses", f.Local, d.Name, MaxTargets)
		}
		targets := map[string]bool{}
		for j, to := range f.To {
			addr, err := checkAddress(to)
			if err != nil {
				return err
			}
			if targets[addr] {
				return badArgs("%s@%s forwards to %s twice", f.Local, d.Name, addr)
			}
			targets[addr] = true
			f.To[j] = addr
		}
	}
	return nil
}

func (r *Autoresponder) check() error {
	if err := checkText("the subject", r.Subject, MaxSubject, false); err != nil {
		return err
	}
	if strings.TrimSpace(r.Subject) == "" {
		return badArgs("an autoresponder needs a subject")
	}
	if err := checkText("the message", r.Body, MaxBody, true); err != nil {
		return err
	}
	if err := checkText("the sender's name", r.FromName, MaxFromName, false); err != nil {
		return err
	}
	if r.Days < 1 || r.Days > MaxResponderDays {
		return badArgs("an autoresponder answers each sender once in 1 to %d days", MaxResponderDays)
	}
	for _, d := range []string{r.Start, r.End} {
		if d != "" && !dateRule.MatchString(d) {
			return badArgs("%q is not a date (YYYY-MM-DD)", d)
		}
	}
	if r.Start != "" && r.End != "" && r.End < r.Start {
		return badArgs("an autoresponder cannot end before it starts")
	}
	return nil
}

// check validates how the account files spam, and puts its senders in their
// one form: an address, or "@domain" for a whole domain.
func (s *Spam) check() error {
	if s.Junk < 0 || s.Junk > MaxScore || s.Delete < 0 || s.Delete > MaxScore {
		return badArgs("spam scores must be 0 to %d (tenths)", MaxScore)
	}
	if s.Junk > 0 && s.Delete > 0 && s.Delete <= s.Junk {
		return badArgs("the score that deletes spam must be above the one that files it in Junk")
	}
	for _, list := range []*[]string{&s.Allow, &s.Block} {
		if len(*list) > MaxSenders {
			return badArgs("an account lists at most %d allowed, and %d blocked, senders", MaxSenders, MaxSenders)
		}
		seen := map[string]bool{}
		for i, sender := range *list {
			clean, err := checkSender(sender)
			if err != nil {
				return err
			}
			if seen[clean] {
				return badArgs("%s is listed twice", clean)
			}
			seen[clean] = true
			(*list)[i] = clean
		}
	}
	return nil
}

// checkSender accepts an address, or "@domain" for every address of a
// domain, in lowercase.
func checkSender(s string) (string, error) {
	s = strings.ToLower(strings.TrimSpace(s))
	if domain, ok := strings.CutPrefix(s, "@"); ok {
		if err := checkDomain(domain); err != nil {
			return "", badArgs("%q is not an address or @domain", s)
		}
		return s, nil
	}
	addr, err := checkAddress(s)
	if err != nil {
		return "", badArgs("%q is not an address or @domain", s)
	}
	return strings.ToLower(addr), nil
}

// Filter fields, tests and actions.
var (
	filterFields  = map[string]bool{"from": true, "to": true, "subject": true}
	filterMatches = map[string]bool{"contains": true, "is": true, "begins": true, "ends": true}
)

// Filter actions.
const (
	FilterFolder  = "folder"  // file the message in a folder (made when missing)
	FilterForward = "forward" // send a copy to an address
	FilterDiscard = "discard" // throw the message away
)

func (f *Filter) check() error {
	if !filterFields[f.Field] {
		return badArgs("%q is not a field a filter looks at (from, to, subject)", f.Field)
	}
	if !filterMatches[f.Match] {
		return badArgs("%q is not a test (contains, is, begins, ends)", f.Match)
	}
	if err := checkText("what it looks for", f.Value, MaxFilterValue, false); err != nil {
		return err
	}
	if strings.TrimSpace(f.Value) == "" {
		return badArgs("a filter needs something to look for")
	}
	switch f.Action {
	case FilterFolder:
		return checkFolder(f.Target)
	case FilterForward:
		addr, err := checkAddress(f.Target)
		if err != nil {
			return err
		}
		f.Target = addr
	case FilterDiscard:
		f.Target = ""
	default:
		return badArgs("%q is not an action (folder, forward, discard)", f.Action)
	}
	return nil
}

// checkFolder accepts a folder's name: printable text, no path characters
// or IMAP wildcards, "." only between names (Work.Clients is Clients inside
// Work).
func checkFolder(name string) error {
	if err := checkText("the folder", name, MaxFolder, false); err != nil {
		return err
	}
	if name == "" || strings.TrimSpace(name) != name || strings.HasPrefix(name, ".") || strings.HasSuffix(name, ".") ||
		strings.Contains(name, "..") || strings.ContainsAny(name, `/\"*%~`+"\t") {
		return badArgs("%q is not a folder name", name)
	}
	return nil
}
