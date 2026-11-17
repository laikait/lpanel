<?php

declare(strict_types=1);

namespace App\Modules\Files\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Files\FileManager;
use App\Modules\Files\FileOutcome;
use App\Modules\Identity\PanelUsers;

/**
 * files:* from the shell, as root: an account's files through the agent, as
 * the account's own user, the same as the User panel and the API. Paths are
 * relative to the account's home.
 */
final class FileCommands
{
    public function __construct(
        private readonly FileManager $files,
        private readonly Accounts $accounts,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    public function list(Output $output, string $username, string $path = ''): int
    {
        return $this->with($output, $username, $path, function (array $account, string $path) use ($output): int {
            $outcome = $this->files->list($account, $path);

            if (!$outcome->ok()) {
                return $this->fail($output, $outcome);
            }

            foreach (\is_array($outcome->result['entries'] ?? null) ? $outcome->result['entries'] : [] as $e) {
                $name = (string) $e['name'] . ($e['type'] === 'dir' ? '/' : '') . ($e['type'] === 'link' ? ' -> ' . (string) ($e['target'] ?? '') : '');
                $output->line(\sprintf('%s  %10s  %s  %s', (string) $e['mode'], $e['type'] === 'dir' ? '-' : self::bytes((int) $e['size']), \gmdate('Y-m-d H:i', (int) $e['mtime']), $name));
            }

            if (($outcome->result['truncated'] ?? false) === true) {
                $output->line('(the folder has more entries than are listed)');
            }

            return 0;
        });
    }

    /** A file's text, as it is, on standard output. */
    public function read(Output $output, string $username, string $path): int
    {
        return $this->with($output, $username, $path, function (array $account, string $path) use ($output): int {
            $outcome = $this->files->read($account, $path);

            if (!$outcome->ok()) {
                return $this->fail($output, $outcome);
            }

            if (($outcome->result['binary'] ?? false) === true) {
                $output->error('That is not a text file; use files:download.');

                return 1;
            }

            \fwrite(\STDOUT, (string) $outcome->result['content']);

            return 0;
        });
    }

    /** A file's new content, read from standard input. */
    public function write(Output $output, string $username, string $path, ?string $expect = null, bool $create = false): int
    {
        return $this->with($output, $username, $path, function (array $account, string $path) use ($output, $expect, $create): int {
            $content = (string) \stream_get_contents(\STDIN, FileManager::MAX_EDIT + 1);

            return $this->done($output, $this->files->save($this->root(), null, $account, $path, $content, $expect, $create), 'Saved /' . $path . '.');
        });
    }

    /** Copy a file from this machine into the account's home. */
    public function upload(Output $output, string $username, string $local, string $dir = '', ?string $name = null, bool $overwrite = false): int
    {
        if (!\is_file($local) || !\is_readable($local)) {
            $output->error(\sprintf('%s is not a file that can be read.', $local));

            return 1;
        }

        return $this->with($output, $username, $dir, fn(array $account, string $dir): int => $this->done(
            $output,
            $this->files->upload($this->root(), null, $account, $dir, $name ?? \basename($local), $local, $overwrite, uploaded: false),
            'Uploaded.',
        ));
    }

    /** Copy a file out of the account's home to this machine. */
    public function download(Output $output, string $username, string $path, string $local): int
    {
        if (\file_exists($local)) {
            $output->error(\sprintf('%s is already there.', $local));

            return 1;
        }

        return $this->with($output, $username, $path, function (array $account, string $path) use ($output, $local): int {
            $outcome = $this->files->download($this->root(), null, $account, $path);

            if (!$outcome->ok()) {
                return $this->fail($output, $outcome);
            }

            $staged = (string) $outcome->result['staged'];
            $ok = @\copy($staged, $local);
            @\unlink($staged);

            if (!$ok) {
                $output->error(\sprintf('Could not write %s.', $local));

                return 1;
            }

            $output->success(\sprintf('Saved %s (%s).', $local, self::bytes((int) $outcome->result['size'])));

            return 0;
        });
    }

    public function mkdir(Output $output, string $username, string $path): int
    {
        return $this->with($output, $username, $path, fn(array $a, string $p): int => $this->done($output, $this->files->mkdir($this->root(), null, $a, $p), 'Folder made.'));
    }

    public function rename(Output $output, string $username, string $from, string $to): int
    {
        $to = FileManager::clean($to);

        return $to === null ? $this->badPath($output) : $this->with($output, $username, $from, fn(array $a, string $p): int => $this->done($output, $this->files->rename($this->root(), null, $a, $p, $to), 'Renamed.'));
    }

    public function move(Output $output, string $username, string $path, string $to): int
    {
        $to = FileManager::clean($to);

        return $to === null ? $this->badPath($output) : $this->with($output, $username, $path, fn(array $a, string $p): int => $this->done($output, $this->files->move($this->root(), null, $a, [$p], $to), 'Moved.'));
    }

    public function copy(Output $output, string $username, string $path, string $to): int
    {
        $to = FileManager::clean($to);

        return $to === null ? $this->badPath($output) : $this->with($output, $username, $path, fn(array $a, string $p): int => $this->done($output, $this->files->copy($this->root(), null, $a, [$p], $to), 'Copied.'));
    }

    public function delete(Output $output, string $username, string $path): int
    {
        return $this->with($output, $username, $path, fn(array $a, string $p): int => $this->done($output, $this->files->delete($this->root(), null, $a, [$p]), 'Deleted.'));
    }

    public function chmod(Output $output, string $username, string $path, string $mode): int
    {
        return $this->with($output, $username, $path, fn(array $a, string $p): int => $this->done($output, $this->files->chmod($this->root(), null, $a, [$p], $mode), 'Permissions set.'));
    }

    public function compress(Output $output, string $username, string $path, string $to): int
    {
        $to = FileManager::clean($to);

        return $to === null ? $this->badPath($output) : $this->with($output, $username, $path, fn(array $a, string $p): int => $this->done($output, $this->files->compress($this->root(), null, $a, [$p], $to), 'Compressed into /' . $to . '.'));
    }

    public function extract(Output $output, string $username, string $path, string $to = ''): int
    {
        $to = FileManager::clean($to);

        return $to === null ? $this->badPath($output) : $this->with($output, $username, $path, function (array $a, string $p) use ($output, $to): int {
            $outcome = $this->files->extract($this->root(), null, $a, $p, $to);

            return $this->done($output, $outcome, $outcome->ok() ? \sprintf('Extracted %d files and %d folders into /%s%s.', (int) $outcome->result['files'], (int) $outcome->result['dirs'], $to, (int) $outcome->result['skipped'] > 0 ? \sprintf('; %d entries left out (already there, links, or names outside the folder)', (int) $outcome->result['skipped']) : '') : '');
        });
    }

    /** What in a folder takes the account's space, largest first. */
    public function usage(Output $output, string $username, string $path = ''): int
    {
        return $this->with($output, $username, $path, function (array $account, string $path) use ($output): int {
            $outcome = $this->files->usage($account, $path);

            if (!$outcome->ok()) {
                return $this->fail($output, $outcome);
            }

            foreach (\is_array($outcome->result['entries'] ?? null) ? $outcome->result['entries'] : [] as $e) {
                $output->line(\sprintf('%10s  %8s  %s', self::bytes((int) $e['bytes']), $e['type'] === 'dir' ? (string) $e['items'] : '', (string) $e['name'] . ($e['type'] === 'dir' ? '/' : '')));
            }

            $output->line(\sprintf('%10s  %8d  total%s', self::bytes((int) $outcome->result['bytes']), (int) $outcome->result['items'], ($outcome->result['truncated'] ?? false) === true ? ' (stopped early: the folder is very large)' : ''));

            return 0;
        });
    }

    /** Remove staged uploads and downloads nobody collected. */
    public function sweep(Output $output): int
    {
        $output->line(\sprintf('Removed %d staged files.', $this->files->sweep()));

        return 0;
    }

    // -------------------------------------------------------------------------

    /**
     * Find the account and clean the path, then run.
     *
     * @param \Closure(array<string, mixed>, string): int $run
     */
    private function with(Output $output, string $username, string $path, \Closure $run): int
    {
        $account = $this->accounts->findByUsernameUnscoped($username);

        if ($account === null) {
            $output->error(\sprintf('There is no account named %s.', $username));

            return 1;
        }

        $clean = FileManager::clean($path);

        return $clean === null ? $this->badPath($output) : $run($account, $clean);
    }

    private function done(Output $output, FileOutcome $outcome, string $message): int
    {
        if (!$outcome->ok()) {
            return $this->fail($output, $outcome);
        }

        $output->success($message);

        return 0;
    }

    private function fail(Output $output, FileOutcome $outcome): int
    {
        $output->error($this->localization->get($outcome->message) . ($outcome->detail !== '' && $outcome->detail !== $outcome->message ? ' (' . $outcome->detail . ')' : ''));

        return 1;
    }

    private function badPath(Output $output): int
    {
        $output->error('That is not a path within the home (no "..").');

        return 1;
    }

    private static function bytes(int $bytes): string
    {
        $n = (float) $bytes;

        foreach (['KB', 'MB', 'GB'] as $unit) {
            $n /= 1024;

            if ($n < 1024) {
                return $bytes < 1024 ? $bytes . ' B' : \sprintf('%.1f %s', $n, $unit);
            }
        }

        return \sprintf('%.1f TB', $n / 1024);
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
