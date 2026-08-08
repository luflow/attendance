#!/usr/bin/env bash
#
# Every gate this repo has, mirroring .github/workflows/tests.yml. All of them
# run even if an earlier one fails.
#
#   ./scripts/check.sh          # everything except e2e
#   ./scripts/check.sh --e2e    # plus the Playwright suite (needs Docker)

set -uo pipefail
cd "$(dirname "$0")/.."

RUN_E2E=0
[ "${1:-}" = "--e2e" ] && RUN_E2E=1

FAILED=()

run() {
	local name="$1"
	shift
	printf '\n\033[1m==> %s\033[0m\n' "$name"
	if "$@"; then
		printf '\033[32m    ok\033[0m\n'
	else
		printf '\033[31m    FAILED\033[0m\n'
		FAILED+=("$name")
	fi
}

if [ ! -d node_modules ]; then
	echo "node_modules is missing — run 'npm ci' first." >&2
	exit 1
fi
if [ ! -d vendor ]; then
	echo "vendor is missing — run 'composer install' first." >&2
	exit 1
fi

run "eslint"        npm run --silent lint
run "stylelint"     npm run --silent stylelint
run "php-cs-fixer"  composer --quiet cs:check
run "psalm"         composer --quiet psalm
run "phpunit"       composer --quiet test:unit
run "vite build"    npm run --silent build

# German is hand-maintained (see .tx/config), so no upstream sync catches these.
run "german l10n"   python3 scripts/check-german-l10n.py

# Generated from the controller annotations — a stale spec means drift.
printf '\n\033[1m==> openapi spec is up to date\033[0m\n'
if composer --quiet openapi >/dev/null 2>&1 && git diff --quiet -- openapi.json openapi-administration.json openapi-full.json; then
	printf '\033[32m    ok\033[0m\n'
else
	printf '\033[31m    FAILED — run "composer openapi" and commit the result\033[0m\n'
	FAILED+=("openapi spec is up to date")
fi

if [ "$RUN_E2E" = 1 ]; then
	run "playwright e2e" npm run --silent test:e2e
fi

printf '\n'
if [ ${#FAILED[@]} -eq 0 ]; then
	printf '\033[32mAll checks passed.\033[0m\n'
	exit 0
fi
printf '\033[31mFailed: %s\033[0m\n' "${FAILED[*]}"
exit 1
