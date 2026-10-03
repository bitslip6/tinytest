<?php declare(strict_types=1);

// TINYTEST v12 — read this file to write and run tests; no internals required.
// PHP 7.4+ syntax baseline; verified on PHP CLI 8.5. No required third-party
// libraries or Composer. Keep tinytest.php, assertions.php, user_defined.php,
// and the entire src/ directory together (this is NOT a standalone distribution).
// Run from your project root; use an absolute runner path if installed elsewhere.
// This file is an EXECUTABLE, not an include: requiring it runs the CLI and exits.
// HOSTED AGENT TOOL (no host output/exit; fresh child process on every call):
// Load your global AgentTool interface, then require src/agent_tool.php directly.
// $tool = new \TinyTest\TestRunnerTool('/project', '/usr/bin/php', 'tests/bootstrap.php');
// Constructor bootstrap is optional (null); configure paths on the server.
// $tool->definition() supplies a strict Responses API function-tool definition.
// $report = $tool->execute(['directory'=>'tests', 'files'=>[], 'test'=>null, 'list'=>false]);
// All four keys required; choose directory OR nonempty project-relative files.
// test is null or exact function name. Returns normal JSON-report array; tool errors
// return exit_code 2 + errors. Needs proc_open/temp storage. Use CLI PHP, not FPM.
// POSIX required (host posix_kill, CLI posix_setsid); Windows unsupported.
// Fourth constructor argument: wall-clock seconds (default 30). Timeout kills the
// process group and returns exit_code=2, timed_out=true, timeout_seconds, errors.
// Trusted PHP only: detached processes can escape; no disk quota/security sandbox.
// Complete host integration instructions: new-agents.md.
// Never runs the core runtime in the host; repeated tool calls use fresh processes.
// src/bootstrap.php loads declarations, but standalone assertion use and scoped,
// repeatable runtime initialization are not yet supported. Use fresh CLI runs.
//
// QUICK START
// Save the following as tests/test_example.php. Assertions are loaded by the
// runner: do not require TinyTest inside tests. Require your application code
// with __DIR__-relative paths, or load its autoloader using a bootstrap below.
// BEGIN RUNNABLE EXAMPLE
// <?php declare(strict_types=1);
// function test_addition(): void {
//     assert_eq(1 + 1, 2, 'adds integers');
// }
// function addition_rows(): array {
//     return ['positive' => [2, 3, 5], 'zero' => [0, 0, 0]];
// }
// /** @dataprovider addition_rows */
// function test_addition_rows(array $row): void {
//     assert_eq($row[0] + $row[1], $row[2], 'adds each row');
// }
// /** @exception InvalidArgumentException */
// function test_rejection(): void {
//     throw new InvalidArgumentException('example rejected input');
// }
// END RUNNABLE EXAMPLE
//
// Execute (replace /path/to/tinytest with the installation directory):
//   php /path/to/tinytest/tinytest.php -j -d tests/
//   php /path/to/tinytest/tinytest.php -j -f tests/test_example.php
//   php /path/to/tinytest/tinytest.php -j -f tests/test_example.php -t test_addition
//   php /path/to/tinytest/tinytest.php -v -f tests/test_example.php
// Check the process exit code; use -j for agents/CI, -v for console diagnostics.
//
// DISCOVERY & SETUP
// Tests are plain GLOBAL functions named test_*, it_*, or should_*; no PHPUnit
// classes, inheritance, $this assertions, automatic setUp/tearDown, or mocks.
// -d scans ONLY immediate test_*.php files, not subdirectories. -f explicitly
// loads any named PHP file and is repeatable; use either -d or -f, never both.
// Helpers/providers must have other names; functions must be globally unique.
// Define tests in selected files, not bootstrap: discovery uses the difference
// in declared user functions before/after loading selected files. Loading/listing
// executes file-level PHP. Tests share process globals; this is not isolation.
// Use helper functions for setup and try/finally for resource cleanup per test.
//   -b tests/bootstrap.php : explicit shared setup/autoloader, before test files
//   -a                    : find bootstrap.php beside selected files/directory
// Explicit -b wins over -a; -a across directories requires explicit -b.
// Order: bundled user_defined.php -> CWD/user_defined.php -> bootstrap -> tests.
// Argument/path/backend validation precedes project code; help loads none.
//
// ASSERTIONS — global functions; messages describe the expected behavior.
// Below A=actual, E=expected, H=haystack, N=needle, M=message string.
// Messages are required except assert_identical (default 'test failed').
// All return normally on success; failures throw TinyTest\TestError.
//   assert_eq(A,E,M)             strict === (objects: same reference)
//   assert_neq(A,E,M)            strict !==
//   assert_eqic(A,E,M)           case-insensitive string equality
//   assert_gt(A,E,M), assert_lt(A,E,M)    > and <
//   assert_true(A,M), assert_false(A,M)  PHP truthy/falsy, not strict booleans
//   assert_contains(H,N,M)      case-sensitive substring, nullable strings
//   assert_icontains(H,N,M)     case-insensitive substring, nullable strings
//   assert_not_contains(H,N,M)  absent substring; null H or N passes
//   assert_matches(A,pattern,M), assert_not_matches(A,pattern,M)
//                               string + delimited PCRE; invalid regex FAILS both
//   assert_array_has(H,N,M)     strict array VALUE membership, not key existence
//   assert_array_contains(N,H,M) LEGACY: needle FIRST, loose membership
//   assert_count(A,E,M)         Countable/array size equals integer E
//   assert_empty(A,M), assert_not_empty(A,M)     PHP empty() semantics
//   assert_instanceof(A,ClassName::class,M)     object type/subtype
//   assert_object(A,E,M)        structural object equality (details below)
//   assert_identical(A,E,M)     structural objects; === for other values
//   assert_fails(callback,M)    intentional assertion-failure check (details below)
// assert_true/false/eq also accept a final debug-output string after M, emitted
// only on failure. Other assertions have no supported debug-output parameter.
// contains/icontains reject null operands. Empty strings follow PHP substring
// semantics. Use assert_true(array_key_exists('key', $data), 'key exists') for keys.
// Structural objects: same class, strict recursive stored properties (including
// private/protected); insertion order ignored. Arrays compare keys/order/values.
// Cyclic objects supported; recursive arrays or depth >100 fail closed. Distinct
// internal PHP objects except stdClass are unsupported: compare extracted values.
// Uninitialized properties are absent; identical object references always match.
//
// A CAUGHT failed assertion STILL fails its case; never clear/reset counters.
// To test an assertion itself, use:
//   $error = assert_fails(fn() => assert_eq(1, 2, 'intentional'), 'must reject');
// This requires a THROWN TinyTest\TestError, isolates only that callback, counts
// one check, and returns the error; other throwables propagate. Prior failures
// stay failed. For custom assertions, call existing assertions or:
//   assert_base_condition(fn($a,$e) => $a === $e, $actual, $expected, 'message');
// Predicate receives actual/expected; this helper counts and throws correctly.
// Native PHP assert() is NOT counted; no zend.assertions setting is needed for
// TinyTest assertions. Every dataset needs a TinyTest check or matched expectation.
//
// ANNOTATIONS — PHPDoc immediately above function, one annotation per line.
//   @dataprovider addition_rows   callable returning iterable key => value
// Each VALUE is ONE argument (arrays are NOT spread; null is still an argument).
// Provider runs once; body runs per row. Empty providers are incomplete. Provider
// exceptions/diagnostics fail the function, not satisfy body expectations.
//   @exception InvalidArgumentException  expected throwable class or subclass
// Repeat for alternatives; namespaced classes need fully qualified names.
// Normal return fails; matched exception counts one check. TinyTest errors,
// native AssertionError and runner timeouts cannot satisfy @exception Throwable.
//   @phperror E_USER_WARNING      expected PHP diagnostic; counts one check
// Repeat expectations; each must occur. Legacy Warning:message substring works.
// Unexpected warnings/notices/deprecations fail independently of PHP log/display
// settings. @ suppression, error_reporting() and -s are honored; suppressed
// diagnostics cannot satisfy expectations. TinyTest enables E_ALL by default.
//   @type integration            category (default standard), selected by -i/-e
//   @skip reason / @todo reason  do not execute; both count as skipped
//   @ambiguous reason            still executes; flag only, not a success override
//   @timeout 0.5                 seconds per dataset; fractional limits are checked
// after execution, cannot stop hangs. >=1s also uses pcntl alarms when available.
//   @covers ../src/Parser.php    FILE-header PHPDoc, before first definition;
// repeat for files. Resolved relative to test file, then CWD fallback. Does not
// load code. Current coverage is approximate: @covers affects display/framework
// exclusions, not a strict LCOV scope filter; do not treat it as proof of coverage.
//
// CLI REFERENCE (options precede/follow one another; quote paths containing spaces)
// -d DIR | -f FILE (repeatable)  select files; -t NAME selects ONE exact function
// -i TYPE / -e TYPE             repeatable category include/exclude; -i wins
// -b FILE / -a                  explicit/auto bootstrap as described above
// -j, --json                    JSON report; default is console output
// -l                           list definitions, do not run bodies/providers
// -v                           verbose loading/failure traces
// -q / -qq / -qqq               hide body output / progress dots / quiet results
// -x                           display only failed/incomplete; counts unchanged
// -m                           monochrome console (still emits ANSI reset codes)
// -s                           suppress PHP diagnostics, NOT assertions/exceptions
// --allow-empty                permit empty discovery/filter results, NEVER bad -t
// -h, -?, --help                help (also default with no arguments)
// -c / -r                      write lcov.info / also show coverage (-r implies -c)
// -p / -k                      write NAME.xhprof.json / callgrind.NAME per test
// -n / -w                      omit low-overhead profile entries / use wall vs CPU
// Unknown flags, missing values, repeated scalar options and trailing args fail.
// No dataset selector or recursive-directory option. List still loads project PHP.
//
// RESULTS & AUTOMATION
// Exit 0: success/help/list/allowed empty; 1: failed OR incomplete; 2: invocation,
// loading, backend, cleanup or worker error. Do NOT check summary.failed alone.
// v12 JSON: version, tests[], summary, errors[], exit_code; optional output/coverage.
// tests entries: name, file, status (OK/FAIL/IN/SKIP/TODO), duration, assertions,
// output and optional error {class,message,file,line}, reason, ambiguous flags.
// Counts are FUNCTIONS, not rows: any failed row -> FAIL; otherwise incomplete
// row -> IN. summary: total, passed, failed, incomplete, skipped (includes TODO),
// ambiguous (additional flag), assertions {total,passed,failed}, duration, memory_kb.
// List entries instead have name/file/type and no executed outcomes. -x can omit
// successful entries without changing summary. Root errors describe runner failures.
// JSON uses one worker per SUITE, not per test. Requires proc_open and writable
// temporary storage; uses same PHP settings/extensions (configure in php.ini).
// Buffered body output: tests[].output; other streams: output.stdout/stderr, each
// capped at 64 KiB with *_truncated flags. Report >16 MiB fails; invalid UTF-8 is
// replaced. Worker exit/fatal/shutdown failures yield exit 2, even exit(0).
// Not a security sandbox or hard timeout. Abrupt exits cannot guarantee cleanup;
// supervisor startup/fatal/resource failures are outside the JSON guarantee.
//
// OPTIONAL BACKENDS (artifacts written to CWD; ordinary assertions need neither)
//   phpdbg -q -rr -e /path/to/tinytest/tinytest.php -c -r -d tests/
// Coverage requires phpdbg_start_oplog; token/branch mapping has known limitations.
// Profiling requires tideways_enable/disable plus TIDEWAYS_FLAGS_CPU/MEMORY;
// an arbitrary xhprof extension alone is insufficient. -p takes priority over -k.
// Backend/JSON-worker compatibility requires verification on your installation.
//
// EXTENSIONS & SOURCE MAP (only needed when customizing/changing the framework)
// Define unique global helpers/custom assertions in project user_defined.php.
// Selection hooks return bool: user_is_test_file($filename,$opts),
// user_is_test_function($funcname,$opts). -t must still match an exact function.
// Console hooks return strings, do not print (not called for JSON rendering):
// user_format_test_run($name,$data,$opts),
// user_format_test_success($data,$opts,$time),
// user_format_assertion_error($data,$opts,$time).
// Internal owners: src/options.php arguments; src/cli.php worker/exit/JSON transport;
// src/application.php suite orchestration; src/discovery.php loading/selection;
// src/annotations.php metadata; src/runner.php execution/providers;
// src/results.php result object; src/errors.php throwable types;
// src/execution_state.php sticky scopes/diagnostics; src/runtime.php initialization;
// src/reporters/console.php console; src/coverage/ mapping/LCOV; src/profiling.php
// profile output; assertions.php global API. src/bootstrap.php explicitly loads core.
// Contributor details: AGENTS.md; longer examples: README.md (not prerequisites).

require_once __DIR__ . '/src/bootstrap.php';
exit(\TinyTest\run_cli($argv));
