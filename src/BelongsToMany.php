<?php

declare(strict_types=1);

namespace Crab\Tide;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class BelongsToMany
{
    public function __construct(
        public string $related,
        public ?string $pivot = null,
        public ?string $key = null,
        public ?string $relatedKey = null,
    ) {
        foreach (array_filter(['pivot' => $pivot, 'key' => $key, 'relatedKey' => $relatedKey]) as $name => $value) {
            Table::identifier($value, self::class . '::$' . $name);
        }
    }
}
