# TinyTest

### PHP testing designed for agentic workflows

A small, zero-required-library PHP test framework built for speed, simplicity, and AI-assisted development. Write tests as plain functions, run thousands in under a second, and let your coding agent generate, improve, and debug tests using built-in skills.

## Features

* **Small distribution** — copy the framework directory (`tinytest.php`, `assertions.php`, `user_defined.php`, and `src/`); no Composer required
* **Fast** — run thousands of tests in under a second
* **Agentic coding** — ships with skills for test generation, coverage analysis, and bug hunting
* **Code coverage** — generates `lcov.info` for editor integration _(requires phpdbg)_
* **Profiling** — xhprof and callgrind output _(requires xhprof/tideways)_
* **Functional style** — no classes to extend, tests are plain functions
* **JSON output** — machine-readable results for CI and agent consumption
* **Extensible** — override formatting, test selection, and assertions at runtime

[![asciicast](https://asciinema.org/a/pEnyZFEObOr2HWStjcM0SRtjb.svg)](https://asciinema.org/a/pEnyZFEObOr2HWStjcM0SRtjb)


## Install

```shell
git clone https://github.com/bitslip6/tinytest
# Optional: install phpdbg for code coverage
sudo apt install php-phpdbg   # Debian/Ubuntu
```

## Quick Start

### With an AI coding agent (recommended)

The setup script installs TinyTest skills and agent instructions into your project:

```shell
cd ~/projects/my-app
/path/to/tinytest/agent-integration/setup.sh .
```

This creates:
- `CLAUDE.md` with TinyTest conventions and skills reference
- `.claude/skills/` with skills for generating, running, and fixing tests
- `.claude/settings.local.json` with permission to run tinytest
- A `tinytest` shell alias in your `.bashrc`/`.zshrc`

Then use your agent to generate tests:

```shell
claude -p '/test-generate src/Parser.php'
```

### Manual setup

Create a shell alias:

```shell
# With phpdbg (enables code coverage):
alias tinytest='phpdbg -q -d xdebug.mode=off -rr -e /path/to/tinytest.php'

# Without phpdbg:
alias tinytest='php /path/to/tinytest.php'
```

Write a test:

```php
<?php declare(strict_types=1);

function test_hello_world(): void {
    assert_eq(1 + 1, 2, "basic math works");
}
```

Run it:

```shell
tinytest -f tests/test_hello_world.php
```


## Writing Tests

Tests are plain PHP functions in global namespace. No classes, no inheritance.

### Test file conventions

- **File names** start with `test_` and end with `.php` (e.g., `test_parser.php`)
- **Test functions** are prefixed with `test_`, `it_`, or `should_`
- **A test case with no TinyTest assertions is marked incomplete** (`IN`) and causes exit code 1. A matched `@exception` counts as one successful check. Native PHP `assert()` calls are not counted; use TinyTest assertions instead.

```php
<?php declare(strict_types=1);

require_once __DIR__ . '/../src/Parser.php';

function test_parse_returns_array(): void {
    $result = parse("key=value");
    assert_eq($result['key'], 'value', "should parse key=value pair");
}

function it_handles_empty_input(): void {
    $result = parse("");
    assert_empty($result, "empty input produces empty result");
}

function should_reject_malformed_input(): void {
    assert_false(parse(null), "null input returns false");
}
```

### Test setup

There is no magic `setUp` method. Create helper functions and call them:

```php
<?php declare(strict_types=1);

require_once __DIR__ . '/../src/MyObject.php';

function make_object(): MyObject {
    return new MyObject("default args");
}

function test_object_does_stuff(): void {
    $obj = make_object();
    $result = $obj->does_stuff("input");
    assert_neq($result, null, "does_stuff should return a value");
}
```

### Bootstrap files

Paths and backend requirements are validated before project code runs. `-b` loads one explicit bootstrap; `-a` finds `bootstrap.php` beside the selected files/directory. Explicit `-b` takes precedence; `-a` across multiple directories requires an explicit bootstrap. Parsing and `--help` never execute a bootstrap or project overrides. Use either `-d` or repeatable `-f`, not both.

For shared initialization (autoloaders, constants, database connections), create a bootstrap file:

```shell
# Explicit bootstrap:
tinytest -b tests/bootstrap.php -d tests/

# Auto-load tests/bootstrap.php if it exists:
tinytest -a -d tests/
```


## Assertions

All assertions are plain global functions. Prefer **actual before expected**, **haystack before needle**, **message last**. The legacy `assert_array_contains` keeps its historical needle-first order; use `assert_array_has` for the consistent, strict API. A few assertions retain an optional debug-output argument after the message.

Add custom assertions to `user_defined.php` — see [Custom Assertions](#custom-assertions) below.

### Equality

| Assertion | Description |
|-----------|-------------|
| `assert_eq($actual, $expected, "msg")` | Strict equality (`===`) |
| `assert_neq($actual, $expected, "msg")` | Strict inequality (`!==`) |
| `assert_eqic($actual, $expected, "msg")` | Case-insensitive string equality |
| `assert_identical($actual, $expected, "msg")` | Strict equality for non-objects; structural comparison for objects |

### Comparison

| Assertion | Description |
|-----------|-------------|
| `assert_true($condition, "msg")` | Truthy check |
| `assert_false($condition, "msg")` | Falsy check |
| `assert_gt($actual, $expected, "msg")` | Greater than (`>`) |
| `assert_lt($actual, $expected, "msg")` | Less than (`<`) |

### Strings

| Assertion | Description |
|-----------|-------------|
| `assert_contains($haystack, $needle, "msg")` | String contains substring |
| `assert_not_contains($haystack, $needle, "msg")` | String does not contain substring |
| `assert_icontains($haystack, $needle, "msg")` | Case-insensitive string contains |
| `assert_matches($actual, $pattern, "msg")` | Matches regex pattern |
| `assert_not_matches($actual, $pattern, "msg")` | Does not match regex pattern |

### Collections

| Assertion | Description |
|-----------|-------------|
| `assert_count($actual, $expected, "msg")` | Count of countable equals expected |
| `assert_empty($actual, "msg")` | Value is empty |
| `assert_not_empty($actual, "msg")` | Value is not empty |
| `assert_array_has($haystack, $needle, "msg")` | Strict array membership (`in_array` with strict mode) |
| `assert_array_contains($needle, $haystack, "msg")` | Legacy needle-first, loose array membership; retained for compatibility |

### Objects & Types

| Assertion | Description |
|-----------|-------------|
| `assert_instanceof($actual, ClassName::class, "msg")` | Object is instance of class |
| `assert_object($actual, $expected, "msg")` | Strict structural object comparison |

Object comparison requires the same class and all initialized stored properties (including private/protected properties) to match recursively with strict types. Extra properties fail; property insertion order is ignored. Nested arrays require matching keys, key order, and values. Object cycles are supported, but recursive arrays or comparisons deeper than 100 levels fail closed. Distinct internal PHP objects (except `stdClass`), including internal-class subclasses, are not compared structurally: compare extracted values instead. Identical object references always match. Uninitialized properties are absent from the stored-property comparison.

`assert_true` rejects every PHP falsey value (`false`, `0`, `0.0`, `""`, `"0"`, `null`, `[]`). Both regex assertions fail on invalid patterns or regex execution errors; an invalid regex cannot satisfy `assert_not_matches`.

### Intentionally testing assertion failures

A failed TinyTest assertion **still fails its case if caught**. Later passing assertions or resetting reporting counters cannot clear that outcome. Use the explicit helper when testing assertions themselves:

```php
$error = assert_fails(
    fn() => assert_eq(1, 2, "intentional failure"),
    "assert_eq must reject different values"
);
assert_contains($error->getMessage(), "intentional failure", "failure explains why");
```

`assert_fails($callback, $message)` requires a thrown `TinyTest\TestError`, isolates only the callback's assertion state/counters, and counts one successful check. Other throwable types propagate; a callback that returns normally fails the helper. Unrelated earlier failures remain failures. Custom assertions should use `assert_base_condition`, or call `TinyTest\count_assertion_fail($error)` before throwing their `TestError`.

### Optional verbose output

`assert_true`, `assert_false`, `assert_eq`, and `assert_contains` accept an optional final `$output` parameter for additional console output on failure:

```php
assert_eq($result, 42, "wrong answer", "debug: input was $input");
```


## Annotations

Add annotations in the PHPDoc block above a test function.

### @exception — expected exceptions

The test passes if the listed exception (or a subclass) is thrown. A normal return fails, even if earlier assertions passed. A matched annotation counts as one successful assertion. Any `Throwable` type can be expected, including `Error` and `TypeError`, but TinyTest assertion errors, native `AssertionError`, and runner timeouts cannot satisfy an expectation. Do **not** use try/catch for expected exceptions — TinyTest handles them internally:

```php
/**
 * @exception InvalidArgumentException
 */
function test_rejects_negative(): void {
    calculate_total(-5, 1);
}
```

Multiple exception types (test passes if **any** is thrown):

```php
/**
 * @exception InvalidArgumentException
 * @exception RangeException
 */
function test_rejects_bad_input(): void {
    process_data(null);
}
```

Use fully qualified class names for namespaced exceptions:

```php
/**
 * @exception App\Exceptions\ValidationException
 */
function test_validation_fails(): void {
    validate_record([]);
}
```

### @dataprovider — data-driven tests

Run a test once per entry in a data set:

```php
function addition_data(): array {
    return [
        'one plus one'  => [1, 1, 2],
        'two plus two'  => [2, 2, 4],
        'ten plus ten'  => [10, 10, 20],
    ];
}

/**
 * @dataprovider addition_data
 */
function test_addition(array $data): void {
    assert_eq($data[0] + $data[1], $data[2], "addition failed");
}
```

Each dataset must make an assertion or satisfy an exception expectation. A `null` dataset is passed as one argument, not mistaken for a no-argument test. Empty providers are incomplete; provider errors fail the test and cannot satisfy `@exception`. Results are still reported per test function: any failed dataset fails the function, otherwise any incomplete dataset makes it incomplete.

### @type — categorize tests

Tag tests for selective inclusion/exclusion:

```php
/**
 * @type sql
 */
function test_db_access(): void {
    assert_true(db_connect(), "unable to connect to the db");
}
```

```shell
tinytest -f tests.php -i sql     # only run @type sql tests
tinytest -f tests.php -e sql     # run everything except @type sql
```

Both `-i` and `-e` can be repeated to include/exclude multiple types.

### @skip / @todo — skip tests

```php
/**
 * @skip database not available in CI
 */
function test_requires_db(): void { ... }

/**
 * @todo implement after v2 API ships
 */
function test_new_endpoint(): void { ... }
```

### @ambiguous — flag uncertain behavior

Mark a test where the correct behavior is not obvious. The test still runs, but is flagged in output:

```php
/**
 * @ambiguous returns null on some systems, false on others
 */
function test_edge_case(): void {
    assert_eq(get_value(), null, "expected null");
}
```

### @timeout — time limit

Fail the test if it takes longer than the specified duration. Supports decimal values. Fractional timeouts are checked after execution and cannot stop a hung test. Timeouts of at least one second also use an alarm when pcntl is available; this is not subprocess isolation:

```php
/**
 * @timeout 0.5
 */
function test_fast_response(): void {
    $result = fetch_data();
    assert_not_empty($result, "should return data");
}
```

### @phperror — expected PHP errors

Expect a specific PHP error type (E_WARNING, E_USER_WARNING, E_NOTICE, etc.). Each declared expectation must actually occur and counts as one successful check. Unexpected warnings/notices/deprecations fail the case independently of `log_errors` and `display_errors`; they are captured with severity/location rather than parsed from logs. TinyTest enables `E_ALL` by default; runtime PHP suppression (`@`, `error_reporting`, or `-s`) is honored, but a suppressed error cannot satisfy an expectation. Legacy `@phperror Warning:message substring` remains supported. Provider warnings fail the provider and cannot satisfy the test body's expectations.

```php
/**
 * @phperror E_WARNING
 */
function test_triggers_warning(): void {
    file_get_contents('/nonexistent/path');
}
```

### @covers — restrict coverage scope

File-level annotation (in the docblock at the top of the test file, before any function) to restrict coverage reporting to specific source files:

```php
<?php declare(strict_types=1);
/**
 * @covers ../src/Parser.php
 * @covers ../src/Validator.php
 */

require_once __DIR__ . '/../src/Parser.php';
```

Paths are resolved relative to the test file. When no `@covers` is present, all executed source files appear in coverage output.


## Command Line Reference

```
tinytest [options]
```

| Flag | Description |
|------|-------------|
| `-f <file>` | Load and run tests from a file |
| `-d <directory>` | Load and run all test files in a directory |
| `-t <test_name>` | Run only the named test function |
| `-i <type>` | Include only tests with this `@type` (repeatable) |
| `-e <type>` | Exclude tests with this `@type` (repeatable) |
| `-b <file>` | Include a bootstrap file before running tests |
| `-a` | Auto-load `bootstrap.php` from the test directory |
| `-c` | Generate code coverage (`lcov.info`) — requires phpdbg |
| `-r` | Display code coverage totals to console (implies `-c`) |
| `-j` | Output results as JSON |
| `-l` | List tests without running them |
| `-v` | Verbose output (show stack traces on failure) |
| `-q` | Quiet mode — suppress test output (up to `-q -q -q`) |
| `-x` | Show only failures and incomplete tests; preserve full-suite summary |
| `-m` | Monochrome console output (no ANSI colors) |
| `-s` | Suppress PHP diagnostics (does not suppress assertions/exceptions or validation) |
| `--allow-empty` | Allow no discovered/filtered tests; an unmatched `-t` still fails |
| `--help` | Show help without loading project code (`-h` also works) |
| `--json` | Alias for `-j` |
| `-p` | Save xhprof profiling data (requires tideways/xhprof) |
| `-k` | Save callgrind profiling data (for KCachegrind) |
| `-n` | Skip profiling for low-overhead functions |
| `-w` | Use wall time for callgrind (default: CPU time) |

### Common recipes

```shell
# Run a single test file
tinytest -f tests/test_parser.php

# Run all tests in a directory
tinytest -d tests/

# Run a single test function
tinytest -f tests/test_parser.php -t test_parse_header

# Verbose output with stack traces
tinytest -v -f tests/test_parser.php

# JSON output for CI/agent consumption
tinytest -j -f tests/test_parser.php

# Code coverage with console summary
tinytest -r -c -f tests/test_parser.php

# JSON output with code coverage
tinytest -j -c -f tests/test_parser.php

# Run with bootstrap
tinytest -a -d tests/

# Run only integration tests
tinytest -d tests/ -i integration

# Run everything except slow tests
tinytest -d tests/ -e slow
```


## JSON Output

Version 12 corrects summary semantics: `total`, `passed`, `failed`, `incomplete`, and `skipped` count **test functions**, not assertions or provider rows. During execution, `total = passed + failed + incomplete + skipped`; TODO is included in skipped. Ambiguous is an additional flag, not a pass/fail override. `-l` reports the number discovered with no execution outcomes.

Assertion totals are separate under `summary.assertions`; matched exception/PHP-error expectations count as successful assertions. Execution failures (errors, timeouts, provider failures, missing exceptions, incomplete tests) do not invent assertion counts. Failed assertions are sticky per case, even if caught; use `assert_fails` for intentional assertion-failure checks.

Exit codes:
- **0:** success (or explicit help/list/allowed empty selection).
- **1:** failed or incomplete tests.
- **2:** invalid arguments/paths, unmatched selectors, loading, backend, cleanup, or worker errors.

The root `exit_code` matches the process exit. Root `errors` contains structured runner errors (`kind`, `class`, `message`, `file`, `line`); test failures remain under `tests[].error`. Do not decide success from `summary.failed` alone: incomplete tests and runner errors also fail. Unknown flags, repeated scalar options, trailing arguments, missing files/directories/bootstrap files, and empty selections fail explicitly. `--allow-empty` opts out of empty discovery/filter results, but never an unmatched `-t`.

### Clean JSON process boundary

JSON mode uses one supervised PHP worker for the **entire suite** (not one process per test). Only the supervisor writes JSON to stdout. The worker's report travels over a separate descriptor; file/bootstrap/provider output, direct `fwrite(STDOUT, ...)`, stderr, cleanup, and shutdown output are captured rather than mixed into JSON. Case-buffered output is in `tests[].output`; other output is under root `output.stdout` / `output.stderr`. Root stream previews are limited to 64 KiB with `stdout_truncated` / `stderr_truncated` flags; reports larger than 16 MiB fail with a runner error. Invalid UTF-8 is replaced safely during JSON encoding.

A worker that exits before reporting (including `exit(0)`), fatals, or fails during shutdown produces exit 2 and a valid error report. Cleanup callbacks run on handled loading/execution failures, but PHP cannot run ordinary `finally` blocks after an abrupt `exit()` or forced termination. This is transport/failure isolation, **not a security sandbox or a hard timeout**. Tests still share one worker's global state, and can intentionally replace PHP error handlers; TinyTest restores its handler afterward.

This requires the built-in `proc_open` function and writable temporary storage, and adds one PHP startup per JSON invocation. Startup INI settings and loaded extensions are checked for consistency; configure extensions in php.ini rather than only `-d extension=...` if the worker cannot reproduce them. Console mode runs in-process and cannot contain an explicit `exit()` or fatal termination. Interpreter/startup output before TinyTest starts, killing the supervisor, and exhaustion of supervisor resources are outside the JSON guarantee. phpdbg/profiling worker compatibility still requires separate backend verification.

Use `-j` for machine-readable output:

```shell
tinytest -j -f tests/test_parser.php
```

```json
{
  "version": 12,
  "tests": [
    {
      "name": "test_parse_header",
      "file": "tests/test_parser.php",
      "status": "OK",
      "duration": 0.001,
      "assertions": 3
    },
    {
      "name": "test_parse_invalid",
      "file": "tests/test_parser.php",
      "status": "FAIL",
      "duration": 0.001,
      "assertions": 1,
      "error": {
        "message": "expected [42] got [41] \"values differ\"",
        "file": "tests/test_parser.php",
        "line": 28
      }
    }
  ],
  "summary": {
    "total": 2,
    "passed": 1,
    "failed": 1,
    "incomplete": 0,
    "skipped": 0,
    "ambiguous": 0,
    "assertions": {"total": 4, "passed": 3, "failed": 1},
    "duration": 0.002,
    "memory_kb": 2048
  },
  "errors": [],
  "exit_code": 1
}
```

With `-c`, a `coverage` key is added containing per-file function coverage data:

```json
{
  "coverage": {
    "/path/to/src/Parser.php": {
      "functions_total": 10,
      "functions_covered": 7,
      "covered_functions": [
        {"name": "parse_header", "line": 12}
      ],
      "uncovered_functions": [
        {"name": "validate_input", "line": 108}
      ]
    }
  }
}
```

List tests without running them:

```shell
tinytest -j -l -f tests/test_parser.php
```


## Code Coverage

Code coverage requires [phpdbg](https://www.php.net/manual/en/book.phpdbg.php), the interactive PHP debugger bundled with most PHP distributions.

```shell
# Install phpdbg
sudo apt install php-phpdbg          # Debian/Ubuntu
brew install php                      # macOS (included with Homebrew PHP)

# Run tests with coverage
tinytest -c -f tests/test_parser.php

# Run with coverage summary printed to console
tinytest -r -c -f tests/test_parser.php
```

This generates an `lcov.info` file in the current directory. Use it with:

- **VS Code** — install [Coverage Gutters](https://marketplace.visualstudio.com/items?itemName=ryanluker.vscode-coverage-gutters) to see coverage inline
- **CI pipelines** — most CI services accept `lcov.info` for coverage reporting

> **Tip:** Add `lcov.info` to your `.gitignore`.


## Performance Profiling

Install the [tideways xhprof extension](https://github.com/tideways/php-xhprof-extension) (or the original [xhprof](https://github.com/patrickallaert/xhprof)):

```shell
# xhprof format (one .xhprof.json per test)
tinytest -p -f tests/test_parser.php

# callgrind format (open with KCachegrind)
tinytest -k -f tests/test_parser.php

# callgrind with wall time instead of CPU time
tinytest -k -w -f tests/test_parser.php

# Skip low-overhead functions in profile output
tinytest -k -n -f tests/test_parser.php
```


## Custom Assertions

Add project-specific assertions to `user_defined.php`. TinyTest loads its bundled `user_defined.php` first, then loads one from your working directory if it exists. This lets you add assertions without modifying the TinyTest installation.

```php
<?php
// user_defined.php in your project root

function assert_json_valid(string $json, string $message): void {
    json_decode($json);
    $valid = json_last_error() === JSON_ERROR_NONE;
    assert_base_condition(fn() => $valid, $json, "valid JSON", $message);
}
```

**Important:** All user override functions must be defined in the **global namespace**. Do not wrap them in a `namespace` declaration.

### Override functions

You can override TinyTest's built-in formatting and test selection by defining these functions in `user_defined.php`:

| Function | Purpose |
|----------|---------|
| `user_format_test_run($name, $data, $opts)` | Customize the "running test..." message |
| `user_format_test_success($data, $opts, $time)` | Customize the success output |
| `user_format_assertion_error($data, $opts, $time)` | Customize the failure output |
| `user_is_test_file($filename, $opts)` | Custom logic for identifying test files |
| `user_is_test_function($funcname, $opts)` | Custom logic for identifying test functions |


## Agentic Coding Skills

TinyTest ships with skills that AI coding agents can use to generate, analyze, and improve your test suite. Install them with the [setup script](#quick-start) or copy them manually from `agent-integration/skills/`.

### Utilities

| Skill | Command | Description |
|-------|---------|-------------|
| **test-run** | `/test-run [file\|dir] [-t test_name]` | Run tests and report results. Accepts a file, directory, or single test name. |
| **test-fix** | `/test-fix <test_file> [test_name]` | Diagnose and fix a failing test. Reads error output, compares test vs source, repairs the test or explains the bug. |
| **test-report** | `/test-report [tests-path]` | Read-only project coverage dashboard: per-file health, failing/incomplete tests, unresolved annotations. Requires phpdbg. |

### Single File

| Skill | Command | Description |
|-------|---------|-------------|
| **test-generate** | `/test-generate <source-file.php>` | Generate an initial TinyTest test file for a PHP source file. Creates mock stubs, runs the file, fixes failures, and registers it in `test_sources.md`. |
| **test-improve** | `/test-improve <test_file.php>` | Review an existing test file for quality problems — weak assertions, missing messages, wrong parameter order, PHPUnit drift, and anti-patterns — then fix all approved issues. |
| **test-cover-file** | `/test-cover-file <source-file.php>` | Achieve full coverage for one file in two phases: Phase 1 iterates until every function is called; Phase 2 iterates until every reachable statement and branch is exercised. Requires phpdbg. |

### Single Function

| Skill | Command | Description |
|-------|---------|-------------|
| **test-analyze** | `/test-analyze <file.php> <function_name>` | Deep-analyze a single function — trace all code paths, find callers, classify bugs and smells, and generate exhaustive tests. Useful before `/test-cover-file` when a function is complex. |
| **test-regression** | `/test-regression <function_name> "<bug description>"` | Write a regression test for a known bug. Produces a named test that proves the bug exists (or is fixed) and will catch any future recurrence. |

### Project-Wide

| Skill | Command | Description |
|-------|---------|-------------|
| **test-bootstrap** | `/test-bootstrap [source-path] [tests-path]` | Scan all PHP source files, register any without a test file in `test_sources.md`, and generate initial test files for each. Run this first on a new greenfield project. |
| **test-audit** | `/test-audit [tests-path]` | Full coverage audit — runs phpdbg across all tests, finds every uncovered function, analyzes each in context, generates tests, and produces a structured bug/smell report. Requires phpdbg. |

### Refactoring & Adoption

| Skill | Command | Description |
|-------|---------|-------------|
| **test-refactor** | `/test-refactor <path/to/file.php>` | Analyze a file for business logic entangled with side effects (DB, IO, HTTP, globals), extract it into pure unit-testable functions, rewire call sites, and generate tests. |
| **test-migrate** | `/test-migrate <PHPUnit-test-file-or-directory>` | Migrate PHPUnit test files to TinyTest — structural conversion, full assertion remapping, exception annotations, data providers, setUp/tearDown, and PHPUnit mock objects. |

### Recommended workflow — greenfield project

```
1.  /test-bootstrap                       # register all source files, generate initial test files
2.  /test-cover-file src/Parser.php       # full coverage: functions, then lines and branches
3.  /test-improve tests/test_parser.php   # strengthen assertions and fix anti-patterns
4.  /test-fix tests/test_parser.php       # fix any remaining failures
5.  /test-report                          # review overall project health
```

### Recommended workflow — migrating from PHPUnit

```
1.  /test-migrate tests/                  # convert existing PHPUnit test files to TinyTest
2.  /test-improve tests/test_parser.php   # strengthen any weak assertions from the migration
3.  /test-cover-file src/Parser.php       # fill coverage gaps the migration didn't cover
4.  /test-audit                           # full project coverage audit and bug report
```

> **Tip:** Install [ripgrep](https://github.com/BurntSushi/ripgrep) (`rg`) and add it to your agent's allowed programs. It helps the agent find call sites and usage patterns to generate better tests.


## Agent Integration Setup

### Automated

```shell
cd /path/to/my-project
/path/to/tinytest/agent-integration/setup.sh .
```

### Manual

1. Copy `agent-integration/CLAUDE.md.template` to your project root as `CLAUDE.md`, replacing `{{TINYTEST_PATH}}` with the path to tinytest
2. Copy skill directories from `agent-integration/skills/` to `.claude/skills/`
3. Add tinytest to `.claude/settings.local.json`:

```json
{
  "permissions": {
    "allow": [
      "Bash(php /path/to/tinytest/tinytest.php*)",
      "Bash(phpdbg*/path/to/tinytest/tinytest.php*)"
    ]
  }
}
```

### What the setup script installs

| Component | Location | Purpose |
|-----------|----------|---------|
| Agent instructions | `CLAUDE.md` | Assertion API reference and TinyTest conventions |
| Skills | `.claude/skills/` | Test generation, execution, and debugging skills |
| Agent definitions | `.pi/agents/`, `.claude/agents/` | Sub-agent definitions (if pi is detected) |
| Permissions | `.claude/settings.local.json` | Allow agent to run tinytest |
| Shell alias | `~/.bashrc` / `~/.zshrc` | `tinytest` command |


## Requirements

- **PHP 7.4+** syntax/runtime baseline (PHP 8.x recommended; current fixes verified on PHP CLI 8.5)
- **phpdbg** — for code coverage _(optional, bundled with most PHP distributions)_
- **tideways/xhprof** — for profiling _(optional)_

## Verifying framework changes

```shell
php -d zend.assertions=1 tinytest.php -j -d tests/
php tests/cli_contracts.php
php tests/boundary_contracts.php
```

The independent subprocess harnesses check real exit codes, JSON outcomes, sticky assertion failures, exception/PHP-error expectations, providers, filtering, input validation, startup settings, and noisy/aborted worker processes. Deliberately failing fixtures live in `tests/fixtures/` (or temporary projects), outside the default shallow test scan. The unit suite includes an intentional stderr exercise; JSON captures it in `output.stderr`.

Contributors: start with [AGENTS.md](AGENTS.md) for the call chain and task-to-file map. Boundary implementations are in `src/cli.php`, `src/options.php`, and `src/execution_state.php`; the remaining runner extraction is still planned.

## Roadmap

See [ROADMAP.md](ROADMAP.md) for the prioritized review and improvement plan: reliable results, a thin entry point, agent discoverability, and stable machine-readable contracts.
