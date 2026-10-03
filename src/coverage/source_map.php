<?php declare(strict_types=1);
namespace TinyTest;

function combine_oplog(array $cov, array $newdata, array $options): array
{

    // remove unit test files from oplog data
    $remove_element = function ($item) use (&$newdata) {
        unset($newdata[$item]);
    };
    do_for_allkey($newdata, if_then_do(is_contain($options['d'] ?? $options['f']), $remove_element));

    // remove tinytest framework files from coverage data (unless @covers overrides)
    if (empty($GLOBALS['_tinytest_covers'])) {
        $tinytest_dir = framework_root() . DIRECTORY_SEPARATOR;
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
    $funcs = get_defined_functions();
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

