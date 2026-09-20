<?php

declare(strict_types=1);

namespace Laika\Engine\Cli;

use Composer\Script\Event;

/**
 * @deprecated since 2.1.0. `php laika app:sync` generates the executables now,
 * so the root composer.json needs one entry rather than a handler plus a sync:
 *
 *   "post-autoload-dump": [
 *       "@php laika app:sync"
 *   ]
 *
 * That works on a first install, before the root `laika` proxy exists: Composer
 * checks `@php <name>` against the filesystem and otherwise looks it up on PATH,
 * to which it has already added the bin-dir — so it resolves through
 * vendor/bin/laika, which this package ships via its "bin" entry.
 *
 * Kept because removing it would break every project still wired against it:
 * Composer aborts the whole script run on a handler class it cannot autoload,
 * so `composer install` would fail until each project edited its composer.json.
 */
class ScriptHandler
{
    /**
     * Generate the project-root executables from a Composer script.
     *
     * The project root is the parent of Composer's configured vendor-dir, so a
     * project with a custom vendor-dir still resolves correctly.
     *
     * Safe no-op for anything that isn't a Laika Framework project root (a
     * global install, or CI for this package itself), and harmless next to
     * `app:sync`: generation compares content before writing, so whichever runs
     * second finds the files already current and does nothing.
     */
    public static function generate(Event $event): void
    {
        $io        = $event->getIO();
        $vendorDir = rtrim((string) $event->getComposer()->getConfig()->get('vendor-dir'), '/\\');

        $result = EntryPoints::write(dirname($vendorDir));

        foreach ($result['missing'] as $stub) {
            $io->writeError("<warning>Laika: stub not found — {$stub}</warning>");
        }

        $notes = [];

        if ($result['written']) {
            $notes[] = 'generated ' . implode(', ', $result['written']);
        }

        if ($result['removed']) {
            $notes[] = 'removed ' . implode(', ', $result['removed']);
        }

        if ($notes) {
            $io->write('<info>Laika:</info> ' . implode(', ', $notes) . ' in project root.');
        }
    }
}
