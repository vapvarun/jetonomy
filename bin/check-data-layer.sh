#!/usr/bin/env bash
# Fails when $wpdb use outside the data layer grows in any file
# (docs/standards/developer-quality.md, rule 2). The baseline only shrinks:
# after moving queries into models, run with --update and commit the file.
#
# Usage: bin/check-data-layer.sh [--update]
set -u
cd "$(dirname "$0")/.." || exit 2
BASELINE=audit/wpdb-baseline.txt
# One-time and repair code (imports, the demo seeder, GDPR export/erase, backfills,
# recount, the purge service) runs once per site, so it may keep its own queries.
# includes/search/ is the full-text search backend: building those queries is its job.
ALLOWED='^(includes/models/|includes/search/|includes/db/|includes/cli/|includes/qa/|includes/class-cli\.php|includes/import/|includes/admin/ajax/class-demo-seeder\.php|includes/class-privacy(-backfill)?\.php|includes/class-[a-z-]+-backfill\.php|includes/class-recount\.php|includes/class-space-purge\.php)'

current=$(grep -rnF '$wpdb' includes templates --include='*.php' 2>/dev/null \
	| grep -vE '^[^:]+:[0-9]+:[[:space:]]*(//|\*|/\*|#)' \
	| grep -vE '^[^:]+:[0-9]+:[[:space:]]*global \$wpdb;' \
	| cut -d: -f1 | grep -vE "$ALLOWED" | sort | uniq -c | awk '{print $2" "$1}')

if [ "${1:-}" = "--update" ]; then
	printf '%s\n' "$current" | sed '/^$/d' > "$BASELINE"
	echo "Baseline written: $(awk '{s+=$2} END {print s+0}' "$BASELINE") uses in $(wc -l < "$BASELINE" | tr -d ' ') files."
	exit 0
fi

fail=0
while read -r file count; do
	[ -z "$file" ] && continue
	allowed=$(awk -v f="$file" '$1==f {print $2}' "$BASELINE" 2>/dev/null)
	if [ "${count}" -gt "${allowed:-0}" ]; then
		echo "$file: $count \$wpdb uses (baseline ${allowed:-0}). Move the query into a model."
		fail=1
	fi
done <<< "$current"

# Member-facing templates read categories through the viewer's visibility rule.
# Category::find() returns hidden categories too, and four templates printed a
# hidden category's name in their breadcrumb (2.0.3 audit). find_visible() only.
leaks=$(grep -rn 'Category::find(' templates --include='*.php' 2>/dev/null)
if [ -n "$leaks" ]; then
	echo "$leaks"
	echo "Templates must use Category::find_visible(), not Category::find()."
	fail=1
fi
exit $fail
