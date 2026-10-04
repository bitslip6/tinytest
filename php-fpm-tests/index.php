<?php declare(strict_types=1);
// Standalone deployment probe. Keep this in a separate pool from worker.php.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo <<<'HTML'
<!doctype html><meta charset="utf-8"><title>TinyTest FPM end-to-end probe</title>
<h1>TinyTest PHP-FPM end-to-end probe</h1>
<p>Read README.md and configure dedicated driver/worker pools first. This probe
intentionally runs an infinite loop; FPM must kill it. Do not use production pools.</p>
<label>Test bearer token <input id="token" type="password" autocomplete="off"></label>
<button id="run">Run checks (including hard termination)</button><pre id="result"></pre>
<script>
document.getElementById('run').onclick = async () => {
  const button = document.getElementById('run'); button.disabled = true;
  const output = document.getElementById('result'); output.textContent = 'Running…';
  try {
    const response = await fetch(location.pathname, {method:'POST',
      headers:{Authorization:'Bearer '+document.getElementById('token').value}});
    document.getElementById('token').value = '';
    output.textContent = await response.text();
  } catch (error) { output.textContent = String(error); }
  finally { button.disabled = false; }
};
</script>
HTML;
    return;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$token = (string) getenv('TINYTEST_FPM_TEST_TOKEN');
if (strlen($token) < 32 || !hash_equals('Bearer ' . $token, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
    http_response_code(401);
    echo '{"ok":false,"error":"Unauthorized or test token not configured"}';
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{"ok":false,"error":"POST required"}';
    return;
}

interface AgentTool
{
    public function name(): string;
    public function definition(): array;
    public function execute(array $args): array;
}
require_once dirname(__DIR__) . '/src/http_agent_tool.php';

$checks = [];
$check = function (string $name, bool $ok, $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};
$state = null;
$id = null;
$started = microtime(true);
$initial = [getcwd(), ini_get_all(null, false), error_reporting(), headers_list()];
$GLOBALS['fpm_driver_marker'] = 'driver';
ob_start();
try {
    if (PHP_SAPI !== 'fpm-fcgi') { throw new RuntimeException('Run this driver through PHP-FPM, not CLI/development server'); }
    if (getenv('TINYTEST_FPM_TEST_ENABLE_HANG') !== '1') {
        throw new RuntimeException('Explicitly set TINYTEST_FPM_TEST_ENABLE_HANG=1 after configuring the worker hard timeout');
    }
    $hard = (float) (getenv('TINYTEST_FPM_TEST_HARD_SECONDS') ?: '3');
    if (!is_finite($hard) || $hard < 1 || $hard > 10) { throw new RuntimeException('Test hard deadline must be between 1 and 10 seconds'); }
    $url = (string) getenv('TINYTEST_FPM_TEST_WORKER_URL');
    if (strpos($url, '?') !== false) { throw new RuntimeException('Use a worker URL without a query string'); }
    $base = realpath((string) getenv('TINYTEST_FPM_TEST_STATE'));
    if ($base === false || !is_dir($base) || !is_writable($base)) { throw new RuntimeException('Configure a shared writable state directory outside the web root'); }
    $id = bin2hex(random_bytes(16));
    $state = $base . DIRECTORY_SEPARATOR . $id;
    if (!mkdir($state, 0700)) { throw new RuntimeException('Cannot create test run state'); }
    $url .= '?run=' . $id;
    $allowHttp = getenv('TINYTEST_FPM_TEST_ALLOW_HTTP') === '1';
    $tool = new TinyTest\HttpTestRunnerTool($url, $token, $hard + 3, $allowHttp);
    $args = ['directory' => null, 'files' => ['test_web.php'], 'test' => 'test_pass', 'list' => false];
    $badToken = str_repeat($token[0] === 'x' ? 'y' : 'x', 32);
    $unauthorized = new TinyTest\HttpTestRunnerTool($url, $badToken, $hard + 3, $allowHttp);
    $report = $unauthorized->execute($args);
    $check('authentication_before_project_loading', ($report['errors'][0]['http_status'] ?? null) === 401
        && !file_exists($state . '/loaded'), $report);

    $healthy = false;
    foreach (['test_pass' => 0, 'test_failure' => 1, 'test_incomplete' => 1,
        'test_exit' => 2, 'test_shutdown_failure' => 2, 'test_host_isolation' => 0] as $test => $exit) {
        $report = $tool->execute(array_replace($args, ['test' => $test]));
        $ok = ($report['exit_code'] ?? null) === $exit && isset($report['summary']);
        $check($test, $ok, $ok ? ['exit_code' => $report['exit_code']] : $report);
        if ($test === 'test_pass') {
            $healthy = $ok;
            $check('output_capture', ($report['tests'][0]['output'] ?? '') === 'body noise'
                && strpos($report['output']['stdout'] ?? '', 'bootstrap noise') !== false
                && strpos($report['output']['stdout'] ?? '', 'file noise') !== false
                && strpos($report['output']['stdout'] ?? '', 'shutdown noise') !== false);
            if (!$healthy) { throw new RuntimeException('Worker health check failed; refusing to launch further fixtures'); }
            $check('worker_is_php_fpm', @file_get_contents($state . '/sapi') === 'fpm-fcgi');
        }
    }
    $list = $tool->execute(['directory' => '.', 'files' => [], 'test' => null, 'list' => true]);
    $check('list_without_bodies', ($list['exit_code'] ?? null) === 0 && ($list['summary']['total'] ?? null) === 7
        && !file_exists($state . '/heartbeat'));
    foreach (['unmatched_selector' => array_replace($args, ['test' => 'test_missing']),
        'outside_project_path' => array_replace($args, ['files' => ['../worker.php']])] as $name => $invalid) {
        $report = $tool->execute($invalid);
        $check($name, ($report['exit_code'] ?? null) === 2 && isset($report['summary']), $report['errors'] ?? $report);
    }
    if (!$healthy) { throw new RuntimeException('Worker health check failed; refusing to launch hanging fixture'); }

    $hangStarted = microtime(true);
    $report = $tool->execute(array_replace($args, ['test' => 'test_hang']));
    $elapsed = microtime(true) - $hangStarted;
    $check('hang_not_reported_as_success', ($report['exit_code'] ?? null) === 2, $report);
    // Client timeout alone is explicitly NOT enough: observe that the independent
    // heartbeat actually stopped and that no PHP shutdown handler finished it.
    clearstatcache();
    $first = @file_get_contents($state . '/heartbeat');
    usleep(1500000);
    clearstatcache();
    $second = @file_get_contents($state . '/heartbeat');
    $stopped = $first !== false && (int) $first >= 2 && $first === $second;
    $check('worker_heartbeat_stopped', $stopped, ['first' => $first, 'second' => $second,
        'pid' => @file_get_contents($state . '/hang.pid'), 'request_seconds' => round($elapsed, 3)]);
    $check('external_wall_clock_termination', $stopped && !file_exists($state . '/hang.shutdown')
        && $elapsed >= $hard - 0.5 && $elapsed < $hard + 3,
        'Expect the worker FPM log to confirm request_terminate_timeout; client/gateway timeout alone is insufficient.');
    if (!$stopped) { throw new RuntimeException('Worker may STILL be running. Stop/restart the dedicated worker pool immediately; inspect retained run artifacts.'); }
    $report = $tool->execute($args);
    $check('successful_request_after_termination', ($report['exit_code'] ?? null) === 0, $report['errors'] ?? $report);
    $check('host_state_unchanged', [getcwd(), ini_get_all(null, false), error_reporting(), headers_list()] === $initial
        && $GLOBALS['fpm_driver_marker'] === 'driver' && !function_exists('assert_eq'));
} catch (Throwable $error) {
    $check('driver', false, $error->getMessage());
} finally {
    $output = ob_get_clean();
    $check('no_tool_output_in_host', $output === '', $output === '' ? null : substr($output, 0, 4096));
}
$ok = !in_array(false, array_column($checks, 'ok'), true);
if ($ok && $state !== null) {
    foreach (glob($state . '/*') as $file) { unlink($file); }
    rmdir($state);
}
echo json_encode(['ok' => $ok, 'run_id' => $id, 'duration_seconds' => round(microtime(true) - $started, 3),
    'artifacts_retained' => !$ok && $state !== null, 'checks' => $checks],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
