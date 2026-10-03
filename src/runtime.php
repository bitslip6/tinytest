<?php declare(strict_types=1);
namespace TinyTest;



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
// Errors-only mode includes incomplete tests because they cause a failing exit.
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
function count_assertion_fail(?\Throwable $error = null)
{
    count_assertion();
    $GLOBALS['assert_fail_count']++;
    $scope = $GLOBALS['_tinytest_assertion_scope'] ?? null;
    if ($scope !== null) {
        $scope->failures++;
        $scope->error = $error ?? new TestError('failed assertion was recorded', false, true);
    }
}
function panic_if(bool $result, string $msg)
{
    if ($result) {
        throw new \RuntimeException(strip_ansi($msg));
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
    $needles = is_array($value) ? array_values($value) : [$value];
    return function ($argument) use ($needles) {
        foreach ($needles as $needle) {
            if ($needle !== null && $needle !== '' && strstr($argument, $needle) !== false) {
                return true;
            }
        }
        return false;
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
    return date(($m < time() - 86400 * 365) ? "M o" : "M j", $m);
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
        echo framework_root() . '/tinytest.php' . CYAN . " Ver " . VER . NORML . "\n";
    }

    if (!defined('TinyTest\\ERR_OUT')) {
        $error_file = tempnam(sys_get_temp_dir(), 'tinytest_');
        if ($error_file === false) { throw new \RuntimeException('cannot create error-log compatibility file'); }
        define('TinyTest\\ERR_OUT', $error_file);
    }
    // set assertion state
    ini_set("assert.exception", "1");

    // squelch error reporting if requested
    error_reporting($options['s'] ? 0 : E_ALL);
    @unlink(ERR_OUT);
    gc_enable();

    return $options;
}
