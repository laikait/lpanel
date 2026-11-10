//go:build linux && cgo && pamtest

package main

import (
	"errors"
	"os"
	"os/exec"
	"testing"
)

// Runs against the real PAM stack. test/run.sh creates the user and
// /etc/pam.d/lpanel in a throwaway container; never run this on a real host.
func TestRealPAM(t *testing.T) {
	if os.Getuid() != 0 {
		t.Skip("needs root, in the test container")
	}
	v := newPAMVerifier("lpanel")

	if err := v.Verify("authdtest", "Correct-Horse-9", "192.0.2.1"); err != nil {
		t.Fatalf("right password: %v", err)
	}
	if err := v.Verify("authdtest", "wrong", "192.0.2.1"); !errors.Is(err, ErrDenied) {
		t.Fatalf("wrong password: %v", err)
	}
	if err := v.Verify("nosuchuser", "x", "192.0.2.1"); !errors.Is(err, ErrDenied) {
		t.Fatalf("unknown user: %v", err)
	}

	if out, err := exec.Command("usermod", "--lock", "authdtest").CombinedOutput(); err != nil {
		t.Fatalf("usermod: %v %s", err, out)
	}
	if err := v.Verify("authdtest", "Correct-Horse-9", "192.0.2.1"); !errors.Is(err, ErrDenied) {
		t.Fatalf("a locked account was accepted: %v", err)
	}
	_ = exec.Command("usermod", "--unlock", "authdtest").Run()

	if out, err := exec.Command("chage", "--expiredate", "1", "authdtest").CombinedOutput(); err != nil {
		t.Fatalf("chage: %v %s", err, out)
	}
	if err := v.Verify("authdtest", "Correct-Horse-9", "192.0.2.1"); !errors.Is(err, ErrDenied) {
		t.Fatalf("an expired account was accepted (pam_acct_mgmt not applied): %v", err)
	}
}
