<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * Many to many with models of several kinds through one pivot: the pivot's
 * type column holds this model's morph name, its id column this model's key,
 * and a third column the related model's key. The name says the pivot and
 * its columns:
 *
 *     #[MorphToMany(Tag::class, 'taggable')]    // taggables: taggable_type, taggable_id, tag_id
 *     public array $tags;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class MorphToMany
{
    /**
     * @param class-string<Model> $related
     * @param string $name What the pivot's type and id columns are named after: taggable_type, taggable_id.
     * @param string|null $pivot The pivot table; the name made plural when left out, taggables.
     * @param string|null $relatedKey The pivot's column holding the related model's key; tag_id for Tag.
     */
    public function __construct(
        public string $related,
        public string $name,
        public ?string $pivot = null,
        public ?string $relatedKey = null,
    ) {
        Table::identifier($name, self::class . '::$name');
        if ($pivot !== null) {
            Table::identifier($pivot, self::class . '::$pivot');
        }
        if ($relatedKey !== null) {
            Table::identifier($relatedKey, self::class . '::$relatedKey');
        }
    }
}
