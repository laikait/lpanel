// Package ops is the agent's fixed list of operations. There is no way to
// run anything else: no shell, no "exec this command". Each operation decodes
// and checks its own arguments, because the agent does not trust the panel to
// have done so (ARCHITECTURE.md, rule 1).
package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"sort"
	"time"

	"lpa/internal/protocol"
)

// Op is one operation.
type Op struct {
	Name string
	// ReadOnly operations change nothing, so they skip the journal and may
	// be repeated freely.
	ReadOnly bool
	// Async operations answer "accepted" at once and run in the background;
	// the panel asks op.status for the outcome.
	Async   bool
	Timeout time.Duration
	// Run decodes args (a JSON object) and does the work. A *protocol.Error
	// with CodeBadArgs means the arguments were refused; anything else is a
	// failure of the work itself.
	Run func(ctx context.Context, args json.RawMessage) (any, error)
}

// Registry holds the operations by name.
type Registry struct{ ops map[string]Op }

// NewRegistry makes an empty registry.
func NewRegistry() *Registry { return &Registry{ops: map[string]Op{}} }

// Add registers an operation; a name used twice is a programming error.
func (r *Registry) Add(op Op) {
	if _, dup := r.ops[op.Name]; dup {
		panic("ops: " + op.Name + " registered twice")
	}
	if op.Timeout <= 0 {
		op.Timeout = 30 * time.Second
	}
	r.ops[op.Name] = op
}

// Get finds an operation.
func (r *Registry) Get(name string) (Op, bool) {
	op, ok := r.ops[name]
	return op, ok
}

// Names lists every operation, sorted.
func (r *Registry) Names() []string {
	names := make([]string, 0, len(r.ops))
	for n := range r.ops {
		names = append(names, n)
	}
	sort.Strings(names)
	return names
}

// Decode reads args strictly: unknown fields are refused, so a typo in the
// panel is an error rather than a silently ignored setting.
func Decode(args json.RawMessage, into any) error {
	dec := json.NewDecoder(bytesReader(args))
	dec.DisallowUnknownFields()
	if err := dec.Decode(into); err != nil {
		return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("arguments: %v", err))
	}
	return nil
}
