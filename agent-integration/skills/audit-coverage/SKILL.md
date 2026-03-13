---
name: audit-coverage
description: Master agent workflow that scans a project for functions missing code coverage, dispatches sub-agents to analyze each function in context, generates tests for all code paths, and surfaces potential bugs and ambiguities to the operator. Requires phpdbg for coverage.
argument-hint: "[tests-path] [source-path]"
---

# Audit Coverage — Master Orchestrator

Scan a project for functions without test coverage, analyze each one in context, generate comprehensive tests, and report potential bugs to the operator.

## Step 1: Check Prerequisites

```bash
which phpdbg
```

If not found:
> Code coverage requires phpdbg. Install with: `sudo apt install php-phpdbg` (Debian/Ubuntu).

Find the tinytest path from `CLAUDE.md` in the project root. If no `CLAUDE.md`, ask the user for the tinytest installation path.

## Step 2: Run Coverage Scan

Run all existing tests with coverage and JSON output:

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -j -c -d <tests_path>
```

- Default `<tests_path>` is `tests/`. Override if the user specifies a path argument.
- Parse the JSON output. The `coverage` key maps source files to their uncovered functions:

```json
{
  "coverage": {
    "/path/to/src/Foo.php": {
      "functions_total": 10,
      "functions_covered": 7,
      "uncovered_functions": [
        {"name": "parse_header", "line": 42},
        {"name": "validate_input", "line": 108}
      ]
    }
  }
}
```

If there is no `coverage` key or it is empty, **all functions are covered** — report that and stop.

## Step 3: Build the Work Queue

For each uncovered function, build a context packet:

### 3a. Read the function source

Read the source file at the function's line number. Capture the full function body including its docblock/annotations and any class it belongs to.

### 3b. Find all callers

Use grep/ripgrep to find every place the function is called across the project:

```bash
rg -n "<function_name>\s*\(" --type php -g '!vendor/' -g '!tests/' <project_root>
```

Collect up to 20 callers (file, line, surrounding 3 lines of context). This tells the sub-agent how the function is actually used in production.

### 3c. Identify dependencies

Look for:
- What the function receives (parameters, types)
- What it returns
- What it calls internally (other functions, classes, DB, file I/O, HTTP)
- What it throws
- What globals or superglobals it reads

## Step 4: Present the Work Queue to the Operator

Before dispatching any sub-agents, present the full list:

```
Coverage Audit — 12 uncovered functions found:

src/Parser.php (3/10 functions uncovered):
  1. parse_header (line 42) — called from 5 locations
  2. normalize_value (line 95) — called from 2 locations
  3. extract_metadata (line 160) — called from 1 location

src/Validator.php (2/8 functions uncovered):
  4. validate_postal_code (line 78) — called from 3 locations
  5. check_email_format (line 142) — 0 callers (possibly dead code?)

src/HttpClient.php (1/6 functions uncovered):
  6. build_query_string (line 200) — called from 4 locations

⚠ Functions with 0 callers may be dead code. Include them? (y/n)

Proceed with analysis? (enter numbers to exclude, or press enter for all)
```

Wait for operator confirmation. Remove any they want to skip.

## Step 5: Dispatch Analysis — One Function at a Time

For each function in the work queue, perform the following analysis inline (as the master agent). Do NOT use sub-agents — analyze each function directly.

### 5a. Read and understand the function

Read the full function source. Understand:
- **Every code path** — trace each branch, loop, early return, exception throw
- **Guard clauses** — what inputs are rejected and how
- **Edge cases** — empty arrays, null values, zero, negative numbers, empty strings, boundary values
- **Side effects** — does it modify state, write files, query databases, call external services?

### 5b. Read the callers

For each caller found in Step 3b, read the surrounding context (5-10 lines around the call). Understand:
- What actual values/types are passed in practice
- Whether callers handle the return value or exceptions
- Whether callers depend on specific behavior that isn't obvious from the function signature

### 5c. Identify potential bugs and ambiguities

Flag any of the following:
- **Missing input validation** — function accepts values that could cause errors but doesn't check
- **Inconsistent return types** — some paths return a value, others return null/false without documentation
- **Silent failures** — errors caught and swallowed, empty catch blocks, error conditions that return default values
- **Type confusion** — loose comparisons where strict is needed, string/int mixing
- **Race conditions** — shared state modification without locking
- **Unchecked dependencies** — calling functions that could fail without checking their return
- **Off-by-one errors** — array indexing, string slicing, loop bounds
- **Ambiguous behavior** — code that works but it's unclear if the behavior is intentional

### 5d. Generate tests

For the function, generate test functions covering:

1. **Happy path** — normal expected usage (based on how callers actually use it)
2. **Each branch/path** — one test per distinct code path through the function
3. **Guard clauses** — one test per rejection condition, using `@exception` if it throws
4. **Edge cases** — empty inputs, boundary values, type boundaries
5. **Caller-informed tests** — test patterns that reflect how callers actually invoke the function

**Follow all TinyTest conventions:**
- File: `tests/test_<source_name>.php` — append if exists, create if not
- Functions: `test_<function_name>_<description>` in global namespace
- Use ONLY TinyTest assertions: `assert_eq`, `assert_true`, `assert_false`, `assert_contains`, etc.
- Use `@exception` annotation for expected throws (NO try/catch)
- Use `@dataprovider` for multiple input/output combos
- Parameter order: `assert_eq($actual, $expected, "message")`

### 5e. Classify issues found

For each function, classify findings into:

- **🐛 BUG** — code that is demonstrably wrong (will produce incorrect results for valid input)
- **⚠️ SMELL** — code that works but is fragile, unclear, or likely to break under edge cases
- **❓ AMBIGUOUS** — behavior that could be intentional or a bug — needs operator decision
- **✅ CLEAN** — function is correct, tests written, no issues

## Step 6: Run Generated Tests

After generating tests for each function, run them:

```bash
php <tinytest_path>/tinytest.php -v -f tests/test_<name>.php
```

If tests fail:
1. Determine if the test expectation is wrong or the source has a bug
2. If the test is wrong: fix it and re-run
3. If the source has a bug: **do NOT fix the source** — add it to the bug report for the operator
4. Write the test to document the current (buggy) behavior with a comment: `// BUG: see audit report`

## Step 7: Report to Operator

After processing all functions, present a structured report:

```
═══════════════════════════════════════════════
  COVERAGE AUDIT REPORT
═══════════════════════════════════════════════

📊 Summary:
  Functions analyzed: 12
  Tests generated: 34
  All tests passing: ✓

🐛 Bugs Found (3):
──────────────────
  1. src/Parser.php:parse_header (line 42)
     Issue: Returns null instead of throwing when header is malformed.
     Callers at lines 88, 102, 156 all assume non-null return.
     Impact: Silent data corruption in downstream processing.
     Suggested fix: Throw InvalidArgumentException on malformed input.

  2. src/Validator.php:validate_postal_code (line 78)
     Issue: Regex allows 4-digit codes but US zips require 5.
     Evidence: Test with "1234" passes validation but shouldn't.
     Suggested fix: Change regex from /^\d{4,5}$/ to /^\d{5}(-\d{4})?$/

  3. src/HttpClient.php:build_query_string (line 200)
     Issue: Does not URL-encode values containing '&'.
     Evidence: assert_eq(build_query_string(['a' => 'b&c']), 'a=b%26c') fails.
     Suggested fix: Use urlencode() on values.

⚠️ Code Smells (2):
────────────────────
  1. src/Parser.php:normalize_value (line 95)
     Observation: Uses loose comparison (==) for string matching.
     Risk: "0" == false evaluates true, could cause silent mismatches.

  2. src/Parser.php:extract_metadata (line 160)
     Observation: Catches all exceptions with empty catch block.
     Risk: Errors during metadata extraction are completely hidden.

❓ Needs Operator Decision (2):
────────────────────────────────
  1. src/Validator.php:check_email_format (line 142)
     Question: Function has 0 callers. Is this dead code to remove,
     or planned functionality to keep?

  2. src/Parser.php:parse_header (line 42)
     Question: When the optional $strict parameter is true, the function
     silently truncates long headers to 255 chars. Is this intentional?
     The docblock doesn't mention this behavior.

✅ Clean (5):
  - src/Util.php:format_date (line 12)
  - src/Util.php:slugify (line 45)
  - src/Util.php:truncate_string (line 78)
  - src/Validator.php:validate_phone (line 200)
  - src/HttpClient.php:parse_response_code (line 150)
```

## Step 8: Handle Operator Decisions

For each ❓ AMBIGUOUS item, ask the operator how to proceed:

1. **Explain the ambiguity** clearly with code references
2. **Present options** — e.g., "Is this intentional? Should I: (a) write a test that locks in current behavior, (b) write a test that asserts the alternative behavior (marking current as bug), (c) skip this"
3. **Execute the operator's choice** — update tests accordingly

For each 🐛 BUG item, ask:
1. "Should I fix this bug now, or just document it in tests?"
2. If fix: apply the fix, update tests, re-run
3. If document: ensure the test captures the buggy behavior with a `// KNOWN BUG:` comment

## Step 9: Final Coverage Re-run

After all tests are written and operator decisions are handled:

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -j -c -d <tests_path>
```

Report the improvement:
```
Coverage before: 45 functions covered out of 57 (78.9%)
Coverage after:  55 functions covered out of 57 (96.5%)
Remaining uncovered: 2 functions (operator chose to skip)
```
