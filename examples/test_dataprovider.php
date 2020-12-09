<?php declare(strict_types=1);

function addition_test_data() : array {
  return array(
    "one plus one = 2" => array(1, 1, 2),
    "two plus two = 4" => array(2, 2, 4),
    "10 plus 10 = 20" => array(10, 10, 20)
  );
}

/**
 * @dataprovider addition_test_data
 */
function test_addition(array $data) : void {
  assert_eq(($data[0] + $data[1]), $data[2], "addition failed");
}

function multiplication_test_data() : Iterator {
  for ($i = 0; $i < 5000; $i++) {
    yield array(3, $i, 3*$i);
  }
}

/**
 * @dataprovider multiplication_test_data
 */
function test_mulitplication(array $data) : void {
  assert_eq(($data[0] * $data[1]), $data[2], "multiplication failed");
}
