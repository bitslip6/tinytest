<?php declare(strict_types=1);
namespace TinyTest;

class TestError extends \Error
{
    public $test_data;
    //public function __construct(string $message, $actual, $expected, \Exception $ex = null) {
    public function __construct(string $message, $actual, $expected, ?\Throwable $ex = null)
    {
        $str_actual = is_object($actual) ? get_class($actual) . '(...)' : (is_array($actual) ? 'Array(' . count($actual) . ')' : (string)$actual);
        $str_expected = is_object($expected) ? get_class($expected) . '(...)' : (is_array($expected) ? 'Array(' . count($expected) . ')' : (string)$expected);
        $formatted_msg = sprintf("%sexpected [%s%s%s] got [%s%s%s] \"%s%s%s\"\n", NORML, GREEN, $str_expected, NORML, YELLOW, $str_actual, NORML, RED, $message, NORML);

        parent::__construct($formatted_msg, 0, $ex);
        if ($ex != null) {
            $this->line = $ex->getLine();
            $this->file = $ex->getFile();
        } else {
            $bt = nth_element(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3), 2);
            $this->line = $bt['line'];
            $this->file = $bt['file'];
            //$this->file = "BTF:".$bt['file'] . " L:".$bt['line'];
        }
    }
}


// Runner failures must never satisfy an application @exception annotation.
class TimeoutError extends \RuntimeException {}
