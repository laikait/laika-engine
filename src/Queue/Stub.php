<?php

declare(strict_types=1);

namespace Laika\Engine\Queue;

class Stub
{
    public static function load(string $name): string
    {
        $path = __DIR__ . '/../../stubs/queue/' . $name . '.stub';

        if (!is_file($path)) {
            throw new \RuntimeException("Stub not found: {$name}");
        }

        return file_get_contents($path);
    }
}
