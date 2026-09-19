<?php

declare(strict_types=1);

// The extension tests read lf-config/queue.php through config(), so the fixture
// tree is the application root. Defined before the autoloader, which runs
// helpers/loader.php and would otherwise define APP_PATH first.
define('APP_PATH', __DIR__ . '/fixtures/app');

require_once __DIR__ . '/../../vendor/autoload.php';

// config() and the other global helpers are loaded by lf-boot/app.php in an
// application, not by the autoloader.
foreach (glob(__DIR__ . '/../../helpers/functions/*.php') as $file) {
    require_once $file;
}
