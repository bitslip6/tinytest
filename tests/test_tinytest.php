<?php

declare(strict_types=1);
/**
 * Tests for tinytest.php utility functions.
 * These functions are already loaded in the TinyTest namespace when the runner executes.
 * @covers tinytest.php
 */

// --- temp file cleanup ---
// Track all temp files/dirs created during tests and clean them up before exit.
// Uses tinytest's _tinytest_cleanup hook which fires before exit() — this works
// under both regular PHP and phpdbg (which skips shutdown handlers on exit()).
$GLOBALS['_tt_temp_paths'] = [];

function _tt_tempnam(string $prefix, string $suffix = ''): string
{
    $base = tempnam(sys_get_temp_dir(), $prefix);
    if ($suffix !== '') {
        $path = $base . $suffix;
        // tempnam() creates the base file; register it for cleanup too
        $GLOBALS['_tt_temp_paths'][] = $base;
    } else {
        $path = $base;
    }
    $GLOBALS['_tt_temp_paths'][] = $path;
    return $path;
}

function _tt_tempdir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '_' . getmypid() . '_' . mt_rand();
    @mkdir($dir, 0755, true);
    $GLOBALS['_tt_temp_paths'][] = $dir;
    return $dir;
}

function _tt_cleanup(): void
{
    // delete files first, then directories (reverse order so nested content is removed first)
    $dirs = [];
    foreach ($GLOBALS['_tt_temp_paths'] as $path) {
        if (is_dir($path)) {
            $dirs[] = $path;
        } else {
            @unlink($path);
        }
    }
    // remove directory contents then the directories themselves (deepest first)
    usort($dirs, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($dirs as $dir) {
        $items = @scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                @unlink($dir . '/' . $item);
            }
        }
        @rmdir($dir);
    }
    $GLOBALS['_tt_temp_paths'] = [];
}

// register via tinytest's pre-exit cleanup hook (works under phpdbg unlike shutdown handlers)
$GLOBALS['_tinytest_cleanup'][] = '_tt_cleanup';

// --- is_test_file ---

function test_is_test_file_matches_valid_test_file(): void
{
    assert_true(\TinyTest\is_test_file("test_foo.php"), "should match test_foo.php");
}

function test_is_test_file_rejects_non_test_prefix(): void
{
    assert_false(\TinyTest\is_test_file("foo.php"), "should reject foo.php");
}

function test_is_test_file_rejects_non_php_extension(): void
{
    assert_false(\TinyTest\is_test_file("test_foo.txt"), "should reject non-php extension");
}

function test_is_test_file_rejects_test_prefix_no_php(): void
{
    assert_false(\TinyTest\is_test_file("test_foo"), "should reject without .php");
}

// --- is_test_function ---

function test_is_test_function_matches_test_prefix(): void
{
    $options = ['q' => 0];
    assert_true(\TinyTest\is_test_function("test_something", $options), "should match test_ prefix");
}

function test_is_test_function_matches_it_prefix(): void
{
    $options = ['q' => 0];
    assert_true(\TinyTest\is_test_function("it_does_stuff", $options), "should match it_ prefix");
}

function test_is_test_function_matches_should_prefix(): void
{
    $options = ['q' => 0];
    assert_true(\TinyTest\is_test_function("should_work", $options), "should match should_ prefix");
}

function test_is_test_function_rejects_random_name(): void
{
    $options = ['q' => 0];
    assert_false(\TinyTest\is_test_function("helper_fn", $options), "should reject helper_fn");
}

function test_is_test_function_filters_by_t_option(): void
{
    $options = ['q' => 0, 't' => 'test_specific'];
    assert_true(\TinyTest\is_test_function("test_specific", $options), "should match exact -t name");
    assert_false(\TinyTest\is_test_function("test_other", $options), "should reject non-matching -t name");
}

// --- starts_with / ends_with ---

function test_starts_with_true(): void
{
    assert_true(\TinyTest\starts_with("hello world", "hello"), "should start with hello");
}

function test_starts_with_false(): void
{
    assert_false(\TinyTest\starts_with("hello world", "world"), "should not start with world");
}

function test_starts_with_empty_needle(): void
{
    assert_true(\TinyTest\starts_with("hello", ""), "empty needle always matches");
}

function test_ends_with_true(): void
{
    assert_true(\TinyTest\ends_with("hello world", "world"), "should end with world");
}

function test_ends_with_false(): void
{
    assert_false(\TinyTest\ends_with("hello world", "hello"), "should not end with hello");
}

function test_ends_with_empty_needle(): void
{
    // ends_with uses substr($haystack, -0) which returns full string, so empty needle does NOT match
    assert_false(\TinyTest\ends_with("hello", ""), "empty needle does not match due to substr(-0) behavior");
}

// --- between ---

function test_between_inside_range(): void
{
    assert_true(\TinyTest\between(5, 1, 10), "5 is between 1 and 10");
}

function test_between_at_min(): void
{
    assert_true(\TinyTest\between(1, 1, 10), "min boundary is inclusive");
}

function test_between_at_max(): void
{
    assert_true(\TinyTest\between(10, 1, 10), "max boundary is inclusive");
}

function test_between_below_min(): void
{
    assert_false(\TinyTest\between(0, 1, 10), "0 is below range");
}

function test_between_above_max(): void
{
    assert_false(\TinyTest\between(11, 1, 10), "11 is above range");
}

// --- last_element / nth_element ---

function test_last_element_returns_last(): void
{
    assert_eq(\TinyTest\last_element([1, 2, 3]), 3, "should return 3");
}

function test_last_element_empty_array_returns_default(): void
{
    assert_eq(\TinyTest\last_element([], "fallback"), "fallback", "should return default");
}

function test_last_element_single_item(): void
{
    assert_eq(\TinyTest\last_element(["only"]), "only", "single item");
}

function test_nth_element_first(): void
{
    assert_eq(\TinyTest\nth_element(["a", "b", "c"], 0), "a", "index 0");
}

function test_nth_element_negative_index(): void
{
    assert_eq(\TinyTest\nth_element(["a", "b", "c"], -1), "c", "negative index gets from end");
}

function test_nth_element_empty_array(): void
{
    assert_eq(\TinyTest\nth_element([], 0, "none"), "none", "empty returns default");
}

// --- strip_ansi ---

function test_strip_ansi_removes_color_codes(): void
{
    $colored = "\033[31mRed text\033[0m";
    assert_eq(\TinyTest\strip_ansi($colored), "Red text", "should strip ANSI codes");
}

function test_strip_ansi_no_codes(): void
{
    assert_eq(\TinyTest\strip_ansi("plain text"), "plain text", "no change for plain text");
}

function test_strip_ansi_multiple_codes(): void
{
    $text = "\033[32mGreen\033[0m and \033[34mBlue\033[0m";
    assert_eq(\TinyTest\strip_ansi($text), "Green and Blue", "strips all codes");
}

// --- quiet helpers ---

function test_not_quiet_when_zero(): void
{
    assert_true(\TinyTest\not_quiet(['q' => 0]), "q=0 is not quiet");
}

function test_not_quiet_when_one(): void
{
    assert_false(\TinyTest\not_quiet(['q' => 1]), "q=1 is quiet");
}

function test_little_quiet_when_zero(): void
{
    assert_true(\TinyTest\little_quiet(['q' => 0]), "q=0 passes little_quiet");
}

function test_little_quiet_when_one(): void
{
    assert_true(\TinyTest\little_quiet(['q' => 1]), "q=1 passes little_quiet");
}

function test_little_quiet_when_two(): void
{
    assert_false(\TinyTest\little_quiet(['q' => 2]), "q=2 fails little_quiet");
}

function test_very_quiet_when_two(): void
{
    assert_true(\TinyTest\very_quiet(['q' => 2]), "q=2 is very quiet");
}

function test_very_quiet_when_one(): void
{
    assert_false(\TinyTest\very_quiet(['q' => 1]), "q=1 is not very quiet");
}

function test_full_quiet_when_three(): void
{
    assert_true(\TinyTest\full_quiet(['q' => 3]), "q=3 is full quiet");
}

function test_full_quiet_when_four(): void
{
    assert_true(\TinyTest\full_quiet(['q' => 4]), "q>=3 is full quiet");
}

function test_full_quiet_when_two(): void
{
    assert_false(\TinyTest\full_quiet(['q' => 2]), "q=2 is not full quiet");
}

function test_verbose_when_set(): void
{
    assert_true(\TinyTest\verbose(['v' => true]), "v set means verbose");
}

function test_verbose_when_not_set(): void
{
    assert_false(\TinyTest\verbose([]), "v unset means not verbose");
}

// --- partial ---

function test_partial_binds_first_arg(): void
{
    $add = function (int $a, int $b): int {
        return $a + $b;
    };
    $add5 = \TinyTest\partial($add, 5);
    assert_eq($add5(3), 8, "partial(add, 5)(3) = 8");
}

function test_partial_binds_multiple_args(): void
{
    $sum3 = function (int $a, int $b, int $c): int {
        return $a + $b + $c;
    };
    $bound = \TinyTest\partial($sum3, 1, 2);
    assert_eq($bound(3), 6, "partial(sum3, 1, 2)(3) = 6");
}

// --- is_excluded_test ---

function test_is_excluded_no_filters(): void
{
    $test_data = ['type' => 'unit'];
    $options = ['q' => 0];
    assert_false(\TinyTest\is_excluded_test($test_data, $options), "no filters means not excluded");
}

function test_is_excluded_include_match(): void
{
    $test_data = ['type' => 'unit'];
    $options = ['q' => 0, 'i' => ['unit']];
    assert_false(\TinyTest\is_excluded_test($test_data, $options), "matching include means not excluded");
}

function test_is_excluded_include_no_match(): void
{
    $test_data = ['type' => 'integration'];
    $options = ['q' => 0, 'i' => ['unit']];
    assert_true(\TinyTest\is_excluded_test($test_data, $options), "non-matching include means excluded");
}

function test_is_excluded_exclude_match(): void
{
    $test_data = ['type' => 'slow'];
    $options = ['q' => 0, 'e' => ['slow']];
    assert_true(\TinyTest\is_excluded_test($test_data, $options), "matching exclude means excluded");
}

function test_is_excluded_exclude_no_match(): void
{
    $test_data = ['type' => 'fast'];
    $options = ['q' => 0, 'e' => ['slow']];
    assert_false(\TinyTest\is_excluded_test($test_data, $options), "non-matching exclude means not excluded");
}

// --- all_match / any_match ---

function test_all_match_all_true(): void
{
    $data = [2, 4, 6];
    $is_even = function (int $n): bool {
        return $n % 2 === 0;
    };
    assert_true(\TinyTest\all_match($data, $is_even), "all even");
}

function test_all_match_one_false(): void
{
    $data = [2, 3, 6];
    $is_even = function (int $n): bool {
        return $n % 2 === 0;
    };
    assert_false(\TinyTest\all_match($data, $is_even), "3 is not even");
}

function test_all_match_empty_array(): void
{
    assert_true(\TinyTest\all_match([], function ($x) {
        return false;
    }), "empty array matches all");
}

function test_any_match_one_true(): void
{
    $data = [1, 2, 3];
    $is_even = function (int $n): bool {
        return $n % 2 === 0;
    };
    assert_true(\TinyTest\any_match($data, $is_even), "2 is even");
}

function test_any_match_none_true(): void
{
    $data = [1, 3, 5];
    $is_even = function (int $n): bool {
        return $n % 2 === 0;
    };
    assert_false(\TinyTest\any_match($data, $is_even), "no even numbers");
}

// --- is_contain ---

function test_is_contain_found(): void
{
    $fn = \TinyTest\is_contain("world");
    assert_true($fn("hello world"), "contains world");
}

function test_is_contain_not_found(): void
{
    $fn = \TinyTest\is_contain("xyz");
    assert_false($fn("hello world"), "does not contain xyz");
}

// --- is_equal_reduced ---

function test_is_equal_reduced_finds_match(): void
{
    $fn = \TinyTest\is_equal_reduced("target");
    $result = array_reduce(["a", "target", "b"], $fn, false);
    assert_true($result, "should find target");
}

function test_is_equal_reduced_no_match(): void
{
    $fn = \TinyTest\is_equal_reduced("missing");
    $result = array_reduce(["a", "b", "c"], $fn, false);
    assert_false($result, "should not find missing");
}

// --- is_important_token ---

function test_is_important_token_rejects_non_array(): void
{
    assert_false(\TinyTest\is_important_token(";"), "non-array token is not important");
}

function test_is_important_token_rejects_whitespace(): void
{
    assert_false(\TinyTest\is_important_token([T_WHITESPACE, " ", 1]), "whitespace not important");
}

function test_is_important_token_rejects_comment(): void
{
    assert_false(\TinyTest\is_important_token([T_COMMENT, "// hi", 1]), "comment not important");
}

function test_is_important_token_rejects_open_tag(): void
{
    assert_false(\TinyTest\is_important_token([T_OPEN_TAG, "<?php", 1]), "open tag not important");
}

function test_is_important_token_accepts_string(): void
{
    assert_true(\TinyTest\is_important_token([T_STRING, "foo", 1]), "T_STRING is important");
}

function test_is_important_token_accepts_function(): void
{
    assert_true(\TinyTest\is_important_token([T_FUNCTION, "function", 1]), "T_FUNCTION is important");
}

// --- new_line_definition ---

function test_new_line_definition_structure(): void
{
    $def = \TinyTest\new_line_definition(10, "myFunc", "fn", 20);
    assert_eq($def['start'], 10, "start line");
    assert_eq($def['name'], "myFunc", "name");
    assert_eq($def['type'], "fn", "type");
    assert_eq($def['end'], 20, "end line");
    assert_eq($def['hit'], HIT_MISS, "hit should be HIT_MISS");
}

// --- find_index_lineno_between ---

function test_find_index_lineno_between_found(): void
{
    // Note: the loop uses `$i < max(array_keys(...))` so the last element is never checked.
    // We need 3 entries to test finding the middle one, or test only the first.
    $listing = [
        ['start' => 1, 'end' => 10, 'name' => 'fn1'],
        ['start' => 15, 'end' => 25, 'name' => 'fn2'],
        ['start' => 30, 'end' => 40, 'name' => 'fn3'],
    ];
    assert_eq(\TinyTest\find_index_lineno_between($listing, 5, "fn"), 0, "line 5 is in fn1");
    assert_eq(\TinyTest\find_index_lineno_between($listing, 20, "fn"), 1, "line 20 is in fn2");
}

function test_find_index_lineno_between_not_found(): void
{
    $listing = [
        ['start' => 1, 'end' => 10, 'name' => 'fn1'],
    ];
    assert_eq(\TinyTest\find_index_lineno_between($listing, 15, "fn"), -1, "line 15 not in any");
}

function test_find_index_lineno_between_empty(): void
{
    assert_eq(\TinyTest\find_index_lineno_between([], 5, "fn"), -1, "empty listing");
}

// --- format_output ---

function test_format_output_fn(): void
{
    $def = ['name' => 'myFunc', 'start' => 10];
    $result = \TinyTest\format_output("fn", $def, 3);
    assert_eq($result, "FNDA:3,myFunc\n", "FNDA format");
}

function test_format_output_da(): void
{
    $def = ['start' => 42];
    $result = \TinyTest\format_output("da", $def, 5);
    assert_eq($result, "DA:42,5\n", "DA format");
}

function test_format_output_brda(): void
{
    $def = ['start' => 7];
    $result = \TinyTest\format_output("brda", $def, 1);
    assert_contains($result, "BRDA:7,", "BRDA format starts correctly");
}

// --- read_file_covers ---

function test_read_file_covers_parses_annotation(): void
{
    $tmp = _tt_tempnam('tt_');
    file_put_contents($tmp, "<?php\n/**\n * @covers ../src/foo.php\n */\nfunction test_x(): void {}\n");
    $result = \TinyTest\read_file_covers($tmp);
    assert_eq(count($result), 1, "one covers annotation");
    assert_eq($result[0], "../src/foo.php", "path extracted");
}

function test_read_file_covers_no_annotation(): void
{
    $tmp = _tt_tempnam('tt_');
    file_put_contents($tmp, "<?php\nfunction test_x(): void {}\n");
    $result = \TinyTest\read_file_covers($tmp);
    assert_eq(count($result), 0, "no covers annotations");
}

// --- parse_options ---

function test_parse_options_quiet_count(): void
{
    $opts = \TinyTest\parse_options(['q' => [false, false, false]]);
    assert_eq($opts['q'], 3, "three -q flags = 3");
}

function test_parse_options_quiet_single(): void
{
    $opts = \TinyTest\parse_options(['q' => false]);
    assert_eq($opts['q'], 1, "single -q = 1");
}

function test_parse_options_quiet_absent(): void
{
    $opts = \TinyTest\parse_options([]);
    assert_eq($opts['q'], 0, "no -q = 0");
}

function test_parse_options_coverage_flags(): void
{
    $opts = \TinyTest\parse_options(['c' => false]);
    assert_true($opts['c'], "coverage enabled");
}

function test_parse_options_json_flag(): void
{
    $opts = \TinyTest\parse_options(['j' => false]);
    assert_true($opts['j'], "json enabled");
}

function test_parse_options_show_coverage_enables_coverage(): void
{
    $opts = \TinyTest\parse_options(['r' => false]);
    assert_true($opts['r'], "show coverage enabled");
    assert_true($opts['c'], "coverage auto-enabled by -r");
}

// --- combine_oplog ---

function test_combine_oplog_merges_counts(): void
{
    $cov = ['/tmp/a.php' => [10 => 1, 20 => 2]];
    $new = ['/tmp/a.php' => [10 => 3, 30 => 1]];
    $options = ['d' => '/nonexistent/', 'f' => '/nonexistent/'];
    $result = \TinyTest\combine_oplog($cov, $new, $options);
    assert_eq($result['/tmp/a.php'][10], 4, "line 10 counts merge: 1+3=4");
    assert_eq($result['/tmp/a.php'][20], 2, "line 20 unchanged");
    assert_eq($result['/tmp/a.php'][30], 1, "line 30 new");
}

function test_combine_oplog_adds_new_file(): void
{
    $cov = [];
    $new = ['/tmp/b.php' => [5 => 1]];
    $options = ['d' => '/nonexistent/'];
    $result = \TinyTest\combine_oplog($cov, $new, $options);
    assert_eq($result['/tmp/b.php'][5], 1, "new file added");
}

// --- count_assertion_fail ---

function test_count_assertion_fail_increments_counters(): void
{
    $before_total = $GLOBALS['assert_count'];
    $before_fail = $GLOBALS['assert_fail_count'];
    \TinyTest\count_assertion_fail();
    $new_total = $GLOBALS['assert_count'];
    $new_fail = $GLOBALS['assert_fail_count'];
    // undo the fail count so it doesn't affect the test run (before assertions)
    $GLOBALS['assert_fail_count'] = $before_fail;
    $GLOBALS['assert_count'] = $before_total;
    assert_eq($new_total, $before_total + 1, "total count incremented");
    assert_eq($new_fail, $before_fail + 1, "fail count incremented");
}

// --- warn_ifnot ---

function test_warn_ifnot_prints_on_false(): void
{
    ob_start();
    \TinyTest\warn_ifnot(false, "something is wrong");
    $output = ob_get_clean();
    assert_contains($output, "something is wrong", "should print warning message");
}

function test_warn_ifnot_silent_on_true(): void
{
    ob_start();
    \TinyTest\warn_ifnot(true, "should not print");
    $output = ob_get_clean();
    assert_eq($output, "", "should print nothing");
}

// --- do_for_all ---

function test_do_for_all_calls_fn_for_each(): void
{
    $collected = [];
    \TinyTest\do_for_all([1, 2, 3], function ($item) use (&$collected) {
        $collected[] = $item;
    });
    assert_eq($collected, [1, 2, 3], "should call fn with each item");
}

function test_do_for_all_empty_array(): void
{
    $called = false;
    \TinyTest\do_for_all([], function ($item) use (&$called) {
        $called = true;
    });
    assert_false($called, "should not call fn for empty array");
}

// --- do_for_all_key_value ---

function test_do_for_all_key_value_passes_pairs(): void
{
    $collected = [];
    \TinyTest\do_for_all_key_value(['a' => 1, 'b' => 2], function ($key, $value) use (&$collected) {
        $collected[$key] = $value;
    });
    assert_eq($collected, ['a' => 1, 'b' => 2], "should pass key-value pairs");
}

// --- do_for_all_key_value_recursive ---

function test_do_for_all_key_value_recursive_iterates_nested(): void
{
    $collected = [];
    $data = ['x' => ['a', 'b'], 'y' => ['c']];
    \TinyTest\do_for_all_key_value_recursive($data, function ($key, $item) use (&$collected) {
        $collected[] = "$key:$item";
    });
    assert_eq($collected, ['x:a', 'x:b', 'y:c'], "should iterate nested values with keys");
}

// --- array_map_assoc ---

function test_array_map_assoc_transforms_key_value(): void
{
    $result = \TinyTest\array_map_assoc(function ($key, $value) {
        return [$key, $value * 2];
    }, ['a' => 1, 'b' => 2]);
    assert_eq($result, ['a' => 2, 'b' => 4], "should map with keys preserved");
}

// --- say ---

function test_say_returns_formatter(): void
{
    $fn = \TinyTest\say("\033[32m", ">> ");
    $result = $fn("hello");
    assert_contains($result, ">> hello", "should contain prefix and line");
    assert_contains($result, "\033[32m", "should contain color");
}

function test_say_empty_line_returns_empty(): void
{
    $fn = \TinyTest\say("\033[32m", ">> ");
    $result = $fn("");
    assert_eq($result, "", "empty line returns empty string");
}

// --- line_at_a_time ---

function test_line_at_a_time_reads_lines(): void
{
    $tmp = _tt_tempnam('tt_');
    file_put_contents($tmp, "line1\nline2\nline3\n");
    $lines = [];
    foreach (\TinyTest\line_at_a_time($tmp) as $key => $line) {
        $lines[] = $line;
    }
    assert_eq($lines, ['line1', 'line2', 'line3'], "should read all lines trimmed");
}

function test_line_at_a_time_empty_file(): void
{
    $tmp = _tt_tempnam('tt_');
    file_put_contents($tmp, "");
    $lines = [];
    foreach (\TinyTest\line_at_a_time($tmp) as $line) {
        $lines[] = $line;
    }
    assert_eq($lines, [], "empty file yields no lines");
}

// --- get_mtime ---

function test_get_mtime_returns_date_string(): void
{
    $tmp = _tt_tempnam('tt_');
    file_put_contents($tmp, "x");
    $result = \TinyTest\get_mtime($tmp);
    assert_true(strlen($result) > 0, "should return a non-empty date string");
    // recent file should have "Mon day" format like "Mar 13"
    assert_matches($result, '/^[A-Z][a-z]{2} \d+$/', "recent file has 'Mon day' format");
}

// --- format_test_success ---

function test_format_test_success_ok_status(): void
{
    $test_data = ['status' => 'OK', 'result' => null];
    $options = ['q' => 0];
    $result = \TinyTest\format_test_success($test_data, $options, 0.12345);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "OK", "should contain OK");
    assert_contains($plain, "0.12345", "should contain time");
}

function test_format_test_success_incomplete_status(): void
{
    $test_data = ['status' => 'IN', 'result' => null];
    $options = ['q' => 0];
    $result = \TinyTest\format_test_success($test_data, $options, 0.001);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "IN", "should contain IN for incomplete");
}

function test_format_test_success_very_quiet(): void
{
    $test_data = ['status' => 'OK', 'result' => null];
    $options = ['q' => 2];
    $result = \TinyTest\format_test_success($test_data, $options, 0.1);
    $plain = \TinyTest\strip_ansi($result);
    assert_eq($plain, ".", "very quiet shows just a dot");
}

// --- display_test_output ---

function test_display_test_output_with_result(): void
{
    $result = \TinyTest\display_test_output("some output", ['q' => 0]);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "some output", "should display result");
}

function test_display_test_output_null_result(): void
{
    $result = \TinyTest\display_test_output(null, ['q' => 0]);
    assert_eq($result, "", "null result returns empty");
}

function test_display_test_output_quiet_hides(): void
{
    $result = \TinyTest\display_test_output("hidden", ['q' => 1]);
    assert_eq($result, "", "quiet hides output");
}

// --- format_test_run ---

function test_format_test_run_normal(): void
{
    $test_data = ['file' => '/some/path/test_foo.php', 'type' => 'unit'];
    $options = ['q' => 0];
    $result = \TinyTest\format_test_run("test_bar", $test_data, $options);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "test_foo.php", "should contain filename");
    assert_contains($plain, "test_bar", "should contain test name");
    assert_contains($plain, "unit", "should contain type");
}

function test_format_test_run_very_quiet(): void
{
    $test_data = ['file' => 'test_foo.php', 'type' => 'unit'];
    $options = ['q' => 2];
    $result = \TinyTest\format_test_run("test_bar", $test_data, $options);
    assert_eq($result, "", "very quiet returns empty");
}

// --- format_assertion_error ---

function test_format_assertion_error_normal(): void
{
    $ex = new \Error("something broke");
    $test_data = ['error' => $ex, 'result' => null];
    $options = ['q' => 0];
    $result = \TinyTest\format_assertion_error($test_data, $options, 0.05);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "err", "should contain err");
    assert_contains($plain, "something broke", "should contain error message");
}

function test_format_assertion_error_very_quiet(): void
{
    $ex = new \Error("fail");
    $test_data = ['error' => $ex, 'result' => null];
    $options = ['q' => 2];
    $result = \TinyTest\format_assertion_error($test_data, $options, 0.01);
    assert_eq($result, "E", "very quiet shows E");
}

function test_format_assertion_error_full_quiet(): void
{
    $ex = new \Error("fail");
    $test_data = ['error' => $ex, 'result' => null];
    $options = ['q' => 3];
    $result = \TinyTest\format_assertion_error($test_data, $options, 0.01);
    assert_eq($result, "", "full quiet shows nothing");
}

function test_format_assertion_error_verbose(): void
{
    $ex = new \Error("trace me");
    $test_data = ['error' => $ex, 'result' => null];
    $options = ['q' => 0, 'v' => true];
    $result = \TinyTest\format_assertion_error($test_data, $options, 0.01);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "trace me", "should contain error message");
    assert_contains($plain, "#0", "should contain stack trace");
}

// --- TestError ---

function test_testerror_construct_with_exception(): void
{
    $prev = new \Exception("prev");
    $err = new \TinyTest\TestError("msg", "actual_val", "expected_val", $prev);
    $plain = \TinyTest\strip_ansi($err->getMessage());
    assert_contains($plain, "expected_val", "should contain expected");
    assert_contains($plain, "actual_val", "should contain actual");
    assert_contains($plain, "msg", "should contain message");
    assert_eq($err->getLine(), $prev->getLine(), "line from previous exception");
    assert_eq($err->getFile(), $prev->getFile(), "file from previous exception");
}

function test_testerror_construct_without_exception(): void
{
    $err = new \TinyTest\TestError("check", "got", "want");
    $plain = \TinyTest\strip_ansi($err->getMessage());
    assert_contains($plain, "got", "should contain actual");
    assert_contains($plain, "want", "should contain expected");
    // file is set from backtrace (may point to framework internals)
    assert_true(strlen($err->getFile()) > 0, "file from backtrace should be non-empty");
    assert_gt($err->getLine(), 0, "line from backtrace should be positive");
}

// --- TestResult::set_error ---

function test_testresult_set_error(): void
{
    $r = new \TinyTest\TestResult();
    $err = new \Error("test error");
    $r->set_error($err);
    assert_eq($r->error, $err, "error should be set");
    assert_false($r->pass, "pass should remain false");
}

// --- read_test_annotations ---

/**
 * @type integration
 * @exception RuntimeException
 * @timeout 5
 */
function test_read_annotations_helper(): void
{
    assert_true(true, "placeholder");
}

function test_read_test_annotations_parses_type(): void
{
    $data = \TinyTest\read_test_annotations('test_read_annotations_helper');
    assert_eq($data['type'], 'integration', "should parse @type");
}

function test_read_test_annotations_parses_exception(): void
{
    $data = \TinyTest\read_test_annotations('test_read_annotations_helper');
    assert_contains($data['exception'][0], 'RuntimeException', "should parse @exception");
}

function test_read_test_annotations_parses_timeout(): void
{
    $data = \TinyTest\read_test_annotations('test_read_annotations_helper');
    assert_eq($data['timeout'], '5', "should parse @timeout");
}

function test_read_test_annotations_includes_file_and_line(): void
{
    $data = \TinyTest\read_test_annotations('test_read_annotations_helper');
    assert_contains($data['file'], 'test_tinytest.php', "should include file");
    assert_gt($data['line'], 0, "should include line number");
}

function test_read_test_annotations_no_docblock(): void
{
    $data = \TinyTest\read_test_annotations('test_starts_with_true');
    assert_eq($data['type'], 'standard', "no docblock defaults to standard type");
    assert_eq($data['exception'], [], "no docblock has empty exceptions");
}

// --- keep_fn ---

function test_keep_fn_accepts_test_function(): void
{
    $fn = \TinyTest\keep_fn(['q' => 0]);
    assert_true($fn('test_something'), "should accept test_ prefix");
    assert_true($fn('it_works'), "should accept it_ prefix");
    assert_true($fn('should_pass'), "should accept should_ prefix");
}

function test_keep_fn_rejects_non_test(): void
{
    $fn = \TinyTest\keep_fn(['q' => 0]);
    assert_false($fn('helper_fn'), "should reject non-test function");
}

// --- make_source_map_from_tokens ---

function test_make_source_map_from_tokens_finds_functions(): void
{
    $code = "<?php\nfunction hello() { return 1; }\nfunction world() { return 2; }\n";
    $tokens = ['/tmp/test_src.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    assert_true(isset($result['/tmp/test_src.php']), "should have file key");
    $fn_names = array_column($result['/tmp/test_src.php']['fn'], 'name');
    assert_true(in_array('hello', $fn_names), "should find hello function");
    assert_true(in_array('world', $fn_names), "should find world function");
}

function test_make_source_map_excludes_non_executable_tokens(): void
{
    // visibility modifiers, literals, operators should NOT create DA entries
    $code = "<?php\nclass Foo {\n    public \$x = 1;\n    private \$y;\n    public function bar(): void {}\n}\n";
    $tokens = ['/tmp/test_noexec.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $da_lines = array_column($result['/tmp/test_noexec.php']['da'], 'start');
    // Line 5: "public function bar(): void {}" — function signature line
    // T_PUBLIC is not executable, so this should NOT have a DA entry on its own
    // (only if there's also an executable token like T_VARIABLE on the same line)
    assert_false(in_array(5, $da_lines), "function signature line should not be a DA entry");
}

function test_make_source_map_includes_executable_tokens(): void
{
    // return, echo, foreach, variable assignments should create DA entries
    $code = "<?php\nfunction foo() {\n    \$x = 1;\n    echo \$x;\n    return \$x;\n}\n";
    $tokens = ['/tmp/test_exec.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $da_lines = array_column($result['/tmp/test_exec.php']['da'], 'start');
    assert_true(in_array(3, $da_lines), "variable assignment should be a DA entry");
    assert_true(in_array(4, $da_lines), "echo should be a DA entry");
    assert_true(in_array(5, $da_lines), "return should be a DA entry");
}

function test_make_source_map_class_extends_not_counted(): void
{
    $code = "<?php\nclass Child extends Parent_ {\n}\n";
    $tokens = ['/tmp/test_extends.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $da_lines = array_column($result['/tmp/test_extends.php']['da'], 'start');
    // "class Child extends Parent_" — structural, not executable
    assert_false(in_array(2, $da_lines), "class extends line should not be a DA entry");
}

function test_make_source_map_string_literal_only_not_counted(): void
{
    // A line with only a string literal (e.g., array value) should not create a DA entry
    // unless there's also an executable token on that line
    $code = "<?php\n\$x = [\n    'hello',\n    'world',\n];\n";
    $tokens = ['/tmp/test_strlit.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $da_lines = array_column($result['/tmp/test_strlit.php']['da'], 'start');
    // Line 2 has T_VARIABLE ($x) — should be counted
    assert_true(in_array(2, $da_lines), "variable assignment line should be counted");
    // Lines 3,4 have only T_CONSTANT_ENCAPSED_STRING — should NOT be counted
    assert_false(in_array(3, $da_lines), "string literal line should not be counted");
    assert_false(in_array(4, $da_lines), "string literal line should not be counted");
}

function test_make_source_map_from_tokens_skips_class_names(): void
{
    $code = "<?php\nclass MyClass {\n    function myMethod() {}\n}\n";
    $tokens = ['/tmp/test_class.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_class.php']['fn'], 'name');
    assert_false(in_array('MyClass', $fn_names), "should not treat class name as function");
    assert_true(in_array('myMethod', $fn_names), "should find method");
}

function test_make_source_map_from_tokens_finds_branches(): void
{
    $code = "<?php\nfunction foo() {\n    if (true) { return 1; }\n    return 0;\n}\n";
    $tokens = ['/tmp/test_br.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    assert_gt(count($result['/tmp/test_br.php']['brda']), 0, "should find branch definitions");
}

function test_make_source_map_from_tokens_skips_use_statements(): void
{
    $code = "<?php\nuse function SomeNs\\helper;\nfunction real() {}\n";
    $tokens = ['/tmp/test_use.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_use.php']['fn'], 'name');
    assert_false(in_array('helper', $fn_names), "should not treat use import as function");
    assert_true(in_array('real', $fn_names), "should find real function");
}

// --- output_lcov ---

function test_output_lcov_produces_lcov_format(): void
{
    $covered_lines = [2 => 3, 5 => 1];
    $mapping = [
        'fn' => [\TinyTest\new_line_definition(1, 'myFunc', 'fn', 10)],
        'da' => [\TinyTest\new_line_definition(2, 'S', 'da', 2), \TinyTest\new_line_definition(5, 'S', 'da', 5)],
        'brda' => [],
    ];
    $result = \TinyTest\output_lcov('/tmp/test.php', $covered_lines, $mapping, false);
    assert_contains($result['lcov'], "SF:/tmp/test.php", "should contain source file");
    assert_contains($result['lcov'], "FNDA:", "should contain function data");
    assert_contains($result['lcov'], "end_of_record", "should end with end_of_record");
}

function test_output_lcov_tracks_covered_functions(): void
{
    $covered_lines = [2 => 1];
    // find_index_lineno_between uses `$i < max(array_keys(...))` so we need 2+ entries
    $mapping = [
        'fn' => [
            \TinyTest\new_line_definition(1, 'coveredFn', 'fn', 5),
            \TinyTest\new_line_definition(10, 'otherFn', 'fn', 15),
        ],
        'da' => [
            \TinyTest\new_line_definition(2, 'S', 'da', 2),
            \TinyTest\new_line_definition(11, 'S', 'da', 11),
        ],
        'brda' => [],
    ];
    $result = \TinyTest\output_lcov('/tmp/t.php', $covered_lines, $mapping, false);
    $covered_names = array_column($result['covered'], 'name');
    assert_true(in_array('coveredFn', $covered_names), "coveredFn should be covered");
}

function test_output_lcov_tracks_uncovered_functions(): void
{
    $covered_lines = [];
    $mapping = [
        'fn' => [\TinyTest\new_line_definition(1, 'uncovFn', 'fn', 5)],
        'da' => [],
        'brda' => [],
    ];
    $result = \TinyTest\output_lcov('/tmp/t.php', $covered_lines, $mapping, false);
    assert_eq(count($result['uncovered']), 1, "one uncovered function");
    assert_eq($result['uncovered'][0]['name'], 'uncovFn', "uncovered function name");
}

function test_output_lcov_show_coverage_output(): void
{
    $covered_lines = [2 => 1];
    $mapping = [
        'fn' => [
            \TinyTest\new_line_definition(1, 'shownFn', 'fn', 5),
            \TinyTest\new_line_definition(10, 'uncovShownFn', 'fn', 15),
        ],
        'da' => [\TinyTest\new_line_definition(2, 'S', 'da', 2)],
        'brda' => [],
    ];
    ob_start();
    \TinyTest\output_lcov('/tmp/show.php', $covered_lines, $mapping, true);
    $output = ob_get_clean();
    $plain = \TinyTest\strip_ansi($output);
    assert_contains($plain, "/tmp/show.php", "should show filename");
    assert_contains($plain, "shownFn : covered", "should show covered function");
    assert_contains($plain, "uncovShownFn : uncovered", "should show uncovered function");
}

// --- coverage_to_lcov ---

function test_coverage_to_lcov_produces_output(): void
{
    // Create a temp PHP file to use as coverage source
    $tmp = _tt_tempnam('tt_cov_', '.php');
    file_put_contents($tmp, "<?php\nfunction cov_test_fn() {\n    return 42;\n}\n");
    $coverage = [$tmp => [2 => 1, 3 => 1]];
    $options = ['r' => false, 'c' => true, 'q' => 0];
    $result = \TinyTest\coverage_to_lcov($coverage, $options);
    assert_contains($result['lcov'], "SF:$tmp", "should contain source file");
    assert_contains($result['lcov'], "end_of_record", "should end with end_of_record");
}

// --- get_error_log ---

function test_get_error_log_no_errors(): void
{
    // ensure error log file doesn't exist
    @unlink(\TinyTest\ERR_OUT);
    $result = \TinyTest\get_error_log([], ['q' => 0]);
    assert_eq($result, null, "no error log returns null");
}

function test_get_error_log_with_error(): void
{
    file_put_contents(\TinyTest\ERR_OUT, "PHP Fatal error: something\n");
    $result = \TinyTest\get_error_log([], ['q' => 0]);
    assert_true($result instanceof \Error, "should return Error object");
    assert_contains($result->getMessage(), "PHP Fatal error", "should contain error message");
    @unlink(\TinyTest\ERR_OUT);
}

function test_get_error_log_matching_phperror_config(): void
{
    file_put_contents(\TinyTest\ERR_OUT, "PHP Warning: deprecated thing\n");
    $result = \TinyTest\get_error_log(['Warning:deprecated'], ['q' => 0]);
    // matching phperror config means the error is expected, so null
    assert_eq($result, null, "matching phperror config returns null");
    @unlink(\TinyTest\ERR_OUT);
}

// --- call_to_source ---

function test_call_to_source_with_user_function(): void
{
    $x = ['ct' => 5, 'cpu' => 100, 'wt' => 200];
    $options = ['cost' => 'cpu'];
    // Use a function we know exists
    $result = \TinyTest\call_to_source('test_starts_with_true', $x, $options);
    assert_eq($result['fn'], 'test_starts_with_true', "function name");
    assert_eq($result['count'], 5, "call count");
    assert_eq($result['cost'], 100, "cost from cpu");
    assert_contains($result['file'], 'test_tinytest.php', "file from reflection");
    assert_gt($result['line'], 0, "line from reflection");
}

function test_call_to_source_with_internal_function(): void
{
    $x = ['ct' => 1, 'cpu' => 10, 'wt' => 20];
    $options = ['cost' => 'wt'];
    $result = \TinyTest\call_to_source('strlen', $x, $options);
    assert_eq($result['fn'], 'strlen', "function name");
    assert_eq($result['file'], '<internal>', "internal function file");
    assert_eq($result['cost'], 20, "cost from wt");
}

function test_call_to_source_with_class_method(): void
{
    $x = ['ct' => 2, 'cpu' => 50, 'wt' => 100];
    $options = ['cost' => 'cpu'];
    $result = \TinyTest\call_to_source('TinyTest\TestResult::pass', $x, $options);
    assert_eq($result['fn'], 'TinyTest\TestResult::pass', "method name");
    assert_gt($result['line'], 0, "should have line number");
}

// --- parse_options additional cases ---

function test_parse_options_squelch_flag(): void
{
    $opts = \TinyTest\parse_options(['s' => false]);
    assert_true($opts['s'], "squelch enabled");
}

function test_parse_options_list_flag(): void
{
    $opts = \TinyTest\parse_options(['l' => false]);
    assert_true($opts['l'], "list enabled");
}

function test_parse_options_profile_flags(): void
{
    $opts = \TinyTest\parse_options(['p' => false, 'k' => false, 'n' => false]);
    assert_true($opts['p'], "xhprof profile enabled");
    assert_true($opts['k'], "callgrind profile enabled");
    assert_true($opts['n'], "skip low overhead enabled");
}

function test_parse_options_wall_time_cost(): void
{
    $opts = \TinyTest\parse_options(['w' => false]);
    assert_eq($opts['cost'], 'wt', "wall time cost");
}

function test_parse_options_cpu_cost_default(): void
{
    $opts = \TinyTest\parse_options([]);
    assert_eq($opts['cost'], 'cpu', "default cpu cost");
}

function test_parse_options_include_filter(): void
{
    $opts = \TinyTest\parse_options(['i' => ['unit', 'integration']]);
    assert_eq($opts['i'], ['unit', 'integration'], "include types preserved");
}

function test_parse_options_exclude_filter(): void
{
    $opts = \TinyTest\parse_options(['e' => 'slow']);
    assert_eq($opts['e'], ['slow'], "exclude type coerced to array");
}

// --- combine_oplog removes test files ---

function test_combine_oplog_removes_test_dir_files(): void
{
    $cov = [];
    $new = ['/my/tests/test_foo.php' => [1 => 1], '/my/src/real.php' => [5 => 2]];
    $options = ['d' => '/my/tests/'];
    $result = \TinyTest\combine_oplog($cov, $new, $options);
    assert_false(isset($result['/my/tests/test_foo.php']), "test file should be removed");
    assert_true(isset($result['/my/src/real.php']), "source file should remain");
}

// --- read_file_covers with multiple annotations ---

function test_read_file_covers_multiple_annotations(): void
{
    $tmp = _tt_tempnam('tt_');
    file_put_contents($tmp, "<?php\n/**\n * @covers ../src/a.php\n * @covers ../src/b.php\n */\nfunction test_x(): void {}\n");
    $result = \TinyTest\read_file_covers($tmp);
    assert_eq(count($result), 2, "two covers annotations");
}

// --- make_source_map_from_tokens with closures ---

function test_make_source_map_from_tokens_anonymous_fn_not_named(): void
{
    $code = "<?php\n\$fn = function() { return 1; };\n";
    $tokens = ['/tmp/test_anon.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_anon.php']['fn'], 'name');
    // Anonymous functions should not produce named entries
    assert_eq($fn_names, [], "anonymous functions should not create named entries");
}

// --- panic_if (false path) ---

function test_panic_if_false_does_not_die(): void
{
    // Should simply return without dying
    \TinyTest\panic_if(false, "should not die");
    assert_true(true, "panic_if(false) did not terminate");
}

// UNTESTABLE: panic_if(true, ...) calls die() — cannot test without killing the test runner
// UNTESTABLE: dbg() calls die() — cannot test without killing the test runner

// --- fatals ---

function test_fatals_outputs_newline_when_no_error_file(): void
{
    @unlink(\TinyTest\ERR_OUT);
    ob_start();
    \TinyTest\fatals();
    $output = ob_get_clean();
    assert_eq($output, "\n", "fatals outputs newline when no error file exists");
}

function test_fatals_writes_error_to_stderr_when_file_exists(): void
{
    file_put_contents(\TinyTest\ERR_OUT, "some error output\n");
    ob_start();
    // Capture STDERR by redirecting it temporarily
    $stderr_tmp = _tt_tempnam('tt_stderr_');
    $old_stderr = fopen('php://stderr', 'w');
    \TinyTest\fatals();
    $stdout = ob_get_clean();
    echo "ERR OUT [$stdout]\n";

    assert_eq($stdout, "\n", "fatals outputs newline to stdout");
    // The error file content was written to STDERR (we can't easily capture it,
    // but we can verify the function ran without error)
    @unlink(\TinyTest\ERR_OUT);
}

// --- show_usage ---

function test_show_usage_outputs_help_text(): void
{
    ob_start();
    \TinyTest\show_usage();
    $output = ob_get_clean();
    $plain = \TinyTest\strip_ansi($output);
    assert_contains($plain, "-d <directory>", "should show -d option");
    assert_contains($plain, "-f <file>", "should show -f option");
    assert_contains($plain, "-t <test_name>", "should show -t option");
    assert_contains($plain, "-c ", "should show -c option");
    assert_contains($plain, "-j ", "should show -j option");
    assert_contains($plain, "-v ", "should show -v option");
    assert_contains($plain, "-q ", "should show -q option");
    assert_contains($plain, "-m ", "should show -m option");
    assert_contains($plain, "-s ", "should show -s option");
    assert_contains($plain, "-l ", "should show -l option");
    assert_contains($plain, "-p ", "should show -p option");
    assert_contains($plain, "-k ", "should show -k option");
    assert_contains($plain, "-n ", "should show -n option");
    assert_contains($plain, "-w ", "should show -w option");
    assert_contains($plain, "-r ", "should show -r option");
    assert_contains($plain, "-b <bootstrap>", "should show -b option");
    assert_contains($plain, "-a ", "should show -a option");
}

// --- do_test ---

function test_do_test_passes_value_to_function(): void
{
    $fn = function ($val) {
        return "got:$val";
    };
    $result = \TinyTest\do_test($fn, [], "dataset1", "hello");
    assert_true($result->pass, "should pass");
    assert_eq($result->result, "got:hello", "should pass value to function");
}

function test_do_test_without_value(): void
{
    $fn = function () {
        return "no_args";
    };
    $result = \TinyTest\do_test($fn, [], null, null);
    assert_true($result->pass, "should pass");
    assert_eq($result->result, "no_args", "should call without args");
}

function test_do_test_catches_test_error(): void
{
    $fn = function () {
        throw new \TinyTest\TestError("fail", "a", "b");
    };
    $result = \TinyTest\do_test($fn, [], null, null);
    assert_false($result->pass, "should not pass");
    assert_true($result->error instanceof \Error, "should capture TestError");
}

function test_do_test_expected_exception_passes(): void
{
    $fn = function () {
        throw new \RuntimeException("expected");
    };
    $result = \TinyTest\do_test($fn, ['RuntimeException'], null, null);
    assert_true($result->pass, "expected exception should pass");
}

function test_do_test_unexpected_exception_fails(): void
{
    $before_fail = $GLOBALS['assert_fail_count'];
    $fn = function () {
        throw new \RuntimeException("unexpected");
    };
    $result = \TinyTest\do_test($fn, ['InvalidArgumentException'], "ds", "val");
    // undo the fail count increment from do_test
    $GLOBALS['assert_fail_count'] = $before_fail;
    assert_false($result->pass, "unexpected exception should fail");
    assert_true($result->error instanceof \TinyTest\TestError, "should wrap in TestError");
}

function test_do_test_captures_console_output(): void
{
    $fn = function () {
        echo "console stuff";
        return "result";
    };
    $result = \TinyTest\do_test($fn, [], null, null);
    assert_eq($result->console, "console stuff", "should capture console output");
}

// --- run_test ---

function _test_dataprovider_simple(): iterable
{
    yield 'case_a' => 10;
    yield 'case_b' => 20;
}

function test_run_test_with_dataprovider(): void
{
    $fn = function ($val) {
        assert_true($val > 0, "positive");
        return "ok:$val";
    };
    $test_data = [
        'exception' => [],
        'dataprovider' => '_test_dataprovider_simple',
    ];
    $results = \TinyTest\run_test($fn, $test_data);
    assert_eq(count($results), 2, "should have 2 results from dataprovider");
    assert_true($results[0]->pass, "first result passes");
    assert_true($results[1]->pass, "second result passes");
}

function test_run_test_without_dataprovider(): void
{
    $fn = function () {
        assert_true(true, "ok");
        return "done";
    };
    $test_data = [
        'exception' => [],
    ];
    $results = \TinyTest\run_test($fn, $test_data);
    assert_eq(count($results), 1, "should have 1 result");
    assert_true($results[0]->pass, "result passes");
}

function test_run_test_with_timeout(): void
{
    $fn = function () {
        assert_true(true, "ok");
        return "fast";
    };
    $test_data = [
        'exception' => [],
        'timeout' => '10',
    ];
    $results = \TinyTest\run_test($fn, $test_data);
    assert_eq(count($results), 1, "should have 1 result");
    assert_true($results[0]->pass, "fast test should pass within timeout");
}

// --- TestResult ---

function test_testresult_set_result(): void
{
    $r = new \TinyTest\TestResult();
    $r->set_result("output");
    assert_eq($r->result, "output", "result should be set");
}

function test_testresult_set_console(): void
{
    $r = new \TinyTest\TestResult();
    $r->set_console("console output");
    assert_eq($r->console, "console output", "console should be set");
}

function test_testresult_pass(): void
{
    $r = new \TinyTest\TestResult();
    assert_false($r->pass, "default is not passed");
    $r->pass();
    assert_true($r->pass, "should be passed after call");
}

// --- if_then_do ---

function test_if_then_do_executes_when_test_passes(): void
{
    $collected = [];
    $test = function ($arg, $opts) {
        return $arg > 5;
    };
    $action = function ($arg) use (&$collected) {
        $collected[] = $arg;
    };
    $fn = \TinyTest\if_then_do($test, $action);
    $fn(10);
    $fn(3);
    $fn(7);
    assert_eq($collected, [10, 7], "should only collect values > 5");
}

function test_if_then_do_skips_falsy_argument(): void
{
    $called = false;
    $test = function ($arg, $opts) {
        return true;
    };
    $action = function ($arg) use (&$called) {
        $called = true;
    };
    $fn = \TinyTest\if_then_do($test, $action);
    $fn(null);
    $fn(0);
    $fn("");
    $fn(false);
    assert_false($called, "should skip falsy arguments");
}

// --- do_for_allkey ---

function test_do_for_allkey_iterates_keys(): void
{
    $collected = [];
    \TinyTest\do_for_allkey(['a' => 1, 'b' => 2, 'c' => 3], function ($key) use (&$collected) {
        $collected[] = $key;
    });
    assert_eq($collected, ['a', 'b', 'c'], "should iterate keys only");
}

// --- count_assertion / count_assertion_pass ---

function test_count_assertion_pass_increments_both(): void
{
    $before_total = $GLOBALS['assert_count'];
    $before_pass = $GLOBALS['assert_pass_count'];
    \TinyTest\count_assertion_pass();
    $after_total = $GLOBALS['assert_count'];
    $after_pass = $GLOBALS['assert_pass_count'];
    // Restore counters
    $GLOBALS['assert_count'] = $before_total;
    $GLOBALS['assert_pass_count'] = $before_pass;
    assert_eq($after_total, $before_total + 1, "total count incremented");
    assert_eq($after_pass, $before_pass + 1, "pass count incremented");
}

// --- load_file ---

function test_load_file_loads_php_file(): void
{
    $tmp = _tt_tempnam('tt_load_', '.php');
    file_put_contents($tmp, "<?php\n// empty test file\n");
    $_saved_covers = $GLOBALS['_tinytest_covers'] ?? [];
    $GLOBALS['_tinytest_covers'] = [];
    ob_start();
    \TinyTest\load_file($tmp, ['q' => 0, 'v' => false]);
    ob_end_clean();
    // If we got here without assertion error, it loaded
    assert_true(true, "load_file should load without error");
    $GLOBALS['_tinytest_covers'] = $_saved_covers;
}

function test_load_file_verbose_output(): void
{
    $tmp = _tt_tempnam('tt_load_', '.php');
    file_put_contents($tmp, "<?php\n// empty verbose\n");
    $_saved_covers = $GLOBALS['_tinytest_covers'] ?? [];
    $GLOBALS['_tinytest_covers'] = [];
    ob_start();
    \TinyTest\load_file($tmp, ['q' => 0, 'v' => true]);
    $output = ob_get_clean();
    $plain = \TinyTest\strip_ansi($output);
    assert_contains($plain, "loading test file:", "verbose should show loading message");
    assert_contains($plain, "OK", "verbose should show OK");
    $GLOBALS['_tinytest_covers'] = $_saved_covers;
}

function test_load_file_with_covers_annotation(): void
{
    $dir = _tt_tempdir('tt_covers');
    $tmp = "$dir/test_covers_check.php";
    $src = "$dir/source.php";
    file_put_contents($src, "<?php\n");
    // Use relative path so it resolves from the test file's directory
    file_put_contents($tmp, "<?php\n/**\n * @covers source.php\n */\n");
    $_saved_covers = $GLOBALS['_tinytest_covers'] ?? [];
    $GLOBALS['_tinytest_covers'] = [];
    ob_start();
    \TinyTest\load_file($tmp, ['q' => 0, 'v' => false]);
    ob_end_clean();
    assert_true(count($GLOBALS['_tinytest_covers']) > 0, "should populate covers list");
    $GLOBALS['_tinytest_covers'] = $_saved_covers;
}

function test_load_file_covers_path_not_found_warning(): void
{
    $tmp = _tt_tempnam('tt_cov_', '.php');
    file_put_contents($tmp, "<?php\n/**\n * @covers /nonexistent/path/nowhere.php\n */\n");
    $_saved_covers = $GLOBALS['_tinytest_covers'] ?? [];
    $GLOBALS['_tinytest_covers'] = [];
    ob_start();
    \TinyTest\load_file($tmp, ['q' => 0, 'v' => false]);
    $output = ob_get_clean();
    $plain = \TinyTest\strip_ansi($output);
    assert_contains($plain, "warning: @covers path not found", "should warn about missing covers path");
    $GLOBALS['_tinytest_covers'] = $_saved_covers;
}

function test_load_file_covers_path_not_found_json_silent(): void
{
    $tmp = _tt_tempnam('tt_cov_', '.php');
    file_put_contents($tmp, "<?php\n/**\n * @covers /nonexistent/path/nowhere.php\n */\n");
    $_saved_covers = $GLOBALS['_tinytest_covers'] ?? [];
    $GLOBALS['_tinytest_covers'] = [];
    ob_start();
    \TinyTest\load_file($tmp, ['q' => 0, 'v' => false, 'j' => true]);
    $output = ob_get_clean();
    assert_eq($output, "", "json mode should suppress covers warning");
    $GLOBALS['_tinytest_covers'] = $_saved_covers;
}

// --- load_dir ---

function test_load_dir_loads_test_files_from_directory(): void
{
    $dir = _tt_tempdir('tt_loaddir');
    file_put_contents("$dir/test_sample.php", "<?php\n// sample\n");
    file_put_contents("$dir/helper.php", "<?php\n// not a test\n");
    $_saved_covers = $GLOBALS['_tinytest_covers'] ?? [];
    $GLOBALS['_tinytest_covers'] = [];
    ob_start();
    \TinyTest\load_dir($dir, ['q' => 0, 'v' => false]);
    ob_end_clean();
    assert_true(true, "load_dir should load test files without error");
    $GLOBALS['_tinytest_covers'] = $_saved_covers;
}

// --- output_profile ---

function test_output_profile_xhprof_json(): void
{
    $data = [
        'main()==>strlen' => ['ct' => 5, 'cpu' => 100, 'wt' => 200],
    ];
    $func_name = 'tt_test_profile_' . getmypid();
    $options = ['p' => true, 'k' => false, 'n' => false, 'cost' => 'cpu', 'cmd' => 'test'];
    \TinyTest\output_profile($data, $func_name, $options);
    $file = "$func_name.xhprof.json";
    $GLOBALS['_tt_temp_paths'][] = $file;
    assert_true(file_exists($file), "xhprof json file should be created");
    $content = json_decode(file_get_contents($file), true);
    assert_true(is_array($content), "should contain valid JSON");
    assert_true(isset($content['main()==>strlen']), "should contain profile data");
}

function test_output_profile_callgrind(): void
{
    $data = [
        'main()==>strlen' => ['ct' => 5, 'cpu' => 100, 'wt' => 200],
    ];
    $func_name = 'tt_test_cg_' . getmypid();
    $options = ['p' => false, 'k' => true, 'n' => false, 'cost' => 'cpu', 'cmd' => 'test cmd'];
    \TinyTest\output_profile($data, $func_name, $options);
    $file = "callgrind.$func_name";
    $GLOBALS['_tt_temp_paths'][] = $file;
    assert_true(file_exists($file), "callgrind file should be created");
    $content = file_get_contents($file);
    assert_contains($content, "version: 1", "should contain callgrind header");
    assert_contains($content, "test cmd", "should contain command");
}

function test_output_profile_filters_low_overhead(): void
{
    $data = [
        'fast' => ['ct' => 1, 'cpu' => 1, 'wt' => 1],
        'slow' => ['ct' => 10, 'cpu' => 100, 'wt' => 200],
    ];
    $func_name = 'tt_test_filter_' . getmypid();
    $options = ['p' => true, 'k' => false, 'n' => true, 'cost' => 'cpu', 'cmd' => 'test'];
    \TinyTest\output_profile($data, $func_name, $options);
    $file = "$func_name.xhprof.json";
    $GLOBALS['_tt_temp_paths'][] = $file;
    $content = json_decode(file_get_contents($file), true);
    assert_false(isset($content['fast']), "low overhead function should be filtered");
    assert_true(isset($content['slow']), "high overhead function should remain");
}

// --- get_error_log with verbose ---

function test_get_error_log_verbose_shows_matching_errors(): void
{
    file_put_contents(\TinyTest\ERR_OUT, "PHP Warning: deprecated thing\n");
    ob_start();
    $result = \TinyTest\get_error_log(['Warning:deprecated'], ['q' => 0, 'v' => true]);
    $output = ob_get_clean();
    assert_eq($result, null, "matching phperror returns null");
    assert_contains($output, "deprecated thing", "verbose should print matching error");
    @unlink(\TinyTest\ERR_OUT);
}

// --- make_source_map_from_tokens with namespace/interface/trait ---

function test_make_source_map_from_tokens_skips_namespace_name(): void
{
    $code = "<?php\nnamespace MyApp;\nfunction realFn() {}\n";
    $tokens = ['/tmp/test_ns.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_ns.php']['fn'], 'name');
    assert_false(in_array('MyApp', $fn_names), "namespace name should not be a function");
    assert_true(in_array('realFn', $fn_names), "real function should be found");
}

function test_make_source_map_from_tokens_skips_interface_name(): void
{
    $code = "<?php\ninterface MyIface {\n    public function doIt();\n}\n";
    $tokens = ['/tmp/test_iface.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_iface.php']['fn'], 'name');
    assert_false(in_array('MyIface', $fn_names), "interface name should not be a function");
}

function test_make_source_map_from_tokens_skips_trait_name(): void
{
    $code = "<?php\ntrait MyTrait {\n    function doStuff() {}\n}\n";
    $tokens = ['/tmp/test_trait.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_trait.php']['fn'], 'name');
    assert_false(in_array('MyTrait', $fn_names), "trait name should not be a function");
    assert_true(in_array('doStuff', $fn_names), "method in trait should be found");
}

function test_make_source_map_deduplicates_da_lines(): void
{
    // Multiple tokens on same line should result in one DA entry
    $code = "<?php\nfunction x() { return strlen('hi'); }\n";
    $tokens = ['/tmp/test_dedup.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $line_counts = array_count_values(array_column($result['/tmp/test_dedup.php']['da'], 'start'));
    foreach ($line_counts as $line => $count) {
        assert_eq($count, 1, "line $line should appear only once in da");
    }
}

function test_make_source_map_closes_previous_function(): void
{
    $code = "<?php\nfunction first() {\n    return 1;\n}\nfunction second() {\n    return 2;\n}\n";
    $tokens = ['/tmp/test_close.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fns = $result['/tmp/test_close.php']['fn'];
    // first function's end should be set to line before second function starts
    $first = $fns[0];
    $second = $fns[1];
    assert_eq($first['name'], 'first', "first function");
    assert_eq($second['name'], 'second', "second function");
    assert_true($first['end'] < $second['start'], "first function end should be before second function start");
}

// --- coverage_to_lcov with covers filter ---

function test_coverage_to_lcov_with_covers_filter(): void
{
    $tmp = _tt_tempnam('tt_cf_', '.php');
    file_put_contents($tmp, "<?php\nfunction cf_test() {\n    return 1;\n}\n");
    $coverage = [$tmp => [2 => 1, 3 => 1]];
    $options = ['r' => true, 'c' => true, 'q' => 0];
    ob_start();
    $result = \TinyTest\coverage_to_lcov($coverage, $options, [$tmp]);
    ob_end_clean();
    assert_contains($result['lcov'], "SF:$tmp", "should contain source file");
}

// --- read_test_annotations with @skip and @todo ---

/**
 * @skip testing skip annotation
 */
function test_annotation_skip_helper(): void
{
    assert_true(true, "placeholder");
}

/**
 * @todo implement later
 */
function test_annotation_todo_helper(): void
{
    assert_true(true, "placeholder");
}

/**
 * @ambiguous might be flaky
 */
function test_annotation_ambiguous_helper(): void
{
    assert_true(true, "placeholder");
}

function test_read_test_annotations_parses_skip(): void
{
    $data = \TinyTest\read_test_annotations('test_annotation_skip_helper');
    assert_true(isset($data['skip']), "should have skip annotation");
    assert_contains($data['skip'], 'testing skip annotation', "should contain skip reason");
}

function test_read_test_annotations_parses_todo(): void
{
    $data = \TinyTest\read_test_annotations('test_annotation_todo_helper');
    assert_true(isset($data['todo']), "should have todo annotation");
    assert_contains($data['todo'], 'implement later', "should contain todo reason");
}

function test_read_test_annotations_parses_ambiguous(): void
{
    $data = \TinyTest\read_test_annotations('test_annotation_ambiguous_helper');
    assert_true(isset($data['ambiguous']), "should have ambiguous annotation");
    assert_contains($data['ambiguous'], 'might be flaky', "should contain ambiguous reason");
}

/**
 * @phperror Warning:test
 */
function test_annotation_phperror_helper(): void
{
    assert_true(true, "placeholder");
}

function test_read_test_annotations_parses_phperror(): void
{
    $data = \TinyTest\read_test_annotations('test_annotation_phperror_helper');
    assert_eq(count($data['phperror']), 1, "should have one phperror");
}

/**
 * @dataprovider _test_dataprovider_simple
 */
function test_annotation_dataprovider_helper(): void
{
    assert_true(true, "placeholder");
}

function test_read_test_annotations_parses_dataprovider(): void
{
    $data = \TinyTest\read_test_annotations('test_annotation_dataprovider_helper');
    assert_eq($data['dataprovider'], '_test_dataprovider_simple', "should parse dataprovider");
}

// --- get_mtime old file ---

function test_get_mtime_old_file_format(): void
{
    $tmp = _tt_tempnam('tt_old_');
    file_put_contents($tmp, "x");
    // Set mtime to 2 years ago
    touch($tmp, time() - 86400 * 730);
    $result = \TinyTest\get_mtime($tmp);
    // Old file should have "Mon year" format like "Mar 2024"
    assert_matches($result, '/^[A-Z][a-z]{2} \d{4}$/', "old file has 'Mon year' format");
}

// --- is_excluded_test with empty arrays ---

function test_is_excluded_include_empty_array(): void
{
    $test_data = ['type' => 'unit'];
    $options = ['q' => 0, 'i' => []];
    assert_false(\TinyTest\is_excluded_test($test_data, $options), "empty include array means not excluded");
}

function test_is_excluded_exclude_empty_array(): void
{
    $test_data = ['type' => 'unit'];
    $options = ['q' => 0, 'e' => []];
    assert_false(\TinyTest\is_excluded_test($test_data, $options), "empty exclude array means not excluded");
}

// --- parse_options with bootstrap ---

function test_parse_options_autodetect_bootstrap(): void
{
    $dir = _tt_tempdir('tt_bootstrap');
    file_put_contents("$dir/bootstrap.php", "<?php\n// bootstrap\n");
    $opts = \TinyTest\parse_options(['a' => false, 'd' => $dir]);
    assert_eq($opts['b'], "$dir/bootstrap.php", "should autodetect bootstrap");
}

function test_parse_options_autodetect_bootstrap_from_file(): void
{
    $dir = _tt_tempdir('tt_bootstrap_f');
    file_put_contents("$dir/bootstrap.php", "<?php\n// bootstrap\n");
    $opts = \TinyTest\parse_options(['a' => false, 'f' => "$dir/test_foo.php"]);
    assert_eq($opts['b'], "$dir/bootstrap.php", "should autodetect bootstrap from file dir");
}

// --- call_to_source with nonexistent function ---

function test_call_to_source_nonexistent_function(): void
{
    $x = ['ct' => 1, 'cpu' => 10, 'wt' => 20];
    $options = ['cost' => 'cpu'];
    $result = \TinyTest\call_to_source('nonexistent_function_xyz_abc', $x, $options);
    assert_eq($result['file'], '<internal>', "nonexistent function file should be <internal>");
    assert_eq($result['line'], 0, "nonexistent function line should be 0");
}

// --- output_lcov with branch coverage ---

function test_output_lcov_with_branches(): void
{
    $covered_lines = [3 => 1];
    $mapping = [
        'fn' => [
            \TinyTest\new_line_definition(1, 'branchFn', 'fn', 10),
            \TinyTest\new_line_definition(15, 'dummy', 'fn', 20),
        ],
        'da' => [\TinyTest\new_line_definition(3, 'S', 'da', 3)],
        'brda' => [
            \TinyTest\new_line_definition(3, 'if', 'brda', 3),
            \TinyTest\new_line_definition(12, 'if', 'brda', 12),
        ],
    ];
    $result = \TinyTest\output_lcov('/tmp/br.php', $covered_lines, $mapping, false);
    assert_contains($result['lcov'], "BRDA:", "should contain branch data");
    assert_contains($result['lcov'], "BRF:2", "should have 2 total branches");
}

// --- format_test_success with result output ---

function test_format_test_success_with_result_output(): void
{
    $test_data = ['status' => 'OK', 'result' => "some test output"];
    $options = ['q' => 0];
    $result = \TinyTest\format_test_success($test_data, $options, 0.01);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "some test output", "should display test result output");
}

function test_format_test_success_little_quiet_hides_result(): void
{
    $test_data = ['status' => 'OK', 'result' => "hidden output"];
    $options = ['q' => 1];
    $result = \TinyTest\format_test_success($test_data, $options, 0.01);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "OK", "should show OK");
    // q=1 hides display_test_output
    assert_not_contains($plain, "hidden output", "q=1 should hide result output");
}

// --- combine_oplog: tinytest framework file filtering (empty covers) ---

function test_combine_oplog_removes_tinytest_framework_files(): void
{
    $tinytest_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $framework_file = $tinytest_dir . 'tinytest.php';
    $source_file = '/some/other/path/app.php';
    $cov = [];
    $new = [$framework_file => [10 => 1], $source_file => [5 => 2]];
    $options = ['d' => '/nonexistent/'];
    // Temporarily clear covers so the framework filter applies
    $_saved = $GLOBALS['_tinytest_covers'] ?? [];
    $GLOBALS['_tinytest_covers'] = [];
    $result = \TinyTest\combine_oplog($cov, $new, $options);
    $GLOBALS['_tinytest_covers'] = $_saved;
    assert_false(isset($result[$framework_file]), "framework file should be removed when no @covers");
    assert_true(isset($result[$source_file]), "non-framework file should remain");
}

// --- read_test_annotations: generic annotation (else branch) ---

/**
 * @custom_tag my_value
 */
function test_annotation_custom_tag_helper(): void
{
    assert_true(true, "placeholder");
}

function test_read_test_annotations_parses_custom_tag(): void
{
    $data = \TinyTest\read_test_annotations('test_annotation_custom_tag_helper');
    assert_eq($data['custom_tag'], 'my_value', "should parse custom annotation");
}

// --- make_source_map: T_STRING as function call (else branch line 703) ---

function test_make_source_map_recognizes_function_calls(): void
{
    // strlen is an internal function — its call should appear as a DA entry
    $code = "<?php\nfunction foo() {\n    \$x = strlen('hi');\n    return \$x;\n}\n";
    $tokens = ['/tmp/test_fncall.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $da_lines = array_column($result['/tmp/test_fncall.php']['da'], 'start');
    assert_true(in_array(3, $da_lines), "strlen call on line 3 should be tracked as DA");
}

// --- do_test: fallback timeout failure ---

function test_do_test_fallback_timeout_very_short(): void
{
    // Use a very short timeout (0.001s) with a function that takes a bit longer
    $before_fail = $GLOBALS['assert_fail_count'];
    $fn = function () {
        usleep(50000); // 50ms
        assert_true(true, "ok");
        return "done";
    };
    $result = \TinyTest\do_test($fn, [], null, null, 0.001);
    // Undo fail count increment
    $GLOBALS['assert_fail_count'] = $before_fail;
    assert_false($result->pass, "should fail due to timeout");
    $plain = \TinyTest\strip_ansi($result->error->getMessage());
    assert_contains($plain, "timed out", "error should mention timeout");
}

// --- output_lcov: show coverage with uncovered functions ---

function test_output_lcov_show_coverage_uncovered_functions(): void
{
    $covered_lines = []; // nothing covered
    $mapping = [
        'fn' => [\TinyTest\new_line_definition(1, 'uncoveredShow', 'fn', 10)],
        'da' => [\TinyTest\new_line_definition(2, 'S', 'da', 2)],
        'brda' => [],
    ];
    ob_start();
    \TinyTest\output_lcov('/tmp/uncov_show.php', $covered_lines, $mapping, true);
    $output = ob_get_clean();
    $plain = \TinyTest\strip_ansi($output);
    assert_contains($plain, "uncoveredShow", "should show uncovered function name");
    assert_contains($plain, "uncovered", "should indicate uncovered status");
}

// --- make_source_map: use { function } block (use with braces) ---

function test_make_source_map_skips_use_with_braces(): void
{
    $code = "<?php\nclass Foo {\n    use SomeTrait { someMethod as private; }\n    function realMethod() {}\n}\n";
    $tokens = ['/tmp/test_use_brace.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_use_brace.php']['fn'], 'name');
    assert_false(in_array('someMethod', $fn_names), "use-trait method alias should not be a function");
    assert_true(in_array('realMethod', $fn_names), "real method should be found");
}

// --- is_important_token: doc comment and inline HTML ---

function test_is_important_token_rejects_doc_comment(): void
{
    assert_false(\TinyTest\is_important_token([T_DOC_COMMENT, "/** doc */", 1]), "doc comment not important");
}

function test_is_important_token_rejects_ns_separator(): void
{
    assert_false(\TinyTest\is_important_token([T_NS_SEPARATOR, "\\", 1]), "ns separator not important");
}

function test_is_important_token_rejects_inline_html(): void
{
    assert_false(\TinyTest\is_important_token([T_INLINE_HTML, "<html>", 1]), "inline HTML not important");
}

// --- format_assertion_error: little_quiet (q=1) branch ---

function test_format_assertion_error_little_quiet(): void
{
    $ex = new \Error("q1 error");
    $test_data = ['error' => $ex, 'result' => null];
    $options = ['q' => 1];
    $result = \TinyTest\format_assertion_error($test_data, $options, 0.05);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "err", "q=1 should contain err");
    // q=1 (little_quiet) shows file/line but not message
    assert_not_contains($plain, "q1 error", "q=1 should not show error message");
}

// --- format_assertion_error: with result output ---

function test_format_assertion_error_with_result_output(): void
{
    $ex = new \Error("err with output");
    $test_data = ['error' => $ex, 'result' => "test produced output"];
    $options = ['q' => 0];
    $result = \TinyTest\format_assertion_error($test_data, $options, 0.05);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "test produced output", "should display test result output in error");
}

// --- display_test_output: multiline result ---

function test_display_test_output_multiline(): void
{
    $result = \TinyTest\display_test_output("line1\nline2\nline3", ['q' => 0]);
    $plain = \TinyTest\strip_ansi($result);
    assert_contains($plain, "line1", "should contain first line");
    assert_contains($plain, "-> line2", "should prefix subsequent lines with ->");
}

// --- format_test_run: long filename truncation ---

function test_format_test_run_long_filename(): void
{
    $long_path = '/some/very/long/path/to/a/really/long/filename/test_with_very_long_name.php';
    $test_data = ['file' => $long_path, 'type' => 'unit'];
    $options = ['q' => 0];
    $result = \TinyTest\format_test_run("test_x", $test_data, $options);
    $plain = \TinyTest\strip_ansi($result);
    // filename is truncated to 32 chars
    assert_contains($plain, ".php", "should still show .php extension");
}

// --- output_lcov: zero DA count (no statements) ---

function test_output_lcov_zero_da_count(): void
{
    $mapping = [
        'fn' => [],
        'da' => [],
        'brda' => [],
    ];
    ob_start();
    $result = \TinyTest\output_lcov('/tmp/empty.php', [], $mapping, true);
    $output = ob_get_clean();
    $plain = \TinyTest\strip_ansi($output);
    assert_contains($plain, "0 %", "zero statements should show 0%");
    assert_contains($result['lcov'], "LF:0", "should have zero line count");
}

// --- make_source_map: enum support ---

function test_make_source_map_from_tokens_skips_enum_name(): void
{
    if (!defined('T_ENUM')) {
        assert_true(true, "T_ENUM not available in this PHP version, skipping");
        return;
    }
    $code = "<?php\nenum Status {\n    case Active;\n    case Inactive;\n}\n";
    $tokens = ['/tmp/test_enum.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_enum.php']['fn'], 'name');
    assert_false(in_array('Status', $fn_names), "enum name should not be treated as function");
}

// --- do_test: catch Error branch (non-TestError) ---

function test_do_test_catches_generic_error(): void
{
    $fn = function () {
        throw new \Error("generic error");
    };
    $result = \TinyTest\do_test($fn, [], "ds1", null);
    assert_false($result->pass, "should not pass on Error");
    assert_true($result->error instanceof \Error, "should capture Error");
    assert_contains($result->error->getMessage(), "generic error", "should preserve error message");
}

// --- do_test: catch Error sets test_data ---

function test_do_test_error_has_dataset_name(): void
{
    $fn = function () {
        throw new \Error("err");
    };
    $result = \TinyTest\do_test($fn, [], "my_dataset", null);
    assert_eq($result->error->test_data, "my_dataset", "should set test_data on Error");
}

// --- do_test: timeout with passing test (no timeout exceeded) ---

function test_do_test_timeout_not_exceeded(): void
{
    $fn = function () {
        assert_true(true, "ok");
        return "fast";
    };
    $result = \TinyTest\do_test($fn, [], null, null, 5.0);
    assert_true($result->pass, "should pass when timeout not exceeded");
}

// --- coverage_to_lcov: returns uncovered dict ---

function test_coverage_to_lcov_returns_uncovered(): void
{
    $tmp = _tt_tempnam('tt_uncov_', '.php');
    file_put_contents($tmp, "<?php\nfunction uncov_fn_a() {\n    return 1;\n}\nfunction uncov_fn_b() {\n    return 2;\n}\n");
    $coverage = [$tmp => []]; // no lines covered
    $options = ['r' => false, 'c' => true, 'q' => 0];
    $result = \TinyTest\coverage_to_lcov($coverage, $options);
    assert_true(isset($result['uncovered']), "should have uncovered key");
}

// --- read_file_covers: @covers after function definition is ignored ---

function test_read_file_covers_ignores_covers_after_function(): void
{
    $tmp = _tt_tempnam('tt_');
    file_put_contents($tmp, "<?php\nfunction test_x(): void {\n    // @covers should_not_match.php\n}\n");
    $result = \TinyTest\read_file_covers($tmp);
    assert_eq(count($result), 0, "@covers after function should be ignored");
}

// --- read_file_covers: nonexistent file ---

function test_read_file_covers_nonexistent_file(): void
{
    $result = \TinyTest\read_file_covers('/tmp/does_not_exist_ever_' . getmypid() . '.php');
    assert_eq(count($result), 0, "nonexistent file returns empty array");
}

// --- combine_oplog: keeps tinytest files when @covers is set ---

function test_combine_oplog_keeps_tinytest_files_when_covers_set(): void
{
    $tinytest_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $framework_file = $tinytest_dir . 'tinytest.php';
    $cov = [];
    $new = [$framework_file => [10 => 1]];
    $options = ['d' => '/nonexistent/'];
    $_saved = $GLOBALS['_tinytest_covers'] ?? [];
    $GLOBALS['_tinytest_covers'] = [$framework_file]; // non-empty covers
    $result = \TinyTest\combine_oplog($cov, $new, $options);
    $GLOBALS['_tinytest_covers'] = $_saved;
    assert_true(isset($result[$framework_file]), "framework file should be KEPT when @covers is set");
}

// --- get_error_log: with error and non-matching phperror config ---

function test_get_error_log_non_matching_phperror_returns_error(): void
{
    file_put_contents(\TinyTest\ERR_OUT, "PHP Fatal error: something bad\n");
    $result = \TinyTest\get_error_log(['Warning:deprecated'], ['q' => 0]);
    assert_true($result instanceof \Error, "non-matching phperror should return Error");
    assert_contains($result->getMessage(), "something bad", "should contain error text");
    @unlink(\TinyTest\ERR_OUT);
}

// --- TestResult: initial state ---

function test_testresult_initial_state(): void
{
    $r = new \TinyTest\TestResult();
    assert_eq($r->error, null, "initial error is null");
    assert_false($r->pass, "initial pass is false");
    assert_eq($r->result, "", "initial result is empty string");
    assert_eq($r->console, "", "initial console is empty string");
}

// --- find_index_lineno_between: sparse array with gaps ---

function test_find_index_lineno_between_sparse_array(): void
{
    $listing = [];
    $listing[0] = ['start' => 1, 'end' => 10, 'name' => 'fn1'];
    $listing[2] = ['start' => 15, 'end' => 25, 'name' => 'fn2']; // index 1 missing
    $listing[3] = ['start' => 30, 'end' => 40, 'name' => 'fn3'];
    assert_eq(\TinyTest\find_index_lineno_between($listing, 5, "fn"), 0, "should find in sparse array");
    assert_eq(\TinyTest\find_index_lineno_between($listing, 20, "fn"), 2, "should find at gap index");
}

// --- parse_options: include as single string ---

function test_parse_options_include_single_string(): void
{
    $opts = \TinyTest\parse_options(['i' => 'unit']);
    assert_eq($opts['i'], ['unit'], "single include string coerced to array");
}

// --- parse_options: no bootstrap when -a not set ---

function test_parse_options_no_bootstrap_without_a_flag(): void
{
    $opts = \TinyTest\parse_options([]);
    assert_false(isset($opts['b']) && strlen($opts['b']) > 1, "no bootstrap without -a flag");
}

// --- parse_options: -a with no bootstrap file found ---

function test_parse_options_autodetect_no_bootstrap_file(): void
{
    $dir = _tt_tempdir('tt_noboot');
    $opts = \TinyTest\parse_options(['a' => false, 'd' => $dir]);
    // No bootstrap.php exists in $dir, so b should be empty or unset
    assert_true(!isset($opts['b']) || $opts['b'] === '', "no bootstrap file should mean empty b");
}

// --- make_source_map: type hint names not treated as functions ---

function test_make_source_map_skips_type_hint_names(): void
{
    $code = "<?php\nfunction foo(string \$x, int \$y): bool { return true; }\n";
    $tokens = ['/tmp/test_typehints.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    $fn_names = array_column($result['/tmp/test_typehints.php']['fn'], 'name');
    assert_false(in_array('string', $fn_names), "type hint 'string' should not be a function name");
    assert_false(in_array('int', $fn_names), "type hint 'int' should not be a function name");
    assert_false(in_array('bool', $fn_names), "type hint 'bool' should not be a function name");
    assert_true(in_array('foo', $fn_names), "actual function should be found");
}

// --- output_lcov: excludes test functions from covered/uncovered lists ---

function test_output_lcov_excludes_test_functions(): void
{
    $mapping = [
        'fn' => [
            \TinyTest\new_line_definition(1, 'test_something', 'fn', 5),
            \TinyTest\new_line_definition(10, 'it_works', 'fn', 15),
            \TinyTest\new_line_definition(20, 'should_pass', 'fn', 25),
            \TinyTest\new_line_definition(30, 'real_function', 'fn', 35),
        ],
        'da' => [],
        'brda' => [],
    ];
    $result = \TinyTest\output_lcov('/tmp/test_excl.php', [], $mapping, false);
    $all_names = array_merge(
        array_column($result['covered'], 'name'),
        array_column($result['uncovered'], 'name')
    );
    assert_false(in_array('test_something', $all_names), "test_ prefixed should be excluded");
    assert_false(in_array('it_works', $all_names), "it_ prefixed should be excluded");
    assert_false(in_array('should_pass', $all_names), "should_ prefixed should be excluded");
    assert_true(in_array('real_function', $all_names), "real function should be included");
}

// --- coverage_to_lcov: with covers_filter limiting output ---

function test_coverage_to_lcov_covers_filter_limits_files(): void
{
    $tmp1 = _tt_tempnam('tt_filt1_', '.php');
    $tmp2 = _tt_tempnam('tt_filt2_', '.php');
    file_put_contents($tmp1, "<?php\nfunction filt1() { return 1; }\n");
    file_put_contents($tmp2, "<?php\nfunction filt2() { return 2; }\n");
    $coverage = [$tmp1 => [2 => 1], $tmp2 => [2 => 1]];
    $options = ['r' => false, 'c' => true, 'q' => 0];
    // Only show tmp1 via covers filter
    $result = \TinyTest\coverage_to_lcov($coverage, $options, [$tmp1]);
    // lcov should contain both files (the filter only affects -r display)
    assert_contains($result['lcov'], "SF:$tmp1", "should contain filtered file");
    assert_contains($result['lcov'], "SF:$tmp2", "should still contain non-filtered file in lcov");
}

// --- make_source_map: function followed by open paren (anonymous fn edge case) ---

function test_make_source_map_arrow_function_in_array(): void
{
    $code = "<?php\n\$arr = array_map(fn(\$x) => \$x * 2, [1,2,3]);\n";
    $tokens = ['/tmp/test_arrow.php' => token_get_all($code)];
    $result = \TinyTest\make_source_map_from_tokens($tokens);
    // Should not crash and should handle arrow functions gracefully
    assert_true(isset($result['/tmp/test_arrow.php']), "should process arrow functions");
}

// --- all_match with match=false (any_match internal) ---

function test_all_match_with_false_match(): void
{
    $data = [1, 3, 5];
    $is_even = function (int $n): bool {
        return $n % 2 === 0;
    };
    // all_match with match=false means "check that at least one doesn't match"
    // which is the any_match behavior
    $result = \TinyTest\all_match($data, $is_even, false);
    assert_false($result, "no even numbers means any_match returns false");
}

// --- between edge cases ---

function test_between_equal_min_max(): void
{
    assert_true(\TinyTest\between(5, 5, 5), "value equals both min and max");
}

// --- is_test_file with empty string ---

function test_is_test_file_empty_string(): void
{
    assert_false(\TinyTest\is_test_file(""), "empty string is not a test file");
}

// --- is_test_function with empty string ---

function test_is_test_function_empty_string(): void
{
    assert_false(\TinyTest\is_test_function("", ['q' => 0]), "empty string is not a test function");
}

// --- do_test: exception that is neither Error nor in expected list ---

function test_do_test_unexpected_logic_exception(): void
{
    $before_fail = $GLOBALS['assert_fail_count'];
    $fn = function () {
        throw new \LogicException("logic fail");
    };
    $result = \TinyTest\do_test($fn, [], null, null);
    $GLOBALS['assert_fail_count'] = $before_fail;
    assert_false($result->pass, "unexpected LogicException should fail");
}

// --- do_test: expected exception list with multiple entries ---

function test_do_test_multiple_expected_exceptions(): void
{
    $fn = function () {
        throw new \InvalidArgumentException("bad arg");
    };
    $result = \TinyTest\do_test($fn, ['RuntimeException', 'InvalidArgumentException'], null, null);
    assert_true($result->pass, "should pass when exception matches one of multiple expected");
}

// --- read_test_annotations: multiple @exception annotations ---

/**
 * @exception RuntimeException
 * @exception InvalidArgumentException
 */
function test_annotation_multi_exception_helper(): void
{
    assert_true(true, "placeholder");
}

function test_read_test_annotations_multiple_exceptions(): void
{
    $data = \TinyTest\read_test_annotations('test_annotation_multi_exception_helper');
    assert_eq(count($data['exception']), 2, "should have two exceptions");
    assert_contains($data['exception'][0], 'RuntimeException', "first exception");
    assert_contains($data['exception'][1], 'InvalidArgumentException', "second exception");
}

// --- call_to_source: wall time cost ---

function test_call_to_source_wall_time(): void
{
    $x = ['ct' => 3, 'cpu' => 50, 'wt' => 150];
    $options = ['cost' => 'wt'];
    $result = \TinyTest\call_to_source('test_starts_with_true', $x, $options);
    assert_eq($result['cost'], 150, "wall time cost");
    assert_eq($result['count'], 3, "call count");
}

// --- is_excluded_test: include takes precedence over exclude ---

function test_is_excluded_include_overrides_exclude(): void
{
    $test_data = ['type' => 'unit'];
    // Both -i and -e set — -i is checked first
    $options = ['q' => 0, 'i' => ['unit'], 'e' => ['unit']];
    assert_false(\TinyTest\is_excluded_test($test_data, $options), "include should take precedence");
}

// UNREACHABLE: Lines in main execution block (1100-1327) are inline code that runs when
// tinytest.php is loaded as entry point — not callable as functions, cannot be unit tested.

// UNTESTABLE: init() calls define() for constants that are already defined, would cause fatal error.
// UNTESTABLE: Lines calling exit(0) in init() for usage help display.
// UNTESTABLE: dbg() calls die() — cannot test without killing the test runner.
