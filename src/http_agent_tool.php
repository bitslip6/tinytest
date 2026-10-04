<?php declare(strict_types=1);
namespace TinyTest;

require_once __DIR__ . '/agent_contract.php';

/** Host supplies global AgentTool. Does not load TinyTest runtime or project code. */
final class HttpTestRunnerTool implements \AgentTool
{
    private $url;
    private $token;
    private $timeout;

    public function __construct(string $workerUrl, string $token, float $timeoutSeconds = 40.0, bool $allowHttp = false)
    {
        $url = parse_url($workerUrl);
        if (!is_array($url) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || !in_array($url['scheme'] ?? '', $allowHttp ? ['https', 'http'] : ['https'], true)) {
            throw new \InvalidArgumentException('worker URL must be HTTPS without credentials/fragment; plaintext HTTP requires explicit server opt-in');
        }
        if (strlen($token) < 32 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('worker token must be at least 32 characters with no whitespace/control characters');
        }
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 86400) {
            throw new \InvalidArgumentException('HTTP timeout must be positive and at most 86400 seconds');
        }
        $this->url = $workerUrl;
        $this->token = $token;
        $this->timeout = $timeoutSeconds;
    }

    public function name(): string { return 'tinytest_run'; }

    public function definition(): array
    {
        return agent_tool_definition('Run trusted PHP TinyTest tests on the configured HTTP worker. Paths are relative to the WORKER project root. Exit 0 succeeds, 1 means failed/incomplete, 2 means runner/tool error. Listing still executes file-level PHP. HTTP wait limit: ' . $this->timeout . ' seconds; a client timeout does NOT confirm remote termination. Never automatically retry an unknown outcome.');
    }

    public function execute(array $args): array
    {
        $curl = null;
        try {
            if (!function_exists('curl_init')) { throw new \RuntimeException('HTTP TinyTest tool requires the PHP cURL extension'); }
            $payload = json_encode($args, JSON_THROW_ON_ERROR);
            if (strlen($payload) > 65536) { throw new \InvalidArgumentException('tool arguments exceed 64 KiB'); }
            $body = '';
            $oversized = false;
            $curl = curl_init($this->url);
            if ($curl === false) { throw new \RuntimeException('cannot initialize HTTP worker connection'); }
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $this->token],
                CURLOPT_FOLLOWLOCATION => false, // never forward credentials to a redirect
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT_MS => max(1, (int) ceil($this->timeout * 1000)),
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil(min(10, $this->timeout) * 1000)),
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$oversized): int {
                    if (strlen($body) + strlen($chunk) > 20 * 1024 * 1024) { $oversized = true; return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($curl);
            $errno = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($ok === false) {
                $timeout = $errno === CURLE_OPERATION_TIMEDOUT;
                return ['exit_code' => 2, 'timed_out' => $timeout, 'termination_confirmed' => false,
                    'execution_state' => 'unknown', 'errors' => [['kind' => $timeout ? 'http_timeout' : 'http_transport',
                        'message' => $oversized ? 'HTTP worker response exceeded 20 MiB. Remote execution state is unknown.'
                            : ($timeout ? 'HTTP worker did not respond within ' . $this->timeout . ' seconds. This stopped waiting, NOT the remote tests. Check the worker/request logs and server hard-timeout policy before retrying.'
                                : 'HTTP worker transport failed (cURL code ' . $errno . '). Remote execution state is unknown; do not automatically retry.'),
                    ]]];
            }
            if ($status !== 200) {
                return ['exit_code' => 2, 'execution_state' => 'unknown', 'errors' => [['kind' => 'http',
                    'http_status' => $status,
                    'message' => 'HTTP test worker returned status ' . $status . '. Check authentication, endpoint configuration and worker logs. A gateway timeout does not confirm test termination.',
                ]]];
            }
            $report = json_decode($body, true);
            if (!is_array($report) || !isset($report['exit_code'], $report['tests'], $report['summary'], $report['errors'])
                || !in_array($report['exit_code'], [0, 1, 2], true)
                || !is_array($report['tests']) || !is_array($report['summary']) || !is_array($report['errors'])) {
                throw new \RuntimeException('HTTP worker returned an invalid/incomplete report; remote outcome is unknown. Check worker logs before retrying.');
            }
            return $report;
        } catch (\Throwable $error) {
            return ['exit_code' => 2, 'errors' => [['kind' => 'http_tool',
                'message' => json_decode(json_encode($error->getMessage(), JSON_INVALID_UTF8_SUBSTITUTE), true),
            ]]];
        } finally {
            if (is_resource($curl)) { curl_close($curl); } // PHP 7.4 resource; PHP 8+ object closes on release
            $curl = null;
        }
    }
}
