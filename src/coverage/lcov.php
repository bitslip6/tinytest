<?php declare(strict_types=1);
namespace TinyTest;

const HIT_MISS = 999999999;
// Legacy coverage helpers expose this sentinel globally.
if (!defined('HIT_MISS')) { define('HIT_MISS', HIT_MISS); }

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
