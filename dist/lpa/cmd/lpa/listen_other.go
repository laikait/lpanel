//go:build !linux

package main

import (
	"errors"
	"net"
)

// listen: the agent runs only on Linux; other systems build for tests only.
func listen(string, int) (net.Listener, error) {
	return nil, errors.New("lpa serve runs on Linux only")
}
