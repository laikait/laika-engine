<?php

declare(strict_types=1);

// Asset::serve() resolves every path against APP_PATH, so the fixture tree is
// the application root for the whole suite. Defined before the autoloader,
// which runs helpers/loader.php and would otherwise define APP_PATH first.
define('APP_PATH', __DIR__ . '/fixtures/app');

// The Config and MimeType doubles must be declared before the autoloader runs
// helpers/loader.php, which reads Config and would load the real class first.
require_once __DIR__ . '/Stub/Service.php';
require_once __DIR__ . '/../../vendor/autoload.php';
