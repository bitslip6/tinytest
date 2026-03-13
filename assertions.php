<?php declare(strict_types=1);
namespace {

    use function ThreadFin\dbg;

    function assert_base_condition(callable $test_fn, $actual, $expected, string $message, string $output = "") {
        if ($test_fn($actual, $expected) === false) {
        	TinyTest\count_assertion_fail();
			if ($output !== "") { echo $output; }
            throw new TinyTest\TestError($message, $actual, $expected);
        }
        TinyTest\count_assertion_pass();
    }

    function assert_true($condition, string $message, string $output = "") {
        assert_base_condition(function($condition, $expected) { return $condition; }, $condition, true, $message, $output);
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
            return ($haystack != null && stripos($haystack, $needle) !== false); }, $haystack, $needle, $message);
    }

    function assert_contains(?string $haystack, ?string $needle, string $message) {
        assert_base_condition(function(?string $haystack, ?string $needle) {
            $p = strpos($haystack, $needle);
            return ($haystack != null && $p !== false); }, $haystack, $needle, $message);
    }

    function assert_not_contains(?string $haystack, ?string $needle, string $message) {
        assert_base_condition(function(?string $haystack, ?string $needle) {
            return ($haystack == null || $needle == null || strpos($haystack, $needle) === false); }, $haystack, $needle, $message);
    }

	function assert_instanceof($actual, $expected, string $message) {
        assert_base_condition(function($actual, $expected) { 
            return ($actual != null && $actual instanceof $expected); }, $actual, $expected, $message);
	}


    function objects_equal($actual, $expected) : bool {
        $actual_props = get_object_vars($actual);
        $expected_props = get_object_vars($expected);

        foreach ($actual_props as $prop_name => $prop_value) {
            if (!array_key_exists($prop_name, $expected_props)) {
                return false;
            } else if (is_array($prop_value)) {
                if (array_diff($prop_value, $expected_props[$prop_name] ?? []) !== []) {
                    return false;
                }
            } else if (is_object($prop_value)) {
                if (!objects_equal($prop_value, $expected_props[$prop_name])) {
                    return false;
                }
            } else if ($prop_value != $expected_props[$prop_name]) {
                return false;
            }
        }

        return true;
    }

    function assert_object($actual, $expected, string $message) {
        assert_base_condition('objects_equal', $actual, $expected, $message);
    }

    function assert_matches(string $actual, string $pattern, string $message) {
        assert_base_condition(function(string $actual, string $pattern) {
            return preg_match($pattern, $actual) === 1;
        }, $actual, $pattern, $message);
    }

    function assert_not_matches(string $actual, string $pattern, string $message) {
        assert_base_condition(function(string $actual, string $pattern) {
            return preg_match($pattern, $actual) !== 1;
        }, $actual, $pattern, $message);
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
