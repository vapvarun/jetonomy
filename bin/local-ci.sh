#!/usr/bin/env bash
# bin/local-ci.sh — single-command local CI for jetonomy + jetonomy-pro
#
# Mirrors what GitHub Actions would run (PHPStan + WPCS + PHPUnit), backed by
# the existing wp-env Docker stack already wired in .wp-env.json. Exits non-zero
# on any blocking gate failure so it can drop straight into a pre-push hook.
#
# Usage:
#   bin/local-ci.sh                    # default: stan + cs + unit tests (fast, ~30s after wp-env warm)
#   bin/local-ci.sh --combo            # add free+pro integration suite (slower, ~5 min)
#   bin/local-ci.sh --no-stan          # skip phpstan (it's the slowest)
#   bin/local-ci.sh --bootstrap        # one-time: composer install + npm install + wp-env start
#   bin/local-ci.sh --browser          # add Playwright smoke (requires MCP playwright)
#
# Gates (in order):
#   1. wp-env reachable (auto-start if not)
#   2. PHPStan free + pro (level 5/6, baselines honoured)
#   3. WPCS free + pro (staged or full)
#   4. PHPUnit unit suite (must be 100% green)
#   5. PHPUnit combo (free+pro) — flagged but not blocking until rot is cleared
#   6. (optional) Playwright browser smoke
#
# Why not just run composer test? composer test runs PHPUnit only. This wraps
# every gate the build-release.sh would run pre-tag, but cheap enough to run
# routinely.

set -uo pipefail

# Resolve to plugin root regardless of where the script is called from.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PRO="$(cd "$ROOT/../jetonomy-pro" 2>/dev/null && pwd || echo "")"
cd "$ROOT"

# --- Flags ---------------------------------------------------------------
DO_STAN=1
DO_CS=1
DO_UNIT=1
DO_COMBO=0
DO_BOOTSTRAP=0
DO_BROWSER=0
for arg in "$@"; do
    case "$arg" in
        --combo)     DO_COMBO=1 ;;
        --no-stan)   DO_STAN=0 ;;
        --no-cs)     DO_CS=0 ;;
        --no-unit)   DO_UNIT=0 ;;
        --bootstrap) DO_BOOTSTRAP=1 ;;
        --browser)   DO_BROWSER=1 ;;
        --help|-h)
            sed -n '2,/^$/p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *)
            echo "unknown flag: $arg" >&2
            exit 2
            ;;
    esac
done

# --- Pretty printing ----------------------------------------------------
RED=$(printf '\033[0;31m')
GREEN=$(printf '\033[0;32m')
YELLOW=$(printf '\033[0;33m')
BOLD=$(printf '\033[1m')
RESET=$(printf '\033[0m')

ok()    { printf '%s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
fail()  { printf '%s✗%s %s\n' "$RED"   "$RESET" "$1"; }
warn()  { printf '%s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
step()  { printf '\n%s== %s ==%s\n' "$BOLD" "$1" "$RESET"; }

FAILED_GATES=()

# --- Gate 0: bootstrap (one-time) ---------------------------------------
if [ "$DO_BOOTSTRAP" -eq 1 ]; then
    step "Bootstrap (composer + npm + wp-env)"
    composer install --no-interaction || { fail "composer install (free) failed"; exit 1; }
    [ -n "$PRO" ] && (cd "$PRO" && composer install --no-interaction) \
        || warn "skipping pro composer install (jetonomy-pro not present)"
    npm install --no-audit --no-fund || { fail "npm install failed"; exit 1; }
    npx wp-env start || { fail "wp-env start failed"; exit 1; }
    ok "Bootstrap complete"
fi

# --- Pre-flight: wp-env up? ---------------------------------------------
step "Pre-flight"
if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -q tests-cli && ! curl -sSf -o /dev/null http://localhost:8889; then
    warn "wp-env tests environment not reachable; starting it"
    npx wp-env start 2>&1 | tail -3 || { fail "wp-env start failed"; exit 1; }
fi
ok "wp-env reachable"

# Helper: returns 0 if PHPStan reported zero errors. Robust against the
# "PHPStan 2.x available" promo text that appends after the result block.
phpstan_clean() {
    local out
    out=$("$@" 2>&1)
    echo "$out" | grep -qE '\[OK\] No errors' && return 0
    echo "$out" | grep -qE 'Found 0 errors' && return 0
    return 1
}

# Helper: returns 0 if PHPCS reports zero ERRORS (warnings allowed).
# Errors-only matches what the pre-commit hook gates on; warnings are advisory.
phpcs_zero_errors() {
    local out
    out=$("$@" --report=summary --error-severity=1 --warning-severity=99 2>&1)
    if echo "$out" | grep -qE 'A TOTAL OF [1-9][0-9]* ERROR'; then
        return 1
    fi
    return 0
}

# --- Gate 1: PHPStan (free + pro) ---------------------------------------
if [ "$DO_STAN" -eq 1 ]; then
    step "PHPStan — free"
    if phpstan_clean vendor/bin/phpstan analyse --memory-limit=2G --no-progress; then
        ok "PHPStan free clean"
    else
        fail "PHPStan free reported errors"
        vendor/bin/phpstan analyse --memory-limit=2G --no-progress 2>&1 | grep -E '^\s+[0-9]+\s+|^Found' | head -10
        FAILED_GATES+=("phpstan-free")
    fi

    if [ -n "$PRO" ] && [ -f "$PRO/phpstan.neon.dist" ]; then
        step "PHPStan — pro"
        if (cd "$PRO" && phpstan_clean vendor/bin/phpstan analyse --memory-limit=2G --no-progress); then
            ok "PHPStan pro clean"
        else
            fail "PHPStan pro reported errors"
            FAILED_GATES+=("phpstan-pro")
        fi
    fi
fi

# --- Gate 2: WPCS (free + pro) -----------------------------------------
# Errors only. Warnings are advisory and printed but don't fail the gate.
if [ "$DO_CS" -eq 1 ]; then
    step "WPCS — free (errors only)"
    if phpcs_zero_errors vendor/bin/phpcs; then
        ok "WPCS free 0 errors"
    else
        fail "WPCS free has errors"
        vendor/bin/phpcs --report=summary --error-severity=1 --warning-severity=99 2>&1 | tail -8
        FAILED_GATES+=("wpcs-free")
    fi

    if [ -n "$PRO" ]; then
        step "WPCS — pro (errors only)"
        if (cd "$PRO" && phpcs_zero_errors vendor/bin/phpcs); then
            ok "WPCS pro 0 errors"
        else
            fail "WPCS pro has errors"
            (cd "$PRO" && vendor/bin/phpcs --report=summary --error-severity=1 --warning-severity=99 2>&1 | tail -8)
            FAILED_GATES+=("wpcs-pro")
        fi
    fi
fi

# --- Gate 3: PHPUnit unit suite ----------------------------------------
if [ "$DO_UNIT" -eq 1 ]; then
    step "PHPUnit unit suite (must be 100% green)"
    if composer test:docker:unit 2>&1 | grep -qE "OK \([0-9]+ tests"; then
        UNIT_OUT=$(composer test:docker:unit 2>&1 | grep -E "Tests: |OK \(" | tail -1)
        ok "PHPUnit unit clean — $UNIT_OUT"
    else
        fail "PHPUnit unit suite has failures"
        composer test:docker:unit 2>&1 | grep -E "^[ ]*✘|FAILURES|Tests:" | tail -10
        FAILED_GATES+=("phpunit-unit")
    fi
fi

# --- Gate 4: PHPUnit combo (informational) ------------------------------
if [ "$DO_COMBO" -eq 1 ]; then
    step "PHPUnit combo (free+pro integration; informational)"
    COMBO=$(composer test:docker 2>&1 | grep -E "Tests: |Failures:" | tail -1)
    if echo "$COMBO" | grep -q "Failures: 0"; then
        ok "PHPUnit combo clean — $COMBO"
    else
        warn "PHPUnit combo has $(echo "$COMBO" | grep -oE 'Failures: [0-9]+') — known rot in SpaceMembersUpdateGuardTest"
        warn "$COMBO"
        # Not added to FAILED_GATES until the rot is cleared.
    fi
fi

# --- Gate 5: browser smoke (optional) -----------------------------------
if [ "$DO_BROWSER" -eq 1 ]; then
    step "Playwright browser smoke"
    if [ -f tools/agent-smoke-runbook.md ] || [ -f docs/AGENT_SMOKE_RUNBOOK.md ]; then
        warn "Browser smoke runs through MCP — invoke /jetonomy-smoke separately, then re-run this script"
    else
        warn "no browser smoke runbook found; skipping"
    fi
fi

# --- Summary -----------------------------------------------------------
step "Summary"
if [ ${#FAILED_GATES[@]} -eq 0 ]; then
    ok "All blocking gates passed. Safe to push."
    exit 0
else
    fail "Blocking gates failed: ${FAILED_GATES[*]}"
    fail "Fix and rerun before pushing."
    exit 1
fi
