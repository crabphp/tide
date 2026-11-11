<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * Says which times Tide keeps for a model. Without it, a model keeps the
 * times it declares, created_at when it is first saved and updated_at at
 * each change. Write it to name other columns, or null to keep one by hand:
 *
 *     #[Timestamps(updated: 'modified_at')]
 *     #[Timestamps(created: null, updated: null)]
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Timestamps
{
    public function __construct(
        public ?string $created = 'created_at',
        public ?string $updated = 'updated_at',
    ) {
        foreach (array_filter([$created, $updated]) as $column) {
            Table::identifier($column, '#[Timestamps]');
        }
    }
}
