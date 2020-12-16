<?php declare(strict_types=1);
namespace {

    // override the begin test message here
    // function user_format_test_run(string $test_name, array $test_data, array $options) : string { }
    
    // override the test success output
    // function user_format_test_success(array $test_data, array $options, float $time) : string { }

    // this function overrides the test failure output
    // function user_format_assertion_error(array $test_data, array $options, float $time) { }

    // fill in this function to write your own function to identify test file names
    // function user_is_test_file(string $filename, array $options = null) : bool { }

    // fill in this function to write your own function to identify test function names
    // function user_is_test_function(string $funcname, array $options) : bool { }

    /**
     * add your user defined assertions here
     */
    function assert_array_contains($needle, array $haystack, string $message) {
        TinyTest\count_assertion();
        if (!in_array($needle, $haystack)) { 
            throw new TinyTest\TestError("array does not contain [$needle], \"$message\"", join(', ', $haystack), $needle);
        }
        TinyTest\count_assertion_pass();
    }
}

