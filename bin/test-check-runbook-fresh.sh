#!/usr/bin/env bash
# bin/test-check-runbook-fresh.sh - prove bin/check-runbook-fresh.php can fail.
#
# Builds a throwaway repo with one journey covering one file, walked at the
# first commit. A comment-only or whitespace-only edit must keep it fresh; a
# code edit, a CSS edit and a deleted file must make it stale. Run it after
# editing the checker.

set -uo pipefail
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/check-runbook-fresh.php"
T="$(mktemp -d)"
trap 'rm -rf "$T" "$T.out"' EXIT
mkdir -p "$T/bin" "$T/docs/qa"
cp "$SRC" "$(dirname "$SRC")/release-exception.php" "$T/bin/"
printf '<?php\n/**\n * Version: 2.0.3\n */\n' > "$T/jetonomy.php"
cd "$T" || exit 1
git init -q && git config user.email t@t && git config user.name t
printf '<?php\n/**\n * Doc.\n */\nfunction a() {\n\treturn 1;\n}\n' > a.php
printf '.x { color: red; }\n' > a.css
git add -A && git commit -qm base
BASE="$(git rev-parse --short HEAD)"
# The runbook stays untracked so reset --hard never touches it.
echo 'docs/' > .git/info/exclude
printf '### J1\n**Covers:** a.php, a.css\n**Verified:** %s\n' "$BASE" > docs/qa/AGENT_SMOKE_RUNBOOK.md

fails=0
expect() { # expect <0|1> <label>
	php bin/check-runbook-fresh.php > "$T.out" 2>&1
	local got=$?
	if { [ "$1" = 0 ] && [ $got -eq 0 ]; } || { [ "$1" = 1 ] && [ $got -ne 0 ]; }; then
		echo "ok    $2"
	else
		echo "FAIL  $2 (exit $got)"; sed 's/^/      /' "$T.out"; fails=$((fails + 1))
	fi
}
step() { git add -A && git commit -qm "$1"; }
reset() { git reset -q --hard "$BASE"; }

expect 0 "untouched files are fresh"

printf '<?php\n/**\n * Better doc.\n * Second line.\n */\n// note\nfunction a() {\n\treturn 1;\n}\n' > a.php; step comments
expect 0 "comment-only PHP edit stays fresh"
reset

printf '<?php\n/**\n * Doc.\n */\nfunction a()   {\n\t\treturn 1;\n}\n\n' > a.php; step ws
expect 0 "whitespace-only edit stays fresh"
reset

printf '<?php\n/**\n * Doc.\n */\nfunction a() {\n\treturn 2;\n}\n' > a.php; step code
expect 1 "code edit goes stale"
reset

printf '<?php\n/**\n * Doc.\n */\nfunction a() {\n\treturn 1; // why\n}\n' > a.php; step trailing
expect 1 "code line with a trailing comment goes stale"
reset

printf '/* note */\n.x { color: red; }\n' > a.css; step css
expect 1 "CSS edit goes stale (comments only skipped in PHP and JS)"
reset

git rm -q a.php; step delete
expect 1 "deleted covered file goes stale"

[ $fails -eq 0 ] && echo "runbook-fresh self-test: all cases behave" || { echo "runbook-fresh self-test: $fails case(s) wrong"; exit 1; }
