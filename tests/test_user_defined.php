<?php declare(strict_types=1);
/**
 * Legacy collection API compatibility tests; implementation moved to assertions.php.
 * @covers ../assertions.php
 */

function _ud_expect_test_error(callable $fn, string $label): void {
    assert_fails($fn, "$label should throw TestError");
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
