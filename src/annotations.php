<?php declare(strict_types=1);
namespace TinyTest;

function read_test_annotations(string $testname): array
{
    $refFunc = new \ReflectionFunction($testname);
    $result = array('exception' => array(), 'test' => $testname, 'type' => 'standard', 'file' => $refFunc->getFileName(), 'line' => $refFunc->getStartLine(), 'error' => '', 'phperror' => array());
    $result['mtime'] = get_mtime($result['file']);
    $doc = $refFunc->getDocComment();
    if ($doc === false) {
        return $result;
    }

    $docs = explode("\n", $doc);
    array_walk($docs, function ($line) use (&$result) {
        if (preg_match("/\@(\w+)(.*)/", $line, $matches)) {
            // Accept both single-line and multiline PHPDoc annotations.
            $matches[2] = preg_replace('/\*\/\s*$/', '', $matches[2]);
            $last = preg_split('/\s+/', trim($matches[2]))[0];
            if ($matches[1] === "exception") {
                $result['exception'][] = $last;
            } else if ($matches[1] === "phperror") {
                array_push($result['phperror'], $matches[2]);
            } else if ($matches[1] === "skip" || $matches[1] === "todo" || $matches[1] === "ambiguous") {
                $result[$matches[1]] = trim($matches[2]);
            } else {
                $result[$matches[1]] = $last;
            }
        }
    });

    return $result;
}
