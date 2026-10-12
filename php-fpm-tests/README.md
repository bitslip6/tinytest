# Actual nginx + PHP-FPM end-to-end probe

Point nginx at this directory's **two explicit entry points**, using the supplied
configuration examples. The test driver calls the HTTP tool over real HTTP into a
separate FPM pool. Neither PHP endpoint needs `proc_open`, `exec`, POSIX or shell
access. The driver needs PHP cURL. The complete TinyTest checkout must be readable.

**Do not use production pools.** One fixture intentionally runs forever, ignores
client disconnects and disables the PHP script timer. Only the dedicated worker
pool's external `request_terminate_timeout` should stop it. If termination is not
configured, the probe reports failure but cannot kill that remote request itself.

## Deploy

1. Copy/adapt `php-fpm.conf.example` into your FPM pool configuration. Set the
   service user/group/socket permissions and replace both token placeholders with
   the same randomly generated secret (at least 32 characters). Do not commit it.
2. Create `/var/tmp/tinytest-fpm-tests` outside the web root, writable by both pools.
   Use the same service user for driver and worker: per-run directories are 0700.
3. Copy/adapt `nginx.conf.example` into nginx's HTTP configuration. Replace the
   absolute checkout path. Only `/fpm-tests` and `/fpm-worker` are exposed; fixtures
   and configuration files must NOT be public URLs.
4. Validate/reload nginx and PHP-FPM using your platform's administration tooling.
   The examples bind nginx to loopback port 8099. Use a local browser or SSH tunnel.
   Remote exposure requires HTTPS plus access controls, not public plaintext.
5. Open `http://127.0.0.1:8099/fpm-tests`, enter the test bearer token and press Run.
   Or POST with an HTTP client:

   ```sh
   curl --fail-with-body \
     -H "Authorization: Bearer $TINYTEST_FPM_TEST_TOKEN" \
     -X POST http://127.0.0.1:8099/fpm-tests
   ```

   HTTP 200 means the driver returned a result, not that tests passed. Require
   root **`"ok": true`** and every `checks[].ok` true. Browser output is the same JSON.

The driver must use a **different pool** from the worker: synchronous requests into
an exhausted shared pool deadlock. Both endpoints require FPM for this acceptance
probe; this is not a replacement for the development-server HTTP contract tests.

## Configuration

Driver environment:

| Variable | Purpose |
|---|---|
| `TINYTEST_FPM_TEST_TOKEN` | Shared bearer secret, at least 32 characters |
| `TINYTEST_FPM_TEST_WORKER_URL` | Fixed worker URL, no query string |
| `TINYTEST_FPM_TEST_ALLOW_HTTP=1` | Permit loopback plaintext; omit for HTTPS |
| `TINYTEST_FPM_TEST_STATE` | Shared existing writable directory outside web root |
| `TINYTEST_FPM_TEST_HARD_SECONDS=3` | Expected worker FPM limit, 1–10 seconds |
| `TINYTEST_FPM_TEST_ENABLE_HANG=1` | Explicit permission to run the infinite-loop fixture |

Worker environment: the same `TOKEN` and `STATE` variables. `worker.php` fixes its
project root to `fixtures/` and bootstrap to `fixtures/bootstrap.php`; a caller cannot
select your application as its project. PHP-FPM must explicitly forward the env values.
Use matching `request_terminate_timeout` and `TINYTEST_FPM_TEST_HARD_SECONDS` values.

The sample worker FPM limit is **3 seconds**, HTTP client limit **6 seconds**, worker
nginx read timeout **8 seconds**, driver FPM limit **60 seconds**, and driver nginx
read timeout **65 seconds**. The shorter FPM limit—not the HTTP client's timeout—must
terminate the request. FPM checks its timeout periodically, so allow for scheduling
jitter. The probe tolerates roughly 3 seconds of extra response time. If your platform
exceeds that, diagnose timing rather than interpreting a client timeout as success.

`pm.max_requests=1` recycles the OS process after each request. Both sample pools
have process-launch functions disabled, proving that this deployment needs none.
Do not disable `set_time_limit` in the fixture pool: the deliberate hang calls it to
ensure PHP's script timer is not mistaken for FPM's wall-clock termination.

## What the probe checks

- Invalid bearer token rejected **before** project bootstrap executes.
- Driver and worker are actual PHP-FPM requests.
- Passing, failing and assertion-free cases return exits 0, 1 and 1 respectively.
- Body/bootstrap/file/shutdown output is captured instead of leaking into the host.
- Test `exit(0)` and shutdown exception are reported as runner errors.
- Listing does not execute bodies (especially the hanging fixture).
- Unmatched selector and path outside the worker project are rejected.
- Host globals, CWD, INI, error reporting and headers remain unchanged.
- Deliberate infinite loop returns an error, its **heartbeat stops changing**, and
  no PHP shutdown marker was written; response timing matches the configured FPM limit.
- Another passing request succeeds after the terminated request.

The hanging request writes its PID and an atomic heartbeat in the run's state
directory. The driver samples the heartbeat after HTTP returns and again 1.5 seconds
later. Merely reaching the client timeout does NOT pass this test. To attribute the
kill definitively to FPM, also inspect the **worker FPM error log** for the recorded PID
and a request execution timeout/termination message. An external OS kill could produce
the same stopped-heartbeat observation; PHP cannot inspect the FPM manager's decision.

## Results and cleanup

```json
{
  "ok": true,
  "run_id": "random-32-character-hex-id",
  "duration_seconds": 5.2,
  "artifacts_retained": false,
  "checks": [{"name":"worker_heartbeat_stopped","ok":true,"detail":{}}]
}
```

Success removes that run's artifacts. Failure retains them under
`$TINYTEST_FPM_TEST_STATE/<run_id>/` for diagnosis; paths/IDs are never accepted from
the agent as arbitrary filesystem locations. The probe can be run again; every run
has a new random ID. Avoid concurrent runs while assessing timeout behavior.

**If `worker_heartbeat_stopped` fails, stop/restart the dedicated worker pool
immediately.** The infinite loop may still be active. Do not keep clicking Run.
Inspect the retained `hang.pid`, `heartbeat`, `hang.shutdown`, `loaded`, and `sapi`
files and FPM/nginx logs. Remove old state directories after stopping stray workers.
Never expose the state directory through nginx.

Remove these test locations/pools and unset the test token after acceptance. Integrate
your real project endpoint using `new-agents.md`; do not use the deliberately failing
fixtures as your application suite.

## GitHub Actions acceptance

[The nginx/FPM workflow](../.github/workflows/fpm.yml) runs this probe on PHP 8.5 for pull requests, pushes to `master`, merge-queue groups and manual dispatch. It explicitly enables the `ondrej/php` APT repository for PHP 8.5 FPM/cURL packages (Ubuntu 24.04's default repositories do not provide them), installs nginx/FPM on an ephemeral Linux runner, then runs isolated instances as the runner user, not the package's system services. The existing configuration examples are rendered into private temporary configs with an ephemeral token, separate driver/worker sockets and a shared state directory. Only loopback HTTP is exposed.

The job requires root `ok: true`, every probe check to pass, and an FPM error-log line attributing execution timeout/termination to the exact hanging worker PID. The artifact contains `report.json`, `termination-confirmed.txt`, service/config-test logs and any retained failure state; token-bearing configs are never uploaded. Script exit (including failure or a handled termination signal) stops both service process groups and removes private configs. Forced runner termination ultimately relies on the ephemeral VM's destruction.

To run the same acceptance automation locally on Linux with nginx, PHP-FPM, cURL, Python 3 and `setsid` installed:

```sh
FPM_BIN=php-fpm bash .github/scripts/fpm-integration.sh /tmp/tinytest-fpm-artifacts
```

Run as a non-root user with a readable checkout. This does not modify/reload system services. `FPM_BIN` can select a versioned executable (e.g. `php-fpm8.5`); `FASTCGI_PARAMS` defaults to `/etc/nginx/fastcgi_params`, and `FPM_TEST_PORT` defaults to loopback port 8099. Configuration paths must have no whitespace or configuration metacharacters. Use a fresh output directory for each run to avoid stale diagnostics. Inspect artifacts on failure before removing them.

## Repository verification vs deployment verification

Lint these PHP files with `php -l`. Existing `tests/http_agent_contracts.php` tests the
HTTP implementation with a development server. The CI acceptance job checks a real nginx/FPM stack with temporary configuration. Repeat the probe on **your own nginx/FPM deployment** to test its service identity, permissions, routing, timeouts and recovery. Neither a lint pass nor a CI deployment pass certifies your host's configuration.

Locally verified with nginx 1.30.4 and PHP-FPM 8.5.10 using temporary instances of
these configurations: all 18 checks passed. The 3-second FPM policy terminated the
hanging worker at approximately 3.98 seconds; its heartbeat stopped, and the FPM
log confirmed that PID's execution timeout and termination. No system service
configuration was changed. Repeat the probe on your own deployment.
