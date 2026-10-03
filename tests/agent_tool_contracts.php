<?php declare(strict_types=1);
// Standalone host contract: no TinyTest bootstrap/runtime in this process.
interface AgentTool
{
    public function name(): string;
    public function definition(): array;
    public function execute(array $args): array;
}
require_once dirname(__DIR__) . '/src/agent_tool.php';

function tool_check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$directory = sys_get_temp_dir() . '/tinytest tool ' . bin2hex(random_bytes(6));
mkdir($directory);
file_put_contents($directory . '/bootstrap.php', '<?php $GLOBALS["host_marker"] = "child"; echo "bootstrap noise";');
file_put_contents($directory . '/test_project.php', <<<'PHP'
<?php
function test_pass() { assert_eq($GLOBALS['host_marker'], 'child', 'bootstrap loaded'); echo 'body noise'; }
function test_fail() { assert_eq(1, 2, 'intentional'); }
function test_empty() {}
function test_exit() { fwrite(STDERR, 'child stderr'); exit(0); }
function test_fatal() { require 'missing-file.php'; }
PHP
);
$initial = [getcwd(), ini_get_all(null, false), error_reporting(), ob_get_level(), get_included_files()];
$GLOBALS['host_marker'] = 'host';
$worker = getenv('TINYTEST_JSON_WORKER');
putenv('TINYTEST_JSON_WORKER=1');
$failure = null;
ob_start();
try {
    $tool = new TinyTest\TestRunnerTool($directory, PHP_BINARY, 'bootstrap.php');
    tool_check($tool instanceof AgentTool, 'must implement host interface');
    $definition = $tool->definition();
    tool_check($definition['type'] === 'function' && $definition['name'] === $tool->name()
        && $definition['strict'] === true, 'Responses function definition');
    json_encode($definition, JSON_THROW_ON_ERROR);
    $args = ['directory' => null, 'files' => ['test_project.php'], 'test' => 'test_pass', 'list' => false];
    foreach (['test_pass' => 0, 'test_fail' => 1, 'test_empty' => 1, 'test_exit' => 2,
        'test_fatal' => 1, 'test_nonexistent' => 2] as $name => $expected) {
        $report = $tool->execute(array_replace($args, ['test' => $name]));
        tool_check($report['exit_code'] === $expected, "$name outcome");
        json_encode($report, JSON_THROW_ON_ERROR);
        if ($name === 'test_pass') {
            tool_check(strpos($report['output']['stdout'], 'bootstrap noise') !== false, 'capture bootstrap');
            tool_check($report['tests'][0]['output'] === 'body noise', 'capture body');
        }
    }
    tool_check($tool->execute($args)['exit_code'] === 0, 'repeat after worker exit');
    $list = $tool->execute(['directory' => '.', 'files' => [], 'test' => null, 'list' => true]);
    tool_check($list['exit_code'] === 0 && $list['summary']['total'] === 5, 'directory listing');
    foreach ([[], array_replace($args, ['files' => ['../outside.php']]),
        array_replace($args, ['directory' => '.']), array_replace($args, ['list' => 'yes']),
        array_replace($args, ['shell' => 'anything'])] as $bad) {
        tool_check($tool->execute($bad)['exit_code'] === 2, 'reject invalid arguments');
    }
    $missing = new TinyTest\TestRunnerTool($directory, $directory . '/missing-php');
    tool_check($missing->execute($args)['exit_code'] === 2, 'missing PHP executable');
    tool_check(getcwd() === $initial[0] && ini_get_all(null, false) === $initial[1]
        && error_reporting() === $initial[2] && get_included_files() === $initial[4], 'host state unchanged');
    tool_check($GLOBALS['host_marker'] === 'host' && getenv('TINYTEST_JSON_WORKER') === '1', 'globals/environment unchanged');
    tool_check(!function_exists('assert_eq') && !defined('TinyTest\\ERR_OUT'), 'no host runtime initialization');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $output = ob_get_clean();
    putenv($worker === false ? 'TINYTEST_JSON_WORKER' : 'TINYTEST_JSON_WORKER=' . $worker);
    unlink($directory . '/bootstrap.php');
    unlink($directory . '/test_project.php');
    rmdir($directory);
}
if ($failure !== null || $output !== '' || ob_get_level() !== $initial[3]) {
    fwrite(STDERR, 'Agent tool contract failed: ' . ($failure ? $failure->getMessage() : 'unexpected host output/buffer change') . "\n");
    exit(1);
}
echo "Agent tool contracts passed (host isolation, repeated runs, output capture, failures, validation).\n";
