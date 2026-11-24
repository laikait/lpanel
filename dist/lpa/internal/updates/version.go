package updates

import (
	"regexp"
	"strconv"
	"strings"
)

// versionRule is what a panel release may be called: 1.2.3, or 1.2.3-rc.1.
var versionRule = regexp.MustCompile(`^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,6}(-[0-9A-Za-z.]{1,40})?$`)

// ValidVersion reports whether v is a release name LPanel uses.
func ValidVersion(v string) bool { return versionRule.MatchString(v) }

// CompareVersions orders two release names the way semantic versioning does:
// the three numbers first, then a release before which a pre-release
// ("-rc.1", "-dev") comes. Names that are not versions compare as equal.
func CompareVersions(a, b string) int {
	if !ValidVersion(a) || !ValidVersion(b) {
		return 0
	}
	ac, apre, _ := strings.Cut(a, "-")
	bc, bpre, _ := strings.Cut(b, "-")
	an, bn := strings.Split(ac, "."), strings.Split(bc, ".")
	for i := 0; i < 3; i++ {
		x, _ := strconv.Atoi(an[i])
		y, _ := strconv.Atoi(bn[i])
		if x != y {
			return sign(x - y)
		}
	}
	switch {
	case apre == bpre:
		return 0
	case apre == "":
		return 1
	case bpre == "":
		return -1
	}
	ap, bp := strings.Split(apre, "."), strings.Split(bpre, ".")
	for i := 0; i < len(ap) && i < len(bp); i++ {
		x, xerr := strconv.Atoi(ap[i])
		y, yerr := strconv.Atoi(bp[i])
		switch {
		case xerr == nil && yerr == nil:
			if x != y {
				return sign(x - y)
			}
		case xerr == nil:
			return -1 // numbers before words
		case yerr == nil:
			return 1
		default:
			if c := strings.Compare(ap[i], bp[i]); c != 0 {
				return c
			}
		}
	}
	return sign(len(ap) - len(bp))
}

func sign(n int) int {
	switch {
	case n < 0:
		return -1
	case n > 0:
		return 1
	}
	return 0
}
