---
name: test-generate
description: Generate an initial TinyTest test file for a PHP source file. Creates the test file, runs it, fixes failures, and registers it in test_sources.md. Contains the full TinyTest assertion and annotation reference.
argument-hint: "<source-file.php>"
---

# Generate TinyTest Tests

## Step 1: Check if a Test File Already Exists

Look for a matching test file at `tests/test_<filename>.php`. If it already exists, report back:

> A test file already exists at `tests/test_<filename>.php`. Use `/test-cover-file <source>` to achieve full function, line, and branch coverage.

Stop here — do not continue.

## Step 2: Read the Source File

Read the PHP source file the user specifies. Identify:
- All functions and public class methods
- Constructor parameters and dependencies
- Return types and parameter types
- Guard clauses that throw exceptions
- Edge cases (null inputs, empty strings, boundary values)

## Step 3: Identify and Resolve Mocks

Before writing any test code, identify every external type the source file depends on and ensure a mock exists for each one.

### 3a. Collect external dependencies

Scan the source file for any type that is **used but not defined in the file itself**:
- Constructor parameters with class/interface types
- Function parameters with class/interface types
- `new ClassName(...)` calls
- Static calls (`ClassName::method(...)`)
- `instanceof` checks
- `@param` / `@return` docblock types that reference a class

Exclude:
- PHP built-in types (`string`, `int`, `array`, `callable`, `stdClass`, `Exception`, `RuntimeException`, etc.)
- Classes defined elsewhere in the same file
- Types whose real implementation is available via the source file's own `require_once` chain and is cheap to instantiate in a test (e.g. a simple value object with no I/O)

### 3b. Check for existing mocks

```bash
ls tests/mocks/ 2>/dev/null
```

For each dependency collected in 3a, look for a file matching the naming convention:

**Naming convention:** `tests/mocks/Mock<ClassName>.php` containing a class named `Mock<ClassName>`

Examples:
| Dependency type | Mock file | Mock class |
|---|---|---|
| `Database` | `tests/mocks/MockDatabase.php` | `MockDatabase` |
| `HttpClient` | `tests/mocks/MockHttpClient.php` | `MockHttpClient` |
| `UserRepository` | `tests/mocks/MockUserRepository.php` | `MockUserRepository` |
| `LoggerInterface` | `tests/mocks/MockLoggerInterface.php` | `MockLoggerInterface` |

If an interface or abstract class is named with a suffix (`Interface`, `Abstract`, `Contract`), keep that suffix in the mock name so the provenance is clear.

**If a matching mock file exists** — read it to understand what it already supports before writing tests. Use it as-is. Do not modify existing mocks unless they are missing a method the tests actively need.

### 3c. Create missing mocks

For each dependency that has no existing mock, create `tests/mocks/Mock<ClassName>.php`.

**Mock template:**

```php
<?php declare(strict_types=1);

class Mock<ClassName> {
    // --- Recorded calls (assert against these in tests) ---
    public array $calls = [];           // every method call: [method, args]

    // --- Configurable responses ---
    public mixed $return_value = null;  // default return for all methods
    public array $return_map = [];      // per-method overrides: ['method' => value]
    public bool $should_throw = false;
    public string $throw_class = 'RuntimeException';
    public string $throw_message = '';

    // --- Implement each method from the real class/interface ---
    public function <methodName>(<typed params>): <return type> {
        $this->calls[] = [__FUNCTION__, func_get_args()];
        if ($this->should_throw) {
            throw new $this->throw_class($this->throw_message);
        }
        return $this->return_map[__FUNCTION__] ?? $this->return_value;
    }

    // --- Test helpers ---
    public function reset(): void {
        $this->calls = [];
        $this->return_value = null;
        $this->return_map = [];
        $this->should_throw = false;
        $this->throw_class = 'RuntimeException';
        $this->throw_message = '';
    }

    public function was_called(string $method): bool {
        foreach ($this->calls as [$m, $_]) {
            if ($m === $method) return true;
        }
        return false;
    }

    public function call_count(string $method): int {
        return count(array_filter($this->calls, fn($c) => $c[0] === $method));
    }
}
```

**Filling in methods:** Implement every public method the source file actually calls. If the real class is available in the codebase, read it to get the correct signatures. If only an interface is available, implement every method on the interface. If neither is available, implement only the methods referenced in the source file under test — use `mixed` return types when uncertain.

**Return type handling:**
- Methods that return `void`: omit the `return` line and the `return_value` lookup
- Methods with `bool` return: default `return_value` to `false`; tests set it to `true` when needed
- Methods with typed returns (e.g. `array`): default to an appropriate empty value (`[]`, `''`, `0`)

**Concrete example — `MockDatabase`:**

```php
<?php declare(strict_types=1);

class MockDatabase {
    public array $calls = [];
    public mixed $return_value = null;
    public array $return_map = [];
    public bool $should_throw = false;
    public string $throw_class = 'RuntimeException';
    public string $throw_message = '';

    public function query(string $sql, array $params = []): mixed {
        $this->calls[] = [__FUNCTION__, func_get_args()];
        if ($this->should_throw) {
            throw new $this->throw_class($this->throw_message);
        }
        return $this->return_map[__FUNCTION__] ?? $this->return_value;
    }

    public function execute(string $sql, array $params = []): bool {
        $this->calls[] = [__FUNCTION__, func_get_args()];
        if ($this->should_throw) {
            throw new $this->throw_class($this->throw_message);
        }
        return (bool) ($this->return_map[__FUNCTION__] ?? $this->return_value ?? true);
    }

    public function reset(): void {
        $this->calls = [];
        $this->return_value = null;
        $this->return_map = [];
        $this->should_throw = false;
        $this->throw_class = 'RuntimeException';
        $this->throw_message = '';
    }

    public function was_called(string $method): bool {
        foreach ($this->calls as [$m, $_]) {
            if ($m === $method) return true;
        }
        return false;
    }

    public function call_count(string $method): int {
        return count(array_filter($this->calls, fn($c) => $c[0] === $method));
    }
}
```

**Using mocks in tests:**

```php
// Inject via constructor
function test_order_processor_saves_to_db(): void {
    $db = new MockDatabase();
    $db->return_map['execute'] = true;

    $processor = new OrderProcessor($db);
    $processor->save(['item' => 'widget', 'qty' => 2]);

    assert_true($db->was_called('execute'), "save() should call db->execute()");
    assert_eq($db->call_count('execute'), 1, "execute called exactly once");
}

// Test error handling
function test_order_processor_handles_db_failure(): void {
    $db = new MockDatabase();
    $db->should_throw = true;
    $db->throw_message = 'connection lost';

    $processor = new OrderProcessor($db);
    $result = $processor->save(['item' => 'widget']);

    assert_false($result, "save() should return false on db error");
}
```

### 3d. Plan require_once lines

For each mock that will be used, note the `require_once` line needed at the top of the test file:

```php
require_once __DIR__ . '/mocks/Mock<ClassName>.php';
```

## Step 4: Generate the Test File

Create `tests/test_<source_name>.php` following these rules strictly:

**File structure:**
```php
<?php declare(strict_types=1);
/**
 * @covers ../path/to/source.php
 */

require_once __DIR__ . '/../path/to/source.php';

// Mocks for external dependencies (see Step 3 — one line per mock used)
require_once __DIR__ . '/mocks/MockDatabase.php';

// Setup helpers if needed (plain functions, no magic)
function make_<thing>(): SourceClass {
    $db = new MockDatabase();
    return new SourceClass($db);
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

**CRITICAL: Do NOT use PHPUnit assertions like `$this->assertEquals()`, `$this->assertTrue()`, `assertSame()` etc. TinyTest assertions are plain global functions.**

**Parameter order:**
- Equality: `assert_eq($actual, $expected, "message")`
- Strings: `assert_contains($haystack, $needle, "message")`
- Message is ALWAYS the last parameter

**Annotations** (add in PHPDoc block above test function):
- `@type <name>` — categorize tests for filtering with `-i`/`-e`
- `@dataprovider <function_name>` — run test once per entry from provider function
- `@phperror <error_type>` — expect a PHP error
- `@skip <reason>` — skip this test without running it
- `@todo <reason>` — mark as TODO without running it
- `@ambiguous <reason>` — the correct behavior is not obvious — possible bug in the code
- `@timeout <seconds>` — fail if test takes longer (supports decimals like `0.5`)

**File-level annotation** (add in docblock at top of test file, before any function):
- `@covers <path>` — restrict coverage reports to this source file (relative to test file). Multiple `@covers` lines supported.

**Mocks:**
- All mocks were resolved in Step 3. Each needed mock lives at `tests/mocks/Mock<ClassName>.php` and is already required at the top of the test file.
- Inject mocks through constructor parameters or function arguments — never bypass the real interface.
- Use `$mock->return_map['method']` to set per-method return values; use `$mock->should_throw = true` to test error-handling paths.
- Use `$mock->was_called('method')` and `$mock->call_count('method')` to assert interaction behavior.
- Call `$mock->reset()` in helpers that build fresh objects if the same mock instance is reused across tests.

**`@exception` annotation — testing that code throws:**

Use `@exception` when a function should throw on certain input. The annotation goes in the PHPDoc block. The test body calls the code that should throw — **do NOT wrap it in try/catch**. TinyTest handles the catch internally. If the listed exception is thrown, the test passes. If no exception is thrown or a different one is thrown, the test fails.

```php
/**
 * @exception InvalidArgumentException
 */
function test_rejects_negative_price(): void {
    // Just call the function — do NOT try/catch.
    calculate_total(-5, 1);
}
```

Multiple exception types — the test passes if **any** of the listed types is thrown:

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
- Do NOT use try/catch in the test body — `@exception` replaces it entirely
- Do NOT add assertions in the test body alongside `@exception` — assertions after the throwing call never execute
- Do NOT use `assert_true(false, ...)` as a fallback — the framework handles the "no exception thrown" case automatically

**Data providers for multiple test cases:**
```php
function <name>_data(): array {
    return [
        'description'       => [input1, input2, expected],
        'edge: empty input' => ['', null, false],
    ];
}

/**
 * @dataprovider <name>_data
 */
function test_<name>(array $data): void {
    assert_eq(some_function($data[0], $data[1]), $data[2], "failed for case");
}
```

## Step 5: Run the Tests

After generating the test file, run it:

```bash
php <tinytest_path>/tinytest.php -v -f tests/test_<name>.php
```

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

## Step 6: Fix Any Failures

If tests fail:
1. Read the error output — it shows expected vs actual values, file and line number
2. Determine if the test is wrong or the source code has a bug
3. If a mock is missing a method the source calls, add it to the mock file
4. If the behavior is ambiguous, mark with `@ambiguous <reason>` and move on
5. Repeat until all tests pass or are marked `@ambiguous`

## Step 7: Register in test_sources.md

Open `tests/test_sources.md` (create it if it doesn't exist). Add an entry for the new file:

```
source: <source-file.php>  test: tests/test_<name>.php  [INITIAL]
```

Report final results: test file created, number of test functions generated, pass/fail/ambiguous counts, and any new mock files created.
