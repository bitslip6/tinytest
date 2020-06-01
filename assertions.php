<?php declare(strict_types=1);
namespace {

    function assert_base_condition(callable $test_fn, $actual, $expected, string $message) {
        TinyTest\count_assertion();
        if ($test_fn($actual, $expected) === false) {
            throw new TinyTest\TestError($message, $actual, $expected);
        }
        TinyTest\count_assertion_pass();
    }

    function assert_true($condition, string $message) {
        assert_base_condition(function($condition, $expected) { return $condition; }, $condition, true, $message);
    }

    function assert_false($condition, string $message) {
        assert_base_condition(function($condition, $expected) { return !$condition; }, $condition, false, $message);
    }

    function assert_eq($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return $actual === $expected; }, $actual, $expected, $message);
    }

    function assert_eqic($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return ($actual != null && strcasecmp($actual, $expected) === 0); }, $actual, $expected, $message);
    }

    function assert_neq($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return $actual !== $expected; }, $actual, $expected, $message);
    }

    function assert_gt($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return $actual > $expected; }, $actual, $expected, $message);
    }

    function assert_lt($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return $actual < $expected; }, $actual, $expected, $message);
    }

    function assert_icontains(string $haystack, string $needle, string $message) {
        assert_base_condition(function(string $needle, string $haystack) { 
            return ($haystack != null && stristr($haystack, $needle) !== false); }, $needle, $haystack, $message);
    }

    function assert_contains(string $haystack, string $needle, string $message) {
        assert_base_condition(function(string $needle, string $haystack) { 
            return ($haystack != null && strstr($haystack, $needle) !== false); }, $needle, $haystack, $message);
    }

    function assert_not_contains(string $haystack, string $needle, string $message) {
        assert_base_condition(function(string $needle, string $haystack) { 
            return ($haystack != null && strstr($haystack, $needle) === false); }, $needle, $haystack, $message);
    }

}