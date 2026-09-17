# Implementation handoff: thin TinyTest entry point

**Status: plan only.** Implement against the current working tree, not an assumed clean historical revision. Read `AGENTS.md` first. Preserve existing uncommitted work. Function names below are search anchors; paths marked proposed do not exist yet.

## Objective

Make `tinytest.php` a 10–30-line executable adapter, with a short, explicit lifecycle visible through named functions. An agent changing an assertion, selector, reporter, or coverage algorithm should need the root map and one or two relevant modules—not a replacement monolith.

Keep plain global test/assertion functions, zero required third-party runtime libraries, existing CLI invocations, and documented `user_*` hooks. Do not introduce a container, automatic plugin scanning, magic autoloading, or a class hierarchy.

## Dependencies and ownership

Recommended order across the handoffs:

1. Do **T1–T3 here** to establish the declaration/module boundary.
2. Complete [plan-safe-import.md](plan-safe-import.md), which owns import semantics, runtime state, and initialization/teardown behavior.
3. Finish T4–T7 here: small orchestration, ownership cleanup, documentation, and verification.
4. Implement [plan-schema.md](plan-schema.md) and [plan-code-cverage.md](plan-code-cverage.md) on those stable modules. Coordinate their shared coverage payload before publishing a schema.

The safe-import agent may execute T1–T3 if those prerequisites are missing. Do not let two agents independently move the same functions. This plan owns file placement/call-chain simplification; it does **not** own new result semantics, backend algorithms, or dataset selectors.

## Current implementation to inspect

| Location | Relevant implementation |
|---|---|
| `tinytest.php` (~1,500 lines) | top-level constants/requires, `init`, `parse_options`, `load_file`, `load_dir`, annotations, `TestError`, `TestResult`, `do_test`, `run_test`, `run_suite`, coverage/profiling, unconditional `exit(run_cli($argv))` |
| `src/cli.php` | CLI error boundary, cleanup, JSON supervisor, report helpers |
| `src/options.php` | strict arguments, path/bootstrap/backend validation; normalization still lives in `tinytest.php` |
| `src/execution_state.php` | sticky `AssertionScope`, `PhpErrors`, handler restoration |
| `assertions.php` | public global assertions and `assert_fails` |
| `user_defined.php` | compatibility/project extension example |
| `tests/test_tinytest.php` | large internal test collection; references existing helper names and globals |
| `tests/cli_contracts.php`, `tests/boundary_contracts.php` | independent CLI contract harnesses |

Important current details:

- JSON uses a child process running the **same root executable**. `src/cli.php::supervise_json` assumes `dirname(__DIR__) . '/tinytest.php'`.
- Worker reports travel on descriptor 3; stdout/stderr are captured separately. INI/extension equivalence checks and cleared private worker environment markers must survive extraction.
- Discovery is the before/after difference of **user functions**, excluding system/framework functions. Moving declaration loading after the snapshot can change discovery!
- `run_suite` mixes loading, selection, execution, formatting, aggregation, and artifact generation. Moving it unchanged to `application.php` is only an intermediate step, not completion.
- Root-relative `__DIR__` expressions currently locate assertions, bundled overrides, and framework coverage exclusions. Moving files changes their meaning.

## Contracts to preserve

- Exit 0 success; 1 failed/incomplete tests; 2 invocation/loading/backend/cleanup/worker errors.
- Caught failed assertions remain failures. `assert_fails` isolates only its callback.
- Missing/unmatched selectors fail; `--allow-empty` never excuses an unmatched `-t`.
- `-x` filters display, not suite counts; skip/TODO and ambiguous policies stay unchanged.
- v12 JSON `tests` and `summary` still count functions, with separate assertion totals. Do not add a schema or case payload during mechanical moves.
- `-j` remains clean across load/provider/direct-stream/shutdown output and early worker termination; preserve UTF-8 handling and size limits.
- Preserve formatter hook names/signatures, bootstrap/override ordering, shallow discovery, and actual-before-expected assertion APIs.

## Proposed module map

Use these names in all four handoffs; adapt only if a preceding implementation has already established equivalent owners.

```text
tinytest.php                  executable adapter only
src/bootstrap.php             explicit require_once list; no runtime start
src/constants.php             immutable namespaced metadata/constants
src/runtime.php               explicit initialization/state acquisition/release
src/cli.php                   CLI boundary, worker transport, exit handling
src/options.php               arguments, normalization, validation, usage metadata
src/application.php           short run_suite lifecycle
src/discovery.php             project loading, file discovery, function selection
src/annotations.php           test/file metadata parsing
src/runner.php                do_test/run_test; providers and execution cleanup
src/results.php               TestResult, aggregation, summaries, exit policy
src/errors.php                TestError, TimeoutError, neutral diagnostic helpers
src/execution_state.php        sticky assertion/PHP diagnostic scopes
src/reporters/console.php      existing formatters and hook dispatch
src/reporters/json.php         report construction/encoding, not process supervision
src/coverage.php               optional collection lifecycle and scope coordinator
src/coverage/source_map.php    current mapping implementation, pending repair
src/coverage/lcov.php          LCOV serialization, pending repair
src/profiling.php              optional profiler lifecycle/artifacts
assertions.php                stable public global API
```

Most modules should be approximately 100–300 readable lines. Split an actual second responsibility when needed; do not create one file per tiny helper or compress statements to hit line budgets. Keep helpers with their owners. A small documented compatibility module is preferable to an indefinite `utils.php` bucket when old internal tests temporarily require shared helpers.

Dependency direction: CLI/application → discovery/runner/results/reporters → low-level errors/state/constants. Pure reporters must not call the CLI. Optional collectors must not be prerequisites for ordinary assertions. Use ordinary calls and explicit requires, not a registry to conceal dependencies.

## Work packages

### T1 — Record contracts before moving code

- Run the three existing verification commands below and lint changed files.
- Record normalized CLI outputs for passing/failing/incomplete, skip/TODO, list/help, bad arguments, unmatched selectors, noisy loading, `-x`, and worker failures. Normalize only volatile duration/memory/temp paths and intentionally moved framework stack locations.
- Inventory helpers, public hooks, `__DIR__`/`__FILE__`, constants, globals, statics, and startup/cleanup actions with `rg`.
- Record known coverage inaccuracies rather than fixing them in this extraction.

**Checkpoint:** independent fixtures describe behavior before any moves.

### T2 — Extract declarations into named owners

- Move groups to the map above, retaining namespace/function names and behavior initially. Move `parse_options` beside `cli_arguments`/`validate_options`.
- Move `TestError`/`TimeoutError` separately from reporters; keep existing message behavior until the safe-import plan changes presentation dependencies.
- Move assertion-count helpers beside execution state or runtime, not into the executable.
- Move coverage/profiling as optional modules; retain the old algorithms for now. Separate the token mapper from LCOV formatting even if their correctness work is deferred.
- Move `run_suite` to `application.php` temporarily, then decompose it in T4.
- Make declaration loading explicit. No top-level CLI parsing, project includes, `init`, reporting, or `exit` in a reusable module.
- Static metadata constants are declarations. Put dynamic `YEARAGO` calculation, colour setup, compatibility temp-file allocation, and mutable counters behind runtime calls; coordinate detailed behavior with the safe-import plan.
- Loading a module before the user-function snapshot must not cause its helpers to be discovered as tests. Never restore the removed `get_defined_functions` boolean as a supposed user/system filter.

**Checkpoint:** existing CLI contracts pass using the extracted declarations. No `src/tinytest.php`/`framework.php` monolith has replaced the original.

### T3 — Introduce the explicit loader and executable

- `src/bootstrap.php` explicitly loads the core declarations and public assertion declarations with `require_once`; it does not start a session or load project overrides.
- Replace root implementation with a small adapter equivalent to:

```php
<?php declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
exit(\TinyTest\run_cli($argv));
```

- Importers use `src/bootstrap.php`, **not** the executable. Avoid `argv`/filename heuristics, a global “library mode” switch, or a require cycle to detect importing.
- Establish a single framework-root/entry-path helper based on source location, never caller CWD. Update assertions/override paths and worker launch paths explicitly.
- Preserve the console banner's root executable path if it is part of captured compatibility output; do not accidentally print `src/runtime.php`.
- Optional modules load explicitly when requested. Framework self-tests that exercise coverage/profiling helpers must explicitly require those declarations; don't load optional modules on every ordinary run just to satisfy those tests.

**Checkpoint:** root entry is 10–30 lines, runs from another working directory, and the supervisor still executes that root file. Hand off to safe-import work before claiming the library is import-safe.

### T4 — Make orchestration explain itself

Extract named operations from the old `run_suite` closure:

1. Load project overrides/bootstrap/test files under the existing error/assertion boundary.
2. Discover and select test definitions once; reject invalid empty selection.
3. Execute definitions/cases under collector lifecycle management.
4. Aggregate authoritative outcomes independently of display filters.
5. Render console output or construct a neutral report, and finalize artifacts.

Aim for a 30–60-line `run_suite` orchestration function. Keep implementation details in the owners above. Resolve user hook dispatch in a small explicit place; preserve signatures and call timing where compatible. Keep profiler/coverage start-stop paired with `try/finally` without changing measured semantics in this PR; any deliberate coverage-window correction belongs to the coverage plan.

Do not combine test execution with JSON encoding. `src/cli.php` owns descriptor/process transport and serialization timing after cleanup. Keep report-level cleanup errors and exit precedence intact.

### T5 — Reduce reading dependencies and split tests

- Move pure aggregation/status logic to `results.php` and test directly after bootstrap import.
- Move console-only colour/format logic to its reporter; the safe-import work defines the neutral error contract.
- Replace one-use higher-order wrappers with ordinary loops only when that clarifies the call chain; separate this cleanup from file-move commits.
- Split `tests/test_tinytest.php` by owner without creating duplicate global test/helper names. Keep shared fixture helpers under a clearly named test-support file, not runtime utilities.
- Update `@covers`, source maps, relative fixture paths, and legacy test references to moved files. Don't inflate coverage by silently changing the measured scope.

### T6 — Document the navigation surface

- Update `AGENTS.md` with task → implementation → tests, and the actual call chain. Keep it about 60–100 lines.
- Add `docs/architecture.md` for dependency direction, library-vs-CLI entry points, hook ownership, and optional module loading.
- README links to detailed contracts; do not repeat the whole implementation in agent templates.
- Add a lightweight architecture check: executable/loader line budgets, explicit known includes, root entry free of function/class declarations, optional modules absent from an ordinary bootstrap's included-file list.

### T7 — Verify behavior and installation layout

```sh
php -d zend.assertions=1 tinytest.php -j -d tests/
php tests/cli_contracts.php
php tests/boundary_contracts.php
```

Also run the safe-import contracts when available; lint each extracted PHP file. Smoke-test paths with spaces, a different CWD, project `user_defined.php`, explicit/auto bootstrap, quiet/verbose/list modes, and worker subprocess startup. Exercise phpdbg/profiling on real backends when available; otherwise report them unverified, not green.

## Acceptance checklist

- [ ] Root entry is thin; orchestration is short; no replacement monolith or broad generic helper dump.
- [ ] Every major behavior has one discoverable owner and nearby tests.
- [ ] Core bootstrap contains only explicit declaration loads/static metadata; full import-safety tests pass after the companion plan.
- [ ] Required directory distribution includes all new files; execution works independent of CWD.
- [ ] Existing statuses, assertion semantics, hooks, CLI output contracts, exit codes, and JSON supervision remain correct.
- [ ] Coverage exclusion identifies the actual framework files after moves, not an accidentally broadened or narrowed directory.
- [ ] `AGENTS.md`, README, and architecture map reflect actual paths.

Deliver small reviewable changes: characterize → move declarations → loader/entry → safe-import handoff → decompose orchestration → split tests/docs. Record behavioral changes separately; do not hide them in a large relocation diff.
