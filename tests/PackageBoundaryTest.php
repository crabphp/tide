<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\Model;
use Crab\Tide\ModelQuery;
use PHPUnit\Framework\TestCase;

final class PackageBoundaryTest extends TestCase
{
    public function testSourceUsesOnlyContractsAndTheColumnNameAttribute(): void
    {
        $violations = [];
        foreach (glob(dirname(__DIR__) . '/src/*.php') as $file) {
            $source = file_get_contents($file);
            preg_match_all('/\bCrab\\\\[A-Za-z_\\\\]*[A-Za-z_]/', $source, $names);
            foreach ($names[0] as $name) {
                if (
                    str_starts_with($name, 'Crab\\Contracts\\')
                    || str_starts_with($name, 'Crab\\Tide')
                    || $name === 'Crab\\Database\\ColumnName'
                ) {
                    continue;
                }
                $violations[] = basename($file) . ': ' . $name;
            }
            self::assertStringContainsString('declare(strict_types=1);', $source);
            self::assertDoesNotMatchRegularExpression('/function\s+__(get|set|call|callStatic)\s*\(/', $source);
        }
        self::assertSame([], $violations);
    }

    public function testTheModelOwnsOnlyItsSnapshotAndTheQueryOnlySelectsModels(): void
    {
        $model = new \ReflectionClass(Model::class);
        self::assertNull($model->getConstructor());
        self::assertCount(1, $model->getProperties());
        self::assertTrue($model->getProperty('storedValues')->isPrivate());
        // A model query chooses rows of its model, and which of its own columns to read, and loads their
        // relations; what cannot be told from the model's types, joins, expressions, raw values, is the builder's.
        self::assertTrue(method_exists(ModelQuery::class, 'select'));
        self::assertTrue(method_exists(ModelQuery::class, 'except'));
        foreach ([
            'addSelect',
            'selectRaw',
            'selectSub',
            'distinct',
            'join',
            'groupBy',
            'having',
            // A shape a model is not is the query builder's, started on the database: there is no handoff.
            'into',
            'tap',
            'when',
            'whereRaw',
            'sum',
            'avg',
            'min',
            'max',
            'pluck',
            'value',
            'update',
            'increment',
            'decrement',
            'delete',
        ] as $method) {
            self::assertFalse(method_exists(ModelQuery::class, $method), $method);
        }
        self::assertFalse(method_exists(Model::class, 'insertMany'));
        self::assertFalse(method_exists(Model::class, 'saveMany'));
        self::assertFalse(is_subclass_of(ModelQuery::class, \Crab\Contracts\Database\Query::class));
    }
}
