<?php

declare(strict_types=1);

// Shared bootstrap for the suites that need nothing but the autoloader
// (Model, Session). A suite that needs its own APP_PATH has its own bootstrap
// under tests/<Module>/, because the autoloader runs helpers/loader.php, which
// defines APP_PATH if nothing has yet.
require_once __DIR__ . '/../vendor/autoload.php';
