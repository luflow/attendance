#!/usr/bin/env bash
#
# Every gate this repo has, in one command. Run it before handing work back —
# CI (.github/workflows/tests.yml) runs the same set, so a green run here means
# a green run there, minus the e2e suite which needs Docker.
#
#   ./scripts/check.sh            # everything except e2e
#   ./scripts/check.sh --e2e      # also run the Playwright suite (needs Docker)
#
# Every gate runs even if an earlier one fails, so one invocation gives you the
# full picture instead of a first-failure-only trickle.

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

# The OpenAPI specs are generated from the controller annotations, so a stale
# spec means the documented API and the real one have drifted apart.
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
