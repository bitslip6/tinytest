# Implementation handoff: accurate coverage and non-phpdbg backends

**Status: plan only.** Filename intentionally follows the requested `plan-code-cverage.md` spelling. Read `AGENTS.md` first and preserve the current working tree. Backend APIs/capabilities below must be checked against the installed extension versions before implementation; alternate engines have not yet been validated in this repository.

## Objective

Make reported coverage defensible enough for agents to act on. Repair known mapper/filter/LCOV defects, distinguish measured data from derived or unknown metrics, and support normal PHP CLI installations without a phpdbg binary using **optional Xdebug or PCOV**.

No required third-party runtime library. Normal testing must work without any coverage engine. A server with no instrumentation backend cannot provide trustworthy dynamic coverage merely by tokenizing source; fail explicitly rather than invent a “pure PHP coverage” fallback.

## Dependencies and coordination

Use the owners from [plan-thin-entry-refactor.md](plan-thin-entry-refactor.md): `src/coverage.php`, `src/coverage/source_map.php`, and `src/coverage/lcov.php`. Add small explicit `src/coverage/backends/phpdbg.php`, `xdebug.php`, and `pcov.php` adapters as needed. If extraction has not happened, locate the current functions below and coordinate moves with that agent.

Preserve declaration-only imports from [plan-safe-import.md](plan-safe-import.md). Backend detection may happen during explicit coverage preflight; collection must never start during import/help/list or in the JSON supervisor parent.

Coordinate optional `coverage` and `coverage_meta` fields with [plan-schema.md](plan-schema.md). Do not silently replace its result envelope or case-count semantics. Per-case coverage attribution is a follow-up; first make suite-level measurements correct.

## Current defects and anchors

Read `tinytest.php::combine_oplog`, `find_index_lineno_between`, `make_source_map_from_tokens`, `output_lcov`, `coverage_to_lcov`, `read_file_covers`, and the collector/artifact portions of `run_suite`. Also inspect `src/options.php::validate_options` and `src/cli.php::supervise_json`.

| Current behavior | Required repair |
|---|---|
| `find_index_lineno_between` loops with `< max(array_keys(...))` | Include the last/single/sparse entry; iterate entries rather than assuming contiguous indexes |
| `test_output_lcov_tracks_covered_functions` deliberately adds entries to bypass that bug | Replace workaround expectations with independent regression fixtures |
| Function end is the next declaration or sentinel `999999` | Track actual lexical bodies; distinguish methods, nesting, closures, arrow functions, and namespaces |
| `T_USE` is treated uniformly as an import | Distinguish namespace imports, closure captures, and trait use/adaptation |
| Executable lines guessed from token allowlists and currently defined functions | Prefer backend executable-line inventories; static guesses cannot establish exact denominators |
| Function hits use minimum line counts across broad ranges | Define measurement semantics; missed lines do not mean a function was never entered |
| One synthetic branch per `if`, inferred from line hits | Do not claim both branch outcomes or real branch coverage without native evidence |
| LCOV branch block ID is a function-static counter | Stable file-local IDs; independent reports must not leak state |
| Test/framework exclusion uses substrings and whole runner-directory prefixes | Canonical file/root membership; don't exclude application code merely because TinyTest is installed inside it |
| `@covers` filters JSON/display late, but LCOV receives broader data | Apply one scope before aggregation/serialization; JSON and LCOV must agree |
| Function-name prefixes remove items from detail lists but not all totals | Classify tests by discovered definition/file identity, not an application function's name alone; filter once before counting |
| Only backend-returned files are tokenized | Account explicitly for scoped but unobserved files; unknown is not zero or 100% |
| Collection starts after loading source/test files and wraps each function | Define and test a collection window that includes relevant source initialization; avoid backend-dependent blind spots |
| phpdbg calls are hard-coded and preflight rejects every normal CLI backend | Select an available explicit adapter without requiring phpdbg |
| `lcov.info` writes are fixed to CWD | Validate/report artifact path, write failures, and stale-artifact behavior |

The assertion/exit/JSON fixes are already implemented. Do not regress them while changing coverage lifecycle.

## Measurement contract to establish first

### Normalized model

Use a documented PHP array/value record shared by adapters and serializers:

- backend name/version, active capabilities, collection window, requested/canonical scope, source identity/hash where available, diagnostics;
- per-file executable line **states**: hit, executable-but-unhit, or unknown; backend-designated dead/non-executable lines are not missed executable lines;
- optional hit counts only with a declared meaning (presence vs actual engine events/visits);
- optional qualified function records with bounded body ranges and measurement provenance;
- optional native branch/edge records with stable backend-informed identities;
- explicitly listed unmeasured/unloaded files and unsupported metrics.

Do not add raw backend integers together before normalization. For example, Xdebug distinguishes positive hit markers, unused executable lines, and dead lines; those values are not universal counts. PCOV is principally line coverage, not a branch or invocation-count engine. Verify phpdbg oplog counting semantics rather than calling opcode counts function calls.

Merge line presence using set union/OR. Sum only values that really are independent measured counts, not cumulative snapshots or boolean markers. A missing key is not automatically a missed line: the denominator must come from a valid executable-line inventory.

### Honest metrics

- **Lines:** denominator is known executable lines in the measured scope. Empty denominator yields an unavailable/null percentage, not an invented success rate.
- **Functions:** prefer native entry evidence when available. Line-derived function presence is labeled derived and must exclude declaration/header lines and nested bodies. Shared-line/empty-body/ambiguous mappings are unknown, not proof of entry. Do not claim exact invocation counts from line presence.
- **Branches:** available only when an engine supplies real branch/edge data and the adapter is tested. Line execution of an `if` does not prove either/both edges. Unsupported is null/unavailable, not zero-percent coverage.
- **Unloaded files:** never `require` arbitrary source solely to manufacture a denominator; that can execute application code. Use an engine's safe executable inventory if available. Otherwise list the file as unmeasured and make the aggregate incomplete. Static source scanning can provide advisory structure, not measured zero lines.
- **Completeness:** publish whether the scope's denominator is complete. Thresholds/agents must not treat partial measured coverage as full project coverage.

LCOV has weaker provenance support than JSON. Emit DA records only for defensible executable lines; 1/0 is acceptable when only presence is known. Omit BR records when native branch coverage is unavailable. Emit function totals only where the function universe and hit interpretation are defensible; otherwise omit that metric rather than publish a falsely precise percentage. JSON must explain omitted/derived/unknown metrics.

### Scope and collection window

- Use a single canonical scope calculation shared by every backend/serializer: framework files, discovered test files, explicit source roots, and file-level `@covers` paths.
- Keep `@covers` relative-to-test-file semantics; report unresolved paths rather than silently treating them as measured. Test path boundaries (`src` vs `src-old`), symlinks, spaces, overlapping roots, and framework code explicitly requested by self-tests.
- Add repeatable `--coverage-source <file-or-directory>` to define source inventory when projects need unobserved-file accounting. Reject incompatible/invalid paths explicitly. Document intersection/precedence: explicit source scope limits the universe; `@covers`, when present, further narrows it.
- Prefer one suite-level collection window beginning **before project bootstrap/test/source loading**, after framework declarations/backend setup, and ending after test/provider execution. Exclude framework/harness code by identity, not timing guesses. Document that this deliberately fixes the old omission of source initialization.
- Stop/discard collection in `finally` on handled loading/provider/execution/reporting failures. Cleanup/profiler code should not silently become application coverage. Abrupt worker termination may yield no usable coverage; report incomplete/unavailable, not a stale prior artifact.
- Normal `-l`, `--list-cases`, help, and import do not start measurement. Do not require an installed engine merely to list tests.

## Alternate backends: feasibility and limitations

| Backend | Intended support | Caveats to verify |
|---|---|---|
| phpdbg | Existing oplog collection; executable inventory if supported by the installed API | Requires its SAPI/binary; no assumed native branch evidence |
| Xdebug 3 | Line coverage; native branch/function metadata where supported and validated | Extension must be installed and coverage mode enabled **at PHP startup**; branch collection flags/API shape differ from line-only data |
| PCOV | Fast line coverage on ordinary PHP CLI | Must be installed/enabled; startup directory/exclusion settings can omit files; do not fabricate branches or function calls |
| None | Tests still run normally without `-c`/`-r` | Coverage request exits 2 with actionable install/enable guidance |

Read authoritative documentation during implementation:

- PHP/phpdbg: https://www.php.net/manual/en/book.phpdbg.php
- Xdebug coverage and configuration: https://xdebug.org/docs/code_coverage and https://xdebug.org/docs/all_settings#mode
- PCOV APIs/configuration/compatibility: https://github.com/krakjoe/pcov

Use explicit finite dispatch, not a plugin ecosystem. A small adapter contract should expose detection/capabilities, start, collect/stop, and cleanup. Native extension calls stay in their adapter files. `detect` must not start a collector or write anything.

### Selection policy

Add `--coverage-backend auto|phpdbg|xdebug|pcov`, used with existing `-c` or `-r` (`-r` still implies `-c`). Support value parsing consistently with the strict CLI parser and document accepted syntax.

Recommended `auto` order: phpdbg when running under usable phpdbg; otherwise usable Xdebug coverage mode; otherwise enabled PCOV. Report the selected backend and capabilities. Explicit unavailable/misconfigured selection fails before project code. Do not silently substitute another backend after an explicit choice.

Only one instrumenting engine should be used per run. Verify Xdebug/PCOV/phpdbg interoperability constraints and reject unsupported active combinations with a clear diagnostic. Do not auto-install extensions, change server configuration, or silently disable another extension to make collection work.

Candidate commands to validate on real installations (not promises of already-supported flags):

```sh
phpdbg -qrr -e tinytest.php -j -c --coverage-backend phpdbg -d tests/
XDEBUG_MODE=coverage php tinytest.php -j -c --coverage-backend xdebug -d tests/
php -d pcov.enabled=1 -d pcov.directory=/absolute/project/src \
  tinytest.php -j -c --coverage-backend pcov -d tests/
```

`XDEBUG_MODE` can override the effective mode without changing the INI text. Inspect effective capability, not only `ini_get('xdebug.mode')`. PCOV include/exclude settings need equivalent scrutiny. For PHP 7.4 and supported PHP 8.x versions, pin compatible extension versions; do not assume the newest extension supports every old runtime.

### JSON worker compatibility

`src/cli.php` currently launches the same binary, forwards startup INI settings/environment, checks extension/INI equivalence, and runs phpdbg with special flags. Preserve that safety boundary:

- Parent detects/preflights but never starts collecting; child revalidates effective availability and is the sole collector.
- Preserve `XDEBUG_MODE`, PCOV configuration, and the selected backend through supervision. Backend metadata must describe the child that actually measured code.
- Do not weaken fingerprint checks to hide configuration drift. If effective-mode checks need extending, add explicit tests and diagnostics.
- Verify extension-enabled worker execution, descriptor-3 reports, `-j -r` clean output, native assertion settings, artifact paths with spaces, and exit propagation on actual backends.

## Work packages

### C1 — Establish independent coverage fixtures and repair the boundary bug

Create small source/test fixture pairs under `tests/fixtures/coverage/`; never rely solely on coverage of TinyTest's own implementation.

First add failing tests for empty, single-entry, last-entry, and sparse mappings, then fix `find_index_lineno_between`. Remove workaround fixtures/comments that preserve the bug. This narrow correction can precede full extraction if coordinated with the module owner.

Capture expected **executed behavior** manually: one function called, one never called, both/one/no sides of a branch, statements at first/last lines. Record current defects separately from intended golden results.

### C2 — Define normalized data, scope, and deterministic serializers

- Write `docs/coverage.md` with the contract above and a capabilities table.
- Introduce pure normalization/merge/scope/serialization functions, tested with recorded raw backend fixtures before depending on installed extensions.
- Apply scope before JSON/LCOV aggregation. Include line-only files, not just files with named functions. Use actual test-definition/file identities for exclusions, so a production function named `test_connection` is not automatically erased. Derive totals and detail lists from the same filtered records.
- Replace static LCOV block counters with stable per-file IDs. Check LF/LH, FNF/FNH, BRF/BRH against emitted records; use sorted paths/line numbers for repeatability.
- Keep unsupported fields absent/null according to the schema agreement; don't use numeric zero as a substitute for unknown.

### C3 — Repair structural mapping without overclaiming execution

- Use balanced token scopes for actual function/method bodies and fully qualified names. Handle namespace forms, classes/traits/enums/interfaces, abstract methods, closures/capture `use`, arrow functions, attributes, nested functions, multiple declarations on one line, and strings/interpolation/heredocs containing braces.
- Track body ownership so executing an outer scope or declaring a closure does not mark the inner callable executed.
- Separate structural parsing from executable-line determination. Remove dependence on `get_defined_functions()` for whether a source call is an executable statement.
- Treat unsupported/ambiguous syntax conservatively and report a diagnostic/unknown mapping rather than assigning the next function's range.
- Keep native branch parsing backend-specific. A hand-built token map is not an alternative branch instrumentation engine.

### C4 — Adapt phpdbg and correct collector lifecycle

- Isolate existing oplog operations and verify available executable-line APIs against the installed version.
- Move collection to the explicit suite window and use `try/finally` to stop it.
- Ensure intervals/cumulative snapshots are not counted twice, and that a second run starts clean.
- Test actual `@covers`/source-scope filtering and artifact errors. Where phpdbg cannot support a metric reliably, disclose that limit instead of preserving the old heuristic claim.

### C5 — Implement Xdebug fallback first

- Detect installed version and **active** coverage mode before loading project code.
- Use the documented start/get/stop functions and unused/dead-code flags; enable native branch collection only when the adapter supports its distinct data shape.
- Normalize unused vs dead vs hit lines correctly. Handle already-running coverage explicitly; don't steal or silently reset another tool's collector.
- Add a versioned native branch/edge fixture if branch support is shipped. If that work cannot be verified, ship honest line coverage first and report branches unavailable.
- Verify the ordinary `php ... -j -c` path without any phpdbg executable installed. This is the primary alternate implementation deliverable.

### C6 — Add PCOV line-only fallback

- Verify `pcov.enabled`, directory/exclusion scope, and compatible engine configuration.
- Use documented `pcov\start`, `pcov\stop`, `pcov\collect`, and `pcov\clear` lifecycle operations with explicit ownership and cleanup; confirm exact semantics/version before coding.
- Normalize line presence without claiming branch coverage or invocation counts.
- Detect source paths outside the configured collection scope and surface partial coverage, not false zero/full results.
- Run the same shared line fixtures as Xdebug/phpdbg; allow explicitly documented engine differences in executable-line inventories.

### C7 — Artifacts, schema, and backend matrix

- Add an explicit LCOV output path option (keep `lcov.info` as the default). Validate destination, use a temporary sibling plus atomic rename when possible, and report write failures as exit 2.
- Avoid treating an old file as this run's result after failure; identify current artifacts in the JSON report and document stale-file handling without deleting unrelated user files.
- Serialize optional backend/capability/scope/completeness metadata alongside coverage; coordinate schema definitions/examples with the schema-plan agent.
- Add `tests/coverage_contracts.php` (or equivalent) with extension-free normalization fixtures and real-backend subprocess tests. CI jobs for each supported engine must actually have it installed; distinguish skip/unavailable from pass.

Matrix requirements:

- no extension: ordinary suite succeeds, requested coverage fails before test body;
- phpdbg, Xdebug-enabled, Xdebug-present-but-disabled, PCOV-enabled/mis-scoped;
- explicit wrong backend, auto selection, unsupported active engine combinations;
- console, `-j`, `-r`, clean import/help/list, empty allowed run;
- source loading/top-level code, uncalled/never-loaded files, multiple datasets;
- exceptions, timeouts, provider/load failures, worker termination, unwritable artifacts;
- namespace/method/closure/shared-line structure, sparse/end mappings, real branch edges;
- identical scope between JSON and LCOV, deterministic repeated artifacts, no collector leakage.

Compare shared **line presence** on a controlled common subset across engines; do not demand identical raw hit counts or unsupported branch metrics. Verify emitted LCOV with an independent parser/tool when available and retain golden records in the repository.

### C8 — Document server deployment and remaining limits

Clarify what “server without phpdbg” means:

1. **Running TinyTest via PHP CLI on that server:** Xdebug or PCOV is a feasible replacement when installed/enabled for that CLI binary. Document manual administrator setup and capability diagnostics. Do not automatically change production configuration.
2. **Code executed by HTTP requests in PHP-FPM/Apache or on another machine:** a CLI collector cannot see a different PHP process. This requires opt-in server-side instrumentation, not just choosing a CLI flag.
3. **No extension installation permitted:** run the same source/tests in a controlled CI/container environment with an engine, or consume an artifact produced by an instrumented environment. Static token inspection is not measured coverage of the uninstrumented server.

For a later HTTP/artifact bridge, first write a separate design: collect inside each request process before application loading, gate instrumentation by trusted server configuration, isolate artifacts per run/request outside the web root, merge with source hashes/path mappings and schema checks, and retrieve through an existing authenticated channel. Never expose an unauthenticated coverage/debug endpoint, evaluate imported PHP/serialized objects, accept arbitrary artifact paths, or enable production instrumentation by default. Source revision mismatch must be an error/diagnostic. This bridge is **not required** to ship the CLI alternate backend and must not be advertised as already supported.

Update README, agent coverage skills/templates, `AGENTS.md`, roadmap, CLI help, and backend compatibility guidance. Remove claims that full branch/function coverage is available when only line evidence exists.

## Acceptance checklist

- [ ] Known single/last/sparse mapping bug is fixed with tests that no longer work around it.
- [ ] JSON/LCOV scopes and totals reconcile; IDs/output are deterministic; unknown/unavailable data is explicit.
- [ ] Unsupported branches/function counts are not fabricated from token/line heuristics.
- [ ] Ordinary PHP CLI produces verified line coverage through Xdebug without phpdbg; PCOV is separately implemented/tested or explicitly left as a documented remaining milestone.
- [ ] No backend leaves collection running or starts during import/help/list/JSON supervision in the parent.
- [ ] Missing/misconfigured engines and failed artifact writes produce actionable exit-2 reports without corrupting JSON.
- [ ] Real backend tests, not just mocks/token fixtures, substantiate each advertised capability.
- [ ] CLI/server-process limitations and extension-free limitations are clear to agents and users.

Deliver incrementally: boundary regression → normalized model/scope/LCOV → structural repair → phpdbg lifecycle → Xdebug line coverage → verified native branches/PCOV → matrix/docs. Correctness and honest unavailable metrics take precedence over preserving misleading coverage percentages.
