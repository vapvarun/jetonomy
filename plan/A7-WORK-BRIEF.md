# A7 — Revisions admin page (per-post diff browser)

**Branch:** `1.4.1`
**Plugin:** jetonomy (free)
**Risk:** low — read-only browser over an existing populated table
**Estimated time:** 2 days
**Reference:** `plan/1.4.1-plan.md` row A7, `plan/1.4.1-safety-checks.md` § A7

## Problem

`{prefix}jt_revisions` already exists (schema row 20 — `class-schema.php:382`) and is populated whenever a post or reply is edited. The model `Jetonomy\Models\Revision` has `list_for_object( $type, $id )`. **There is no admin UI to browse revisions of a given post**, so admins/moderators cannot audit content edits or restore an earlier copy without raw SQL.

## Schema (already shipped)

```sql
CREATE TABLE wp_jt_revisions (
    id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    object_type   ENUM('post','reply') NOT NULL DEFAULT 'post',
    object_id     bigint(20) unsigned NOT NULL DEFAULT 0,
    author_id     bigint(20) unsigned NOT NULL DEFAULT 0,
    content       longtext,
    title         varchar(255) DEFAULT NULL,
    edit_summary  varchar(255) DEFAULT NULL,
    created_at    datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY object_created (object_type,object_id,created_at)
);
```

Each row is a **full snapshot** (not a delta), so the diff is computed at render time by comparing two adjacent rows.

## Implementation

### Files

```
includes/admin/views/revisions.php             ← new view
includes/admin/class-revisions-list-table.php  ← new (extends WP_List_Table)
includes/admin/class-admin.php                 ← additive only — register submenu + render method
```

**Coordination note:** A6 just landed `// ── A6: Activity Log ──` markers in `class-admin.php`. A7 must:
- Add a `// ── A7: Revisions ──` marker block for its submenu registration + render method
- NOT modify A6's block
- Slot the submenu **immediately after** Activity Log (so order becomes: Content · Moderation · Activity Log · **Revisions** · Users)

A8 (Email Templates) will land after A7 with its own marker; do not touch A8 territory.

### Submenu registration in `class-admin.php`

```php
// ── A7: Revisions ──
add_submenu_page(
    'jetonomy',
    __( 'Revisions', 'jetonomy' ),
    __( 'Revisions', 'jetonomy' ),
    'jetonomy_manage_settings',
    'jetonomy-revisions',
    [ self::class, 'render_revisions_page' ]
);
```

Cap is `jetonomy_manage_settings` (matches A6 + every other admin page). No new capability.

### Page features

Two view modes, controlled by URL:

1. **List mode** — `?page=jetonomy-revisions` (default)
   - WP_List_Table listing of distinct `(object_type, object_id)` pairs that have ≥1 revision
   - Columns: Object Type · Object Title (linked to detail mode) · Revision Count · Last Edited · Last Edited By
   - Filters: object_type (post/reply dropdown), date range
   - Default 20 per page (`screen_options`)

2. **Detail mode** — `?page=jetonomy-revisions&object_type=post&object_id=123`
   - Shows the chronological list of revisions for that one object (newest first), via `Revision::list_for_object()`
   - Each row: timestamp · author · edit_summary · "View diff" button
   - "View diff" expands to a left/right pane comparing this revision against the previous one
   - Diff is HTML (use WP core `wp_text_diff()` — already loaded for `wp-admin/revision.php`; we just `require_once ABSPATH . 'wp-admin/includes/revision.php';` if needed). If `wp_text_diff()` is not desired, fall back to a simple line-by-line `diff_words` via `WP_Text_Diff_Renderer_inline` from `wp-includes/wp-diff.php`.

### Privacy / access

- Only `jetonomy_manage_settings` users see the page at all (covered by cap on the menu).
- **Per safety check § A7 line 146:** non-mod cannot view revisions for posts they didn't author. Since the cap gate already keeps non-mods out of the admin page entirely, this is satisfied — but ALSO add an explicit `current_user_can( 'jetonomy_manage_settings' )` guard at the top of `render_revisions_page()` so a direct URL hit gets `wp_die( __( 'You do not have sufficient permissions...' ) )` rather than a blank page.

### Read-only — no edit / restore in v1

- No restore button
- No delete button
- No bulk actions
- This is a **browser**, not an editor. Restore is a separate UX call (1.5.0+).

### List query

Use `$wpdb->get_results()` once via the model layer if a static helper exists; otherwise add a thin helper to `Revision::list_objects_with_revisions( array $filters = [], int $limit, int $offset )` that returns:

```php
SELECT object_type, object_id, COUNT(*) AS revision_count, MAX(created_at) AS last_edited
FROM wp_jt_revisions
GROUP BY object_type, object_id
ORDER BY last_edited DESC
LIMIT %d OFFSET %d
```

Resolve `object_title` per row by joining or by a per-row lookup against `Post::get($id)` / `Reply::get($id)`. **No N+1** — if a per-row lookup is unavoidable, batch the IDs and `WHERE id IN (…)` in a single query.

### Empty state

- "No revisions have been recorded yet. Revisions are created automatically when a post or reply is edited."

## Safety checks

1. **PRE state:**
   - Confirm A6 submenu still registered: `wp eval "var_dump( menu_page_url( 'jetonomy-activity', false ) );"` returns the URL string (not empty)
   - Existing admin pages timing baseline:
     ```bash
     for slug in jetonomy jetonomy-categories jetonomy-tags jetonomy-spaces \
                 jetonomy-content jetonomy-moderation jetonomy-activity \
                 jetonomy-users jetonomy-import jetonomy-settings jetonomy-extensions; do
         time curl -s -o /dev/null --cookie "$(wp user generate-cookie test_admin)" \
             "http://forums.local/wp-admin/admin.php?page=$slug"
     done
     # Each <2s
     ```
   - Snapshot revisions count: `wp db query "SELECT COUNT(*) FROM wp_jt_revisions"`

2. **Implement, then run quality gates:**
   ```bash
   php -l includes/admin/class-revisions-list-table.php
   php -l includes/admin/views/revisions.php
   php -l includes/admin/class-admin.php
   composer phpstan
   composer phpcs
   wp jetonomy qa-actions run     # expect 210/210
   bin/access-matrix-check.sh --diff-baseline   # expect 78/78, no drift
   ```

3. **POST checks (browser):**
   - Login `?autologin=1` → navigate `/wp-admin/admin.php?page=jetonomy-revisions` (list mode)
   - Page loads in <2s, shows pagination with 20/page, columns render
   - Click an object's title → detail mode loads for that `(type,id)`
   - Detail mode shows N rows for an object with N revisions; "View diff" expands a left/right pane
   - **No JS errors** in console (Playwright `browser_console_messages level=error` returns 0)
   - **Empty state:** with no revisions in DB, list shows the empty message (truncate `wp db query "TRUNCATE wp_jt_revisions"` in a snapshot DB only — DO NOT do this on the live site; if needed, copy DB to a scratch site)
   - **Cap gate:** logout → `?autologin=test_subscriber` → hit `/wp-admin/admin.php?page=jetonomy-revisions` → "You do not have sufficient permissions" (or WP standard 403 page). Do NOT show a blank page.
   - **No regression:** rerun the timing loop from PRE — all existing pages still <2s
   - **Submenu order:** Content · Moderation · Activity Log · Revisions · Users (Activity must remain immediately before Revisions; Users must remain immediately after)

4. **Smoke + matrix:** `wp jetonomy qa-actions run` → 210/210; `bin/access-matrix-check.sh --diff-baseline` → 78/78 PASS, no drift (admin pages aren't in the matrix, but REST shouldn't have changed)

## Commits

```
1. feat(admin): Revisions_List_Table class (A7.1)
2. feat(admin): Revisions admin page + per-object diff view (A7.2)
3. chore(admin): submenu order — Revisions slot between Activity and Users (A7.3)
```

## Done criteria

- [ ] Page loads, paginates, filters
- [ ] Detail mode renders per-object revision list with diff
- [ ] Cap gate denies non-mods (subscriber + author + editor without `jetonomy_manage_settings`)
- [ ] Existing admin pages unaffected, all <2s
- [ ] Submenu order matches spec (A6 still slotted, A8's slot reserved)
- [ ] CHECKLIST `plan/1.4.1-baselines/CHECKLIST.md` marks A7 done
- [ ] Push to origin/1.4.1

## Forbidden

- ❌ Don't add restore / delete / edit actions in v1 — read-only browser
- ❌ Don't add a new capability — reuse `jetonomy_manage_settings`
- ❌ Don't disturb A6's `// ── A6: Activity Log ──` block in `class-admin.php`
- ❌ Don't create a new REST endpoint — list and detail render server-side via the existing model
- ❌ Don't break submenu order: Content · Moderation · Activity · Revisions · Users
