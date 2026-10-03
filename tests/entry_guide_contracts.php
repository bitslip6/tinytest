<?php declare(strict_types=1);
// Independent check: the root guide's copyable example must work in a foreign
// CWD with spaces, using directory, file, function and listing selections.
$root = dirname(__DIR__);
$guide = file_get_contents($root . '/tinytest.php');
$directory = sys_get_temp_dir() . '/tinytest guide ' . bin2hex(random_bytes(6));
mkdir($directory);
$file = $directory . '/test_example.php';
try {
    if (!preg_match('~// BEGIN RUNNABLE EXAMPLE\R(.*?)// END RUNNABLE EXAMPLE~s', $guide, $match)) {
        throw new RuntimeException('missing root guide example');
    }
    file_put_contents($file, preg_replace('/^\/\/ ?/m', '', $match[1]));
    foreach ([
        [['-d', $directory], 3, 4],
        [['-f', $file], 3, 4],
        [['-f', $file, '-t', 'test_addition'], 1, 1],
        [['-l', '-f', $file], 3, 0],
    ] as [$arguments, $total, $assertions]) {
        $process = proc_open(array_merge([PHP_BINARY, $root . '/tinytest.php', '-j'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
        if (!is_resource($process)) { throw new RuntimeException('cannot start guide example'); }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $report = json_decode($stdout, true);
        if ($exit !== 0 || $stderr !== '' || !is_array($report)
            || $report['exit_code'] !== 0 || $report['errors'] !== []
            || $report['summary']['total'] !== $total
            || $report['summary']['assertions']['total'] !== $assertions) {
            throw new RuntimeException('guide example failed: ' . $stdout . $stderr);
        }
    }
    // Every public assertion has a discoverable entry in the root reference.
    preg_match_all('/function (assert_\w+)\(/', file_get_contents($root . '/assertions.php'), $matches);
    foreach ($matches[1] as $name) {
        if (strpos($guide, $name . '(') === false) {
            throw new RuntimeException('root guide missing assertion: ' . $name);
        }
    }
    echo "Entry guide contracts passed (copyable example, selections, assertion reference).\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    if (is_file($file)) { unlink($file); }
    rmdir($directory);
}
