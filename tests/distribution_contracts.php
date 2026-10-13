<?php declare(strict_types=1);
// Independent consumer probe: never require TinyTest into the harness process.
// Copy only the documented distribution, not the repository/tests/integration.
function distribution_check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function distribution_copy(string $source, string $destination): void
{
    distribution_check(!is_link($source), 'distribution must not depend on symlinks: ' . $source);
    if (is_dir($source)) {
        distribution_check(mkdir($destination), 'create distribution directory: ' . $destination);
        foreach (scandir($source) as $name) {
            if ($name !== '.' && $name !== '..') {
                distribution_copy($source . '/' . $name, $destination . '/' . $name);
            }
        }
    } else {
        distribution_check(copy($source, $destination), 'copy distribution file: ' . $source);
    }
}

function distribution_remove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') { distribution_remove($path . '/' . $name); }
        }
        rmdir($path);
    } else { unlink($path); }
}

function distribution_run(string $runner, string $cwd, array $arguments, int $expected): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    distribution_check($stdout !== false && $stderr !== false, 'create capture streams');
    try {
        $process = proc_open(array_merge([PHP_BINARY, '-d', 'zend.assertions=1', $runner, '-j'], $arguments),
            [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, $cwd);
        distribution_check(is_resource($process), 'start distributed runner');
        fclose($pipes[0]);
        $exit = proc_close($process);
        rewind($stdout);
        rewind($stderr);
        $out = stream_get_contents($stdout);
        $err = stream_get_contents($stderr);
        distribution_check($err === '', 'runner leaked stderr: ' . $err);
        $report = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        distribution_check(is_array($report) && $exit === $expected && ($report['exit_code'] ?? null) === $expected,
            'distribution exit/report mismatch (expected ' . $expected . ', actual ' . $exit . '): ' . $out);
        distribution_check(isset($report['summary'], $report['tests'], $report['errors']), 'report shape');
        if ($expected === 2) {
            distribution_check($report['errors'] !== [], 'invocation failure needs root errors');
        } else {
            distribution_check($report['errors'] === [], 'ordinary test outcomes must not be runner errors');
        }
        return $report;
    } finally {
        fclose($stdout);
        fclose($stderr);
    }
}

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/tinytest distribution ' . bin2hex(random_bytes(6));
$failure = null;
try {
    distribution_check(mkdir($temporary), 'create temporary consumer');
    $consumer = $temporary . '/consumer project';
    $package = $consumer . '/vendor/tinytest';
    $foreign = $temporary . '/unrelated working directory';
    distribution_check(mkdir($package, 0777, true) && mkdir($consumer . '/tests') && mkdir($foreign), 'create consumer layout');
    foreach (['tinytest.php', 'assertions.php', 'user_defined.php', 'src'] as $name) {
        distribution_copy($root . '/' . $name, $package . '/' . $name);
    }
    $entries = array_values(array_diff(scandir($package), ['.', '..']));
    sort($entries);
    distribution_check($entries === ['assertions.php', 'src', 'tinytest.php', 'user_defined.php'], 'documented distribution contents only');
    file_put_contents($consumer . '/bootstrap.php', <<<'PHP'
<?php
$GLOBALS['distribution_bootstrapped'] = true;
echo 'bootstrap noise';
fwrite(STDOUT, 'direct stdout noise');
fwrite(STDERR, 'bootstrap stderr noise');
register_shutdown_function(function () { echo 'shutdown noise'; });
PHP
    );
    file_put_contents($consumer . '/tests/test_consumer.php', <<<'PHP'
<?php
echo 'file noise';
function test_pass(): void {
    echo 'body noise';
    assert_eq(2 + 2, 4, 'consumer assertions work');
    assert_true($GLOBALS['distribution_bootstrapped'] ?? false, 'consumer bootstrap loaded');
    $consumer = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR;
    $outside = array_filter(get_included_files(), function ($file) use ($consumer) {
        return strpos(realpath($file), $consumer) !== 0;
    });
    assert_eq(array_values($outside), [], 'no dependency on original checkout or foreign CWD');
}
function test_fail(): void { assert_eq(1, 2, 'deliberate consumer failure'); }
PHP
    );
    $runner = $package . '/tinytest.php';
    $bootstrap = $consumer . '/bootstrap.php';
    $file = $consumer . '/tests/test_consumer.php';
    // Relative consumer paths and absolute paths from an unrelated CWD must both work.
    foreach ([[$consumer, ['-b', 'bootstrap.php', '-f', 'tests/test_consumer.php']],
        [$foreign, ['-b', $bootstrap, '-f', $file]]] as [$cwd, $selection]) {
        $report = distribution_run($runner, $cwd, array_merge($selection, ['-t', 'test_pass']), 0);
        distribution_check($report['summary']['total'] === 1 && $report['summary']['passed'] === 1
            && $report['summary']['assertions']['total'] === 3, 'passing consumer totals');
        distribution_check($report['tests'][0]['output'] === 'body noise', 'body output isolated from JSON');
        foreach (['bootstrap noise', 'direct stdout noise', 'file noise', 'shutdown noise'] as $noise) {
            distribution_check(strpos($report['output']['stdout'] ?? '', $noise) !== false, 'capture ' . $noise);
        }
        distribution_check(strpos($report['output']['stderr'] ?? '', 'bootstrap stderr noise') !== false, 'capture project stderr');
        $failed = distribution_run($runner, $cwd, array_merge($selection, ['-t', 'test_fail']), 1);
        distribution_check($failed['summary']['failed'] === 1 && $failed['tests'][0]['status'] === 'FAIL', 'failed consumer case');
    }
    $mixed = distribution_run($runner, $foreign, ['-b', $bootstrap, '-d', $consumer . '/tests'], 1);
    distribution_check($mixed['summary']['total'] === 2 && $mixed['summary']['passed'] === 1
        && $mixed['summary']['failed'] === 1, 'consumer directory discovery');
    $list = distribution_run($runner, $foreign, ['-b', $bootstrap, '-d', $consumer . '/tests', '-l'], 0);
    distribution_check($list['summary']['total'] === 2 && $list['summary']['assertions']['total'] === 0, 'consumer listing');
    distribution_check(strpos($list['output']['stdout'] ?? '', 'body noise') === false, 'listing must not execute bodies');
    distribution_run($runner, $foreign, ['-f', $consumer . '/missing.php'], 2);
    distribution_run($runner, $foreign, ['--not-a-real-option'], 2);
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (is_dir($temporary)) { distribution_remove($temporary); }
}
if ($failure !== null) {
    fwrite(STDERR, 'Distribution contract failed: ' . $failure->getMessage() . "\n");
    exit(1);
}
echo "Distribution contracts passed (documented files only, consumer/foreign CWD, exits 0/1/2, JSON and output isolation, discovery/listing).\n";
