<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * Has Tide keep a model's times: when it is first saved and when it is
 * last changed. Written on the model, so that a reader sees it:
 *
 *     #[Table('books'), Timestamps]
 *     final class Book extends Model
 *     {
 *         public ?\DateTimeImmutable $created_at = null;
 *         public ?\DateTimeImmutable $updated_at = null;
 *     }
 *
 * Give another column's name, or null for a time the table does not have:
 * #[Timestamps(updated: null)].
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Timestamps
{
    public function __construct(
        public ?string $created = 'created_at',
        public ?string $updated = 'updated_at',
    ) {
        foreach ([$created, $updated] as $column) {
            if ($column !== null) {
                Table::identifier($column, '#[Timestamps]');
            }
        }
    }
}
