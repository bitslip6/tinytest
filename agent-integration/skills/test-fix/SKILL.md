---
name: test-fix
description: Diagnose and fix a failing TinyTest test. Reads the error output, compares test vs source, and repairs the test or explains the source bug.
argument-hint: "<test_file> [test_name]"
---

# Fix a Failing TinyTest Test

## Step 1: Run the Failing Test with Verbose Output

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

```bash
php <tinytest_path>/tinytest.php -v -f <test_file>
```

Or for a specific test:

```bash
php <tinytest_path>/tinytest.php -v -f <test_file> -t <test_name>
```

## Step 2: Read the Error Output

The verbose output includes:
- `expected [X] got [Y] "message"` — the assertion values
- File path and line number of the failing assertion
- Stack trace showing the call chain

## Step 3: Read Both Files

1. Read the **test file** at the failing line to understand what assertion failed
2. Read the **source file** being tested to understand actual behavior

## Step 4: Diagnose the Issue

Common failure causes:
- **Wrong expected value** — test expectation doesn't match actual behavior
- **Wrong parameter order** — TinyTest uses `assert_eq($actual, $expected, "msg")`, not reversed
- **Type mismatch** — `assert_eq` uses `===` (strict), so `"1"` !== `1`
- **Missing setup** — test doesn't properly initialize dependencies
- **Source bug** — the code under test actually has a bug
- **No assertions** — test marked "IN" (incomplete) because no assertion was called

## Step 5: Fix and Verify

1. Make the fix (to test or source, as appropriate)
2. Re-run the test
3. If it still fails, repeat from Step 2
4. Report what was wrong and what was changed
