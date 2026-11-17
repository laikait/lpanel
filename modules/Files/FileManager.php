<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Engine\Auth\Identity;
use App\Engine\Config\Config;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * The file manager's actions on an account's home, for the User panel, the
 * API and the CLI alike.
 *
 * The panel never reads or writes an account's files itself: the agent does
 * each operation as the account's own user, inside its home (files.*). What
 * travels with an operation (an upload, a saved file, a download) goes
 * through the staging folder, which only the panel and root can open, under
 * a random name; the agent is given only that name.
 *
 * Paths are relative to the home, "" being the home itself. Every change is
 * audited (files.*), never with a file's contents.
 */
final class FileManager
{
    /** The editor opens and saves files up to this size. */
    public const MAX_EDIT = 4 * 1024 * 1024;

    /** Staged files nobody collected are removed after this long. */
    public const STAGED_SECONDS = 3600;

    /** Agent operations that take long (copy, archives, uploads) get this long. */
    private const SLOW = 110.0;

    private const QUICK = 40.0;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly Config $config,
    ) {}

    /**
     * A path as the agent takes it: no "." or "..", no empty parts, ""
     * for the home. Null when it is not a path within the home at all.
     */
    public static function clean(string $path): ?string
    {
        if (\strlen($path) > 4096 || \str_contains($path, "\0") || !\mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        $parts = [];

        foreach (\explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                return null;
            }

            $parts[] = $part;
        }

        return \implode('/', $parts);
    }

    /** The folder a path is in ("" for the home). */
    public static function parent(string $path): string
    {
        $at = \strrpos($path, '/');

        return $at === false ? '' : \substr($path, 0, $at);
    }

    /** A path's last part. */
    public static function name(string $path): string
    {
        $at = \strrpos($path, '/');

        return $at === false ? $path : \substr($path, $at + 1);
    }

    public static function join(string $dir, string $name): string
    {
        return $dir === '' ? $name : $dir . '/' . $name;
    }

    // -------------------------------------------------------------------------
    // Reading

    /** @param array<string, mixed> $account */
    public function list(array $account, string $path): FileOutcome
    {
        return $this->ask($account, 'files.list', ['path' => $path]);
    }

    /** @param array<string, mixed> $account */
    public function read(array $account, string $path): FileOutcome
    {
        return $this->ask($account, 'files.read', ['path' => $path]);
    }

    /** @param array<string, mixed> $account */
    public function usage(array $account, string $path): FileOutcome
    {
        return $this->ask($account, 'files.usage', ['path' => $path], 100.0);
    }

    /**
     * Copy a file out of the home into a staged file. The result's "staged"
     * is that file's path: the caller sends it and removes it.
     *
     * @param array<string, mixed> $account
     */
    public function download(?Identity $actor, ?Request $request, array $account, string $path): FileOutcome
    {
        [$token, $file] = $this->stage();

        if ($token === null) {
            return FileOutcome::refused('staging', 'Files.error_staging');
        }

        $outcome = $this->run($actor, $request, $account, 'files.download', ['path' => $path, 'into' => $token], ['path' => $path], self::SLOW);

        if (!$outcome->ok()) {
            @\unlink($file);

            return $outcome;
        }

        return FileOutcome::done($outcome->result + ['staged' => $file]);
    }

    // -------------------------------------------------------------------------
    // Changing

    /**
     * Save text into a file. $expect is the SHA-256 the file had when it was
     * opened, so a save over someone else's change is refused.
     *
     * @param array<string, mixed> $account
     */
    public function save(?Identity $actor, ?Request $request, array $account, string $path, string $content, ?string $expect = null, bool $create = false): FileOutcome
    {
        if (\strlen($content) > self::MAX_EDIT) {
            return FileOutcome::refused('files_too_big', 'Files.error_files_too_big');
        }

        [$token, $file] = $this->stage($content);

        if ($token === null) {
            return FileOutcome::refused('staging', 'Files.error_staging');
        }

        try {
            return $this->run($actor, $request, $account, 'files.write', ['path' => $path, 'staged' => $token, 'expect' => $expect ?? '', 'create' => $create], ['path' => $path, 'bytes' => \strlen($content)]);
        } finally {
            @\unlink($file);
        }
    }

    /**
     * Put an uploaded file (already on this machine, at $from) into a folder.
     * The file at $from is moved into the staging folder and removed after.
     *
     * @param array<string, mixed> $account
     */
    public function upload(?Identity $actor, ?Request $request, array $account, string $dir, string $name, string $from, bool $overwrite = false, bool $uploaded = true): FileOutcome
    {
        $staging = $this->stagingDir();
        $token = \bin2hex(\random_bytes(32));
        $file = $staging . '/' . $token;

        if (!\is_dir($staging) || !\is_writable($staging)) {
            return FileOutcome::refused('staging', 'Files.error_staging');
        }

        $old = \umask(0o177);

        try {
            $moved = $uploaded && \is_uploaded_file($from) ? @\move_uploaded_file($from, $file) : @\copy($from, $file);
        } finally {
            \umask($old);
        }

        if ($moved === false) {
            return FileOutcome::refused('staging', 'Files.error_staging');
        }

        @\chmod($file, 0o600);

        try {
            return $this->run($actor, $request, $account, 'files.upload', ['dir' => $dir, 'name' => $name, 'staged' => $token, 'overwrite' => $overwrite], ['dir' => $dir, 'name' => $name, 'bytes' => (int) @\filesize($file)], self::SLOW);
        } finally {
            @\unlink($file);
        }
    }

    /** @param array<string, mixed> $account */
    public function mkdir(?Identity $actor, ?Request $request, array $account, string $path): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.mkdir', ['path' => $path], ['path' => $path]);
    }

    /** @param array<string, mixed> $account */
    public function create(?Identity $actor, ?Request $request, array $account, string $path): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.create', ['path' => $path], ['path' => $path]);
    }

    /** @param array<string, mixed> $account */
    public function rename(?Identity $actor, ?Request $request, array $account, string $from, string $to): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.rename', ['from' => $from, 'to' => $to], ['from' => $from, 'to' => $to]);
    }

    /**
     * @param array<string, mixed> $account
     * @param list<string>         $paths
     */
    public function move(?Identity $actor, ?Request $request, array $account, array $paths, string $to): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.move', ['paths' => $paths, 'to' => $to], ['paths' => $paths, 'to' => $to], self::SLOW);
    }

    /**
     * @param array<string, mixed> $account
     * @param list<string>         $paths
     */
    public function copy(?Identity $actor, ?Request $request, array $account, array $paths, string $to): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.copy', ['paths' => $paths, 'to' => $to], ['paths' => $paths, 'to' => $to], self::SLOW);
    }

    /**
     * @param array<string, mixed> $account
     * @param list<string>         $paths
     */
    public function delete(?Identity $actor, ?Request $request, array $account, array $paths): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.delete', ['paths' => $paths], ['paths' => $paths], self::SLOW);
    }

    /**
     * @param array<string, mixed> $account
     * @param list<string>         $paths
     */
    public function chmod(?Identity $actor, ?Request $request, array $account, array $paths, string $mode): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.chmod', ['paths' => $paths, 'mode' => $mode], ['paths' => $paths, 'mode' => $mode]);
    }

    /**
     * @param array<string, mixed> $account
     * @param list<string>         $paths
     */
    public function compress(?Identity $actor, ?Request $request, array $account, array $paths, string $to): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.compress', ['paths' => $paths, 'to' => $to], ['paths' => $paths, 'to' => $to], self::SLOW);
    }

    /** @param array<string, mixed> $account */
    public function extract(?Identity $actor, ?Request $request, array $account, string $path, string $to): FileOutcome
    {
        return $this->run($actor, $request, $account, 'files.extract', ['path' => $path, 'to' => $to], ['path' => $path, 'to' => $to], self::SLOW);
    }

    /**
     * Remove staged files nobody collected (a download whose browser went
     * away, an upload interrupted mid-way).
     *
     * @return int files removed
     */
    public function sweep(): int
    {
        $removed = 0;

        foreach (\glob($this->stagingDir() . '/*') ?: [] as $file) {
            // Staged files, and PHP's own upload files (uploads land here too)
            // left behind by a request that died.
            if (\preg_match('/^([0-9a-f]{64}|php[A-Za-z0-9]{6,})$/', \basename($file)) === 1 && (int) @\filemtime($file) < \time() - self::STAGED_SECONDS && @\unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function stagingDir(): string
    {
        return \rtrim((string) $this->config->get('Files.staging', '/var/lib/lpanel/files'), '/');
    }

    // -------------------------------------------------------------------------

    /**
     * A new staged file, holding $content: [token, path], or [null, ''] when
     * the staging folder cannot be written.
     *
     * @return array{0: ?string, 1: string}
     */
    private function stage(string $content = ''): array
    {
        $dir = $this->stagingDir();
        $token = \bin2hex(\random_bytes(32));
        $file = $dir . '/' . $token;
        $old = \umask(0o177);

        try {
            $handle = @\fopen($file, 'x');

            if ($handle === false) {
                return [null, ''];
            }

            $ok = \fwrite($handle, $content) === \strlen($content);
            \fclose($handle);
        } finally {
            \umask($old);
        }

        if (!$ok) {
            @\unlink($file);

            return [null, ''];
        }

        return [$token, $file];
    }

    /**
     * A question that changes nothing: not audited.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $args
     */
    private function ask(array $account, string $op, array $args, float $timeout = self::QUICK): FileOutcome
    {
        return $this->run(null, null, $account, $op, $args, [], $timeout, audit: false);
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $args
     * @param array<string, mixed> $details
     */
    private function run(?Identity $actor, ?Request $request, array $account, string $op, array $args, array $details, float $timeout = self::QUICK, bool $audit = true): FileOutcome
    {
        if (!\in_array($account['status'] ?? '', [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
            return FileOutcome::refused('not_ready', 'Files.error_not_ready');
        }

        $username = (string) $account['username'];
        $action = $op;

        try {
            $reply = $this->agent->call($op, ['username' => $username] + $args, id: Protocol::newId(), timeout: $timeout);
        } catch (AgentUnavailable) {
            return FileOutcome::refused('unreachable', 'Files.error_unreachable');
        }

        if ($reply->done()) {
            if ($audit) {
                $this->audit->record($action, $actor, $request, $username, AuditLog::OK, $details);
            }

            return FileOutcome::done($reply->result);
        }

        $code = (string) $reply->errorCode;
        $message = (string) $reply->errorMessage;
        $outcome = match (true) {
            \str_starts_with($code, 'files_') => FileOutcome::refused($code, 'Files.error_' . $code, $message),
            $code === 'bad_args' => FileOutcome::refused('input', $message, $message),
            $code === 'timeout' => FileOutcome::refused('timeout', 'Files.error_timeout', $message),
            $code === 'busy' => FileOutcome::refused('busy', 'Files.error_busy', $message),
            default => FileOutcome::failed($code, $message),
        };

        if ($audit) {
            $this->audit->record($action, $actor, $request, $username, AuditLog::FAILED, $details + ['reason' => $code, 'error' => $message]);
        }

        return $outcome;
    }
}
