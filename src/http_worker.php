<?php declare(strict_types=1);
namespace TinyTest;

require_once __DIR__ . '/agent_contract.php';

/** Dedicated web-request entry only. Never call from the agent host request. */
function serve_http_tests(string $projectRoot, string $token, ?string $bootstrap = null): void
{
    // Authenticate before importing runtime declarations, reading project paths or
    // loading any project PHP. One configured project per endpoint, no request root.
    if (strlen($token) < 32 || preg_match('/[\x00-\x20\x7f]/', $token)) {
        http_worker_rejection(503, 'Worker authentication is not configured');
        return;
    }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!hash_equals('Bearer ' . $token, $authorization)) {
        http_worker_rejection(401, 'Unauthorized');
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_worker_rejection(405, 'POST required');
        return;
    }
    $input = file_get_contents('php://input', false, null, 0, 65537);
    if ($input === false || strlen($input) > 65536) {
        http_worker_rejection(413, 'Request exceeds 64 KiB');
        return;
    }
    $args = json_decode($input, true);
    if (!is_array($args)) {
        http_worker_rejection(400, 'JSON object required');
        return;
    }

    require_once __DIR__ . '/bootstrap.php';
    $report = null;
    $preview = '';
    $truncated = false;
    $reserve = str_repeat('x', 65536);
    register_shutdown_function(function () use (&$reserve) { $reserve = null; });
    // Non-removable/non-flushable outer buffer: all ordinary output, including
    // shutdown output, is consumed. Emit exactly one response at request teardown.
    // This is best effort for fatal resource exhaustion; the HTTP client rejects
    // missing/malformed responses and never infers success from HTTP status alone.
    ob_start(function (string $chunk, int $phase) use (&$report, &$preview, &$truncated): string {
        $remaining = 65536 - strlen($preview);
        if (strlen($chunk) > $remaining) { $truncated = true; }
        $preview .= substr($chunk, 0, $remaining);
        if (!($phase & PHP_OUTPUT_HANDLER_FINAL)) { return ''; }
        if ($report === null) {
            $report = empty_report();
            add_runner_error($report, new \RuntimeException('HTTP worker terminated without a complete report; possible exit(), fatal error or request termination'), 'worker');
        }
        $last = error_get_last();
        if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            add_runner_error($report, new \ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']), 'worker');
        }
        if ($preview !== '') { $report['output']['stdout'] = $preview; }
        if ($truncated) { $report['output']['stdout_truncated'] = true; }
        $encoded = encode_report($report);
        if (strlen($encoded) > 16 * 1024 * 1024) {
            $report = empty_report();
            add_runner_error($report, new \RuntimeException('HTTP worker report exceeded 16 MiB'), 'worker');
            $encoded = encode_report($report);
        }
        if (!headers_sent()) {
            header_remove(); // do not return cookies/redirects set by project code
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        return $encoded;
    }, 8192, 0);

    try {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) { throw new \InvalidArgumentException('worker project root is invalid'); }
        $bootstrapPath = $bootstrap === null ? null : agent_project_path($root, $bootstrap, false);
        $arguments = agent_arguments($args, $root, $bootstrapPath);
        if (!chdir($root)) { throw new \RuntimeException('cannot enter worker project directory'); }
        run_cli(array_merge([framework_root() . '/tinytest.php', '-j'], $arguments), function (array $result) use (&$report): void {
            $report = $result;
        });
    } catch (\Throwable $error) {
        $report = empty_report();
        add_runner_error($report, $error, 'http_worker');
    }
}

function http_worker_rejection(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['exit_code' => 2, 'errors' => [['kind' => 'http_worker', 'message' => $message]]]);
}
