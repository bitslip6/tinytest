# tinytest

### Because you just want to run some tests - not install a "framework"

## Features
* Single file <1000 lines of code
* Code coverage in lcov format _requires phpdbg_
* Profiling in callgrind (Kcachegrind) format _requires xhprof_
* Override callback functions for formatting, test selection, etc
* Functional style - No classes to extend
* Supports variety of test selection methods
* Supports expected exceptions
* Supports data providers for processing test data and conditions
* Generates full reports with code coverage in milliseconds

[![asciicast](https://asciinema.org/a/pEnyZFEObOr2HWStjcM0SRtjb.svg)](https://asciinema.org/a/pEnyZFEObOr2HWStjcM0SRtjb)


## Install
```shell
git clone https://github.com/bitslip6/tinytest
or
curl https://github.com/bitslip6/tinytest/release/r1
```


## Quick Start
create a test under your project:
```shell
cd myproject
mkdir tests
cd tests
vim test_helloworld.php
```


add the following content to your test_helloworld.php:
```php
<?php declare(strict_types=1);

function test_hello_world() : string {
  assert_eq(true, true, "oh no! true is not true!");
  return "hello world!";
}
```

**run the test**

tinytest.php requires phpdbg (debug php build) for code coverage reports but can be run without coverage using a standard php >= 7.x intrepreter.  (see installing phpdbg for additional details). 

```ShellSession
php path/to/tinytest.php -f ./tests/test_hello_world.php

/home/cory/tools/tinytest/tinytest.php Ver 1
loading test file: [./tests/test_hello_world.php]                    OK
testing function:  test_hello_world                                  OK
  -> hello world!
Memory usage: 2,048KB
```

## writing tests
tinytest was created to support testing functional style code and the tests are simple php functions.
See the examples folder for additional test info.


**test assertions**
The following assertion methods are provided for you if you choose to not use the php builtin assert() (or your runtime does not enabnle zend.assertions).  These assert methods also include additional log messages to show actual vs expected and can output additional data to the console. Add your own assertions to user_defined.php and tinytest will include them.  Checkout assertions.php for boilereplate.  Pull requests welcome.

* assert_ture(bool $condition, string $message)  truthy
* assert_false(bool $condition, string $message) falsy
* assert_eq($actual, $expected, string $message) === really equals
* assert_neq($actual, $expected, string $message) !== really not equals
* assert_eqic($actual, $expected, string $message) equals ignore case (for strings) strcasecmp === 0
* assert_gt($actual, $excected, string $message) assert actual is greater than expected
* assert_lt($actual, $excected, string $message) assert actual is less than expected
* assert_contains(string $haystack, string $needle, string $message) assert needle is in haystack
* assert_not_contains(string $haystack, string $needle, string $message) assert needle is NOT in haystack
* assert_icontains(string $haystack, string $needle, string $message) assert needle is in haystack ignore case


```php
<?php
assert_true(false, "an error message", "optional log data to include on verbose output");
```

**test setup**
There is no predefined "setup" method.  Simply create a function and call it as needed in your test file.
_example:_
```php
<?php declare(strict_types=1);

include_once PROJECT_PATH_DIR . "/path/to/file/MyObject.php";

function setup_test() : MyObject {
    return new MyObject("constructor args");
}

function test_object_does_stuff() : void {
    $myObject = setup_test();
    $result = $myObject->does_stuff("input");
    assert($result !== null, "does_stuff returned null");
    assert_neq($result, "foobar", "does_stuff returned foobar!");
}
```

you can add boilerplate to a bootstrap file and add it from the command line, this will run your boilerplate bootstrap once before every test file that is included:
```shellsession
php tinytest.php -b tests/bootstrap.php -d ./tests
php tinytest.php -a -d ./tests
```

## exclude / include test
you can skip over tests by their type annotation by excluding tests with the -e command line parameter.
you can also only run included tests by using the -i command line parameter.  Both parameters can be chained to add additional included or excluded test types.

example:
```php
/**
 * @type sql
 */
funtion test_db_access() : void {
  assert_true(db_connect(), "unable to connect to the db");
}
```

```shellsession
php tinytest.php -f my_tests.php -i sql
tinytest.php (Ver 8)
loading test file: [my_tests.php                            ]  OK
testing function:  test_db_access                              OK           in 0.08483996

1 test, 1 passed, 0 failures/exceptions, using 2,048KB in 0.08483996 seconds
```

## performance profiling

install the [xhprof extension](https://github.com/tideways/php-xhprof-extension).
you can install from source, pecl or maybe your OS distribution.  Tideways xhprof extension is almost fully compatible with the original xhprof extension, and tinytest
can be edited to use xhprof as well by changing 2 lines. [php 7.0 patched xhprof ](https://github.com/patrickallaert/xhprof)

Run your test with the -k or -p options.  One test_name.xhprof.json or callgrind.test_name file will be generated foreach test. callgrind files can be opened with kcachegrind on Linux / Mac.


## code coverage

Code coverage reports are generated by default when using phpdbg.  you can add code coverage to VSCode by installing the [coverage gutters](https://marketplace.visualstudio.com/items?itemName=ryanluker.vscode-coverage-gutters) plugin to VSCode.  Coverage Gutters looks for the generated lcov.info files by default but this can be changed in the coverage gutters settings.  Make sure the generated lcov.info is in your project's root directory.  You probably want to add the generated lcov.info to your .gitignore as well.

__You can exclude code coverage reports by adding the -e command line option.__


## creating test data
We borrowed the @dataprovider annotation from phpunit.  To send test data as invocation for each item in the dataset, simply add the @dataprovider annotation to your phpdoc block.

```php
<?php declare(strict_types=1);

function addition_test_data() : array {
  return array(
    "one plus one = 2" => array(1, 1, 2),
    "two plus two = 4" => array(2, 2, 4),
    "10 plus 10 = 20" => array(10, 10, 200)
  );
}

/**
 * @dataprovider addition_test_data
 */
function test_addition(array $data) : void {
  assert_eq(($data[0] + $data[1]), $data[2], "addition failed");
}

function multiplication_test_data() : array {
  for ($i = 0; $i < 5; $i++) {
    yield array("multiply 3 * $i" => array(3, $i, 3*$i));
   }
}

/**
 * @dataprovider mulitplication_test_data
 */
function test_mulitplication(array $data) : void {
  assert_eq(($data[0] * $data[1]), $data[2], "multiplication failed");
}
```

**output:** 
```ShellSession
tinytest/tinytest.php -f ./tests/test_hello_world.php
tinytest/tinytest.php Ver 5
loading test file: [./tests/test_hello_world.php]                    OK
testing function:  test_hello_world                                  OK
  -> hello world!
testing function:  test_addition                                    error
  /home/cory/tools/bitwaf/bitwaf/tests/test_hello_world.php:20
  expected [200] got [20] addition failed
  -> failed on dataset member [10 plus 10 = 20]
generating lconv.info...
Memory usage: 2,048KB
```

As you can see there is an error in our test data, we added an extra 0 to the expected test output and produced an error.  Change 200 to 20, repeat the test and it will succeed.

#### todo
* add multithreaded support for large test suites

