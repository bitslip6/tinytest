<?php declare(strict_types=1);

function do_some_http_request(string $url) : string {
    return file_get_contents($url);
}

function risky_behavior() : bool {
    return true;
}


/**
 * define test types with the @type annotation
 * you can include type integration  tests with the =i command line parameter
 * you can exclude type integration with the -e command line parameter
 *
 * @type integration
 */
function test_integration_action() : void  {
    $url = __FILE__;
    $result = do_some_http_request($url);

    assert_contains($result, "foobar", "unable to read my own file?");
}

/**
 * no idea why people write "risky" tests, but here it is...
 * exclude this test with -e risky
 * @type risky
 */
function test_something_risky() : void {
    assert_true(risky_behavior(), "risky thing failed");
}

/**
 * you can define any test type that you like.  untyped tests default to "standard"
 * @type my_special_type
 */
function test_foobar() : void {
    assert_true(true, "my special type always works!");
}

