# TinyTest roadmap: trustworthy results, thin entry point, easy discovery

## Goal

Keep plain-function tests, a small assertion API, zero required dependencies, and the existing `php tinytest.php ...` entry point. Make both using and changing TinyTest possible without loading the whole implementation into an agent's context.

**Priority order:** trustworthy outcomes → discoverable structure → stable machine interface → optional features. Do not expand parallelism or coverage claims before the underlying results are reliable.

This is a review and implementation plan. Locations in the baseline refer to the reviewed source; use function names as durable search anchors.

## Implementation handoff plans

These are implementation instructions for a new agent, **not completed features**:

- [Thin entry point and module extraction](plan-thin-entry-refactor.md)
- [Import-safe declarations and explicit initialization](plan-safe-import.md)
- [Versioned JSON schema, dataset results, and exact selectors](plan-schema.md)
- [Coverage accuracy and optional Xdebug/PCOV backends](plan-code-cverage.md)

Recommended order: thin-entry steps T1–T3 → safe-import work → remaining thin-entry work → schema and coverage work with a shared coverage-data contract. A safe-import agent starting earlier should perform the documented extraction prerequisites, not create an alternative bootstrap. The coverage boundary-index regression can be fixed separately if edits are coordinated.

## Current implementation status — v12

**Outcome, assertion, loading/diagnostic, and JSON transport fixes are implemented; broad deployment still needs runtime/backend validation.** Boundary logic now lives in `src/options.php`, `src/cli.php`, and `src/execution_state.php`, and root `AGENTS.md` is a real navigation guide. The full thin-entry-point extraction remains unfinished (`tinytest.php` is still about 1,500 lines). Status labels distinguish implemented behavior from remaining work; the review baseline is historical.

### Implemented

- Result-based exits: failed or incomplete test functions cause exit 1 independently of assertion counters. JSON and console summaries count functions; assertion counters are separate under `summary.assertions`. Reporting filters preserve full-suite totals, and `-x` includes incomplete failures.
- Exception expectations: missing exceptions fail; matching subclasses and `Throwable` types pass and count as one successful check. Framework assertion errors, native `AssertionError`, and runner timeouts cannot satisfy expectations. Single-line and multiline annotations are supported.
- Providers: null values are passed as arguments, each dataset is checked for assertions, empty providers are incomplete, and missing/invalid/throwing providers fail. Dataset results remain aggregated into one function result.
- Assertions: falsey predicates fail correctly; regex errors cannot pass negative assertions; empty strings and null are distinguished in containment checks; object comparison is strict, symmetric, class-aware, and includes stored non-public properties. Object cycles are supported; recursive arrays/excessive depth fail closed during object comparison. Distinct internal objects, except `stdClass`, require identity.
- Collections: `assert_array_has($haystack, $needle, $message)` provides strict membership. Legacy needle-first, loose `assert_array_contains` is preserved but moved into `assertions.php`.
- Sticky assertion outcomes: per-case/provider/loading/cleanup scopes record assertion failures independently of mutable counters. Catching an assertion error or making later successful assertions cannot clear a failure. `assert_fails($callback, $message)` explicitly isolates an intentional assertion-failure check, preserves unrelated failures, and counts one assertion.
- Scoped diagnostics: cases collect PHP errors independent of log/display settings, honor suppression, and enforce numeric `@phperror E_*` or legacy `Warning:substring` expectations. Provider/loading/cleanup diagnostics fail their own boundary. Error-log parsing is no longer used for execution; `get_error_log` remains a legacy helper. Handlers, removable buffers, and prior pcntl settings are restored on handled paths.
- Validated loading/selection: strict short options plus `--json`, `--help`, and `--allow-empty`; explicit path checks; no bootstrap execution during parsing/help; `-d`/`-f` conflicts and ambiguous auto-bootstrap directories rejected; actual `test_*.php` files only. Empty selection exits 2 unless explicitly allowed; unmatched `-t` always exits 2. Missing backends fail preflight.
- Clean JSON boundary: one supervised worker per suite sends its report on descriptor 3, separately from all stdout/stderr, including direct writes and shutdown output. Early worker exits/fatals/shutdown failures produce a valid exit-2 report. Startup settings/extensions are checked, invalid UTF-8 is substituted, root stream previews are bounded, and oversized reports fail explicitly. Normal case output remains associated with the function result.
- Exit/report contract: 0 success, 1 failed/incomplete tests, 2 invocation/loading/backend/cleanup/worker errors. Root `exit_code` matches the process; root `errors` describes runner failures. Cleanup callbacks are attempted after handled failures, and one cleanup failure does not skip others.
- Compatibility/documentation: v12 summary semantics and assertion contracts are documented in README and the agent template; custom assertion counting example is fixed; the exception example's invalid `time(true)` call is fixed. README now states a PHP 7.4 baseline, not a verified multi-runtime support matrix.
- Baseline repairs: unresolved `@covers` paths warn in console mode; PHP 8.5 `get_defined_functions` deprecations are removed. User/internal arrays and before/after user-function filtering are preserved—the removed boolean controlled disabled functions, not user/system separation.

### Verification and ownership

```sh
php -d zend.assertions=1 tinytest.php -j -d tests/
php tests/cli_contracts.php
php tests/boundary_contracts.php
```

Latest verification on Linux PHP CLI 8.5.10: **314 functions, 312 passed, 2 skipped/TODO, 0 failed/incomplete; 531 assertions.** The independent CLI harness passes **40 outcome fixtures plus filtering, mixed-suite, diagnostic-isolation, console, and list checks**. `tests/boundary_contracts.php` also passes validation, sticky/intentional assertion, PHP-error, noisy output, early-exit, shutdown, and worker-configuration checks. The existing `fatals()` unit exercise's deliberate stderr is now captured under root `output.stderr`, not leaked alongside JSON.

Earlier verification also passed with native assertions disabled (`-d zend.assertions=-1`), linted all PHP files, and ran the exception example. PHP 7.4/other PHP 8.x versions, phpdbg coverage, and profiling backends have **not** been validated.

| Change area | Implementation | Regression coverage |
|---|---|---|
| Outcomes, exceptions, providers | `tinytest.php` (`do_test`, `run_test`, `run_suite`) | `tests/test_execution.php`, `tests/cli_contracts.php`, `tests/fixtures/outcomes.php` |
| CLI validation/loading policy | `src/options.php`, `tinytest.php::run_suite` | `tests/boundary_contracts.php`, `tests/test_tinytest.php` |
| JSON supervision, runner errors, cleanup | `src/cli.php` | `tests/boundary_contracts.php` |
| Sticky scopes and PHP diagnostics | `src/execution_state.php`, `assertions.php::assert_fails` | boundary contracts, assertion unit tests |
| Agent navigation | `AGENTS.md` | documented commands and local-link checks |
| Assertion predicates, regex, strings, collections | `assertions.php`, `user_defined.php` | `tests/test_assertions.php`, `tests/test_user_defined.php`, CLI fixtures |
| Structural object equality | `assertions.php::objects_equal` | `tests/test_object_assertions.php` |
| Existing utilities and annotations | `tinytest.php` | `tests/test_tinytest.php` |

### Release gates and regression risks

- [x] **Resolve caught-assertion false greens.** A caught failed TinyTest assertion now fails its case through a sticky scope. `test_caught_assertion` expects FAIL, and boundary contracts verify later successes/counter resets cannot clear the outcome. Intentional negative assertion tests use `assert_fails`; existing framework tests have migrated. Consumers that manually reset counters must migrate too.
- [ ] Compare representative consuming suites under old and new versions. Stricter exception expectations, per-dataset incomplete checks, and newly enforced logged warnings/deprecations can change outcomes.
- [ ] Review object-comparison compatibility, especially private properties, class/type differences, and distinct but equivalent internal objects such as `DateTimeImmutable`. Compare extracted values where structural comparison is unsupported.
- [ ] Migrate JSON consumers to function totals and `summary.assertions`; use the exit code or include incomplete outcomes when deciding success. Review `-x` visibility and changed skip/TODO list-entry shapes. An independent JSON schema version is not implemented yet.
- [ ] Test supported PHP versions and optional phpdbg/profiling backends. The removed `get_defined_functions` argument can affect disabled-function listings on PHP 7.4, though user-function filtering remains intact.
- [ ] Validate JSON worker deployment requirements: `proc_open`, writable temp storage, descriptor-3 transport, equivalent PHP startup configuration, and one extra PHP startup per JSON invocation. Windows, phpdbg, and profiling need separate checks. The supervisor is not a security sandbox or hard deadline; parent-process kills/resource exhaustion and interpreter output before TinyTest starts are outside its guarantee. Console mode remains in-process.
- [ ] Stage the compatibility-changing v12 release with rollback available. Deploy matching framework files **including `src/`**, not just `tinytest.php`; bundled collection assertions reside in `assertions.php`.

**Next work:** validate consuming suites and worker/backend compatibility, then continue the thin-entry-point extraction and publish a versioned JSON schema. Root instructions, caught-assertion policy, validated load/selection boundaries, scoped `@phperror`, and JSON transport isolation are now implemented. Per-test process isolation/hard deadlines, accurate coverage, and installer redesign remain planned.

## Historical review baseline — before v12 fixes

- `tinytest.php`: 1,428 lines / approximately 58 KB. CLI parsing, loading, execution, result aggregation, formatting, coverage, profiling, and process-global initialization are interleaved.
- `tests/test_tinytest.php`: 2,446 lines. Many helper-level tests exist, but CLI contracts need independent subprocess tests.
- `AGENTS.md` points to missing `CLAUDE.md`. A fresh agent has no working root instruction file.
- The advertised single-file distribution already requires adjacent `assertions.php` and `user_defined.php`.
- README claims PHP 7.0+, but arrow functions require at least PHP 7.4. Choose and test a supported runtime range rather than inferring compatibility from old comments.

Baseline command, run on PHP CLI 8.5.10:

```sh
php -d zend.assertions=1 tinytest.php -j -d tests/
```

Observed: exit 1; 302 result entries (299 OK, 1 FAIL, 1 SKIP, 1 TODO). The failing test is `test_load_file_covers_path_not_found_warning`: `load_file()` silently ignores unresolved `@covers` paths. JSON summary reports 466 total, 462 passed, 1 failed—these are assertion counters, not test counts, and the suite itself manipulates them. Stderr also contains `some error output` from a stderr exercise. Treat this as a recorded baseline, not a clean suite.

Coverage/profiling extensions and other PHP versions were not exercised in this review. Findings about those paths below are from source inspection.

## P0 — Make pass/fail and automation trustworthy

### 1. Establish a subprocess contract harness

**Status: implemented for the reviewed CLI boundaries.** `tests/cli_contracts.php` and `tests/boundary_contracts.php` independently check exit codes, JSON/stdout/stderr, provider failures, incomplete cases, exception outcomes, malformed inputs, noisy/aborted processes, empty suites, and unmatched selectors. The baseline `@covers` mismatch is fixed. Broader backend/runtime combinations remain a release gate.

**Current locations:** `tests/test_tinytest.php`, `do_test()`, final `exit()`.

- Add a dependency-free PHP subprocess harness using `PHP_BINARY`/`proc_open`; inspect exit code, stdout, and stderr independently of TinyTest's own pass/fail counters.
- Keep small fixture programs outside the default test-file discovery path. Include deliberately failing tests, syntax errors, noisy loaders, provider failures, and empty suites.
- Record intended behavior separately from legacy behavior. Existing tests sometimes explicitly accommodate defects: `test_output_lcov_tracks_covered_functions` works around the final-index bug.
- Fix or explicitly resolve the baseline `@covers` warning mismatch before calling the suite green.

**Done when:** one documented verification command detects a runner that prints FAIL but exits 0, prints invalid JSON, or silently runs zero selected tests.

### 2. Derive status and exit code from test results, not assertions

**Status: implemented core behavior.** Outcomes, separate function/assertion totals, sticky caught-assertion failures, exception expectations, input validation, and failure-only visibility are implemented. Native PHP assertions are documented as uncounted. Explicit case-level reporting and runtime/backend deployment validation remain outstanding.

**Current locations:** `do_test()`, `run_test()`, main execution closure, JSON summary, final `exit()`.

Originally confirmed with isolated CLI fixtures; all three required outcomes below now pass regression tests:

| Fixture | Pre-v12 behavior | Implemented behavior |
|---|---|---|
| Successful assertion followed by `throw new Error("boom")` | FAIL entry, exit 0 | Failed test, nonzero exit |
| `@exception RuntimeException`, matching exception thrown, no explicit assertion | IN, exit 1 | Expected-exception success |
| Same annotation, successful assertion but no exception | OK, exit 0 | Missing expected exception failure |

- Use one canonical case result and one suite aggregator. Assertion helpers count assertions; they must not decide the process exit status.
- Model test and assertion totals separately; explicitly distinguish function counts from dataset/case counts.
- Check exception expectations after normal return. Match subclasses with `is_a`/`instanceof`, and define support for all `Throwable` types rather than treating `Error` separately by accident.
- Keep timeout/framework failures distinct from expected application exceptions.
- Implemented: incomplete causes exit 1; skip/TODO do not execute; ambiguous does not override pass/fail; `-x` retains failed and incomplete results.
- Decide native PHP `assert()` support: require explicit startup configuration or document it as unsupported for counted assertions. Never rely on native `assert()` for runner input validation.

**Done when:** every failure category reliably exits nonzero; summary totals reconcile with case results; reporting filters cannot change suite truth.

### 3. Correct assertion semantics

**Status: implemented for the reviewed defects.** Falsey, regex, string/null, structural object, and collection compatibility contracts are covered by unit/CLI tests. README's custom assertion example uses one counting path. Consumer migration review is still required; see the release gates, especially internal-object identity and caught assertion failures.

The following bullets record the original defects and the scope addressed by this slice.

**Current locations:** `assertions.php::assert_base_condition`, `assert_true`, `objects_equal`, `assert_not_matches`; `user_defined.php`.

- Fixed: `assert_true(0, "zero is falsey")` previously passed because the base helper only rejected literal `false`. Make predicates return booleans and test all falsey inputs against the documented truthy contract.
- Replaced the old `objects_equal()` behavior that checked only actual-side public properties, used loose comparisons, and compared arrays asymmetrically. Specify class identity, property visibility, types, keys, extra properties, and cycles before replacing it.
- Invalid regexes must be assertion/input errors, not successes for `assert_not_matches()`.
- Move the bundled collection assertion out of the user override example. Its needle-first, loose-membership behavior contradicts the general API convention; introduce a clear migration path rather than silently reversing arguments.
- Fix README custom assertion accounting: its example increments once directly and again on success, and does not increment failures when throwing.

**Done when:** table-driven edge-case tests enforce documented semantics; every custom assertion has one accounting path.

### 4. Make loading and diagnostics controlled

**Status: implemented standard boundaries.** Options normalize without executing code; paths/bootstrap/backend requirements validate explicitly; loading, cleanup, and worker failures report exit 2; cases/providers use scoped diagnostics. Native `assert()` no longer validates paths. JSON supervision contains worker output/early termination. Further work: backend-specific lifecycle validation and optional per-test isolation/hard deadlines; arbitrary project termination is not containable in in-process console mode.

**Current locations:** `parse_options()`, `init()`, `load_file()`, `load_dir()`, `get_error_log()`, `panic_if()`, `fatals()`.

- Implemented: separate argument parsing, option validation/bootstrap resolution, and project-code execution; help never loads project code.
- Implemented: unreadable paths, repeated scalar options, unknown arguments, and missing optional backends report exit 2. `panic_if()` throws rather than calling `die(string)`.
- Implemented: `-d` with `-f` is rejected; explicit `-b` wins over `-a`; auto-bootstrap across directories requires explicit `-b`.
- Implemented: shallow directory discovery only loads real files matching `test_*.php` (or the project file-selection hook), not directories.
- Implemented: scoped PHP diagnostics replace log scraping for execution. `@phperror E_*` and legacy `Warning:substring` are checked for actual occurrence; suppression is respected. Logging/display configuration does not determine whether an observed diagnostic fails a case.
- Handle load/bootstrap/provider failures at an application boundary. Use `finally` to restore output buffers, handlers, profiler/coverage state, and signal settings. Avoid process-wide opcode-cache reset.
- Keep fatal shutdown handling as a bounded fallback; document that forced termination cannot always produce a complete report.

**Done when:** invalid invocation exits distinctly from failed tests, errors cannot leak between tests, and extension absence produces actionable diagnostics before executing tests.

## P1 — Make the implementation thin AND discoverable

### 5. Extract by responsibility, preserving a visible call chain

**Status: started with boundary modules.** `src/options.php`, `src/cli.php`, and `src/execution_state.php` have explicit responsibilities. The full side-effect-free bootstrap/thin entry point is not implemented; most runner/coverage/profiling code remains in `tinytest.php`.

Target structure (proposed names, not existing files):

```text
tinytest.php                 # require bootstrap; exit(TinyTest\main($argv))
src/bootstrap.php            # explicit requires, declarations only
src/application.php          # main: parse → load → discover → run → report
src/options.php              # option definitions, validation, help
src/discovery.php            # file/function selection and bootstrap loading
src/annotations.php          # annotation grammar and metadata
src/runner.php               # execute a case/provider; resource cleanup
src/results.php              # result shapes, aggregation, exit policy
src/errors.php               # assertion errors and PHP diagnostics
src/reporters/console.php    # human rendering and legacy formatter hooks
src/reporters/json.php       # machine serialization only
src/coverage.php             # optional backend lifecycle and scope
src/coverage/source_map.php  # source mapping, isolated from normal runs
src/coverage/lcov.php        # LCOV serialization
src/profiling.php            # optional profiling and artifact serialization
assertions.php              # canonical public assertion API
user_defined.php            # documented extension example/compatibility file
```

Design rules:

- Aim for **10–30 lines in `tinytest.php`**, bootstrap under 60 lines, and a **30–60 line `main()`** whose direct calls explain the lifecycle.
- Keep most modules around 100–300 lines. These are readability budgets, not incentives to compress code or create dozens of tiny files.
- Explicit requires and ordinary named functions; no service container, plugin discovery scan, magic autoloader, generic event bus, or inheritance hierarchy.
- Load coverage/profiling implementations only when requested. Ordinary assertion fixes should not require reading source-map code.
- Keep helpers near their owner. Replace single-use higher-order wrappers with straightforward loops where that shortens the reader's dependency chain. Avoid a new miscellaneous `utils.php` dumping ground.
- Define small result/context records using documented array shapes or simple value objects. Choose one convention. Pass runtime state explicitly; keep any necessary global assertion context behind a narrow compatibility boundary.
- Preserve public global assertion functions and documented `user_*` hooks. Mark private internals; do not accidentally promise compatibility for every currently namespaced helper.
- Requiring `src/bootstrap.php` must not parse CLI arguments, load project code, create temp files, mutate PHP configuration, print, or exit.
- Update `__DIR__`-dependent paths during extraction: assertion loading, bundled overrides, framework coverage exclusion, and artifact paths must retain deliberate roots.
- Split tests by the same responsibilities. Unit tests should load declarations without running the CLI.

**Done when:** an agent can identify and test the owner of a CLI, execution, JSON, assertion, or coverage change by reading the root map plus one or two relevant modules—not a relocated monolith.

### 6. Add progressive, agent-neutral documentation

**Status: partial, with root navigation repaired.** A real 65-line `AGENTS.md` provides exact commands, a task-to-file/test map, call chain, and public contracts. README and the agent template describe current behavior. Separate architecture/focused references and generated-contract/link checks remain planned.

- Replace the broken `AGENTS.md` symlink with a tracked, concise canonical file; optionally make `CLAUDE.md` point to it, not to a missing or ignored target.
- Keep root instructions roughly **60–100 lines**: exact verification commands, minimum PHP version, assertion order, entry point, task-to-file/test map, extension rules, and links.
- Add `docs/architecture.md` with the call chain, dependency direction, public/internal boundary, result lifecycle, and module ownership table.
- Add focused `docs/assertions.md`, `docs/cli.md`, `docs/results.md`, and `docs/extensions.md`. Separate consumer instructions from contributor architecture.
- Keep README short enough to be an entry point; link detailed references and this roadmap.
- Make option metadata the source for help and CLI reference validation; make assertion signatures the source for API-reference checks. Avoid competing hand-maintained full API lists in every skill.
- Update skills/templates to link canonical contracts. Describe agent workflow as discover → select → run JSON → inspect failure → rerun exact case; do not require loading implementation to write tests.
- Add checks for broken local links, missing instruction targets, documented commands, and drift in options/assertion examples.

**Done when:** a fresh checkout has working agent instructions, all documented paths resolve, and locating a change owner requires at most two navigation hops.

### 7. Resolve distribution and runtime promises

**Status: partial documentation/distribution clarification.** README states the PHP 7.4 baseline and requires copying the framework directory including `src/`, rather than claiming a standalone file. There is no verified runtime CI matrix, generated single-file bundle, or release/install smoke suite.

- Prefer a small source directory distribution with no Composer requirement; call it that in README.
- If a literal single-file download is important, generate it as a release artifact from modular source. Do not maintain two implementations, and direct agents to source rather than the generated bundle.
- Choose a supported PHP floor; lint and run subprocess contracts across that range in CI. Test CLI separately from optional phpdbg/profiling jobs.
- Document custom assertion/override loading precedence and collision behavior without requiring edits inside the installed framework.

**Done when:** clean-install smoke tests verify every advertised install mode and supported runtime.

## P2 — Make machine use a first-class contract

### 8. Version and validate JSON output

**Status: partial, with clean transport implemented.** v12 function/assertion summaries, unfiltered totals, case output, root errors/exit codes, empty-selection policy, UTF-8 substitution, bounded root stream previews, and a supervised JSON worker are implemented and regression-tested. An independent schema version, published schema, full case-level payloads/selectors, richer typed values, and backend option-combination validation remain planned.

**Current locations:** main JSON assembly, `TestError`, `coverage_to_lcov()`.

- Implemented: supervisor-owned stdout contains one JSON document; bootstrap/load/provider/test/direct stream output is captured separately. Parent startup/termination limits are documented.
- Implemented: help is serialized, coverage prose is disabled for JSON, and supervisor capture isolates incidental output. Validate `-j -r` with an actual coverage backend before deployment.
- Add `schema_version` independent of the framework release. Publish a JSON Schema and checked examples; explicitly migrate the existing summary semantics.
- Include stable case ID, function name, dataset key, test file/line, status, assertion count, duration, captured output, and structured error kind/class/message/location. Store actual/expected values with types and bounded previews, not ANSI-colored strings embedded in exceptions.
- Separate test definition location from failure location. Optional stack traces and truncated output limits should be explicit.
- Handle invalid UTF-8 and JSON encoding failure deliberately. Keep suite diagnostics and artifact paths in documented fields.
- Specify exit codes, for example 0 success, 1 test failures/incomplete, 2 invocation/runner errors. Define zero-discovery and unmatched-selector behavior with an explicit allow-empty escape hatch.
- Keep full suite counts even for failure-only output; identify that the results array is filtered.

**Done when:** subprocess fixtures validate JSON and exit codes for success, assertions, exceptions, warnings, invalid input, provider errors, noisy loading, and supported option combinations.

### 9. Improve discovery and precise reruns

**Status: partial.** List handling precedes skip/TODO execution handling and yields consistent name/file/type entries without running test bodies. Null datasets, invalid/empty providers, per-case incomplete checks, exact unmatched-selector failures, and `--allow-empty` policy are handled. `--json`/`--help` exist; other long options, rich inventory, recursive discovery, dataset selectors/results, and capabilities remain planned.

- Add long aliases such as `--list`, `--json`, `--filter`, and `--help` through the same option definitions, not a second parser contract.
- Make `--list --json` a stable inventory including file/line, annotations, type, skip/todo reason, and selector. The old skip-before-list inconsistency is fixed; richer metadata and stable selectors remain to be added.
- Clearly state that reflection-based discovery loads PHP files and can execute top-level code. Listing must not execute test bodies; do not describe it as sandboxed or side-effect-free.
- Add explicit recursive discovery with deterministic ordering and documented exclusions. Preserve the existing shallow default until a deliberate compatibility decision.
- Support exact dataset reruns and preserve every dataset result instead of reducing failures to the last error.
- Distinguish “no arguments” from a provider value of `null`; catch provider generation failures; define empty-provider and iterable behavior.
- Add machine-readable capabilities (`--describe --json` or equivalent): version, backend availability, options, statuses, annotations, and schema location. Keep this derived from canonical metadata, not a parallel registry of everything.

**Done when:** an agent can list cases, choose an unambiguous selector, and reproduce one failure without running unrelated cases or guessing supported options.

## P3 — Repair optional analysis, then expand features

### 10. Make coverage honest and testable

**Status: planned, apart from console warnings for unresolved `@covers` paths and removal of a deprecated function argument.** The mapping boundary bug, heuristic scopes/branch metrics, path filtering, and JSON/LCOV scope differences are not repaired. Coverage was not backend-validated.

**Current locations:** `find_index_lineno_between()`, `make_source_map_from_tokens()`, `output_lcov()`, `combine_oplog()`, `coverage_to_lcov()`.

- Fix the loop's `< max(array_keys(...))` boundary: it ignores the final entry and every single-entry mapping. Add first/last/sparse/single-entry fixtures.
- Replace heuristic function ranges (ending at the next declaration or 999999) with correctly bounded scopes and qualified names. Test namespaces, methods, closures, arrow functions, nested functions, and closure `use` clauses.
- Audit hit aggregation and duplicate executable lines against backend data and golden LCOV files.
- Do not label an executed `if` line as proof of both branch outcomes. Expose coverage capabilities/limitations; omit unsupported branch metrics rather than manufacturing confidence for agents.
- Use canonical path membership instead of substring exclusions. Apply `@covers` consistently to JSON and LCOV, warn on unresolved paths, and include explicitly scoped but unexecuted source files with zero hits where supported.
- Decouple backend collection from source mapping and serialization. Consider Xdebug/PCOV only after the coverage contract is stable; those backends are not required dependencies.

**Done when:** golden fixtures agree with actual executed/unexecuted code, JSON/LCOV scopes match, and agents can distinguish measured data from unsupported metrics.

### 11. Bound execution and optional tooling

**Status: partial.** Fractional timeout limits and restored pcntl state are documented/tested; timeouts cannot satisfy exception expectations. JSON has suite-level process isolation and backend availability is checked before execution. Per-test process isolation, hard deadlines, safe artifact handling, alternate profiling API support, and backend validation remain planned.

- Fractional timeouts remain post-run checks, not interrupting deadlines. Integer alarms still depend on pcntl and are not subprocess isolation; do not claim hard containment.
- Add opt-in subprocess isolation for hard deadlines, `exit()`/fatal containment, and global-state isolation; specify startup cost and bootstrap semantics.
- Detect supported tideways/xhprof API variants explicitly. Current implementation unconditionally calls `tideways_enable`/`tideways_disable` despite broader documentation.
- Add explicit artifact directory/path options, safe filenames, write-error handling, and cleanup on failure. Avoid overwriting unrelated artifacts in the working directory.
- Consider fail-fast, deterministic shuffle/seed, repeat runs, and CI/JUnit export only after result contracts are stable.
- Defer parallel workers until isolated cases, deterministic aggregation, dataset identity, and artifact naming are proven. Parallelism should remain optional.

**Done when:** missing backends, timeouts, subprocess termination, and unwritable artifacts produce predictable outcomes without contaminating subsequent cases.

### 12. Make integration setup safe and repeatable

**Status: planned.** The instruction template reflects v12 semantics, but `setup.sh` has not been redesigned or tested for safe/idempotent installation.

**Current locations:** `agent-integration/setup.sh`, `agent-integration/CLAUDE.md.template`.

- Install a concise agent-neutral instruction block with tool-specific adapters as optional conveniences.
- Use marked sections for idempotent updates; current reruns append the entire template again.
- Add dry-run and explicit choices for skills, permissions, and shell changes. Do not automatically modify shell startup files for a project-only installation.
- Prefer exact executable commands usable in noninteractive agents; aliases are not a reliable automation interface, and the current alias always assumes phpdbg.
- Quote/escape paths safely for shell, template substitution, and JSON. Preserve existing settings rather than replacing user configuration.
- Validate source assets before modifications; test setup in temporary HOME/project directories, including paths with spaces and repeat installation.

**Done when:** a second installation produces no duplicate content, project settings survive, and plain-PHP usage works without phpdbg or shell aliases.

## Suggested pull-request sequence

This is the target sequence, not a record of completed PRs. The core work of steps 1–3, initial boundary extraction, and JSON transport/exit policy in step 6 are implemented as described above. Finish deployment/backend checks, then continue structural extraction and schema work before expanding features.

1. **Contracts and navigation:** restore real root instructions, add subprocess harness, record/fix baseline mismatch, link this roadmap.
2. **Outcome correctness:** canonical statuses/exit policy, exceptions, assertion edge cases, regression fixtures.
3. **Load/error boundary:** explicit validation, controlled diagnostics, cleanup, provider failure handling.
4. **Structural extraction:** introduce side-effect-free bootstrap and thin entry point; extract execution/results/reporters; split matching tests. Keep each move reviewable and separate from unrelated behavior changes.
5. **Optional module extraction:** move coverage/profiling out of the normal reading path; audit path assumptions.
6. **Machine contract:** versioned schema, clean JSON, precise inventory/selectors, capabilities.
7. **Coverage repair:** mapping boundaries, truthful metrics, consistent scope, backend fixtures.
8. **Packaging/integration:** runtime CI matrix, distribution smoke tests, idempotent setup and canonical skill references.
9. **Optional features:** isolation/deadlines first; parallelism and additional exporters only with demonstrated need.

For every PR: name the owning module, add the smallest reproducing fixture, update the relevant contract docs, run unit and subprocess tests, and ensure an agent can find the change without reading the whole runner.
