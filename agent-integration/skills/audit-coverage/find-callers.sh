#!/bin/bash
# find-callers.sh — Find all callers of a PHP function across the project
#
# Usage: bash find-callers.sh <function_name> [source_root]
#
# Outputs JSON array of caller locations with surrounding context.

set -euo pipefail

FUNC_NAME="${1:?Usage: find-callers.sh <function_name> [source_root]}"
SOURCE_ROOT="${2:-.}"
MAX_CALLERS=20
CONTEXT_LINES=3

# Use ripgrep if available, fall back to grep
if command -v rg &>/dev/null; then
    MATCHES=$(rg -n "${FUNC_NAME}\s*\(" --type php \
        -g '!vendor/' -g '!tests/' -g '!test_*' -g '!node_modules/' \
        "$SOURCE_ROOT" 2>/dev/null | head -n "$MAX_CALLERS" || true)
else
    MATCHES=$(grep -rn "${FUNC_NAME}\s*(" --include="*.php" \
        --exclude-dir=vendor --exclude-dir=tests --exclude-dir=node_modules \
        "$SOURCE_ROOT" 2>/dev/null | head -n "$MAX_CALLERS" || true)
fi

# Parse matches into JSON
echo "$MATCHES" | php -r '
$lines = array_filter(explode("\n", file_get_contents("php://stdin")));
$callers = [];
foreach ($lines as $line) {
    // Format: file:lineno:content
    if (preg_match("/^(.+?):(\d+):(.*)$/", $line, $m)) {
        $file = $m[1];
        $lineno = (int)$m[2];
        $content = trim($m[3]);

        // Read surrounding context
        $context_lines = [];
        if (file_exists($file)) {
            $all_lines = file($file);
            $start = max(0, $lineno - 4);  // 3 lines before
            $end = min(count($all_lines), $lineno + 3);  // 3 lines after
            for ($i = $start; $i < $end; $i++) {
                $context_lines[] = [
                    "line" => $i + 1,
                    "text" => rtrim($all_lines[$i]),
                    "is_match" => ($i + 1 === $lineno),
                ];
            }
        }

        $callers[] = [
            "file" => $file,
            "line" => $lineno,
            "content" => $content,
            "context" => $context_lines,
        ];
    }
}
echo json_encode(["function" => $argv[1], "caller_count" => count($callers), "callers" => $callers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
' -- "$FUNC_NAME"
