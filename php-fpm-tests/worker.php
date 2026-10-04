<?php declare(strict_types=1);
// TEST DEPLOYMENT ONLY: dedicated worker pool, never the driver pool.
require_once dirname(__DIR__) . '/src/http_worker.php';
\TinyTest\serve_http_tests(
    __DIR__ . '/fixtures',
    (string) getenv('TINYTEST_FPM_TEST_TOKEN'),
    'bootstrap.php'
);
