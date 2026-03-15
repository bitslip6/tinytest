---
name: test-regression
description: Write a regression test for a known bug. Given a bug description, failing input, and expected correct behavior, produces a named regression test that proves the bug exists (or is fixed) and will catch any future recurrence.
argument-hint: "<function_name> \"<bug description>\""
---

# Write a Regression Test

Given a bug report — a function, an input that triggers it, and the expected correct behavior — produce a regression test that documents the bug, proves it exists (or was fixed), and will catch any future recurrence.

A regression test is different from a coverage test:
- It starts from a **known, concrete failure**
- It documents **exactly what went wrong** and **why the fix is correct**
- It should **fail on the buggy code** and **pass after the fix**
- Its name and comments are written for future readers who hit this failure again

## Step 1: Gather Bug Details

The user may provide any combination of:
- A function name
- A description of the wrong behavior
- The specific input that triggers it
- The expected output vs actual output
- A stack trace or error message
- A ticket/issue number or reference

If critical details are missing, ask:

```
To write a regression test, I need:
  1. Which function has the bug? (required)
  2. What input triggers it? (required)
  3. What should the function return/do? (required)
  4. What does it actually return/do instead? (required)
  5. Is the bug already fixed, or does it still exist in the code? (required)
  6. Ticket/issue reference? (optional)
```

Do not proceed to Step 2 until you have items 1–5.

## Step 2: Read the Function

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

Read the source file containing the function. Understand:
- The function signature and return type
- The code path that the bug-triggering input would follow
- What the correct behavior should be (from documentation, docblock, or the bug report)
- Whether the fix is already in place (the bug is "fixed") or the bug is still present

## Step 3: Locate the Test File

1. Read `tests/test_sources.md`.
2. Find the entry for the source file containing the buggy function.
3. If a test file exists, read it and note existing test function names to avoid duplicates.
4. If no test file exists, create one using the standard template (see `/test-generate` for the full template and conventions).

## Step 4: Write the Regression Test

Create a test function with this structure:

**Naming convention:** `test_<function_name>_regression_<brief_snake_case_description>`

Examples:
- `test_calculate_total_regression_empty_array_returns_zero`
- `test_parse_header_regression_null_input_throws_not_returns_null`
- `test_validate_postal_code_regression_four_digit_code_rejected`

**Required comment block above the test:**

```php
// REGRESSION: <one-line description of the bug>
// Input: <the specific input that triggers it>
// Bug: <what it incorrectly did>
// Expected: <what it should do>
// Fixed: <yes|no> — <date or commit ref if known>
// Ref: <ticket/issue/PR if provided>
```

**The assertion must reflect the CORRECT expected behavior**, not the buggy behavior. If the bug is not yet fixed, this test will fail — that is correct and expected.

**Examples:**

```php
// Bug: calculate_total() with an empty array causes division by zero
// instead of returning 0.0

// REGRESSION: calculate_total() throws DivisionByZero on empty items array
// Input: calculate_total([])
// Bug: threw DivisionByZeroError (division by zero in average calculation)
// Expected: returns 0.0
// Fixed: yes — 2024-03-15
// Ref: issue #142
function test_calculate_total_regression_empty_array_returns_zero(): void {
    assert_eq(calculate_total([]), 0.0, "empty items should return 0.0, not throw");
}
```

```php
// Bug: validate_postal_code() accepts 4-digit codes which are invalid US zips

// REGRESSION: validate_postal_code() incorrectly accepts 4-digit codes
// Input: validate_postal_code("1234")
// Bug: returned true (regex was /^\d{4,5}$/)
// Expected: returns false (US zips are exactly 5 digits)
// Fixed: no
function test_validate_postal_code_regression_four_digit_code_rejected(): void {
    assert_false(validate_postal_code("1234"), "4-digit code must be rejected as invalid US zip");
}
```

```php
// Bug: parse_header() returns null on malformed input instead of throwing

// REGRESSION: parse_header() returns null instead of throwing on malformed input
// Input: parse_header("not-a-valid-header")
// Bug: returned null silently (callers assume non-null return)
// Expected: throws InvalidArgumentException
// Fixed: yes — commit a3f9c2b
/**
 * @exception InvalidArgumentException
 */
function test_parse_header_regression_malformed_throws_not_null(): void {
    parse_header("not-a-valid-header");
}
```

```php
// Bug: build_query_string() does not URL-encode values containing '&'

// REGRESSION: build_query_string() fails to URL-encode '&' in values
// Input: build_query_string(['q' => 'cats & dogs'])
// Bug: returned 'q=cats & dogs' (unencoded ampersand breaks query parsing)
// Expected: returns 'q=cats+%26+dogs'
// Fixed: no
function test_build_query_string_regression_ampersand_encoded(): void {
    $result = build_query_string(['q' => 'cats & dogs']);
    assert_eq($result, 'q=cats+%26+dogs', "ampersand in value must be URL-encoded");
}
```

**For bugs involving multiple related inputs**, use a data provider:

```php
// REGRESSION: slugify() does not handle consecutive special characters
// Input: multiple inputs with consecutive/leading/trailing special chars
// Bug: produced double-hyphens and leading/trailing hyphens
// Expected: collapses runs, strips leading/trailing hyphens
// Fixed: yes — issue #201
function slugify_regression_special_char_cases(): array {
    return [
        'consecutive specials'       => ['hello!!world',  'hello-world'],
        'leading special'            => ['---hello',       'hello'],
        'trailing special'           => ['hello---',       'hello'],
        'mixed consecutive specials' => ['foo!@#bar',      'foo-bar'],
    ];
}

/**
 * @dataprovider slugify_regression_special_char_cases
 */
function test_slugify_regression_consecutive_special_characters(array $data): void {
    assert_eq(slugify($data[0]), $data[1], "slugify should handle consecutive special chars correctly");
}
```

## Step 5: Run the Test — Verify It's Real

Run the test in isolation:

```bash
php <tinytest_path>/tinytest.php -v -f <test_file> -t <regression_test_name>
```

**If the bug is NOT yet fixed:** The test must FAIL. If it passes, either the bug was already fixed without you knowing, or the test is wrong. Investigate before proceeding.

**If the bug IS already fixed:** The test must PASS. If it fails, the fix is incomplete or the regression test has a mistake. Investigate and correct the test.

Report what happened:

```
Regression test: test_calculate_total_regression_empty_array_returns_zero

  Bug status: fixed
  Test result: ✓ PASS — regression guard is in place

  OR

  Bug status: not yet fixed
  Test result: ✗ FAIL (as expected) — test correctly documents the bug
  Run again after applying the fix to verify it passes.
```

## Step 6: Update test_sources.md

Open `tests/test_sources.md` and find the entry for the source file. No status change is needed — just ensure the entry exists. If the file wasn't registered, add it.

## Step 7: Report

```
Regression test written:

  Function: calculate_total() in src/Util.php
  Test:     test_calculate_total_regression_empty_array_returns_zero
  File:     tests/test_util.php (appended)
  Bug ref:  issue #142

  Test status: ✓ PASS (bug was already fixed — regression guard confirmed)

  Next steps:
  - Run the full test suite to confirm no regressions: /test-run
  - If the bug is not yet fixed: apply the fix, then run this test to verify
```
