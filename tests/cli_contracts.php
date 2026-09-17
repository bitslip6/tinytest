<?php declare(strict_types=1);
/**
 * Independent CLI regression harness (does not use TinyTest to judge TinyTest).
 * Run: php tests/cli_contracts.php
 */

function check_contract(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

function run_cli_contract(array $arguments, bool $json_output = true): array {
    $command = array_merge([
        PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'assert.exception=1',
        '-d', 'log_errors=1', '-d', 'display_errors=0',
        dirname(__DIR__) . '/tinytest.php', '-f', __DIR__ . '/fixtures/outcomes.php',
    ], $json_output ? ['-j'] : [], $arguments);
    $stdout = tmpfile();
    $stderr = tmpfile();
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, dirname(__DIR__));
    check_contract(is_resource($process), 'unable to start PHP subprocess');
    fclose($pipes[0]);
    $exit = proc_close($process);
    rewind($stdout);
    rewind($stderr);
    $out = stream_get_contents($stdout);
    $err = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    $result = $json_output ? json_decode($out, true, 512, JSON_THROW_ON_ERROR) : $out;
    check_contract($err === '', 'unexpected stderr: ' . $err);
    return [$exit, $result];
}

try {
    // name => [status, assertion count]. Framework errors are not assertions.
    $cases = [
        'test_ok' => ['OK', 2],
        'test_assertion_failure' => ['FAIL', 1],
        'test_error_after_assertion' => ['FAIL', 1],
        'test_type_error' => ['FAIL', 0],
        'test_unexpected_exception' => ['FAIL', 0],
        'test_warning_after_assertion' => ['FAIL', 1],
        'test_warning_and_exception' => ['FAIL', 0],
        'test_clean_after_warning' => ['OK', 1],
        'test_incomplete' => ['IN', 0],
        'test_falsey' => ['FAIL', 1],
        'test_invalid_regex' => ['FAIL', 1],
        'test_strict_membership' => ['FAIL', 1],
        'test_legacy_membership' => ['OK', 1],
        'test_direct_test_error' => ['FAIL', 0],
        'test_expected_exception' => ['OK', 1],
        'test_expected_subclass' => ['OK', 1],
        'test_expected_error' => ['OK', 1],
        'test_missing_exception' => ['FAIL', 1],
        'test_wrong_exception' => ['FAIL', 0],
        'test_multiple_exceptions' => ['OK', 1],
        'test_assertion_not_expected' => ['FAIL', 1],
        'test_native_assertion_not_expected' => ['FAIL', 0],
        'test_timeout_not_expected' => ['FAIL', 1],
        'test_result_conversion_not_expected' => ['FAIL', 0],
        'test_result_conversion_failure' => ['FAIL', 1],
        'test_skip' => ['SKIP', 0],
        'test_todo' => ['TODO', 0],
        'test_ambiguous_ok' => ['OK', 1],
        'test_ambiguous_failure' => ['FAIL', 0],
        'test_provider_null' => ['OK', 2],
        'test_empty_provider' => ['IN', 0],
        'test_invalid_provider' => ['FAIL', 0],
        'test_missing_provider' => ['FAIL', 0],
        'test_provider_failure' => ['FAIL', 0],
        'test_provider_incomplete' => ['IN', 1],
        'test_provider_failure_beats_incomplete' => ['FAIL', 1],
        'test_provider_partial' => ['FAIL', 1],
        'test_nested_output_buffer' => ['FAIL', 0],
        'test_caught_assertion' => ['FAIL', 2],
    ];
    if (function_exists('pcntl_alarm')) {
        $cases['test_alarm_not_expected'] = ['FAIL', 0];
    }
    foreach ($cases as $name => [$status, $assertions]) {
        [$exit, $json] = run_cli_contract(['-t', $name]);
        $failing = in_array($status, ['FAIL', 'IN'], true);
        check_contract($exit === ($failing ? 1 : 0), "$name: incorrect process exit code $exit");
        check_contract(count($json['tests']) === 1, "$name: expected one result");
        $result = $json['tests'][0];
        check_contract($result['name'] === $name && $result['status'] === $status, "$name: incorrect result status");
        check_contract($result['assertions'] === $assertions, "$name: incorrect assertion count");
        check_contract(isset($result['error']) === $failing, "$name: missing or unexpected error details");
        $summary = $json['summary'];
        check_contract($summary['total'] === 1, "$name: summary counts assertions instead of tests");
        check_contract($summary['passed'] === (int) ($status === 'OK'), "$name: passed total");
        check_contract($summary['failed'] === (int) ($status === 'FAIL'), "$name: failed total");
        check_contract($summary['incomplete'] === (int) ($status === 'IN'), "$name: incomplete total");
        check_contract($summary['skipped'] === (int) in_array($status, ['SKIP', 'TODO'], true), "$name: skipped total");
        check_contract($summary['assertions']['total'] === $assertions, "$name: assertion summary");
        check_contract($summary['assertions']['total'] === $summary['assertions']['passed'] + $summary['assertions']['failed'], "$name: assertion totals must reconcile");
    }

    foreach (['test_error_after_assertion', 'test_incomplete', 'test_provider_incomplete', 'test_ambiguous_failure'] as $name) {
        [$exit, $json] = run_cli_contract(['-t', $name, '-x']);
        check_contract($exit === 1 && count($json['tests']) === 1, "$name: errors-only hides a failing outcome");
    }
    [$exit, $json] = run_cli_contract(['-t', 'test_provider_failure_beats_incomplete']);
    check_contract(strpos($json['tests'][0]['error']['message'], 'dataset failure must not be hidden') !== false, 'incomplete dataset replaced the assertion failure diagnostic');
    [$exit, $console] = run_cli_contract(['-t', 'test_incomplete', '-x'], false);
    check_contract($exit === 1 && strpos($console, 'test_incomplete') !== false && strpos($console, 'made no assertions') !== false, 'console errors-only must explain incomplete failures');
    [$exit, $json] = run_cli_contract(['-t', 'test_ok', '-x']);
    check_contract($exit === 0 && $json['tests'] === [] && $json['summary']['passed'] === 1, 'output filters must not filter summary');
    [$exit, $json] = run_cli_contract(['-i', 'log_drain']);
    check_contract($exit === 1 && array_column($json['tests'], 'status') === ['FAIL', 'OK'], 'logs must be drained after an exception');

    [$exit, $json] = run_cli_contract([]);
    check_contract($exit === 1, 'mixed suite must fail');
    $summary = $json['summary'];
    check_contract($summary['total'] === count($json['tests']), 'mixed suite total must count functions');
    foreach (['passed' => ['OK'], 'failed' => ['FAIL'], 'incomplete' => ['IN'], 'skipped' => ['SKIP', 'TODO']] as $key => $statuses) {
        $count = count(array_filter($json['tests'], fn($test) => in_array($test['status'], $statuses, true)));
        check_contract($summary[$key] === $count, "mixed suite $key summary disagrees with results");
    }
    check_contract($summary['assertions']['total'] === array_sum(array_column($json['tests'], 'assertions')), 'mixed suite assertion totals disagree');
    [$filtered_exit, $filtered] = run_cli_contract(['-x']);
    check_contract($filtered_exit === $exit, 'filter changed mixed-suite exit code');
    foreach (['total', 'passed', 'failed', 'incomplete', 'skipped', 'ambiguous', 'assertions'] as $key) {
        check_contract($filtered['summary'][$key] === $summary[$key], "filter changed $key summary");
    }
    check_contract(count($filtered['tests']) === $summary['failed'] + $summary['incomplete'], 'filter must retain every failing outcome');

    [$exit, $json] = run_cli_contract(['-l']);
    check_contract($exit === 0 && $json['summary']['assertions']['total'] === 0, 'list must not execute bodies');
    check_contract(count($json['tests']) === $json['summary']['total'], 'list inventory and total disagree');
    foreach ($json['tests'] as $test) {
        check_contract(isset($test['type']) && !isset($test['status']), 'list entry shape must be consistent, including skip/todo');
    }
    echo 'CLI contracts passed (' . count($cases) . " outcome fixtures plus filtering/list checks).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'CLI contract failure: ' . $error->getMessage() . "\n");
    exit(1);
}
