//go:build !linux

package main

import (
	"errors"
	"net"
)

func peerUID(*net.UnixConn) (uint32, error) {
	return 0, errors.New("peer credentials are only available on Linux")
}
