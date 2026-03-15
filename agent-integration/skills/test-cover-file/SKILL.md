---
name: test-cover-file
description: Achieve full coverage for a single PHP source file. Phase 1 iterates until every function is called. Phase 2 iterates until every reachable statement and branch is exercised. Requires phpdbg.
argument-hint: "<source-file.php>"
---

# Cover File — Full Function, Line, and Branch Coverage

Given a PHP source file, drive it to complete coverage in two sequential phases:

- **Phase 1 — Function coverage:** ensure every function in the file is called by at least one test.
- **Phase 2 — Line & branch coverage:** ensure every reachable statement and conditional branch inside those functions is exercised.

If the file already has 100% function coverage when this skill starts, Phase 1 is skipped and the skill begins directly at Phase 2.

---

## Step 1: Locate the Test File

1. Read `tests/test_sources.md`.
2. Find the entry matching the source file. Entries look like:

```
source: src/foo.php  test: tests/test_foo.php  [INITIAL]
```

3. If no entry exists, tell the user:
   > No entry found in `test_sources.md` for `<file>`. Run `/test-generate <file>` first to create an initial test file.

   Stop here.

4. If the entry exists but the test file does not exist on disk, tell the user:
   > `test_sources.md` lists `<test_file>` but the file does not exist. Run `/test-generate <source_file>` to create it.

   Stop here.

5. Read the test file and note all existing test function names (any function prefixed with `test_`, `it_`, or `should_`) to avoid generating duplicates later.

---

## Phase 1 — Function Coverage

### Step 2: Check Function Coverage

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -j -c -f <test_file>
```

Parse the JSON `coverage` key:

```json
{
  "coverage": {
    "/absolute/path/to/source.php": {
      "functions_total": 10,
      "functions_covered": 7,
      "covered_functions": [
        {"name": "do_thing", "line": 12}
      ],
      "uncovered_functions": [
        {"name": "parse_header", "line": 42},
        {"name": "validate_input", "line": 108}
      ]
    }
  }
}
```

Find the coverage entry whose path ends with the source filename. If the source file does not appear in coverage output at all, the test file likely does not `require`/`include` the source — add the require and re-run.

**If `uncovered_functions` is empty (or missing): Phase 1 is already complete. Skip to Phase 2 (Step 5).**

Record the starting function coverage as `before_functions_covered / before_functions_total`.

### Step 3: Generate Tests for Uncovered Functions

For each function listed in `uncovered_functions`:

1. **Read the source function** at the reported line number. Identify:
   - Parameters and types
   - Return type
   - Guard clauses that throw exceptions
   - Edge cases (null, empty, boundary values)
   - Dependencies on other functions or classes
   - For complex functions with many branches: consider running `/test-analyze <file> <function_name>` to get a full path map before writing tests.

2. **Generate test functions** following all TinyTest conventions (see `/test-generate` for the full assertion and annotation reference):
   - Use ONLY TinyTest global assertion functions
   - `assert_eq($actual, $expected, "message")` — actual before expected, message last
   - `@exception` annotation for throw paths — do NOT use try/catch
   - `@dataprovider` for multiple input/output combos
   - `@ambiguous <reason>` if correct behavior is unclear
   - Mocks: run `ls tests/mocks/ 2>/dev/null` and look for `Mock<ClassName>.php` matching each external dependency. Use any existing mock as-is. For missing mocks, create `tests/mocks/Mock<ClassName>.php` containing a class `Mock<ClassName>` — follow the mock template in `/test-generate` (standard fields: `$calls`, `$return_value`, `$return_map`, `$should_throw`, `reset()`, `was_called()`, `call_count()`). Add `require_once __DIR__ . '/mocks/Mock<ClassName>.php';` at the top of the test file for each mock used.
   - Generate at minimum: one happy-path test, one test per guard clause, one edge-case test

3. **Append to the existing test file.** Do NOT overwrite or remove existing tests. Add new functions at the end of the file.

4. **Avoid duplicates** — compare new function names against the list from Step 1. If a name collides, use a more specific name (e.g., describe the scenario more precisely).

### Step 4: Run, Fix, Repeat — Functions

1. **Run the updated test file:**

```bash
php <tinytest_path>/tinytest.php -v -f <test_file>
```

2. **If tests fail:**
   - Read the error output (expected vs actual, file, line)
   - Fix the test if the test logic is wrong
   - If the source behavior is ambiguous, mark with `@ambiguous <reason>`
   - Re-run until all tests pass or are marked `@ambiguous`

3. **Re-run coverage:**

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -j -c -f <test_file>
```

4. **Check for remaining uncovered functions.** If any remain, return to Step 3.

5. **If a function cannot be tested** (infrastructure dependency, `exit`/`die` calls), add a documented placeholder and move on:

```php
/**
 * @skip <reason>
 */
function test_<function_name>_untestable(): void {}
```

6. **Continue until** all functions are covered or explicitly skipped.

---

## Phase 2 — Line & Branch Coverage

### Step 5: Run Coverage and Generate lcov.info

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -c -f <test_file>
```

This generates an `lcov.info` file in the current working directory. Confirm the file exists before continuing.

### Step 6: Parse lcov.info for the Source File

Read `lcov.info`. Find the record for the source file being tested. Each record looks like:

```
SF:/absolute/path/to/source.php
FN:<start_line>,<function_name>
FNDA:<hit_count>,<function_name>
FNF:<total_functions>
FNH:<functions_hit>
BRDA:<line>,<block>,<branch>,<hit_count>
BRF:<total_branches>
BRH:<branches_hit>
DA:<line>,<hit_count>
LF:<total_lines>
LH:<lines_hit>
end_of_record
```

Extract three sets of information:

**A. Uncovered statements** — every `DA:<line>,0` entry. Statements never executed.

**B. Uncovered branches** — every `BRDA:<line>,<block>,<branch>,0` entry. Conditional paths never taken (if/else arms, switch cases, ternary paths, null coalescing fallbacks).

**C. Coverage totals** — `LF` (total lines), `LH` (lines hit), `BRF` (total branches), `BRH` (branches hit).

Calculate starting coverage:
- Statement coverage: `LH / LF * 100`
- Branch coverage: `BRH / BRF * 100`

**If both are 100%: skip to Step 9 (update test_sources.md).**

Record these as the Phase 2 starting values.

### Step 7: Analyze Uncovered Lines and Branches

For each uncovered line (`DA:line,0`) and uncovered branch (`BRDA:line,...,0`):

1. **Read the source file** at those line numbers. Read enough context (the enclosing function, surrounding conditionals) to understand what code path reaches that line.

2. **Classify each gap:**

   **a. TESTABLE — guard clause / validation branch:**
   Inside an `if` that checks input validity, type, range, or state. A test can reach it by providing the right input.

   **b. TESTABLE — else/default/fallback path:**
   In an `else`, `default:`, catch block, or fallback path existing tests don't exercise.

   **c. TESTABLE — early return:**
   A `return` inside a conditional that tests haven't triggered.

   **d. UNTESTABLE — process termination:**
   Calls `exit()`, `die()`, or a process-terminating function. Cannot be tested without killing the test runner.

   **e. UNTESTABLE — infrastructure dependency:**
   Requires a real database connection, file system state, network call, or other external resource that cannot be mocked.

   **f. UNREACHABLE — dead code:**
   Cannot be reached under any input.

3. **Group the lines by enclosing function** so tests can be focused.

### Step 8: Generate or Modify Tests for Uncovered Lines

Work function by function:

**For TESTABLE lines:**

1. **Determine the input** that causes execution to reach the uncovered line. Trace the conditionals backward from the uncovered line to the function entry point.

2. **Check if an existing test can be modified.** If a test for this function tests a similar path, modify it — add a case to a `@dataprovider`, or adjust an input. Prefer modifying over adding when the change is small.

3. **Otherwise, create a new test function.** Follow all TinyTest conventions (see `/test-generate` for the full assertion and annotation reference):
   - `@exception` for paths that throw — no try/catch
   - `@dataprovider` when covering multiple related branches
   - All test functions in global namespace, prefixed with `test_`

4. **Name new tests descriptively** to indicate the code path exercised:
   ```php
   function test_parse_header_returns_null_on_empty_input(): void { ... }
   function test_validate_input_rejects_negative_values(): void { ... }
   ```

5. **Append new functions** to the end of the test file. Do NOT remove or overwrite existing tests.

**For UNTESTABLE lines (`exit`/`die`):**

Add a documented skip placeholder if one doesn't already exist from Phase 1:

```php
/**
 * @skip line <N>: <function_name> calls exit() — cannot test without killing the test runner
 */
function test_<function_name>_exit_path(): void {}
```

If the untestable function does work *before* the exit, consider whether that pre-exit logic can be tested separately.

**For UNREACHABLE lines:**

Add a comment in the test file:
```php
// UNREACHABLE: line <N> in <function_name> — appears to be dead code
```

Do NOT generate a test for dead code. Report it to the user as a finding.

### Step 9: Run, Fix, Repeat — Lines and Branches

1. **Run the updated test file:**

```bash
php <tinytest_path>/tinytest.php -v -f <test_file>
```

2. **Fix any test failures:**
   - Fix the test if the test logic is wrong
   - If the source behavior is ambiguous, mark with `@ambiguous <reason>`
   - Re-run until all tests pass or are marked `@ambiguous`/`@skip`

3. **Re-run coverage:**

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -c -f <test_file>
```

4. **Re-parse `lcov.info`.** Check for remaining `DA:<line>,0` and `BRDA:<line>,...,0` entries.

5. **If gaps remain**, return to Step 7. Sometimes covering one branch reveals new lines that were previously unreachable.

6. **Stop when:**
   - All `DA:` and `BRDA:` entries have hit count > 0, OR
   - All remaining uncovered lines are UNTESTABLE or UNREACHABLE, OR
   - Three consecutive iterations produce no improvement (report as a plateau and list the stubborn lines)

---

## Step 10: Update test_sources.md

Update the entry with the final statement coverage percentage:

```
source: src/foo.php  test: tests/test_foo.php  [100%]
```

Use `LH / LF * 100`, rounded to the nearest integer. Use `[100%]` only when all reachable lines and branches are covered (or explicitly skipped/noted as unreachable).

## Step 11: Report Results

```
Coverage for <source_file>:

  Function coverage:   before X/Y (Z%)  →  after X/Y (100%)
  Statement coverage:  before X% → after Y%  (LH/LF lines)
  Branch coverage:     before X% → after Y%  (BRH/BRF branches)

  Phase 1 — new test functions added: N
  Phase 2 — new tests added: N  |  existing tests modified: M

  Tests passing: P
  Tests ambiguous: A
  Functions/lines skipped (untestable): U  (list names/line numbers)
  Unreachable lines found: R  (list line numbers — possible dead code)
```
