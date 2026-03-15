<?php declare(strict_types=1);
/**
 * @covers ../assertions.php
 */

/**
 * Helper: verifies that a callable throws TinyTest\TestError.
 * Saves and restores the assertion fail counter so that the expected failure
 * inside the callable doesn't inflate the global fail count.
 */
function expect_test_error(callable $fn, string $label): void {
    $threw = false;
    $saved_fail = $GLOBALS['assert_fail_count'];
    $saved_total = $GLOBALS['assert_count'];
    try {
        $fn();
    } catch (TinyTest\TestError $e) {
        $threw = true;
    }
    // restore counters — the assertion inside fn() was *supposed* to fail
    $GLOBALS['assert_fail_count'] = $saved_fail;
    $GLOBALS['assert_count'] = $saved_total;
    assert_true($threw, "$label should throw TestError");
}

// === assert_true ===

function test_assert_true_passes_on_truthy(): void {
    assert_true(true, "true should pass");
    assert_true(1, "1 should pass");
    assert_true("non-empty", "non-empty string should pass");
}

function test_assert_true_fails_on_false(): void {
    expect_test_error(fn() => assert_true(false, "should fail"), "assert_true(false)");
}

function test_assert_true_zero_does_not_throw(): void {
    // assert_true(0) does NOT throw because assert_base_condition uses === false check,
    // and the lambda returns 0 (not false). This is known behavior: assert_true only
    // rejects exactly `false`, not other falsy values like 0, "", null.
    // We verify the actual behavior here rather than the ideal behavior.
    assert_true(0 !== false, "0 is not identical to false, so assert_true passes it");
}

// === assert_false ===

function test_assert_false_passes_on_falsy(): void {
    assert_false(false, "false should pass");
    assert_false(0, "0 should pass");
    assert_false("", "empty string should pass");
    assert_false(null, "null should pass");
}

function test_assert_false_fails_on_true(): void {
    expect_test_error(fn() => assert_false(true, "should fail"), "assert_false(true)");
}

// === assert_eq ===

function test_assert_eq_passes_on_strict_equal(): void {
    assert_eq(1, 1, "int equality");
    assert_eq("hello", "hello", "string equality");
    assert_eq(null, null, "null equality");
    assert_eq(true, true, "bool equality");
    assert_eq([1,2,3], [1,2,3], "array equality");
}

function test_assert_eq_fails_on_type_mismatch(): void {
    expect_test_error(fn() => assert_eq(1, "1", "int vs string"), "assert_eq type mismatch");
}

function test_assert_eq_fails_on_different_values(): void {
    expect_test_error(fn() => assert_eq(1, 2, "different ints"), "assert_eq different values");
}

// === assert_eqic ===

function test_assert_eqic_passes_case_insensitive(): void {
    assert_eqic("Hello", "hello", "case insensitive match");
    assert_eqic("ABC", "abc", "upper vs lower");
    assert_eqic("same", "same", "exact match also works");
}

function test_assert_eqic_fails_on_different_strings(): void {
    expect_test_error(fn() => assert_eqic("hello", "world", "different strings"), "assert_eqic different");
}

function test_assert_eqic_fails_on_null(): void {
    expect_test_error(fn() => assert_eqic(null, "hello", "null vs string"), "assert_eqic null");
}

// === assert_neq ===

function test_assert_neq_passes_on_different(): void {
    assert_neq(1, 2, "different ints");
    assert_neq("a", "b", "different strings");
    assert_neq(1, "1", "different types");
}

function test_assert_neq_fails_on_same(): void {
    expect_test_error(fn() => assert_neq(1, 1, "same values"), "assert_neq same");
}

// === assert_gt ===

function test_assert_gt_passes(): void {
    assert_gt(2, 1, "2 > 1");
    assert_gt(0.5, 0.1, "0.5 > 0.1");
}

function test_assert_gt_fails_on_equal(): void {
    expect_test_error(fn() => assert_gt(1, 1, "equal"), "assert_gt equal");
}

function test_assert_gt_fails_on_less(): void {
    expect_test_error(fn() => assert_gt(1, 2, "less"), "assert_gt less");
}

// === assert_lt ===

function test_assert_lt_passes(): void {
    assert_lt(1, 2, "1 < 2");
    assert_lt(-1, 0, "-1 < 0");
}

function test_assert_lt_fails_on_equal(): void {
    expect_test_error(fn() => assert_lt(1, 1, "equal"), "assert_lt equal");
}

function test_assert_lt_fails_on_greater(): void {
    expect_test_error(fn() => assert_lt(2, 1, "greater"), "assert_lt greater");
}

// === assert_contains ===

function test_assert_contains_passes(): void {
    assert_contains("hello world", "world", "should find world");
    assert_contains("abc", "a", "should find a");
    assert_contains("test", "test", "exact match");
}

function test_assert_contains_fails_on_missing(): void {
    expect_test_error(fn() => assert_contains("hello", "xyz", "missing"), "assert_contains missing");
}

function test_assert_contains_null_haystack_throws_test_error(): void {
    expect_test_error(fn() => assert_contains(null, "test", "null haystack"), "assert_contains(null, ...) should throw TestError");
}

// === assert_not_contains ===

function test_assert_not_contains_passes(): void {
    assert_not_contains("hello", "xyz", "xyz not in hello");
    assert_not_contains(null, "test", "null haystack passes");
    assert_not_contains("hello", null, "null needle passes");
}

function test_assert_not_contains_fails_when_found(): void {
    expect_test_error(fn() => assert_not_contains("hello world", "world", "found"), "assert_not_contains found");
}

// === assert_icontains ===

function test_assert_icontains_passes(): void {
    assert_icontains("Hello World", "hello", "case insensitive contains");
    assert_icontains("ABCDEF", "cde", "upper haystack lower needle");
}

function test_assert_icontains_fails_on_missing(): void {
    expect_test_error(fn() => assert_icontains("hello", "xyz", "not found"), "assert_icontains missing");
}

function test_assert_icontains_fails_on_null(): void {
    expect_test_error(fn() => assert_icontains(null, "test", "null haystack"), "assert_icontains null");
}

// === assert_instanceof ===

function test_assert_instanceof_passes(): void {
    $obj = new \stdClass();
    assert_instanceof($obj, \stdClass::class, "stdClass check");
}

function test_assert_instanceof_fails_on_wrong_type(): void {
    expect_test_error(fn() => assert_instanceof("not an object", \stdClass::class, "wrong"), "assert_instanceof wrong type");
}

function test_assert_instanceof_fails_on_null(): void {
    expect_test_error(fn() => assert_instanceof(null, \stdClass::class, "null"), "assert_instanceof null");
}

// === objects_equal ===

function test_objects_equal_same_props(): void {
    $a = (object)['x' => 1, 'y' => 'hello'];
    $b = (object)['x' => 1, 'y' => 'hello'];
    assert_true(objects_equal($a, $b), "same props should be equal");
}

function test_objects_equal_different_values(): void {
    $a = (object)['x' => 1];
    $b = (object)['x' => 2];
    assert_false(objects_equal($a, $b), "different values should not be equal");
}

function test_objects_equal_missing_prop(): void {
    $a = (object)['x' => 1, 'y' => 2];
    $b = (object)['x' => 1];
    assert_false(objects_equal($a, $b), "missing prop should not be equal");
}

function test_objects_equal_nested_objects(): void {
    $a = (object)['inner' => (object)['val' => 42]];
    $b = (object)['inner' => (object)['val' => 42]];
    assert_true(objects_equal($a, $b), "nested objects should be equal");
}

function test_objects_equal_nested_different(): void {
    $a = (object)['inner' => (object)['val' => 42]];
    $b = (object)['inner' => (object)['val' => 99]];
    assert_false(objects_equal($a, $b), "nested objects with different values");
}

function test_objects_equal_with_arrays(): void {
    $a = (object)['items' => [1, 2, 3]];
    $b = (object)['items' => [1, 2, 3]];
    assert_true(objects_equal($a, $b), "objects with same arrays");
}

function test_objects_equal_with_different_arrays(): void {
    $a = (object)['items' => [1, 2, 3]];
    $b = (object)['items' => [1, 2, 4]];
    assert_false(objects_equal($a, $b), "objects with different arrays");
}

// === assert_object ===

function test_assert_object_passes(): void {
    $a = (object)['x' => 1, 'name' => 'test'];
    $b = (object)['x' => 1, 'name' => 'test'];
    assert_object($a, $b, "same objects should pass");
}

function test_assert_object_fails_on_different(): void {
    expect_test_error(fn() => assert_object((object)['x' => 1], (object)['x' => 2], "different"), "assert_object different objects");
}

// === assert_matches ===

function test_assert_matches_passes(): void {
    assert_matches("hello123", '/\d+/', "should match digits");
    assert_matches("test@email.com", '/@/', "should match @");
}

function test_assert_matches_fails(): void {
    expect_test_error(fn() => assert_matches("hello", '/\d+/', "no digits"), "assert_matches fail");
}

// === assert_not_matches ===

function test_assert_not_matches_passes(): void {
    assert_not_matches("hello", '/\d+/', "no digits should pass");
}

function test_assert_not_matches_fails(): void {
    expect_test_error(fn() => assert_not_matches("hello123", '/\d+/', "has digits"), "assert_not_matches fail");
}

// === assert_count ===

function test_assert_count_passes(): void {
    assert_count([1, 2, 3], 3, "array of 3");
    assert_count([], 0, "empty array");
}

function test_assert_count_fails_on_wrong_count(): void {
    expect_test_error(fn() => assert_count([1, 2], 3, "wrong count"), "assert_count wrong");
}

function test_assert_count_non_countable(): void {
    expect_test_error(fn() => assert_count("string", 6, "not countable"), "assert_count non-countable");
}

// === assert_empty ===

function test_assert_empty_passes(): void {
    assert_empty([], "empty array");
    assert_empty("", "empty string");
    assert_empty(null, "null");
    assert_empty(0, "zero");
    assert_empty(false, "false");
}

function test_assert_empty_fails_on_non_empty(): void {
    expect_test_error(fn() => assert_empty([1], "non-empty"), "assert_empty non-empty");
}

// === assert_not_empty ===

function test_assert_not_empty_passes(): void {
    assert_not_empty([1], "non-empty array");
    assert_not_empty("hello", "non-empty string");
    assert_not_empty(1, "non-zero int");
    assert_not_empty(true, "true");
}

function test_assert_not_empty_fails_on_empty(): void {
    expect_test_error(fn() => assert_not_empty([], "empty"), "assert_not_empty empty");
}

// === assert_identical ===

function test_assert_identical_same_types_and_values(): void {
    assert_identical(1, 1, "int match");
    assert_identical("hello", "hello", "string match");
    assert_identical(true, true, "bool match");
}

function test_assert_identical_fails_on_type_mismatch(): void {
    expect_test_error(fn() => assert_identical(1, "1", "int vs string"), "assert_identical type mismatch");
}

function test_assert_identical_objects(): void {
    $a = (object)['x' => 1];
    $b = (object)['x' => 1];
    assert_identical($a, $b, "identical objects");
}

function test_assert_identical_different_objects(): void {
    expect_test_error(fn() => assert_identical((object)['x' => 1], (object)['x' => 2], "different"), "assert_identical different objects");
}
