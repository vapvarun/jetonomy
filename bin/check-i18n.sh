#!/usr/bin/env bash
# Jetonomy — JS i18n gate (WS4-C, 1.4.3).
#
# Greps assets/js/ for user-facing English string literals that are NOT
# being read from one of the localized i18n objects. Catches the kind of
# bug Basecamp #9876720271 + #9876871333 surfaced: a contributor adds a
# `button.textContent = 'Cancel';` line and ships English to translators.
#
# Allowed patterns:
#   • foo.textContent = ( state.i18n?.cancel || 'Cancel' );
#   • foo.textContent = data.i18n.cancel || 'Cancel';
#   • foo.textContent = jtI18n( 'cancel', 'Cancel' );
#   • foo.textContent = pi( 'cancel', 'Cancel' );
#   • foo.textContent = D.i18n.cancel || 'Cancel';
#
# Disallowed:
#   • foo.textContent = 'Cancel';
#   • alert( 'Failed!' );
#   • prompt( 'Enter URL:', ... );
#
# Exits 1 if any literal is found.

set -euo pipefail

cd "$(dirname "$0")/.."

# Patterns that indicate a literal user-facing string (capitalised English
# phrase) being assigned/passed without a sibling i18n key on the same
# expression. Matches assignments to common UI-bearing properties.
SUSPECT_PROPS='textContent|innerText|innerHTML|placeholder|title'

FAILED=0

while IFS= read -r line; do
	# Skip lines that already mention an i18n lookup or jtI18n()/pi() helper
	# on the SAME line — those are localized with a defensive fallback.
	if echo "$line" | grep -Eq '(\.i18n[?]?\.[a-zA-Z]|jtI18n\(|pi\(|state\.i18n|D\.i18n|jetonomyData)'; then
		continue
	fi
	# Skip lines that include a translator comment marker or are inside
	# data-* attribute strings.
	if echo "$line" | grep -Eq '(data-jt-confirm|translators:|aria-label.*\$\{)'; then
		continue
	fi
	echo "$line"
	FAILED=1
done < <(grep -rEn \
	"\.($SUSPECT_PROPS)\s*=\s*['\"][A-Z][a-zA-Z][^'\"]{1,60}['\"]" \
	assets/js/ \
	--include="*.js" \
	2>/dev/null \
	| grep -v '\.min\.js' \
	|| true)

# Also flag literal string args to alert/confirm/prompt — those are blocking
# (the modal toolkit replaces them) and must be i18n.
while IFS= read -r line; do
	if echo "$line" | grep -Eq '(\.i18n[?]?\.[a-zA-Z]|jtI18n\(|pi\(|state\.i18n|D\.i18n)'; then
		continue
	fi
	if echo "$line" | grep -Eq '(window\.jetonomyConfirm|window\.jetonomyAlert|window\.jetonomyPrompt|jetonomyConfirm|jetonomyAlert|jetonomyPrompt)'; then
		# These are our modal wrappers — body strings are checked separately
		# below via the broader regex. Skip the wrapper call itself.
		:
	fi
	if [[ "$line" =~ "jetonomyPrompt"|"jetonomyConfirm"|"jetonomyAlert" ]]; then
		continue
	fi
	echo "$line"
	FAILED=1
done < <(grep -rEn \
	"\b(alert|confirm|prompt)\s*\(\s*['\"][A-Z][a-zA-Z][^'\"]{1,60}['\"]" \
	assets/js/ \
	--include="*.js" \
	2>/dev/null \
	| grep -v '\.min\.js' \
	|| true)

# Classic scripts (no ES `import`) translate with wp.i18n, so an English
# fallback after a localized lookup (`i18n.key || 'Saved!'`) or a
# key-plus-fallback helper means a string is not in the JS translation files.
# Script modules (view.js and friends) cannot use wp.i18n and keep fallbacks.
while IFS= read -r file; do
	while IFS= read -r line; do
		echo "$file:$line"
		FAILED=1
	done < <(grep -nE "\|\|\s*['\"][A-Z][a-z]+[ a-z.!?,…]|\b(jtI18n|lbI18n)\(" "$file" | grep -v '^\s*[0-9]*:\s*\(//\|\*\)' || true)
done < <(grep -rL '^import ' assets/js --include='*.js' | grep -v '\.min\.js' || true)

if [[ "$FAILED" -ne 0 ]]; then
	echo ""
	echo "FAILED: literal user-facing string(s) found in assets/js/."
	echo "Classic scripts: wp.i18n.__() + script_translations(). Modules: state.i18n / jetonomyData.i18n."
	echo "See docs/architecture/I18N.md."
	exit 1
fi

echo "OK: no hardcoded user-facing strings in assets/js/."
