<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * The rows of a model whose #[MorphTo] points here: Post's comments are the
 * comments whose type column holds Post's morph name and whose id column holds
 * the post's key. The related model's #[MorphTo] is found by the models it
 * lists; name it when there are several.
 *
 *     #[MorphMany(Comment::class)] public array $comments;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class MorphMany
{
    /**
     * @param class-string<Model> $related
     * @param string|null $relation The related model's #[MorphTo] property, when several list this model.
     * @param string|null $localKey This model's column the id column holds; its key when left out.
     */
    public function __construct(
        public string $related,
        public ?string $relation = null,
        public ?string $localKey = null,
    ) {
        if ($relation !== null) {
            Table::identifier($relation, self::class . '::$relation');
        }
        if ($localKey !== null) {
            Table::identifier($localKey, self::class . '::$localKey');
        }
    }
}
