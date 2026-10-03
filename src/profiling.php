<?php declare(strict_types=1);
namespace TinyTest;

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
