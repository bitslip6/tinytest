<?php declare(strict_types=1);
// Deploy ONLY behind TLS/access controls in a dedicated PHP-FPM pool, never in
// the agent host's request. Configure a real request_terminate_timeout in FPM.
// See new-agents.md. No proc_open, exec, shell, POSIX extension or CLI is needed.
require_once dirname(__DIR__) . '/src/http_worker.php';
\TinyTest\serve_http_tests(
    (string) getenv('TINYTEST_HTTP_PROJECT_ROOT'),
    (string) getenv('TINYTEST_HTTP_TOKEN'),
    getenv('TINYTEST_HTTP_BOOTSTRAP') === false || getenv('TINYTEST_HTTP_BOOTSTRAP') === ''
        ? null : getenv('TINYTEST_HTTP_BOOTSTRAP')
);
