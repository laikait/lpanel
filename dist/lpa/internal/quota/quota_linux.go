package quota

import (
	"errors"
	"fmt"
	"syscall"
	"unsafe"
)

const (
	qGetQuota   = 0x800007
	qSetQuota   = 0x800008
	usrQuota    = 0
	qifBLimits  = 1
	qifILimits  = 4
	subCmdShift = 8
)

// dqblk is the kernel's struct if_dqblk. Block limits are in 1 KiB units;
// space used is in bytes.
type dqblk struct {
	BHardLimit uint64
	BSoftLimit uint64
	CurSpace   uint64
	IHardLimit uint64
	ISoftLimit uint64
	CurInodes  uint64
	BTime      uint64
	ITime      uint64
	Valid      uint32
	_          uint32
}

func quotactl(cmd int, device string, id int, dq *dqblk) error {
	dev, err := syscall.BytePtrFromString(device)
	if err != nil {
		return err
	}
	c := uint32(cmd)<<subCmdShift | usrQuota
	_, _, errno := syscall.Syscall6(syscall.SYS_QUOTACTL, uintptr(c), uintptr(unsafe.Pointer(dev)), uintptr(id), uintptr(unsafe.Pointer(dq)), 0, 0)
	switch {
	case errno == 0:
		return nil
	case errors.Is(errno, syscall.ESRCH), errors.Is(errno, syscall.ENOSYS), errors.Is(errno, syscall.ENOTSUP):
		return ErrNotEnabled
	default:
		return fmt.Errorf("quotactl on %s: %w", device, errno)
	}
}

// Set implements Quotas. Soft and hard limits are the same: there is no
// grace period, a full account is full.
func (k Kernel) Set(dir string, uid int, l Limits) error {
	device, _, err := mountOf(k.Mounts, dir)
	if err != nil {
		return err
	}
	dq := dqblk{
		BHardLimit: l.BlocksKiB, BSoftLimit: l.BlocksKiB,
		IHardLimit: l.Inodes, ISoftLimit: l.Inodes,
		Valid: qifBLimits | qifILimits,
	}
	return quotactl(qSetQuota, device, uid, &dq)
}

// Get implements Quotas.
func (k Kernel) Get(dir string, uid int) (Usage, error) {
	device, _, err := mountOf(k.Mounts, dir)
	if err != nil {
		return Usage{}, err
	}
	var dq dqblk
	if err := quotactl(qGetQuota, device, uid, &dq); err != nil {
		return Usage{}, err
	}
	return Usage{UsedBytes: dq.CurSpace, UsedInodes: dq.CurInodes, LimitKiB: dq.BHardLimit, InodeLimit: dq.IHardLimit}, nil
}
