<?php declare(strict_types=1);
// Harness may spawn a development server. The SERVER disables all process-launch
// APIs, proving that HTTP execution itself does not need proc_open or exec.
interface AgentTool
{
    public function name(): string;
    public function definition(): array;
    public function execute(array $args): array;
}
require_once dirname(__DIR__) . '/src/http_agent_tool.php';
function http_check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/tinytest http ' . bin2hex(random_bytes(6));
mkdir($directory);
file_put_contents($directory . '/router.php', '<?php '
    . 'if ($_SERVER["REQUEST_URI"] === "/invalid") { echo "not JSON"; return; } '
    . 'if ($_SERVER["REQUEST_URI"] === "/redirect") { header("Location: /redirect-target", true, 302); return; } '
    . 'if ($_SERVER["REQUEST_URI"] === "/redirect-target") { file_put_contents(__DIR__ . "/redirected", "yes"); return; } '
    . 'require ' . var_export($root . '/agent-integration/http-worker.php', true) . ';');
file_put_contents($directory . '/test_cases.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/loaded', 'yes');
echo 'load noise';
register_shutdown_function(function () { echo 'shutdown noise'; });
function test_pass() { echo 'body noise'; assert_contains(ini_get('disable_functions'), 'proc_open', 'process launch disabled'); }
function test_fail() { assert_eq(1, 2, 'intentional'); }
function test_incomplete() {}
function test_exit() { exit(0); }
function test_shutdown_error() {
    assert_true(true, 'body passed');
    register_shutdown_function(function () { throw new RuntimeException('shutdown failed'); });
}
function test_slow() { usleep(600000); file_put_contents(__DIR__ . '/finished', 'yes'); assert_true(true, 'remote continued'); }
PHP
);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$token = bin2hex(random_bytes(32));
$environment = getenv();
$environment['TINYTEST_HTTP_TOKEN'] = $token;
$environment['TINYTEST_HTTP_PROJECT_ROOT'] = $directory;
unset($environment['TINYTEST_HTTP_BOOTSTRAP']);
$log = tmpfile();
$server = proc_open([PHP_BINARY, '-d', 'disable_functions=proc_open,exec,shell_exec,system,passthru,popen',
    '-d', 'display_errors=1', '-S', $address, $directory . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $directory, $environment);
$failure = null;
ob_start();
try {
    http_check(is_resource($server), 'development server startup');
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection !== false) { fclose($connection); $ready = true; break; }
        usleep(20000);
    }
    http_check($ready, 'server ready');
    $url = 'http://' . $address . '/';
    $tool = new TinyTest\HttpTestRunnerTool($url, $token, 3, true);
    http_check($tool instanceof AgentTool && $tool->definition()['strict'] === true, 'interface/schema');
    $args = ['directory' => null, 'files' => ['test_cases.php'], 'test' => 'test_pass', 'list' => false];
    $bad = new TinyTest\HttpTestRunnerTool($url, str_repeat('x', 32), 3, true);
    http_check($bad->execute($args)['errors'][0]['http_status'] === 401 && !file_exists($directory . '/loaded'), 'authentication before project');
    $redirect = new TinyTest\HttpTestRunnerTool($url . 'redirect', $token, 3, true);
    http_check($redirect->execute($args)['errors'][0]['http_status'] === 302
        && !file_exists($directory . '/redirected'), 'do not forward credentials through redirects');
    $invalidReport = new TinyTest\HttpTestRunnerTool($url . 'invalid', $token, 3, true);
    http_check($invalidReport->execute($args)['exit_code'] === 2, 'reject invalid JSON report');
    foreach (['test_pass' => 0, 'test_fail' => 1, 'test_incomplete' => 1, 'test_exit' => 2, 'test_shutdown_error' => 2,
        'test_missing' => 2] as $test => $expected) {
        $report = $tool->execute(array_replace($args, ['test' => $test]));
        http_check($report['exit_code'] === $expected, $test . ': ' . json_encode($report));
        json_encode($report, JSON_THROW_ON_ERROR);
        if ($test === 'test_pass') {
            http_check($report['tests'][0]['output'] === 'body noise', 'body capture');
            http_check(strpos($report['output']['stdout'], 'shutdown noise') !== false, 'shutdown capture');
        }
    }
    http_check($tool->execute($args)['exit_code'] === 0, 'fresh request after shutdown failure');
    $list = $tool->execute(['directory' => '.', 'files' => [], 'test' => null, 'list' => true]);
    http_check($list['exit_code'] === 0 && $list['summary']['total'] === 6, 'list directory');
    foreach ([[], array_replace($args, ['files' => ['../outside.php']]), array_replace($args, ['directory' => '.'])] as $invalid) {
        http_check($tool->execute($invalid)['exit_code'] === 2, 'argument/path validation');
    }
    $short = new TinyTest\HttpTestRunnerTool($url, $token, 0.15, true);
    $start = microtime(true);
    $timeout = $short->execute(array_replace($args, ['test' => 'test_slow']));
    http_check(microtime(true) - $start < 1 && $timeout['timed_out'] === true
        && $timeout['termination_confirmed'] === false && $timeout['execution_state'] === 'unknown', 'honest HTTP timeout');
    usleep(700000);
    http_check(file_exists($directory . '/finished'), 'HTTP timeout must not claim it killed remote PHP');
    http_check($tool->execute($args)['exit_code'] === 0, 'another request after timeout');
    http_check(!function_exists('assert_eq'), 'host runtime untouched');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $output = ob_get_clean();
    if (is_resource($server)) { proc_terminate($server, 9); proc_close($server); }
    fclose($log);
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}
if ($failure !== null || $output !== '') {
    fwrite(STDERR, 'HTTP contract failed: ' . ($failure ? $failure->getMessage() : 'unexpected host output: ' . $output) . "\n");
    exit(1);
}
echo "HTTP agent contracts passed (no server process APIs, auth, capture, exit, shutdown failure, timeout, repeated requests).\n";
