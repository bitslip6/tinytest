<?php declare(strict_types=1);
namespace TinyTest;

function error_details(\Throwable $error, string $phase): array
{
    $details = ['kind' => $phase, 'class' => get_class($error), 'message' => strip_ansi($error->getMessage()),
        'file' => $error->getFile(), 'line' => $error->getLine()];
    if ($error instanceof \ErrorException) { $details['severity'] = $error->getSeverity(); }
    return $details;
}

function empty_report(): array
{
    return ['version' => (int) VER, 'tests' => [], 'summary' => [
        'total' => 0, 'passed' => 0, 'failed' => 0, 'incomplete' => 0, 'skipped' => 0, 'ambiguous' => 0,
        'assertions' => ['total' => 0, 'passed' => 0, 'failed' => 0],
    ], 'errors' => [], 'exit_code' => 0];
}

function encode_report(array $report): string
{
    return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
}

function add_runner_error(array &$report, \Throwable $error, string $phase): void
{
    $report['errors'][] = error_details($error, $phase);
    $report['exit_code'] = 2;
}

// Parent owns stdout. Worker stdout/stderr (including direct writes and shutdown
// output) are separate from its report on descriptor 3. No shell interpolation.
function supervise_json(array $argv): array
{
    if (!function_exists('proc_open')) {
        throw new \RuntimeException('JSON isolation requires proc_open; enable it or use console mode');
    }
    $streams = [];
    try {
        for ($i = 1; $i <= 3; $i++) {
            $streams[$i] = tmpfile();
            if ($streams[$i] === false) { throw new \RuntimeException('cannot create JSON worker capture files'); }
        }
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if ($ini === false) { $command[] = '-n'; } else { $command[] = '-c'; $command[] = $ini; }
        // Carry startup -d settings (especially zend.assertions) into the worker.
        foreach (ini_get_all(null, false) as $key => $value) {
            $command[] = '-d';
            $command[] = $key . '="' . addcslashes((string) $value, "\\\"") . '"';
        }
        if (PHP_SAPI === 'phpdbg') { $command[] = '-q'; $command[] = '-rr'; $command[] = '-e'; }
        $command[] = dirname(__DIR__) . '/tinytest.php';
        $command = array_merge($command, array_slice($argv, 1));
        $environment = getenv();
        $environment['TINYTEST_JSON_WORKER'] = '1';
        $environment['TINYTEST_WORKER_EXTENSIONS'] = json_encode(get_loaded_extensions());
        $environment['TINYTEST_WORKER_INI'] = hash('sha256', json_encode(array_map('strval', ini_get_all(null, false))));
        $process = proc_open($command, [0 => STDIN, 1 => $streams[1], 2 => $streams[2], 3 => $streams[3]], $pipes, getcwd(), $environment);
        if (!is_resource($process)) { throw new \RuntimeException('cannot start JSON worker'); }
        $exit = proc_close($process);
        foreach ($streams as $stream) { rewind($stream); }
        $report_size = fstat($streams[3])['size'];
        $raw = $report_size <= 16 * 1024 * 1024 ? stream_get_contents($streams[3]) : '';
        $report = json_decode($raw, true);
        if (!is_array($report) || !isset($report['exit_code'], $report['summary'], $report['errors'], $report['tests'])) {
            $report = empty_report();
            $message = $report_size > 16 * 1024 * 1024 ? 'worker report exceeded the 16 MiB limit'
                : "worker terminated without a complete report (exit $exit); possible exit(), fatal error, or signal";
            add_runner_error($report, new \RuntimeException($message), 'worker');
        } elseif ($exit !== $report['exit_code']) {
            add_runner_error($report, new \RuntimeException("worker exit $exit disagrees with its completed report; possible shutdown failure"), 'worker');
        }
        // Bounded previews; temp files drain all writes without pipe deadlocks.
        foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $name) {
            $output = stream_get_contents($streams[$index], 65537);
            if ($output !== '') {
                $report['output'][$name] = substr($output, 0, 65536);
                if (strlen($output) > 65536) { $report['output'][$name . '_truncated'] = true; }
            }
        }
        return $report;
    } finally {
        foreach ($streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
    }
}

function run_cli(array $argv): int
{
    $json = json_requested($argv);
    $worker = getenv('TINYTEST_JSON_WORKER') === '1';
    $extensions = getenv('TINYTEST_WORKER_EXTENSIONS');
    $ini_hash = getenv('TINYTEST_WORKER_INI');
    // Project subprocesses must not accidentally inherit worker mode.
    putenv('TINYTEST_JSON_WORKER');
    putenv('TINYTEST_WORKER_EXTENSIONS');
    putenv('TINYTEST_WORKER_INI');
    $report = empty_report();
    $phase = 'options';
    $handler = function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) { return true; }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    };
    $previous = set_error_handler($handler);
    try {
        $options = validate_options(parse_options(cli_arguments($argv)));
        if ($worker && json_decode((string) $extensions, true) !== get_loaded_extensions()) {
            throw new \RuntimeException('worker extensions differ from parent; configure extensions in php.ini rather than startup -d extension flags');
        }
        if ($worker && $ini_hash !== hash('sha256', json_encode(array_map('strval', ini_get_all(null, false))))) {
            throw new \RuntimeException('worker PHP settings differ from parent; check php.ini and startup -d values');
        }
        if ($json && !$worker) {
            $report = supervise_json($argv);
        } else {
            $phase = 'initialization';
            init($options);
            $options['cmd'] = implode(' ', $argv);
            if (isset($options['h']) || isset($options['?'])) {
                ob_start();
                show_usage();
                $help = ob_get_clean();
                if ($json) { $report['help'] = strip_ansi($help); } else { echo $help; }
            } else {
                $phase = 'loading/execution';
                $report = run_suite($options);
            }
        }
    } catch (\Throwable $error) {
        add_runner_error($report, $error, $phase);
    } finally {
        // Cleanup failures are runner errors, and must not prevent other cleanup.
        foreach ($GLOBALS['_tinytest_cleanup'] ?? [] as $callback) {
            $previous_scope = $GLOBALS['_tinytest_assertion_scope'] ?? null;
            $scope = new AssertionScope();
            $GLOBALS['_tinytest_assertion_scope'] = $scope;
            $diagnostics = new PhpErrors();
            $cleanup_handler = [$diagnostics, 'handle'];
            $previous_cleanup_handler = set_error_handler($cleanup_handler);
            try {
                $callback();
                $error = $scope->error ?? $diagnostics->finish();
                if ($error !== null) { throw $error; }
            } catch (\Throwable $error) {
                add_runner_error($report, $error, 'cleanup');
            } finally {
                $GLOBALS['_tinytest_assertion_scope'] = $previous_scope;
                restore_handler($cleanup_handler, $previous_cleanup_handler);
            }
        }
        if (defined('TinyTest\\ERR_OUT')) { @unlink(ERR_OUT); }
        restore_handler($handler, $previous);
    }
    if ($worker) {
        $channel = fopen('php://fd/3', 'w');
        if ($channel === false) { throw new \RuntimeException('missing private JSON report channel'); }
        fwrite($channel, encode_report($report));
        fclose($channel);
    } elseif ($json) {
        echo encode_report($report);
    } else {
        foreach ($report['errors'] as $error) {
            fwrite(STDERR, $error['kind'] . ': ' . $error['message'] . "\n");
        }
    }
    return $report['exit_code'];
}
