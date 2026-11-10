package ops

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"runtime"
	"time"

	"lpa/internal/sysinfo"
)

func bytesReader(b []byte) io.Reader { return bytes.NewReader(b) }

// Facts is what system.facts answers: the server at a glance.
type Facts struct {
	Hostname      string            `json:"hostname"`
	OS            sysinfo.OSRelease `json:"os"`
	Kernel        string            `json:"kernel"`
	Arch          string            `json:"arch"`
	CPUs          int               `json:"cpus"`
	Load          sysinfo.Load      `json:"load"`
	Memory        sysinfo.Memory    `json:"memory"`
	UptimeSeconds int64             `json:"uptime_seconds"`
	Disks         []sysinfo.Disk    `json:"disks"`
	Agent         AgentInfo         `json:"agent"`
	// Problems lists what could not be read; the rest is still answered.
	Problems []string `json:"problems,omitempty"`
}

// AgentInfo describes the agent itself.
type AgentInfo struct {
	Version string `json:"version"`
	Started string `json:"started"`
}

// System adds the read-only system operations.
func System(r *Registry, reader *sysinfo.Reader, version string, started time.Time) {
	r.Add(Op{
		Name: "system.ping", ReadOnly: true, Timeout: 2 * time.Second,
		Run: func(_ context.Context, args json.RawMessage) (any, error) {
			if err := Decode(args, &struct{}{}); err != nil {
				return nil, err
			}
			return map[string]string{"agent": version, "time": time.Now().UTC().Format(time.RFC3339)}, nil
		},
	})

	r.Add(Op{
		Name: "system.facts", ReadOnly: true, Timeout: 10 * time.Second,
		Run: func(_ context.Context, args json.RawMessage) (any, error) {
			if err := Decode(args, &struct{}{}); err != nil {
				return nil, err
			}
			return ReadFacts(reader, version, started), nil
		},
	})
}

// ReadFacts gathers Facts, keeping going past anything it cannot read.
func ReadFacts(reader *sysinfo.Reader, version string, started time.Time) Facts {
	f := Facts{Arch: runtime.GOARCH, Agent: AgentInfo{Version: version, Started: started.UTC().Format(time.RFC3339)}, Disks: []sysinfo.Disk{}}
	note := func(err error) {
		if err != nil {
			f.Problems = append(f.Problems, err.Error())
		}
	}
	var err error
	f.Hostname, err = reader.Hostname()
	note(err)
	f.OS, err = reader.OSRelease()
	note(err)
	f.Kernel, err = reader.Kernel()
	note(err)
	f.CPUs, err = reader.CPUCount()
	note(err)
	f.Load, err = reader.Load()
	note(err)
	f.Memory, err = reader.Memory()
	note(err)
	f.UptimeSeconds, err = reader.UptimeSeconds()
	note(err)
	if disks, err := reader.Disks(); err == nil {
		f.Disks = disks
	} else {
		note(err)
	}
	return f
}
