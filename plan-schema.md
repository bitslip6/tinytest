# Implementation handoff: versioned JSON reports and exact dataset reruns

**Status: plan only.** Read `AGENTS.md` first and preserve the current working tree. Proposed field/flag names below are a concrete design to implement and test, not existing features.

## Objective

An agent should be able to discover test functions/cases, validate a report against a published schema, identify **every** failing dataset, and rerun exactly one case without parsing human messages or executing other test bodies.

Preserve the current reliable exit/error/JSON transport contracts. Keep the default function-level view compatible; do not silently reinterpret `tests` or `summary` as dataset counts.

## Dependencies and scope

Prefer completing [plan-thin-entry-refactor.md](plan-thin-entry-refactor.md) and [plan-safe-import.md](plan-safe-import.md) first. If they are not finished, locate the functions below by name and coordinate edits rather than creating parallel result implementations.

This plan owns the wire contract, case identity, enumeration/selection semantics, and report aggregation. [plan-code-cverage.md](plan-code-cverage.md) owns measurement semantics; agree on optional `coverage`/`coverage_meta` fields before publishing the schema. Per-case coverage is **not** required here.

Do not add parallel execution, NDJSON streaming, arbitrary result-value serialization, or a new assertion DSL in this work.

## Current implementation to inspect

- `src/cli.php`: `empty_report`, `encode_report`, `add_runner_error`, `supervise_json`, `run_cli`.
- `tinytest.php`: `TestResult`, `do_test`, `run_test`, `run_suite`; after extraction, use `src/results.php`, `src/runner.php`, `src/application.php`, and reporters.
- `src/options.php`: strict parsing, JSON-intent detection, validation; only `--json`, `--help`, and `--allow-empty` currently exist as long flags.
- `assertions.php`, `src/execution_state.php`: sticky failures and explicit `assert_fails` must remain intact.
- `tests/cli_contracts.php`, `tests/boundary_contracts.php`, `tests/fixtures/outcomes.php`.

Current facts:

- `version: 12` is the framework version, not a schema version.
- `tests[]` aggregates each function. Provider results are reduced to one error and combined output; earlier dataset failures can disappear from the payload.
- `TestResult.dataset` holds a stringified key. Key type, duplicate-key occurrence, and per-case duration are not preserved.
- Provider-level assertion counts are currently added to the first result. Empty providers/provider failures produce synthetic `TestResult` objects. These cannot be blindly serialized as real dataset executions.
- Skip/TODO does not invoke providers. Ordinary `-l` lists functions and does not evaluate providers.
- Root `errors` describes runner failures; case failures do not belong there. Exit 2 takes precedence over test exit 1.
- JSON transport captures root stdout/stderr, substitutes invalid UTF-8, caps stream previews at 64 KiB and worker reports at 16 MiB. Keep these protections.

## Proposed v1 contract

### Versioning and publication policy

- Add required integer `schema_version: 1`, independent of existing numeric `version`.
- Publish `schemas/report-v1.schema.json` using JSON Schema Draft 2020-12 and a stable identifier such as `urn:tinytest:report:1`.
- Commit the schema, local examples, documentation, and validation tooling together; include schema files in release artifacts. A repository-relative link is useful immediately; do not invent a live hosted URL. Add a hosted URL only when publication is actually configured.
- Breaking type/meaning/removal changes require a new schema major/file; do not overwrite a released contract with incompatible rules.
- Permit additive unknown properties for forward compatibility, while validating all defined fields and required discriminators. Document this choice explicitly; semantic regression fixtures still detect accidental missing/misspelled known fields.
- Keep previous major schemas/examples in the repository if another version is introduced. Do not publish an incomplete v1 and then make previously optional fields mandatory under the same released version.

### Envelope

Retain `version`, `tests`, `summary`, `errors`, `exit_code`, optional `coverage`, root `output`, and `help`.

Add:

| Field | Meaning |
|---|---|
| `schema_version` | Wire contract major, not framework release |
| `mode` | `run`, `list`, `list_cases`, `help`, or `error` for pre-execution/transport failures without a normal report |
| `complete` | Whether requested execution/inventory completed; false for early worker termination or incomplete discovery, not merely for a failed assertion |
| `project_root` | Canonical root used for portable file identities, fixed before project code can change CWD; null if an early error prevents resolving it |
| `results_filtered` | Whether `-x` omitted result entries; totals always describe the selected execution, not just displayed entries |

Runner errors can also accompany a completed `run` report, e.g. cleanup/shutdown failure. The supervisor must add errors/change exit without dropping valid completed results. For an absent/unparseable worker report, emit the schema-valid minimal `error` variant. Help, list, allow-empty, unavailable backend, parse error, and oversized-report fallbacks must all validate—not only successful runs.

Root `output` is transport-captured stdout/stderr. Function/case `output` is buffered test output. Do not pretend direct `fwrite(STDOUT)` can always be attributed to one dataset without additional instrumentation.

### Function results and real cases

Keep one entry per function under `tests`. In run mode, retain the current name/file/status/duration/assertion/error fields and add `id`, definition `line`, and `cases`.

Every **executed real case** has:

- `id`, `function_id` matching its parent, definition location, duration, assertion count, status, captured output;
- `dataset: null` for a non-provider invocation, otherwise a typed key/occurrence descriptor;
- optional structured error with class/message/file/line/severity and optional bounded trace; explicit truncation flags when output/diagnostic previews are shortened;
- a reproducible selector/argument recipe supplied as an **argv array**, not a shell command string.

Rules:

- Every dataset failure is retained in `cases[]`, not just the last one. Preserve raw `Throwable` information until serialization; don't flatten errors during execution.
- Empty provider: parent IN with a provider diagnostic and zero executed cases.
- Provider factory/generation failure: parent FAIL with a separate `provider` diagnostic; retain any cases already completed. Do not invent a row claiming the provider itself was a dataset.
- Skip/TODO: parent result, zero executed cases; do not invoke the provider to count hypothetical rows.
- Provider assertions/duration/output belong in a separate `provider` accounting record, not the first case. Loading/cleanup checks are not dataset assertions.
- Parent assertion total equals case assertions plus provider assertions. Root assertion totals reconcile with selected function totals; intentional `assert_fails` callbacks count as one check as today.
- Parent status precedence: FAIL if any real case/provider fails; otherwise IN if any case is incomplete or provider is empty; otherwise OK. Ambiguous remains an orthogonal flag.
- Preserve legacy representative parent `error` for existing consumers, but document that `cases`/`provider` contain the complete diagnostics. Never choose an incomplete diagnostic over an actual failure.

Add a separate `summary.cases` with counts of **executed** cases by outcome. Existing summary totals remain functions; TODO remains included in existing skipped count. Do not manufacture executed-case counts in list modes. For a dataset subset, totals describe the subset actually selected, with selection metadata making that scope explicit.

### Case identity and selection

Implement one precise case selector before adding fuzzy matching:

```sh
php tinytest.php -j -f tests/test_parser.php -t test_parse --list-cases
php tinytest.php -j -f tests/test_parser.php -t test_parse --case '<returned-case-id>'
```

- `--case` requires a single `-t` for this first version, so an opaque ID does not force enumeration of unrelated providers. Reject invalid combinations before project execution.
- Preserve `-t` as the existing exact **function** selector.
- Add `--project-root <directory>` for explicit identity control; default to invocation CWD, captured before loading code. IDs use canonical project-relative file paths and normalized PHP function names. For files outside that root, use an explicitly marked canonical absolute identity and document reduced portability.
- Use parent function IDs `ttf1:` plus SHA-256 of canonical file identity and normalized function name. Use case IDs `ttc1:` plus SHA-256 of a canonical identity tuple: file identity, function name, singleton/provider discriminator, observed key type/value, and duplicate-key occurrence. Do not include assertion count, timing, data values, status, absolute checkout location for in-root files, or unique-key enumeration ordinal.
- Preserve observed integer vs string keys; PHP arrays may already coerce numeric-looking keys, while generators can differ. Encode integer keys as decimal strings in metadata to avoid JSON consumer precision loss. Preserve invalid UTF-8 string keys with an explicit base64 encoding descriptor; display substitution must not alter identity.
- Assign duplicate generator-key occurrences deterministically as encountered, even when filtering. Expose enumeration ordinal as metadata, not as the sole identity. Document that IDs depend on deterministic provider keys/duplicate order and do not survive arbitrary provider changes.
- For this version, reject unsupported provider key types with a provider diagnostic rather than stringifying arbitrary objects or invoking `__toString`. A null **data value** remains a valid one-argument case, unrelated to key validity.
- Use one shared identity function in enumeration, execution, and selectors. Never separately reconstruct IDs in reporters.
- Missing case after complete provider enumeration: exit 2, even with `--allow-empty`. No unselected test body may execute.

Provider iteration policy must be explicit: preserve streaming behavior and invoke a matching case at its normal yield point; iterate the provider once, without buffering/serializing all data values, and continue to surface later provider failures. If enumeration fails before the selected ID is resolved, report a selection runner error (exit 2) with the provider diagnostic; if the case was resolved/executed and the provider later fails, the function fails (exit 1). Preserve completed results in either situation. Add tests for both paths.

`--list-cases` is an **opt-in provider-evaluating query**: no test bodies run, but factories/generators and file loading can have side effects. Ordinary `-l` remains provider-free. Do not expand skipped/TODO providers. Enumeration failure returns a partial inventory with structured diagnostics and exit 2. Never advertise either discovery mode as a security sandbox.

## Work packages

### J1 — Freeze semantics and capture the old interface

- Run existing verification commands and capture representative v12 reports for all modes/errors.
- Write `docs/results.md` with the above outcome/identity/version rules and a migration table for old consumers.
- Audit legacy `count_assertion()`-only custom checks before imposing assertion pass/fail sum constraints. Do not invent a pass/fail classification for a total-only increment: document an unclassified-count representation or an explicit migration. Function/case outcomes must remain independent of that accounting.
- Resolve any schema/coverage metadata conflict with the coverage-plan agent before freezing required fields. Current inaccurate coverage values are not made trustworthy just by validating their shape.

### J2 — Preserve case records before serialization

- Extend `TestResult` or introduce one small case result record in `results.php`; keep the public plain-function test API.
- Record dataset identity and duration in the runner, separately from returned strings/captured output.
- Replace provider synthetic-case/accounting shortcuts with explicit provider records and real case lists.
- Centralize parent/case aggregation; keep output filtering after aggregation. Retain all case outcomes for a failing function in `-x` initially, rather than introducing another hidden case filter.
- Bound function and case output previews (recommend 64 KiB each) with `output_truncated` flags; define equivalent bounds/flags for long error previews and traces. Avoid duplicating unlimited output in both parent and cases. This is an explicit migration from currently unbounded case-buffered text, not a claim that serializer truncation alone bounds execution memory. Never silently omit case outcomes to fit the transport budget; preserve the structured oversized-report error.
- Verify sticky assertion scopes and cleanup for every provider row, including null values, thrown exceptions, PHP-error expectations, and timeouts.

### J3 — Add enumeration and exact selection

- Implement `--list-cases`, `--case`, and `--project-root` through the existing parser/normalizer/validator; update JSON-intent scanning where value-taking long flags affect it.
- Parse/validate flags before code loading. Keep worker arguments and project root stable across parent/child and different CWDs.
- Share one provider iterator/identity path; do not call a provider twice to list then run during a single invocation.
- Use fixtures that append markers from every test body/provider to prove which code did and did not execute.

### J4 — Write schemas and real examples

Create:

```text
schemas/report-v1.schema.json
docs/results.md
docs/examples/json/          generated/checked examples by report mode
tests/schema_contracts.php   fixture producer + semantic assertions
tests/selector_contracts.php independent CLI selection checks
```

Use `$defs`/local references for errors, summaries, function results, case identities, provider diagnostics, output truncation, and optional coverage data. Define discriminator-dependent fields with `oneOf` or equivalent constraints; list entries are not run outcomes.

Specify nonnegative integer counts, finite nonnegative durations, status enums, legal exit codes, and required error fields. Don't impose an incorrect positive-only line constraint on internal/unknown locations. Unknown locations should have an explicit consistent representation, not fabricated source positions.

Validate generated real outputs, not only hand-written examples. Use a maintained Draft 2020-12 validator as a **development/CI-only dependency** (for example a pinned Python `jsonschema` tool environment); do not make Composer/runtime dependencies mandatory and do not write a home-grown JSON Schema engine. Keep lightweight semantic assertions in the independent PHP harness.

### J5 — Wire every serializer and worker fallback

- Centralize envelope creation and encoding in the JSON reporter. Make `empty_report`, help, list, runner failures, and supervisor-created fallbacks emit the same schema version/mode rules.
- Preserve UTF-8 substitution, report-size limits, captured streams, and cleanup-before-final-report behavior.
- Strengthen private worker-envelope checks for required field types/exit range/version; distinguish unsupported schema from an absent/corrupt report. The private channel is not a malicious-code security boundary.
- Return a schema-valid runner error on unsupported/corrupt worker payloads, without echoing the raw payload.
- Keep exact shell-safe argv recipes in docs/output; never interpolate dataset labels into a command string. Prefer logical runner arguments plus a required CWD, so consumers prepend their own PHP/runner executable. Include necessary bootstrap/selection arguments, but never dump all startup INI settings, environment variables, or provider data into a rerun recipe.

### J6 — Validate modes, identities, and migration

Required fixtures:

- Every existing success/failure/help/list/empty/worker-error shape, plus invalid UTF-8, per-record/root truncation, and large multi-dataset reports crossing the transport limit.
- Two or more failing datasets: all errors and correct locations survive.
- Integer/string/numeric-looking/empty/Unicode/punctuation keys; duplicate generator keys; invalid-UTF-8 labels; null data; unsupported key types.
- Provider failure before the first row, after successful rows, before/after a selected case; empty provider; skip/TODO provider not invoked.
- Stable IDs across repeated processes and relocated checkouts with the same explicit project root; documented external-file behavior.
- Exact case rerun executes one body, preserves its identity, and does not execute other providers/functions. Invalid/conflicting/unmatched selectors fail as specified.
- `-x` preserves authoritative totals, failure precedence, and all cases belonging to a shown failing function.
- Semantic equations for function/case/assertion/provider counts. JSON Schema alone cannot express every cross-field sum.
- Negative schema fixtures: wrong types, missing required discriminators/fields, invalid statuses/exit codes, malformed case IDs, and incompatible version.

Run the existing three commands, import contracts when present, and the two new harnesses; provide one documented command for the real schema validator too. Test emitted report schemas in CI with network access unnecessary at validation time (local schemas and pinned tooling).

### J7 — Publish and update discovery documentation

- Include schema files and examples in the directory distribution/release artifact, with a migration note for added case data and provider accounting.
- Update README, `AGENTS.md`, agent templates/skills consuming JSON, CLI help, and the roadmap.
- Provide the agent recipe: ordinary inventory → explicit case inventory if needed → exact returned argv → inspect full case diagnostics → rerun.
- Do not claim v1 published until fixtures validate, selector regressions pass, and the referenced schema file is actually distributed.

## Acceptance checklist

- [ ] Every JSON path, including supervisor-generated errors, validates against a published local versioned schema.
- [ ] Function summary compatibility is retained; case/provider counts are separate and reconcile.
- [ ] Every dataset outcome/error remains visible; exact selectors round-trip from inventory to execution.
- [ ] Providers are not silently executed during ordinary list mode or more than once per invocation.
- [ ] IDs are deterministic under documented conditions and safe to pass as argv values.
- [ ] Unknown/missing selectors and incomplete provider enumeration never produce false success.
- [ ] No new runtime validator dependency, lost JSON isolation, or weakened sticky-assertion behavior.
