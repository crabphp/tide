<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Plugin;

final class TidePlugin implements Plugin
{
    public function register(Container $container): void
    {
        Tide::use(static fn(): Database => $container->get(Database::class));
    }
}
