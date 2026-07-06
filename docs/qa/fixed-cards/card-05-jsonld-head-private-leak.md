# [Fixed 1.6.0] Private space/post titles exposed in page-head JSON-LD (SEO schema)

**Severity:** Major (privacy) - **Status:** Fixed, ready for QA
**Area:** Free - SEO / structured data
**File:** `includes/seo/class-schema-markup.php`

## What was wrong
Page-head JSON-LD is crawler-facing (Googlebot = anonymous guest). Two paths leaked gated content:
- `CollectionPage` schema on a **private/hidden space** URL emitted its title, description, and top-10 post titles+URLs.
- `BreadcrumbList` emitted a **private post's** title even though the HTML view 403s.

## What changed
- Space schema now emits **nothing** for non-public spaces, and uses the visibility-aware post list (no private ideas) for public ones.
- Breadcrumb crumbs are gated per-viewer (`can(read)` for the space, `can_read_post` for the post) - matching the already-correct post schema.

## QA test steps
1. **View page source** (or DevTools) as a **logged-out guest**:
   - On a **private/hidden space** URL -> NO `application/ld+json` `CollectionPage` block containing the space title/description/post list; NO `BreadcrumbList` exposing the space title.
   - On a **private topic** URL -> NO `BreadcrumbList`/schema exposing the post title.
2. On a **public space / public post** URL -> schema still present (unchanged).
3. Member/admin viewing the private space -> breadcrumb may include it (they can read it); crawler/guest never does.

**Pass =** no private/hidden space or post title appears in any head JSON-LD for guests; public schema intact.
