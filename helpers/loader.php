<?php

/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Laika\Engine\Relay\Relay;
use Laika\Engine\Relay\RelayRegistry;
use Laika\Engine\Relay\RelayBootstrap;
use Laika\Engine\System\MemoryManager;
use Laika\Engine\Route\Invoke;

// Define APP Path
defined('APP_PATH') || define('APP_PATH', realpath(__DIR__ . '/../../../../'));

// Define DEBUG
defined('DEBUG') || define('DEBUG', true);

// Define Directory Separator
defined('DS') || define('DS', DIRECTORY_SEPARATOR);

// Defiene Storage Paht
defined('STORAGE_PATH') || define('STORAGE_PATH', APP_PATH . DS . 'lf-storage');

// Define Template Path
defined('TEMPLATE_PATH') || define('TEMPLATE_PATH', APP_PATH . DS . 'template');

// Define Template Cache Path
defined('TEMPLATE_CACHE_PATH') || define('TEMPLATE_CACHE_PATH', STORAGE_PATH . DS . 'cache' . DS . 'template');

// Define Config Path
defined('CONFIG_PATH') || define('CONFIG_PATH', APP_PATH . DS . 'lf-config');

// Define Language Path
defined('LANG_PATH') || define('LANG_PATH', APP_PATH . DS . 'lf-lang');

// Apply lf-inc/const.php's MEMORY_LIMIT (web) or CLI_MEMORY_LIMIT (CLI). It only
// ever lowers memory_limit. Only the queue worker used to apply it, so
// MEMORY_LIMIT had no effect on web requests.
(new MemoryManager())->apply();

####################################################################################
/*--------------------------------- RELAY LOADER ---------------------------------*/
####################################################################################

// Build The Container
//
// RelayBootstrap decides which providers are registered and in what order -- core
// first, then packages, then the application, because RelayRegistry::singleton() is
// last-write-wins. This file keeps the wiring, so the whole sequence stays readable
// in one place.
$registry  = new RelayRegistry();
$providers = RelayBootstrap::providers($registry);

// Wire Registry
Relay::setRegistry($registry);

// Wire The Router To The Container
//
// The router has no container of its own - it depends on nothing but PHP. This is
// the single line that joins the two. Pipelines, filters and controllers are built
// through RelayRegistry::make() from here on, so their constructor dependencies are
// auto-wired; without this call the router falls back to a plain `new`.
Invoke::setResolver(static fn(string $class): object => $registry->make($class));

// Boot Providers
//
// Phase two of the provider lifecycle: every service is registered by now, so a
// provider's boot() may resolve any of them. It is also where CoreProviders installs
// the error handler, so everything below this line is covered by a real error page
// rather than a bare fatal.
$providers->boot();

// Query Result Caching
//
// Off unless lf-config/cache.php turns it on, and even then a query is only
// cached when it calls ->remember(). Model does not depend on the cache module,
// so the store is handed over as a resolver: nothing is built or connected
// until a remembered query runs.
try {
    $queryCache = (array) (\Laika\Engine\Helper\Config::get('cache', 'query') ?? []);
} catch (\Throwable) {
    $queryCache = [];
}

if (!empty($queryCache['enabled'])) {
    \Laika\Engine\Model\Model::setQueryCache(
        static fn (): object => \Laika\Engine\Services\Cache::driver(),
        (int) ($queryCache['ttl'] ?? 60)
    );
}
unset($queryCache);

// Reset Per-Process State Between Queue Jobs
//
// The same shape as the line above: the queue module depends on nothing, so it
// cannot know what the framework memoises. Without pcntl it runs every job in one
// long-lived process, and config, options and the rest would otherwise stay as
// the first job left them for the life of the worker.
\Laika\Engine\Queue\Worker::beforeJob(static function (): void {
    foreach (\Laika\Engine\System\ProcessState::reset() as $failure) {
        fwrite(STDERR, "[laika] process reset: {$failure}\n");
    }
});

// Relay providers are the only resource read during autoload. Every other resource
// stays lazy: packages - laika-engine included - declare them in composer.json under
// extra.laika.resources, and Resource discovers them from
// vendor/composer/installed.json on first use.
