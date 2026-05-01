<?php

declare(strict_types=1);

namespace TinyTest {
    // #!phpdbg -r -e

    use Error;
    use Throwable;

    const VER = "11";
    define('TinyTest\ERR_OUT', tempnam(sys_get_temp_dir(), 'tinytest_'));
    const COVERAGE = 'c';
    const TEST_FN = 't';
    const SHOW_COVERAGE = 'r';
    const ASSERT_CNT = 'assert_count';
    define('YEARAGO', time() - 86400 * 365);
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }


    /** BEGIN USER EDITABLE FUNCTIONS, override in user_defined.php and prefix with "user_" */
    // test if a file is a test file, this should match your test filename format
    // $options command line options
    // $filename is the file to test
    function is_test_file(string $filename, ?array $options = null): bool
    {
        return (starts_with($filename, "test_") && ends_with($filename, "php"));
    }

    // test if a function is a valid test function also limits testing to a single function
    // $funcname - function name to test
    // $options - command line options
    function is_test_function(string $funcname, array $options): bool
    {
        if (isset($options[TEST_FN])) {
            return $funcname == $options[TEST_FN];
        }
        return (substr($funcname, 0, 5) === "test_" ||
            substr($funcname, 0, 3) === "it_" ||
            substr($funcname, 0, 7) === "should_");
    }

    // format test success
    function format_test_success(array $test_data, array $options, float $time): string
    {
        $out = ($test_data['status'] == "OK") ? GREEN : YELLOW;
        if (little_quiet($options)) {
            $out .= sprintf("%-3s%s in %s", $test_data['status'], NORML, number_format($time, 5));
        } else if (very_quiet($options)) {
            $out .= "." . NORML;
        }
        return $out . display_test_output($test_data['result'], $options);
    }

    // display the test returned string output
    function display_test_output(?string $result, array $options)
    {
        return ($result != null && not_quiet($options)) ?
            GREY . substr(str_replace("\n", "\n  -> ", "\n" . rtrim($result)), 1) . "\n" . NORML :
            "";
    }

    // format the test running. only return data if 0 or 1 -q options
    function format_test_run(string $test_name, array $test_data, array $options): string
    {
        $tmp = explode(DIRECTORY_SEPARATOR, $test_data['file']);
        $file = end($tmp);
        $file = substr($file, -32);
        return (little_quiet($options)) ? sprintf("\n%s%-32s :%s%-16s/%s%-42s%s ", CYAN, $file, GREY, $test_data['type'], BLUE_BR, $test_name, NORML) : '';
    }

    // format test failures , simplify?
    function format_assertion_error(array $test_data, array $options, float $time)
    {
        $out = "";
        $ex = $test_data['error'];
        if (little_quiet($options) && $ex !== null) {
            $out .= sprintf("%s%-3s%s in %s\n", RED, "err", NORML, number_format($time, 5));
            $out .= YELLOW . "  " . $ex->getFile() . NORML . ":" . $ex->getLine() . "";
        }
        if (not_quiet($options)) {
            $out .= LRED . "  " . $ex->getMessage() . NORML . "";
        }
        if (very_quiet($options)) {
            $out = "E";
        }
        if (full_quiet($options)) {
            $out = "";
        }
        if (isset($options['v'])) {
            $out .= GREY . $ex->getTraceAsString() . NORML . "";
        }
        return $out . display_test_output($test_data['result'], $options);
    }
    /** END USER EDITABLE FUNCTIONS */
// assertion functions located in assertion.php



    /** internal helper functions */
    // TODO bind options array to global option helpers...
    function dbg($x)
    {
        print_r($x);
        die();
    }
    function partial(callable $fn, ...$args): callable
    {
        return function (...$x) use ($fn, $args) {
            return $fn(...array_merge($args, $x));
        };
    }
    function not_quiet(array $options): bool
    {
        return $options['q'] == 0;
    }
    function little_quiet(array $options): bool
    {
        return $options['q'] <= 1;
    }
    function very_quiet(array $options): bool
    {
        return $options['q'] == 2;
    }
    function full_quiet(array $options): bool
    {
        return $options['q'] >= 3;
    }
    function verbose(array $options): bool
    {
        return isset($options['v']);
    }
    // errors-only mode: hide passing/skipped/todo/incomplete/ambiguous output, show only failures
    function errors_only(array $options): bool
    {
        return isset($options['x']) && $options['x'] === true;
    }
    function count_assertion()
    {
        $GLOBALS[ASSERT_CNT]++;
    }
    function count_assertion_pass()
    {
        count_assertion();
        $GLOBALS['assert_pass_count']++;
    }
    function count_assertion_fail()
    {
        count_assertion();
        $GLOBALS['assert_fail_count']++;
    }
    function panic_if(bool $result, string $msg)
    {
        if ($result) {
            die($msg);
        }
    }
    function warn_ifnot(bool $result, string $msg)
    {
        if (!$result) {
            printf("%s%s%s\n", YELLOW, $msg, NORML);
        }
    }
    function between(int $data, int $min, int $max)
    {
        return $data >= $min && $data <= $max;
    }
    function do_for_all(array $data, callable $fn)
    {
        foreach ($data as $item) {
            $fn($item);
        }
    }
    function do_for_allkey(array $data, callable $fn)
    {
        foreach ($data as $key => $item) {
            $fn($key);
        }
    }
    function do_for_all_key_value(array $data, callable $fn)
    {
        foreach ($data as $key => $item) {
            $fn($key, $item);
        }
    }
    function do_for_all_key_value_recursive(array $data, callable $fn)
    {
        foreach ($data as $key => $items) {
            foreach ($items as $item) {
                $fn($key, $item);
            }
        }
    }
    function array_map_assoc(callable $f, array $a)
    {
        return array_column(array_map($f, array_keys($a), $a), 1, 0);
    }
    function if_then_do($testfn, $action, $optionals = null): callable
    {
        return function ($argument) use ($testfn, $action, $optionals) {
            if ($argument && $testfn($argument, $optionals)) {
                $action($argument);
            }
        };
    }
    function is_equal_reduced($value): callable
    {
        return function ($initial, $argument) use ($value) {
            return ($initial || $argument === $value);
        };
    }
    function is_contain($value): callable
    {
        return function ($argument) use ($value) {
            return (strstr($argument, $value) !== false);
        };
    }
    function starts_with(string $haystack, string $needle)
    {
        return (substr($haystack, 0, strlen($needle)) === $needle);
    }
    function ends_with(string $haystack, string $needle)
    {
        return (substr($haystack, -strlen($needle)) === $needle);
    }
    function strip_ansi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
    function say($color = '\033[39m', $prefix = ""): callable
    {
        return function ($line) use ($color, $prefix): string {
            return (strlen($line) > 0) ? "{$color}{$prefix}{$line}" . NORML . "\n" : "";
        };
    }
    function last_element(array $items, $default = "")
    {
        return (count($items) > 0) ? array_slice($items, -1, 1)[0] : $default;
    }
    function nth_element(array $items, int $index, $default = "")
    {
        return (count($items) > 0) ? array_slice($items, $index, 1)[0] : $default;
    }
    function line_at_a_time(string $filename): iterable
    {
        $r = fopen($filename, 'r');
        $i = 0;
        while (($line = fgets($r)) !== false) {
            $i++;
            yield "line $i" => trim($line);
        }
    }
    function get_mtime(string $filename): string
    {
        $st = stat($filename);
        $m = $st['mtime'];
        return date(($m < YEARAGO) ? "M o" : "M j", $m);
    }
    function all_match(array $data, callable $fn, bool $match = true): bool
    {
        foreach ($data as $elm) {
            if ($fn($elm) !== $match) {
                return !$match;
            }
        }
        return $match;
    }
    function any_match(array $data, callable $fn): bool
    {
        return all_match($data, $fn, false);
    }
    function fatals()
    {
        echo "\n";
        if (file_exists(ERR_OUT)) {
            fwrite(STDERR, file_get_contents(ERR_OUT));
        }
    }


    // initialize the system
    function init(array $options): array
    {
        // global state (yuck)
        $GLOBALS['m0'] = microtime(true);
        $GLOBALS[ASSERT_CNT] = $GLOBALS['assert_pass_count'] = $GLOBALS['assert_fail_count'] = 0;

        // define console colors
        define("ESC", "\033");
        $d = array("RED" => 0, "LRED" => 0, "CYAN" => 0, "GREEN" => 0, "BLUE" => 0, "GREY" => 0, "YELLOW" => 0, "UNDERLINE" => 0, "NORML" => 0);
        if (!isset($options['m'])) {
            $d = array("RED" => 31, "LRED" => 91, "CYAN" => 36, "GREEN" => 32, "BLUE" => 34, "GREY" => 90, "YELLOW" => 33, "UNDERLINE" => "4:3", "NORML" => 0);
        }
        do_for_allkey($d, function ($name) use ($d) {
            define($name, ESC . "[" . $d[$name] . "m");
            define("{$name}_BR", ESC . "[" . $d[$name] . ";1m");
        });

        // program info
        if (!($options['j'] ?? false)) {
            echo __FILE__ . CYAN . " Ver " . VER . NORML . "\n";
        }

        // include test assertions
        require __DIR__ . "/assertions.php";
        include_once __DIR__ . "/user_defined.php";
        $cwd_user = getcwd() . "/user_defined.php";
        if (file_exists($cwd_user) && realpath($cwd_user) !== realpath(__DIR__ . "/user_defined.php")) {
            include_once $cwd_user;
        }

        // usage help
        if ((!isset($options['d']) && !isset($options['f'])) || isset($options['h']) || isset($options['?'])) {
            show_usage();
            exit(0);
        }
        // set assertion state
        ini_set("assert.exception", "1");

        // squelch error reporting if requested
        error_reporting($options['s'] ? 0 : E_ALL);
        @unlink(ERR_OUT);
        ini_set("error_log", ERR_OUT);
        gc_enable();

        // trying to read error log fails in shutdown fails if we are monitoring code coverage...
        if (!$options[COVERAGE]) {
            register_shutdown_function("TinyTest\\fatals");
        } else {
            ini_set('memory_limit', '1024M');
        }
        register_shutdown_function(function () {
            @unlink(ERR_OUT);
        });

        return $options;
    }

    // load a single unit test
    function load_file(string $file, array $options): void
    {
        assert(is_file($file), "test file [$file] does not exist");
        if (verbose($options) && !($options['j'] ?? false) && !errors_only($options)) {
            printf("loading test file: [%s%-45s%s]", CYAN, $file, NORML);
        }
        // collect @covers annotations before loading
        $covers = read_file_covers($file);
        if (!empty($covers)) {
            $base_dir = dirname(realpath($file));
            foreach ($covers as $path) {
                $resolved = realpath($base_dir . DIRECTORY_SEPARATOR . $path) ?: realpath($path);
                if ($resolved !== false) {
                    $GLOBALS['_tinytest_covers'][] = $resolved;
                }
            }
        }
        require_once "$file";
        if (verbose($options) && !($options['j'] ?? false) && !errors_only($options)) {
            echo GREEN_BR . "  OK\n" . NORML;
        }
    }

    // load all unit tests in a directory
    function load_dir(string $dir, array $options)
    {
        assert(is_dir($dir), "[$dir] is not a directory");
        $action = function ($item) use ($dir, $options) {
            load_file($dir . DIRECTORY_SEPARATOR . $item, $options);
        };
        $is_test_file_fn = (function_exists("\\user_is_test_file")) ? "\\user_is_test_file" : "\\TinyTest\\is_test_file";
        do_for_all(scandir($dir), if_then_do($is_test_file_fn, $action, $options));
    }

    // scan a test file for @covers annotations before the first function/class definition
    function read_file_covers(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }
        // take only the header — everything before first function/class/trait/interface/enum
        $header = preg_split('/^\s*(?:function |class |trait |interface |enum )/m', $contents)[0];
        preg_match_all('/@covers\s+(.+)/', $header, $matches);
        return array_filter(array_map(fn($p) => trim($p, " \t\n\r\0\x0B*/"), $matches[1]), 'strlen');
    }

    // check if this test should be excluded, returns false if test should run
    function is_excluded_test(array $test_data, array $options)
    {
        //print_r($options);
        if (isset($options['i']) && is_array($options['i']) && count($options['i']) > 0) {
            return !in_array($test_data['type'], $options['i']);
        }
        if (isset($options['e']) && is_array($options['e']) && count($options['e']) > 0) {
            return in_array($test_data['type'], $options['e']);
        }
        return false;
    }

    // read the test annotations, returns an array with all annotations
    function read_test_annotations(string $testname): array
    {
        $refFunc = new \ReflectionFunction($testname);
        $result = array('exception' => array(), 'test' => $testname, 'type' => 'standard', 'file' => $refFunc->getFileName(), 'line' => $refFunc->getStartLine(), 'error' => '', 'phperror' => array());
        $result['mtime'] = get_mtime($result['file']);
        $doc = $refFunc->getDocComment();
        if ($doc === false) {
            return $result;
        }

        $docs = explode("\n", $doc);
        array_walk($docs, function ($line) use (&$result) {
            $last = last_element(explode(" ", $line));
            if (preg_match("/\@(\w+)(.*)/", $line, $matches)) {
                if ($matches[1] === "exception") {
                    array_push($result['exception'], $last);
                } else if ($matches[1] === "phperror") {
                    array_push($result['phperror'], $matches[2]);
                } else if ($matches[1] === "skip" || $matches[1] === "todo" || $matches[1] === "ambiguous") {
                    $result[$matches[1]] = trim($matches[2]);
                } else {
                    $result[$matches[1]] = $last;
                }
            }
        });

        return $result;
    }

    // show the test runner usage
    function show_usage()
    {
        warn_ifnot(ini_get("zend.assertions") == 1, "zend.assertions are disabled. set zend.assertions in " . php_ini_loaded_file());
        echo " -d <directory> " . GREY . "load all tests in directory\n" . NORML;
        echo " -f <file>      " . GREY . "load all tests in file\n" . NORML;
        echo " -t <test_name> " . GREY . "run just the test named test_name\n" . NORML;
        echo " -i <test_type> " . GREY . "only include tests of type <test_type> support multiple -i\n" . NORML;
        echo " -e <test_type> " . GREY . "exclude tests of type <test_type> support multiple -e\n" . NORML;
        echo " -b <bootstrap> " . GREY . "include a bootstrap file before running tests\n" . NORML;
        echo " -a " . GREY . "            auto load a bootstrap file in test directory\n" . NORML;
        echo " -c " . GREY . "            include code coverage information (generate lcov.info)\n" . NORML;
        echo " -q " . GREY . "            hide test console output (up to 3x -q -q -q)\n" . NORML;
        echo " -x " . GREY . "            show only failing tests (hide passing/skip/todo/incomplete/ambiguous)\n" . NORML;
        echo " -m " . GREY . "            set monochrome console output\n" . NORML;
        echo " -v " . GREY . "            set verboise output (stack traces)\n" . NORML;
        echo " -s " . GREY . "            squelch php error reporting\n" . NORML;
        echo " -r " . GREY . "            display code coverage totals (assumes -c)\n" . NORML;
        echo " -p " . GREY . "            save xhprof profiling tideways or xhprof profilers\n" . NORML;
        echo " -k " . GREY . "            save callgrind profiling data for cachegrind profilers\n" . NORML;
        echo " -n " . GREY . "            skip profile data for functions with low overhead\n" . NORML;
        echo " -w " . GREY . "            use wall time for callgrind output (default cpu)\n" . NORML;
        echo " -l " . GREY . "            just list tests, don't run\n" . NORML;
        echo " -j " . GREY . "            output results as JSON\n" . NORML;
    }


    /** BEGIN CODE COVERAGE FUNCTIONS */
    // merge the oplog after every test adding all new counts to the overall count
    function combine_oplog(array $cov, array $newdata, array $options): array
    {

        // remove unit test files from oplog data
        $remove_element = function ($item) use (&$newdata) {
            unset($newdata[$item]);
        };
        do_for_allkey($newdata, if_then_do(is_contain($options['d'] ?? $options['f']), $remove_element));

        // remove tinytest framework files from coverage data (unless @covers overrides)
        if (empty($GLOBALS['_tinytest_covers'])) {
            $tinytest_dir = __DIR__ . DIRECTORY_SEPARATOR;
            foreach (array_keys($newdata) as $file) {
                if (strpos($file, $tinytest_dir) === 0) {
                    unset($newdata[$file]);
                }
            }
        }

        // a bit ugly...
        foreach ($newdata as $file => $lines) {
            if (isset($cov[$file])) {
                foreach ($lines as $line => $cnt1) {
                    $cov[$file][$line] = $cnt1 + ($cov[$file][$line] ?? 0);
                }
            } else {
                $cov[$file] = $lines;
            }
        }

        return $cov;
    }

    // return true only if token is valid with lineno, and is not whitespace or other crap
    function is_important_token($token): bool
    {
        return (!is_array($token) || in_array($token[0], array(T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_NS_SEPARATOR, T_DOC_COMMENT, T_INLINE_HTML))) ? false : true;
    }

    // return a new function definition
    function new_line_definition(int $lineno, string $name, string $type, int $end): array
    {
        return array("start" => $lineno, "type" => $type, "end" => $end, "name" => $name, "hit" => HIT_MISS);
    }

    // find the function, branch or statement at lineno for source_listing
    function find_index_lineno_between(array $source_listing, int $lineno, string $type): int
    {
        if (empty($source_listing)) {
            return -1;
        }
        for ($i = 0, $m = max(array_keys($source_listing)); $i < $m; $i++) {
            if (!isset($source_listing[$i])) {
                continue;
            } // skip empty items

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
    function format_output(string $type, array $def, int $hit)
    {
        static $brda_block = 0;
        switch ($type) {
            case "fn":
                return "FNDA:{$hit},{$def['name']}\n";
            case "da":
                return "DA:{$def['start']},$hit\n";
            case "brda":
                return "BRDA:{$def['start']}," . ($brda_block++) . ",0,$hit\n";
        }
    }

    // combine the source file mappings, with the covered lines and produce an lcov output
    function output_lcov(string $file, array $covered_lines, array $src_mapping, bool $showcoverage = false)
    {
        // loop over all covered lines and update hit counts
        do_for_all_key_value($covered_lines, function ($lineno, $cnt) use (&$src_mapping, $file) {
            // loop over all covered line types
            do_for_allkey($src_mapping, function ($src_type) use (&$index, &$src_mapping, $lineno, &$type, $cnt) {
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
        do_for_all_key_value_recursive($src_mapping, function ($type, $def) use (&$hits, &$outputs) {
            $hit = ($def['hit'] === HIT_MISS) ? 0 : $def['hit'];
            if ($hit > 0) {
                $hits[$type]++;
            }
            $outputs[$type] .= format_output($type, $def, $hit);
            // special case since functions have 2 outputs...
            if ($type == "fn") {
                $outputs["fnprefix"] .= "FN:{$def['start']},{$def['name']}\n";
            }
        });

        // update the lcov coverage totals
        $outputs['fn'] .= "FNF:" . count($src_mapping['fn']) . "\nFNH:{$hits['fn']}\n";
        $outputs['brda'] .= "BRF:" . count($src_mapping['brda']) . "\nBRH:{$hits['brda']}\n";
        $outputs['da'] .= "LF:" . count($src_mapping['da']) . "\nLH:{$hits['da']}\n";

        // collect covered and uncovered functions, excluding test functions
        $fn_details = ['covered' => [], 'uncovered' => []];
        foreach ($src_mapping['fn'] as $fn_def) {
            $name = $fn_def['name'];
            if (substr($name, 0, 5) === 'test_' || substr($name, 0, 3) === 'it_' || substr($name, 0, 7) === 'should_') {
                continue;
            }
            $hit = ($fn_def['hit'] === HIT_MISS) ? 0 : $fn_def['hit'];
            $key = $hit > 0 ? 'covered' : 'uncovered';
            $fn_details[$key][] = ['name' => $name, 'line' => $fn_def['start']];
        }

        $fn_count = count($src_mapping['fn']);

        // output to the console the coverage totals
        if ($showcoverage) {
            $da_count = count($src_mapping['da']);
            $brda_count = count($src_mapping['brda']);
            echo "$file " . GREEN . ($da_count > 0 ? round((intval($hits['da']) / $da_count) * 100) : 0) . " % " . NORML . "\n";
            echo "function coverage: {$hits['fn']}/" . $fn_count . "\n";
            echo "conditional coverage: {$hits['brda']}/" . $brda_count . "\n";
            echo "statement coverage: {$hits['da']}/" . $da_count . "\n";
            foreach ($fn_details['covered'] as $fn) {
                echo GREEN . "    {$fn['name']} : covered" . NORML . "\n";
            }
            foreach ($fn_details['uncovered'] as $fn) {
                echo YELLOW . "    {$fn['name']} : uncovered" . NORML . "\n";
            }
        }
        // return the combined outputs
        $lcov = array_reduce($outputs, function ($result, $item) {
            return $result . $item;
        }, "SF:$file\n") . "end_of_record\n";

        return [
            'lcov' => $lcov,
            'covered' => $fn_details['covered'],
            'uncovered' => $fn_details['uncovered'],
            'totals' => ['fn_total' => $fn_count, 'fn_covered' => $hits['fn']],
        ];
    }

    // take a mapping of file => array(tokens) and create a source mapping for function, branch, statement
    function make_source_map_from_tokens(array $tokens)
    {
        $funcs = get_defined_functions(false);
        $lcov = array();
        // token types that introduce a named block whose name should NOT be treated as a function
        $skip_name_tokens = array('T_NAMESPACE', 'T_CLASS', 'T_INTERFACE', 'T_TRAIT');
        if (defined('T_ENUM')) {
            $skip_name_tokens[] = 'T_ENUM';
        }
        // PHP type keywords that should never be treated as function names
        $type_hint_names = explode(' ', 'string int float bool array void null true false never mixed object callable iterable self static parent');

        // token types that represent executable statements (allowlist for DA entries)
        $executable_tokens = array(
            'T_ECHO',
            'T_PRINT',
            'T_RETURN',
            'T_YIELD',
            'T_THROW',
            'T_FOREACH',
            'T_FOR',
            'T_WHILE',
            'T_DO',
            'T_SWITCH',
            'T_CASE',
            'T_DEFAULT',
            'T_BREAK',
            'T_CONTINUE',
            'T_TRY',
            'T_CATCH',
            'T_FINALLY',
            'T_ELSE',
            'T_ELSEIF',
            'T_EXIT',
            'T_INCLUDE',
            'T_INCLUDE_ONCE',
            'T_REQUIRE',
            'T_REQUIRE_ONCE',
            'T_VARIABLE',
            'T_NEW',
            'T_CLONE',
            'T_UNSET',
            'T_EMPTY',
            'T_ISSET',
            'T_GLOBAL',
            'T_GOTO',
            'T_DECLARE',
            'T_CONST',
        );
        // add tokens that may not exist in older PHP versions
        foreach (array('T_FN', 'T_MATCH', 'T_YIELD_FROM') as $opt_token) {
            if (defined($opt_token)) {
                $executable_tokens[] = $opt_token;
            }
        }

        foreach ($tokens as $file => $tokens) {
            $lcov[$file] = array("fn" => array(), "da" => array(), "brda" => array());
            $expect_fn_name = false;  // true after we see T_FUNCTION (not in a use statement)
            $skip_next_name = false;  // true after namespace/class/interface/trait/enum
            $in_use = false;          // true after T_USE, cleared on semicolon or closing brace
            $fn_start_line = 0;       // line where T_FUNCTION was seen

            foreach ($tokens as $token) {
                // non-array tokens are single characters like ( ) { } ; , etc
                if (!is_array($token)) {
                    // if we were expecting a function name and hit '(' instead,
                    // this is an anonymous function / closure — cancel expectation
                    if ($expect_fn_name && $token === '(') {
                        $expect_fn_name = false;
                    }
                    // semicolon or closing brace ends a use statement
                    if ($in_use && ($token === ';' || $token === '}')) {
                        $in_use = false;
                    }
                    continue;
                }

                // skip whitespace and other tokens we don't care about
                if (!is_important_token($token)) {
                    continue;
                }

                $nm = token_name($token[0]);
                $src = $token[1];
                $lineno = $token[2];

                // "use" statements import names — skip everything until semicolon/brace
                if ($nm == "T_USE") {
                    $in_use = true;
                    continue;
                }
                // while inside a use statement, ignore all tokens (T_FUNCTION, T_STRING, etc)
                if ($in_use) {
                    continue;
                }

                if (in_array($nm, $skip_name_tokens)) {
                    // next T_STRING is a namespace/class/etc name, skip it
                    $skip_next_name = true;
                } else if ($nm == "T_FUNCTION") {
                    // close the previous function definition if any
                    if (count($lcov[$file]["fn"]) > 0) {
                        $last_idx = count($lcov[$file]["fn"]) - 1;
                        if ($lcov[$file]["fn"][$last_idx]['end'] === 999999) {
                            $lcov[$file]["fn"][$last_idx]['end'] = $lineno - 1;
                        }
                    }
                    $expect_fn_name = true;
                    $fn_start_line = $lineno;
                } else if ($nm == "T_STRING" && $expect_fn_name) {
                    // this T_STRING follows T_FUNCTION — it's the function/method name
                    $expect_fn_name = false;
                    // skip names that are actually type hints (shouldn't happen here
                    // since type hints come after params, but guard against edge cases)
                    if (!in_array(strtolower($src), $type_hint_names)) {
                        $fndef = new_line_definition($fn_start_line, $src, "fn", 999999);
                        array_push($lcov[$file]["fn"], $fndef);
                    }
                } else if ($nm == "T_STRING" && $skip_next_name) {
                    // this T_STRING is a namespace/class/interface/trait name — skip it
                    $skip_next_name = false;
                } else if ($nm == "T_STRING") {
                    // handle user and system function calls (not type hints)
                    if (
                        !in_array(strtolower($token[1]), $type_hint_names) &&
                        (in_array($token[1], $funcs['internal']) || in_array($token[1], $funcs['user']))
                    ) {
                        array_push($lcov[$file]["da"], new_line_definition($lineno, "S", "da", $lineno));
                    }
                } else if ($nm == "T_IF") {
                    array_push($lcov[$file]["brda"], new_line_definition($lineno, $src, "brda", $lineno));
                } else if (in_array($nm, $executable_tokens)) {
                    // only count executable statement tokens as DA entries
                    array_push($lcov[$file]["da"], new_line_definition($lineno, "E", "da", $lineno));
                }
            }

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

    function keep_fn($options): callable
    {
        $is_test_function_fn = function_exists("\\user_is_test_function") ? "\\user_is_test_function" : "\\TinyTest\\is_test_function";
        return function ($fn_name) use ($options, $is_test_function_fn): bool {
            return $is_test_function_fn($fn_name, $options);
        };
    }

    // take coverage data from oplog and convert to lcov file format
    // $covers_filter: array of resolved file paths from @covers annotations (empty = show all)
    function coverage_to_lcov(array $coverage, array $options, array $covers_filter = [])
    {

        // read in all source files and parse the php tokens
        $tokens = array();
        do_for_allkey($coverage, function ($file) use (&$tokens) {
            $contents = file_get_contents($file);
            if ($contents !== false) {
                $tokens[$file] = token_get_all($contents);
            }
        });

        // convert the tokens to a source map
        $src_map = make_source_map_from_tokens($tokens);
        $res = "";
        $covered = [];
        $uncovered = [];
        // combine the coverage output with the source map and produce an lcov output
        foreach ($src_map as $file => $mapping) {
            // when @covers is active, only show -r detail for covered files
            $show_this_file = $options[SHOW_COVERAGE] && (empty($covers_filter) || in_array($file, $covers_filter));
            $result = output_lcov($file, $coverage[$file], $mapping, $show_this_file);
            $res .= $result['lcov'];
            if (!empty($result['covered']) || !empty($result['uncovered'])) {
                $covered[$file] = [
                    'functions_total' => $result['totals']['fn_total'],
                    'functions_covered' => $result['totals']['fn_covered'],
                    'covered_functions' => $result['covered'],
                    'uncovered_functions' => $result['uncovered'],
                ];
            }
            if (!empty($result['uncovered'])) {
                $uncovered[$file] = [
                    'functions_total' => $result['totals']['fn_total'],
                    'functions_covered' => $result['totals']['fn_covered'],
                    'uncovered_functions' => $result['uncovered'],
                ];
            }
        }

        return ['lcov' => $res, 'coverage' => $covered, 'uncovered' => $uncovered];
    }
    /** END CODE COVERAGE FUNCTIONS */

    define("HIT_MISS", 999999999);
    // internal assert errors.  handle getting correct file and line number.  formatting for assertion error
    // todo: add user override callback for assertion error formatting
    class TestError extends \Error
    {
        public $test_data;
        //public function __construct(string $message, $actual, $expected, \Exception $ex = null) {
        public function __construct(string $message, $actual, $expected, ?\Throwable $ex = null)
        {
            $str_actual = is_object($actual) ? get_class($actual) . '(...)' : (is_array($actual) ? 'Array(' . count($actual) . ')' : (string)$actual);
            $str_expected = is_object($expected) ? get_class($expected) . '(...)' : (is_array($expected) ? 'Array(' . count($expected) . ')' : (string)$expected);
            $formatted_msg = sprintf("%sexpected [%s%s%s] got [%s%s%s] \"%s%s%s\"\n", NORML, GREEN, $str_expected, NORML, YELLOW, $str_actual, NORML, RED, $message, NORML);

            parent::__construct($formatted_msg, 0, $ex);
            if ($ex != null) {
                $this->line = $ex->getLine();
                $this->file = $ex->getFile();
            } else {
                $bt = nth_element(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3), 2);
                $this->line = $bt['line'];
                $this->file = $bt['file'];
                //$this->file = "BTF:".$bt['file'] . " L:".$bt['line'];
            }
        }
    }


    // coerce get_opt to something we like better...
    function parse_options(array $options): array
    {
        // count quiet setting
        $q = $options['q'] ?? array();
        $options['q'] = is_array($q) ? count($q) : 1;

        // print_r($options);
        // force inclusion to array type
        if (isset($options['i'])) {
            $options['i'] = array_filter((array)$options['i'], 'is_string');
        }
        if (isset($options['e'])) {
            $options['e'] = array_filter((array)$options['e'], 'is_string');
        }

        //print_r($options);
        /*
    $options['i'] = is_array($options['i']) ? $options['i'] : isset($options['i']) ? array($options['i']) : array();
    $options['e'] = is_array($options['e']) ? $options['e'] : isset($options['e']) ? array($options['e']) : array();
    if (count($options['i']) <= 0) { unset($options['i']); }
    if (count($options['e']) <= 0) { unset($options['e']); }
*/

        // load / autodetect test bootstrap file
        if (isset($options['a'])) {
            $d = isset($options['f']) ? dirname($options['f']) : $options['d'];
            $options['b'] = file_exists("$d/bootstrap.php") ? "$d/bootstrap.php" : $options['b'] ?? '';
        }
        //print_r($options);
        //die();
        if (isset($options['b']) && is_string($options['b']) && strlen($options['b']) > 1) {
            require $options['b'];
        }

        // php error squelching
        $options['s'] = isset($options['s']) ? true : false;
        $options['l'] = isset($options['l']) ? true : false;
        $options['p'] = isset($options['p']) ? true : false;
        $options['k'] = isset($options['k']) ? true : false;
        $options['n'] = isset($options['n']) ? true : false;
        $options['cost'] = isset($options['w']) ? 'wt' : 'cpu';
        $options['j'] = isset($options['j']) ? true : false;
        // errors-only: suppress passing/skipped/todo/incomplete/ambiguous output
        $options['x'] = isset($options['x']) ? true : false;
        // code coverage reporting
        $options[COVERAGE] = isset($options[COVERAGE]) ? true : false;
        $options[SHOW_COVERAGE] = isset($options[SHOW_COVERAGE]) ? true : false;
        if ($options[SHOW_COVERAGE]) {
            $options[COVERAGE] = true;
        }
        return $options;
    }

    /** MAIN ... */
    // process command line options
    $options = parse_options(getopt("b:d:f:t:i:e:pmnqchrvsalkwjx?"));
    $options = init($options);
    $options['cmd'] = join(' ', $argv);

    // get a list of all tinytest fucntion names
    $funcs1 = get_defined_functions(true);
    unset($funcs1['internal']);

    // initialize @covers collection
    $GLOBALS['_tinytest_covers'] = [];

    // load the unit test files
    if (isset($options['d'])) {
        load_dir($options['d'], $options);
    } else if ($options['f']) {
        load_file($options['f'], $options);
    }

    // filter out test framework functions by diffing functions before and after loading test files
    $just_test_functions = array_filter(get_defined_functions(true)['user'], function ($fn_name) use ($funcs1) {
        return !in_array($fn_name, $funcs1['user']);
    });

    // display functions with userspace override
    $is_test_fn = (function_exists("\\user_is_test_function")) ? "\\user_is_test_function" : "\\TinyTest\\is_test_function";

    class TestResult
    {
        public $error = null;
        public $pass = false;
        public $result = "";
        public $console = "";
        public function set_error(\Throwable $error)
        {
            $this->error = $error;
        }
        public function set_result(?string $output)
        {
            $this->result = $output;
        }
        public function set_console(?string $output)
        {
            $this->console = $output;
        }
        public function pass()
        {
            $this->pass = true;
        }
    }

    function do_test(callable $test_function, array $exceptions, ?string $dataset_name, $value, float $timeout = 0): TestResult
    {
        $result = new TestResult();
        // set up alarm-based timeout if pcntl is available (integer seconds only, for hard kill)
        $has_pcntl = $timeout >= 1 && function_exists('pcntl_alarm');
        if ($has_pcntl) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, function () use ($timeout) {
                throw new \RuntimeException("test timed out after {$timeout}s");
            });
            pcntl_alarm((int) ceil($timeout));
        }
        $t_start = $timeout > 0 ? microtime(true) : 0;
        try {
            ob_start();
            if ($value !== null) {
                $result->set_result(strval($test_function($value)));
            } else {
                $result->set_result(strval($test_function()));
            }
            $result->pass();
        } catch (Error $err) {
            $err->test_data = $dataset_name;
            $result->set_error($err);
        } catch (Throwable $ex) {
            if (array_reduce($exceptions, is_equal_reduced(get_class($ex)), false) === false) {
                count_assertion_fail();
                $err = new TestError("unexpected: (" . $ex->getMessage() . ") [$dataset_name] [$value]", get_class($ex), join(', ', $exceptions), $ex);
                $result->set_error($err);
            } else {
                $result->pass();
            }
        } finally {
            $out = ob_get_contents();
            $result->set_console($out ?: "");
            ob_end_clean();
            if ($has_pcntl) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, SIG_DFL);
            }
        }
        // fallback timeout check (always applies — pcntl_alarm only handles integer seconds)
        if ($timeout > 0 && $result->pass) {
            $elapsed = microtime(true) - $t_start;
            if ($elapsed > $timeout) {
                count_assertion_fail();
                $result->pass = false;
                $result->set_error(new TestError("test timed out after {$timeout}s (took " . number_format($elapsed, 3) . "s)", number_format($elapsed, 3) . "s", "{$timeout}s"));
            }
        }
        return $result;
    }

    // run the test (remove pass by ref)
    function run_test(callable $test_function, array $test_data): array
    {
        $timeout = isset($test_data['timeout']) ? (float) $test_data['timeout'] : 0;
        $results = array();
        if (isset($test_data['dataprovider'])) {
            foreach (call_user_func($test_data['dataprovider']) as $dataset_name => $value) {
                $result = do_test($test_function, $test_data['exception'], strval($dataset_name), $value, $timeout);
                $results[] = $result;
            }
        } else {
            $results[] = do_test($test_function, $test_data['exception'], null, null, $timeout);
        }

        return $results;
    }


    // TODO: simplify, maybe add an error handler and skip the error file...
    function get_error_log(array $errorconfig, array $options): ?\Error
    {
        $verbose_out = "";
        if (file_exists((ERR_OUT))) {
            $lines = file(ERR_OUT);
            @unlink(ERR_OUT);

            foreach ($lines as $line) {
                if (count($errorconfig) > 0) {
                    foreach ($errorconfig as $config) {
                        $type_name = explode(":", $config);
                        if (stripos($line, $type_name[0]) !== false && stripos($line, $type_name[1]) !== false) {
                            $verbose_out .= $line;
                            continue;
                        }
                        return new \Error($line);
                    }
                } else {
                    return new \Error($line);
                }
            }
        }

        if (verbose($options)) {
            echo $verbose_out;
        }
        return null;
    }

    // ugly but compatible with all versions of php
    function call_to_source(string $fn, array $x, array $options): array
    {
        $file = '<internal>';
        $line = -1;
        try {
            $o = null;
            if (strpos($fn, '::') !== false) {
                list($c, $f) = explode('::', $fn, 2);
                $o = new \ReflectionMethod($c, $f);
                $file = $o->getFileName();
                $line = $o->getStartLine();
            } else {
                $o = new \ReflectionFunction($fn);
                $file = $o->getFileName();
                if (!$file) {
                    $file = "<internal>";
                }
                $line = $o->getStartLine();
                if (!$line) {
                    $line = 0;
                }
            }
        } catch (\ReflectionException $e) {
            $file = '<internal>';
            $line = 0;
        }

        //$call['cost'] = $x[$options['cost']];
        //$call['count'] = $x['ct'];
        //echo "file $fn = [$file:$line]\n";
        return array('line' => $line, 'fn' => $fn, 'file' => $file, 'calls' => array(), 'count' => $x['ct'], 'cost' => $x[$options['cost']]);
    }

    /*

version: 1
  2 creator: xh2cg for xhprof
  3 cmd: Unknown PHP script
  4 part: 1
  5 positions: line
  6 events: Time
  7 summary: 748168
  */

    function output_profile(array $data, string $func_name, array $options)
    {
        if ($options['n']) {
            $data = array_filter($data, function ($elm) {
                return ($elm['ct'] > 2 || $elm['wt'] > 9 || $elm['cpu'] > 9);
            });
        }
        if ($options['p']) {
            return file_put_contents("$func_name.xhprof.json", json_encode($data, JSON_PRETTY_PRINT));
        }

        $pre  = "version: 1\ncreator: https://github.com/bitslip6/tinytest\ncmd: {$options['cmd']}\npart: 1\npositions: line\nevents: Time\nsummary: ";

        // remove internal functions
        $call_graph = array_filter($data, function ($k) {
            return (stripos($k, 'tinytest') !== false
                || stripos($k, 'assert_') !== false
            ) ? false : true;
        }, ARRAY_FILTER_USE_KEY);


        $fn_list = array();
        array_walk($call_graph, function ($x, $fn_name) use (&$fn_list, $func_name, $options) {
            $parts = explode('==>', $fn_name);
            if (!isset($fn_list[$parts[0]])) {
                $call = call_to_source($parts[0], $x, $options);
                $fn_list[$parts[0]] = $call;
            }
            if (count($parts) > 1) {
                $call = call_to_source($parts[1], $x, $options);
                $fn_list[$parts[0]]['calls'][] = $call;
            }
        });

        $out = "";
        $sum = 0;
        array_walk($fn_list, function ($x, $fn_name) use (&$out, &$sum) {
            $out .= sprintf("fl=%s\nfn=%s\n%d %d\n", $x['file'], $x['fn'], $x['line'], $x['cost']);
            //$sum += $x['cost'];
            foreach ($x['calls'] as $call) {
                $out .= sprintf("cfl=%s\ncfn=%s\ncalls=%d %d\n%d %d\n", $call['file'], $call['fn'], $call['count'], $call['line'], $x['line'], $call['cost']);
                $sum += $call['cost'];
            }
            $out .= "\n";
        });

        file_put_contents("callgrind.$func_name", $pre . $sum . "\n\n" . $out);
        return;
    }

    // a bit ugly
    // loop over all user included functions
    $coverage = array();
    $json_results = array();
    do_for_all($just_test_functions, function ($function_name) use (&$coverage, &$json_results, $options, $is_test_fn) {

        // exclude functions that don't match test name signature
        if (!$is_test_fn($function_name, $options)) {
            return;
        }
        // read the test annotations, exclude test based on types
        $test_data = read_test_annotations($function_name);
        if (is_excluded_test($test_data, $options)) {
            return;
        }

        // display the test we are running. In errors-only mode (-x), buffer the header and
        // flush it only if the test fails, so passing tests produce no output at all.
        $test_header = "";
        if (!$options['j']) {
            $format_test_fn = (function_exists("\\user_format_test_run")) ? "\\user_format_test_run" : "\\TinyTest\\format_test_run";
            $test_header = $format_test_fn($function_name, $test_data, $options);
            if (!errors_only($options)) {
                echo $test_header;
            }
        }

        // handle @skip and @todo annotations
        if (isset($test_data['skip']) || isset($test_data['todo'])) {
            $reason = isset($test_data['todo']) ? $test_data['todo'] : $test_data['skip'];
            $test_data['status'] = isset($test_data['todo']) ? 'TODO' : 'SKIP';
            $GLOBALS['assert_skip_count'] = ($GLOBALS['assert_skip_count'] ?? 0) + 1;
            if (!$options['j'] && !errors_only($options)) {
                $label = $test_data['status'];
                $out = CYAN . sprintf("%-4s", $label) . NORML;
                if ($reason !== '') {
                    $out .= GREY . " ($reason)" . NORML;
                }
                echo $out;
            }
            if ($options['j'] && !errors_only($options)) {
                $json_entry = [
                    'name' => $function_name,
                    'file' => $test_data['file'],
                    'status' => $test_data['status'],
                    'duration' => 0,
                    'assertions' => 0,
                ];
                if ($reason !== '') {
                    $json_entry['reason'] = $reason;
                }
                $json_results[] = $json_entry;
            }
            return;
        }

        // only list tests
        if ($options['l']) {
            if ($options['j']) {
                $json_results[] = [
                    'name' => $function_name,
                    'file' => $test_data['file'],
                    'type' => $test_data['type'],
                ];
            } else if (errors_only($options)) {
                // -x buffered the header; list mode has no pass/fail, so flush it here
                echo $test_header;
            }
            return;
        }
        $error = $result = $t0 = $t1 = null;
        $pre_test_assert_count = $GLOBALS[ASSERT_CNT];

        // turn on output buffer and start the operation log for code coverage reporting
        if ($options[COVERAGE]) {
            panic_if(!function_exists('phpdbg_start_oplog'), RED . "\ncode coverage only available in phpdbg -rre tinytest.php\n" . NORML);
            \phpdbg_start_oplog();
        }

        // run the test
        if ($options['p'] || $options['k']) {
            \tideways_enable(TIDEWAYS_FLAGS_MEMORY | TIDEWAYS_FLAGS_CPU);
        }
        $t0 = microtime(true);
        $results = run_test($function_name, $test_data);
        $t1 = microtime(true);
        if ($options['p'] || $options['k']) {
            output_profile(\tideways_disable(), $function_name, $options);
        }

        // combine the oplogs...
        if ($options[COVERAGE]) {
            $coverage = combine_oplog($coverage, \phpdbg_end_oplog(), $options);
        }


        // did the test pass?
        $passed = all_match($results, function (TestResult $result) {
            return $result->pass;
        });

        $test_data['result'] = array_reduce($results, function (string $out, TestResult $result) {
            return $out . $result->result;
        }, "");
        $console = array_reduce($results, function (string $out, TestResult $result) {
            return $out . $result->console;
        }, "");
        if (verbose($options) && $console !== "") {
            $test_data["result"] .= "\nconsole output:\n$console";
        }


        $test_data['error'] = (!$passed) ?
            array_reduce($results, function ($last_error, TestResult $result) {
                return (!$result->pass) ? $result->error : $last_error;
            }, null) :
            get_error_log($test_data['phperror'], $options);

        $duration = $t1 - $t0;
        $assertion_count = $GLOBALS[ASSERT_CNT] - $pre_test_assert_count;

        if ($passed) {
            $test_data['status'] = "OK";
            if ($GLOBALS[ASSERT_CNT] === $pre_test_assert_count) {
                count_assertion_fail();
                $test_data['status'] = "IN";
                $GLOBALS['assert_incomplete_count'] = ($GLOBALS['assert_incomplete_count'] ?? 0) + 1;
            }
            // in errors-only mode, OK and IN are both hidden (IN = incomplete, per user spec)
            if (!$options['j'] && !errors_only($options)) {
                $success_display_fn = (function_exists("\\user_format_test_success")) ? "\\user_format_test_success" : "\\TinyTest\\format_test_success";
                echo $success_display_fn($test_data, $options, $duration);
            }
        } else {
            if (!$options['j']) {
                // flush the buffered header first so the failure is attributable
                if (errors_only($options)) {
                    echo $test_header;
                }
                $error_display_fn = (function_exists("\\user_format_assertion_error")) ? "\\user_format_assertion_error" : "\\TinyTest\\format_assertion_error";
                echo $error_display_fn($test_data, $options, $duration);
            }
        }

        // track @ambiguous tests (test still runs, just flagged)
        if (isset($test_data['ambiguous'])) {
            $GLOBALS['assert_ambiguous_count'] = ($GLOBALS['assert_ambiguous_count'] ?? 0) + 1;
            if (!$options['j'] && !errors_only($options)) {
                $reason = $test_data['ambiguous'];
                echo YELLOW . " AMBG" . NORML . ($reason !== '' ? GREY . " ($reason)" . NORML : '');
            }
        }

        // collect JSON result (in errors-only mode, only include failing tests)
        if ($options['j'] && (!errors_only($options) || !$passed)) {
            $json_entry = [
                'name' => $function_name,
                'file' => $test_data['file'],
                'status' => $test_data['status'] ?? 'FAIL',
                'duration' => round($duration, 6),
                'assertions' => $assertion_count,
            ];
            if (isset($test_data['ambiguous'])) {
                $json_entry['ambiguous'] = true;
                if ($test_data['ambiguous'] !== '') {
                    $json_entry['ambiguous_reason'] = $test_data['ambiguous'];
                }
            }
            if (!$passed && $test_data['error'] !== null) {
                $ex = $test_data['error'];
                $json_entry['error'] = [
                    'message' => strip_ansi($ex->getMessage()),
                    'file' => $ex->getFile(),
                    'line' => $ex->getLine(),
                ];
            }
            $json_results[] = $json_entry;
        }

        gc_collect_cycles();
    });

    $uncovered_functions = [];
    $coverage_data = [];
    if (count($coverage) > 0) {
        if (!$options['j']) {
            echo "\ngenerating lcov.info...\n";
        }
        $covers_list = array_unique($GLOBALS['_tinytest_covers']);
        $cov_result = coverage_to_lcov($coverage, $options, $covers_list);
        file_put_contents("lcov.info", $cov_result['lcov']);
        $uncovered_functions = $cov_result['uncovered'];
        $coverage_data = $cov_result['coverage'];

        // if @covers annotations were found, filter coverage to only listed files
        if (!empty($covers_list)) {
            $filter_fn = fn($file) => in_array($file, $covers_list);
            $coverage_data = array_filter($coverage_data, $filter_fn, ARRAY_FILTER_USE_KEY);
            $uncovered_functions = array_filter($uncovered_functions, $filter_fn, ARRAY_FILTER_USE_KEY);
        }
    }

    @unlink(ERR_OUT);
    $m1 = microtime(true);

    if ($options['j']) {
        // JSON output mode
        $output = [
            'version' => (int) VER,
            'tests' => $json_results,
            'summary' => [
                'total' => (int) $GLOBALS[ASSERT_CNT],
                'passed' => (int) $GLOBALS['assert_pass_count'],
                'failed' => (int) $GLOBALS['assert_fail_count'],
                // use global counters (not $json_results) so counts stay accurate
                // when -x filters passing/skipped/todo/incomplete entries out of the array
                'incomplete' => (int) ($GLOBALS['assert_incomplete_count'] ?? 0),
                'skipped' => (int) ($GLOBALS['assert_skip_count'] ?? 0),
                'ambiguous' => (int) ($GLOBALS['assert_ambiguous_count'] ?? 0),
                'duration' => round($m1 - $GLOBALS['m0'], 6),
                'memory_kb' => (int) (memory_get_peak_usage(true) / 1024),
            ],
        ];
        if ($options[COVERAGE] && !empty($coverage_data)) {
            $output['coverage'] = $coverage_data;
        }
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        // display the test results
        $skip_count = $GLOBALS['assert_skip_count'] ?? 0;
        $skip_str = $skip_count > 0 ? ", $skip_count skipped" : "";
        $ambiguous_count = $GLOBALS['assert_ambiguous_count'] ?? 0;
        $ambig_str = $ambiguous_count > 0 ? ", $ambiguous_count ambiguous" : "";
        $cov_total = 0;
        $uncov_total = 0;
        foreach ($coverage_data as $file_data) {
            $cov_total += count($file_data['covered_functions']);
            $uncov_total += count($file_data['uncovered_functions']);
        }
        $fn_total = $cov_total + $uncov_total;
        $cov_str = $fn_total > 0 ? ", $cov_total/$fn_total functions covered" : "";
        $uncov_str = $uncov_total > 0 ? ", $uncov_total uncovered" : "";
        echo "\n" . NORML . $GLOBALS[ASSERT_CNT] . " tests, " . $GLOBALS['assert_pass_count'] . " passed, " . $GLOBALS['assert_fail_count'] . " failures/exceptions" . $skip_str . $ambig_str . $cov_str . $uncov_str . ", using " . number_format(memory_get_peak_usage(true) / 1024) . "KB in " . number_format($m1 - $GLOBALS['m0'], 5) . " seconds";
    }

    // run any registered cleanup callbacks (shutdown handlers don't fire under phpdbg)
    foreach ($GLOBALS['_tinytest_cleanup'] ?? [] as $cb) {
        $cb();
    }

    exit($GLOBALS['assert_fail_count'] > 0 ? 1 : 0);
}
