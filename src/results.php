<?php declare(strict_types=1);
namespace TinyTest;

class TestResult
{
    public $error = null;
    public $pass = false;
    public $result = "";
    public $console = "";
    public $assertions = 0;
    public $incomplete = false;
    public $dataset = null;
    public function set_error(\Throwable $error)
    {
        $this->pass = false;
        $this->error = $error;
    }
    public function set_result(?string $output)
    {
        $this->result = $output;
    }
    public function set_console(?string $output)
    {
        $this->console = $output;
    }
    public function pass()
    {
        $this->pass = true;
    }
}

