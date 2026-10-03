# Integrate TinyTest as a server-owned agent tool

## Goal and boundaries

Expose synchronous PHP test execution through your existing `AgentTool` registry.
The caller receives an array, not printed output; TinyTest never exits the host
request. Tests run in fresh PHP CLI subprocesses, with captured output and a
server-configured wall-clock deadline (default **30 seconds**).

Use trusted project code only. This is process isolation, **not a security sandbox**.
A timeout kills the invocation's dedicated POSIX process group: the CLI supervisor,
JSON worker, and ordinary descendants. Descendants that deliberately detach using
`setsid()`/`setpgid()` can escape; use a container/cgroup if containment of untrusted
or daemonizing code is required. SIGKILL bypasses PHP cleanup and shutdown handlers;
external side effects are not rolled back. OS-uninterruptible tasks may take longer
to reap. Put host-level request/resource limits above the tool's deadline.

## 1. Install and preflight

Keep `tinytest.php`, `assertions.php`, `user_defined.php`, and **all of `src/`**
together. No Composer dependency is required. PHP 7.4+ syntax; verified on CLI 8.5.

Requirements for this adapter:
- POSIX OS (Linux recommended); Windows is explicitly unsupported.
- Host PHP: `proc_open`, `proc_get_status`, `proc_terminate`, `proc_close`, `posix_kill`.
- Child PHP CLI: POSIX extension with `posix_setsid`, plus `proc_open` for JSON worker.
- Writable temporary storage and read permissions for framework/project files.
- Configure a real PHP **CLI** executable; `PHP_BINARY` in FPM may point to FPM.

CLI INI/extensions come from that executable, not the web host's SAPI configuration.
Ensure application dependencies and required extensions are installed for CLI.
Report/stream reads are bounded, but temporary output files have no disk quota.
Apply deployment-level memory/disk/CPU limits. Do not put secrets or credentials
into agent-visible reports unnecessarily: test output/error messages are returned.

## 2. Load the host interface and adapter

The adapter implements the host's **global** `AgentTool` interface:

```php
interface AgentTool
{
    public function name(): string;
    public function definition(): array;
    public function execute(array $args): array;
}
```

Use your existing interface; do not redeclare it if already loaded. If your host
interface is namespaced, bridge it with an application-owned adapter/delegator or
an interface alias before loading TinyTest. Do not modify the CLI to detect imports.

```php
// Your host's AgentTool interface must already be loaded here.
require_once '/opt/tinytest/src/agent_tool.php';

$tool = new \TinyTest\TestRunnerTool(
    '/srv/my-project',          // absolute, trusted project root
    '/usr/bin/php',             // server-selected CLI executable
    'tests/bootstrap.php',     // optional project-relative bootstrap; null to omit
    30.0                       // positive finite wall-clock limit in seconds
);
$registry[$tool->name()] = $tool;  // name: tinytest_run
```

Do not include `tinytest.php` in the host: it is an executable that exits.
Do not load `src/bootstrap.php` for this integration. The adapter does not initialize
assertions, counters, handlers, INI settings, or project code in the host process.
Invalid constructor configuration throws `InvalidArgumentException`; handle it as
a server configuration error. `execute()` errors are returned as arrays.

Root, PHP executable, bootstrap, and deadline are **server-owned configuration**,
not model-controlled tool arguments. Bind the project root to the authenticated
workspace and authorize invocation in the host. A path check is not authorization.

## 3. Register and dispatch Responses API calls

`definition()` returns the flat Responses API function-tool definition, including
`type: function`, `name`, `description`, `strict: true`, and its JSON schema. Do not
wrap it in a Chat Completions-style `function` object.

```php
$tools = [$tool->definition()]; // send as the Responses API tools array

// Inside your existing function-call dispatch loop:
$name = $call['name'];
if (!isset($registry[$name])) {
    throw new RuntimeException('Unknown tool');
}
$args = json_decode($call['arguments'], true, 512, JSON_THROW_ON_ERROR);
if (!is_array($args)) {
    throw new InvalidArgumentException('Tool arguments must be an object');
}
$result = $registry[$name]->execute($args);
$functionOutput = [
    'type' => 'function_call_output',
    'call_id' => $call['call_id'],
    'output' => json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
];
// Append/send $functionOutput in your agent loop and continue the response.
// Do not echo the result from the tool implementation.
```

Keep your existing handling for malformed call JSON, unknown tool names, API errors,
authentication and conversation continuation. The snippet is dispatch wiring, not
a complete API client. Execution is blocking/synchronous; provision worker capacity
and prevent overlapping calls from racing over the same external test resources.

## 4. Tool arguments

All four keys are required; no extra keys are accepted:

```json
{"directory":"tests","files":[],"test":null,"list":false}
```

```json
{"directory":null,"files":["tests/test_parser.php"],"test":"test_parse_header","list":false}
```

```json
{"directory":"tests","files":[],"test":null,"list":true}
```

- Choose a directory **or** a nonempty list of files, not both.
- Paths are project-relative and must resolve inside the project, including symlinks.
- Directory discovery is shallow: immediate `test_*.php` files only.
- `test` is one exact global function name, or null. There is no dataset selector.
- `list` loads PHP declarations but does not execute test bodies/providers.
- Bundled `user_defined.php`, project-root `user_defined.php`, and configured
  bootstrap run before test discovery. Bootstrap is not auto-detected by this tool.
- No arbitrary CLI flags, shell commands, profiling, or coverage arguments are exposed.

## 5. Interpret the result

Always check `exit_code`, not just `summary.failed`:
- **0:** successful execution or listing.
- **1:** failed or incomplete tests.
- **2:** invalid selection, runner/tool error, or wall-clock timeout.

Normal transport returns the v12 CLI report: `tests`, `summary`, `errors`,
`exit_code`, and optional captured `output`. Test statuses are `OK`, `FAIL`, `IN`,
`SKIP`, `TODO`. Counts are **functions**, not datasets; assertion totals live under
`summary.assertions`. Test failures appear in `tests[].error`; runner errors in
root `errors`. Buffered test output is in `tests[].output`; other captured streams
are under `output.stdout`/`output.stderr`. A worker `exit(0)` without its report is
an error, not success.

Tool-level errors may have **no `summary` or `tests`**. Timeout example:

```json
{
  "exit_code": 2,
  "timed_out": true,
  "timeout_seconds": 30,
  "errors": [{
    "kind": "timeout",
    "message": "TinyTest exceeded the 30 second wall-clock limit. The test process group was sent SIGKILL (supervisor, worker and descendants). No complete test results are available. Run a narrower selection or investigate a hanging bootstrap, test, provider or shutdown handler; the server owns the timeout setting."
  }]
}
```

The deadline covers launcher startup, bootstrap, providers, test bodies, cleanup,
and shutdown—not just individual cases. It applies to listing too. Timeout returns
no invented totals or partial success: the JSON supervisor may not have finished
capturing results. Narrow the selection and investigate hangs before retrying;
do not blindly raise the timeout. The host can configure another positive finite
limit when a suite legitimately requires more time. Normal completion also kills
remaining background descendants in the invocation's process group.

Other tool errors use `errors[].kind: tool` with a readable message, sometimes
bounded stdout/stderr previews and `process_exit_code` for interpreter failures.

## 6. Teach the calling agent to write tests

Give the agent `tinytest.php` as its compact usage reference. That file contains
the full assertion/annotation/CLI guide; implementation-module reading is unnecessary.
A suitable system/project instruction is:

> Use TinyTest global test functions, not PHPUnit classes. Read the guide in
> tinytest.php before writing tests. Save test_*.php files under tests/; use
> test_*, it_* or should_* global function names and unique helper names.
> Assertions take actual before expected, haystack before needle, message last.
> Use assert_array_has for strict membership. Every dataset needs an assertion
> or matched exception/PHP-error expectation. A caught assertion failure stays
> failed; use assert_fails for intentional assertion-failure checks. Native PHP
> assert() is not counted. Call tinytest_run for execution, inspect exit_code and
> errors as well as test outcomes, and report timeouts as runner failures, not
> passing or completed suites. Do not include the TinyTest executable in tests.

Example `tests/test_math.php`:

```php
<?php declare(strict_types=1);
function test_addition(): void {
    assert_eq(1 + 1, 2, 'adds integers');
}
```

Application loading belongs in the configured bootstrap or test files using
`__DIR__`-relative `require_once`; bootstrap should not define test functions.
Tests share child-process globals. Use explicit helper setup and `try/finally`
cleanup; no implicit setUp/tearDown methods exist.

## 7. Acceptance checks in your host deployment

Run from the TinyTest installation with the configured CLI PHP:

```sh
php tests/agent_tool_contracts.php
php tests/agent_timeout_contracts.php
php tests/entry_guide_contracts.php
php -d zend.assertions=1 tinytest.php -j -d tests/
php tests/cli_contracts.php
php tests/boundary_contracts.php
```

Then exercise the actual agent host, not only CLI:
1. Register the definition, dispatch a passing test, and consume its function output.
2. Verify assertion failure and assertion-free tests return exit 1.
3. Verify bad paths/selectors return exit 2 without host output or termination.
4. Verify a hanging test/descendant is killed and returns `timed_out: true`.
5. Run another passing test through the same tool object afterward.
6. Confirm host stdout/stderr, CWD, globals and PHP settings remain unaffected.

Do not declare deployment ready until process-group support and the configured
CLI path work under the real service user's permissions and PHP configuration.
