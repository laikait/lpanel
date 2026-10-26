<?php

declare(strict_types=1);

namespace App\Engine\System\Permission;

use App\Engine\System\SystemException;

/**
 * A permission or ownership change was refused, or the operating system did not allow it.
 *
 * isRefusal() is true when this code's rules said no -- a dangerous mode, an
 * owner nobody listed -- and false when the kernel did, which is what an audit
 * record needs to tell a security event from a missing privilege.
 */
final class PermissionException extends SystemException
{
    private bool $refusal = false;

    public function isRefusal(): bool
    {
        return $this->refusal;
    }

    public static function invalidMode(int $mode): self
    {
        return (new self(\sprintf(
            'Mode %04o is not usable. A mode is 0 to 0777; setuid, setgid and sticky bits are refused.',
            $mode,
        )))->refusing();
    }

    public static function worldWritable(int $mode): self
    {
        return (new self(\sprintf(
            'Mode %04o lets every user on the machine write. That is refused: grant a group instead.',
            $mode,
        )))->refusing();
    }

    public static function invalidName(string $kind): self
    {
        return (new self(\sprintf(
            'The %s name is invalid. A name is lowercase letters, digits, underscore and dash, starting with a '
            . 'letter or underscore, at most 32 characters.',
            $kind,
        )))->refusing();
    }

    public static function notAllowed(string $kind, string $name): self
    {
        return (new self(\sprintf(
            'Nothing allows giving files to %s "%s". Ownership changes are refused unless the %1$s is listed '
            . 'when the permission manager is made.',
            $kind,
            $name,
        )))->refusing();
    }

    public static function unsupportedPlatform(): self
    {
        return new self('Permissions and ownership are POSIX; on Windows they are refused rather than half-applied.');
    }

    public static function failed(string $operation, string $path): self
    {
        return new self(\sprintf(
            'Could not %s "%s". Changing ownership needs root; changing a mode needs to own the file. '
            . 'Nothing was changed.',
            $operation,
            $path,
        ));
    }

    /** Mutates a new exception before anyone has seen it, as FrameworkException::withheld() does. */
    private function refusing(): self
    {
        $this->refusal = true;

        return $this;
    }
}
