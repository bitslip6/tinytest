<?php declare(strict_types=1);
echo 'file noise';
register_shutdown_function(function () { echo 'shutdown noise'; });

function test_pass(): void
{
    assert_eq(2 + 2, 4, 'basic assertion');
    echo 'body noise';
}
function test_failure(): void { assert_eq(1, 2, 'deliberate failure'); }
function test_incomplete(): void {}
function test_exit(): void { exit(0); }
function test_shutdown_failure(): void
{
    assert_true(true, 'test body passes before shutdown failure');
    register_shutdown_function(function () { throw new RuntimeException('deliberate shutdown failure'); });
}
function test_host_isolation(): void
{
    $GLOBALS['fpm_driver_marker'] = 'changed by worker';
    ini_set('precision', '7');
    chdir(sys_get_temp_dir());
    header('X-TinyTest-Fixture: must-not-leak');
    assert_true(true, 'changes remain in worker request');
    echo 'isolated output';
}
function test_hang(): void
{
    // Intentionally defeat client disconnects and PHP's script timer. Only the
    // external process manager's wall-clock limit should stop this fixture.
    ignore_user_abort(true);
    set_time_limit(0);
    $directory = $GLOBALS['fpm_test_state'];
    file_put_contents($directory . '/hang.pid', (string) getmypid());
    register_shutdown_function(function () use ($directory) {
        file_put_contents($directory . '/hang.shutdown', 'PHP shutdown ran');
    });
    $tick = 0;
    while (true) {
        file_put_contents($directory . '/heartbeat.next', (string) ++$tick);
        rename($directory . '/heartbeat.next', $directory . '/heartbeat');
        usleep(100000);
    }
}
