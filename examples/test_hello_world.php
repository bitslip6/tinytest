<?php declare(strict_types=1);

/**
 * returning a string will display the output on the test console output
 */
function  test_return_output() : string  {
    assert_true(true, "true is not true");
	return "Hello World!";
}

/**
 * returning a string will display the output on the test console output
 */
function  test_return_output_2() : string  {
    assert_true(true, "true is not true");
	$data = "a list of things";
	return str_replace(" ", "\n", $data);
}


/**
 * sending console data via echo or print will also output info in the test output
 */
function  test_display_output() : void  {
    assert_true(true, "true is not true");
    echo "hello world 2\n";
    echo "hello world 3\n";
}
