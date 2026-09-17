<?php declare(strict_types=1);
/** @covers ../assertions.php */

class ObjectAssertionValue {
    private $hidden;
    protected $protectedValue = 1;
    public function __construct($hidden) { $this->hidden = $hidden; }
}
class OtherObjectAssertionValue extends ObjectAssertionValue {}

function test_objects_equal_checks_types_keys_and_extra_properties(): void {
    $unequal = [
        [(object)['x' => 1], (object)['x' => '1']],
        [(object)['x' => 1], (object)['x' => 1, 'extra' => true]],
        [(object)['x' => [1]], (object)['x' => [1, 2]]],
        [(object)['x' => ['a' => 1]], (object)['x' => ['b' => 1]]],
        [(object)['x' => [1, 2]], (object)['x' => [2, 1]]],
        [(object)['x' => [1]], (object)['x' => ['1']]],
        [(object)['x' => (object)['a' => 1]], (object)['x' => ['a' => 1]]],
        [new ObjectAssertionValue(1), new ObjectAssertionValue(2)],
        [new ObjectAssertionValue(1), new OtherObjectAssertionValue(1)],
    ];
    foreach ($unequal as [$a, $b]) {
        assert_false(objects_equal($a, $b), 'different structures must not match');
        assert_false(objects_equal($b, $a), 'comparison must be symmetric');
    }
    assert_false(objects_equal(1, 1), 'objects_equal requires objects');
}

function test_objects_equal_compares_nonpublic_and_nested_properties(): void {
    assert_object(new ObjectAssertionValue(1), new ObjectAssertionValue(1), 'private values match');
    assert_object((object)['a' => 1, 'b' => 2], (object)['b' => 2, 'a' => 1], 'property order is irrelevant');
    assert_identical((object)['items' => [(object)['x' => 1]]], (object)['items' => [(object)['x' => 1]]], 'objects inside arrays compare structurally');
}

function test_objects_equal_handles_cycles_and_detects_mismatch(): void {
    $a = (object)['value' => 1];
    $b = (object)['value' => 1];
    $a->self = $a;
    $b->self = $b;
    assert_object($a, $b, 'equivalent object cycles');
    $b->value = 2;
    assert_false(objects_equal($a, $b), 'cyclic objects still compare properties');

    $array = [];
    $array['self'] = &$array;
    assert_false(objects_equal((object)['array' => $array], (object)['array' => $array]), 'recursive arrays fail closed');
}

function test_objects_equal_does_not_guess_internal_state(): void {
    $date = new DateTimeImmutable('2020-01-01');
    assert_object($date, $date, 'same internal object matches');
    assert_false(objects_equal($date, new DateTimeImmutable('2020-01-01')), 'distinct internal objects require identity');
    assert_false(objects_equal(fn() => 1, fn() => 2), 'closures are not equal merely because they expose no properties');
}

function test_assert_array_has_uses_strict_membership(): void {
    assert_array_has([1, null, ['nested' => true]], 1, 'integer present');
    assert_array_has([1, null], null, 'null present');
    assert_array_has([['nested' => true]], ['nested' => true], 'strict nested array membership');
}
