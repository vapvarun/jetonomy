# [Fixed 1.6.0] Space members roster shown to non-members on private/hidden spaces

**Severity:** Critical (privacy) - **Status:** Fixed, ready for QA
**Area:** Free - Spaces / frontend template
**File:** `templates/views/space-members.php`

## What was wrong
`/community/s/<slug>/members/` rendered the full member roster (names, profile links, join dates, reputation, admin/mod badges) for ANY visitor - including logged-out guests - on private and hidden spaces.

## What changed
The members view now gates on `Permission_Engine::can( uid, 'read', space_id )` before any roster query - the same rule the REST members endpoint and main space view use.

## QA test steps
1. Create a **private** space, add member `A`. Keep a non-member `B` and a guest.
2. Visit `/community/s/<slug>/members/`:
   - **Guest** -> "You need to be a member of this space to see its members." (403), NO roster.
   - **Non-member `B`** -> same block, NO roster.
   - **Member `A`** -> roster renders normally.
   - **Admin** -> roster renders.
3. Repeat with a **hidden** space -> same as above.
4. Public space `/members/` -> roster still visible to everyone (unchanged).

**Pass =** roster is members/admin-only on private+hidden spaces; unchanged on public.
