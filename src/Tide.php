<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Database;
use Psr\Clock\ClockInterface;

final class Tide
{
    private static Database|\Closure|null $database = null;
    private static ClockInterface|\Closure|null $clock = null;

    /**
     * The database model calls use, and the clock that #[Timestamps] reads:
     * the application's, or closures that give them on first use. Without
     * a clock, the time is the system's.
     */
    public static function use(Database|\Closure $database, ClockInterface|\Closure|null $clock = null): void
    {
        self::$database = $database;
        self::$clock = $clock;
    }

    /**
     * @internal The time a save is stamped with, to the second, as a column holds one.
     */
    public static function now(): \DateTimeImmutable
    {
        $clock = self::$clock;
        if ($clock instanceof \Closure) {
            $clock = $clock();
            if (!$clock instanceof ClockInterface) {
                throw new \LogicException(
                    'Tide::use() needs a clock closure returning '
                    . ClockInterface::class
                    . '; return the application clock.',
                );
            }
            self::$clock = $clock;
        }
        // In the zone a date is read back in, so that a model saved and the same model read show one time.
        $now = ($clock === null ? new \DateTimeImmutable() : $clock->now())->setTimezone(
            new \DateTimeZone(date_default_timezone_get()),
        );
        return $now->setTime((int) $now->format('G'), (int) $now->format('i'), (int) $now->format('s'));
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
