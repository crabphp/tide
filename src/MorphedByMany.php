<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * The other side of a MorphToMany: the models of one kind that the pivot
 * links to this one, read from the rows whose type column holds their morph
 * name. The name is the one the MorphToMany gives:
 *
 *     #[MorphedByMany(Post::class, 'taggable')]   // the posts tagged with this tag
 *     public array $posts;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class MorphedByMany
{
    /**
     * @param class-string<Model> $related
     * @param string $name What the pivot's type and id columns are named after: taggable_type, taggable_id.
     * @param string|null $pivot The pivot table; the name made plural when left out, taggables.
     * @param string|null $key The pivot's column holding this model's key; tag_id for Tag.
     */
    public function __construct(
        public string $related,
        public string $name,
        public ?string $pivot = null,
        public ?string $key = null,
    ) {
        Table::identifier($name, self::class . '::$name');
        if ($pivot !== null) {
            Table::identifier($pivot, self::class . '::$pivot');
        }
        if ($key !== null) {
            Table::identifier($key, self::class . '::$key');
        }
    }
}
