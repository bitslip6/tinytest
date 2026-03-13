<?php declare(strict_types=1);

function test_assert_count(): void {
    assert_count([1, 2, 3], 3, "array should have 3 elements");
    assert_count([], 0, "empty array should have 0 elements");
    assert_count(["a" => 1, "b" => 2], 2, "assoc array should have 2 elements");
}

function test_assert_empty(): void {
    assert_empty([], "empty array");
    assert_empty("", "empty string");
    assert_empty(null, "null");
    assert_empty(0, "zero");
    assert_empty(false, "false");
}

function test_assert_not_empty(): void {
    assert_not_empty([1], "non-empty array");
    assert_not_empty("hello", "non-empty string");
    assert_not_empty(1, "non-zero");
    assert_not_empty(true, "true");
}
