---
name: fix-test
description: Fix a failing TinyTest test
---

# Fix Failing TinyTest Test

When the user asks to fix a failing test, follow these steps:

## Step 1: Run the Failing Test with Verbose Output

```bash
php <tinytest_path>/tinytest.php -v -f <test_file>
```

Or for a specific test:

```bash
php <tinytest_path>/tinytest.php -v -f <test_file> -t <test_name>
```

Find the tinytest path from `CLAUDE.md` in the project root.

## Step 2: Read the Error Output

The verbose output includes:
- `expected [X] got [Y] "message"` — the assertion values
- File path and line number of the failing assertion
- Stack trace showing the call chain

## Step 3: Read Both Files

1. Read the **test file** at the failing line to understand what assertion failed
2. Read the **source file** being tested to understand the actual behavior

## Step 4: Diagnose the Issue

Common failure causes:
- **Wrong expected value** — test expectation doesn't match actual behavior
- **Wrong parameter order** — TinyTest uses `assert_eq($actual, $expected, "msg")`, not `($expected, $actual)`
- **Type mismatch** — `assert_eq` uses `===` (strict), so `"1"` !== `1`
- **Missing setup** — test doesn't properly initialize dependencies
- **Source bug** — the code under test actually has a bug
- **No assertions** — test marked "IN" (incomplete) because no assertion was called

## Step 5: Fix and Verify

1. Make the fix (to test or source, as appropriate)
2. Re-run the test
3. If it still fails, repeat from Step 2
4. Report what was wrong and what you changed
