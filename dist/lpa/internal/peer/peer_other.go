//go:build !linux

package peer

import (
	"errors"
	"net"
)

// PeerUID needs SO_PEERCRED, which only Linux has here: every peer is refused.
func UID(net.Conn) (int, error) { return -1, errors.New("peer credentials need Linux") }
