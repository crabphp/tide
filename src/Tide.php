<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Database;

final class Tide
{
    private static Database|\Closure|null $database = null;

    public static function use(Database|\Closure $database): void
    {
        self::$database = $database;
    }

    /**
     * @internal The application's database, resolved once in its worker.
     */
    public static function database(?string $name = null): Database
    {
        $database = self::$database;
        if ($database === null) {
            throw new \LogicException(
                'Tide has no database; list TidePlugin in app.php, or call Tide::use($database) in a test.',
            );
        }
        if ($database instanceof \Closure) {
            $database = $database();
            if (!$database instanceof Database) {
                throw new \LogicException(
                    'Tide::use() needs a closure returning ' . Database::class . '; return the application database.',
                );
            }
            self::$database = $database;
        }
        return $name === null ? $database : $database->connection($name);
    }
}
