---
name: run-tests
description: Run TinyTest tests and report results
---

# Run TinyTest Tests

When the user asks to run tests, follow these steps:

## Step 1: Determine What to Run

- If user specifies a file: use `-f <file>`
- If user specifies a directory: use `-d <dir>`
- If user specifies a test name: use `-f <file> -t <test_name>`
- Default: look for a `tests/` directory and use `-d tests/`

## Step 2: Run with JSON Output

Find the tinytest path from `CLAUDE.md` in the project root, then run:

```bash
php <tinytest_path>/tinytest.php -j -f <file>
```

If `-j` is not available (older tinytest), fall back to verbose mode:

```bash
php <tinytest_path>/tinytest.php -v -f <file>
```

## Step 3: Parse and Report Results

From JSON output, report:
- Total tests, passed, failed, incomplete
- For each failure: test name, file, line, expected vs actual, error message
- Duration and memory usage

From text output, look for:
- Lines with `err` — these are failures
- `expected [X] got [Y]` — the assertion that failed
- File path and line number after the error

## Step 4: Suggest Fixes

For each failure:
1. Read the failing test file at the reported line
2. Read the source code being tested
3. Explain what went wrong
4. Suggest whether the test or source needs fixing

Report results concisely: "X/Y tests passed. Z failures:" followed by a brief summary of each failure.
