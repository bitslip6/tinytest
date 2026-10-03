<?php declare(strict_types=1);
namespace TinyTest;

/**
 * Host must load its global AgentTool interface first. Import this file directly;
 * do not load the CLI or acquire TinyTest runtime state in the host request.
 * Executes trusted project PHP, NOT a sandbox. Requires POSIX/proc_open/temp storage.
 * Deadline includes startup through shutdown; descendants must not detach sessions.
 */
final class TestRunnerTool implements \AgentTool
{
    private $root;
    private $php;
    private $bootstrap;
    private $timeout;

    public function __construct(string $projectRoot, string $phpBinary = PHP_BINARY, ?string $bootstrap = null, float $timeoutSeconds = 30.0)
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be finite and greater than zero');
        }
        $this->timeout = $timeoutSeconds;
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('project root must be an existing directory');
        }
        $this->root = $root;
        $this->php = $phpBinary;
        $this->bootstrap = $bootstrap === null ? null : $this->projectPath($bootstrap, false);
    }

    public function name(): string
    {
        return 'tinytest_run';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'name' => $this->name(),
            'description' => 'Run trusted PHP TinyTest tests synchronously. Select a project-relative directory or files, optionally one exact global test function. Returns the JSON report: exit_code 0 succeeds, 1 means failed/incomplete tests, 2 means runner/tool error. Listing still loads PHP files. Tests share a child process, not the host request. Server wall-clock limit: ' . $this->timeout . ' seconds. Timeout kills the process group and returns timed_out=true; narrow the selection or investigate hangs.',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'directory' => ['type' => ['string', 'null'], 'description' => 'Project-relative directory; shallow test_*.php discovery. Null when files are supplied.'],
                    'files' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Project-relative PHP files. Empty when directory is supplied.'],
                    'test' => ['type' => ['string', 'null'], 'description' => 'Exact global test function name, or null for all.'],
                    'list' => ['type' => 'boolean', 'description' => 'List tests without executing bodies/providers.'],
                ],
                'required' => ['directory', 'files', 'test', 'list'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function execute(array $args): array
    {
        $streams = [];
        $process = null;
        $pid = null;
        $started = hrtime(true);
        try {
            $arguments = $this->arguments($args);
            if (DIRECTORY_SEPARATOR !== '/' || !function_exists('proc_open') || !function_exists('posix_kill')) {
                throw new \RuntimeException('TinyTest agent tool requires POSIX process-group support (proc_open and posix_kill); Windows is unsupported');
            }
            // File descriptors avoid pipe deadlocks on noisy tests. The CLI's
            // supervisor bounds report/stream previews; cap what we read too.
            foreach ([0, 1, 2] as $index) {
                $streams[$index] = @tmpfile();
                if ($streams[$index] === false) {
                    throw new \RuntimeException('cannot allocate tool capture files');
                }
            }
            $command = array_merge([$this->php, __DIR__ . '/agent_worker.php', '-j'], $arguments);
            $environment = getenv();
            foreach (['TINYTEST_JSON_WORKER', 'TINYTEST_WORKER_EXTENSIONS', 'TINYTEST_WORKER_INI'] as $key) {
                unset($environment[$key]);
            }
            // No shell, no caller CWD/environment/INI mutations. CLI configuration
            // comes from the configured PHP executable, not the host SAPI.
            $process = @proc_open($command, $streams, $pipes, $this->root, $environment);
            if (!is_resource($process)) {
                throw new \RuntimeException('cannot start TinyTest PHP process');
            }
            $status = proc_get_status($process);
            $pid = $status['pid'];
            while ($status['running']) {
                if ((hrtime(true) - $started) / 1e9 >= $this->timeout) {
                    $this->killTree($process, $pid);
                    return ['exit_code' => 2, 'timed_out' => true,
                        'timeout_seconds' => $this->timeout,
                        'errors' => [['kind' => 'timeout',
                            'message' => 'TinyTest exceeded the ' . $this->timeout . ' second wall-clock limit. The test process group was sent SIGKILL (supervisor, worker and descendants). No complete test results are available. Run a narrower selection or investigate a hanging bootstrap, test, provider or shutdown handler; the server owns the timeout setting.',
                        ]]];
                }
                usleep(10000);
                $status = proc_get_status($process);
            }
            // PHP <8.3 can lose the exit code after proc_get_status observes exit.
            $exit = $status['exitcode'];
            $closedExit = proc_close($process);
            $process = null;
            if ($exit < 0) { $exit = $closedExit; }
            // Do not leave background descendants behind after a completed run.
            @posix_kill(-$pid, 9);
            $pid = null;
            rewind($streams[1]);
            rewind($streams[2]);
            $raw = stream_get_contents($streams[1], 20 * 1024 * 1024 + 1);
            $stderr = stream_get_contents($streams[2], 65536);
            if (strlen($raw) > 20 * 1024 * 1024) {
                throw new \RuntimeException('TinyTest CLI report exceeded tool size limit');
            }
            $report = json_decode($raw, true);
            if (!is_array($report) || !isset($report['exit_code'], $report['summary'], $report['tests'], $report['errors'])
                || $report['exit_code'] !== $exit || $stderr !== '') {
                // Interpreter startup failures may not produce TinyTest JSON.
                return ['exit_code' => 2, 'errors' => [['kind' => 'tool',
                    'message' => 'TinyTest process did not return a consistent clean JSON report',
                    'process_exit_code' => $exit,
                    'stderr' => $this->utf8($stderr),
                    'stdout' => $this->utf8(substr($raw, 0, 65536)),
                ]]];
            }
            return $report;
        } catch (\Throwable $error) {
            return ['exit_code' => 2, 'errors' => [['kind' => 'tool', 'message' => $this->utf8($error->getMessage())]]];
        } finally {
            if (is_resource($process)) {
                $this->killTree($process, $pid);
                // SIGKILL cannot be caught or delayed by project shutdown handlers.
                @proc_close($process);
            }
            foreach ($streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
        }
    }

    private function killTree($process, ?int $pid): void
    {
        // The launcher either creates a group whose ID is its PID before project
        // loading, or exits. Never signal the host's inherited process group.
        if ($pid !== null) { @posix_kill(-$pid, 9); }
        @proc_terminate($process, 9); // also covers timeout before setsid/setup
        // Cover the race where setsid completed between the two signals.
        if ($pid !== null) { @posix_kill(-$pid, 9); }
    }

    private function arguments(array $args): array
    {
        $keys = ['directory', 'files', 'test', 'list'];
        if (array_diff(array_keys($args), $keys) !== [] || array_diff($keys, array_keys($args)) !== []) {
            throw new \InvalidArgumentException('expected exactly directory, files, test and list');
        }
        if (($args['directory'] !== null && !is_string($args['directory']))
            || !is_array($args['files']) || !is_bool($args['list'])
            || ($args['test'] !== null && (!is_string($args['test']) || $args['test'] === ''))) {
            throw new \InvalidArgumentException('invalid tool argument types');
        }
        if (($args['directory'] !== null) === ($args['files'] !== [])) {
            throw new \InvalidArgumentException('select either directory or nonempty files, not both');
        }
        $arguments = [];
        if ($args['directory'] !== null) {
            $arguments = ['-d', $this->projectPath($args['directory'], true)];
        } else {
            foreach ($args['files'] as $file) {
                if (!is_string($file)) { throw new \InvalidArgumentException('files must contain strings'); }
                $arguments[] = '-f';
                $arguments[] = $this->projectPath($file, false);
            }
        }
        if ($this->bootstrap !== null) { $arguments[] = '-b'; $arguments[] = $this->bootstrap; }
        if ($args['test'] !== null) { $arguments[] = '-t' . $args['test']; }
        if ($args['list']) { $arguments[] = '-l'; }
        return $arguments;
    }

    private function projectPath(string $relative, bool $directory): string
    {
        if ($relative === '' || strpos($relative, "\0") !== false
            || $relative[0] === '/' || $relative[0] === '\\' || preg_match('/^[A-Za-z]:/', $relative)) {
            throw new \InvalidArgumentException('paths must be project-relative');
        }
        $path = realpath($this->root . DIRECTORY_SEPARATOR . $relative);
        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($path === false || ($path !== $this->root && strpos($path, $prefix) !== 0)
            || !is_readable($path) || ($directory ? !is_dir($path) : !is_file($path))) {
            throw new \InvalidArgumentException('path must resolve to a readable project ' . ($directory ? 'directory' : 'file'));
        }
        return $path;
    }

    private function utf8(string $text): string
    {
        return json_decode(json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE), true);
    }
}
