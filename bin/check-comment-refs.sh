#!/usr/bin/env bash
# Fails when source carries tracker, plan or work-stream references.
# They belong in commit messages, where git blame finds them
# (docs/standards/developer-quality.md, rule 1).
#
# Usage: bin/check-comment-refs.sh [file-or-dir ...]
#   default: includes templates assets/js assets/css (minified files skipped)
set -u
cd "$(dirname "$0")/.." || exit 2
# Word/ticket patterns: matched anywhere on a line (a bare "Basecamp" is never legitimate in source).
PATTERN='[Bb][Aa][Ss][Ee][Cc][Aa][Mm][Pp]|Zoho[[:space:]]*#|(^|[^A-Za-z0-9])BC[[:space:]]*#?[0-9]+|(^|[^A-Za-z0-9])WP[0-9]+\.[0-9]+([^0-9]|$)|(^|[^A-Za-z])Task [0-9]+|plan [A-Z]+[0-9]|(^|[^A-Za-z0-9])WS[0-9]+-[A-Z]([^A-Za-z]|$)|graveyard #[0-9]|Zoho [0-9]+'
# Card ids (9-11 digits) only count inside a comment, so numeric test fixtures stay legal.
CARD='(//|/\*|^[[:space:]]*\*|^[[:space:]]*#)[^0-9]*([^0-9]|^)[0-9]{9,11}([^0-9]|$)'
if [ $# -gt 0 ]; then TARGETS=("$@"); else TARGETS=(includes templates assets/js assets/css); fi
hits=$(grep -rnE -e "$PATTERN" -e "$CARD" "${TARGETS[@]}" --include='*.php' --include='*.js' --include='*.css' 2>/dev/null | grep -vE '\.min\.(js|css):' || true)
if [ -n "$hits" ]; then
	echo "Tracker/plan references in source (move them to the commit message):"
	echo "$hits"
	exit 1
fi
exit 0
