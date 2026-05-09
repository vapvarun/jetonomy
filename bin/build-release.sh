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
plans/
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
README.md
CONTRIBUTING.md
CHANGELOG.md
.DS_Store
.vscode/
.idea/
.distignore
.wp-env.json
.playwright-mcp/
build/
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
REQUIRED_FILES=( "$MAIN_FILE" "readme.txt" "includes/functions.php" "includes/class-autoloader.php" "includes/class-jetonomy.php" )
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
# The smoke skill writes the per-mode artefact (.last-smoke-pass-free.json)
# in current runs; legacy runs wrote .last-smoke-pass.json. Try the per-mode
# name first, fall back to the legacy single-file name.
SMOKE_REPORT="$ROOT/docs/qa/.last-smoke-pass-free.json"
if [ ! -f "$SMOKE_REPORT" ]; then
	SMOKE_REPORT="$ROOT/docs/qa/.last-smoke-pass.json"
fi
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
failures = d.get('failures') or []
debug_issues = d.get('debug_log_issues') or []
ran_at = d.get('ran_at', '')
from_failures = [f for f in failures if (f.get('origin') or 'from') == 'from']
from_issues = [i for i in debug_issues if (i.get('origin') or 'from') == 'from']
print('VERSION=' + release)
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
	if [ -n "$RAN_AT" ]; then
		echo "    smoke report dated $RAN_AT - OK"
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
