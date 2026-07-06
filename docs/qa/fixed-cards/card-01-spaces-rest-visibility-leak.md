# [Fixed 1.6.0] Private/hidden spaces leaked via GET /spaces?visibility=hidden

**Severity:** Critical (privacy) - **Status:** Fixed, ready for QA
**Area:** Free - Spaces / REST
**File:** `includes/models/class-space.php` (`Space::list_visible`)

## What was wrong
`GET /jetonomy/v1/spaces?visibility=hidden` (or `=private`) returned ALL hidden/private spaces to anyone, including logged-out guests. The explicit `visibility` param skipped the viewer-visibility gate entirely.

## What changed
The `visibility` filter now only NARROWS within what the viewer may already see. The viewer gate (guest -> public only; member -> public + own spaces; admin -> all) is ALWAYS applied.

## QA test steps
1. As a site owner, create a **hidden** space `H` and a **private** space `P`. Note their slugs.
2. **Logged out (guest):**
   - `GET /wp-json/jetonomy/v1/spaces?visibility=hidden` -> must return **no** hidden spaces (empty or only public).
   - `GET /wp-json/jetonomy/v1/spaces?visibility=private` -> must return **no** private spaces you can't see.
3. **Logged-in non-member** of `P`/`H`: same two calls -> still must NOT expose `P` or `H`.
4. **Member of `P`:** `?visibility=private` -> returns `P` (a space you belong to), not other private spaces you don't.
5. **Admin:** `?visibility=hidden` -> returns all hidden spaces (admin bypass intact).

**Pass =** guests/non-members never see hidden/private spaces regardless of the `visibility` param; members see only their own; admins see all.
