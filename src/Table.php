<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * A model's table, its key, its database, and its morph name: what a
 * #[MorphTo] type column holds for it, the table's name unless said.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Table
{
    public function __construct(
        public ?string $name = null,
        public string $key = 'id',
        public ?string $database = null,
        public ?string $morph = null,
    ) {
        if ($name !== null) {
            Table::identifier($name, self::class . '::$name');
        }
        if ($morph !== null) {
            Table::identifier($morph, self::class . '::$morph');
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
