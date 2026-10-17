#!/usr/bin/env bash
# bin/test-check-journey-depth.sh - prove bin/check-journey-depth.php can fail.
#
# A gate that cannot fail proves nothing. This builds a throwaway runbook,
# breaks each rule once, and expects the checker to refuse it; then a complete
# one, which it must accept. Run it after editing the checker.

set -uo pipefail
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/check-journey-depth.php"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/docs/qa/walks" "$T/audit"
cp "$SRC" "$T/bin/"
echo '{"personas":{"member":{},"member_b":{}}}' > "$T/docs/qa/qa-config.json"
echo '{"escapes":[{"card":"111"}]}' > "$T/audit/escapes.json"

fails=0
expect() { # expect <0|1> <label>
	php "$T/bin/check-journey-depth.php" "${@:3}" > "$T/out" 2>&1
	local got=$?
	if { [ "$1" = 0 ] && [ $got -eq 0 ]; } || { [ "$1" = 1 ] && [ $got -ne 0 ]; }; then
		echo "ok    $2"
	else
		echo "FAIL  $2 (exit $got)"; sed 's/^/      /' "$T/out"; fails=$((fails + 1))
	fi
}
runbook() { printf '%s\n' "$@" > "$T/docs/qa/AGENT_SMOKE_RUNBOOK.md"; }
good=( '### C.member.vote' '**Covers:** x.php' '**Checks:**'
	'- `c-member-vote.1` @member #ui: Upvote a topic -> score goes up by one.'
	'- `c-member-vote.2` @member_b #rest !refuse card:111: Vote on a hidden topic -> 404 jetonomy_not_found.' )

runbook '### C.member.vote' '**Covers:** x.php'
expect 1 'journey with no checks'
runbook "${good[@]:0:4}"
echo '{"escapes":[]}' > "$T/audit/escapes.json"
expect 1 'C journey with no refusal and one role'
echo '{"escapes":[{"card":"111"}]}' > "$T/audit/escapes.json"
runbook "${good[@]:0:3}" '- `c-member-vote.1` @nobody #ui: x -> y.' "${good[4]}"
expect 1 'unknown role'
runbook "${good[@]:0:3}" '- `c-member-vote.1` @member #web: x -> y.' "${good[4]}"
expect 1 'unknown surface'
runbook "${good[@]:0:3}" "${good[3]}" "${good[3]}" "${good[4]}"
expect 1 'duplicate check id'
runbook "${good[@]:0:4}" '- `c-member-vote.2` @member_b #rest !refuse: x -> y.'
expect 1 'escape card with no check'
runbook "${good[@]}"
expect 0 'complete checklist, not stamped'

runbook "${good[@]:0:2}" '**Verified:** abc1234' "${good[@]:2}"
expect 1 'stamp with no walk record'
echo '{"free":"abc1234","checks":{"c-member-vote.1":{"result":"pass"},"c-member-vote.2":{"result":"untested"}}}' > "$T/docs/qa/walks/C.member.vote.json"
expect 1 'stamp with an untested check'
echo '{"free":"def5678","checks":{"c-member-vote.1":{"result":"pass"},"c-member-vote.2":{"result":"pass"}}}' > "$T/docs/qa/walks/C.member.vote.json"
expect 1 'walk record at other commits'
echo '{"free":"abc1234","checks":{"c-member-vote.1":{"result":"pass"},"c-member-vote.2":{"result":"pass"}},"proposed_checks":["- `c-member-vote.3` ..."]}' > "$T/docs/qa/walks/C.member.vote.json"
expect 1 'walk proposed a check not yet in the runbook'
echo '{"free":"abc1234","checks":{"c-member-vote.1":{"result":"pass"},"c-member-vote.2":{"result":"pass"}}}' > "$T/docs/qa/walks/C.member.vote.json"
expect 0 'stamp backed by a complete walk'
expect 0 '--ready at the walked commit' --ready C.member.vote abc1234
expect 1 '--ready at a newer commit' --ready C.member.vote 9999999

[ $fails -eq 0 ] && echo "check-journey-depth: every rule fails when broken and passes when met" || exit 1
