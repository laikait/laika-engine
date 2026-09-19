<?php

declare(strict_types=1);

namespace Laika\Engine\Route\Contracts;

interface PipelineInterface
{
    public function handle(callable $next, array &$params): ?string;
}
