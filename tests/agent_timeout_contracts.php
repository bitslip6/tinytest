<?php declare(strict_types=1);
interface AgentTool
{
    public function name(): string;
    public function definition(): array;
    public function execute(array $args): array;
}
require_once dirname(__DIR__) . '/src/agent_tool.php';

function timeout_check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

$directory = sys_get_temp_dir() . '/tinytest timeout ' . bin2hex(random_bytes(6));
mkdir($directory);
file_put_contents($directory . '/descendant.php', <<<'PHP'
<?php
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
}
file_put_contents(__DIR__ . '/descendant.pid', (string) getmypid());
while (true) { file_put_contents(__DIR__ . '/heartbeat', (string) hrtime(true)); usleep(10000); }
PHP
);
file_put_contents($directory . '/test_hang.php', <<<'PHP'
<?php
function test_hang() {
    $process = proc_open([PHP_BINARY, __DIR__ . '/descendant.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    while (true) { usleep(10000); }
}
function test_pass() { assert_true(true, 'host can run again'); }
PHP
);
file_put_contents($directory . '/hang_bootstrap.php', '<?php while (true) { usleep(10000); }');
file_put_contents($directory . '/test_shutdown.php', '<?php register_shutdown_function(function () { while (true) { usleep(10000); } }); function test_pass() { assert_true(true, "ok"); }');
file_put_contents($directory . '/test_provider.php', '<?php function rows() { while (true) { usleep(10000); } } /** @dataprovider rows */ function test_rows($row) {}');
$failure = null;
ob_start();
try {
    foreach ([0, -1, INF, NAN] as $invalid) {
        try {
            new TinyTest\TestRunnerTool($directory, PHP_BINARY, null, $invalid);
            throw new RuntimeException('accepted invalid timeout');
        } catch (InvalidArgumentException $expected) {}
    }
    $args = ['directory' => null, 'files' => ['test_hang.php'], 'test' => 'test_hang', 'list' => false];
    foreach ([['test_hang.php', 'test_hang', null],
        ['test_hang.php', 'test_pass', 'hang_bootstrap.php'],
        ['test_shutdown.php', 'test_pass', null],
        ['test_provider.php', 'test_rows', null]] as [$file, $test, $bootstrap]) {
        $tool = new TinyTest\TestRunnerTool($directory, PHP_BINARY, $bootstrap, 1.0);
        $start = hrtime(true);
        $report = $tool->execute(array_replace($args, ['files' => [$file], 'test' => $test]));
        $elapsed = (hrtime(true) - $start) / 1e9;
        timeout_check($elapsed >= 1 && $elapsed < 3, 'deadline must bound whole invocation: ' . $file);
        timeout_check($report['exit_code'] === 2 && $report['timed_out'] === true
            && $report['errors'][0]['kind'] === 'timeout' && $report['timeout_seconds'] === 1.0,
            'structured timeout result: ' . json_encode($report));
        json_encode($report, JSON_THROW_ON_ERROR);
    }
    timeout_check(is_file($directory . '/descendant.pid'), 'grandchild must actually start');
    $heartbeat = file_get_contents($directory . '/heartbeat');
    usleep(200000);
    timeout_check(file_get_contents($directory . '/heartbeat') === $heartbeat, 'grandchild must stop writing after timeout');
    // Linux exposes zombie state; zombies await the system reaper but cannot run.
    $pid = (int) file_get_contents($directory . '/descendant.pid');
    if (is_dir('/proc')) {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        timeout_check($stat === false || preg_match('/\) [ZX] /', $stat) === 1, 'grandchild still running');
    }
    $tool = new TinyTest\TestRunnerTool($directory, PHP_BINARY, null, 2.0);
    timeout_check($tool->execute(array_replace($args, ['test' => 'test_pass']))['exit_code'] === 0,
        'fresh run after timeout');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $output = ob_get_clean();
    // Cleanup even if a regression left the heartbeat process running.
    if (is_file($directory . '/descendant.pid')) { @posix_kill((int) file_get_contents($directory . '/descendant.pid'), 9); }
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}
if ($failure !== null || $output !== '') {
    fwrite(STDERR, 'Timeout contract failed: ' . ($failure ? $failure->getMessage() : 'unexpected output') . "\n");
    exit(1);
}
echo "Agent timeout contracts passed (test/descendant, bootstrap, provider, shutdown, repeat execution).\n";
