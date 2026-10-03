<?php declare(strict_types=1);
namespace TinyTest;

function run_suite(array $options): array
{
    if ($options[COVERAGE]) {
        require_once __DIR__ . '/coverage/source_map.php';
        require_once __DIR__ . '/coverage/lcov.php';
    }
    if ($options['p'] || $options['k']) { require_once __DIR__ . '/profiling.php'; }
$GLOBALS['_tinytest_covers'] = [];
$loading_scope = new AssertionScope();
$GLOBALS['_tinytest_assertion_scope'] = $loading_scope;
$loading_errors = new PhpErrors();
$loading_handler = [$loading_errors, 'handle'];
$previous_handler = set_error_handler($loading_handler);
try {
    include_once framework_root() . '/user_defined.php';
    $project_overrides = getcwd() . '/user_defined.php';
    if (is_file($project_overrides)) { include_once $project_overrides; }
    if (isset($options['b'])) { require $options['b']; }
    $before = get_defined_functions()['user'];
    if (isset($options['d'])) { load_dir($options['d'], $options); }
    else { foreach ($options['f'] as $file) { load_file($file, $options); } }
    if ($loading_scope->failures > 0) { throw $loading_scope->error; }
    $loading_error = $loading_errors->finish();
    if ($loading_error !== null) { throw $loading_error; }
} finally {
    $GLOBALS['_tinytest_assertion_scope'] = null;
    restore_handler($loading_handler, $previous_handler);
}
// Preserve the before/after user-function difference; system functions never enter discovery.
$just_test_functions = array_diff(get_defined_functions()['user'], $before);
$is_test_fn = function_exists('user_is_test_function') ? 'user_is_test_function' : 'TinyTest\\is_test_function';
$selected = [];
foreach ($just_test_functions as $function) {
    if (isset($options['t']) && $function !== $options['t']) { continue; }
    if ($is_test_fn($function, $options) && !is_excluded_test(read_test_annotations($function), $options)) {
        $selected[] = $function;
    }
}
if ($selected === [] && (isset($options['t']) || !isset($options['allow-empty']))) {
    throw new \InvalidArgumentException(isset($options['t']) ? 'no test matched selector: ' . $options['t'] : 'no tests matched the selected files and filters (use --allow-empty to opt out)');
}
$GLOBALS[ASSERT_CNT] = $GLOBALS['assert_pass_count'] = $GLOBALS['assert_fail_count'] = 0;
$coverage = array();
$json_results = array();
// Test outcomes are independent of mutable assertion counters and output filters.
$summary = ['total' => 0, 'passed' => 0, 'failed' => 0, 'incomplete' => 0, 'skipped' => 0, 'ambiguous' => 0];
do_for_all($selected, function ($function_name) use (&$coverage, &$json_results, &$summary, $options) {
    $test_data = read_test_annotations($function_name);
    $summary['total']++;

    // display the test we are running. In errors-only mode (-x), buffer the header and
    // flush it only if the test fails, so passing tests produce no output at all.
    $test_header = "";
    if (!$options['j']) {
        $format_test_fn = (function_exists("\\user_format_test_run")) ? "\\user_format_test_run" : "\\TinyTest\\format_test_run";
        $test_header = $format_test_fn($function_name, $test_data, $options);
        if (!errors_only($options)) {
            echo $test_header;
        }
    }

    // Listing is discovery only, even for skipped/todo tests.
    if ($options['l']) {
        if ($options['j']) {
            $json_results[] = [
                'name' => $function_name,
                'file' => $test_data['file'],
                'type' => $test_data['type'],
            ];
        } else if (errors_only($options)) {
            echo $test_header;
        }
        return;
    }

    // handle @skip and @todo annotations
    if (isset($test_data['skip']) || isset($test_data['todo'])) {
        $reason = isset($test_data['todo']) ? $test_data['todo'] : $test_data['skip'];
        $test_data['status'] = isset($test_data['todo']) ? 'TODO' : 'SKIP';
        $summary['skipped']++;
        if (!$options['j'] && !errors_only($options)) {
            $label = $test_data['status'];
            $out = CYAN . sprintf("%-4s", $label) . NORML;
            if ($reason !== '') {
                $out .= GREY . " ($reason)" . NORML;
            }
            echo $out;
        }
        if ($options['j'] && !errors_only($options)) {
            $json_entry = [
                'name' => $function_name,
                'file' => $test_data['file'],
                'status' => $test_data['status'],
                'duration' => 0,
                'assertions' => 0,
            ];
            if ($reason !== '') {
                $json_entry['reason'] = $reason;
            }
            $json_results[] = $json_entry;
        }
        return;
    }

    $error = $result = $t0 = $t1 = null;

    // turn on output buffer and start the operation log for code coverage reporting
    if ($options[COVERAGE]) {
        panic_if(!function_exists('phpdbg_start_oplog'), RED . "\ncode coverage only available in phpdbg -rre tinytest.php\n" . NORML);
        \phpdbg_start_oplog();
    }

    // run the test
    if ($options['p'] || $options['k']) {
        \tideways_enable(TIDEWAYS_FLAGS_MEMORY | TIDEWAYS_FLAGS_CPU);
    }
    $t0 = microtime(true);
    $results = run_test($function_name, $test_data);
    $t1 = microtime(true);
    if ($options['p'] || $options['k']) {
        output_profile(\tideways_disable(), $function_name, $options);
    }

    // combine the oplogs...
    if ($options[COVERAGE]) {
        $coverage = combine_oplog($coverage, \phpdbg_end_oplog(), $options);
    }


    // did the test pass?
    $passed = all_match($results, function (TestResult $result) {
        return $result->pass;
    });

    $test_data['result'] = array_reduce($results, function (string $out, TestResult $result) {
        return $out . $result->result;
    }, "");
    $console = array_reduce($results, function (string $out, TestResult $result) {
        return $out . $result->console;
    }, "");
    if (verbose($options) && $console !== "") {
        $test_data["result"] .= "\nconsole output:\n$console";
    }


    // PHP diagnostics are captured per case, not inferred from error-log text.
    $test_data['error'] = null;
    $has_failure = false;
    foreach ($results as $result) {
        if (!$result->pass) {
            if (!$result->incomplete || $test_data['error'] === null) {
                $test_data['error'] = $result->error;
            }
            $has_failure = $has_failure || !$result->incomplete;
        }
    }
    $test_data['status'] = $passed ? 'OK' : ($has_failure ? 'FAIL' : 'IN');
    $summary[$passed ? 'passed' : ($has_failure ? 'failed' : 'incomplete')]++;

    $duration = $t1 - $t0;
    $assertion_count = array_sum(array_map(fn(TestResult $result) => $result->assertions, $results));

    if ($passed) {
        if (!$options['j'] && !errors_only($options)) {
            $success_display_fn = (function_exists("\\user_format_test_success")) ? "\\user_format_test_success" : "\\TinyTest\\format_test_success";
            echo $success_display_fn($test_data, $options, $duration);
        }
    } else {
        if (!$options['j']) {
            // flush the buffered header first so the failure is attributable
            if (errors_only($options)) {
                echo $test_header;
            }
            $error_display_fn = (function_exists("\\user_format_assertion_error")) ? "\\user_format_assertion_error" : "\\TinyTest\\format_assertion_error";
            echo $error_display_fn($test_data, $options, $duration);
        }
    }

    // track @ambiguous tests (test still runs, just flagged)
    if (isset($test_data['ambiguous'])) {
        $summary['ambiguous']++;
        if (!$options['j'] && !errors_only($options)) {
            $reason = $test_data['ambiguous'];
            echo YELLOW . " AMBG" . NORML . ($reason !== '' ? GREY . " ($reason)" . NORML : '');
        }
    }

    // collect JSON result (in errors-only mode, only include failing tests)
    if ($options['j'] && (!errors_only($options) || !$passed)) {
        $json_entry = [
            'name' => $function_name,
            'file' => $test_data['file'],
            'status' => $test_data['status'],
            'duration' => round($duration, 6),
            'assertions' => $assertion_count,
            'output' => $console,
        ];
        if (isset($test_data['ambiguous'])) {
            $json_entry['ambiguous'] = true;
            if ($test_data['ambiguous'] !== '') {
                $json_entry['ambiguous_reason'] = $test_data['ambiguous'];
            }
        }
        if (!$passed && $test_data['error'] !== null) {
            $ex = $test_data['error'];
            $json_entry['error'] = [
                'class' => get_class($ex),
                'message' => strip_ansi($ex->getMessage()),
                'file' => $ex->getFile(),
                'line' => $ex->getLine(),
            ];
            if ($ex instanceof \ErrorException) { $json_entry['error']['severity'] = $ex->getSeverity(); }
        }
        $json_results[] = $json_entry;
    }

    gc_collect_cycles();
});

$uncovered_functions = [];
$coverage_data = [];
if (count($coverage) > 0) {
    if (!$options['j']) {
        echo "\ngenerating lcov.info...\n";
    }
    $covers_list = array_unique($GLOBALS['_tinytest_covers']);
    $coverage_options = $options;
    if ($options['j']) { $coverage_options[SHOW_COVERAGE] = false; }
    $cov_result = coverage_to_lcov($coverage, $coverage_options, $covers_list);
    file_put_contents("lcov.info", $cov_result['lcov']);
    $uncovered_functions = $cov_result['uncovered'];
    $coverage_data = $cov_result['coverage'];

    // if @covers annotations were found, filter coverage to only listed files
    if (!empty($covers_list)) {
        $filter_fn = fn($file) => in_array($file, $covers_list);
        $coverage_data = array_filter($coverage_data, $filter_fn, ARRAY_FILTER_USE_KEY);
        $uncovered_functions = array_filter($uncovered_functions, $filter_fn, ARRAY_FILTER_USE_KEY);
    }
}

@unlink(ERR_OUT);
$m1 = microtime(true);

// Build a report for both modes; only the CLI boundary serializes JSON.
    $output = [
        'version' => (int) VER,
        'tests' => $json_results,
        'summary' => $summary + [
            'assertions' => [
                'total' => (int) $GLOBALS[ASSERT_CNT],
                'passed' => (int) $GLOBALS['assert_pass_count'],
                'failed' => (int) $GLOBALS['assert_fail_count'],
            ],
            'duration' => round($m1 - $GLOBALS['m0'], 6),
            'memory_kb' => (int) (memory_get_peak_usage(true) / 1024),
        ],
    ];
    if ($options[COVERAGE] && !empty($coverage_data)) {
        $output['coverage'] = $coverage_data;
    }
if (!$options['j']) {
    // display the test results
    $skip_count = $summary['skipped'];
    $skip_str = $skip_count > 0 ? ", $skip_count skipped" : "";
    $ambiguous_count = $summary['ambiguous'];
    $ambig_str = $ambiguous_count > 0 ? ", $ambiguous_count ambiguous" : "";
    $cov_total = 0;
    $uncov_total = 0;
    foreach ($coverage_data as $file_data) {
        $cov_total += count($file_data['covered_functions']);
        $uncov_total += count($file_data['uncovered_functions']);
    }
    $fn_total = $cov_total + $uncov_total;
    $cov_str = $fn_total > 0 ? ", $cov_total/$fn_total functions covered" : "";
    $uncov_str = $uncov_total > 0 ? ", $uncov_total uncovered" : "";
    echo "\n" . NORML . $summary['total'] . " tests, " . $summary['passed'] . " passed, " . $summary['failed'] . " failures/exceptions, " . $summary['incomplete'] . " incomplete" . $skip_str . $ambig_str . $cov_str . $uncov_str . ", using " . number_format(memory_get_peak_usage(true) / 1024) . "KB in " . number_format($m1 - $GLOBALS['m0'], 5) . " seconds";
}

$output['errors'] = [];
$output['exit_code'] = $summary['failed'] > 0 || $summary['incomplete'] > 0 ? 1 : 0;
return $output;
}
