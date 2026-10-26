<?php

declare(strict_types=1);

namespace App\Engine\Database;

/**
 * The configured connections, opened on demand.
 *
 * Heavy backends rarely have one database. A reporting replica, a legacy
 * system being migrated from, a separate ledger -- each is a named connection,
 * and a repository that needs one asks for it by name.
 *
 * Nothing is opened here. The manager builds Connection objects, and a
 * Connection opens its socket on first use, so an application can declare every
 * database it might touch without paying for the ones it does not.
 */
final class ConnectionManager
{
    /** @var array<string, ConnectionConfig> */
    private array $configs = [];

    /** @var array<string, Connection> */
    private array $connections = [];

    private ?string $default = null;

    /** @var (\Closure(string, int, string, int, ?int): void)|null */
    private ?\Closure $observer = null;

    /** @var (\Closure(string, mixed...): void)|null */
    private ?\Closure $listener = null;

    /** @param list<ConnectionConfig> $configs */
    public function __construct(array $configs = [], ?string $default = null)
    {
        foreach ($configs as $config) {
            $this->configs[$config->name] = $config;
        }

        $this->default = $default;
    }

    public function add(ConnectionConfig $config, bool $asDefault = false): void
    {
        $this->configs[$config->name] = $config;

        if ($asDefault || $this->default === null) {
            $this->default = $config->name;
        }
    }

    /** The connection by name, or the default one. */
    public function connection(?string $name = null): Connection
    {
        $name ??= $this->defaultName();

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $config = $this->configs[$name] ?? throw DatabaseException::unknownConnection(
            $name,
            \implode(', ', $this->names()),
        );

        $connection = new Connection($config);

        $connection->observe($this->observer);
        $connection->listen($this->listener);

        return $this->connections[$name] = $connection;
    }

    /**
     * Observe every statement on every connection, open now or opened later.
     *
     * See Connection::observe(), including why the bindings never reach the
     * observer.
     *
     * @param (\Closure(string, int, string, int, ?int): void)|null $observer
     */
    public function observe(?\Closure $observer): void
    {
        $this->observer = $observer;

        foreach ($this->connections as $connection) {
            $connection->observe($observer);
        }
    }

    /**
     * Hear every connection's events, open now or opened later. See
     * Connection::listen() for what they are.
     *
     * @param (\Closure(string, mixed...): void)|null $listener
     */
    public function listen(?\Closure $listener): void
    {
        $this->listener = $listener;

        foreach ($this->connections as $connection) {
            $connection->listen($listener);
        }
    }

    public function defaultName(): string
    {
        if ($this->default !== null && isset($this->configs[$this->default])) {
            return $this->default;
        }

        // A single configured connection is unambiguously the default, whatever
        // it happens to be called.
        $names = $this->names();

        return match (\count($names)) {
            0 => throw DatabaseException::noConnectionsConfigured(),
            default => $names[0],
        };
    }

    public function has(string $name): bool
    {
        return isset($this->configs[$name]);
    }

    /** Whether anything at all is configured, without throwing to find out. */
    public function isConfigured(): bool
    {
        return $this->configs !== [];
    }

    /** @return list<string> */
    public function names(): array
    {
        return \array_keys($this->configs);
    }

    public function config(string $name): ConnectionConfig
    {
        return $this->configs[$name] ?? throw DatabaseException::unknownConnection(
            $name,
            \implode(', ', $this->names()),
        );
    }

    /**
     * Which connections are actually open, as opposed to merely configured.
     *
     * @return list<string>
     */
    public function opened(): array
    {
        $open = [];

        foreach ($this->connections as $name => $connection) {
            if ($connection->isConnected()) {
                $open[] = $name;
            }
        }

        return $open;
    }

    /**
     * Close everything. A worker calls this between units of work.
     *
     * Every connection is closed even if one of them had a transaction left
     * open; the first such leak is reported once they all are.
     */
    public function disconnectAll(): void
    {
        $leak = null;

        foreach ($this->connections as $connection) {
            try {
                $connection->disconnect();
            } catch (DatabaseException $e) {
                $leak ??= $e;
            }
        }

        if ($leak !== null) {
            throw $leak;
        }
    }

    /**
     * Every connection as plain data, without any password.
     *
     * @return list<array{name: string, driver: string, dsn: string, username: string|null}>
     */
    public function describe(): array
    {
        return \array_values(\array_map(
            static fn(ConnectionConfig $config): array => $config->describe(),
            $this->configs,
        ));
    }

    /**
     * Build from a configuration block.
     *
     * Taking an array rather than the Config object keeps the database layer
     * from depending on the configuration system, which is what lets a script
     * build a connection with two lines and no application around it.
     *
     * @param array<string, mixed> $connections
     */
    public static function fromArray(array $connections, ?string $default = null): self
    {
        $configs = [];

        foreach ($connections as $name => $values) {
            if (\is_string($name) && \is_array($values)) {
                /** @var array<string, mixed> $values */
                $configs[] = ConnectionConfig::fromArray($name, $values);
            }
        }

        return new self($configs, $default);
    }
}
