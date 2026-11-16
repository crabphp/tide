<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Http\HttpError;

/**
 * Thrown by findOrFail() and firstOrFail() when no row matches. It is
 * answered with a 404 whose message names the model in words, "No such
 * order item.", never the class or the key asked for; both are kept here
 * for the code that catches it.
 */
final class ModelNotFound extends \RuntimeException implements HttpError
{
    public readonly int $status;

    /**
     * @param class-string<Model> $model
     */
    public function __construct(
        public readonly string $model,
        public readonly int|string|null $id = null,
    ) {
        parent::__construct('No such ' . str_replace('_', ' ', Names::snake($model)) . '.');
        $this->status = 404;
    }
}
