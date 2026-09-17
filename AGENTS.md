# TinyTest contributor guide

TinyTest is a zero-required-library PHP test runner for plain global functions.
Read this file first; use the map below rather than reading the entire runner.
PHP 7.4 is the syntax baseline; current verification is on PHP CLI 8.5.

## Verify changes

From the repository root:

```sh
php -d zend.assertions=1 tinytest.php -j -d tests/
php tests/cli_contracts.php
php tests/boundary_contracts.php
```

Lint changed PHP files with `php -l path/to/file.php`.
The subprocess harnesses judge real exit codes/JSON independently of TinyTest.
Deliberately failing fixtures are under `tests/fixtures/`; do not run them as a normal suite.
The existing `fatals()` unit exercise writes deliberate stderr; JSON captures it.
Coverage/profiling require optional backends and separate verification.

## Find the owner

| Task | Implementation | Tests |
|---|---|---|
| Argument parsing, paths, bootstrap resolution, backend preflight | `src/options.php` | `tests/boundary_contracts.php`, `tests/test_tinytest.php` |
| CLI errors, exit codes, JSON supervision/serialization | `src/cli.php` | `tests/boundary_contracts.php`, `tests/cli_contracts.php` |
| Sticky assertion scopes and PHP diagnostics | `src/execution_state.php` | `tests/test_execution.php`, boundary contracts |
| Public assertion API and `assert_fails` | `assertions.php` | `tests/test_assertions.php`, `tests/test_object_assertions.php` |
| Discovery, annotations, case/provider execution, aggregation | `tinytest.php`: `load_file`, `load_dir`, `do_test`, `run_test`, `run_suite` | `tests/test_tinytest.php`, CLI contracts |
| Coverage/profiling (still embedded) | `tinytest.php`: `coverage_to_lcov`, `output_profile` | `tests/test_tinytest.php` |
| Project overrides | `user_defined.php`, `run_suite` | boundary contracts |
| Consumer setup/instructions | `agent-integration/` | setup smoke tests still planned |

Call chain: `tinytest.php` → `run_cli` → validate → optional JSON worker →
`init` → `run_suite` (load → select → execute → aggregate) → cleanup → report.
The same script is the worker; descriptor 3 carries its report independently
of project stdout/stderr. Private worker environment markers are cleared before project code.

## Test and assertion contracts

- Test files: `test_*.php`; functions: `test_*`, `it_*`, or `should_*`.
- Run one test: `php tinytest.php -j -f tests/test_assertions.php -t test_assert_eq_passes_on_strict_equal`.
- No PHPUnit classes or `$this->assertEquals()`; use global assertion functions.
- Actual before expected; haystack before needle; message last (legacy debug output may follow).
- Prefer `assert_array_has`; legacy `assert_array_contains` is needle-first and loose.
- A caught failed assertion still fails its case. Never clear counters to make tests pass.
- Use `assert_fails(fn() => assert_eq(1, 2, 'intentional'), 'must fail')` when testing an assertion failure. It isolates only that callback and counts one check.
- Every dataset needs a TinyTest assertion or matched exception/PHP-error expectation; otherwise it is incomplete. Native PHP `assert()` is not counted.
- Exit 0: success; 1: failed/incomplete cases; 2: invocation, loading, cleanup, or worker errors.
- Empty selection fails unless `--allow-empty`; unmatched `-t` always fails.
- JSON summaries count functions, not datasets; `summary.assertions` holds assertion totals. Consult exit code and root `errors`, not just `summary.failed`.
- Do not print from JSON reporters. Project output belongs in captured output fields.
- Honor `@`/`-s` suppression; expected PHP diagnostics must actually occur.

## Keep changes discoverable

Use ordinary functions and explicit requires. Keep new boundary logic in its
named module; do not grow a generic utilities layer or relocate the monolith wholesale.
Preserve global assertions and documented `user_*` hooks. Add a subprocess
regression for every CLI outcome change and update the consumer contract.

See [README.md](README.md) for API/CLI details and [ROADMAP.md](ROADMAP.md)
for implementation status, remaining extraction work, and deployment limits.
The [handoff plan index](ROADMAP.md#implementation-handoff-plans) links the detailed next-step plans.
