# Implementation handoff: import-safe declarations and explicit runtime initialization

**Status: plan only.** Read `AGENTS.md`, inspect the current working tree, and preserve existing changes. This document is sufficient to identify the work without the earlier conversation.

## Objective and scope

A caller must be able to `require_once '/path/to/tinytest/src/bootstrap.php'` to obtain TinyTest declarations without running the CLI, loading project code, allocating temporary resources, changing PHP configuration, printing, or exiting.

Go beyond merely hiding `exit()`: assertions must work after import, runtime initialization must be explicit, and framework-owned state must be restored after an explicit run. Do not promise to undo arbitrary project code, unload PHP functions/classes, or sandbox application globals.

The root `tinytest.php` remains an executable and is **not** the library import API.

## Prerequisite and coordination

Use the shared module names in [plan-thin-entry-refactor.md](plan-thin-entry-refactor.md). Its **T1–T3** create the declaration modules and loader. If they are absent, implement those prerequisites first; do not introduce an import-mode global, an `argv` heuristic, a circular bootstrap, or a second monolithic copy of the runner.

This plan owns `src/bootstrap.php`'s observable contract, `src/runtime.php`, assertion accounting boundaries, and presentation-independent errors. The thin-entry plan owns final module layout/orchestration. Schema/dataset work in [plan-schema.md](plan-schema.md) must preserve these runtime boundaries. Alternate coverage engines in [plan-code-cverage.md](plan-code-cverage.md) must initialize only during explicit execution.

## Current hazards to inspect

| Current source | Why it matters |
|---|---|
| `tinytest.php` top/bottom | Dynamic global `YEARAGO`, global `HIT_MISS`, and unconditional `exit(run_cli($argv))`; no reusable import entry |
| `init()` | Resets counters, defines global ANSI constants, prints a banner, requires assertions, allocates `ERR_OUT`, sets `assert.exception`/error reporting, enables GC |
| `TestError::__construct()` | Depends on colour constants from `init()` and a fixed backtrace depth |
| `count_assertion*()` / `assert_fails()` | Assume initialized `$GLOBALS` counters and active scope infrastructure |
| `get_mtime()` | Depends on a timestamp evaluated when the file was loaded |
| `src/cli.php::run_cli()` | Reads/clears worker environment markers, installs handlers, initializes runtime, runs cleanup, emits reports |
| `run_suite()` | Loads project overrides/bootstrap/tests, resets counters and scopes, uses global coverage/cleanup collections |
| `ERR_OUT`, `fatals`, `get_error_log` | Legacy compatibility helpers still assume an allocated global pathname; normal execution no longer needs log scraping |
| `format_output()` | Static branch block counter survives calls; coverage plan must remove this hidden report state |

Existing per-case assertion/PHP-error scopes, handler restoration, and pcntl cleanup must not regress. Those safeguards are not a substitute for an explicit outer runtime lifecycle.

## Precise contract

### Import permits

- Explicit `require_once` of framework declaration files.
- Function/class declarations and immutable **namespaced** metadata constants such as version and option keys.
- Growth of PHP's declared-symbol/included-file lists associated with those declarations.

### Import must not

- Read `argv`, consume worker environment markers, choose a backend, inspect project files/CWD for overrides, or execute tests/providers/bootstrap code.
- Print, write to stdout/stderr, open temp files, create coverage/profile artifacts, register shutdown handlers, or start output buffers.
- Call `ini_set`, change error reporting/handlers, signal state, GC configuration, CWD, or environment values.
- Initialize mutable globals/counters, define generic global colour constants, or evaluate time-derived run state.
- Require optional coverage/profiling engines or reset opcache.

“Idempotent import” means repeated `require_once` of the documented entry is safe. Do not attempt to make arbitrary repeated `require` of every implementation file redeclaration-proof. PHP syntax compatibility and non-conflicting TinyTest function/class names are prerequisites; the existing global assertion API cannot coexist with unrelated functions of the same names. Document that limitation rather than silently binding TinyTest calls to another library's assertions.

### Assertion behavior outside a run

Recommended explicit policy: public assertions are usable immediately after bootstrap import. Passing checks return normally; failures throw a plain `TinyTest\TestError`. **No active runtime means no suite counters or sticky run state are created.** `assert_fails` still verifies an intentionally thrown assertion error and preserves any enclosing active scope when one exists.

This avoids a hidden lazy `init()` every time an assertion is called. During an explicit run, counting and sticky failures behave exactly as documented today.

### Explicit execution

Add a small `with_runtime($options, $callback)` lifecycle boundary in `src/runtime.php` (name may follow an already-established equivalent). It acquires framework-owned runtime state, invokes the callback, attempts owned cleanup, and restores state in `finally`. Keep one state representation—small value object or documented array—not parallel globals and object counters that can diverge.

`run_cli` remains the transport/error/exit boundary and invokes runtime execution only after validation. Import never calls `run_cli`. A library caller can explicitly request runtime work; that is not a promise of JSON transport isolation or side-effect-free project execution.

## Work packages

### S1 — Add import contracts that fail on the current implementation

Create `tests/import_contracts.php`, an independent PHP subprocess harness, outside default `test_*.php` discovery. Test in a fresh process with arbitrary host `argv`, a foreign CWD, and project files that would write markers or throw if loaded.

Snapshot before/after import:

- stdout and stderr, output-buffer depth, CWD, environment worker markers;
- `error_reporting()`, relevant/all observable INI values, installed error handler, GC enabled state;
- pcntl handler/asynchronous mode/pending alarm when available (restore any probing changes);
- known framework mutable globals and existence of global colours/dynamic constants;
- directory contents in a dedicated `sys_temp_dir`, project/artifact directory, and registered autoloaders.

PHP lacks a general shutdown-handler inventory API: supplement snapshots with source checks and a shutdown sentinel proving normal caller control continues, with no framework shutdown output/artifacts. Test imports in a child process so accidental `exit` or fatal redeclaration is observable to the harness.

Allow only declaration/static namespaced metadata changes. Don't compare volatile memory/time or demand unchanged `get_included_files()`.

### S2 — Make the loader genuinely declaration-only

- Audit every file reachable from `src/bootstrap.php`, including public assertions. No optional module should be pulled in solely to satisfy old framework unit tests.
- Move immutable constants to `src/constants.php`; remove dynamic/global startup definitions.
- Replace `YEARAGO` with calculation at use time, or a run clock passed to `get_mtime` with a sensible call-time default. This avoids stale dates in long-running hosts.
- Scope the coverage sentinel to its optional module; do not define generic global `HIT_MISS` during core import.
- Keep the root executable's `exit(run_cli($argv))` out of the library dependency graph.
- Do not make `assertions.php` require the bootstrap back again; document the one canonical library import path.

**Checkpoint:** import tests can reach a sentinel statement after importing twice, with no project/resource/configuration side effects.

### S3 — Remove runtime/presentation dependencies from assertions and errors

- Make `TestError` construction safe without colours, initialized counters, or a runner stack frame. Its message should be plain text; colour belongs to console rendering.
- Preserve actual/expected ordering, throwable chaining, source locations, and existing public constructor signature where feasible. Adding structured values belongs to schema work, not a reason to invoke arbitrary object serialization here.
- Replace fixed backtrace indexing with bounded frame selection that tolerates direct construction, helper wrappers, namespaced custom assertions, and short stacks. Test the actual assertion callsite, not just that a positive line exists.
- Count only when a runtime/assertion context is active. Outside one, don't warn about undefined globals or quietly create a run.
- Adapt `assert_fails` to both standalone and active/nested contexts, preserving earlier sticky failures. Wrong exception types must still propagate; missing failures must fail the helper.
- Update assertion-unit helpers rather than exposing a production “clear failures” API.

**Checkpoint:** after import alone, successful/failed assertions and `assert_fails` work without stdout/stderr noise or configuration changes.

### S4 — Make initialization and teardown scoped and repeatable

Inventory all framework-owned mutable state and decide one owner per item:

| State | Required lifecycle |
|---|---|
| Timing, counters, coverage collection, active assertion scope | Fresh per explicit runtime; restore outer context on nested use |
| Error handlers/reporting | Snapshot before acquisition; restore even if callback/setup/cleanup throws |
| INI settings changed by TinyTest | Apply only explicit execution policy; restore previous values; do not attempt to enable compiled-out native assertions |
| GC setting | Prefer not to change it; otherwise restore prior enabled/disabled state |
| Cleanup callbacks | Runtime-owned queue, attempted once; one failure cannot prevent remaining callbacks; no replay on a later run |
| Buffers/signals | Keep existing per-case guards; verify framework-level acquisition failure also unwinds |
| Worker environment markers | Remain a CLI-worker concern; library import/runtime must not consume them |
| Legacy error-log resource | No allocation during import; explicit optional compatibility access only, with bounded ownership/cleanup |

- Separate declaration loading, runtime acquisition, project loading, and console banner rendering. `init` can remain a compatibility wrapper temporarily, but cannot be the reusable declaration entry.
- Replace global colour constants with a palette selected per render/run; colour then monochrome runs in one process must work. Audit custom hooks before deciding whether legacy colour aliases require a documented CLI-only compatibility shim. Do not keep unchangeable globals as the authoritative palette.
- Prefer retiring the temporary `ERR_OUT` path from normal execution, since diagnostics are scoped now. Migrate legacy helper tests to explicit temp-path inputs or a lazily requested compatibility resource; don't allocate a file solely because the framework was imported.
- Route runtime errors/cleanup failures through the existing CLI report policy: exit 2, clean JSON, remaining cleanup still attempted.
- If internal `$GLOBALS` compatibility views remain during migration, scope and restore them explicitly. Document that direct global counter manipulation is not a supported public API. There must be one authoritative accounting store.

**Checkpoint:** two explicit runtime callbacks, including one failure, have isolated counters/cleanup/palettes and restore the host's state. A nested callback restores its caller's sticky state, not a fresh empty scope.

### S5 — Wire the CLI without weakening worker isolation

- Keep argument/path/backend validation before project code.
- Supervisor mode should not acquire a test runtime unnecessarily. The child acquires its own runtime; descriptor-3 reporting happens after cleanup/restoration.
- Avoid changing worker INI/extension fingerprint behavior accidentally by moving setup earlier. Compare startup settings before intentional runtime mutations, as today.
- Preserve root error reporting when initialization fails halfway, cleanup throws, or a worker exits before reporting.
- Help/list should initialize only what they need; help must not allocate test resources or load overrides. Listing still loads PHP test declarations and is not a sandbox.

### S6 — Handle repeated execution honestly

Import safety does not make PHP declarations unloadable. `require_once` plus before/after function-difference discovery can return no new functions on a second run of the same file.

Choose and document the boundary rather than claiming unrestricted in-process reruns:

- Required for this plan: repeated imports, standalone assertion use, and repeated/nested **runtime callbacks** work.
- CLI suite runs remain fresh processes; JSON keeps its fresh worker.
- If exposing repeated `run_suite` in one process as supported, first introduce an explicit discovered-definition manifest/reuse policy and tests for redeclarations/changed fixtures. Otherwise reject/document that usage; do not silently return success for an empty second discovery.
- Arbitrary project globals, functions, resources, and fatal `exit()` are not reversible. Per-test isolation is a separate feature.

### S7 — Verify, document, and hand back

Run existing commands plus the new contract harness:

```sh
php -d zend.assertions=1 tinytest.php -j -d tests/
php tests/cli_contracts.php
php tests/boundary_contracts.php
php tests/import_contracts.php
```

Add tests for import under native assertions enabled/disabled, no backend extensions, hostile-but-unexecuted project bootstrap/overrides, host-defined colour constants, nested assertion scopes, cleanup failures, thrown setup, coloured then monochrome rendering, and preserved caller handlers/environment.

Update README with a minimal library import example and explicit execution limits. Update `AGENTS.md`, module ownership/tests, and the roadmap. Validate PHP 7.4-compatible syntax unless the project explicitly changes its supported floor.

## Acceptance checklist

- [ ] Documented import is silent, returns control, creates no runtime resources, and leaves host process state unchanged except declarations.
- [ ] Assertions and assertion-error inspection work without calling `init`.
- [ ] Runtime acquisition/cleanup is explicit, exception-safe, repeatable, and nested-state-safe.
- [ ] Sticky failures and `assert_fails` semantics survive the change.
- [ ] CLI output, selection, exit codes, and JSON supervision pass existing independent contracts.
- [ ] No misleading promise of unloading project code or repeated discovery in one PHP process.
- [ ] Optional coverage/profiling remains absent/inactive on ordinary import.

Deliver in separate reviewable slices: failing import tests → declaration audit → neutral errors/assertions → runtime ownership/restoration → CLI integration → docs. File moves alone are not proof of import safety.
