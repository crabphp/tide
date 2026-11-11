<?php

declare(strict_types=1);

namespace Crab\Tide;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Table
{
    public function __construct(
        public ?string $name = null,
        public string $key = 'id',
        public ?string $database = null,
    ) {
        if ($name !== null) {
            Table::identifier($name, self::class . '::$name');
        }
        Table::identifier($key, self::class . '::$key');
    }

    /**
     * @internal Names are identifiers, never SQL fragments.
     */
    public static function identifier(string $name, string $where): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new \LogicException(
                "{$where} must name a table or column; use letters, digits and underscores, starting with a letter or underscore.",
            );
        }
    }
}
