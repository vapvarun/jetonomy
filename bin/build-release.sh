#!/usr/bin/env bash
#
# build-release.sh — produce a release zip for Jetonomy.
#
# Guards against the 1.3.5-style shipping accident: the zip is always
# rebuilt from the current working tree, verified against the version
# headers, and smoke-tested by actually booting the plugin in a
# minimal WP stub before the zip is deemed shippable.
#
# Usage:
#   ./bin/build-release.sh           # build from current HEAD
#   ./bin/build-release.sh --allow-dirty  # skip the clean-tree check (dev)
#   ./bin/build-release.sh --output ~/Desktop  # copy zip to DIR after build
#
# Exit codes:
#   0  success — zip at dist/jetonomy-<version>.zip is ready to ship
#   10 tree is dirty and --allow-dirty not set
#   11 version mismatch across jetonomy.php / readme.txt
#   20 composer install failed
#   30 smoke test failed — plugin fatals on boot
#   31 qa-coverage gate regressed
#   32 phpunit gate failed (tests red, or WP test scaffold absent)
#   40 zip missing expected files
#
# The script is intentionally thorough. Shipping a broken zip is a worse
# problem than a slow build.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="jetonomy"
MAIN_FILE="${PLUGIN_SLUG}.php"

ALLOW_DIRTY=0
OUTPUT_DIR=""
SKIP_BROWSER_SMOKE=0
while [ $# -gt 0 ]; do
	case "$1" in
		--allow-dirty) ALLOW_DIRTY=1; shift ;;
		--output) OUTPUT_DIR="$2"; shift 2 ;;
		--skip-browser-smoke) SKIP_BROWSER_SMOKE=1; shift ;;
		*) echo "unknown flag: $1" >&2; exit 2 ;;
	esac
done

echo "==> build-release.sh — $PLUGIN_SLUG"
cd "$ROOT"

# --- 0. asset regen (grunt build) ------------------------------------------
# Refresh every *.min.css, *.min.js, RTL CSS, and the .pot translation file
# from the committed source. Closes the "stale .min" gap that shipped a
# 1.3.7 zip without minified versions of newly added 1.3.8 assets.
if [ -f "$ROOT/Gruntfile.js" ]; then
	echo "==> grunt build (regen .min, RTL CSS, .pot)"
	if [ ! -x "$ROOT/node_modules/.bin/grunt" ]; then
		echo "    installing node deps (one-time)"
		( cd "$ROOT" && npm install --silent ) || {
			echo "FAIL: npm install failed; cannot regenerate assets" >&2
			exit 21
		}
	fi
	( cd "$ROOT" && ./node_modules/.bin/grunt build > /dev/null ) || {
		echo "FAIL: grunt build failed; will not ship without fresh .min / .pot" >&2
		exit 21
	}
fi

# --- 0b. hooks-index drift gate ---------------------------------------------
# The generated hooks index (docs/website/developer-guide/02a-hooks-index.md)
# is derived from audit/manifest.json by bin/gen-hooks-reference.php. It was
# documented as "regenerated at every release" but nothing enforced that, so
# it sat one release stale (192 hooks while the manifest carried 200 -
# Basecamp 10114333907). Regenerate here and FAIL if the committed copy
# differs: the fix is to commit the regenerated index, same discipline as the
# clean-tree gate below.
if [ -f "$ROOT/bin/gen-hooks-reference.php" ]; then
	echo "==> hooks index regen (from manifest)"
	( cd "$ROOT" && php bin/gen-hooks-reference.php > /dev/null ) || {
		echo "FAIL: gen-hooks-reference.php errored - manifest unreadable?" >&2
		exit 22
	}
	if ! git -C "$ROOT" diff --quiet -- docs/website/developer-guide/02a-hooks-index.md; then
		echo "FAIL: generated hooks index is stale vs audit/manifest.json." >&2
		echo "    Commit the regenerated docs/website/developer-guide/02a-hooks-index.md and re-run." >&2
		exit 23
	fi
fi

# --- 0c. docs-consistency gate ----------------------------------------------
# Docs must not advertise features the code does not have (the realtime
# adapter kept resurfacing across three QA passes after manual scrubs -
# Basecamp 10114333907). Exit 24 so CI can distinguish the failure.
if [ -f "$ROOT/bin/audit-docs-consistency.php" ]; then
	echo "==> Step 0c: docs-consistency gate"
	( cd "$ROOT" && php bin/audit-docs-consistency.php ) || {
		echo "FAIL: docs claim features the code does not have (see list above)." >&2
		exit 24
	}
fi

# --- 0d. i18n make-pot warning gate ------------------------------------------
# Translator-comment coverage is release-gated (QA 10150516732): a release
# cannot ship while wp i18n make-pot reports string warnings on the tree.
# Exit 25. Requires wp-cli; skipped (with a loud note) when wp is absent.
if command -v wp > /dev/null 2>&1; then
	echo "==> Step 0d: i18n make-pot warning gate"
	I18N_WARNINGS="$(wp i18n make-pot "$ROOT" /tmp/jetonomy-release-check.pot \
		--slug=jetonomy --domain=jetonomy \
		--exclude=node_modules,vendor,tests,build,libs,docs,dist 2>&1 \
		| grep -cE '^Warning: The string' || true)"
	rm -f /tmp/jetonomy-release-check.pot
	if [ "$I18N_WARNINGS" -gt 0 ]; then
		echo "FAIL: wp i18n make-pot reports $I18N_WARNINGS string warning(s). Fix translator comments before tagging." >&2
		exit 25
	fi
else
	echo "    NOTE: wp-cli not found - i18n warning gate SKIPPED on this box."
fi

# --- 1. clean-tree gate -----------------------------------------------------
# Step 0 may have regenerated build artefacts. The gate excludes
# grunt-generated paths so the build doesn't trip on its own deterministic
# output, while still catching any uncommitted source / template / PHP work.
if [ "$ALLOW_DIRTY" -eq 0 ]; then
	DIRTY="$(git status --porcelain \
		':(exclude)assets/css/*.min.css' \
		':(exclude)assets/css/*-rtl.css' \
		':(exclude)assets/css/*-rtl.min.css' \
		':(exclude)assets/js/*.min.js' \
		':(exclude)languages/*.pot' || true)"
	if [ -n "$DIRTY" ]; then
		echo "FAIL: working tree has uncommitted changes (excluding build artefacts). Commit or pass --allow-dirty." >&2
		echo "$DIRTY" >&2
		exit 10
	fi
fi
HEAD_SHA="$(git rev-parse --short HEAD)"
echo "    HEAD: $HEAD_SHA"

# --- 2. version triangulation ----------------------------------------------
V_HEADER="$(grep -E '^\s*\*\s*Version:' "$MAIN_FILE" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"
V_CONST="$(grep -E "define\(\s*'JETONOMY_VERSION'" "$MAIN_FILE" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"
V_README="$(grep -E '^Stable tag:' readme.txt | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"

if [ -z "$V_HEADER" ] || [ -z "$V_CONST" ] || [ -z "$V_README" ]; then
	echo "FAIL: could not read one of the version fields" >&2
	echo "    header=$V_HEADER  const=$V_CONST  readme=$V_README" >&2
	exit 11
fi

if [ "$V_HEADER" != "$V_CONST" ] || [ "$V_HEADER" != "$V_README" ]; then
	echo "FAIL: version mismatch across metadata" >&2
	echo "    header=$V_HEADER  const=$V_CONST  readme=$V_README" >&2
	exit 11
fi

VERSION="$V_HEADER"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
echo "    version: $VERSION"

# --- 3. stage into dist/ ---------------------------------------------------
STAGE_PARENT="$ROOT/dist"
STAGE="$STAGE_PARENT/$PLUGIN_SLUG"
rm -rf "$STAGE" "$STAGE_PARENT/$ZIP_NAME"
mkdir -p "$STAGE"

EXCLUDES_FILE="$(mktemp)"
trap 'rm -f "$EXCLUDES_FILE"' EXIT
cat > "$EXCLUDES_FILE" <<'EOF'
.git/
.github/
.githooks/
.gitignore
.gitattributes
.claude/
node_modules/
tests/
plan/
audit/
marketing/
bin/
tools/
dist/
docs/
CLAUDE.md
phpcs.xml
phpcs.xml.dist
.phpcs.xml.dist
phpstan.neon
phpstan.neon.dist
phpstan-*.neon
phpstan-*.neon.dist
phpstan-bootstrap.php
phpunit.xml
phpunit.xml.dist
.phpunit.result.cache
composer.lock
package-lock.json
package.json
webpack.config.js
Gruntfile.js
.editorconfig
.eslintrc*
.prettierrc*
# Every Markdown file is a developer artifact - none is read at runtime.
# This was a named list (README/CONTRIBUTING/CHANGELOG), which is why
# CAPABILITIES.md still shipped in the zip (Basecamp 10208076128): the list
# only ever grew after someone spotted a leak. The pattern closes the class.
# readme.txt is NOT matched by *.md and still ships, as WordPress.org needs it.
*.md
.DS_Store
.vscode/
.idea/
.distignore
.wp-env.json
.playwright-mcp/
/build/
verify-*.png
verify-*.jpg
verify-*.log
*.log
EOF

rsync -a --exclude-from="$EXCLUDES_FILE" ./ "$STAGE/"

# --- 4. production composer in staging ------------------------------------
if [ -f "$STAGE/composer.json" ]; then
	echo "==> composer install (no-dev, optimize-autoloader) in staging"
	pushd "$STAGE" > /dev/null
	composer install --no-dev --optimize-autoloader --quiet || {
		echo "FAIL: composer install in staging exited non-zero" >&2
		exit 20
	}
	popd > /dev/null
fi

# --- 5. sanity — required files present -----------------------------------
# Bundled SDKs (EDD SL SDK, etc.) MUST ship in the zip. They live under libs/
# and are committed to git so they don't depend on the build machine having
# them in vendor/. The 1.4.2 release shipped without edd-sl-sdk.js because
# the SDK was in vendor/ (gitignored) and the build box happened not to have it.
REQUIRED_FILES=(
	"$MAIN_FILE"
	"readme.txt"
	"includes/functions.php"
	"includes/class-autoloader.php"
	"includes/class-jetonomy.php"
	"libs/edd-sl-sdk/edd-sl-sdk.php"
	"libs/edd-sl-sdk/assets/build/js/edd-sl-sdk.js"
	"libs/edd-sl-sdk/assets/build/css/style-edd-sl-sdk.css"
	"libs/action-scheduler/action-scheduler.php"
	"libs/action-scheduler/functions.php"
	# Vendored Prism (QA card 10149499675): the 1.8.0 zip shipped WITHOUT
	# assets/vendor/ because the tree was never committed - the loader's
	# file_exists() guard then silently no-opped the marketed highlighting
	# feature for every customer. Required so packaging can't regress.
	"assets/vendor/prismjs/prism.min.js"
	"assets/vendor/prismjs/prism.min.css"
	"assets/vendor/prismjs/prism-autoloader.min.js"
	"assets/vendor/prismjs/components/prism-php.min.js"
	# MIT notice must ship with the vendored library (QA 10149499675).
	"assets/vendor/prismjs/LICENSE"
	# Full language set backs the readme's language claim - kotlin is the
	# canary the QA probe requested and 404'd on when only 20 shipped.
	"assets/vendor/prismjs/components/prism-kotlin.min.js"
)
for f in "${REQUIRED_FILES[@]}"; do
	if [ ! -f "$STAGE/$f" ]; then
		echo "FAIL: required file missing from staging: $f" >&2
		exit 40
	fi
done

# --- 5b. source/min pairing assertion -------------------------------------
# Every *.css that is not already a *.min.css and not an *-rtl* generated
# variant must have a *.min.css counterpart in staging. Same for JS. Catches
# the "newly added source file with no minified version" gap that almost
# shipped in 1.3.8 (fluent-community.css, moderation.js, space-members.js).
MISSING_MINS=()
if [ -d "$STAGE/assets/css" ]; then
	while IFS= read -r src; do
		base="$(basename "$src" .css)"
		if [ ! -f "$STAGE/assets/css/${base}.min.css" ]; then
			MISSING_MINS+=( "${src#$STAGE/} -> ${base}.min.css" )
		fi
	done < <(find "$STAGE/assets/css" -maxdepth 1 -type f -name '*.css' ! -name '*.min.css' ! -name '*-rtl.css' ! -name '*-rtl.min.css')
fi
if [ -d "$STAGE/assets/js" ]; then
	while IFS= read -r src; do
		base="$(basename "$src" .js)"
		if [ ! -f "$STAGE/assets/js/${base}.min.js" ]; then
			MISSING_MINS+=( "${src#$STAGE/} -> ${base}.min.js" )
		fi
	done < <(find "$STAGE/assets/js" -maxdepth 1 -type f -name '*.js' ! -name '*.min.js')
fi
if [ "${#MISSING_MINS[@]}" -gt 0 ]; then
	echo "FAIL: staged tree is missing minified counterparts for source files:" >&2
	printf '    %s\n' "${MISSING_MINS[@]}" >&2
	echo "    Run \`./node_modules/.bin/grunt build\` and re-run." >&2
	exit 40
fi

# --- 5c. cruft check on staged top level ----------------------------------
# Reject the build if any obvious dev / temp file made it past the EXCLUDES
# list. Cheaper than reading every released zip by hand.
CRUFT=()
for pattern in \
	"$STAGE/.distignore" \
	"$STAGE/.wp-env.json" \
	"$STAGE/.playwright-mcp" \
	"$STAGE/build" \
	"$STAGE/Gruntfile.js" \
	"$STAGE/package.json" \
	"$STAGE/package-lock.json"; do
	[ -e "$pattern" ] && CRUFT+=( "${pattern#$STAGE/}" )
done
while IFS= read -r f; do
	CRUFT+=( "${f#$STAGE/}" )
done < <(find "$STAGE" -maxdepth 1 -type f \( -name 'verify-*' -o -name '*.log' -o -name 'phpstan-*.dist' -o -name 'phpstan-*.neon' \) 2>/dev/null)
if [ "${#CRUFT[@]}" -gt 0 ]; then
	echo "FAIL: dev / temp files leaked into the staged tree:" >&2
	printf '    %s\n' "${CRUFT[@]}" >&2
	echo "    Add the matching pattern to the EXCLUDES heredoc in this script, or remove the file." >&2
	exit 40
fi

# --- 5c2. admin-table responsive contract guard -----------------------------
# Raw <table> markup in admin views bypasses the responsive contract and
# regresses mobile/iPad rendering (Basecamp 10146443346). The baseline inside
# the script is shrink-only; a NEW offender fails the build.
if [ -f "$ROOT/bin/audit-admin-tables.php" ]; then
	echo "==> admin-table contract guard"
	php "$ROOT/bin/audit-admin-tables.php" "$ROOT/includes/admin/views" || {
		echo "FAIL: new admin table bypasses the responsive contract (see above)." >&2
		exit 41
	}
fi

# --- 5d. contract audit (key/hook contract drift, free+pro pair) ----------
# Static scan for orphan option/meta keys, hooks consumed-never-fired, and
# AJAX wiring drift. Baseline: .contract-audit-baseline.json (triaged
# 2026-06-11). New unbaselined errors = contract drift since last release.
CONTRACT_AUDIT="$HOME/.claude/skills/wp-contract-audit/scripts/contract-audit.php"
if [ -f "$CONTRACT_AUDIT" ]; then
	echo "==> contract audit (pair: jetonomy-pro)"
	if ! php "$CONTRACT_AUDIT" "$ROOT" --pair="$ROOT/../jetonomy-pro"; then
		echo "FAIL: contract audit found unbaselined errors - fix or baseline (with a real reason) before release." >&2
		exit 41
	fi
else
	echo "==> contract audit SKIPPED (skill script not on this machine)"
fi

# --- 6. syntax lint every PHP in the staging ------------------------------
echo "==> php -l on staged PHP files"
LINT_FAILED=0
while IFS= read -r -d '' file; do
	if ! php -l "$file" > /dev/null 2>&1; then
		echo "FAIL: syntax error in $file" >&2
		php -l "$file" >&2
		LINT_FAILED=1
	fi
done < <(find "$STAGE" -type f -name '*.php' -print0)
if [ "$LINT_FAILED" -ne 0 ]; then
	exit 30
fi

# --- 6b. QA COVERAGE GATE — manifest entries must have test stubs ---------
# Phase A/E gate: bin/qa-coverage-check.php reads audit/manifest.json and
# asserts every REST endpoint / AJAX handler / cron / hook-with-consumer
# has corresponding test coverage. Exit 1 if uncovered_total grew vs the
# previous run (drift) — same baseline-shrink discipline phpstan-baseline
# uses. Skip with COVERAGE_SKIP=1 only in genuine emergencies.
if [ -f "$ROOT/bin/qa-coverage-check.php" ] && [ -z "${COVERAGE_SKIP:-}" ]; then
	echo "==> qa-coverage gate"
	if ! php "$ROOT/bin/qa-coverage-check.php" --quiet --plugin="$ROOT"; then
		echo "FAIL: QA coverage regressed — see audit/qa-coverage.json" >&2
		echo "      Each uncovered entry has a stub_command. Run them, fill in TODOs," >&2
		echo "      verify pass, retry the build." >&2
		echo "      Bypass (emergency only): COVERAGE_SKIP=1 bin/build-release.sh" >&2
		exit 31
	fi
fi

# --- 6c. PHPUNIT GATE — run the free test matrix CI runs, locally ---------
# The gap that let red CI ship in 1.8.0: nothing ran PHPUnit before the tag, so
# a zip whose GitHub test matrix was failing still got packaged and released.
# Run the exact free testsuites CI runs (JETONOMY_TEST_SKIP_PRO=1) against the
# source tree ($ROOT keeps its dev deps; staging is a separate --no-dev copy),
# and fail the build on any failure. FAIL-CLOSED: if the WP test scaffold is
# absent we STOP rather than skip, so "no tests ran" can never look like green.
# Bypass (emergencies only): PHPUNIT_SKIP=1 bin/build-release.sh
if [ -z "${PHPUNIT_SKIP:-}" ]; then
	echo "==> phpunit gate (free: unit,integration,security,error-paths,concurrency)"
	PHPUNIT_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
	if [ ! -f "$PHPUNIT_TESTS_DIR/includes/functions.php" ]; then
		echo "FAIL: WordPress test library not found at $PHPUNIT_TESTS_DIR." >&2
		echo "      Install it once, then retry:" >&2
		echo "        bash bin/install-wp-tests.sh wp_tests root root localhost latest true" >&2
		echo "      or export WP_TESTS_DIR to an existing scaffold before building." >&2
		echo "      Bypass (emergency only): PHPUNIT_SKIP=1 bin/build-release.sh" >&2
		exit 32
	fi
	if ! JETONOMY_TEST_SKIP_PRO=1 php "$ROOT/vendor/bin/phpunit" \
			--testsuite=unit,integration,security,error-paths,concurrency; then
		echo "FAIL: PHPUnit reported failures — this is the gate that was missing when" >&2
		echo "      red CI shipped in 1.8.0. Fix the tests/code; do not tag." >&2
		echo "      Bypass (emergency only): PHPUNIT_SKIP=1 bin/build-release.sh" >&2
		exit 32
	fi
fi

# --- 7. SMOKE TEST — boot the plugin in the minimal WP stub ---------------
# This is the step that would have caught the 1.3.5 fatal. Do not remove.
echo "==> smoke-test: boot plugin + fire plugins_loaded"
if [ ! -f "$ROOT/tools/smoke-test.php" ]; then
	echo "FAIL: tools/smoke-test.php missing — cannot run smoke test" >&2
	exit 30
fi
if ! php "$ROOT/tools/smoke-test.php" "$STAGE/$MAIN_FILE"; then
	echo "FAIL: smoke test reported a fatal while booting the plugin from the staged zip." >&2
	echo "      This is the same class of error that shipped in the broken 1.3.5 zip." >&2
	echo "      Fix the underlying bug; do not ship." >&2
	exit 30
fi

# --- 7b. BROWSER SMOKE GATE — require a recent agent-run green report -----
# Gates the package behind a documented browser walk of customer-facing flows.
# Customer-first-hand-experience protection: no release ships unless a fresh
# run of docs/qa/AGENT_SMOKE_RUNBOOK.md (dispatched to Sonnet via the
# jetonomy-smoke skill in jetonomy-pro/.claude/skills/) reported zero failures
# and was dated within the last 24 hours.
#
# Emergency bypass: --skip-browser-smoke (logs a warning to the zip manifest).
#
# WHERE the report lives is docs/qa/qa-config.json's business, not this
# script's. This used to prefer a per-mode name (.last-smoke-pass-free.json)
# and fall back to the config's name - but nothing has written the per-mode
# name since the smoke skill started honouring report_path, so the preference
# was vestigial AND dangerous: on the 1.9.3 release a leftover -free file from
# 1.8.0 (five weeks and two releases old) shadowed the report that had just
# been written, and the gate compared the release against a stale artefact.
#
# One file, named by the config, no fallback chain. A sibling can no longer
# shadow the real report because siblings are not consulted.
SMOKE_REPORT_REL="$(python3 -c "
import json, sys
try:
    print(json.load(open('$ROOT/docs/qa/qa-config.json')).get('report_path') or 'docs/qa/.last-smoke-pass.json')
except Exception:
    print('docs/qa/.last-smoke-pass.json')
" 2>/dev/null)"
SMOKE_REPORT="$ROOT/${SMOKE_REPORT_REL:-docs/qa/.last-smoke-pass.json}"

# A stale sibling is no longer READ, but it still misleads a human who goes
# looking. Say so rather than leaving it lying there silently.
for _stale in "$ROOT"/docs/qa/.last-smoke-pass-*.json; do
	[ -e "$_stale" ] || continue
	echo "    NOTE: ignoring $(basename "$_stale") - the gate reads $SMOKE_REPORT_REL (qa-config.json report_path)."
done
if [ "$SKIP_BROWSER_SMOKE" -eq 1 ]; then
	echo "WARN: browser smoke gate skipped (--skip-browser-smoke). Not for customer releases."
elif [ ! -f "$SMOKE_REPORT" ]; then
	echo "FAIL: no browser smoke report at $SMOKE_REPORT" >&2
	echo "      Run the jetonomy-smoke skill first:" >&2
	echo "        Ask Claude Code: \"run the jetonomy pre-release smoke in combo mode\"" >&2
	echo "      The skill dispatches Sonnet with Playwright MCP, walks every" >&2
	echo "      customer-facing flow, and writes $SMOKE_REPORT on green pass." >&2
	echo "      Emergency only: rerun with --skip-browser-smoke." >&2
	exit 30
else
	# Validate the report: must match current VERSION, no `from`-origin failures.
	# A `for`-origin entry (test harness, theme, OS, not our plugin) in
	# debug_log_issues is informational and does not block. Only entries our
	# code emitted are blockers.
	REPORT_JSON_CHECK="$(python3 -c "
import json, sys
try:
    d = json.load(open('$SMOKE_REPORT'))
except Exception as e:
    print('PARSE_FAIL ' + str(e))
    sys.exit(0)
release = d.get('release_version', '')
mode = d.get('mode', '')
failures = d.get('failures') or []
debug_issues = d.get('debug_log_issues') or []
ran_at = d.get('ran_at', '')
from_failures = [f for f in failures if (f.get('origin') or 'from') == 'from']
from_issues = [i for i in debug_issues if (i.get('origin') or 'from') == 'from']
print('VERSION=' + release)
print('MODE=' + mode)
print('FROM_FAILURES=' + str(len(from_failures)))
print('FROM_ISSUES=' + str(len(from_issues)))
print('RAN_AT=' + ran_at)
" 2>&1)"
	if echo "$REPORT_JSON_CHECK" | grep -q '^PARSE_FAIL'; then
		echo "FAIL: smoke report at $SMOKE_REPORT is not valid JSON." >&2
		echo "$REPORT_JSON_CHECK" >&2
		exit 30
	fi
	REPORT_VERSION="$(echo "$REPORT_JSON_CHECK" | grep -oE '^VERSION=.*' | sed 's/^VERSION=//')"
	FROM_FAILURES="$(echo "$REPORT_JSON_CHECK" | grep -oE '^FROM_FAILURES=.*' | sed 's/^FROM_FAILURES=//')"
	FROM_ISSUES="$(echo "$REPORT_JSON_CHECK" | grep -oE '^FROM_ISSUES=.*' | sed 's/^FROM_ISSUES=//')"
	RAN_AT="$(echo "$REPORT_JSON_CHECK" | grep -oE '^RAN_AT=.*' | sed 's/^RAN_AT=//')"
	REPORT_MODE="$(echo "$REPORT_JSON_CHECK" | grep -oE '^MODE=.*' | sed 's/^MODE=//')"

	# The filename used to imply the mode; now the report states it, so check it.
	# A report is only a valid gate for a mode this plugin actually declares -
	# otherwise a run against some other scope silently passes for this one.
	ALLOWED_MODES="$(python3 -c "
import json
try:
    print(' '.join(json.load(open('$ROOT/docs/qa/qa-config.json')).get('modes') or []))
except Exception:
    print('')
" 2>/dev/null)"
	if [ -n "$ALLOWED_MODES" ] && [ -n "$REPORT_MODE" ]; then
		case " $ALLOWED_MODES " in
			*" $REPORT_MODE "*) : ;;
			*)
				echo "FAIL: smoke report mode '$REPORT_MODE' is not one this plugin declares ($ALLOWED_MODES)." >&2
				echo "      Rerun the smoke in a declared mode before packaging." >&2
				exit 30
				;;
		esac
	fi

	if [ "$REPORT_VERSION" != "$VERSION" ]; then
		echo "FAIL: smoke report version ($REPORT_VERSION) doesn't match release version ($VERSION)" >&2
		echo "      Rerun the jetonomy-smoke skill against HEAD before packaging." >&2
		exit 30
	fi
	if [ "$FROM_FAILURES" != "0" ]; then
		echo "FAIL: smoke report has $FROM_FAILURES \`from\`-origin failures. Fix them before packaging." >&2
		exit 30
	fi
	if [ "$FROM_ISSUES" != "0" ]; then
		echo "FAIL: smoke report recorded $FROM_ISSUES \`from\`-origin debug.log entries during the walk. Fix them before packaging." >&2
		exit 30
	fi
	# Coverage gate. "No failures" is not the same as "it ran". The 2026-09-02
	# walk skipped 47 of 84 checks - including every E_pro_smoke and
	# S_pro_supplements row, because no Pro licence bypass was set, so all 17
	# extensions silently never booted - and still satisfied every check above.
	# A release carrying a Pro fix was one flag away from shipping with the Pro
	# half of the gate never executed. Assert that each required section
	# actually produced passes.
	COVERAGE_CHECK="$(python3 -c "
import json
try:
    cfg = json.load(open('$ROOT/docs/qa/qa-config.json'))
except Exception:
    cfg = {}
gate = cfg.get('coverage_gate') or {}
required = gate.get('required_sections') or []
minimum = gate.get('min_pass_per_required_section', 1)
if not required:
    print('SKIP')
else:
    d = json.load(open('$SMOKE_REPORT'))
    sections = d.get('sections') or {}
    bad = []
    for name in required:
        got = (sections.get(name) or {}).get('pass', 0)
        if got < minimum:
            bad.append('%s (pass=%d, need >=%d)' % (name, got, minimum))
    print('EMPTY_SECTIONS=' + '; '.join(bad) if bad else 'OK')
" 2>&1)"
	if echo "$COVERAGE_CHECK" | grep -q '^EMPTY_SECTIONS='; then
		echo "FAIL: smoke report has required sections with no passing checks." >&2
		echo "      ${COVERAGE_CHECK#EMPTY_SECTIONS=}" >&2
		echo "      A section that skipped everything is not a section that passed." >&2
		echo "      Check docs/qa/qa-config.json model_env.required_constants first -" >&2
		echo "      a missing JETONOMY_PRO_LICENSE_BYPASS silently skips all Pro checks." >&2
		exit 30
	fi
	if [ -n "$RAN_AT" ]; then
		echo "    smoke report dated $RAN_AT (mode: ${REPORT_MODE:-unknown}) - OK"
		echo "    coverage gate: ${COVERAGE_CHECK}"
	fi
fi

# --- 8. zip it ------------------------------------------------------------
echo "==> zipping $ZIP_NAME"
( cd "$STAGE_PARENT" && zip -rq "$ZIP_NAME" "$PLUGIN_SLUG/" )
SIZE="$(du -h "$STAGE_PARENT/$ZIP_NAME" | awk '{print $1}')"
SHA256="$(shasum -a 256 "$STAGE_PARENT/$ZIP_NAME" | awk '{print $1}')"
echo "    $STAGE_PARENT/$ZIP_NAME ($SIZE, sha256=$SHA256)"

# --- 9. zip content re-verification ---------------------------------------
# Extract the finished zip to a scratch dir and re-run the smoke test so we
# catch any zip corruption / file-dropping surprise.
SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"; rm -f "$EXCLUDES_FILE"' EXIT
unzip -q "$STAGE_PARENT/$ZIP_NAME" -d "$SCRATCH"
if ! php "$ROOT/tools/smoke-test.php" "$SCRATCH/$PLUGIN_SLUG/$MAIN_FILE"; then
	echo "FAIL: smoke test of the zipped-and-re-extracted build failed." >&2
	exit 30
fi

# --- 10. optional output copy ---------------------------------------------
if [ -n "$OUTPUT_DIR" ]; then
	mkdir -p "$OUTPUT_DIR"
	cp "$STAGE_PARENT/$ZIP_NAME" "$OUTPUT_DIR/"
	echo "    copied to $OUTPUT_DIR/$ZIP_NAME"
fi

echo
echo "OK — ${PLUGIN_SLUG}-${VERSION}.zip is ready."
echo "    built from git $HEAD_SHA"
echo "    sha256: $SHA256"
