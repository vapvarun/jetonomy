#!/usr/bin/env bash
# bin/self-qa.sh - what must be walked in the browser before this code can ship.
#
# One command for the developer and for QA, Free + Pro together:
#
#   bin/self-qa.sh                      # coverage + the journeys to walk now
#   bin/self-qa.sh --list               # also every shipped file no journey walks
#   bin/self-qa.sh --stamp C.member.vote E.polls
#                                       # after those journeys PASSED in the browser
#                                       # at the current code: record the commits
#
# Walk each listed journey from docs/qa/AGENT_SMOKE_RUNBOOK.md (or run
# /wp-plugin-smoke on it) as every role it names, at 1440 and 390. Stamp only
# what passed. bin/build-release.sh (Free and Pro) refuses to build while
# anything here is outstanding, so this is the whole pre-release self-QA list.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PRO="$(cd "$ROOT/../jetonomy-pro" 2>/dev/null && pwd || echo "")"
RUNBOOK="$ROOT/docs/qa/AGENT_SMOKE_RUNBOOK.md"
cd "$ROOT"

if [ "${1:-}" = "--stamp" ]; then
	shift
	[ $# -gt 0 ] || { echo "usage: bin/self-qa.sh --stamp <journey-id>..."; exit 2; }
	for repo in "$ROOT" ${PRO:+"$PRO"}; do
		# A stamp records committed code; a walk of uncommitted edits proves nothing that ships.
		if [ -n "$(git -C "$repo" status --porcelain -- includes templates assets ':(glob)*.php')" ]; then
			echo "refusing to stamp: uncommitted code in $(basename "$repo"). Commit, re-walk, then stamp."
			exit 1
		fi
	done
	FREE_SHA="$(git rev-parse --short HEAD)"
	PRO_SHA="${PRO:+$(git -C "$PRO" rev-parse --short HEAD)}"
	for id in "$@"; do
		grep -qE "^### ${id//./\\.}( |$)" "$RUNBOOK" || { echo "no journey '### $id' in the runbook"; exit 1; }
		ID="$id" FREE="$FREE_SHA" PROSHA="$PRO_SHA" perl -0pi -e '
			my ($id, $f, $p) = ($ENV{ID}, $ENV{FREE}, $ENV{PROSHA});
			s{(^\#\#\# \Q$id\E(?: [^\n]*)?\n(?:(?!^\#\#\# ).*\n)*?)\*\*Verified:\*\*[^\n]*}{
				my $head = $1;
				my $pro  = ( $p ne "" && $head =~ /^\*\*Covers:\*\*.*\bpro:/m ) ? " pro:$p" : "";
				"$head**Verified:** $f$pro"
			}me;' "$RUNBOOK"
		echo "stamped $id at $FREE_SHA${PRO_SHA:+ (pro $PRO_SHA)}"
	done
	echo "Commit docs/qa/AGENT_SMOKE_RUNBOOK.md with the walk evidence on the card."
	exit 0
fi

status=0
php bin/check-journey-coverage.php ${1:+"$1"} || status=1
echo
if ! php bin/check-runbook-fresh.php; then
	status=1
	echo
	echo "Walk the journeys above, then: bin/self-qa.sh --stamp <id>..."
fi
[ $status -eq 0 ] && echo "Self-QA clear: every shipped file has a journey and every journey passed at the current code."
exit $status
