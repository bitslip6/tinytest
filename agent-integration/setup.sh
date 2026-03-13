#!/bin/bash
#
# TinyTest Claude Code Integration Setup
#
# Copies CLAUDE.md and skills into a consuming project.
#
# Usage:
#   bash /path/to/tinytest/claude-integration/setup.sh [project_dir]
#
# If project_dir is omitted, uses current directory.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TINYTEST_DIR="$(dirname "$SCRIPT_DIR")"
PROJECT_DIR="${1:-.}"
PROJECT_DIR="$(cd "$PROJECT_DIR" && pwd)"

echo "TinyTest Claude Code Integration Setup"
echo "======================================="
echo "TinyTest path: $TINYTEST_DIR"
echo "Project path:  $PROJECT_DIR"
echo ""

# 1. Copy CLAUDE.md template with path substitution
if [ -f "$PROJECT_DIR/CLAUDE.md" ]; then
    echo "[!] CLAUDE.md already exists in project root."
    echo "    Appending TinyTest section..."
    echo "" >> "$PROJECT_DIR/CLAUDE.md"
    sed "s|{{TINYTEST_PATH}}|$TINYTEST_DIR|g" "$SCRIPT_DIR/CLAUDE.md.template" >> "$PROJECT_DIR/CLAUDE.md"
else
    sed "s|{{TINYTEST_PATH}}|$TINYTEST_DIR|g" "$SCRIPT_DIR/CLAUDE.md.template" > "$PROJECT_DIR/CLAUDE.md"
    echo "[+] Created CLAUDE.md"
fi

# 2. Copy skills (each skill is a directory containing SKILL.md)
mkdir -p "$PROJECT_DIR/.claude/skills"
cp -r "$SCRIPT_DIR/skills/generate-test" "$PROJECT_DIR/.claude/skills/"
cp -r "$SCRIPT_DIR/skills/run-tests" "$PROJECT_DIR/.claude/skills/"
cp -r "$SCRIPT_DIR/skills/fix-test" "$PROJECT_DIR/.claude/skills/"
cp -r "$SCRIPT_DIR/skills/refactor-testable" "$PROJECT_DIR/.claude/skills/"
cp -r "$SCRIPT_DIR/skills/cover-functions" "$PROJECT_DIR/.claude/skills/"
cp -r "$SCRIPT_DIR/skills/audit-coverage" "$PROJECT_DIR/.claude/skills/"
cp -r "$SCRIPT_DIR/skills/analyze-function" "$PROJECT_DIR/.claude/skills/"
echo "[+] Copied skills to .claude/skills/"

# 2b. Copy agent definitions for pi subagent extension (if user has pi)
if [ -d "$PROJECT_DIR/.pi" ] || command -v pi &>/dev/null; then
    mkdir -p "$PROJECT_DIR/.pi/agents"
    if [ -d "$SCRIPT_DIR/../claude-integration/agents" ]; then
        cp "$SCRIPT_DIR/../claude-integration/agents/"*.md "$PROJECT_DIR/.pi/agents/" 2>/dev/null || true
        # Also copy to .claude/agents for Claude Code compatibility
        mkdir -p "$PROJECT_DIR/.claude/agents"
        cp "$SCRIPT_DIR/../claude-integration/agents/"*.md "$PROJECT_DIR/.claude/agents/" 2>/dev/null || true
        echo "[+] Copied agent definitions to .pi/agents/ and .claude/agents/"
    fi
fi

# 3. Create settings with tinytest permission rule
SETTINGS_FILE="$PROJECT_DIR/.claude/settings.local.json"
if [ -f "$SETTINGS_FILE" ]; then
    echo "[!] $SETTINGS_FILE already exists — skipping"
else
    mkdir -p "$PROJECT_DIR/.claude"
    cat > "$SETTINGS_FILE" << HEREDOC
{
  "permissions": {
    "allow": [
      "Bash(php $TINYTEST_DIR/tinytest.php*)"
    ]
  }
}
HEREDOC
    echo "[+] Created .claude/settings.local.json with tinytest permission"
fi

# 4. Create tests directory if it doesn't exist
if [ ! -d "$PROJECT_DIR/tests" ]; then
    mkdir -p "$PROJECT_DIR/tests"
    echo "[+] Created tests/ directory"
fi

echo ""
echo "Setup complete! Claude Code can now:"
echo "  - Use TinyTest assertions and conventions (via CLAUDE.md)"
echo "  - Generate, run, and fix tests (via skills)"
