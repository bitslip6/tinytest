#!phpdbg -qrr,
<?php declare(strict_types=1);
namespace TinyTest {
define("VER", "9");
define('YEARAGO', time() - 86400 * 365);

/** BEGIN USER EDITABLE FUNCTIONS, override in user_defined.php and prefix with "user_" */
// test if a file is a test file, this should match your test filename format
// $options command line options
// $filename is th file to test
function is_test_file(string $filename, array $options = null) : bool {
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
// format test success
function format_test_success(array $test_data, array $options, $t0, $t1) : string {
    $out = ($test_data['status'] == "OK") ? GREEN : YELLOW;
    if (little_quiet($options)) {
        $out .= sprintf("%-3s%s in %s\n", $test_data['status'], NORML, round($t1-$t0, 6));
    } else if (very_quiet($options)) {
        $out .= ".";
    }
    return $out . display_test_output($test_data['result'], $options);
}

// display the test returned string output
function display_test_output(string $result = null, array $options) {
    return ($result != null && not_quiet($options)) ?
        GREY . substr(str_replace("\n", "\n  -> ", "\n".rtrim($result)), 1) . "\n" . NORML:
        "";
}

// format the test running. only return data if 0 or 1 -q options
function format_test_run(string $test_name, array $test_data, array $options) : string {
    $tmp = explode(DIRECTORY_SEPARATOR, $test_data['file']);
    $file = end($tmp);
    $file = substr($file, -32);
    return (little_quiet($options)) ? sprintf("%s%-32s :%s%-16s/%s%-42s%s ", CYAN, $file, GREY, $test_data['type'], BLUE_BR, $test_name, NORML) : '';
}

// format test failures , simplify?
function format_assertion_error(array $test_data, array $options, $t0, $t1) {
    $out = "";
    $ex = $test_data['error'];
    if (little_quiet($options)) {
        $out .= sprintf("%s%-3s%s in %s\n", RED, "err", NORML, round($t1-$t0, 8));
        $out .= YELLOW . "  " . $ex->getFile() . NORML . ":" . $ex->getLine() . "\n";
    }
    if (not_quiet($options)) {
        $out .= LRED . "  " . $ex->getMessage() . NORML . "\n" ;
    }
    if (very_quiet($options)) { 
        $out = "E";
    }
    if (full_quiet($options)) {
        $out = "";
    } 
    if (isset($options['v'])) {
        $out .= GREY . $ex->getTraceAsString(). NORML . "\n";
    }
    return $out . display_test_output($test_data['result'], $options);
}
/** END USER EDITABLE FUNCTIONS */

// assertion functions located in assertion.php



/** internal helper functions */
// TODO bind options array to global option helpers...
function not_quiet(array $options) : bool { return $options['q'] == 0; }
function little_quiet(array $options) : bool { return $options['q'] <= 1; }
function very_quiet(array $options) : bool { return $options['q'] == 2; }
function full_quiet(array $options) : bool { return $options['q'] >= 3; }
function verbose(array $options) : bool { return isset($options['v']); }
function count_assertion() { $GLOBALS['assert_count']++; }
function count_assertion_pass() { count_assertion(); $GLOBALS['assert_pass_count']++; }
function count_assertion_fail() { count_assertion(); $GLOBALS['assert_fail_count']++; }
function panic_if(bool $result, string $msg) {if ($result) { die($msg); }}
function warn_ifnot(bool $result, string $msg) {if (!$result) { printf("%s%s%s\n", YELLOW, $msg, NORML); }}
function between(int $data, int $min, int $max) { return $data >= $min && $data <= $max; }
function do_for_all(array $data, callable $fn) { foreach ($data as $item) { $fn($item); } }
function do_for_allkey(array $data, callable $fn) { foreach ($data as $key => $item) { $fn($key); } }
function do_for_all_key_value(array $data, callable $fn) { foreach ($data as $key => $item) { $fn($key, $item); } }
function do_for_all_key_value_recursive(array $data, callable $fn) { foreach ($data as $key => $items) { foreach ($items as $item) { $fn($key, $item); } } }
function keep_if_key(array $data, callable $fn) { $result = $data; foreach ($data as $key => $item) { if (!$fn($key)) { unset($result[$key]); } return $result; }}
function if_then_do($testfn, $action, $optionals = null) : callable { return function($argument) use ($testfn, $action, $optionals) { if ($argument && $testfn($argument, $optionals)) { $action($argument); }}; }
function is_equal_reduced($value) : callable { return function($initial, $argument) use ($value) { return ($initial || $argument === $value); }; }
function is_contain($value) : callable { return function($argument) use ($value) { return (strstr($argument, $value) !== false); }; }
function is_not_contain($value) : callable { return function($argument) use ($value) { $r = strstr($argument, $value); $nc = $r === false; echo "test $argument !== $value ($r [$nc])\n"; return (strstr($argument, $value) === false); }; }
function startsWith(string $haystack, string $needle) { return (substr($haystack, 0, strlen($needle)) === $needle); } 
function endsWith(string $haystack, string $needle) { return (substr($haystack, -strlen($needle)) === $needle); } 
function say($color = '\033[39m', $prefix = "") : callable { return function($line) use ($color, $prefix) : string { return (strlen($line) > 0) ? "{$color}{$prefix}{$line}".NORML."\n" : ""; }; } 
function last_element(array $items, $default = "") { return (count($items) > 0) ? array_slice($items, -1, 1)[0] : $default; }
function line_at_a_time(string $filename) : iterable { $r = fopen($filename, 'r'); $i=0; while (($line = fgets($r)) !== false) { $i++; yield "line $i" => trim($line); } }
function get_mtime(string $filename) : string { $st = stat($filename); $m = $st['mtime']; return strftime(($m < YEARAGO) ? "%h %y" : "%h %d", $m); }
function fatals() { echo "\n"; $out = file_get_contents("/tmp/tinytest"); if ($out && strlen($out) > 0) { fwrite(STDERR, $out); } @unlink("/tmp/tinytest"); }


// initialize the system
function init(array $options) : array {
    // global state (yuck)
    global $m0;
    $m0 = microtime(true);
    $GLOBALS['assert_count'] = $GLOBALS['assert_pass_count'] = $GLOBALS['assert_fail_count'] = 0;

    // remove empty include / exclude options
    if ($options['i'][0] == '') { unset($options['i']); }
    if ($options['e'][0] == '') { unset($options['e']); }
    // only allow 1 include / exclude
    $to_remove = (isset($options['i'])) ? 'e' : 'i';
    unset($options[$to_remove]);

    // define console colors
    define("ESC", "\033");
    $d = array("RED"=>0, "LRED"=>0, "CYAN"=>0, "GREEN"=>0, "BLUE"=>0, "GREY"=>0, "YELLOW"=>0, "UNDERLINE"=>0, "NORML" => 0); 
    if (!isset($options['m'])) { $d = array("RED"=>31, "LRED"=>91, "CYAN"=>36, "GREEN"=>32, "BLUE"=>34, "GREY"=>90, "YELLOW"=>33, "UNDERLINE"=>"4:3", "NORML" => 0); }
    do_for_allkey($d, function($name) use ($d) { define($name, ESC . "[".$d[$name]."m"); define("{$name}_BR", ESC . "[".$d[$name].";1m"); });

    // program info
    echo __FILE__ . CYAN . " Ver " . VER . NORML . "\n";

    // include test assertions
    require __DIR__ . "/assertions.php";
    if (file_exists("user_defined.php")) { include_once "user_defined.php"; }

    // usage help
    if ((!isset($options['d']) && !isset($options['f'])) || isset($options['h']) || isset($options['?'])) { die(show_usage()); }
    // set assertion state
    ini_set("assert.exception", "1");

    // squelch error reporting if requested
    error_reporting($options['s'] ? 0 : E_ALL);
    //posix_mkfifo("/tmp/tinytest", 0600);
    @unlink("/tmp/tinytest");
    ini_set("error_log", "/tmp/tinytest");
    gc_enable();
    register_shutdown_function("TinyTest\\fatals");
    
    return $options;
}

// load a single unit test
function load_file(string $file, array $options) : void {
    assert(is_file($file), "test directory is not a directory");
    if (verbose(($options))) {
        printf("loading test file: [%s%-45s%s]", CYAN, $file, NORML);
    }
    include "$file";
    if (verbose(($options))) {
        echo GREEN_BR . "  OK\n" . NORML;
    }
}

// load all unit tests in a directory
function load_dir(string $dir, array $options) {
    assert(is_dir($dir), "[$dir] is not a directory");
    $action = function($item) use ($dir, $options) { load_file($dir . DIRECTORY_SEPARATOR . $item, $options); };
    $is_test_file_fn = (function_exists("user_is_test_file")) ? "user_is_test_file" : "TinyTest\is_test_file";
    do_for_all(scandir($dir), if_then_do($is_test_file_fn, $action, $options));
}

// check if this test should be excluded, returns false if test should run
function is_excluded_test(array $test_data, array $options) {
    if (isset($options['i'])) {
        return !in_array($test_data['type'], $options['i']);
    }
    if (isset($options['e'])) {
        return in_array($test_data['type'], $options['e']);
    }
    return false; 
}

// read the test annotations
function read_test_data(string $testname) : array {
    $refFunc = new \ReflectionFunction($testname);
    $result = array('exception' => array(), 'test' => $testname, 'type' => 'standard', 'file' => $refFunc->getFileName(), 'line' => $refFunc->getStartLine(), 'phperror' => array());
    $result['mtime'] = get_mtime($result['file']);
    $doc = $refFunc->getDocComment();
    if ($doc === false) { return $result; }

    $docs = explode("\n", $doc);
    array_walk($docs, function ($line) use (&$result) {
        $last = last_element(explode(" ", $line));
        if (preg_match("/\@(\w+)(.*)/", $line, $matches)) {
            if ($matches[1] === "exception") {
                array_push($result['exception'], $last);
            } else if ($matches[1] === "phperror") {
                array_push($result['phperror'], $matches[2]);
            } else {
                $result[$matches[1]] = $last;
            }
        }
    });

    return $result;
}

// show the test runner usage
function show_usage() {
    warn_ifnot(ini_get("zend.assertions") == 1, "zend.assertions are disabled. set zend.assertions in " . php_ini_loaded_file());
    echo " -d <directory> " . GREY . "load all tests in directory\n" . NORML;
    echo " -f <file>      " . GREY . "load all tests in file\n". NORML;
    echo " -t <test_name> " . GREY . "run just the test named test_name\n" . NORML;
    echo " -i <test_type> " . GREY . "only include tests of type <test_type> multiple -i parameters\n" . NORML;
    echo " -e <test_type> " . GREY . "exclude tests of type <test_type> multiple -e parameters\n" . NORML;
    echo " -b <bootstrap> " . GREY . "include a bootstrap file before running tests\n" . NORML;
    echo " -c " . GREY . "            include code coverage information (generate lcov.info)\n" . NORML;
    echo " -q " . GREY . "            hide test console output (up to 3x -q -q -q)\n" . NORML;
    echo " -m " . GREY . "            set monochrome console output\n" . NORML;
    echo " -v " . GREY . "            set verboise output (stack traces)\n" . NORML;
    echo " -s " . GREY . "            squelch php error reporting (YUCK!)\n" . NORML;
    echo " -r " . GREY . "            display code coverage totals (assumes -c)\n" . NORML;
    echo " -p " . GREY . "            save .xprof profiling tideways or xhprof profilers\n" . NORML;
    echo " -a " . GREY . "            auto detect a bootstrap file\n" . NORML;
    echo " -l " . GREY . "            just list tests, don't run \n" . NORML;
}

// return true if the $test_dir is in the testing path directory
function is_test_path($test_dir) : callable {
    return function($item) use($test_dir) {
        return strstr($item, $test_dir) !== false;
    };
}

/** BEGIN CODE COVERAGE FUNCTIONS */
// merge the oplog after every test adding all new counts to the overall count
function combine_oplog(array $cov, array $newdata, array $options) : array {
    
    // remove unit test files from oplog data
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
function new_line_definition(int $lineno, string $name, string $type, int $end) : array {
    return array("start" => $lineno, "type" => $type, "end" => $end, "name" => $name, "hit" => HIT_MISS);
} 

// find the function, branch or statement at lineno for source_listing
function find_index_lineno_between(array $source_listing, int $lineno, string $type) : int {
    //print_r($source_listing);
    for($i=0,$m=max(array_keys($source_listing)); $i<$m; $i++) {
        if (!isset($source_listing[$i])) { continue; } // skip empty items

        //echo "BETWEEN [$lineno] $type\n";
        //if ($type == "da") { print "is between: ". $source_listing[$i]['start'] . "\n"; }
        if (between($lineno, $source_listing[$i]['start'], $source_listing[$i]['end'])) {
           //echo "HEY FOUND [$i] $type\n";
           //print_r($source_listing[$i]);
           return $i;
        }
        if ($type == "da") {
            //echo "check $lineno {$source_listing[$i]['start']} @ {$source_listing[$i]['name']} $i\n";
        }
    }
    return -1;
}

// main lcov file format output
// TODO: get which branch was taken in oplog output and update branch path here
// TODO: replace first mt_rand with an actual internal branch number.  maybe a counter for function definition number?
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
            //echo "check $lineno [$src_type]\n";
            $index = find_index_lineno_between($src_mapping[$src_type], $lineno, $src_type);
            //echo "find [$src_type] [$lineno] - $index\n";
            // update the hit count for this line
            if ($index >= 0) { 
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
        //print_r($src_mapping['da']);
        echo "function coverage: {$hits['fn']}/".count($src_mapping['fn'])."\n";
        echo "conditional coverage: {$hits['brda']}/".count($src_mapping['brda'])."\n";
        echo "statement coverage: {$hits['da']}/".count($src_mapping['da'])."\n";
    }
    // return the combined outputs
    return array_reduce($outputs, function($result, $item) { return $result . $item; }, "SF:$file\n") . "end_of_record\n";
}

// a bit ugly, consider some state machine abstraction, may require 2 passes...???
// take a mapping of file => array(tokens) and create a source mapping for function, branch, statement 
function make_source_map_from_tokens(array $tokens) {
    $funcs = get_defined_functions(false);
    $lcov = array();
    foreach ($tokens as $file => $tokens) {
        $lcov[$file] = array("fn" => array(), "da" => array(), "brda" => array());
        $fndef = new_line_definition(0, '', 'fn', 999999);
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

            if ($nm == "T_STRING" && $fndef['name'] == "" && $src != "strict_types") {
                $fndef = new_line_definition($lineno, $src, "fn", 999999);
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
                    array_push($lcov[$file]["da"], new_line_definition($lineno, "S", "da", $lineno));
                }
            }
            else if ($nm == "T_IF") {
                array_push($lcov[$file]["brda"], new_line_definition($lineno, $src, "brda", $lineno));
            }
            else { 
                array_push($lcov[$file]["da"], new_line_definition($lineno, "E", "da", $lineno));
            }
        }
        // add the last function definition
        //$fndef['end'] = 999999;
        //array_push($lcov[$file]["fn"], $fndef);

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
 
function keep_fn($options) : callable {
    $is_test_function_fn = function_exists("user_is_test_function") ? "user_is_test_function" : "TinyTest\\is_test_function";
    return function($fn_name) use($options, $is_test_function_fn) : bool {
        return $is_test_function_fn($fn_name, $options);
    };
}

// take coverage data from oplog and convert to lcov file format
function coverage_to_lcov(array $coverage, array $options) {

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
        $res .= output_lcov($file, $coverage[$file], $mapping, $options['r']);
    } 

    return $res;
}
/** END CODE COVERAGE FUNCTIONS */

define("HIT_MISS", 999999999);
// internal assert errors.  handle getting correct file and line number.  formatting for assertion error
// todo: add user override callback for assertion error formatting
class TestError extends \Error {
    public function __construct(string $message, $actual, $expected, \Exception $ex = null) {
        $formatted_msg = sprintf("%sexpected [%s%s%s] got [%s%s%s] \"%s%s%s\"", NORML, GREEN, $expected, NORML, YELLOW, $actual, NORML, RED, $message, NORML);

        parent::__construct($formatted_msg, 0, $ex);
        if ($ex !== null) {
            $this->line = $ex->getLine();
            $this->file = $ex->getFile();
        } else {
            $bt = last_element(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3));
            $this->line = $bt['line'];
            $this->file = $bt['file'];
        }
    }
}

function format_profile(array $data, array $options) : string {
    foreach (array_keys($data) as $key) {
        if (strstr($key, 'TinyTest') !== false || strstr($key, 'assert_') !== false) {
            unset($data[$key]);
        }
    }
    print_r($data);
    return json_encode($data);
}

// coerce get_opt to something we like better...
function parse_options(array $options) : array {
    // count quiet setting
    $q = $options['q'] ?? array();
    $options['q'] = is_array($q) ? count($q) : 1;

    // force inclusion to array type
    $i = $options['i'] ?? '';
    $options['i'] = is_array($i) ? $options['i'] : array($i);

    // force exclusion to array type
    $e = $options['e'] ?? '';
    $options['e'] = is_array($e) ? $options['e'] : array($e);

    // load test bootstrap file
    if (isset($options['b'])) { require $options['b']; }
    else if (isset($options['a'])) { 
        $d = dirname(isset($options['f']) ? $options['f'] : $options['d']);
        if (file_exists("$d/bootstrap.php")) { require "$d/bootstrap.php"; }
    }

    // php error squelching
    $options['s'] = isset($options['s']) ? true : false;
    $options['c'] = isset($options['c']) ? true : false;
    $options['l'] = isset($options['l']) ? true : false;
    $options['p'] = isset($options['p']) ? true : false;
    // code coverage reporting
    $options['r'] = isset($options['r']) ? true : false;
    if ($options['r']) { $options['c'] = true; }
    return $options;
}

/** MAIN ... */
// process command line options
$options = parse_options(getopt("b:d:f:t:i:e:pmqchrvsal?"));
$options = init($options);

// get a list of all tinytest fucntion names
$funcs1 = get_defined_functions(true);
unset($funcs1['internal']);

// load the unit test files
if (isset($options['d'])) {
    load_dir($options['d'], $options);
} else if ($options['f']) {
    load_file($options['f'], $options);
}

// filter out test framework functions by diffing functions before and after loading test files
$just_test_functions = array_filter(get_defined_functions(true)['user'], function($fn_name) use ($funcs1) { return !in_array($fn_name, $funcs1['user']); });

// display functions with userspace override
$success_display_fn = (function_exists("user_format_test_success")) ? "user_format_test_success" : "\\TinyTest\\format_test_success";
$error_display_fn = (function_exists("user_format_assertion_error")) ? "user_format_assertion_error" : "\\TinyTest\\format_assertion_error";
$is_test_fn = (function_exists("user_is_test_function")) ? "user_is_test_function" : "TinyTest\is_test_function";

// run the test (remove pass by ref)
function run_test(string $test_function, array $test_data, string &$dataset_name = "", &$final_error = null) : string {
    $result = "";
    if (isset($test_data['dataprovider'])) {
        foreach (call_user_func($test_data['dataprovider']) as $dataset_name => $value) {
			try {
                if ($value === "" || $value === null) { continue; }
            	$result .= $test_function($value);
        	} catch (\Error | \Exception $ex) {
				$final_error = $ex;
				$result .= "failed [$dataset_name] ";
				$result .= (is_string($value)) ? "[$value]\n" : "\n";
			}
		}

    } else {
        $result = $test_function();
    }
    return ($result == null) ? "" : $result;
}


// TODO: simplify...
function get_error_log(array $errorconfig, array $options) {
    $data = null;
    
    $verbose_out = "";
    if (file_exists(("/tmp/tinytest"))) {
        $lines = file("/tmp/tinytest");
        foreach ($lines as $line) {
            if (count($errorconfig) > 0) {
                foreach ($errorconfig as $config) {
                    $type_name = explode(":", $config);
                    //if (stristr($line, $type_name[0]) && stristr($line, $type_name[1])) { echo "$line"; continue; }
                    if (stristr($line, $type_name[0]) && stristr($line, $type_name[1])) { $verbose_out .= $line; continue; }
                    return new \Error($line);
                }
            } else {
                return new \Error($line);
            }
        }
    }

    if (verbose($options)) { echo $verbose_out; }
    return null;
}

function output_profile(array $data, string $func_name) {
    $all_funcs = array();
    foreach(array_keys($data) as $key) {
        $t = explode("==>", $key);
        if (count($t) == 2) {
            $all_funcs[] = $t[0];
            $all_funcs[] = $t[1]; 
        }
    }
    $funcs = array_unique($all_funcs);
    //print_r($funcs);
    //die();
    //print_r($funcs);
    foreach ($funcs as $fn) {
        if (preg_match('#@\d+$#', $fn)) {
            unset($funcs[$fn]);
            continue;
        }
        try {
            if (strpos($fn, '::') !== false) {
                list($c, $f) = explode('::', $fn, 2);
                $o = new \ReflectionMethod($c, $f);
            } else {
                $o = new \ReflectionFunction($fn);
            }
        } catch (\ReflectionException $e) {
            unset($funcs[$fn]);
            continue;
        }
        $file = $o->getFileName();
        $line = $o->getStartLine();
        /*if (!empty($CFG->dirroot)) {
            $file = str_replace($CFG->dirroot, '', $file);
        }
        */
        if (!empty($file) && !empty($line)) {
            $funcs[$fn] = array('line' => $line, 'file' => $file);
        }
    }
    $funcs['main()'] = array('line' => 0, 'file' => $func_name);
    file_put_contents("$func_name.xhprof.out", json_encode($data, JSON_PRETTY_PRINT));
    file_put_contents("$func_name.xhprof.out.map", json_encode($funcs, JSON_PRETTY_PRINT));
}

// a bit ugly
// loop over all user included functions
$coverage = array();
do_for_all($just_test_functions, function($function_name) use (&$coverage, $options, $is_test_fn) {

    $data_set_name = "";
	$collected = gc_collect_cycles();
    // exclude functions that don't match test name signature
    if (!$is_test_fn($function_name, $options)) { return; }
    // read the test annotations, exclude test based on types
    $test_data = read_test_data($function_name);
    if (is_excluded_test($test_data, $options)) { return; }

    // display the test we are running
    $format_test_fn = (function_exists("user_format_test_run")) ? "user_format_test_run" : "\\TinyTest\\format_test_run";
    echo $format_test_fn($function_name, $test_data, $options);

    if ($options['l']) { return; }

    $error = $result = $t0 = $t1 = null;
    $pre_test_assert_count = $GLOBALS['assert_count'];
    try {
        // turn on output buffer and start the operation log for code coverage reporting
        ob_start();
        if ($options['c']) { \phpdbg_start_oplog(); }
        // run the test
        if ($options['p']) { \tideways_enable(TIDEWAYS_FLAGS_MEMORY | TIDEWAYS_FLAGS_CPU); }
        $t0 = microtime(true);
        $result = run_test($function_name, $test_data, $data_set_name, $error);
    }
    // test failures and developer test errors
	// TODO: add expected assertion errors
    catch (\Error $ex) {
        $error = $ex;
    } // test generated an exception
    catch (\Exception $ex) {
        // if it was not an expected exception, test failure
        if (array_reduce($test_data['exception'], is_equal_reduced(get_class($ex)), false) === false) {
            count_assertion_fail();
            $error = new TestError("unexpected exception", get_class($ex), join(', ', $test_data['exception']), $ex);
        }
    }
    // display the result
    finally  {
        $t1 = microtime(true);
        if ($options['p']) { output_profile(tideways_disable(), $function_name); }
        $test_data['error'] = ($error == null) ? get_error_log($test_data['phperror'], $options) : $error;
        $test_data['result'] = (not_quiet($options)) ? ob_get_contents() . $result : "QUIET";
        ob_end_clean();
        if ($error == null) {
            $test_data['status'] = "OK";
            if ($GLOBALS['assert_count'] === $pre_test_assert_count) {
                count_assertion_fail();
                $test_data['status'] = "IN";
            }
            $success_display_fn = (function_exists("user_format_test_success")) ? "user_format_test_success" : "\\TinyTest\\format_test_success";
            echo $success_display_fn($test_data, $options, $t0, $t1);
        } else {
            if ($data_set_name !== "") { $result .= "\nfailed on dataset member [$data_set_name]\n"; }
            $error_display_fn = (function_exists("user_format_assertion_error")) ? "user_format_assertion_error" : "\\TinyTest\\format_assertion_error";
            echo $error_display_fn($test_data, $options, $t0 , $t1);
        }
    }

    // combine the oplogs...
    if ($options['c']) {
        $oplog = \phpdbg_end_oplog();
        $coverage = combine_oplog($coverage, $oplog, $options);
    }

});

if (count($coverage) > 0) {
    //print_r($coverage);
    echo "\ngenerating lcov.info...\n";
    file_put_contents("lcov.info", coverage_to_lcov($coverage, $options));
}

// display the test results
$m1=microtime(true);
echo "\n".$GLOBALS['assert_count'] . " tests, " . $GLOBALS['assert_pass_count'] . " passed, " . $GLOBALS['assert_fail_count'] . " failures/exceptions, using " . number_format(memory_get_peak_usage(true)/1024) . "KB in ".round($m1-$m0, 6)." seconds";
}