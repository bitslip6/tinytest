---
name: test-report
description: Generate a read-only project-wide coverage dashboard. Runs phpdbg coverage, reads test_sources.md, and produces a prioritized report of coverage gaps, unresolved annotations, and files needing attention — without generating any new tests. Requires phpdbg.
argument-hint: "[tests-path]"
---

# Test Report — Project Coverage Dashboard

Generate a read-only snapshot of the project's test health: coverage by file, unresolved annotations, and a prioritized list of where to focus next. No tests are generated or modified.

Use this skill to:
- Get a quick status check before starting a session
- Decide where to focus `/test-cover-file` effort
- Prepare a coverage summary before a PR or release

## Step 1: Run Coverage

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -j -c -d <tests_path>
```

Default `<tests_path>` is `tests/`. Override if the user specifies a path argument.

Parse the JSON output. Collect from the `coverage` key:

```json
{
  "coverage": {
    "/path/to/src/Foo.php": {
      "functions_total": 10,
      "functions_covered": 7,
      "uncovered_functions": [
        {"name": "parse_header", "line": 42}
      ]
    }
  }
}
```

Also collect the test run summary:
```json
{
  "summary": {
    "total": 120,
    "passed": 115,
    "failed": 3,
    "incomplete": 2,
    "skipped": 4,
    "ambiguous": 1
  }
}
```

If phpdbg is not available, run without coverage to at least get the test summary:

```bash
php <tinytest_path>/tinytest.php -j -d <tests_path>
```

Note that without phpdbg, per-file coverage data will not be available — report test results only.

## Step 2: Read test_sources.md

Open `tests/test_sources.md`. Parse all entries. Each entry looks like:

```
source: src/foo.php  test: tests/test_foo.php  [75%]
```

Build a map of source file → test file → last-recorded status.

Also scan the project for any `.php` source files (excluding vendor/, tests/, tinytest itself) that have **no entry** in `test_sources.md` — these are completely untested files.

## Step 3: Scan for Unresolved Annotations

Read every test file in the tests directory. For each test function, look for:

- `@ambiguous` — behavior is unclear, needs a decision
- `@todo` — marked for future work but not done
- `@skip` — intentionally skipped (note the reason)

Collect: annotation type, test function name, file, line, and the reason text.

## Step 4: Build the Report

Calculate per-file status by merging coverage JSON data with `test_sources.md`. For each source file:

- **Function coverage %**: `functions_covered / functions_total * 100` from the JSON (live data)
- **Recorded status**: the `[X%]` value stored in `test_sources.md` (last saved value)
- **Gap**: if live coverage differs from recorded, note the drift

Assign a health indicator to each file:
- 🔴 **Critical**: no test file, or < 50% function coverage, or has failing tests
- 🟡 **Needs work**: 50%–79% function coverage
- 🟢 **Good**: 80%–99% function coverage
- ✅ **Complete**: 100% function coverage

Sort files within each tier by coverage percentage ascending (lowest first = needs most work).

## Step 5: Write the Report File

Write the full report to `tests/coverage_report.md`:

```markdown
# Test Coverage Report
Generated: <date>

## Summary

| Metric | Value |
|---|---|
| Source files tracked | 18 |
| Source files with no test | 3 |
| Tests total | 120 |
| Tests passing | 115 |
| Tests failing | 3 |
| Tests incomplete (IN) | 2 |
| Tests skipped | 4 |
| Tests ambiguous | 1 |
| Overall function coverage | 67% (82/122 functions) |

## Coverage by File

### 🔴 Critical (< 50% or no tests)

| File | Test File | Functions | Coverage |
|---|---|---|---|
| src/HttpClient.php | tests/test_httpclient.php | 3/12 | 25% |
| src/Database.php | tests/test_database.php | 1/8 | 12% |
| src/Mailer.php | ❌ no test file | —/6 | 0% |

### 🟡 Needs Work (50–79%)

| File | Test File | Functions | Coverage |
|---|---|---|---|
| src/Parser.php | tests/test_parser.php | 5/8 | 62% |
| src/Validator.php | tests/test_validator.php | 6/9 | 66% |

### 🟢 Good (80–99%)

| File | Test File | Functions | Coverage |
|---|---|---|---|
| src/Util.php | tests/test_util.php | 8/9 | 88% |

### ✅ Complete (100%)

| File | Test File | Functions | Coverage |
|---|---|---|---|
| src/Config.php | tests/test_config.php | 4/4 | 100% |
| src/Router.php | tests/test_router.php | 7/7 | 100% |

## Failing Tests

| Test | File | Error |
|---|---|---|
| test_parse_header_strict | tests/test_parser.php | expected [array] got [null] |
| test_validate_zip_four_digit | tests/test_validator.php | expected [false] got [true] |
| test_http_client_timeout | tests/test_httpclient.php | expected [RuntimeException] — no exception thrown |

## Incomplete Tests (IN — counted as failures)

| Test | File |
|---|---|
| test_database_reconnect | tests/test_database.php |
| test_mailer_send_plain | tests/test_mailer.php |

## Unresolved Annotations

### @ambiguous (needs a decision)
| Test | File | Reason |
|---|---|---|
| test_parse_header_malformed | tests/test_parser.php | unclear if null or exception is correct on bad input |

### @todo (planned but not done)
| Test | File | Reason |
|---|---|---|
| test_http_client_retry | tests/test_httpclient.php | retry logic not yet implemented |

### @skip (intentionally skipped)
| Test | File | Reason |
|---|---|---|
| test_database_connection_live | tests/test_database.php | requires live database — infrastructure test |

## Recommended Next Steps

1. 🔴 `src/Mailer.php` — no test file at all. Run: `/test-generate src/Mailer.php`
2. 🔴 `src/Database.php` — 12% coverage and 1 incomplete test. Run: `/test-cover-file src/Database.php`
3. 🔴 `src/HttpClient.php` — 25% coverage and 1 failing test. Run: `/test-fix tests/test_httpclient.php` then `/test-cover-file src/HttpClient.php`
4. ❗ 3 failing tests — run `/test-fix` to diagnose and repair
5. ❗ 2 incomplete (IN) tests — run `/test-improve tests/test_database.php` and `/test-improve tests/test_mailer.php`
6. ❓ 1 ambiguous annotation in test_parser.php — run `/test-analyze src/Parser.php parse_header` to resolve
```

## Step 6: Print Terminal Summary

Print a condensed version to the terminal (not the full markdown):

```
Test Report — <project_name>
─────────────────────────────────────────

Test Run:  115/120 passing  |  3 failing  |  2 incomplete  |  4 skipped

Coverage:  67% overall  (82/122 functions across 15 files)

  🔴 Critical (3 files):   src/Mailer.php [no tests]
                            src/Database.php [12%]
                            src/HttpClient.php [25%]

  🟡 Needs work (2 files): src/Parser.php [62%]
                            src/Validator.php [66%]

  🟢 Good (1 file):        src/Util.php [88%]

  ✅ Complete (2 files):   src/Config.php, src/Router.php

Attention needed:
  ❗ 3 failing tests       — run /test-fix to diagnose
  ❗ 2 incomplete (IN)     — run /test-improve on those test files
  ❓ 1 @ambiguous          — tests/test_parser.php:test_parse_header_malformed
  📋 1 @todo               — tests/test_httpclient.php:test_http_client_retry

Full report written to: tests/coverage_report.md

Suggested focus:  /test-generate src/Mailer.php
```
