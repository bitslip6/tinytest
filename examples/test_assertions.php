<?php
// declare(strict_types=1);

/**
 * standard php assert methods work great for tests
 */
function test_assert() : void  {
    assert_true(true, "true is not true");
}

/**
 * tests with no asserts are marked as "incomplete"
 */
function test_nothing() : void {
	$a = "an test with no asserts";
}

/**
 * a test method with an assertion error
 */
function a_test_method() {
    assert_true(false, "throw a forced assertion error");
}

/**
 * assertions work inside called methods as well and will report the offendingline number correctly
 */
function test_nested_assert() : void  {
    a_test_method();
    assert_true(true, "true is not true");
}

/**
 * or if you prefeer you can use user defined assertions
 * these work regardless of your assertion settings
 */
 function test_internal_assertion() : void {
     assert_eq(1, 1, "equality must match the same type");
     assert_eq("1", "1", "equality must match the same type");
     // assert_eq(1, "1", "equality must match the same type"); // will fail
 }

/**
 * examples of how to use other tinytest assertions
 */
function test_assertion_types() : void {
    assert_contains("a string of data", "of", "string missing of");
    assert_icontains("some different data", "DiFfeR", "string ignore case compare failed");
    // assert_contains("a string of data", "OF", "string case must match"); // will fail
}
