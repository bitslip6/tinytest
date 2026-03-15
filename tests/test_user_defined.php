<?php declare(strict_types=1);
/**
 * @covers ../user_defined.php
 */

/**
 * Helper: verifies that a callable throws TinyTest\TestError.
 * Saves and restores assertion counters so expected failures don't inflate counts.
 */
function _ud_expect_test_error(callable $fn, string $label): void {
    $threw = false;
    $saved_fail = $GLOBALS['assert_fail_count'];
    $saved_total = $GLOBALS['assert_count'];
    try {
        $fn();
    } catch (TinyTest\TestError $e) {
        $threw = true;
    }
    $GLOBALS['assert_fail_count'] = $saved_fail;
    $GLOBALS['assert_count'] = $saved_total;
    assert_true($threw, "$label should throw TestError");
}

// === assert_array_contains ===

function test_assert_array_contains_finds_value(): void {
    assert_array_contains(1, [1, 2, 3], "should find 1");
    assert_array_contains("hello", ["hello", "world"], "should find hello");
    assert_array_contains(null, [null, 1, 2], "should find null");
}

function test_assert_array_contains_finds_at_boundaries(): void {
    assert_array_contains("first", ["first", "middle", "last"], "first element");
    assert_array_contains("last", ["first", "middle", "last"], "last element");
}

function test_assert_array_contains_with_different_types(): void {
    // in_array without strict mode uses loose comparison
    assert_array_contains("2", [1, 2, 3], "loose comparison: '2' matches 2");
}

function test_assert_array_contains_fails_when_missing(): void {
    _ud_expect_test_error(
        fn() => assert_array_contains(99, [1, 2, 3], "99 not in array"),
        "assert_array_contains(99, [1,2,3])"
    );
}

function test_assert_array_contains_fails_on_empty_array(): void {
    _ud_expect_test_error(
        fn() => assert_array_contains("anything", [], "empty array"),
        "assert_array_contains on empty array"
    );
}
