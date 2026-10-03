<?php declare(strict_types=1);
namespace TinyTest;

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




function show_usage()
{
    echo " -h, --help     show help without loading project code\n";
    echo " --allow-empty  allow an empty discovery result (not an unmatched -t selector)\n";
    echo " -d <directory> " . GREY . "load all tests in directory\n" . NORML;
    echo " -f <file>      " . GREY . "load all tests in file (supports multiple -f)\n" . NORML;
    echo " -t <test_name> " . GREY . "run just the test named test_name\n" . NORML;
    echo " -i <test_type> " . GREY . "only include tests of type <test_type> support multiple -i\n" . NORML;
    echo " -e <test_type> " . GREY . "exclude tests of type <test_type> support multiple -e\n" . NORML;
    echo " -b <bootstrap> " . GREY . "include a bootstrap file before running tests\n" . NORML;
    echo " -a " . GREY . "            auto load a bootstrap file in test directory\n" . NORML;
    echo " -c " . GREY . "            include code coverage information (generate lcov.info)\n" . NORML;
    echo " -q " . GREY . "            hide test console output (up to 3x -q -q -q)\n" . NORML;
    echo " -x " . GREY . "            show failing and incomplete tests only\n" . NORML;
    echo " -m " . GREY . "            set monochrome console output\n" . NORML;
    echo " -v " . GREY . "            set verbose output (stack traces)\n" . NORML;
    echo " -s " . GREY . "            squelch php error reporting\n" . NORML;
    echo " -r " . GREY . "            display code coverage totals (assumes -c)\n" . NORML;
    echo " -p " . GREY . "            save xhprof profiling tideways or xhprof profilers\n" . NORML;
    echo " -k " . GREY . "            save callgrind profiling data for cachegrind profilers\n" . NORML;
    echo " -n " . GREY . "            skip profile data for functions with low overhead\n" . NORML;
    echo " -w " . GREY . "            use wall time for callgrind output (default cpu)\n" . NORML;
    echo " -l " . GREY . "            just list tests, don't run\n" . NORML;
    echo " -j " . GREY . "            output results as JSON (also --json; requires proc_open)\n" . NORML;
}


/** BEGIN CODE COVERAGE FUNCTIONS */
