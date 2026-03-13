<?php declare(strict_types=1);

/**
 * @skip not yet implemented
 */
function test_skipped_feature(): void {
    assert_true(false, "this should never run");
}

/**
 * @todo needs database setup
 */
function test_todo_feature(): void {
    assert_true(false, "this should never run either");
}

function test_regex_match(): void {
    assert_matches("hello world 123", "/\d+/", "should contain digits");
    assert_matches("test@example.com", "/^[^@]+@[^@]+\.[^@]+$/", "should look like an email");
    assert_not_matches("hello world", "/\d+/", "should not contain digits");
}

/**
 * @timeout 1
 */
function test_within_timeout(): void {
    assert_true(true, "fast test");
}

/**
 * @timeout 0.01
 */
function test_exceeds_timeout(): void {
    usleep(50000); // 50ms, exceeds 10ms timeout
    assert_true(true, "slow test");
}
