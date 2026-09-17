<?php declare(strict_types=1);
// Independent subprocess contracts for input validation and the JSON boundary.
// Run: php tests/boundary_contracts.php

function boundary_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function boundary_fixture(string $directory, string $name, string $code): string {
    $file = "$directory/$name.php";
    file_put_contents($file, "<?php\n" . $code);
    return $file;
}
function boundary_run(array $arguments, int $expected_exit, array $ini = [], ?string $cwd = null, bool $json = true): array {
    $command = [PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'display_errors=1'];
    foreach ($ini as $key => $value) { $command[] = '-d'; $command[] = $key . '="' . addcslashes((string) $value, "\\\"") . '"'; }
    $command[] = dirname(__DIR__) . '/tinytest.php';
    if ($json) { $command[] = '-j'; }
    $command = array_merge($command, $arguments);
    $out = tmpfile(); $err = tmpfile();
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $out, 2 => $err], $pipes, $cwd ?? dirname(__DIR__));
    boundary_check(is_resource($process), 'cannot launch PHP');
    fclose($pipes[0]);
    $exit = proc_close($process);
    rewind($out); rewind($err);
    $stdout = stream_get_contents($out); $stderr = stream_get_contents($err);
    fclose($out); fclose($err);
    $label = implode(' ', $arguments);
    boundary_check($exit === $expected_exit, "$label: exit $exit, expected $expected_exit; stdout=$stdout stderr=$stderr");
    if (!$json) { return ['stdout' => $stdout, 'stderr' => $stderr]; }
    $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    boundary_check($stderr === '', "$label: JSON invocation leaked stderr: $stderr");
    boundary_check($report['exit_code'] === $exit, "$label: report/exit mismatch");
    boundary_check(($report['errors'] !== []) === ($exit === 2), "$label: runner errors must explain exit 2 only");
    return $report;
}
function boundary_remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $item) { if ($item !== '.' && $item !== '..') { boundary_remove("$path/$item"); } }
        rmdir($path);
    } else { unlink($path); }
}

$directory = sys_get_temp_dir() . '/tinytest boundaries ' . bin2hex(random_bytes(8));
mkdir($directory);
try {
    $good = boundary_fixture($directory, 'good', 'function test_good(){ assert_eq(1,1,"ok"); }');
    $empty = boundary_fixture($directory, 'empty', '// no tests');
    $broken = boundary_fixture($directory, 'broken', 'function syntax error {');
    $bootstrap = boundary_fixture($directory, 'bootstrap', 'file_put_contents(__DIR__."/bootstrap-ran", "yes"); echo "bootstrap noise";');
    foreach ([
        [], ['-f'], ['-d'], ['-t'], ['--unknown'], ['-z'], ['-f', $good, 'trailing'],
        ['-f', "$directory/missing"], ['-f', $directory], ['-d', "$directory/missing"],
        ['-d', $good], ['-f', $good, '-d', $directory], ['-d', $directory, '-d', $directory],
        ['-f', $good, '-b', "$directory/missing"], ['-f', $good, '-t', 'test_missing'],
        ['-f', $good, '-t', 'test_missing', '--allow-empty'], ['-f', $good, '-i', 'absent'],
        ['-f', $good, '-t', 'test_good', '-t', 'test_good'], ['-f', $empty], ['-f', $empty, '-l'],
    ] as $arguments) { boundary_run($arguments, 2); }
    boundary_run(['-f', $empty, '--allow-empty'], 0);
    boundary_run(['-f', $good, '-i', 'absent', '--allow-empty'], 0);
    boundary_run(['-f', $good, '-f', $good], 0);
    boundary_run(['-f', "$directory/missing"], 2, ['zend.assertions' => '-1']);
    boundary_run(['-f', $good], 0, ['zend.assertions' => '-1']);
    boundary_run(['-f', $good], 0, ['user_agent' => 'agent=a;b']);
    boundary_run(['-f', $good], 2, ['disable_functions' => 'proc_open']);

    boundary_run(['-f', "$directory/missing", '-b', $bootstrap], 2);
    boundary_run(['-h', '-b', $bootstrap], 0);
    boundary_check(!file_exists("$directory/bootstrap-ran"), 'invalid invocation/help executed project bootstrap');
    $report = boundary_run(['-f', $good, '-b', $bootstrap], 0);
    boundary_check(strpos($report['output']['stdout'], 'bootstrap noise') !== false, 'bootstrap output not captured');
    boundary_run(['-f', $good, '-b', $broken], 2);
    boundary_run(['-f', $broken], 2);
    $console = boundary_run(['-f', "$directory/missing"], 2, [], null, false);
    boundary_check(strpos($console['stderr'], 'not a readable file') !== false, 'console missing-file diagnostic');
    $console = boundary_run(['-f', $broken], 2, [], null, false);
    boundary_check(strpos($console['stderr'], 'syntax error') !== false, 'console parse-error diagnostic');

    $noisy = boundary_fixture($directory, 'noisy', <<<'PHP'
echo 'load echo'; fwrite(STDOUT, 'load direct');
function data_noise(){ echo 'provider echo'; fwrite(STDOUT, 'provider direct'); return [1]; }
/** @dataprovider data_noise */
function test_noise($value){ echo 'case echo'; fwrite(STDOUT, 'case direct'); assert_eq($value,1,'ok'); }
register_shutdown_function(function(){ echo 'shutdown echo'; fwrite(STDOUT,'shutdown direct'); });
$GLOBALS['_tinytest_cleanup'][] = function(){ echo 'cleanup echo'; };
PHP
    );
    $report = boundary_run(['-f', $noisy], 0);
    foreach (['load echo', 'load direct', 'provider echo', 'provider direct', 'case direct', 'shutdown echo', 'shutdown direct', 'cleanup echo'] as $text) {
        boundary_check(strpos($report['output']['stdout'], $text) !== false, "lost output: $text");
    }
    boundary_check($report['tests'][0]['output'] === 'case echo', 'case echo must remain associated with the case');
    $binary = boundary_fixture($directory, 'binary', 'echo "\xff"; function test_bytes(){ assert_eq(1,2,"bad byte \xff"); }');
    $report = boundary_run(['-f', $binary], 1);
    boundary_check(strpos($report['tests'][0]['error']['message'], "\xef\xbf\xbd") !== false, 'invalid UTF-8 not replaced safely');
    $large = boundary_fixture($directory, 'large', 'fwrite(STDOUT,str_repeat("x",200000)); function test_large(){assert_true(true,"ok");}');
    $report = boundary_run(['-f', $large], 0);
    boundary_check(strlen($report['output']['stdout']) === 65536 && $report['output']['stdout_truncated'], 'unbounded stdout preview');

    foreach ([
        'load_exit' => 'echo "before exit"; exit(0);',
        'case_exit' => 'function test_exit(){fwrite(STDOUT,"before exit"); exit(0);}',
        'shutdown_error' => 'function test_good(){assert_true(true,"ok");} register_shutdown_function(function(){throw new Error("shutdown broke");});',
        'cleanup_error' => 'function test_good(){assert_true(true,"ok");} $GLOBALS["_tinytest_cleanup"][] = function(){echo "cleanup"; throw new Error("cleanup broke");};',
        'load_warning' => 'trigger_error("load warning", E_USER_WARNING); function test_good(){assert_true(true,"ok");}',
        'load_caught_assertion' => 'try { assert_eq(1,2,"load failure"); } catch (Throwable $e) {} function test_good(){assert_true(true,"ok");}',
    ] as $name => $code) {
        boundary_run(['-f', boundary_fixture($directory, $name, $code)], 2);
    }
    $nonremovable = boundary_fixture($directory, 'nonremovable', 'function test_buffer(){ob_start(null,0,0); echo "trapped"; assert_true(true,"ok");}');
    boundary_run(['-f', $nonremovable], 1);

    $cases = [
        'caught' => ['try {assert_eq(1,2,"caught");} catch(Throwable $e) {} assert_true(true,"later success");', 1],
        'counter_reset' => ['try {assert_eq(1,2,"caught");} catch(Throwable $e) {} $GLOBALS["assert_fail_count"]=0;', 1],
        'expected_failure' => ['assert_fails(fn()=>assert_eq(1,2,"intentional"),"should fail");', 0],
        'helper_no_failure' => ['try {assert_fails(fn()=>null,"missing failure");} catch(Throwable $e) {}', 1],
        'helper_does_not_clear_previous' => ['try {assert_eq(1,2,"earlier failure");} catch(Throwable $e) {} assert_fails(fn()=>assert_eq(1,2,"intentional"),"check");', 1],
        'helper_other_throwable' => ['assert_fails(function(){throw new RuntimeException("wrong kind");},"check");', 1],
        'nested_helper' => ['assert_fails(fn()=>assert_fails(fn()=>null,"no failure"),"inner helper must fail");', 0],
        'warning' => ['trigger_error("warning",E_USER_WARNING); assert_true(true,"ok");', 1],
        'suppressed_warning' => ['@trigger_error("warning",E_USER_WARNING); assert_true(true,"ok");', 0],
    ];
    foreach ($cases as $name => [$body, $exit]) {
        $file = boundary_fixture($directory, $name, 'function test_case(){' . $body . '}');
        boundary_run(['-f', $file], $exit, ['log_errors' => '0', 'display_errors' => '1']);
    }
    $report = boundary_run(['-f', "$directory/expected_failure.php"], 0);
    boundary_check($report['summary']['assertions'] === ['total'=>1,'passed'=>1,'failed'=>0], 'assert_fails must count one isolated check');
    $expected = boundary_fixture($directory, 'php_error', "/** @phperror E_USER_WARNING */\nfunction test_expected(){trigger_error('warning',E_USER_WARNING);}");
    $report = boundary_run(['-f', $expected], 0);
    boundary_check($report['tests'][0]['assertions'] === 1, 'expected PHP error must count as a check');
    boundary_run(['-f', $expected, '-s'], 1); // Suppression cannot fabricate an expected error.
    $missing_error = boundary_fixture($directory, 'missing_php_error', "/** @phperror E_WARNING */\nfunction test_expected(){assert_true(true,'other assertion');}");
    boundary_run(['-f', $missing_error], 1);
    $multiple_errors = boundary_fixture($directory, 'multiple_errors', "/**\n * @phperror E_USER_WARNING\n * @phperror E_USER_NOTICE\n */\nfunction test_expected(){trigger_error('warning',E_USER_WARNING);trigger_error('notice',E_USER_NOTICE);}");
    $report = boundary_run(['-f', $multiple_errors], 0);
    boundary_check($report['tests'][0]['assertions'] === 2, 'each PHP error expectation must count once');
    $mask_leak = boundary_fixture($directory, 'mask_leak', "function test_mask(){error_reporting(0);assert_true(true,'ok');}\nfunction test_next(){trigger_error('must not be suppressed',E_USER_WARNING);assert_true(true,'ok');}");
    $report = boundary_run(['-f', $mask_leak], 1);
    boundary_check(array_column($report['tests'], 'status') === ['OK', 'FAIL'], 'PHP diagnostic mask leaked between cases');
    $cleanup = boundary_fixture($directory, 'cleanup_continue', 'function test_ok(){assert_true(true,"ok");} $GLOBALS["_tinytest_cleanup"][] = function(){try{assert_eq(1,2,"caught cleanup failure");}catch(Throwable $e){}}; $GLOBALS["_tinytest_cleanup"][] = function(){echo "second cleanup ran";};');
    $report = boundary_run(['-f', $cleanup], 2);
    boundary_check(strpos($report['output']['stdout'], 'second cleanup ran') !== false, 'one cleanup failure skipped subsequent callbacks');
    $provider_error = boundary_fixture($directory, 'provider_warning', "function data(){trigger_error('provider warning',E_USER_WARNING);return [1];}\n/** @dataprovider data */\nfunction test_data(\$value){assert_true(true,'case passed');}");
    boundary_run(['-f', $provider_error], 1);

    mkdir("$directory/discovery");
    mkdir("$directory/discovery/test_directory.php");
    file_put_contents("$directory/discovery/test_notphp", '<?php exit(0);');
    boundary_fixture("$directory/discovery", 'test_real', 'function test_real(){assert_true(true,"discovered");}');
    $report = boundary_run(['-d', "$directory/discovery"], 0);
    boundary_check($report['summary']['total'] === 1, 'directory scan selected a non-file or wrong extension');
    boundary_run(['-f', $good, '-f', "$directory/discovery/test_real.php", '-a'], 2);
    boundary_run(['-f', $good, '-f', "$directory/discovery/test_real.php", '-a', '-b', $bootstrap], 0);
    boundary_fixture($directory, 'user_defined', 'echo "override noise";');
    $report = boundary_run(['-f', $good], 0, [], $directory);
    boundary_check(strpos($report['output']['stdout'], 'override noise') !== false, 'project override output not captured');
    $report = boundary_run(['--help'], 0, [], $directory);
    boundary_check(!isset($report['output']), 'help loaded project overrides');
    boundary_run(['-f', $good, '-c'], 2); // This harness runs under PHP CLI, not phpdbg.
    if (!function_exists('tideways_enable')) { boundary_run(['-f', $good, '-p'], 2); }
    echo "Boundary contracts passed (validation, sticky assertions, PHP errors, JSON isolation).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Boundary contract failure: ' . $error->getMessage() . "\n");
    $status = 1;
} finally {
    boundary_remove($directory);
}
exit($status ?? 0);
