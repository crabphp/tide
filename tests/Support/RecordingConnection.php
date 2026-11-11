<?php

declare(strict_types=1);

namespace Crab\Tide\Tests\Support;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Query;

/**
 * Decorates the real statement boundary, keeping SQL and bindings at call time.
 */
final class RecordingConnection implements Connection
{
    public array $statements = [];

    public function __construct(
        private Connection $connection,
    ) {}

    public function table(string|Query $table, ?string $as = null): Query
    {
        $query = $this->connection->table($table, $as);
        // Test instrumentation preserves the real builder and intercepts only I/O.
        $copy = new \ReflectionClass($query)->newInstance($this, $table, as: $as);
        return $copy;
    }

    public function driver(): string
    {
        return $this->connection->driver();
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->statements[] = [$sql, $bindings];
        return $this->connection->select($sql, $bindings);
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        $this->statements[] = [$sql, $bindings];
        return $this->connection->first($sql, $bindings);
    }

    public function insert(string $sql, array $bindings = []): int|string
    {
        $this->statements[] = [$sql, $bindings];
        return $this->connection->insert($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->statements[] = [$sql, $bindings];
        return $this->connection->execute($sql, $bindings);
    }

    public function transaction(\Closure $callback): mixed
    {
        return $this->connection->transaction($callback);
    }

    public function afterCommit(\Closure $callback): void
    {
        $this->connection->afterCommit($callback);
    }
}
