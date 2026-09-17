<?php declare(strict_types=1);
// Deliberately failing CLI fixtures. Not loaded by the shallow `-d tests/` scan.

function test_ok(): void {
    assert_eq(1, 1, 'first assertion');
    assert_eq(2, 2, 'second assertion');
}
function test_assertion_failure(): void { assert_eq(1, 2, 'different'); }
function test_error_after_assertion(): void {
    assert_eq(1, 1, 'passes before error');
    throw new Error('boom');
}
function test_type_error(): void { strlen([]); }
function test_unexpected_exception(): void { throw new LogicException('unexpected'); }
function test_warning_after_assertion(): void {
    assert_eq(1, 1, 'passes before warning');
    trigger_error('unexpected warning', E_USER_WARNING);
}
/** @type log_drain */
function test_warning_and_exception(): void {
    trigger_error('warning before exception', E_USER_WARNING);
    throw new Error('exception after warning');
}
/** @type log_drain */
function test_clean_after_warning(): void { assert_eq(1, 1, 'previous warning must not leak'); }
function test_incomplete(): void {}
function test_falsey(): void { assert_true(0, 'zero is falsey'); }
function test_invalid_regex(): void { assert_not_matches('text', '/[/', 'bad regex'); }
function test_strict_membership(): void { assert_array_has([1, 2], '2', 'strict types'); }
function test_legacy_membership(): void { assert_array_contains('2', [1, 2], 'legacy loose types'); }
function test_direct_test_error(): void { throw new TinyTest\TestError('direct error', 1, 2); }

/** @exception RuntimeException */
function test_expected_exception(): void { throw new RuntimeException('expected'); }
/** @exception Exception */
function test_expected_subclass(): void { throw new RuntimeException('subclass'); }
/** @exception \Throwable */
function test_expected_error(): void { throw new TypeError('expected Error'); }
/** @exception RuntimeException */
function test_missing_exception(): void { assert_eq(1, 1, 'normal return is not enough'); }
/** @exception RuntimeException */
function test_wrong_exception(): void { throw new LogicException('wrong class'); }
/**
 * @exception RuntimeException
 * @exception InvalidArgumentException
 */
function test_multiple_exceptions(): void { throw new InvalidArgumentException('second match'); }
/** @exception Throwable */
function test_assertion_not_expected(): void { assert_eq(1, 2, 'cannot accept a framework failure'); }
/** @exception Throwable */
function test_native_assertion_not_expected(): void { assert(false, 'native assertion must fail'); }
/**
 * @exception Throwable
 * @timeout 0.001
 */
function test_timeout_not_expected(): void {
    usleep(20000);
    throw new RuntimeException('expected, but too late');
}
/**
 * @exception Throwable
 * @timeout 1
 */
function test_alarm_not_expected(): void { sleep(3); }
/** @exception Throwable */
function test_result_conversion_not_expected() {
    return new class {
        public function __toString(): string { throw new RuntimeException('conversion failure'); }
    };
}

function test_result_conversion_failure() {
    assert_eq(1, 1, 'test body passes');
    return new class {
        public function __toString(): string { throw new RuntimeException('conversion failure'); }
    };
}

/** @skip not available */
function test_skip(): void { throw new Error('must not run'); }
/** @todo later */
function test_todo(): void { throw new Error('must not run'); }
/** @ambiguous still needs review */
function test_ambiguous_ok(): void { assert_eq(1, 1, 'ambiguous does not change success'); }
/** @ambiguous still needs review */
function test_ambiguous_failure(): void { throw new Error('ambiguous does not excuse failure'); }

function fixture_data(): iterable { yield 'null' => null; yield 'integer' => 1; }
/** @dataprovider fixture_data */
function test_provider_null($value): void { assert_true($value === null || $value === 1, 'null is still an argument'); }
function fixture_invalid() { return false; }
/** @dataprovider fixture_invalid */
function test_invalid_provider($value): void {}
/** @dataprovider fixture_does_not_exist */
function test_missing_provider($value): void {}
function fixture_empty(): array { return []; }
/** @dataprovider fixture_empty */
function test_empty_provider($value): void { assert_true(true, 'never called'); }
function fixture_broken(): array { throw new RuntimeException('provider broke'); }
/**
 * @dataprovider fixture_broken
 * @exception RuntimeException
 */
function test_provider_failure($value): void { assert_true(true, 'never called'); }
/** @dataprovider fixture_data */
function test_provider_incomplete($value): void {
    if ($value !== null) { assert_eq($value, 1, 'only one dataset makes an assertion'); }
}
/** @dataprovider fixture_data */
function test_provider_failure_beats_incomplete($value): void {
    if ($value === null) { assert_eq(1, 2, 'dataset failure must not be hidden by incomplete'); }
}
function fixture_partial(): iterable { yield 'first' => 1; throw new Error('generator broke'); }
/** @dataprovider fixture_partial */
function test_provider_partial($value): void { assert_eq($value, 1, 'first case passes'); }

function test_nested_output_buffer(): void {
    ob_start();
    echo 'captured';
    throw new Error('failure with open buffer');
}
function test_caught_assertion(): void {
    try { assert_eq(1, 2, 'caught failure'); } catch (TinyTest\TestError $ex) {}
    assert_eq(1, 1, 'later success cannot clear a caught assertion failure');
}
