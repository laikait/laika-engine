<?php

declare(strict_types=1);

namespace Laika\Engine\Queue;

use Composer\Script\Event;
use Laika\Engine\Cli\ScriptHandler as EntryPoints;

/**
 * @deprecated since 2.1.0. Laika\Engine\Cli\ScriptHandler::generate() writes
 * both the `laika` and `worker` executables, so one entry in the root
 * composer.json covers what used to need two.
 *
 * Kept because removing it would break every project already wired against it:
 * Composer aborts the whole script run on a handler class it cannot autoload,
 * so `composer install` would fail until each project edited its composer.json.
 */
class ScriptHandler
{
    /**
     * Generate the project-root executables.
     *
     * Listing this alongside the Cli handler is harmless: generation compares
     * content before writing, so the second call finds both files already
     * identical and does nothing.
     */
    public static function generate(Event $event): void
    {
        EntryPoints::generate($event);
    }
}
