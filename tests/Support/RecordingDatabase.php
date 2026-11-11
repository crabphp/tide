<?php

declare(strict_types=1);

namespace Crab\Tide\Tests\Support;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Query;

final class RecordingDatabase implements Database
{
    public readonly RecordingConnection $recording;
    /**
     * @var \WeakMap<Connection, RecordingConnection>
     */
    private \WeakMap $loans;

    public function __construct(
        private Database $database,
    ) {
        $this->recording = new RecordingConnection($database);
        $this->loans = new \WeakMap();
    }

    public function current(): Connection
    {
        $current = $this->database->current();
        if ($current === $this->database) {
            return $this;
        }
        if (isset($this->loans[$current])) {
            return $this->loans[$current];
        }
        $recording = new RecordingConnection($current);
        $recording->statements = &$this->recording->statements;
        return $this->loans[$current] = $recording;
    }

    public function connection(string $name): Database
    {
        return new self($this->database->connection($name));
    }

    public function owns(Connection $connection): bool
    {
        return $connection === $this->recording || $this->database->owns($connection);
    }

    public function acquire(): Connection
    {
        return $this->database->acquire();
    }

    public function release(Connection $connection): void
    {
        $this->database->release($connection);
    }

    public function run(\Closure $callback): mixed
    {
        return $this->database->run($callback);
    }

    public function table(string|Query $table, ?string $as = null): Query
    {
        return $this->recording->table($table, $as);
    }

    public function driver(): string
    {
        return $this->database->driver();
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->recording->select($sql, $bindings);
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        return $this->recording->first($sql, $bindings);
    }

    public function insert(string $sql, array $bindings = []): int|string
    {
        return $this->recording->insert($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->recording->execute($sql, $bindings);
    }

    public function transaction(\Closure $callback): mixed
    {
        return $this->database->transaction($callback);
    }

    public function afterCommit(\Closure $callback): void
    {
        $this->database->afterCommit($callback);
    }

    public function afterRollback(\Closure $callback): void
    {
        $this->database->afterRollback($callback);
    }
}
