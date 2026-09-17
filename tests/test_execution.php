<?php declare(strict_types=1);
/** @covers ../tinytest.php */

function test_result_error_clears_success(): void {
    $result = new TinyTest\TestResult();
    $result->pass();
    $result->set_error(new Error('late failure'));
    assert_false($result->pass, 'late failure must clear success');
}

function test_execution_keeps_outer_output_buffer(): void {
    $level = ob_get_level();
    $result = TinyTest\do_test(function () {
        echo 'outer';
        ob_start();
        echo 'inner';
        throw new Error('nested buffer failure');
    }, [], null, null);
    assert_eq(ob_get_level(), $level, 'runner restores output buffer level');
    assert_eq($result->console, 'outerinner', 'all test output is captured in order');
    assert_false($result->pass, 'exception is still a failure');
}

function test_execution_restores_signal_configuration(): void {
    if (!function_exists('pcntl_alarm')) {
        assert_true(true, 'pcntl is optional');
        return;
    }
    $original_handler = pcntl_signal_get_handler(SIGALRM);
    $original_async = pcntl_async_signals();
    $original_alarm = pcntl_alarm(0);
    $handler = function () {};
    try {
        pcntl_async_signals(false);
        pcntl_signal(SIGALRM, $handler);
        pcntl_alarm(30);
        $result = TinyTest\do_test(function () { throw new RuntimeException('expected'); }, ['Exception'], null, null, 5);
        assert_true($result->pass, 'expected exception matches a parent class');
        assert_eq(pcntl_signal_get_handler(SIGALRM), $handler, 'previous handler restored');
        assert_false(pcntl_async_signals(), 'previous asynchronous mode restored');
        assert_gt(pcntl_alarm(0), 0, 'previous pending alarm restored');
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, $original_handler);
        pcntl_async_signals($original_async);
        if ($original_alarm > 0) { pcntl_alarm($original_alarm); }
    }
}

function test_execution_does_not_count_errors_as_assertions(): void {
    $before = $GLOBALS['assert_count'];
    $failed_before = $GLOBALS['assert_fail_count'];
    $result = TinyTest\do_test(function () { throw new Error('failure'); }, [], null, null);
    $after = $GLOBALS['assert_count'];
    $failed_after = $GLOBALS['assert_fail_count'];
    assert_eq($after, $before, 'execution errors do not invent assertion counts');
    assert_eq($failed_after, $failed_before, 'failure counters belong to assertions');
    assert_false($result->pass, 'execution still fails without any assertion');
}
