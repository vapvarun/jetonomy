# A6 — Activity Log admin page

**Branch:** `1.4.1`
**Plugin:** jetonomy (free)
**Risk:** low — read-only UI, table already populated
**Estimated time:** 2 days

## Problem

`{prefix}jt_activity_log` already exists and is populated with audit events (cron `jetonomy_prune_activity` runs daily). Admins can't browse it from the WP UI. Drop in a list table.

## Implementation

### Files

```
includes/admin/views/activity.php           ← new view
includes/admin/class-admin.php              ← add submenu registration
includes/admin/class-activity-list-table.php ← extends WP_List_Table (new)
```

### Submenu registration in `class-admin.php`

```php
add_submenu_page(
    'jetonomy',
    __( 'Activity Log', 'jetonomy' ),
    __( 'Activity Log', 'jetonomy' ),
    'jetonomy_manage_settings',
    'jetonomy-activity',
    [ self::class, 'render_activity_page' ]
);
```

Place between `jetonomy-content` and `jetonomy-users` in the sidebar order.

### Page features

- WP_List_Table-based listing
- Default 20 per page (`screen_options`)
- Columns: Date · Actor · Type · Object (linked) · Snippet
- Filters: by user (autocomplete), by type (dropdown of distinct types in table), by date range (from/to)
- Export to CSV (admin-only) — small button top-right; reuses existing CSV helper if present, else generates inline
- Read-only — no edit/delete actions in v1

### Access

`jetonomy_manage_settings` capability (matches existing admin pattern). Users without it get the standard WP "you do not have sufficient permissions" page.

## Safety checks

1. **PRE state:** Screenshot existing admin sidebar (Playwright or manual) → `plan/1.4.1-baselines/A6/admin-sidebar-pre.png`
2. **Existing pages still load fast:**
   ```bash
   for slug in jetonomy jetonomy-categories jetonomy-tags jetonomy-spaces \
              jetonomy-content jetonomy-moderation jetonomy-users \
              jetonomy-import jetonomy-settings jetonomy-extensions; do
       time curl -s -o /dev/null --cookie "$(wp user generate-cookie test_admin)" \
           "http://forums.local/wp-admin/admin.php?page=$slug"
   done
   # Each <2s
   ```
3. **POST checks:**
   - New page loads at `?page=jetonomy-activity` in <2s
   - Pagination: ≥3 pages with default 20/page (seed activity if needed via `wp jetonomy activity simulate`)
   - Filters narrow the result correctly
   - CSV export downloads expected rows
   - **No JS errors** in browser console
4. **Other admin pages still work:** rerun the timing loop, all should still be <2s
5. **Submenu order unchanged** for existing pages
6. **Smoke:** `wp jetonomy qa-actions run` 210/210
7. **Runner:** `bin/access-matrix-check.sh --diff-baseline` no drift (admin pages aren't in the matrix, but REST shouldn't have changed)

## Commits

```
1. feat(admin): Activity_List_Table class (A6.1)
2. feat(admin): Activity Log submenu page (A6.2)
3. feat(admin): CSV export for Activity Log (A6.3)
```

## Done criteria

- [ ] Page loads, paginates, filters, exports
- [ ] Existing admin pages unaffected
- [ ] CHECKLIST marks A6 done
- [ ] Push to origin/1.4.1

## Forbidden

- ❌ Don't add edit/delete actions in v1 — read-only browser only
- ❌ Don't break the existing admin menu order
- ❌ Don't introduce a new capability — reuse `jetonomy_manage_settings`
