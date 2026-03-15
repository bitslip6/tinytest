---
name: test-improve
description: Review an existing TinyTest test file for quality problems — weak assertions, missing messages, wrong parameter order, PHPUnit drift, incomplete tests, and anti-patterns — then fix all approved issues and verify the suite still passes.
argument-hint: "<test_file.php>"
---

# Improve Test Quality

Review an existing test file for assertion quality problems, anti-patterns, and TinyTest misuse. Present a prioritized list of issues, get confirmation, apply fixes, and re-verify.

This skill is about **quality**, not coverage. A test can have 100% coverage and still be useless if assertions are weak or wrong. Run this after `/test-cover-file` to make the tests meaningful.

## Step 1: Run the Tests — Establish Baseline

Find the tinytest path from `AGENTS.md` or `CLAUDE.md` in the project root.

```bash
php <tinytest_path>/tinytest.php -j -f <test_file>
```

Parse the JSON output. Record:
- Total tests, passed, failed, incomplete (`IN`), skipped
- The names of any `IN` tests (no assertions — these are failures)
- The names of any already-failing tests (don't introduce new failures)

## Step 2: Read the Test File

Read the full test file. Scan every test function for the following categories of issue.

---

### Category A — Incomplete Tests (highest severity)

**A1. No assertions at all:**
TinyTest marks tests with zero assertions as `IN` (incomplete) — they count as failures in the suite. Find all test functions that contain no calls to any `assert_*` function.

```php
// Bad — will be marked IN (failure)
function test_calculate_total(): void {
    $result = calculate_total([1, 2, 3]);
}
```

---

### Category B — PHPUnit Drift

**B1. PHPUnit-style assertions** — any `$this->assert*()` call. These silently do nothing in TinyTest (there is no `$this`) and make the test incomplete. Convert each one:

| PHPUnit | TinyTest | Note |
|---|---|---|
| `$this->assertEquals($exp, $act)` | `assert_eq($act, $exp, "msg")` | **parameter order reverses** |
| `$this->assertSame($exp, $act)` | `assert_eq($act, $exp, "msg")` | same |
| `$this->assertTrue($x)` | `assert_true($x, "msg")` | add message |
| `$this->assertFalse($x)` | `assert_false($x, "msg")` | add message |
| `$this->assertNull($x)` | `assert_eq($x, null, "msg")` | |
| `$this->assertNotNull($x)` | `assert_neq($x, null, "msg")` | |
| `$this->assertCount($n, $arr)` | `assert_count($arr, $n, "msg")` | **parameter order reverses** |
| `$this->assertContains($needle, $hay)` | `assert_array_contains($needle, $hay, "msg")` | |
| `$this->assertStringContains($needle, $hay)` | `assert_contains($hay, $needle, "msg")` | |
| `$this->assertInstanceOf($cls, $obj)` | `assert_instanceof($obj, $cls, "msg")` | **parameter order reverses** |
| `$this->assertEmpty($x)` | `assert_empty($x, "msg")` | |
| `$this->assertNotEmpty($x)` | `assert_not_empty($x, "msg")` | |

⚠️ **Critical:** PHPUnit's parameter order is `(expected, actual)`. TinyTest's is `(actual, expected)`. Every conversion must flip the order.

**B2. try/catch for expected exceptions** — use `@exception` annotation instead:

```php
// Bad
function test_throws_on_null(): void {
    try {
        process(null);
        assert_true(false, "should have thrown");
    } catch (InvalidArgumentException $e) {
        assert_true(true, "threw correctly");
    }
}

// Good
/**
 * @exception InvalidArgumentException
 */
function test_throws_on_null(): void {
    process(null);
}
```

---

### Category C — Weak Assertions

**C1. `assert_true` or `assert_false` wrapping an equality check** — loses the actual/expected diff in failure output:

```php
// Bad — failure says "expected true got false", unhelpful
assert_true($result === 42, "should be 42");

// Good — failure says "expected 42 got 17"
assert_eq($result, 42, "should be 42");
```

Patterns to replace:
- `assert_true($x === $y)` → `assert_eq($x, $y)`
- `assert_true($x !== $y)` → `assert_neq($x, $y)`
- `assert_false($x === $y)` → `assert_neq($x, $y)`
- `assert_true($x > $y)` → `assert_gt($x, $y)`
- `assert_true($x < $y)` → `assert_lt($x, $y)`
- `assert_true(count($x) === $n)` → `assert_count($x, $n)`
- `assert_true(empty($x))` → `assert_empty($x)`
- `assert_true(!empty($x))` → `assert_not_empty($x)`
- `assert_true($x instanceof Foo)` → `assert_instanceof($x, Foo::class)`
- `assert_true(strpos($h, $n) !== false)` → `assert_contains($h, $n)`
- `assert_true(strpos($h, $n) === false)` → `assert_not_contains($h, $n)`
- `assert_true(is_null($x))` → `assert_eq($x, null)`
- `assert_false(is_null($x))` → `assert_neq($x, null)`

**C2. Overly broad assertions that lose information:**

```php
// Bad — passes even if $result is wrong
assert_true($result !== null, "got something back");
assert_true(is_array($result), "returns an array");

// Better — assert the actual expected value
assert_eq($result, ['name' => 'Alice', 'age' => 30], "returns correct user");
assert_count($result, 3, "returns 3 items");
```

Flag these for human review — the correct expected value may not be obvious. Mark them `@ambiguous` if you cannot determine the right assertion.

**C3. Result discarded without assertion:**

```php
// Bad — $result is computed but never verified
function test_parse_value(): void {
    $result = parse_value("123");
    // no assertion
}
```

This will be caught by A1 (no assertions), but also flag cases where there ARE other assertions but the return value is still silently discarded.

---

### Category D — Assertion Anti-Patterns

**D1. Assertions after a throwing call in an `@exception` test** — they never execute:

```php
/**
 * @exception InvalidArgumentException
 */
function test_rejects_null(): void {
    $result = process(null);           // throws here — execution stops
    assert_eq($result, null, "...");   // NEVER REACHED
}
```

Remove any assertions that appear after the call that throws.

**D2. `assert_true(false, ...)` as a fallback** — redundant since `@exception` handles the "no exception thrown" case automatically:

```php
// Bad
/**
 * @exception InvalidArgumentException
 */
function test_rejects_null(): void {
    try {
        process(null);
        assert_true(false, "should have thrown");  // redundant
    } catch (InvalidArgumentException $e) {}
}
```

**D3. Missing assertion messages** — every `assert_*` call should have a descriptive message as the last argument. Silent failures are hard to diagnose:

```php
// Bad
assert_eq($result, 42);

// Good
assert_eq($result, 42, "calculate_total should sum the array");
```

---

### Category E — Parameter Order Issues

**E1. Suspected reversed `assert_eq` arguments** — TinyTest convention is `assert_eq($actual, $expected)`. If the first argument is a literal (string, integer, `true`, `false`, `null`, array literal `[...]`) and the second is a function call or variable, they are likely reversed:

```php
// Suspicious — literal first suggests reversed order
assert_eq(42, $result, "...");
assert_eq("hello", get_greeting(), "...");
assert_eq(true, is_valid($input), "...");

// Correct
assert_eq($result, 42, "...");
assert_eq(get_greeting(), "hello", "...");
assert_eq(is_valid($input), true, "...");
```

Flag these for human review — do not auto-fix. Present them as likely reversals and ask for confirmation.

---

## Step 3: Present the Issues Found

Group findings by category and severity. Example:

```
Quality review for tests/test_parser.php
────────────────────────────────────────
Found 11 issues across 5 categories:

[A] Incomplete Tests (2) — these are counted as FAILURES:
  1. test_parse_empty_string — no assertions (line 45)
  2. test_normalize_value — no assertions (line 112)

[B] PHPUnit Drift (3):
  3. test_parse_header_returns_array — uses $this->assertEquals() (line 23)
     → will become: assert_eq($result, [...], "...")  ⚠ parameter order will flip
  4. test_validate_input_throws — uses try/catch instead of @exception (line 67)
  5. test_extract_metadata_count — uses $this->assertCount($arr, 3) (line 89)
     → will become: assert_count($arr, 3, "...")  ⚠ parameter order will flip

[C] Weak Assertions (4):
  6. test_calculate_total (line 34) — assert_true($result === 42) → assert_eq($result, 42)
  7. test_format_date (line 56) — assert_true(!empty($result)) — overly broad, flagged for review
  8. test_slugify (line 78) — assert_true(strpos($result, '-') !== false) → assert_contains($result, '-')
  9. test_build_query (line 94) — $result discarded, assert_true(true) used as placeholder

[D] Anti-Patterns (1):
  10. test_parse_strict_throws (line 103) — assertion after throwing call (line 107) — never executes

[E] Suspected Parameter Order Issues (1) — requires your review:
  11. test_normalize_value_v2 (line 145) — assert_eq("expected_string", $result, "...")
      First argument is a literal — likely reversed. Confirm before fixing.

Fix all? (enter numbers to skip, or press enter to fix all)
```

Wait for the operator to respond. Remove any items they want to skip.

## Step 4: Apply Fixes

Apply each approved fix. For each change:
- Make the minimal edit necessary — do not rewrite the test, only fix the specific issue
- For parameter order reversals in Category E, confirm with the operator before each one
- For Category C2 (overly broad assertions): if you cannot determine the correct expected value, mark the test `@ambiguous overly broad assertion — expected value needs clarification` instead of guessing

## Step 5: Re-Run and Verify

```bash
php <tinytest_path>/tinytest.php -j -f <test_file>
```

Compare to the baseline from Step 1:
- Any previously passing test that now fails is a regression in the fix — revert it and report
- Verify the `IN` count has dropped (incomplete tests resolved)
- All previously-failing tests should not have gotten worse

## Step 6: Report Results

```
Quality improvements applied to tests/test_parser.php:

  Issues fixed: 10
  Issues skipped by operator: 1
  Issues marked @ambiguous: 1

  Incomplete tests (IN) before: 2  →  after: 0
  PHPUnit assertions converted: 3
  Weak assertions upgraded: 3
  Anti-patterns fixed: 1
  Suspected reversals reviewed: 1 (operator confirmed, fixed) / 1 (skipped)

  Tests before: 18 passed, 2 incomplete
  Tests after:  20 passed, 0 incomplete

  Remaining attention needed:
  - test_format_date (line 56): marked @ambiguous — overly broad assertion needs a real expected value
```
