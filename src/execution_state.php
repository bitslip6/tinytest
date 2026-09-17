<?php declare(strict_types=1);
namespace TinyTest;

// One scope per case (or provider), separate from mutable reporting counters.
final class AssertionScope
{
    public $failures = 0;
    public $error = null;
}

// Collect PHP diagnostics without throwing them into application try/catch blocks.
// All handlers are scoped; @ and error_reporting()/ -s are respected.
final class PhpErrors
{
    private $errors = [];

    public function handle(int $severity, string $message, string $file, int $line): bool
    {
        if (error_reporting() & $severity) {
            $this->errors[] = new \ErrorException($message, 0, $severity, $file, $line);
        }
        return true;
    }

    public function finish(array $expected = []): ?\Throwable
    {
        $matched = array_fill(0, count($expected), false);
        foreach ($this->errors as $error) {
            $accepted = false;
            foreach ($expected as $index => $specification) {
                $specification = trim($specification);
                if (preg_match('/^E_[A-Z_]+$/', $specification) && defined($specification)) {
                    $matches = constant($specification) === $error->getSeverity();
                } else {
                    // Compatibility: @phperror Warning:message substring
                    $parts = explode(':', $specification, 2);
                    $names = [E_WARNING => 'Warning', E_USER_WARNING => 'Warning',
                        E_NOTICE => 'Notice', E_USER_NOTICE => 'Notice', E_USER_ERROR => 'Error',
                        E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'Deprecated'];
                    $matches = count($parts) === 2
                        && strcasecmp(trim($parts[0]), $names[$error->getSeverity()] ?? '') === 0
                        && stripos($error->getMessage(), $parts[1]) !== false;
                }
                if ($matches) {
                    $matched[$index] = $accepted = true;
                }
            }
            if (!$accepted) {
                return $error;
            }
        }
        foreach ($matched as $index => $found) {
            if (!$found) {
                return new \RuntimeException('expected PHP error was not emitted: ' . trim($expected[$index]));
            }
        }
        foreach ($matched as $found) {
            count_assertion_pass();
        }
        return null;
    }
}

// Restore even when project code installed additional handlers. The probe is a
// unique sentinel, avoiding assumptions about how many handlers code pushed.
function restore_handler(callable $installed, $previous): void
{
    for ($i = 0; $i < 100; $i++) {
        $current = set_error_handler($installed);
        restore_error_handler();
        if ($current === $installed) {
            restore_error_handler();
            return;
        }
        if ($current === $previous) { return; }
        if ($current === null) {
            if ($previous !== null) { set_error_handler($previous); }
            return;
        }
        restore_error_handler();
    }
}
