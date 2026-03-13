---
name: cover-functions
description: Run tests with code coverage, identify untested functions, and generate tests for them. Requires phpdbg.
argument-hint: "[tests-path]"
---

# Cover Untested Functions

Analyze code coverage to find source functions that have no test coverage, then generate tests for them.

## Step 1: Check Prerequisites

Verify phpdbg is available — coverage requires it:

```bash
which phpdbg
```

If not found, tell the user:
> Code coverage requires phpdbg. Install it with: `sudo apt install php-phpdbg` (Debian/Ubuntu) or the equivalent for your OS.

Find the tinytest path from `CLAUDE.md` in the project root.

## Step 2: Run Coverage Analysis

Run all tests with coverage and JSON output:

```bash
phpdbg -qrr -d xdebug.mode=off <tinytest_path>/tinytest.php -j -c -d tests/
```

If the user specified a path argument, use that instead of `tests/`.

Parse the JSON output. The `coverage` key contains uncovered functions per source file:

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

## Step 3: Present Uncovered Functions

List every uncovered function grouped by file:

```
Uncovered functions found:

src/Parser.php (3/8 functions uncovered):
  1. parse_header (line 42)
  2. normalize_value (line 95)
  3. extract_metadata (line 160)

src/Validator.php (1/5 functions uncovered):
  4. validate_postal_code (line 78)

Generate tests for all 4 functions? (enter numbers to exclude, or press enter to proceed)
```

Wait for user confirmation. Remove any they want to skip.

## Step 4: Read Source and Generate Tests

For each uncovered function:

1. Read the source file at the function's line number to understand its signature, logic, and guard conditions
2. Generate test functions following these rules:

**File placement:**
- If `tests/test_<source_name>.php` already exists, **append** new test functions to it — do NOT overwrite existing tests
- If it doesn't exist, create it with the standard template

**Test generation rules:**
- Use ONLY TinyTest assertions (see the generate-test skill for the full reference)
- Do NOT use PHPUnit-style assertions
- All test functions in global namespace, prefixed with `test_`
- For guards that throw: use `@exception` annotation (do NOT use try/catch)
- For functions with multiple input/output combos: use `@dataprovider`
- Generate at minimum: one happy-path test and one test per guard/edge case

**Example for an uncovered function:**

```php
// Source: function calculate_discount(float $price, string $code): float
// Guards: throws InvalidArgumentException on negative price, returns 0.0 for unknown codes

function discount_cases(): array {
    return [
        'valid 10% code'   => [100.0, 'SAVE10', 90.0],
        'valid 20% code'   => [50.0, 'SAVE20', 40.0],
        'unknown code'     => [100.0, 'BOGUS', 100.0],
        'zero price'       => [0.0, 'SAVE10', 0.0],
    ];
}

/**
 * @dataprovider discount_cases
 */
function test_calculate_discount(array $data): void {
    assert_eq(calculate_discount($data[0], $data[1]), $data[2], "discount calculation failed");
}

/**
 * @exception InvalidArgumentException
 */
function test_calculate_discount_rejects_negative(): void {
    calculate_discount(-5.0, 'SAVE10');
}
```

## Step 5: Run and Verify

Run only the new/modified test files:

```bash
php <tinytest_path>/tinytest.php -v -f tests/test_<name>.php
```

If tests fail:
1. Read the error — expected vs actual, file and line
2. Fix the test (not the source) unless the source has an obvious bug
3. Re-run until all pass

## Step 6: Report Results

Summarize:
- How many previously uncovered functions now have tests
- Total new test functions generated
- Pass/fail status
- Any functions that were too complex to test automatically (suggest manual review)
