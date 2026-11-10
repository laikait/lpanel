//go:build !(linux && cgo)

package main

import "fmt"

type noPAM struct{}

func newPAMVerifier(string) Verifier { return noPAM{} }

func (noPAM) Verify(string, string, string) error {
	return fmt.Errorf("%w: built without PAM (needs Linux and cgo)", ErrUnavailable)
}
