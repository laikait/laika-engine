<?php

declare(strict_types=1);

defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

// Fixture for QueueExtendTest: names drivers only an extend() call provides.
return [
    'driver'        => 'fake',
    'failed_driver' => 'fake-failed',
    'marker'        => 'from-config',
];
