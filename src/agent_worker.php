<?php declare(strict_types=1);
// Private CLI launcher for TestRunnerTool, never loaded by the core bootstrap.
// Establish the process group BEFORE loading any project code. The host can then
// kill the entire group, including the JSON supervisor's worker and descendants.
if (PHP_SAPI !== 'cli' || !function_exists('posix_setsid') || posix_setsid() === -1) {
    fwrite(STDERR, "TinyTest agent tool requires PHP CLI with POSIX session support\n");
    exit(2);
}
$argv[0] = __DIR__ . '/../tinytest.php';
require $argv[0];
