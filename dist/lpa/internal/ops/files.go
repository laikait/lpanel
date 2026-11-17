package ops

import (
	"context"
	"encoding/json"
	"time"

	"lpa/internal/files"
)

// fileOp adds one file operation: decode its arguments strictly, then call.
func fileOp[T any, R any](r *Registry, name string, readOnly bool, timeout time.Duration, run func(context.Context, T) (R, error)) {
	r.Add(Op{
		Name: name, ReadOnly: readOnly, Timeout: timeout,
		Run: func(ctx context.Context, args json.RawMessage) (any, error) {
			var a T
			if err := Decode(args, &a); err != nil {
				return nil, err
			}
			return run(ctx, a)
		},
	})
}

// Files adds the file manager's operations. Each runs as the account's own
// user, inside its home (internal/files). Paths are relative to the home.
//
//	files.list      {username, path}                          read-only: a folder's files and folders
//	files.read      {username, path}                          read-only: a file's text, for the editor (4 MB at most)
//	files.usage     {username, path}                          read-only: what in a folder takes the space
//	files.download  {username, path, into}                    copy a file into a staged file the panel made
//	files.write     {username, path, staged, expect, create}  replace a file with staged content
//	files.upload    {username, dir, name, staged, overwrite}  put a staged upload into a folder
//	files.mkdir     {username, path}                          make a folder
//	files.create    {username, path}                          make an empty file
//	files.rename    {username, from, to}                      rename or move one path
//	files.move      {username, paths, to}                     move paths into a folder
//	files.copy      {username, paths, to}                     copy paths into a folder
//	files.delete    {username, paths}                         delete paths, folders with what is in them
//	files.chmod     {username, paths, mode}                   set permissions (not recursively)
//	files.compress  {username, paths, to}                     pack paths into a new .zip, .tar.gz or .tar
//	files.extract   {username, path, to}                      unpack an archive into a folder
func Files(r *Registry, m *files.Manager) {
	quick, slow := 30*time.Second, 100*time.Second
	fileOp(r, "files.list", true, quick, m.List)
	fileOp(r, "files.read", true, quick, m.Read)
	fileOp(r, "files.usage", true, 90*time.Second, m.Usage)
	// Writes only the panel's own staged file; nothing of the account changes.
	fileOp(r, "files.download", true, slow, m.Download)
	fileOp(r, "files.write", false, quick, m.Write)
	fileOp(r, "files.upload", false, slow, m.Upload)
	fileOp(r, "files.mkdir", false, quick, m.Mkdir)
	fileOp(r, "files.create", false, quick, m.Create)
	fileOp(r, "files.rename", false, quick, m.Rename)
	fileOp(r, "files.move", false, slow, m.Move)
	fileOp(r, "files.copy", false, slow, m.Copy)
	fileOp(r, "files.delete", false, slow, m.Delete)
	fileOp(r, "files.chmod", false, quick, m.Chmod)
	fileOp(r, "files.compress", false, slow, m.Compress)
	fileOp(r, "files.extract", false, slow, m.Extract)
}
