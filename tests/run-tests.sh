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

# ---------------------------------------------------------------------------
# LayerRulesPlugin, against a small fake codebase with one good and one bad
# example of each rule.

LAYER_FIXTURES_DIR="$SCRIPT_DIR/layer-fixtures"

echo ""
echo "Running Phan against layer-rule fixtures..."
OUTPUT=$("$PHAN" --config-file "$LAYER_FIXTURES_DIR/.phan/config.php" --project-root-directory "$LAYER_FIXTURES_DIR" --no-progress-bar 2>&1 || true)

echo "Phan output:"
echo "$OUTPUT"
echo ""

assert_count() {
	local label="$1"
	local pattern="$2"
	local expected="$3"
	local actual
	actual=$(echo "$OUTPUT" | grep -cF "$pattern" || true)
	if [ "$actual" -eq "$expected" ]; then
		echo "PASS: $label"
		PASSED=$((PASSED + 1))
	else
		echo "FAIL: $label"
		echo "  Expected $expected lines containing: $pattern (found $actual)"
		FAILED=$((FAILED + 1))
	fi
}

# Domain (DDD-L1): only itself, framework domain code and PHP's own classes.
assert_contains \
	"Domain naming infrastructure only in a PHPDoc generic is reported" \
	"src/Domain.php:33 PhanPluginLayerViolation DDD-L1"
assert_contains \
	"Domain using a WordPress class is reported" \
	"src/Domain.php:40 PhanPluginLayerViolation DDD-L1"

# Application (DDD-L4): not infrastructure, not adapters.
assert_contains \
	"Application naming infrastructure inline, without a use, is reported" \
	"src/Application.php:30 PhanPluginLayerViolation DDD-L4"
assert_contains \
	"Application returning an adapter type is reported" \
	"src/Application.php:36 PhanPluginLayerViolation DDD-L4"

# Adapters (DDD-L5): no repositories, no aggregates, no infrastructure.
assert_contains \
	"Adapter holding a repository is reported (deny)" \
	"src/Api.php:28 PhanPluginLayerViolation DDD-L5"
assert_contains \
	"Adapter handling an aggregate is reported (deny_subtypes_of)" \
	"src/Api.php:34 PhanPluginLayerViolation DDD-L5"
assert_contains \
	"Adapter catching an infrastructure exception is reported" \
	"src/Api.php:42 PhanPluginLayerViolation DDD-L5"

# Nothing else: every good example stays quiet.
assert_count \
	"Exactly the eight planted violations are reported" \
	"PhanPluginLayerViolation" \
	8
assert_not_contains \
	"Aggregate using framework and PHP classes is quiet" \
	'Acme\Shop\Domain\Order (layer'
assert_not_contains \
	"Handler using a repository and returning a read model is quiet" \
	'Acme\Shop\Application\GetOrderHandler (layer'
assert_not_contains \
	"Infrastructure implementing a domain contract is quiet" \
	'Acme\Shop\Infra\OrderRepository (layer'
assert_not_contains \
	"Adapter using handlers, read models and enums is quiet" \
	'Acme\Shop\Api\GoodController (layer'

echo ""
echo "Results: $PASSED passed, $FAILED failed"

if [ "$FAILED" -gt 0 ]; then
	exit 1
fi

echo "All tests passed!"
