---
name: test-bootstrap
description: Project-wide bootstrap — scans all PHP source files, registers any without a test file in test_sources.md, and spawns sub-agents to generate initial test files for each. Run this first on a new project before using test-audit.
argument-hint: "[source-path] [tests-path]"
---

# Bootstrap Tests — Generate Initial Test Files for the Whole Project

Scan a project for PHP source files that have no corresponding test file, generate initial tests for each, and build out `test_sources.md` as the project-wide tracking ledger.

## Step 1: Build the Source File List

Scan the project for all `.php` files. Exclude:
- `vendor/`
- `tests/` and `test/`
- Any file whose name begins with `test_`
- The tinytest runner itself

If the user provides a `[source-path]` argument, restrict the scan to that path. Default is the project root.

Open `tests/test_sources.md` (create it if it doesn't exist). For each source file found:
- If an entry already exists in `test_sources.md` → skip it, regardless of its current status
- If no entry exists → add it with state `[NEW]`

Entries follow this format — one per line:
```
source: src/file1.php  test: tests/test_file1.php  [100%]
source: src/file2.php  test: tests/test_file2.php  [INITIAL]
source: src/file3.php  test: tests/test_file3.php  [NEW]
```

After updating the file, report how many files were found and how many are new.

## Step 2: Generate Initial Tests

For each file marked `[NEW]` in `test_sources.md`, spawn a sub-agent:

```
/test-generate <source-file>
```

After each sub-agent completes, update the corresponding entry in `test_sources.md` from `[NEW]` to `[INITIAL]`.

Process files one at a time so that `test_sources.md` stays consistent if the run is interrupted.

## Step 3: Report

```
Bootstrap complete.

  Source files found:      23
  Already had tests:       10
  New test files created:  13
  test_sources.md entries: 23

Next steps:
  Increase coverage:
    /test-cover-file <file>   — full coverage for one file: functions, lines, and branches (requires phpdbg)
    /test-audit               — full project audit: find every uncovered function, generate tests, report bugs (requires phpdbg)

  Improve quality:
    /test-improve <test_file> — review a test file for weak assertions, PHPUnit drift, and anti-patterns
    /test-analyze <file> <fn> — deep-analyze a single complex function before covering it

  Check status:
    /test-report              — read-only project coverage dashboard (requires phpdbg)

  Note: if this project has existing PHPUnit tests, run /test-migrate <dir> first
  to convert them before running /test-bootstrap.
```
