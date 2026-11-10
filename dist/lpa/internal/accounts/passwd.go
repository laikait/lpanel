package accounts

import (
	"bufio"
	"errors"
	"os"
	"strconv"
	"strings"
)

// Entry is one line of /etc/passwd, plus whether shadow says it has expired.
type Entry struct {
	Name      string
	UID, GID  int
	GECOS     string
	Home      string
	Shell     string
	Suspended bool
}

// Files are the account databases the agent reads. useradd and friends write
// them; the agent only reads.
type Files struct {
	Passwd string
	Group  string
	Shadow string
}

// SystemFiles are the real ones.
var SystemFiles = Files{Passwd: "/etc/passwd", Group: "/etc/group", Shadow: "/etc/shadow"}

// suspendedExpiry is the shadow expiry day the agent writes to suspend an
// account: day 1, 2 January 1970, long past.
const suspendedExpiry = "1"

// lookup finds a user by name; nil when there is none.
func (f Files) lookup(name string) (*Entry, error) {
	all, err := f.users()
	if err != nil {
		return nil, err
	}
	for i := range all {
		if all[i].Name == name {
			return &all[i], nil
		}
	}
	return nil, nil
}

// users reads every passwd entry, with its shadow expiry.
func (f Files) users() ([]Entry, error) {
	expiry, err := f.expiries()
	if err != nil {
		return nil, err
	}
	var out []Entry
	err = eachLine(f.Passwd, func(fields []string) {
		if len(fields) < 7 {
			return
		}
		uid, err1 := strconv.Atoi(fields[2])
		gid, err2 := strconv.Atoi(fields[3])
		if err1 != nil || err2 != nil {
			return
		}
		out = append(out, Entry{
			Name: fields[0], UID: uid, GID: gid, GECOS: fields[4], Home: fields[5], Shell: fields[6],
			Suspended: expiry[fields[0]] == suspendedExpiry,
		})
	})
	return out, err
}

// groupExists says whether a group of that name exists.
func (f Files) groupExists(name string) (bool, error) {
	found := false
	err := eachLine(f.Group, func(fields []string) {
		if len(fields) > 0 && fields[0] == name {
			found = true
		}
	})
	return found, err
}

func (f Files) expiries() (map[string]string, error) {
	out := map[string]string{}
	err := eachLine(f.Shadow, func(fields []string) {
		if len(fields) >= 8 {
			out[fields[0]] = fields[7]
		}
	})
	if errors.Is(err, os.ErrNotExist) {
		return out, nil
	}
	return out, err
}

func eachLine(path string, fn func(fields []string)) error {
	file, err := os.Open(path)
	if err != nil {
		return err
	}
	defer file.Close()
	sc := bufio.NewScanner(file)
	for sc.Scan() {
		line := sc.Text()
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		fn(strings.Split(line, ":"))
	}
	return sc.Err()
}
