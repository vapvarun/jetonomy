# [Fixed 1.6.0] Space roadmap ideas visible to non-members (+ private ideas on public spaces)

**Severity:** Critical (privacy) - **Status:** Fixed, ready for QA
**Area:** Free - Spaces / roadmap template
**File:** `templates/views/space-roadmap.php`

## What was wrong
`/community/s/<slug>/roadmap/` ran a raw query with no space-visibility gate and no `is_private` filter - so guests could read idea titles + content excerpts + vote/reply counts of **private/hidden** spaces, and private ideas (`is_private=1`) showed even on public spaces.

## What changed
Added the same `Permission_Engine::can( uid, 'read', space_id )` gate as the space view, PLUS the `is_private` predicate (`Post::list_by_space_visible` semantics): non-privileged viewers only see public ideas or their own.

## QA test steps
1. **Private ideas space:** as guest / non-member visit `/community/s/<slug>/roadmap/` -> 403 block, no ideas. Member/admin -> roadmap shows.
2. **Public ideas space with a private idea** (`is_private=1`):
   - Guest / ordinary member -> the private idea must NOT appear on the board.
   - The idea's **author**, a space **moderator/admin** -> the private idea IS visible.
3. Public ideas without private flag -> visible to all (unchanged).

**Pass =** roadmap gated on private/hidden spaces; private ideas hidden from non-privileged viewers everywhere.
