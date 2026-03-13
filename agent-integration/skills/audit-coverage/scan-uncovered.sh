#!/bin/bash
# scan-uncovered.sh — Scan for uncovered functions and find their callers
#
# Usage: bash scan-uncovered.sh <tinytest_path> <tests_dir> [source_root]
#
# Outputs JSON to stdout with uncovered functions and their callers.
# Designed to be consumed by the audit-coverage skill.

set -euo pipefail

TINYTEST_PATH="${1:?Usage: scan-uncovered.sh <tinytest_path> <tests_dir> [source_root]}"
TESTS_DIR="${2:?Usage: scan-uncovered.sh <tinytest_path> <tests_dir> [source_root]}"
SOURCE_ROOT="${3:-.}"

# Check phpdbg
if ! command -v phpdbg &>/dev/null; then
    echo '{"error": "phpdbg not found. Install with: sudo apt install php-phpdbg"}' >&2
    exit 1
fi

# Run coverage
COVERAGE_JSON=$(phpdbg -qrr -d xdebug.mode=off "$TINYTEST_PATH/tinytest.php" -j -c -d "$TESTS_DIR" 2>/dev/null)

# Extract just the coverage key — if empty, we're done
COVERAGE=$(echo "$COVERAGE_JSON" | php -r '
$data = json_decode(file_get_contents("php://stdin"), true);
if (!isset($data["coverage"]) || empty($data["coverage"])) {
    echo json_encode(["status" => "fully_covered", "functions" => []]);
    exit(0);
}

$results = [];
foreach ($data["coverage"] as $file => $info) {
    foreach ($info["uncovered_functions"] as $fn) {
        $entry = [
            "file" => $file,
            "function" => $fn["name"],
            "line" => $fn["line"],
            "functions_total" => $info["functions_total"],
            "functions_covered" => $info["functions_covered"],
            "callers" => [],
        ];
        $results[] = $entry;
    }
}

echo json_encode(["status" => "uncovered_found", "count" => count($results), "functions" => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
')

echo "$COVERAGE"
