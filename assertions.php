<?php declare(strict_types=1);
namespace {

    function assert_base_condition(callable $test_fn, $actual, $expected, string $message, string $output = "") {
        if (!(bool) $test_fn($actual, $expected)) {
        	TinyTest\count_assertion_fail();
			if ($output !== "") { echo $output; }
            throw new TinyTest\TestError($message, $actual, $expected);
        }
        TinyTest\count_assertion_pass();
    }

    function assert_true($condition, string $message, string $output = "") {
        assert_base_condition(function($condition, $expected) { return (bool) $condition; }, $condition, true, $message, $output);
    }

    function assert_false($condition, string $message, string $output = "") {
        assert_base_condition(function($condition, $expected) { return !$condition; }, $condition, false, $message, $output);
    }

    function assert_eq($actual, $expected, string $message, string $output = "") {
        assert_base_condition(function($actual, $expected) { return $actual === $expected; }, $actual, $expected, $message, $output);
    }

    function assert_eqic($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return ($actual === $expected || ($actual != null && strcasecmp($actual, $expected) === 0)); }, $actual, $expected, $message);
    }

    function assert_neq($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return $actual !== $expected; }, $actual, $expected, $message);
    }

    function assert_gt($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return $actual > $expected; }, $actual, $expected, $message);
    }

    function assert_lt($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { return $actual < $expected; }, $actual, $expected, $message);
    }

    function assert_icontains(?string $haystack, ?string $needle, string $message) {
        assert_base_condition(function(?string $haystack, ?string $needle) {
            return ($haystack !== null && $needle !== null && stripos($haystack, $needle) !== false); }, $haystack, $needle, $message);
    }

    function assert_contains(?string $haystack, ?string $needle, string $message) {
        assert_base_condition(function(?string $haystack, ?string $needle) {
            if ($haystack === null || $needle === null) { return false; }
            return strpos($haystack, $needle) !== false; }, $haystack, $needle, $message);
    }

    function assert_not_contains(?string $haystack, ?string $needle, string $message) {
        assert_base_condition(function(?string $haystack, ?string $needle) {
            return ($haystack === null || $needle === null || strpos($haystack, $needle) === false); }, $haystack, $needle, $message);
    }

	function assert_instanceof($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { 
            return ($actual != null && $actual instanceof $expected); }, $actual, $expected, $message);
	}


    // Strict structural comparison of user objects, including non-public properties.
    // Object cycles are supported; recursive arrays/excessive depth fail closed.
    function objects_equal($actual, $expected) : bool {
        if (!is_object($actual) || !is_object($expected)) {
            return false;
        }
        $seen = [];
        $compare = function ($a, $b, int $depth = 0) use (&$compare, &$seen): bool {
            if (gettype($a) !== gettype($b) || $depth > 100) {
                return false;
            }
            if (is_object($a)) {
                if ($a === $b) {
                    return true;
                }
                if (get_class($a) !== get_class($b)) {
                    return false;
                }
                // Internal state is not reliably represented by a property cast.
                $class = new \ReflectionClass($a);
                do {
                    if ($class->isInternal() && $class->getName() !== 'stdClass') {
                        return false;
                    }
                } while ($class = $class->getParentClass());
                $pair = spl_object_id($a) . ':' . spl_object_id($b);
                if (isset($seen[$pair])) {
                    return true;
                }
                $seen[$pair] = true;
                $a = (array) $a;
                $b = (array) $b;
                // Property insertion order is irrelevant; array key order is not.
                ksort($a);
                ksort($b);
            }
            if (is_array($a)) {
                if (array_keys($a) !== array_keys($b)) {
                    return false;
                }
                foreach ($a as $key => $value) {
                    if (!$compare($value, $b[$key], $depth + 1)) {
                        return false;
                    }
                }
                return true;
            }
            return $a === $b;
        };
        return $compare($actual, $expected);
    }

    function assert_object($actual, $expected, string $message) {
        assert_base_condition('objects_equal', $actual, $expected, $message);
    }

    function assert_matches(string $actual, string $pattern, string $message) {
        $matched = @preg_match($pattern, $actual);
        assert_base_condition(fn() => $matched === 1, $actual, $pattern,
            $matched === false ? "$message (invalid regex or regex execution error)" : $message);
    }

    function assert_not_matches(string $actual, string $pattern, string $message) {
        $matched = @preg_match($pattern, $actual);
        assert_base_condition(fn() => $matched === 0, $actual, $pattern,
            $matched === false ? "$message (invalid regex or regex execution error)" : $message);
    }

    // Preferred collection API: haystack first, strict membership.
    function assert_array_has(array $haystack, $needle, string $message) {
        assert_base_condition(fn() => in_array($needle, $haystack, true), $haystack, $needle, $message);
    }

    // Legacy API: preserve needle-first ordering and loose comparison.
    function assert_array_contains($needle, array $haystack, string $message) {
        assert_base_condition(fn() => in_array($needle, $haystack), $haystack, $needle, $message);
    }

    function assert_count($actual, int $expected, string $message) {
        $count = is_countable($actual) ? count($actual) : null;
        assert_base_condition(function($count, $expected) {
            return $count === $expected;
        }, $count, $expected, $message);
    }

    function assert_empty($actual, string $message) {
        assert_base_condition(function($actual, $expected) {
            return empty($actual);
        }, $actual, true, $message);
    }

    function assert_not_empty($actual, string $message) {
        assert_base_condition(function($actual, $expected) {
            return !empty($actual);
        }, $actual, false, $message);
    }

    function assert_identical($actual, $expected, string $message = "test failed") {
        assert_base_condition(function($actual, $expected) {
            $t1 = gettype($actual);
            $t2 = gettype($expected);
            if ($t1 != $t2) {
                return false;
            }
            if ($t1 == "object") {
                return objects_equal($actual, $expected);
            }
            return $actual === $expected;
        }, $actual, $expected, $message);
    }
}
