<?php declare(strict_types=1);
namespace TinyTest;

use Throwable;

function do_test(callable $test_function, array $exceptions, ?string $dataset_name, $value, float $timeout = 0, array $expected_php_errors = []): TestResult
{
    $result = new TestResult();
    $result->dataset = $dataset_name;
    $assertions_before = $GLOBALS[ASSERT_CNT];
    $previous_scope = $GLOBALS['_tinytest_assertion_scope'] ?? null;
    $scope = new AssertionScope();
    $GLOBALS['_tinytest_assertion_scope'] = $scope;
    $php_errors = new PhpErrors();
    $php_handler = [$php_errors, 'handle'];
    $previous_handler = set_error_handler($php_handler);
    $previous_reporting = error_reporting();
    $buffer_level = ob_get_level();
    $has_pcntl = $timeout >= 1 && function_exists('pcntl_alarm');
    $t_start = microtime(true);
    if ($has_pcntl) {
        $previous_async = pcntl_async_signals(true);
        $previous_signal_handler = pcntl_signal_get_handler(SIGALRM);
        $previous_alarm = pcntl_alarm(0);
        pcntl_signal(SIGALRM, function () use ($timeout) {
            throw new TimeoutError("test timed out after {$timeout}s");
        });
        pcntl_alarm((int) ceil($timeout));
    }
    ob_start();
    try {
        // A null dataset is still one argument, unlike a test without a provider.
        $output = $dataset_name !== null ? $test_function($value) : $test_function();
        if ($exceptions !== []) {
            $result->set_error(new TestError('expected exception was not thrown', 'normal return', join(', ', $exceptions)));
        } else {
            $result->pass();
        }
    } catch (Throwable $ex) {
        $expected = false;
        if (!$ex instanceof TestError && !$ex instanceof TimeoutError && !$ex instanceof \AssertionError) {
            foreach ($exceptions as $exception) {
                if (is_a($ex, $exception)) {
                    $expected = true;
                    break;
                }
            }
        }
        if ($expected) {
            // The annotation is an implicit successful assertion.
            count_assertion_pass();
            $result->pass();
        } else {
            $err = $ex instanceof TestError ? $ex : new TestError(
                'unexpected: (' . $ex->getMessage() . ')', get_class($ex),
                $exceptions !== [] ? join(', ', $exceptions) : 'no exception', $ex
            );
            $err->test_data = $dataset_name;
            $result->set_error($err);
        }
    } finally {
        try {
        // Result conversion is a runner operation, not an expected exception.
        if ($result->pass && isset($output)) {
            try {
                $result->set_result(strval($output));
            } catch (Throwable $ex) {
                $result->set_error($ex);
            }
        }
        $console = '';
        while (ob_get_level() > $buffer_level) {
            $level = ob_get_level();
            $console = @ob_get_clean() . $console;
            if (ob_get_level() === $level) {
                $result->set_error(new \RuntimeException('test left a non-removable output buffer'));
                break;
            }
        }
        $result->set_console($console);
        $php_error = $php_errors->finish($expected_php_errors);
        if ($php_error !== null && $result->pass) { $result->set_error($php_error); }
        if ($scope->failures > 0) { $result->set_error($scope->error); }
        $result->assertions = $GLOBALS[ASSERT_CNT] - $assertions_before;
        } finally {
        $GLOBALS['_tinytest_assertion_scope'] = $previous_scope;
        restore_handler($php_handler, $previous_handler);
        error_reporting($previous_reporting);
        if ($has_pcntl) {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous_signal_handler);
            pcntl_async_signals($previous_async);
            if ($previous_alarm > 0) {
                pcntl_alarm(max(1, $previous_alarm - (int) (microtime(true) - $t_start)));
            }
        }
        }
    }
    // Fractional deadlines are post-run checks, not interrupting time limits.
    $elapsed = microtime(true) - $t_start;
    if ($timeout > 0 && $elapsed > $timeout && $result->pass) {
        $result->set_error(new TestError("test timed out after {$timeout}s (took " . number_format($elapsed, 3) . "s)", number_format($elapsed, 3) . 's', "{$timeout}s"));
    }
    return $result;
}

function run_test(callable $test_function, array $test_data): array
{
    $timeout = isset($test_data['timeout']) ? (float) $test_data['timeout'] : 0;
    $results = [];
    $assertions_before = $GLOBALS[ASSERT_CNT];
    $previous_scope = $GLOBALS['_tinytest_assertion_scope'] ?? null;
    $provider_scope = new AssertionScope();
    $GLOBALS['_tinytest_assertion_scope'] = $provider_scope;
    $provider_errors = new PhpErrors();
    $provider_handler = [$provider_errors, 'handle'];
    $previous_handler = set_error_handler($provider_handler);
    $previous_reporting = error_reporting();
    try {
        if (isset($test_data['dataprovider'])) {
            $datasets = call_user_func($test_data['dataprovider']);
            if (!is_iterable($datasets)) {
                throw new \UnexpectedValueException('data provider must return an iterable');
            }
            foreach ($datasets as $dataset_name => $value) {
                $results[] = do_test($test_function, $test_data['exception'], strval($dataset_name), $value, $timeout, $test_data['phperror'] ?? []);
            }
        } else {
            $results[] = do_test($test_function, $test_data['exception'], null, null, $timeout, $test_data['phperror'] ?? []);
        }
    } catch (Throwable $ex) {
        $result = new TestResult();
        $result->set_error($ex);
        $results[] = $result;
    } finally {
        $GLOBALS['_tinytest_assertion_scope'] = $previous_scope;
        restore_handler($provider_handler, $previous_handler);
        error_reporting($previous_reporting);
    }
    $provider_error = $provider_scope->error ?? $provider_errors->finish();
    if ($provider_error !== null) {
        $result = new TestResult();
        $result->set_error($provider_error);
        $results[] = $result;
    }
    if ($results === []) {
        $result = new TestResult();
        $result->incomplete = true;
        $result->set_error(new TestError('data provider produced no cases', 0, 'at least one case'));
        $results[] = $result;
    }
    foreach ($results as $result) {
        if ($result->pass && $result->assertions === 0) {
            $result->incomplete = true;
            $result->set_error(new TestError('test case made no assertions', 0, 'at least one assertion'));
        }
    }
    // Provider checks are reported, but cannot make an assertion-free case complete.
    $case_assertions = array_sum(array_map(fn($result) => $result->assertions, $results));
    $results[0]->assertions += $GLOBALS[ASSERT_CNT] - $assertions_before - $case_assertions;
    return $results;
}


// Legacy helper retained for callers/tests; execution uses scoped PhpErrors.
// TODO: simplify, maybe add an error handler and skip the error file...
function get_error_log(array $errorconfig, array $options): ?\Error
{
    $verbose_out = "";
    if (file_exists((ERR_OUT))) {
        $lines = file(ERR_OUT);
        @unlink(ERR_OUT);

        foreach ($lines as $line) {
            if (count($errorconfig) > 0) {
                foreach ($errorconfig as $config) {
                    $type_name = explode(":", $config);
                    if (stripos($line, $type_name[0]) !== false && stripos($line, $type_name[1]) !== false) {
                        $verbose_out .= $line;
                        continue;
                    }
                    return new \Error($line);
                }
            } else {
                return new \Error($line);
            }
        }
    }

    if (verbose($options)) {
        echo $verbose_out;
    }
    return null;
}

// ugly but compatible with all versions of php
function call_to_source(string $fn, array $x, array $options): array
{
    $file = '<internal>';
    $line = -1;
    try {
        $o = null;
        if (strpos($fn, '::') !== false) {
            list($c, $f) = explode('::', $fn, 2);
            $o = new \ReflectionMethod($c, $f);
            $file = $o->getFileName();
            $line = $o->getStartLine();
        } else {
            $o = new \ReflectionFunction($fn);
            $file = $o->getFileName();
            if (!$file) {
                $file = "<internal>";
            }
            $line = $o->getStartLine();
            if (!$line) {
                $line = 0;
            }
        }
    } catch (\ReflectionException $e) {
        $file = '<internal>';
        $line = 0;
    }

    //$call['cost'] = $x[$options['cost']];
    //$call['count'] = $x['ct'];
    //echo "file $fn = [$file:$line]\n";
    return array('line' => $line, 'fn' => $fn, 'file' => $file, 'calls' => array(), 'count' => $x['ct'], 'cost' => $x[$options['cost']]);
}

