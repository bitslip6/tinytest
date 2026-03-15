---
name: test-audit
description: Project-wide coverage audit — runs phpdbg across all tests, finds every uncovered function, analyzes each one in context with caller data, generates comprehensive tests for all code paths, and produces a structured bug/smell report with operator decisions. Requires phpdbg.
argument-hint: "[tests-path]"
---

# Test Audit — Full Coverage Audit with Bug Report

Run coverage across the project, find every uncovered function, analyze each in context, generate comprehensive tests, and surface bugs and ambiguities to the operator.

**Prerequisite:** Run `/test-bootstrap` first if any source files are missing test files (i.e., have `[NEW]` status in `test_sources.md`).

## Step 1: Run Coverage Scan

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -j -c -d <tests_path>
```

Default `<tests_path>` is `tests/`. Override if the user specifies a path argument.

Parse the JSON output. The `coverage` key maps source files to uncovered functions:

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

If there is no `coverage` key or it is empty, all functions are covered — report that and stop.

Write a `tests/audit_todo.md` file with the full list of uncovered functions for reference.

## Step 2: Build the Work Queue

For each uncovered function, collect context:

### 2a. Read the function source

Read the source file at the function's line number. Capture the full function body including its docblock and any class it belongs to.

### 2b. Find all callers

```bash
rg -n "<function_name>\s*\(" --type php -g '!vendor/' -g '!tests/' <project_root>
```

Collect up to 20 callers (file, line, 3 lines of surrounding context).

### 2c. Identify dependencies

- Parameters and types
- Return type
- What it calls internally (other functions, DB, file I/O, HTTP)
- What it throws
- Globals or superglobals it reads

## Step 3: Present the Work Queue to the Operator

Before proceeding, display the full list:

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

## Step 4: Analyze Each Function

For each function in the approved work queue, analyze inline — do NOT spawn sub-agents.

### 4a. Trace every code path

For each distinct path through the function, document:
- **Entry conditions** — what input leads to this path
- **Operations** — what the code does on this path
- **Exit** — return value, exception thrown, or side effect
- **Guard clauses** — what inputs are rejected and how
- **Edge cases** — empty, null, zero, negative, boundary values
- **Side effects** — file I/O, DB, HTTP, state mutation

### 4b. Read the callers

For each caller found in Step 2b, read 5–10 lines of surrounding context:
- What values are actually passed
- Whether callers check the return value or handle exceptions
- Whether callers depend on behavior not obvious from the function signature

### 4c. Identify potential bugs

Flag any of the following:
- **Missing input validation** — accepts values that could cause errors without checking
- **Inconsistent return types** — some paths return a value, others return null/false undocumented
- **Silent failures** — errors swallowed in empty catch blocks, error conditions that return defaults
- **Type confusion** — loose comparisons (`==`) where strict (`===`) is needed
- **Unchecked dependencies** — calling functions that could fail without checking their return
- **Off-by-one errors** — array indexing, string slicing, loop bounds
- **Ambiguous behavior** — code that works but it's unclear if the behavior is intentional

Classify each finding:
- **🐛 BUG** — demonstrably wrong for valid input (evidence: specific input → wrong output)
- **⚠️ SMELL** — works but fragile or unclear, likely to break under edge cases
- **❓ AMBIGUOUS** — could be intentional or a bug — needs operator input
- **✅ CLEAN** — correct and well-handled

### 4d. Generate tests

Create or append to `tests/test_<source_name>.php`. For each function, generate:

1. **Happy path** — normal expected usage based on how callers actually use it
2. **Each branch/path** — one test per distinct code path
3. **Guard clauses** — one test per rejection condition; use `@exception` for throws
4. **Edge cases** — empty inputs, boundary values, null optionals
5. **Caller-informed tests** — patterns that reflect actual production usage

Follow all TinyTest conventions (see `/test-generate` for the full assertion and annotation reference):
- `assert_eq($actual, $expected, "message")` — actual before expected, message last
- `@exception` for throw paths — no try/catch
- `@dataprovider` for multiple input/output combos
- `@ambiguous <reason>` if behavior is unclear
- For bugs: write a test documenting current (buggy) behavior with `// BUG:` comment

## Step 5: Run Generated Tests

After generating tests for each function, run the file:

```bash
php <tinytest_path>/tinytest.php -v -f tests/test_<name>.php
```

If tests fail:
1. Determine if the test expectation is wrong or the source has a bug
2. Fix wrong test expectations and re-run
3. If the source has a bug — do NOT fix the source — document it with `// BUG: see audit report` and add it to the report

## Step 6: Report to Operator

```
═══════════════════════════════════════════════
  TEST AUDIT REPORT
═══════════════════════════════════════════════

📊 Summary:
  Functions analyzed: 12
  Tests generated: 34
  All tests passing: ✓

🐛 Bugs Found (3):
──────────────────
  1. src/Parser.php — parse_header (line 42)
     Issue: Returns null on malformed input; all 5 callers assume non-null return.
     Impact: Silent data corruption in downstream processing.
     Suggested fix: Throw InvalidArgumentException on malformed input.

  2. src/Validator.php — validate_postal_code (line 78)
     Issue: Regex allows 4-digit codes but US zips require 5.
     Evidence: validate_postal_code("1234") returns true but should return false.
     Suggested fix: Change /^\d{4,5}$/ to /^\d{5}(-\d{4})?$/

  3. src/HttpClient.php — build_query_string (line 200)
     Issue: Does not URL-encode values containing '&'.
     Evidence: build_query_string(['a' => 'b&c']) returns 'a=b&c' instead of 'a=b%26c'.
     Suggested fix: Use urlencode() on values.

⚠️ Code Smells (2):
────────────────────
  1. src/Parser.php — normalize_value (line 95)
     Observation: Uses loose comparison (==) for string matching.
     Risk: "0" == false evaluates true, could cause silent mismatches.

  2. src/Parser.php — extract_metadata (line 160)
     Observation: Empty catch block silently swallows all exceptions.
     Risk: Errors during metadata extraction are completely hidden.

❓ Needs Operator Decision (1):
────────────────────────────────
  1. src/Validator.php — check_email_format (line 142)
     Question: Function has 0 callers. Dead code to remove, or planned functionality to keep?

✅ Clean (6):
  - src/Util.php: format_date, slugify, truncate_string
  - src/Validator.php: validate_phone
  - src/HttpClient.php: parse_response_code, build_headers
```

## Step 7: Handle Operator Decisions

For each ❓ AMBIGUOUS item:
1. Explain the ambiguity clearly with code references
2. Present options: (a) lock in current behavior with a test, (b) assert the alternative behavior and mark current as a bug, (c) skip
3. Execute the operator's choice — update tests accordingly

For each 🐛 BUG item, ask:
1. "Fix this bug now, or document it in tests only?"
2. If fix: apply the fix, update tests, re-run
3. If document: ensure the test captures the buggy behavior with a `// KNOWN BUG:` comment

## Step 8: Final Coverage Re-run

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -j -c -d <tests_path>
```

Update `tests/test_sources.md` with the new coverage percentage for each file improved.

Report the delta:
```
Coverage before: 45/57 functions (78.9%)
Coverage after:  55/57 functions (96.5%)
Remaining:        2 functions (operator chose to skip)
```
