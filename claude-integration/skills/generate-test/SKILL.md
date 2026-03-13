---
name: generate-test
description: Generate TinyTest tests for a PHP source file
---

# Generate TinyTest Tests

When the user asks to generate tests for a PHP file, follow these steps:

## Step 1: Read the Source File

Read the PHP source file the user specifies. Identify:
- All public functions and public class methods
- Constructor parameters and dependencies
- Return types and parameter types
- Edge cases (null inputs, empty strings, boundary values, exceptions thrown)

## Step 2: Generate the Test File

Create `tests/test_<source_name>.php` following these rules strictly:

**File structure:**
```php
<?php declare(strict_types=1);

require_once __DIR__ . '/../path/to/source.php';

// Setup helpers if needed (plain functions, no magic)
function make_<thing>(): SourceClass {
    return new SourceClass('defaults');
}

function test_<function_name>_happy_path(): void {
    // Test the normal/expected behavior
}

function test_<function_name>_edge_case(): void {
    // Test boundary conditions, empty inputs, etc.
}

/**
 * @exception ExpectedException
 */
function test_<function_name>_throws_on_invalid(): void {
    // Call with invalid input — test passes if exception is thrown
}
```

**Naming rules:**
- File: `test_<source_filename_without_extension>.php`
- Functions: `test_<descriptive_name>` using snake_case
- All functions in global namespace (NO namespace declaration)

**Assertion rules — use ONLY these TinyTest assertions:**
- `assert_true($condition, "message")` — truthy check
- `assert_false($condition, "message")` — falsy check
- `assert_eq($actual, $expected, "message")` — strict equality (===)
- `assert_neq($actual, $expected, "message")` — strict inequality (!==)
- `assert_gt($actual, $expected, "message")` — greater than
- `assert_lt($actual, $expected, "message")` — less than
- `assert_contains($haystack, $needle, "message")` — string contains
- `assert_not_contains($haystack, $needle, "message")` — string does not contain
- `assert_instanceof($actual, ExpectedClass::class, "message")` — type check
- `assert_eqic($actual, $expected, "message")` — case-insensitive string equality
- `assert_icontains($haystack, $needle, "message")` — case-insensitive string contains
- `assert_identical($actual, $expected, "message")` — type-aware deep equality
- `assert_object($actual, $expected, "message")` — deep object property comparison
- `assert_array_contains($needle, $haystack, "message")` — value exists in array

**CRITICAL: Do NOT use PHPUnit assertions like `$this->assertEquals()`, `$this->assertTrue()`, `assertSame()` with `$this->`, etc. TinyTest assertions are plain global functions.**

**Parameter order:**
- Equality: `assert_eq($actual, $expected, "message")`
- Strings: `assert_contains($haystack, $needle, "message")`
- Message is ALWAYS the last required parameter

**Annotations** (add in PHPDoc block above test function):
- `@type <name>` — categorize tests for filtering with `-i`/`-e`
- `@dataprovider <function_name>` — run test once per entry from provider function
- `@phperror <error_type>` — expect a PHP error
- `@skip <reason>` — skip this test without running it
- `@todo <reason>` — mark as TODO without running it
- `@timeout <seconds>` — fail if test takes longer (supports decimals like `0.5`)

**`@exception` annotation — testing that code throws:**

Use `@exception` when a function should throw on certain input. The annotation goes in the PHPDoc block. The test body calls the code that should throw — **do NOT wrap it in try/catch**. TinyTest handles the catch internally. If the listed exception is thrown, the test passes. If no exception is thrown or a different one is thrown, the test fails.

```php
/**
 * @exception InvalidArgumentException
 */
function test_rejects_negative_price(): void {
    // Just call the function — do NOT try/catch.
    // TinyTest catches it and passes the test if InvalidArgumentException is thrown.
    calculate_total(-5, 1);
}
```

Multiple exception types — if the function could throw one of several types, list each on its own line. The test passes if **any** of the listed types is thrown:

```php
/**
 * @exception InvalidArgumentException
 * @exception RangeException
 */
function test_rejects_bad_input(): void {
    process_data(null);
}
```

Use the **fully qualified class name** if the exception is namespaced:

```php
/**
 * @exception App\Exceptions\ValidationException
 */
function test_validation_fails(): void {
    validate_record([]);
}
```

**Common mistakes to avoid:**
- Do NOT use try/catch in the test body — `@exception` replaces that entirely
- Do NOT add assertions in the test body alongside `@exception` — the test ends when the exception is thrown, so assertions after the throwing call never execute
- Do NOT use `assert_true(false, ...)` as a fallback — the framework handles the "no exception thrown" case automatically

**Data providers for multiple test cases:**
```php
function <name>_data(): array {
    return [
        'description' => [input1, input2, expected],
    ];
}

/**
 * @dataprovider <name>_data
 */
function test_<name>(array $data): void {
    assert_eq(some_function($data[0], $data[1]), $data[2], "failed for case");
}
```

## Step 3: Run the Tests

After generating the test file, run it:

```bash
php <tinytest_path>/tinytest.php -v -f tests/test_<name>.php
```

Look at the `CLAUDE.md` in the project root for the exact tinytest path.

## Step 4: Fix Any Failures

If tests fail:
1. Read the error output — it shows expected vs actual values, file and line number
2. Determine if the test is wrong or the source code has a bug
3. Fix the test (most likely) and re-run
4. Repeat until all tests pass

Report the final results to the user.
