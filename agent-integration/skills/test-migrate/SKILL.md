---
name: test-migrate
description: Migrate one or more PHPUnit test files to TinyTest. Handles structural conversion (class → global functions), full assertion remapping, exception annotations, data providers, setUp/tearDown, and PHPUnit mock objects. Writes a new TinyTest file, runs it, fixes failures, and registers it in test_sources.md.
argument-hint: "<PHPUnit-test-file-or-directory>"
---

# Migrate PHPUnit Tests to TinyTest

Convert PHPUnit test files to TinyTest's functional style. This skill handles everything that can be automated and clearly documents what requires manual attention.

## Step 1: Identify Files to Migrate

- If the argument is a single `.php` file, migrate that file.
- If the argument is a directory, find all `.php` files in it that contain `extends TestCase` or `extends PHPUnit`:

```bash
grep -rl "extends.*TestCase\|extends.*PHPUnit" <path> --include="*.php"
```

List the files found and confirm with the user before proceeding. Process one file at a time.

## Step 2: Survey Each File Before Touching It

Read each file fully. Build a migration inventory:

**Identify the class structure:**
- Class name and namespace (e.g. `namespace Tests\Unit;`, `class ParserTest extends TestCase`)
- Any `use` statements — keep those for classes under test, remove PHPUnit ones

**Count and categorize what needs converting:**
- Methods prefixed `test` or annotated `@test` — these become test functions
- `setUp()` / `tearDown()` — become factory helpers
- `setUpBeforeClass()` / `tearDownAfterClass()` — flag as needing manual review
- `@dataProvider` annotations — become `@dataprovider` with refactored providers
- `$this->expectException()` calls — become `@exception` annotations
- `$this->expectExceptionMessage()` calls — flag as unsupported
- `$this->createMock()` / `getMockBuilder()` calls — become `Mock<ClassName>` files
- `$this->assert*()` calls — remap to TinyTest assertions
- `@depends` annotations — flag as unsupported
- `$this->markTestSkipped()` — becomes `@skip`
- `$this->markTestIncomplete()` — becomes `@todo`

Present the inventory before making any changes:

```
Migration survey for tests/Unit/ParserTest.php
──────────────────────────────────────────────
Class:      ParserTest (namespace Tests\Unit)
Output:     tests/test_parser.php

  12  test methods  →  global functions (snake_case rename)
   1  setUp()       →  make_parser() factory helper
   0  tearDown()
   3  @dataProvider →  @dataprovider (provider refactor required)
   2  expectException  →  @exception annotation
   1  expectExceptionMessage  →  ⚠ unsupported (type-only check kept)
   4  createMock()  →  MockDatabase, MockLogger (2 new mock files)
  28  $this->assert*() calls  →  TinyTest assertions

  ⚠ Manual review required:
    - 1 expectExceptionMessage (message assertion will be dropped)
    - 1 @depends annotation (test_parse_strict depends on test_parse_basic)

Proceed? (y/n)
```

Wait for confirmation before continuing.

## Step 3: Perform the Structural Conversion

Work through the conversions in this order for each file.

---

### 3a. File Header and Namespace

Remove:
- The `namespace` declaration
- `use PHPUnit\Framework\TestCase;` and any other `use PHPUnit\...` lines

Keep:
- `<?php declare(strict_types=1);`
- `use` statements for classes under test (convert to `require_once` if they are local files rather than Composer-loaded classes)

Add at the top:
```php
<?php declare(strict_types=1);
/**
 * @covers ../path/to/source.php
 */

require_once __DIR__ . '/../path/to/source.php';
```

Resolve the correct `@covers` path by finding which source file the test class was testing (usually named after the class: `ParserTest` → `Parser`).

---

### 3b. Class Wrapper → Global Functions

Remove the class declaration and its closing brace:
```php
// Remove:
class ParserTest extends TestCase
{
    ...
}
```

Every method that was inside the class becomes a global function. Dedent the body accordingly.

---

### 3c. Method Renaming — camelCase → snake_case

Convert every test method name from PHPUnit's camelCase to TinyTest's snake_case:

| PHPUnit method | TinyTest function |
|---|---|
| `testCalculateTotal` | `test_calculate_total` |
| `testHandlesNullInput` | `test_handles_null_input` |
| `testItRejectsNegativeValues` | `test_it_rejects_negative_values` |

**Algorithm:** split on uppercase letter boundaries, lowercase everything, join with `_`, ensure `test_` prefix.

For methods annotated `@test` instead of prefixed: rename to `test_<snake_case_name>` and remove the `@test` annotation.

Non-test helper methods (not prefixed `test`, not `setUp`/`tearDown`) keep their names and become global helper functions.

---

### 3d. setUp() → Factory Helper

PHPUnit's `setUp()` runs before every test. There is no equivalent magic in TinyTest. Convert it to a named factory function and call it at the start of each test that needed it.

```php
// PHPUnit
protected function setUp(): void {
    $this->parser = new Parser(new Config());
    $this->logger = new MockLogger();
}

public function testParsesHeader(): void {
    $result = $this->parser->parseHeader("key: value");
    $this->assertEquals("value", $result['key']);
}
```

```php
// TinyTest
function make_parser(): Parser {
    return new Parser(new Config());
}

function test_parses_header(): void {
    $parser = make_parser();
    $result = $parser->parseHeader("key: value");
    assert_eq($result['key'], "value", "should parse header value");
}
```

**Rules:**
- If `setUp` initializes one primary object, name the helper `make_<classname>()`.
- If `setUp` initializes multiple objects, create one factory helper per object OR one helper that returns an array or struct.
- Replace all `$this-><property>` references in test bodies with the local variable from the factory call.
- If `setUp` has conditional logic or side effects (DB seeding, file creation), create a named setup helper that reflects its purpose: `seed_test_database()`, `create_temp_file()`.

---

### 3e. tearDown()

Most `tearDown()` logic cleans up state. Handle case by case:

- **Resetting mocks** — call `$mock->reset()` at the end of tests that care, or just create a fresh mock in each test via the factory helper (preferred).
- **Deleting temp files / cleaning DB** — create a `cleanup_<thing>()` helper and call it at the end of relevant tests.
- **Closing connections** — if the tests use real infrastructure, flag for manual review: TinyTest doesn't have lifecycle hooks.

---

### 3f. setUpBeforeClass() / tearDownAfterClass()

These run once per class. TinyTest has no equivalent.

- If the logic sets up shared read-only state (constants, loaded config): move it to a bootstrap file (`tests/bootstrap.php`) and run with `-b tests/bootstrap.php` or `-a`.
- If the logic is mutable state that individual tests modify: refactor each test to set up its own state (use factory helpers).
- Flag for the user with a comment at the top of the output file:

```php
// MIGRATION NOTE: setUpBeforeClass() from original not converted.
// Logic: <describe what it did>
// Action needed: <move to bootstrap.php / refactor into per-test setup>
```

---

### 3g. $this->assert*() → TinyTest assertions

Replace every `$this->assert*()` call using this complete mapping.

⚠️ **Critical:** PHPUnit's parameter order is `(expected, actual)`. TinyTest's is `(actual, expected)`. Every conversion must flip the order.

#### Equality and identity

| PHPUnit | TinyTest | Notes |
|---|---|---|
| `$this->assertEquals($exp, $act)` | `assert_eq($act, $exp, "msg")` | order flips |
| `$this->assertSame($exp, $act)` | `assert_eq($act, $exp, "msg")` | order flips |
| `$this->assertNotEquals($exp, $act)` | `assert_neq($act, $exp, "msg")` | order flips |
| `$this->assertNotSame($exp, $act)` | `assert_neq($act, $exp, "msg")` | order flips |
| `$this->assertEqualsIgnoringCase($exp, $act)` | `assert_eqic($act, $exp, "msg")` | order flips |

#### Truthiness and nullness

| PHPUnit | TinyTest | Notes |
|---|---|---|
| `$this->assertTrue($x)` | `assert_true($x, "msg")` | add message |
| `$this->assertFalse($x)` | `assert_false($x, "msg")` | add message |
| `$this->assertNull($x)` | `assert_eq($x, null, "msg")` | |
| `$this->assertNotNull($x)` | `assert_neq($x, null, "msg")` | |

#### Comparison

| PHPUnit | TinyTest | Notes |
|---|---|---|
| `$this->assertGreaterThan($exp, $act)` | `assert_gt($act, $exp, "msg")` | order flips |
| `$this->assertLessThan($exp, $act)` | `assert_lt($act, $exp, "msg")` | order flips |
| `$this->assertGreaterThanOrEqual($exp, $act)` | `assert_true($act >= $exp, "msg")` | no direct |
| `$this->assertLessThanOrEqual($exp, $act)` | `assert_true($act <= $exp, "msg")` | no direct |

#### Strings

| PHPUnit | TinyTest | Notes |
|---|---|---|
| `$this->assertStringContainsString($needle, $hay)` | `assert_contains($hay, $needle, "msg")` | order flips |
| `$this->assertStringNotContainsString($needle, $hay)` | `assert_not_contains($hay, $needle, "msg")` | order flips |
| `$this->assertStringContainsStringIgnoringCase($needle, $hay)` | `assert_icontains($hay, $needle, "msg")` | order flips |
| `$this->assertStringStartsWith($prefix, $str)` | `assert_true(str_starts_with($str, $prefix), "msg")` | no direct |
| `$this->assertStringEndsWith($suffix, $str)` | `assert_true(str_ends_with($str, $suffix), "msg")` | no direct |
| `$this->assertMatchesRegularExpression($pat, $str)` | `assert_matches($str, $pat, "msg")` | order flips |
| `$this->assertDoesNotMatchRegularExpression($pat, $str)` | `assert_not_matches($str, $pat, "msg")` | order flips |

#### Arrays and collections

| PHPUnit | TinyTest | Notes |
|---|---|---|
| `$this->assertCount($n, $arr)` | `assert_count($arr, $n, "msg")` | order flips |
| `$this->assertEmpty($x)` | `assert_empty($x, "msg")` | |
| `$this->assertNotEmpty($x)` | `assert_not_empty($x, "msg")` | |
| `$this->assertContains($needle, $arr)` | `assert_array_contains($needle, $arr, "msg")` | |
| `$this->assertNotContains($needle, $arr)` | `assert_false(in_array($needle, $arr), "msg")` | no direct |
| `$this->assertArrayHasKey($key, $arr)` | `assert_true(array_key_exists($key, $arr), "msg")` | no direct |
| `$this->assertArrayNotHasKey($key, $arr)` | `assert_false(array_key_exists($key, $arr), "msg")` | no direct |
| `$this->assertSameSize($a, $b)` | `assert_eq(count($a), count($b), "msg")` | |

#### Types and objects

| PHPUnit | TinyTest | Notes |
|---|---|---|
| `$this->assertInstanceOf($cls, $obj)` | `assert_instanceof($obj, $cls, "msg")` | order flips |
| `$this->assertIsArray($x)` | `assert_true(is_array($x), "msg")` | no direct |
| `$this->assertIsString($x)` | `assert_true(is_string($x), "msg")` | no direct |
| `$this->assertIsInt($x)` | `assert_true(is_int($x), "msg")` | no direct |
| `$this->assertIsBool($x)` | `assert_true(is_bool($x), "msg")` | no direct |
| `$this->assertIsFloat($x)` | `assert_true(is_float($x), "msg")` | no direct |
| `$this->assertIsCallable($x)` | `assert_true(is_callable($x), "msg")` | no direct |
| `$this->assertIsObject($x)` | `assert_true(is_object($x), "msg")` | no direct |

**Missing messages:** every `$this->assert*()` converted to a TinyTest assertion must include a descriptive message string. Infer a message from context (variable name, method under test, test function name). Do not leave any assertion without a message.

---

### 3h. Exception Testing

**Pattern 1 — `$this->expectException()` before the call:**

```php
// PHPUnit
public function testRejectsNegative(): void {
    $this->expectException(InvalidArgumentException::class);
    calculate_total(-5);
}

// TinyTest
/**
 * @exception InvalidArgumentException
 */
function test_rejects_negative(): void {
    calculate_total(-5);
}
```

**Pattern 2 — `expectException` + `expectExceptionMessage`:**

`expectExceptionMessage` has no TinyTest equivalent. Keep the `@exception` annotation for the type check and add a comment:

```php
/**
 * @exception InvalidArgumentException
 */
function test_rejects_negative(): void {
    // MIGRATION NOTE: original also checked exception message "value must be positive"
    // @exception verifies the type only — message assertion dropped
    calculate_total(-5);
}
```

**Pattern 3 — try/catch in test body:**

```php
// PHPUnit (bad style, but common)
public function testThrows(): void {
    try {
        process(null);
        $this->fail("should have thrown");
    } catch (RuntimeException $e) {
        $this->assertEquals("bad input", $e->getMessage());
    }
}

// TinyTest
/**
 * @exception RuntimeException
 */
function test_throws(): void {
    // MIGRATION NOTE: original also asserted message "bad input" — dropped, type only
    process(null);
}
```

**Remove `$this->fail()`** — TinyTest's `@exception` handles the "no exception thrown" case automatically.

---

### 3i. Data Providers

**Rename** the provider method to a global snake_case function, change `@dataProvider` to `@dataprovider`.

**Rewrite the test signature**: PHPUnit passes named parameters matching the provider's array columns; TinyTest passes a single `array $data` and uses indexed access.

Build a parameter-to-index mapping from the original method signature, then replace all uses in the body:

```php
// PHPUnit
public function additionProvider(): array {
    return [
        'zeros'     => [0, 0, 0],
        'positives' => [1, 2, 3],
    ];
}

/**
 * @dataProvider additionProvider
 */
public function testAdd(int $a, int $b, int $expected): void {
    $this->assertEquals($expected, Calculator::add($a, $b));
}

// TinyTest
// Parameter map: $a → $data[0], $b → $data[1], $expected → $data[2]
function addition_provider(): array {
    return [
        'zeros'     => [0, 0, 0],
        'positives' => [1, 2, 3],
    ];
}

/**
 * @dataprovider addition_provider
 */
function test_add(array $data): void {
    assert_eq(Calculator::add($data[0], $data[1]), $data[2], "addition result should match expected");
}
```

For providers that reference `$this` methods or use class constants, extract those references to the global function scope.

---

### 3j. PHPUnit Mock Objects → Mock<ClassName> Files

PHPUnit mock objects require the most manual judgement. Follow this process:

**1. Identify every mock created in the file:**
```php
$this->createMock(Database::class)
$this->getMockBuilder(HttpClient::class)->getMock()
```

**2. Check for existing TinyTest mocks:**
```bash
ls tests/mocks/ 2>/dev/null
```

Look for `MockDatabase.php`, `MockHttpClient.php`, etc. following the convention `Mock<ClassName>.php`.

**3. For each mock without an existing file, create `tests/mocks/Mock<ClassName>.php`:**

Identify which methods were configured on the mock in the original test:
- `->method('foo')->willReturn(...)` — `foo` needs to be in the mock
- `->method('foo')->willThrowException(...)` — `foo` needs to be in the mock
- `->expects($this->once())->method('foo')` — `foo` needs to be in the mock; call count assertion becomes explicit

Create the mock with the standard TinyTest template (see `/test-generate` — standard fields: `$calls`, `$return_value`, `$return_map`, `$should_throw`, `$throw_class`, `$throw_message`, `reset()`, `was_called()`, `call_count()`). Implement every method that was configured in the PHPUnit mock.

**4. Convert mock configuration and assertions:**

| PHPUnit pattern | TinyTest equivalent |
|---|---|
| `$mock->method('foo')->willReturn($val)` | `$mock->return_map['foo'] = $val;` |
| `$mock->method('foo')->willReturn(true)` | `$mock->return_map['foo'] = true;` |
| `$mock->method('foo')->willThrowException(new Foo())` | `$mock->should_throw = true; $mock->throw_class = 'Foo';` |
| `$mock->expects($this->once())->method('foo')` | after call: `assert_eq($mock->call_count('foo'), 1, "foo called once")` |
| `$mock->expects($this->exactly(3))->method('foo')` | after call: `assert_eq($mock->call_count('foo'), 3, "foo called 3 times")` |
| `$mock->expects($this->never())->method('foo')` | after call: `assert_false($mock->was_called('foo'), "foo must not be called")` |
| `$mock->expects($this->any())->method('foo')` | no assertion needed |
| `->with($this->equalTo($x))` | assert the recorded args: `assert_eq($mock->calls[0][1][0], $x, "arg mismatch")` |

**5. Move call-count assertions to after the code under test runs**, not before. PHPUnit configures expectations before the call; TinyTest asserts after.

**6. For complex mock sequences** (returning different values on successive calls, `->onConsecutiveCalls()`): add a note and a `// TODO: sequential return not auto-converted` comment. Implement manually using a counter property on the mock.

---

### 3k. @skip, @todo, and Other Annotations

| PHPUnit | TinyTest |
|---|---|
| `$this->markTestSkipped('reason')` | `@skip reason` annotation on the function |
| `$this->markTestIncomplete('reason')` | `@todo reason` annotation on the function |
| `@group slow` | `@type slow` (then run with `-e slow` to exclude) |
| `@depends test_foo` | ⚠ unsupported — see below |
| `@ticket 123` | Add as a comment: `// Ref: ticket #123` |

**`@depends` — no TinyTest equivalent.** Tests that depend on another test's state violate isolation. Options:
1. If the setup logic is simple, inline it using a factory helper.
2. If it's a complex state chain, leave a comment and mark as `@todo refactor dependency`:

```php
/**
 * @todo refactor: original test depended on test_parse_basic — inline setup here
 */
function test_parse_strict(): void {
    // MIGRATION NOTE: @depends test_parse_basic not supported — add explicit setup
}
```

---

## Step 4: Write the Output File

Create `tests/test_<source_name>.php`. Do **not** overwrite an existing TinyTest file — if one exists, report the conflict and ask whether to merge or skip.

The output file structure:
```php
<?php declare(strict_types=1);
/**
 * @covers ../path/to/source.php
 */

require_once __DIR__ . '/../path/to/source.php';

// Mocks (one line per mock used)
require_once __DIR__ . '/mocks/MockDatabase.php';

// ---- Setup helpers (converted from setUp()) ----

function make_parser(): Parser {
    ...
}

// ---- Tests ----

function test_parses_header(): void {
    ...
}
```

Leave the original PHPUnit file in place. Do not delete or rename it. The user should verify the migration and delete it manually.

Add a comment at the top of the original PHPUnit file once migration is complete:
```php
// MIGRATED: see tests/test_<name>.php — this file can be deleted after verification
```

---

## Step 5: Run the Tests and Fix Failures

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

```bash
php <tinytest_path>/tinytest.php -v -f tests/test_<name>.php
```

For each failure:
1. Read the error — expected vs actual, file and line number
2. Common migration failures and fixes:
   - **"Class not found"** — missing `require_once` for source file or a mock
   - **"Call to undefined function assert_*()"** — PHPUnit assertion not converted, fix it
   - **"Wrong number of arguments"** — assertion conversion missed a parameter, or data provider index is off
   - **Type mismatch (`===` strict)** — PHPUnit's `assertEquals` did loose comparison; `assert_eq` is strict. Cast or use `assert_eqic` / `assert_true($a == $b)` as appropriate and add a comment explaining the loose comparison.
   - **Test marked IN (incomplete)** — a test body has no assertions; check for unconverted `$this->assert*()` calls
3. Fix each failure and re-run until all tests pass or are marked `@ambiguous`

## Step 6: Register in test_sources.md

Open `tests/test_sources.md` (create it if it doesn't exist). Add an entry:

```
source: <source-file.php>  test: tests/test_<name>.php  [INITIAL]
```

If the source file was not identifiable (the PHPUnit test covered multiple source files or tested infrastructure), register with the test file path and a `[INITIAL]` status and note it for manual review.

## Step 7: Report

```
Migration complete: tests/Unit/ParserTest.php → tests/test_parser.php
──────────────────────────────────────────────────────────────────────

  Test functions migrated:    12
  Assertions converted:       28
  setUp() → factory helpers:   1  (make_parser)
  Data providers converted:    3
  Exception tests converted:   2
  Mock files created:          2  (tests/mocks/MockDatabase.php, tests/mocks/MockLogger.php)
  Mock files reused:           1  (tests/mocks/MockConfig.php)

  Tests passing:  11
  Tests failing:   0
  Tests marked @ambiguous:  1  (test_format_output — loose equality needed, see comment)
  Tests marked @todo:       1  (test_strict_parse — @depends not converted)

  ⚠ Manual review required:
    - test_strict_parse (line 87): @depends removed — inline setup needed
    - test_generate_report (line 134): expectExceptionMessage dropped — type-only check kept
    - setUpBeforeClass() not converted — see MIGRATION NOTE at top of file

  Original file: tests/Unit/ParserTest.php  (marked as migrated, safe to delete)
  Registered in: tests/test_sources.md
```
