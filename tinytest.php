#!phpdbg -qrr
<?php declare(strict_types=1);

define("ESC", "\033");
define("RED", ESC . "[31m");
define("LRED", ESC . "[91m");
define("CYAN", ESC . "[36m");
define("GREEN", ESC . "[32m");
define("BLUE", ESC . "[34m");
define("GREY", ESC . "[90m");
define("YELLOW", ESC . "[33m");
define("NORML", ESC . "[39m");
define("VER", "5");
echo __FILE__ . CYAN . " Ver " . VER . NORML . "\n";

/** BEGIN USER EDITABLE FUNCTIONS */
// test if a file is a test file, this should match your test filename format
// $options command line options
// $filename is th file to test
function is_test_file(string $filename, array $options) : bool {
    return (startsWith($filename, "test_") && endsWith($filename, "php"));
}

// test if a function is a valid test function also limits testing to a single function
// $funcname - function name to test
// $options - command line options
function is_test_function(string $funcname, array $options) {
    if (isset($options['t'])) {
        return $funcname == $options['t'];
    }
    return (substr($funcname, 0, 5) === "test_" ||
            substr($funcname, 0, 3) === "it_" ||
            substr($funcname, 0, 7) === "should_");
}

// format test failures
function format_assertion_error(string $test_name, string $result, Error $ex) {
    echo RED . "error\n" . NORML;
    echo YELLOW . "  " . $ex->getFile() . NORML . ":" . $ex->getLine() . "\n";
    echo LRED . "  " . $ex->getMessage() . NORML . "\n" ;
    display_test_output($result);
    //echo GREY . $ex->getTraceAsString(). NORML . "\n";
}

// format test success
function format_test_success(string $test_name, string $result = null) {
    echo GREEN . " OK\n" . NORML;
    display_test_output($result);
}

// display the test returned string output
function display_test_output(string $result) {
    if ($result != null) {
        $r = explode("\n", $result);
        array_walk($r, say(GREY, "  -> "));
    }
}

// format the test running 
function format_test_run(string $test_name) {
    printf("testing function:  %s%-48s%s ", BLUE, $test_name, NORML);
}
/** END USER EDITABLE FUNCTIONS */

// assertion functions
/** user assertion functions */
function assert_true($condition, string $message) { if (!$condition) { throw new TestError("expected [$condition] to be true $message"); } }
function assert_false($condition, string $message) { if ($condition) { throw new TestError("expected [$condition] to be false $message"); } }
function assert_eq($actual, $expected, string $message) { if ($actual !== $expected) { throw new TestError("expected [$expected] got [$actual] $message"); } }
function assert_eqic($actual, $expected, string $message) { if ($actual != null && strcasecmp($actual, $expected) !== 0) { throw new TestError("expected [$expected] got [$actual] $message"); } }
function assert_neq($actual, $expected, string $message) { if ($actual === $expected) { throw new TestError("expected [$expected] !== [$actual] $message"); } }
function assert_gt($actual, $expected, string $message) { if ($actual <= $expected) { throw new TestError("expected [$expected] > [$actual] $message"); } }
function assert_lt($actual, $expected, string $message) { if ($actual >= $expected) { throw new TestError("expected [$expected] < [$actual] $message"); } }
function assert_icontains($actual, $expected, string $message) { if (stristr($actual, $expected) === false) { throw new TestError("expected [$expected] < [$actual] $message"); } }
function assert_contains($actual, $expected, string $message) { if (strstr($actual, $expected) === false) { throw new TestError("expected [$expected] < [$actual] $message"); } }


// helper functions
/** internal helper functions */
function panic_if(bool $result, string $msg) {if ($result) { die($msg); }}
function panic_ifnot(bool $result, string $msg) {if (!$result) { die($msg); }}
function between(int $data, int $min, int $max) { return $data >= $min && $data <= $max; }
function do_for_all(array $data, callable $fn) { foreach ($data as $item) { $fn($item); } }
function do_for_allkey(array $data, callable $fn) { foreach ($data as $key => $item) { $fn($key); } }
function do_for_all_key_value(array $data, callable $fn) { foreach ($data as $key => $item) { $fn($key, $item); } }
function do_for_all_key_value_recursive(array $data, callable $fn) { foreach ($data as $key => $items) { foreach ($items as $item) { $fn($key, $item); } } }
function if_then_do($testfn, $action, $optionals = null) : callable { return function($argument) use ($testfn, $action, $optionals) { if ($argument && $testfn($argument, $optionals)) { $action($argument); }}; }
function is_equal_reduced($value) : callable { return function($initial, $argument) use ($value) { return ($initial || $argument === $value); }; }
function is_contain($value) : callable { return function($argument) use ($value) { return (strstr($argument, $value) !== false); }; }
function startsWith(string $haystack, string $needle) { return (substr($haystack, 0, strlen($needle)) === $needle); } 
function endsWith(string $haystack, string $needle) { return (substr($haystack, -strlen($needle)) === $needle); } 
function say($color = '\033[39m', $prefix = "") : callable { return function($line) use ($color, $prefix) { if(strlen($line) > 0) { echo "{$color}{$prefix}{$line}".NORML."\n"; } }; } 
function last_element(array $items, $default = "") { return (count($items) > 0) ? array_slice($items, -1, 1)[0] : $default; }



// load a single unit test
function load_file($file) {
    assert(is_file($file), "test directory is not a directory");
    printf("loading test file: \033[96m%-48s\033[39m", sprintf("[%s]", $file));
    require "$file";
    echo GREEN . "  OK\n" . NORML;
}

// load all unit tests in a director
function load_dir(string $dir, array $options) {
    assert(is_dir($dir), "[$dir] is not a directory");
    $action = function($item) use ($dir) { load_file($dir . DIRECTORY_SEPARATOR . $item); };
    do_for_all(scandir($dir), if_then_do("is_test_file", $action, $options));
}

// check if this test should be excluded, returns false if test should run
function is_excluded_test(array $test_data, array $options) {
    // default to exclusion
    // match function reverses for inclusion/exclusion
    $test_value = $options['e'] ?? '';
    $matchfn = function($v1, $v2) : bool { return $v1 === $v2; };

    // prefer inclusion only tests
    if (isset($options['i'])) {
        $test_value = $options['i'];
        $matchfn = function($v1, $v2) : bool { return $v1 !== $v2; };
    } 
    // no options passed, reply to just run everything
    else if (!isset($options['e'])) {
        return false;
    }

    // handle single options (string)
    if (is_string($test_value)) {
        return $matchfn($test_data['type'], $test_value);
    }
    // handle array options (multiple values)
    else { 
        return $matchfn(array_reduce($test_value, is_equal_reduced($test_data['type']), false), true);
    }
}

// read the test annotations
function read_test_data(string $testname) : array {
    $result = array('exception' => array(), 'type' => 'standard');
    $refFunc = new ReflectionFunction($testname);
    $doc = $refFunc->getDocComment();
    if ($doc === false) { return $result; }

    $docs = explode("\n", $doc);
    array_walk($docs, function ($line) use (&$result) {
        $last = last_element(explode(" ", $line));
        if (preg_match("/\@(\w+)/", $line, $matches)) {
            if ($matches[1] === "exception") {
                array_push($result['exception'], $last);
            } else {
                $result[$matches[1]] = $last;
            }

        }
    });

    return $result;
}

// show the test runner usage
function show_usage() {
    echo " -d <directory> " . GREY . "load all tests in directory\n" . NORML;
    echo " -f <file>      " . GREY . "load all tests in file\n". NORML;
    echo " -t <test_name> " . GREY . "run just the test named test_name\n" . NORML;
    echo " -i <test_type> " . GREY . "only include tests of type <test_type> multiple -i parameters\n" . NORML;
    echo " -e <test_type> " . GREY . "exclude tests of type <test_type> multiple -e parameters\n" . NORML;
    echo " -b <bootstrap> " . GREY . "include a bootstrap file before running tests\n" . NORML;
    echo " -x " . GREY . "            exclude code coverage information\n" . NORML;
    echo " -q " . GREY . "            hide test console output\n" . NORML;
}

// return true if the $test_dir is in the testing path directory
function is_test_path($test_dir) : callable {
    return function($item) use($test_dir) {
        return strstr($item, $test_dir) !== false;
    };
}

/** BEGIN CODE COVERAGE FUNCTIONS */
// merge the oplog after every test adding all new counts to the overall count
function combine_oplog(array $cov, array $newdata, array $options) {
    
    // remove unit test files from oplog
    $remove_element = function($item) use (&$newdata) { unset($newdata[$item]); };
    do_for_allkey($newdata, if_then_do(is_contain($options['d'] ?? $options['f']), $remove_element));

    // a bit ugly...
    foreach($newdata as $file => $lines) {
        if (isset($cov[$file])) {
            foreach($lines as $line => $cnt1) {
                $cov[$file][$line] = $cnt1 + ($cov[$file][$line] ?? 0);
            }
        }
        else {
            $cov[$file] = $lines;
        }
    }

    return $cov;
}

// return true only if token is valid with lineno, and is not whitespace or other crap
function is_important_token($token) : bool {
    return (!is_array($token) || in_array($token[0], array(379, 382, 378, 323, 377, 268))) ? false : true;
}

// return a new function definition
function new_line_definition(int $lineno, string $name, string $type) {
    return array("start" => $lineno, "type" => $type, "end" => $lineno, "name" => $name, "hit" => HIT_MISS);
} 

// find the function, branch or statement at lineno for source_listing
function find_index_lineno_between(array $source_listing, int $lineno) {
    for($i=0,$m=count($source_listing); $i<$m; $i++) {
        if (!isset($source_listing[$i])) { continue; } // skip empty items
        if (between($lineno, $source_listing[$i]['start'], $source_listing[$i]['end'])) {
           return $i;
        }
    }
    //print_r($source_listing);
    return 0;
}

// main lcov file format output
function format_output(string $type, array $def, int $hit) {
    switch ($type) {
        case "fn":
            return "FNDA:{$hit},{$def['name']}\n";
        case "da":
            return "DA:{$def['start']},$hit\n";
        case "brda":
            return "BRDA:{$def['start']},".mt_rand(100000,900000).",".mt_rand(100000,900000).",$hit\n";
    }
}

// combine the source file mappings, with the covered lines and produce an lcov output
function output_lcov(string $file, array $covered_lines, array $src_mapping, bool $showcoverage = false) {
    // loop over all covered lines and update hit counts
    do_for_all_key_value($covered_lines, function($lineno, $cnt) use (&$src_mapping, $file) {
        // loop over all covered line types
        do_for_allkey($src_mapping, function($src_type) use (&$index, &$src_mapping, $lineno, &$type, $cnt) {
            // see if this line is one of our line types
            $index = find_index_lineno_between($src_mapping[$src_type], $lineno);
            // update the hit count for this line
            if ($index > 0) { 
                $src_mapping[$src_type][$index]["hit"] = min($src_mapping[$src_type][$index]["hit"], $cnt);
            }
        });
    });
    
    $hits = array("fn" => 0, "brda" => 0, "da" => 0);
    $outputs = array("fnprefix" => "", "fn" => "", "brda" => "", "da" => "");
    // loop over all source lines with updated hit counts and product the output format
    do_for_all_key_value_recursive($src_mapping, function($type, $def) use (&$hits, &$outputs) {
        $hit = ($def['hit'] === HIT_MISS) ? 0 : $def['hit'];
        if ($hit > 0) { $hits[$type]++; }
        $outputs[$type] .= format_output($type, $def, $hit);
        // special case since functions have 2 outputs...
        if ($type == "fn") {
            $outputs["fnprefix"] .= "FN:{$def['start']},{$def['name']}\n";
        }
    });

    // update the lcov coverage totals
    $outputs['fn'] .= "FNF:".count($src_mapping['fn'])."\nFNH:{$hits['fn']}\n";
    $outputs['brda'] .= "BRF:".count($src_mapping['brda'])."\nBRH:{$hits['brda']}\n";
    $outputs['da'] .= "LF:".count($src_mapping['da'])."\nLH:{$hits['da']}\n";

    // output to the console the coverage totals
    if ($showcoverage) {
        echo "$file\n";
        echo "function coverage: {$hits['fn']}/".count($src_mapping['fn'])."\n";
        echo "conditional coverage: {$hits['brda']}/".count($src_mapping['brda'])."\n";
        echo "statement coverage: {$hits['da']}/".count($src_mapping['da'])."\n";
    }
    // return the combined outputs
    return array_reduce($outputs, function($result, $item) { return $result . $item; }, "SF:$file\n") . "end_of_record\n";
}

// a bit ugly, consider some state machine???
// take a mapping of file => array(tokens) and create a source mapping for function, branch, statement 
function make_source_map_from_tokens(array $tokens) {
    $funcs = get_defined_functions(false);
    $lcov = array();
    foreach ($tokens as $file => $tokens) {
        $lcov[$file] = array("fn" => array(), "da" => array(), "brda" => array());
        $fndef = new_line_definition(0, '', 'fn');
        $lastname = "";
        foreach ($tokens as $token) {
            // skip whitespace and other tokens we don't care about, as well as non tokenizable stuff
            if (!is_important_token($token)) { 
                // not whitespace, and function name is empty, then we havre anon function... ugly hack
                if ($token[0] != 382 && $fndef['name'] == "" && strlen($lastname) > 0) {
                    $fndef['name'] = $lastname;
                }
                continue;
            }

            $nm = token_name($token[0]);
            $src = $token[1];
            $lineno = $token[2];

            if ($nm == "T_STRING" && $fndef['name'] == "") {
                $fndef = new_line_definition($lineno, $src, "fn");
                array_push($lcov[$file]["fn"], $fndef);
            }
            else if ($nm == "T_FUNCTION") {
                // a new function.  end the previous function
                if ($fndef['name'] != '') { 
                    // update the end of the last function to this line -1, ugly hack
                    $lcov[$file]["fn"][count($lcov[$file]["fn"])-1]['end'] = $lineno-1;
                    $lastname = $fndef['name'];
                    $fndef['name'] = "";
                }
            }
            // handle user and system function calls
            else if ($nm == "T_STRING") {
                if (in_array($token[1], $funcs['internal']) || in_array($token[1], $funcs['user'])) {
                    array_push($lcov[$file]["da"], new_line_definition($lineno, "S", "da"));
                }
            }
            else if ($nm == "T_IF") {
                array_push($lcov[$file]["brda"], new_line_definition($lineno, $src, "brda"));
            }
            else { 
                array_push($lcov[$file]["da"], new_line_definition($lineno, "E", "da"));
            }
        }
        // add the last function definition
        $fndef['end'] = 999999;
        array_push($lcov[$file]["fn"], $fndef);

        // remove statement lines we have multiple tokens for
        $keep_map = array();
        $lcov[$file]['da'] = array_filter($lcov[$file]['da'], function ($element) use (&$keep_map) {
            if (!isset($keep_map[$element['start']])) {
                $keep_map[$element['start']] = true;
                return true;
            }
            return false;
        });
    }

    return $lcov;
}

function coverage_to_lcov(array $coverage, bool $showcoverage = false) {

    // read in all source files and parse the php tokens
    $tokens = array();
    do_for_allkey($coverage, function($file) use (&$tokens) {
        $tokens[$file] = token_get_all(file_get_contents($file));
    });

    // convert the tokens to a source map
    $src_map = make_source_map_from_tokens($tokens);
    $res = "";
    // combine the coverage output with the source map and produce an lcov output
    foreach($src_map as $file => $mapping) {
        $res .= output_lcov($file, $coverage[$file], $mapping, $showcoverage);
    } 

    return $res;
}
/** END CODE COVERAGE FUNCTIONS */

// a generic test filter with fixed file and line number reporting
define("HIT_MISS", 999999999);
class TestError extends Error {
    public function __construct(string $message, int $code = 0, Exception $ex = null) {
        parent::__construct($message, $code, $ex);
        if ($ex !== null) {
            $this->line = $ex->getLine();
            $this->file = $ex->getFile();
        } else {
            $bt = last_element(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2));
            $this->line = $bt['line'];
            $this->file = $bt['file'];
        }
    }
}


/** MAIN ... */
// process command line options
$options = getopt("b:d:f:t:i:e:qxhr?");
$quiet = isset($options['q']) ? true : false;

if (!isset($options['d']) && !isset($options['f']) || isset($options['h']) || isset($options['?'])) { die(show_usage()); }
// verify assertion state
//panic_ifnot(ini_get("zend.assertions") == 1, "zend.assertions but be enabled in the phpdbg ini file");
ini_set("assert.exception", "1");
$funcs1 = get_defined_functions(true);

// load bootstrap file
if (isset($options['b'])) {
    require $options['b'];
}
// load the tests
if (isset($options['d'])) {
    load_dir($options['d'], $options);
} else if ($options['f']) {
    load_file($options['f']);
}

// filter out test framework functions
$coverage = array();
$funcs3 = array_filter(get_defined_functions(true)['user'], function($fn_name) use ($funcs1) { return !in_array($fn_name, $funcs1['user']); });


// a bit ugly
// loop over all user included functions
foreach ($funcs3 as $func) {
    // exclude functions that dont match test signature
    if (!is_test_function($func, $options)) { continue; }
    // read the test annotations, exclude test types
    $test_data = read_test_data($func);
    if (is_excluded_test($test_data, $options)) { continue; }

    // display the test we are running
    format_test_run($func);

    // turn on output buffer and start the operation log for code coverage reporting
    if (!isset($options['x'])) { \phpdbg_start_oplog(); }
    ob_start();

    // run the test
    $error = null;
    $result = "";
    $key = "";
    try {
        if (isset($test_data['dataprovider'])) {
            $data = call_user_func($test_data['dataprovider']);
            foreach ($data as $key => $value) {
                $result .= $func($value);
            }
        } else {
            $result = $func();
        }
    }
    // test failures and developer test errors
    catch (AssertionError | TypeError | TestError $ex) {
        $error = $ex;
    }
    // test generated an exception
    catch (\Exception $ex) {
        // if it was not an expected exception, test failure
        if (array_reduce($test_data['exception'], is_equal_reduced(get_class($ex)), false) === false) {
            $error = new TestError("unexpected " . get_class($ex) . " \"" . $ex->getMessage() . "\"", $ex->getCode(), $ex);
        }
    }
    // display the result
    finally  {
        $result = (!$quiet) ? ob_get_contents() . "\n$result" : $result;
        ob_end_clean();
        if (!$error) {
            format_test_success($func, $result);
        } else {
            if ($key !== "") { $result = "failed on dataset member [$key]\n$result"; }
            format_assertion_error($func, $result, $error);
        }
    }

    // code coverage
    if (!isset($options['x'])) { 
        $oplog = phpdbg_end_oplog();
        unset($oplog[__FILE__]);
        $coverage = combine_oplog($coverage, $oplog, $options);
    }
}

if (count($coverage) > 0) {
    echo "generating lconv.info...\n";
    file_put_contents("../lcov.info", coverage_to_lcov($coverage, isset($options['r'])));
}
echo "Memory usage: " . number_format(memory_get_peak_usage(true)/1024) . "KB\n";
