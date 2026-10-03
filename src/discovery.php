<?php declare(strict_types=1);
namespace TinyTest;

function load_file(string $file, array $options): void
{
    $file = readable_file($file, 'test file');
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
            } else if (!($options['j'] ?? false)) {
                warn_ifnot(false, "warning: @covers path not found: $path");
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
    if (!is_dir($dir) || !is_readable($dir)) {
        throw new \InvalidArgumentException("test directory is not readable: $dir");
    }
    $is_test_file_fn = function_exists('user_is_test_file') ? 'user_is_test_file' : 'TinyTest\\is_test_file';
    foreach (scandir($dir) as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_file($path) && $is_test_file_fn($item, $options)) {
            load_file($path, $options);
        }
    }
}

// scan a test file for @covers annotations before the first function/class definition
function read_file_covers(string $file): array
{
    $contents = @file_get_contents($file);
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



function is_test_file(string $filename, ?array $options = null): bool
{
    return (starts_with($filename, "test_") && ends_with($filename, ".php"));
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

