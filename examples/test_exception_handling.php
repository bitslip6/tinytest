<?php declare(strict_types=1);
mt_srand(time(true));

class AnotherException extends Exception { }

/**
 * @exception RuntimeException
 */
function test_exceptions_1() : void {
    assert_true(true, "yep it's true");
    throw new RuntimeException("this is okay because we defined RuntimeException as okay");
}

/**
 * @exception RuntimeException
 * @exception Exception
 * @exception AnotherException
 */
function test_exceptions_2() : void {
    assert_true(true, "yep it's true");
    $type = mt_rand(1,3);
    // echo "exception type: $type";
    if ($type == 1) {
        throw new RuntimeException("this is okay because we defined RuntimeException as okay");
    } else if ($type == 2) {
        throw new Exception("this is okay because we also defined Exception as okay");
    } else if ($type == 3) {
        throw new AnotherException("unexpected exception");
    }
}
