---
name: refactor-testable
description: Analyze a PHP file for business logic, effects, and complex code that can be extracted into pure, unit-testable functions. Performs the refactoring and generates TinyTest tests.
argument-hint: "<path/to/file.php>"
---

# Refactor to Testable Functions

Analyze a PHP source file, identify code that should be extracted into pure, unit-testable functions, perform the refactoring, and generate TinyTest tests.

## Step 1: Analyze the Source File

Read the file the user specifies. Scan every function, method, and top-level block for extraction candidates. Look for these patterns:

**Effect boundaries** (highest priority):
- Database queries mixed with business logic — separate the query from the decision
- File I/O interleaved with data processing — extract the processing
- HTTP/API calls next to response parsing — extract the parsing
- Output/echo/print mixed with computation — extract the computation
- Global state reads (`$_GET`, `$_POST`, `$_SESSION`, `$_SERVER`) mixed with logic — extract logic that takes values as parameters instead

**Business logic:**
- Conditional blocks that make domain decisions (pricing rules, access control, status transitions)
- Multi-step validation sequences (validate, then normalize, then check constraints)
- Data transformation pipelines (take raw input, produce structured output)

**Complexity:**
- Long if/else or switch chains that encode rules
- Nested conditionals (3+ levels deep)
- Loops that accumulate/transform/filter with inline logic
- Repeated guard clause patterns across functions

**Calculations:**
- Math or date arithmetic embedded in larger functions
- String formatting/building logic
- Array reshaping, grouping, or aggregation

For each candidate, note:
- What it does (one line)
- Where it is (function name, approximate line range)
- What its inputs would be (parameters for the extracted function)
- What its output would be (return value)
- What effects it's currently entangled with (DB, IO, HTTP, global state)
- Guard/error conditions (invalid input, edge cases, boundary values)

## Step 2: Present the Refactoring Plan

**Before making any changes**, present a numbered summary to the user. Each entry must include:
- **Extract from:** the containing function/method name and line range
- **New function:** full signature with typed parameters and return type
- **Purpose:** why this extraction improves testability (what effect is being separated, what becomes pure)
- **Guards:** the error/edge conditions that will become test cases

```
Proposed extractions from src/OrderProcessor.php:

1. Extract from: process_order() (lines 45-78)
   New function: calculate_order_total(array $items, ?string $discount_code): float
   Purpose: Isolate pricing logic from DB side effects — discount lookup stays at call site, pure calculation is extracted
   Guards: empty items array (throws InvalidArgumentException), invalid discount code, negative prices

2. Extract from: process_checkout() (lines 102-130)
   New function: validate_shipping_address(array $address): bool
   Purpose: Separate input validation from the checkout workflow so it can be tested without triggering payment/shipping effects
   Guards: missing required fields, invalid zip format, unsupported country

3. Extract from: generate_invoice() (lines 200-240)
   New function: format_invoice_lines(array $items, float $tax_rate): array
   Purpose: Decouple line-item formatting from PDF generation — pure data transformation becomes independently testable
   Guards: zero tax rate, empty items, items missing price field

Skip any? (enter numbers to exclude, or press enter to proceed)
```

Wait for the user to confirm. Remove any candidates they want to skip.

## Step 3: Perform the Extractions

For each approved candidate, extract a **pure function** — no side effects, no global state, no IO. Follow these rules:

**Function design:**
- All inputs come through parameters (never read globals, superglobals, or external state)
- Return a single type (nullable is fine, e.g. `?float`). Never echo, write files, or modify external state
- If the return type alone is not granular enough for the caller to distinguish between different failure conditions, throw a typed exception for each failed guard (e.g. `InvalidArgumentException`, `RangeException`). This is the only acceptable side effect in an extracted function.
- Use type declarations on parameters and return types
- Name descriptively: `calculate_*`, `validate_*`, `parse_*`, `format_*`, `build_*`, `filter_*`
- Place extracted functions in a logical location — if the source file is a class, add private/protected methods; if procedural, add functions near the top or in a separate include

**Rewire the call site:**
- Replace the inline code with a call to the new function
- Pass the values that were previously accessed inline as arguments
- Keep the effects (DB calls, IO, output) at the call site — the extracted function should be pure

**Preserve behavior exactly.** Do not change what the code does, only where the logic lives.

## Step 4: Generate Tests

Create `tests/test_<source_name>_extracted.php` (or a more specific name if appropriate).

**File structure:**
```php
<?php declare(strict_types=1);

require_once __DIR__ . '/../path/to/source.php';
```

**For each extracted function, generate:**

1. **Happy-path test** — normal input, expected output
2. **Guard/edge-case tests** — one test per identified guard condition. Use `@exception` for guards that throw:
   ```php
   /**
    * @exception InvalidArgumentException
    */
   function test_calculate_total_throws_on_empty_items(): void {
       calculate_order_total([], null);
   }
   ```
3. **Data provider for input/output combinations** when there are multiple valid scenarios:
   ```php
   function order_total_cases(): array {
       return [
           'single item no discount' => [['items' => [['price' => 10.00, 'qty' => 2]]], null, 20.00],
           'with percentage discount'  => [['items' => [['price' => 100.00, 'qty' => 1]]], 'SAVE10', 90.00],
           'multiple items'            => [['items' => [['price' => 5.00, 'qty' => 3], ['price' => 12.00, 'qty' => 1]]], null, 27.00],
       ];
   }

   /**
    * @dataprovider order_total_cases
    */
   function test_calculate_order_total(array $data): void {
       assert_eq(calculate_order_total($data[0], $data[1]), $data[2], "order total mismatch");
   }
   ```
4. **Boundary tests** — empty collections, zero values, null optionals, maximum values where relevant

**Assertion rules — use ONLY TinyTest assertions:**
- `assert_eq($actual, $expected, "message")` — strict equality
- `assert_true($condition, "message")` / `assert_false($condition, "message")`
- `assert_neq($actual, $expected, "message")` — strict inequality
- `assert_gt($actual, $expected, "message")` / `assert_lt($actual, $expected, "message")`
- `assert_contains($haystack, $needle, "message")` — string contains
- `assert_not_contains($haystack, $needle, "message")`
- `assert_matches($string, $pattern, "message")` — regex match
- `assert_count($countable, $expected, "message")` — collection size
- `assert_empty($value, "message")` / `assert_not_empty($value, "message")`
- `assert_instanceof($actual, ExpectedClass::class, "message")`
- `assert_identical($actual, $expected, "message")` — type-aware deep equality

Do NOT use PHPUnit assertions. All test functions must be in the global namespace.

## Step 5: Run Tests and Fix

Run the generated tests:
```bash
php <tinytest_path>/tinytest.php -v -f tests/test_<name>_extracted.php
```

Find the tinytest path from `CLAUDE.md` in the project root.

If tests fail:
1. Determine if the test expectation is wrong or the extraction changed behavior
2. Fix the issue — extraction bugs take priority (the refactored code must behave identically)
3. Re-run until all pass

## Step 6: Report Results

Summarize what was done:
- Number of functions extracted
- Number of tests generated (pass-case + guard-case breakdown)
- Any behavior changes detected during testing
- Suggestions for further extractions that were too risky to automate
