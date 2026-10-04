<?php declare(strict_types=1);
// Artifacts are stored outside the web root and named only by a random hex ID.
$id = $_GET['run'] ?? '';
$base = realpath((string) getenv('TINYTEST_FPM_TEST_STATE'));
if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || $base === false) {
    throw new RuntimeException('FPM test state/run ID is not configured');
}
$state = $base . DIRECTORY_SEPARATOR . $id;
if (!is_dir($state) || is_link($state)) { throw new RuntimeException('FPM test run directory missing'); }
$GLOBALS['fpm_test_state'] = $state;
file_put_contents($state . '/sapi', PHP_SAPI);
file_put_contents($state . '/loaded', "bootstrap\n", FILE_APPEND | LOCK_EX);
echo 'bootstrap noise';
