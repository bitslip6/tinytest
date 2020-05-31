#tinytest

###because you just want to run some tests - not install a "framework"

##features
* Single file < 500 lines of code
* Overideable callback functions for formatting, test selection, etc
* Code Coverage in lcov format _requires phpdbg_
* Functional style - No classes to extend
* Supports variety of test selection methods
* Supports expected exceptions
* Supports data providers for processing test data and conditions
* Generates full reports with code coverage in milliseconds

##todo
* add multithreaded support for large test suites


##install
```
git clone https://github.com/bitslip6/tinytest
or
curl https://github.com/bitslip6/tinytest/release/r1
```

##hello world
**create a test under your project**
```
cd myproject
mkdir tests
cd tests
vim test_helloworld.php
```

**add the following content to your test_helloworld.php**
```
<?php declare(strict_types=1);

function test_hello_world() : string {
  assert_eq(true, true, "oh no! true is not true!");
  return "hello world!";
}
```

**run the test**
tinytest.php requires phpdbg (debug php build) for code coverage reports but can be run without coverage using a standard php 7.x intrepreter.  (see installing phpdbg for additional details). tinytest contains a #! for phpdbg so you can run it as a binary (./tinytest.php) or as a php script (phpdbg ./tinytest.php)

```
/path/to/tinytest.php -f ./tests/test_hello_world.php<pre>/home/cory/tools/tinytest/tinytest.php<font color="#06989A"> Ver 5</font>
loading test file: <font color="#34E2E2">[./tests/test_hello_world.php]                  </font><font color="#4E9A06">  OK</font>
testing function:  <font color="#3465A4">test_hello_world                                </font> <font color="#4E9A06"> OK</font>
<font color="#555753">  -&gt; hello world!</font>
generating lconv.info...
Memory usage: 2,048KB
</pre>

```
