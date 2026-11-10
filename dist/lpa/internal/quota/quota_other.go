//go:build !linux

package quota

// Set implements Quotas; quotas exist only on Linux.
func (Kernel) Set(string, int, Limits) error { return ErrNotEnabled }

// Get implements Quotas.
func (Kernel) Get(string, int) (Usage, error) { return Usage{}, ErrNotEnabled }
