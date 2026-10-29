<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup\Drivers;

use App\Engine\Config\Env;
use App\Engine\Database\Connection;
use App\Engine\Security\Secret;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Tests\Fixtures\Modules\Backup\BackupException;
use App\Tests\Fixtures\Modules\Backup\Dsn;

/**
 * mysqldump out, mysql in. Both need `mysqldump`/`mysql` named in
 * config/system.php's commands.allowed -- see docs/guides/backups.md --
 * or CommandExecutor refuses them before either ever runs.
 */
final class MySqlDriver implements DriverInterface
{
    public function __construct(private readonly CommandExecutor $executor) {}

    public function extension(): string
    {
        return 'sql';
    }

    public function backup(Connection $connection, Dsn $dsn, string $destination): void
    {
        $result = $this->executor->run(new Command('mysqldump', [
            '--single-transaction',
            '--no-tablespaces',
            ...$this->connectionArguments($connection, $dsn),
        ], environment: $this->environment($connection)))->orFail();

        if (\file_put_contents($destination, $result->stdout(), \LOCK_EX) === false) {
            throw BackupException::couldNotWrite($destination);
        }
    }

    public function restore(Connection $connection, Dsn $dsn, string $source): void
    {
        $contents = @\file_get_contents($source);

        if ($contents === false) {
            throw BackupException::sourceNotFound($source);
        }

        $this->executor->run(new Command(
            'mysql',
            $this->connectionArguments($connection, $dsn),
            environment: $this->environment($connection),
            stdin: $contents,
        ))->orFail();
    }

    /** @return list<string> */
    private function connectionArguments(Connection $connection, Dsn $dsn): array
    {
        $arguments = [];

        if ($dsn->host !== null) {
            $arguments[] = '-h';
            $arguments[] = $dsn->host;
        }

        if ($dsn->port !== null) {
            $arguments[] = '-P';
            $arguments[] = (string) $dsn->port;
        }

        if ($connection->config()->username !== null) {
            $arguments[] = '-u';
            $arguments[] = $connection->config()->username;
        }

        if ($dsn->database === null) {
            throw BackupException::namesNoDatabase($connection->name());
        }

        $arguments[] = $dsn->database;

        return $arguments;
    }

    /**
     * A Command's environment, when given, replaces the executor's default
     * entirely -- it is not merged with it (Invocation::environmentFor) --
     * so adding MYSQL_PWD means starting from the same inherited variables
     * a Command with no environment at all would have gotten, PATH among
     * them, or `mysqldump` itself could not be found.
     *
     * @return array<string, string|Secret>|null
     */
    private function environment(Connection $connection): ?array
    {
        $password = $connection->config()->password;

        if ($password === null) {
            return null;
        }

        $environment = [];

        foreach (CommandExecutor::INHERITED_ENVIRONMENT as $name) {
            $value = Env::raw($name);

            if ($value !== null) {
                $environment[$name] = $value;
            }
        }

        $environment['MYSQL_PWD'] = new Secret($password);

        return $environment;
    }
}
