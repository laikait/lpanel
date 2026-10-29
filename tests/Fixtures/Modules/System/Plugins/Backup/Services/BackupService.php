<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\System\Plugins\Backup\Services;

use App\Engine\Auth\Identity;
use App\Engine\Config\Config;
use App\Engine\Queue\Queue;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Filesystem\SystemFilesystem;
use App\Engine\System\Security\SystemAuthorizer;
use App\Engine\System\Security\SystemCapability;
use App\Tests\Fixtures\Modules\System\Plugins\Backup\Jobs\CreateBackup;

/**
 * The business operation, in the module; the machinery, in engine/System.
 *
 * The order is the plan's: authorize who is asking, then act through the
 * managers, whose own policies decide whether the application does this at
 * all. The checksum stands in for tar or rsync: PHP is the one program certain
 * to exist wherever the suite runs.
 *
 * Two ways in. create() does it now, for the console or a short request.
 * queue() decides now and does it later, which is what a web request should
 * use for anything long: the request answers at once, and a worker -- with no
 * HTTP timeout cap -- does the work.
 */
final class BackupService
{
    public function __construct(
        private readonly SystemAuthorizer $system,
        private readonly SystemFilesystem $files,
        private readonly CommandExecutor $executor,
        private readonly Queue $queue,
        private readonly Config $config,
    ) {}

    /** @return string the manifest's SHA-256 */
    public function create(Identity $who, string $name): string
    {
        $this->authorize($who, $name);

        return $this->perform($name, $who->id);
    }

    /** Decide now, on the request; do it on a worker. */
    public function queue(Identity $who, string $name): void
    {
        $this->authorize($who, $name);
        $this->queue->push(new CreateBackup($name, $who->id));
    }

    /**
     * The work itself, after the decision. Called by create() and by the job,
     * never by anything that has not authorized first.
     *
     * @return string the manifest's SHA-256
     */
    public function perform(string $name, string $requestedBy): string
    {
        $manifest = $this->manifest($name);

        $this->files->write($manifest, (string) \json_encode(['name' => $name, 'by' => $requestedBy]));

        // Nothing sensible to do if the checksum fails, so the failure is an exception.
        return $this->executor
            ->run(new Command(\PHP_BINARY, ['-n', '-r', 'echo hash_file("sha256", $argv[1]);', '--', $manifest]))
            ->orFail()
            ->stdout();
    }

    private function authorize(Identity $who, string $name): void
    {
        $manifest = $this->manifest($name);

        $this->system->authorize($who, SystemCapability::FilesystemWrite, $manifest);
        $this->system->authorize($who, SystemCapability::CommandExecute, 'php');
    }

    private function manifest(string $name): string
    {
        if (\preg_match('/^[a-z0-9-]{1,40}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('A backup name is lowercase letters, digits and dashes.');
        }

        return \rtrim((string) $this->config->string('Backup.directory'), '/') . '/' . $name . '.json';
    }
}
