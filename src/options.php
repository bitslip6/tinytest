<?php declare(strict_types=1);
namespace TinyTest;

// Explicit short-option parser: no silently ignored flags or trailing arguments.
function cli_arguments(array $argv): array
{
    $raw = [];
    $values = 'bdftie'; // b,d,f,t,i,e take values.
    $flags = 'pmnqchrvsalkwjx?';
    for ($i = 1; $i < count($argv); $i++) {
        $argument = $argv[$i];
        if ($argument === '--allow-empty') { $raw['allow-empty'] = true; continue; }
        if ($argument === '--help') { $argument = '-h'; }
        if ($argument === '--json') { $argument = '-j'; }
        if (strlen($argument) < 2 || $argument[0] !== '-' || $argument[1] === '-') {
            throw new \InvalidArgumentException("unexpected argument: $argument");
        }
        for ($j = 1; $j < strlen($argument); $j++) {
            $key = $argument[$j];
            if (strpos($values, $key) !== false) {
                $value = substr($argument, $j + 1);
                if ($value === '') {
                    $value = $argv[++$i] ?? '';
                    if ($value === '' || $value[0] === '-') {
                        throw new \InvalidArgumentException("-$key requires a value (attach dash-prefixed values directly)");
                    }
                }
                if (in_array($key, ['f', 'i', 'e'], true)) {
                    $raw[$key][] = $value;
                } else {
                    if (isset($raw[$key])) { throw new \InvalidArgumentException("-$key may only be supplied once"); }
                    $raw[$key] = $value;
                }
                break;
            }
            if (strpos($flags, $key) === false) {
                throw new \InvalidArgumentException("unknown option: -$key");
            }
            if ($key === 'q') { $raw['q'][] = false; } else { $raw[$key] = false; }
        }
    }
    if (count($argv) === 1) { $raw['h'] = false; }
    return $raw;
}

// Detect output intent even when parsing subsequently rejects another option.
function json_requested(array $argv): bool
{
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--json') { return true; }
        if (substr($argv[$i], 0, 2) === '--' || substr($argv[$i], 0, 1) !== '-') { continue; }
        for ($j = 1; $j < strlen($argv[$i]); $j++) {
            $key = $argv[$i][$j];
            if ($key === 'j') { return true; }
            if (strpos('bdftie', $key) !== false) {
                if ($j === strlen($argv[$i]) - 1 && isset($argv[$i + 1]) && substr($argv[$i + 1], 0, 1) !== '-') { $i++; }
                break;
            }
        }
    }
    return false;
}

function readable_file(string $path, string $kind): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new \InvalidArgumentException("$kind is not a readable file: $path");
    }
    return realpath($path);
}

function validate_options(array $options): array
{
    if (isset($options['h']) || isset($options['?'])) { return $options; }
    if (isset($options['d']) && isset($options['f'])) {
        throw new \InvalidArgumentException('use either -d or -f, not both');
    }
    if (!isset($options['d']) && empty($options['f'])) {
        throw new \InvalidArgumentException('select tests with -d <directory> or -f <file>');
    }
    if (isset($options['d'])) {
        if (!is_dir($options['d']) || !is_readable($options['d'])) {
            throw new \InvalidArgumentException('test directory is not readable: ' . $options['d']);
        }
        $options['d'] = realpath($options['d']);
    }
    if (isset($options['f'])) {
        $options['f'] = array_values(array_unique(array_map(fn($file) => readable_file($file, 'test file'), $options['f'])));
    }
    if (isset($options['a']) && !isset($options['b'])) {
        $directories = isset($options['d']) ? [$options['d']] : array_unique(array_map('dirname', $options['f']));
        if (count($directories) !== 1) {
            throw new \InvalidArgumentException('-a is ambiguous across directories; use an explicit -b bootstrap');
        }
        $bootstrap = reset($directories) . '/bootstrap.php';
        if (file_exists($bootstrap)) { $options['b'] = $bootstrap; }
    }
    if (isset($options['b'])) { $options['b'] = readable_file($options['b'], 'bootstrap'); }
    if (!$options['l']) {
        if ($options['c'] && !function_exists('phpdbg_start_oplog')) {
            throw new \RuntimeException('coverage requires phpdbg with oplog support');
        }
        if (($options['p'] || $options['k']) && (!function_exists('tideways_enable') || !function_exists('tideways_disable')
            || !defined('TIDEWAYS_FLAGS_CPU') || !defined('TIDEWAYS_FLAGS_MEMORY'))) {
            throw new \RuntimeException('profiling requires the tideways_enable/tideways_disable API and CPU/memory flags');
        }
    }
    return $options;
}
