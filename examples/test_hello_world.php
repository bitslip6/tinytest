<?php declare(strict_types=1);

/**
 * returning a string will display the output on the test console output
 */
function  test_return_output() : string  {
    assert_true(true, "true is not true");
    return "hello world 1";
}

/**
 * sending console data via echo or print will also output info in the test output
 */
function  test_display_output() : void  {
    assert_true(true, "true is not true");
    echo "hello world 2";
}
