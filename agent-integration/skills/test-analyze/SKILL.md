---
name: test-analyze
description: Deep-analyze a single PHP function — trace all code paths, find callers, classify bugs and smells, and generate exhaustive TinyTest unit tests. Useful before /test-cover-file when a function is complex.
argument-hint: "<file.php> <function_name>"
---

# Analyze Function

Deep-analyze a single PHP function, trace every code path, find potential bugs, and generate exhaustive unit tests.

## Step 1: Parse Arguments

The user provides a file path and function name. Examples:
- `src/Parser.php parse_header`
- `src/Parser.php:42` (line number instead of name)

## Step 2: Read the Function

Read the source file. Find the function by name or line number. Read the entire function body, its docblock, and the surrounding class/file context (imports, class properties, related constants).

## Step 3: Find All Callers

Search the project for every call to this function:

```bash
rg -n "<function_name>\s*\(" --type php -g '!vendor/' -g '!tests/' .
```

For each caller (up to 20), read 5 lines of surrounding context to understand:
- What values are actually passed
- Whether the caller checks the return value
- Whether the caller catches exceptions

## Step 4: Trace Every Code Path

Create a path map. For each path, document:
- **Entry conditions** — what parameter values lead to this path
- **Operations** — what the code does on this path
- **Exit** — return value, exception thrown, or side effect
- **Test strategy** — how to exercise this specific path

Present the path map:

```
Path Map for parse_header($raw, $strict = false):

  Path 1: Empty input guard
    Entry: $raw === "" or $raw === null
    Exit: throws InvalidArgumentException
    Test: @exception annotation

  Path 2: Normal parse, non-strict
    Entry: valid $raw, $strict = false
    Exit: returns parsed array
    Test: happy path with sample header

  Path 3: Normal parse, strict mode
    Entry: valid $raw, $strict = true
    Exit: returns parsed array (truncated to 255 chars)
    Test: verify truncation behavior

  Path 4: Malformed input, non-strict
    Entry: invalid format $raw, $strict = false
    Exit: returns null (⚠️ callers assume non-null!)
    Test: assert_eq(parse_header("garbage"), null, "...")

  Path 5: Malformed input, strict
    Entry: invalid format $raw, $strict = true
    Exit: throws ParseException
    Test: @exception annotation
```

## Step 5: Identify Issues

For each path, check:
- Does the behavior match what callers expect?
- Are there type inconsistencies?
- Are edge cases handled?
- Are error conditions properly surfaced?

Classify findings:
- **🐛 BUG** — demonstrably wrong (evidence: specific input → wrong output)
- **⚠️ SMELL** — fragile or unclear, could break
- **❓ AMBIGUOUS** — unclear if intentional, needs operator input
- **✅ CLEAN** — correct and well-handled

## Step 6: Generate Tests

Create or append to `tests/test_<source_name>.php`.

Follow all TinyTest conventions (see `/test-generate` for the full assertion and annotation reference):
- One test per path minimum, more for complex paths
- `@dataprovider` when multiple inputs test the same path pattern
- `@exception` for throw paths — no try/catch
- For bugs: write test documenting current (buggy) behavior with `// BUG:` comment
- Functions in global namespace, `test_` prefix

**Test template:**
```php
<?php declare(strict_types=1);

require_once __DIR__ . '/../path/to/source.php';

// ---- Tests for <function_name> ----

function <function_name>_path_data(): array {
    return [
        'normal input'   => [<input>, <expected>],
        'edge: empty'    => ['', <expected>],
        'edge: boundary' => [<boundary_input>, <expected>],
    ];
}

/**
 * @dataprovider <function_name>_path_data
 */
function test_<function_name>_paths(array $data): void {
    assert_eq(<function_name>($data[0]), $data[1], "path test failed");
}

/**
 * @exception InvalidArgumentException
 */
function test_<function_name>_rejects_null(): void {
    <function_name>(null);
}

// BUG: returns null on malformed input but callers assume non-null
function test_<function_name>_malformed_returns_null(): void {
    $result = <function_name>("garbage");
    assert_eq($result, null, "malformed input returns null (known bug)");
}
```

## Step 7: Run and Verify

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

```bash
php <tinytest_path>/tinytest.php -v -f tests/test_<name>.php
```

Fix test errors (not source bugs). Re-run until passing.

## Step 8: Report

Present findings:
- Path count and test count generated
- Each issue classified (BUG/SMELL/AMBIGUOUS/CLEAN)
- For AMBIGUOUS: ask the operator what to do
- For BUG: ask if they want it fixed now or just documented
