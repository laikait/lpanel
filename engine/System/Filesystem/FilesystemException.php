<?php

declare(strict_types=1);

namespace App\Engine\System\Filesystem;

use App\Engine\System\SystemException;

/**
 * A filesystem operation was refused by policy, or the filesystem said no.
 *
 * Paths are quoted: they are what somebody needs to act on the message, and a
 * path is not a secret. PHP's own warning text is not, since it can quote
 * another path the caller never named.
 *
 * A refusal is a FilesystemPolicyException -- a path the rules did not allow,
 * which is a security event -- and a ".." in particular is a
 * PathTraversalException. Anything thrown as this class itself is a file that
 * was missing or could not be written, which is an operator's problem.
 */
class FilesystemException extends SystemException
{
    /** Whether the rules said no, rather than the filesystem. */
    public function isRefusal(): bool
    {
        return $this instanceof FilesystemPolicyException;
    }

    public static function notAbsolute(string $path): FilesystemPolicyException
    {
        return new FilesystemPolicyException(\sprintf('"%s" is not an absolute path. System filesystem operations take absolute paths only.', $path));
    }

    public static function nulByte(): FilesystemPolicyException
    {
        return new FilesystemPolicyException('The path contains a NUL byte, which no filesystem path can.');
    }

    public static function traversal(string $path): PathTraversalException
    {
        return new PathTraversalException(\sprintf(
            '"%s" contains a ".." segment. Parent references are refused outright, even when they would stay inside '
            . 'an allowed root: say the path you mean.',
            $path,
        ));
    }

    public static function filesystemRoot(string $root): FilesystemPolicyException
    {
        return new FilesystemPolicyException(\sprintf(
            '"%s" is the root of a filesystem, and allowing it would allow everything. Allow the directories the '
            . 'application needs.',
            $root,
        ));
    }

    public static function outsidePolicy(string $path): FilesystemPolicyException
    {
        return new FilesystemPolicyException(\sprintf(
            '"%s" is not inside any root the filesystem policy allows, once symbolic links are followed.',
            $path,
        ));
    }

    public static function readOnly(string $path): FilesystemPolicyException
    {
        return new FilesystemPolicyException(\sprintf('"%s" is inside a root the filesystem policy allows reading, not writing.', $path));
    }

    public static function danglingLink(string $path): FilesystemPolicyException
    {
        return new FilesystemPolicyException(\sprintf('"%s" passes through a symbolic link whose target does not exist, so where it leads cannot be checked.', $path));
    }

    public static function notFound(string $path): self
    {
        return new self(\sprintf('"%s" does not exist.', $path));
    }

    public static function alreadyExists(string $path): self
    {
        return new self(\sprintf('"%s" already exists. Pass overwrite: true to replace a file.', $path));
    }

    public static function notAFile(string $path): self
    {
        return new self(\sprintf('"%s" is not a regular file.', $path));
    }

    public static function notADirectory(string $path): self
    {
        return new self(\sprintf('"%s" is not a directory.', $path));
    }

    public static function notEmpty(string $path): self
    {
        return new self(\sprintf('"%s" is a directory that is not empty. Pass recursive: true to delete what is in it.', $path));
    }

    public static function isRoot(string $path): FilesystemPolicyException
    {
        return new FilesystemPolicyException(\sprintf('"%s" is an allowed root itself, which is not deleted or moved through the policy that allows it.', $path));
    }

    public static function invalidPermissions(int $permissions): FilesystemPolicyException
    {
        return new FilesystemPolicyException(\sprintf(
            'Permissions %04o are not usable here. They are 0 to 0777; setuid, setgid and sticky bits are refused.',
            $permissions,
        ));
    }

    public static function failed(string $operation, string $path): self
    {
        return new self(\sprintf('Could not %s "%s". Check that the directory exists and this process may write to it.', $operation, $path));
    }
}
