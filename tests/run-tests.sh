#!/usr/bin/env bash
#
# Test runner for the DDD Phan plugins.
# Runs Phan against test fixtures and asserts expected issues.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MONOREPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
PHAN="$MONOREPO_ROOT/vendor/bin/phan"
FIXTURES_DIR="$SCRIPT_DIR/fixtures"

if [ ! -x "$PHAN" ]; then
	echo "ERROR: Phan not found at $PHAN"
	exit 1
fi

echo "Running Phan against test fixtures..."
OUTPUT=$("$PHAN" --config-file "$FIXTURES_DIR/.phan/config.php" --project-root-directory "$FIXTURES_DIR" 2>&1 || true)

echo "Phan output:"
echo "$OUTPUT"
echo ""

PASSED=0
FAILED=0

assert_contains() {
	local label="$1"
	local pattern="$2"
	if echo "$OUTPUT" | grep -qF "$pattern"; then
		echo "PASS: $label"
		PASSED=$((PASSED + 1))
	else
		echo "FAIL: $label"
		echo "  Expected output to contain: $pattern"
		FAILED=$((FAILED + 1))
	fi
}

assert_not_contains() {
	local label="$1"
	local pattern="$2"
	if echo "$OUTPUT" | grep -qF "$pattern"; then
		echo "FAIL: $label"
		echo "  Expected output NOT to contain: $pattern"
		FAILED=$((FAILED + 1))
	else
		echo "PASS: $label"
		PASSED=$((PASSED + 1))
	fi
}

# Rule 1: MissingCollectFromRepository should trigger PhanPluginDDDMissingEventCollection
assert_contains \
	"MissingCollectFromRepository triggers Rule 1" \
	"PhanPluginDDDMissingEventCollection"

assert_contains \
	"MissingCollectFromRepository references save method" \
	"MissingCollectFromRepository.php"

# Rule 2: MissingUoWRepository should trigger PhanPluginDDDMissingEventsUnitOfWork
assert_contains \
	"MissingUoWRepository triggers Rule 2" \
	"PhanPluginDDDMissingEventsUnitOfWork"

assert_contains \
	"MissingUoWRepository is referenced" \
	"MissingUoWRepository.php"

# Good repos should NOT trigger any issues
assert_not_contains \
	"GoodRepository does not trigger any issues" \
	"GoodRepository.php"

# Entity repos should NOT trigger any issues
assert_not_contains \
	"EntityRepository does not trigger any issues" \
	"EntityRepository.php"

# Repos extending PersistsAggregatesRepository should be skipped
assert_not_contains \
	"ExtendsBaseRepository is skipped" \
	"ExtendsBaseRepository.php"

echo ""
echo "Results: $PASSED passed, $FAILED failed"

if [ "$FAILED" -gt 0 ]; then
	exit 1
fi

echo "All tests passed!"
