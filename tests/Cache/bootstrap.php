<?php

declare(strict_types=1);

// The file driver falls back under APP_PATH when no path is configured; point
// that fallback at a scratch directory so the suite never writes somewhere shared.
// Defined before the autoloader, which runs helpers/loader.php and would
// otherwise define APP_PATH first.
defined('APP_PATH') || define('APP_PATH', sys_get_temp_dir() . '/laika-cache-tests');

require_once __DIR__ . '/../../vendor/autoload.php';
