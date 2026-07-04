# [Fixed 1.6.0] Search advanced filters leaked posts from private/hidden spaces

**Severity:** Critical (privacy) - **Status:** Fixed, ready for QA
**Area:** Free - Search
**File:** `templates/views/search.php` (advanced-filter branch)

## What was wrong
The plain search path gated results by space visibility, but the **advanced-filter branch** (triggered by any date range, author, tag, or a sort other than Relevance) skipped `Space::content_visibility_sql`. So a guest adding any filter could see posts from private/hidden spaces they can't access.

## What changed
The advanced-filter query now JOINs the spaces table and applies the same `Space::content_visibility_sql` gate as the non-filtered path (and keeps the per-post `is_private` guard).

## QA test steps
1. Put a findable post (e.g. title contains `secretwidget`) inside a **private** space.
2. **As a guest / non-member**, go to `/community/search/`, search `secretwidget`, then:
   - Set a **date range**, OR pick an **author**, OR a **tag**, OR switch **sort to Newest/Most Voted**.
   - The private-space post must **NOT** appear in any filtered variant.
3. Plain search (no filters) for the same term -> also absent (was already correct).
4. **As a member** of that private space -> the post DOES appear (filtered and unfiltered).

**Pass =** no filter combination surfaces private/hidden-space posts to users who can't read them.
