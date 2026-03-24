# Admin Listings — UX Polish & 100K-Safe Pagination

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every admin listing page renders with consistent card/toolbar UX and handles 100 000+ records via proper server-side `paginate_links()` pagination — no hardcoded LIMIT, no full-table SELECT.

**Architecture:** Three-layer change — (1) data queries in `class-admin.php` get COUNT + LIMIT/OFFSET, (2) view files adopt a uniform HTML pattern (`jt-content-toolbar` + `jt-content-table-wrap` + `paginate_links`), (3) CSS normalises all status/badge pill classes to the `.jt-status-badge` system already present on Content page.

**Tech Stack:** PHP 8.1, WordPress `paginate_links()`, `$wpdb->prepare()`, existing `.jt-*` CSS classes.

---

## Reference: Target HTML Pattern

Every listing page must produce this skeleton (already used by Content page):

```html
<!-- Toolbar: filters left, count + bulk right -->
<form method="get" action="">
  <input type="hidden" name="page" value="jetonomy-{slug}">
  <div class="jt-content-toolbar">
    <!-- filters / search -->
    <div class="jt-content-toolbar__right">
      <span class="displaying-num">N items</span>
      <button type="submit" class="button">Filter</button>
    </div>
  </div>
</form>

<!-- Table wrapped in card -->
<div class="jt-content-table-wrap">
  <table class="wp-list-table widefat fixed striped">…</table>
</div>

<!-- Pagination bar -->
<div class="tablenav bottom">
  <div class="tablenav-pages">
    <span class="displaying-num">N–M of T</span>
    <!-- paginate_links() output -->
  </div>
</div>
```

Status pill class system (already defined in `admin.css`):
```html
<span class="jt-status-badge jt-status-badge--publish">Published</span>
<span class="jt-status-badge jt-status-badge--pending">Pending</span>
<span class="jt-status-badge jt-status-badge--spam">Spam</span>
<span class="jt-status-badge jt-status-badge--trash">Trash</span>
<!-- For spaces -->
<span class="jt-status-badge jt-status-badge--active">Active</span>
<span class="jt-status-badge jt-status-badge--archived">Archived</span>
<span class="jt-status-badge jt-status-badge--locked">Locked</span>
```

---

## File Map

| File | Change |
|------|--------|
| `includes/admin/class-admin.php` | `render_spaces()`, `render_moderation()`, `render_content()` — add COUNT + LIMIT/OFFSET/paged |
| `includes/admin/views/spaces.php` | toolbar form, card wrap, paginate_links, `.jt-status-badge` pills |
| `includes/admin/views/categories.php` | wrap add-form in `.jt-settings-card`, wrap table in `.jt-content-table-wrap` |
| `includes/admin/views/moderation.php` | tab counts from real totals, paginate_links per tab, card wrap |
| `includes/admin/views/content.php` | add COUNT query, paginate_links, showing N–M of T |
| `includes/admin/views/users.php` | add `.jt-content-table-wrap` wrapper (pagination already correct) |
| `assets/css/admin.css` | add missing badge variants; `.jt-status-badge--active/archived/locked`; `.jt-toolbar__right` |

---

## Task 1: Data layer — Spaces pagination

**Files:**
- Modify: `includes/admin/class-admin.php` — `render_spaces()` method (~line 370–391)

### What to change

`render_spaces()` currently runs `SELECT * FROM spaces WHERE … ORDER BY title ASC` with no LIMIT. Replace with COUNT + paginated SELECT.

- [ ] **Step 1: Add COUNT + LIMIT/OFFSET to `render_spaces()`**

Replace the block starting at the comment `// List view` through `include …/spaces.php`:

```php
// List view
$filter_category = absint( $_GET['category_id'] ?? 0 );
$filter_type     = sanitize_text_field( $_GET['type'] ?? '' );
$filter_status   = sanitize_text_field( $_GET['status'] ?? '' );
$paged           = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page        = 20;
$offset          = ( $paged - 1 ) * $per_page;

$where = [ '1=1' ];
if ( $filter_category ) {
    $where[] = $wpdb->prepare( 'category_id = %d', $filter_category );
}
if ( $filter_type && in_array( $filter_type, [ 'forum', 'qa', 'ideas', 'feed' ], true ) ) {
    $where[] = $wpdb->prepare( 'type = %s', $filter_type );
}
if ( $filter_status && in_array( $filter_status, [ 'active', 'archived', 'locked' ], true ) ) {
    $where[] = $wpdb->prepare( 'status = %s', $filter_status );
}

$where_sql   = implode( ' AND ', $where );
$spaces_t    = table( 'spaces' );
$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$spaces_t} WHERE {$where_sql}" );
$total_pages = (int) ceil( $total / $per_page );
$spaces      = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$spaces_t} WHERE {$where_sql} ORDER BY title ASC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    )
) ?: [];
$categories  = $this->get_all_categories_flat();

include JETONOMY_DIR . 'includes/admin/views/spaces.php';
```

- [ ] **Step 2: Verify the file saved correctly**

```bash
grep -n "LIMIT %d OFFSET %d" \
  "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/jetonomy/includes/admin/class-admin.php"
```
Expected: one match showing the new prepared query.

- [ ] **Step 3: Commit**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/jetonomy"
git add includes/admin/class-admin.php
git commit -m "fix: add server-side pagination to render_spaces() data query"
```

---

## Task 2: Data layer — Moderation real counts + pagination

**Files:**
- Modify: `includes/admin/class-admin.php` — `render_moderation()` method (~line 393–421)

### What to change

The four queries each have a hardcoded `LIMIT 50`. Tab badge counts use `count($pending_posts)` (= 50 max) instead of real totals. Fix: add per-tab COUNT queries + per-tab `$paged_*` pagination.

- [ ] **Step 1: Replace `render_moderation()` body**

```php
public function render_moderation(): void {
    global $wpdb;

    $posts_t        = table( 'posts' );
    $replies_t      = table( 'replies' );
    $flags_t        = table( 'flags' );
    $restrictions_t = table( 'restrictions' );
    $per_page       = 20;
    $active_tab     = sanitize_text_field( $_GET['tab'] ?? 'posts' );

    // Per-tab paged params.
    $paged_posts   = max( 1, absint( $_GET['paged_posts'] ?? 1 ) );
    $paged_replies = max( 1, absint( $_GET['paged_replies'] ?? 1 ) );
    $paged_flags   = max( 1, absint( $_GET['paged_flags'] ?? 1 ) );
    $paged_banned  = max( 1, absint( $_GET['paged_banned'] ?? 1 ) );

    // Real totals for tab badge counts.
    $total_posts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts_t} WHERE status = 'pending'" );
    $total_replies = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$replies_t} WHERE status = 'pending'" );
    $total_flags   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flags_t} WHERE status = 'pending'" );
    $total_banned  = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$restrictions_t}
         WHERE type IN ('global_ban','space_ban','silence')
         AND (expires_at IS NULL OR expires_at > '" . now() . "')"
    );

    $pending_posts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.*, s.title as space_title
             FROM {$posts_t} p
             LEFT JOIN " . table( 'spaces' ) . " s ON s.id = p.space_id
             WHERE p.status = 'pending'
             ORDER BY p.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            ( $paged_posts - 1 ) * $per_page
        )
    ) ?: [];

    $pending_replies = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT r.*, p.title as post_title
             FROM {$replies_t} r
             LEFT JOIN {$posts_t} p ON p.id = r.post_id
             WHERE r.status = 'pending'
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            ( $paged_replies - 1 ) * $per_page
        )
    ) ?: [];

    $pending_flags = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$flags_t}
             WHERE status = 'pending'
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            ( $paged_flags - 1 ) * $per_page
        )
    ) ?: [];

    $banned_users = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT r.*, u.display_name, u.user_login
             FROM {$restrictions_t} r
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             WHERE r.type IN ('global_ban','space_ban','silence')
             AND (r.expires_at IS NULL OR r.expires_at > '" . now() . "')
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            ( $paged_banned - 1 ) * $per_page
        )
    ) ?: [];

    include JETONOMY_DIR . 'includes/admin/views/moderation.php';
}
```

- [ ] **Step 2: Verify**

```bash
grep -n "total_posts\|paged_posts\|paged_replies" \
  "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/jetonomy/includes/admin/class-admin.php"
```
Expected: matches for `$total_posts`, `$paged_posts`, `$paged_replies`.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-admin.php
git commit -m "fix: moderation queries use real COUNT totals + paginated LIMIT/OFFSET"
```

---

## Task 3: Data layer — Content pagination

**Files:**
- Modify: `includes/admin/class-admin.php` — `render_content()` method (~line 1414–1451)

- [ ] **Step 1: Add COUNT + paged to `render_content()`**

After building `$where` and `$args`, add:

```php
$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page = 20;
$offset   = ( $paged - 1 ) * $per_page;

// Count query (same WHERE, no LIMIT).
$count_sql = "SELECT COUNT(*) FROM {$posts_t} p WHERE {$where}";
$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );
$total_pages = (int) ceil( $total / $per_page );
```

Replace the main query to add LIMIT/OFFSET (remove the hardcoded `LIMIT 100`):

```php
$sql = "SELECT p.*, s.title AS space_title, s.slug AS space_slug
        FROM {$posts_t} p
        LEFT JOIN {$spaces_t} s ON s.id = p.space_id
        WHERE {$where}
        ORDER BY p.created_at DESC
        LIMIT %d OFFSET %d";
$full_args = array_merge( $args, [ $per_page, $offset ] );
$posts = $wpdb->get_results( $wpdb->prepare( $sql, ...$full_args ) ) ?: [];
```

Pass `$total`, `$total_pages`, `$paged`, `$per_page` through to the view (they are picked up via `include`).

- [ ] **Step 2: Verify**

```bash
grep -n "LIMIT %d OFFSET %d" \
  "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/jetonomy/includes/admin/class-admin.php" \
  | grep -i content
```
Expected: match inside the content block.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-admin.php
git commit -m "fix: render_content() uses COUNT + LIMIT/OFFSET — removes hardcoded LIMIT 100"
```

---

## Task 4: View — Spaces UX + pagination

**Files:**
- Modify: `includes/admin/views/spaces.php`

### Specific changes

1. Replace `<div class="tablenav top">` filter block with a `<form method="get">` + `.jt-content-toolbar`.
2. Wrap table in `<div class="jt-content-table-wrap">`.
3. Replace `jetonomy-status-dot--*` spans with `.jt-status-badge` pills.
4. Replace `jetonomy-badge--*` visibility spans with `.jt-status-badge` pills.
5. Add `paginate_links()` block after the table.
6. Add "showing N–M of T" count to toolbar right.

- [ ] **Step 1: Replace filter bar with `.jt-content-toolbar` form**

Replace the entire `<!-- Filters --> <div class="tablenav top">…</div>` block with:

```php
<!-- ── Toolbar ─────────────────────────────────────────────── -->
<form method="get" action="" id="jetonomy-spaces-filters">
    <input type="hidden" name="page" value="jetonomy-spaces">
    <div class="jt-content-toolbar">
        <select name="category_id">
            <option value=""><?php esc_html_e( 'All Categories', 'jetonomy' ); ?></option>
            <?php foreach ( $categories as $cat ) : ?>
                <option value="<?php echo absint( $cat->id ); ?>" <?php selected( $filter_category, $cat->id ); ?>><?php echo esc_html( $cat->name ); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type">
            <option value=""><?php esc_html_e( 'All Types', 'jetonomy' ); ?></option>
            <option value="forum" <?php selected( $filter_type, 'forum' ); ?>><?php esc_html_e( 'Forum', 'jetonomy' ); ?></option>
            <option value="qa" <?php selected( $filter_type, 'qa' ); ?>><?php esc_html_e( 'Q&A', 'jetonomy' ); ?></option>
            <option value="ideas" <?php selected( $filter_type, 'ideas' ); ?>><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></option>
            <option value="feed" <?php selected( $filter_type, 'feed' ); ?>><?php esc_html_e( 'Feed', 'jetonomy' ); ?></option>
        </select>
        <select name="status">
            <option value=""><?php esc_html_e( 'All Statuses', 'jetonomy' ); ?></option>
            <option value="active" <?php selected( $filter_status, 'active' ); ?>><?php esc_html_e( 'Active', 'jetonomy' ); ?></option>
            <option value="archived" <?php selected( $filter_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'jetonomy' ); ?></option>
            <option value="locked" <?php selected( $filter_status, 'locked' ); ?>><?php esc_html_e( 'Locked', 'jetonomy' ); ?></option>
        </select>
        <div class="jt-content-toolbar__right">
            <?php
            $first = ( $paged - 1 ) * 20 + 1;
            $last  = min( $paged * 20, $total );
            if ( $total ) :
            ?>
            <span class="displaying-num">
                <?php printf( esc_html__( '%1$s–%2$s of %3$s', 'jetonomy' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) ); ?>
            </span>
            <?php endif; ?>
            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'jetonomy' ); ?></button>
        </div>
    </div>
</form>
```

- [ ] **Step 2: Wrap table in `.jt-content-table-wrap`**

Replace `<table class="wp-list-table widefat fixed striped">` with:

```php
<div class="jt-content-table-wrap">
<table class="wp-list-table widefat fixed striped">
```

And add `</div><!-- /.jt-content-table-wrap -->` after `</table>`.

- [ ] **Step 3: Replace status dot with `.jt-status-badge`**

In the `<td class="column-status">` cell, replace:
```php
<span class="jetonomy-status-dot jetonomy-status-dot--<?php echo esc_attr( $space->status ); ?>"></span>
<?php echo esc_html( ucfirst( $space->status ) ); ?>
```
with:
```php
<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $space->status ); ?>"><?php echo esc_html( ucfirst( $space->status ) ); ?></span>
```

- [ ] **Step 4: Replace visibility badge with `.jt-status-badge`**

In the `<td class="column-visibility">` cell, replace:
```php
<span class="jetonomy-badge jetonomy-badge--<?php echo esc_attr( $space->visibility ); ?>"><?php echo esc_html( ucfirst( $space->visibility ) ); ?></span>
```
with:
```php
<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $space->visibility ); ?>"><?php echo esc_html( ucfirst( $space->visibility ) ); ?></span>
```

- [ ] **Step 5: Add `paginate_links()` after the table**

After `</div><!-- /.jt-content-table-wrap -->`:

```php
<?php if ( $total_pages > 1 ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $page_links = paginate_links( [
                'base'    => add_query_arg( 'paged', '%#%' ),
                'format'  => '',
                'current' => $paged,
                'total'   => $total_pages,
                'type'    => 'array',
            ] );
            if ( $page_links ) {
                echo '<span class="pagination-links">' . implode( ' ', $page_links ) . '</span>';
            }
            ?>
        </div>
    </div>
<?php endif; ?>
```

- [ ] **Step 6: Verify the file looks correct**

Open `http://forums.local/wp-admin/admin.php?page=jetonomy-spaces?autologin=1` and confirm: filter form, card-wrapped table, pagination bar (if > 20 spaces).

- [ ] **Step 7: Commit**

```bash
git add includes/admin/views/spaces.php
git commit -m "ux: spaces listing — jt-content-toolbar, card wrap, status badges, pagination"
```

---

## Task 5: View — Categories UX wrap

**Files:**
- Modify: `includes/admin/views/categories.php`

Categories are rarely > 100, so no pagination. Just needs consistent card wrapping.

- [ ] **Step 1: Wrap the add-form in `.jt-settings-card`**

Replace `<div class="jetonomy-inline-form" id="jetonomy-add-category-form">` with:

```php
<div class="jt-settings-card" id="jetonomy-add-category-form">
    <div class="jt-settings-card__head">
        <h2 class="jt-settings-card__title"><?php esc_html_e( 'Add New Category', 'jetonomy' ); ?></h2>
    </div>
```

Close the card after `</p>` (the submit button row): add `</div><!-- /.jt-settings-card -->`.

Remove the old `<h2>Add New Category</h2>` inside the form (it's now in the card head).

- [ ] **Step 2: Wrap the categories table in `.jt-content-table-wrap`**

Replace `<!-- Categories Table --> <table …>` with:

```php
<!-- Categories Table -->
<div class="jt-content-table-wrap">
<table class="wp-list-table widefat fixed striped" id="jetonomy-categories-table">
```

Add `</div><!-- /.jt-content-table-wrap -->` after `</table>`.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/views/categories.php
git commit -m "ux: categories — card wrapper for add-form and table"
```

---

## Task 6: View — Moderation UX + pagination

**Files:**
- Modify: `includes/admin/views/moderation.php`

### Key changes

1. Tab badge counts: replace `count($pending_posts)` etc. with `$total_posts` etc. (now passed from controller).
2. Wrap each tab's table in `.jt-content-table-wrap`.
3. Add `paginate_links()` after each table using the per-tab `$paged_*` variables.
4. Show "N–M of T" count per tab.

- [ ] **Step 1: Replace tab badge counts**

In the `<nav class="nav-tab-wrapper">`, replace each `count(…)` call:

```php
// Before                                After
count( $pending_posts )    →    $total_posts
count( $pending_replies )  →    $total_replies
count( $pending_flags )    →    $total_flags
count( $banned_users )     →    $total_banned
```

- [ ] **Step 2: Add card wrap + pagination to Posts tab**

After the posts `</table>`, before `<?php endif; ?>`:

```php
        </table>
    </div><!-- /.jt-content-table-wrap -->
    <?php if ( ceil( $total_posts / 20 ) > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    $first = ( $paged_posts - 1 ) * 20 + 1;
                    $last  = min( $paged_posts * 20, $total_posts );
                    printf( esc_html__( '%1$s–%2$s of %3$s', 'jetonomy' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total_posts ) );
                    ?>
                </span>
                <?php
                $plinks = paginate_links( [
                    'base'    => add_query_arg( [ 'tab' => 'posts', 'paged_posts' => '%#%' ] ),
                    'format'  => '',
                    'current' => $paged_posts,
                    'total'   => (int) ceil( $total_posts / 20 ),
                    'type'    => 'array',
                ] );
                if ( $plinks ) { echo '<span class="pagination-links">' . implode( ' ', $plinks ) . '</span>'; }
                ?>
            </div>
        </div>
    <?php endif; ?>
```

Wrap the table opening with `<div class="jt-content-table-wrap">`.

Repeat the same pattern for **Replies**, **Flags**, and **Banned Users** tabs (using `$paged_replies`/`$total_replies`, `$paged_flags`/`$total_flags`, `$paged_banned`/`$total_banned` respectively).

- [ ] **Step 3: Verify tab badge counts are real**

With > 50 pending items in test data, confirm tab shows actual total, not capped at 50.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/views/moderation.php
git commit -m "ux: moderation — real tab counts, card wrap, per-tab pagination"
```

---

## Task 7: View — Content pagination controls

**Files:**
- Modify: `includes/admin/views/content.php`

Content page already has `.jt-content-toolbar` + `.jt-content-table-wrap`. Only needs:
1. "N–M of T" count in toolbar right.
2. `paginate_links()` after the table.

- [ ] **Step 1: Add count display to toolbar**

Inside `<div class="jt-content-toolbar__right">` (or create it), add before the Filter button:

```php
<?php if ( $total ) : ?>
    <span class="displaying-num">
        <?php
        $first = ( $paged - 1 ) * $per_page + 1;
        $last  = min( $paged * $per_page, $total );
        printf( esc_html__( '%1$s–%2$s of %3$s', 'jetonomy' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) );
        ?>
    </span>
<?php endif; ?>
```

- [ ] **Step 2: Add `paginate_links()` after table**

After the closing `</div><!-- /.jt-content-table-wrap -->`:

```php
<?php if ( $total_pages > 1 ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $plinks = paginate_links( [
                'base'    => add_query_arg( 'paged', '%#%' ),
                'format'  => '',
                'current' => $paged,
                'total'   => $total_pages,
                'type'    => 'array',
            ] );
            if ( $plinks ) { echo '<span class="pagination-links">' . implode( ' ', $plinks ) . '</span>'; }
            ?>
        </div>
    </div>
<?php endif; ?>
```

- [ ] **Step 3: Commit**

```bash
git add includes/admin/views/content.php
git commit -m "ux: content listing — N-of-T count + paginate_links"
```

---

## Task 8: View — Users card wrap

**Files:**
- Modify: `includes/admin/views/users.php`

Users already has correct pagination. Only needs `.jt-content-table-wrap` on the table.

- [ ] **Step 1: Wrap table**

Replace `<table class="wp-list-table widefat fixed striped">` (line ~41) with:

```php
<div class="jt-content-table-wrap">
<table class="wp-list-table widefat fixed striped">
```

Add `</div><!-- /.jt-content-table-wrap -->` after `</table>` (before the pagination `<?php if ( $total_pages > 1 ) ?>`).

- [ ] **Step 2: Commit**

```bash
git add includes/admin/views/users.php
git commit -m "ux: users — wrap table in jt-content-table-wrap card"
```

---

## Task 9: CSS — Add missing badge variants

**Files:**
- Modify: `assets/css/admin.css`

The existing `.jt-status-badge` system covers `publish`, `pending`, `spam`, `trash`. Need variants for spaces and visibility.

- [ ] **Step 1: Add missing variants to `.jt-status-badge` section**

Find the existing `.jt-status-badge` block (search for `jt-status-badge--publish`) and add after it:

```css
/* Space status */
.jt-status-badge--active   { background: #dcfce7; color: #166534; }
.jt-status-badge--archived { background: #f1f5f9; color: #475569; }
.jt-status-badge--locked   { background: #fef3c7; color: #92400e; }

/* Visibility */
.jt-status-badge--public  { background: #dbeafe; color: #1e40af; }
.jt-status-badge--private { background: #fce7f3; color: #9d174d; }
.jt-status-badge--hidden  { background: #f1f5f9; color: #475569; }

/* Toolbar right-side count + actions */
.jt-content-toolbar__right {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
}
```

- [ ] **Step 2: Verify badge colours in browser**

Navigate to Spaces, Moderation, Content pages and confirm all status pills render correctly.

- [ ] **Step 3: Commit**

```bash
git add assets/css/admin.css
git commit -m "style: add jt-status-badge variants for space/visibility; toolbar right alignment"
```

---

## Task 10: Final browser smoke test

- [ ] Open `http://forums.local/wp-admin/admin.php?page=jetonomy-spaces?autologin=1` — confirm toolbar, card wrap, pagination (if spaces > 20)
- [ ] Open `http://forums.local/wp-admin/admin.php?page=jetonomy-content?autologin=1` — confirm N–M of T count, pagination
- [ ] Open `http://forums.local/wp-admin/admin.php?page=jetonomy-moderation?autologin=1` — confirm real tab badge counts, card-wrapped tables, per-tab pagination
- [ ] Open `http://forums.local/wp-admin/admin.php?page=jetonomy-categories?autologin=1` — confirm card-wrapped add form, card-wrapped table
- [ ] Open `http://forums.local/wp-admin/admin.php?page=jetonomy-users?autologin=1` — confirm card wrap, existing pagination still works

---

## What is NOT in this plan (stays as-is)

| Page | Reason |
|------|--------|
| Dashboard activity feed | 10-item limit is correct; only one site can benefit from a "View All" link as a future enhancement |
| Categories | No server-side pagination needed — sets stay < 200 in practice |
| Extensions admin (Pro) | In-memory finite list, no DB query |
| License admin (Pro) | Single-record UI |
| Analytics admin (Pro) | Already queries with date ranges; no table listing |
| Badges admin (Pro) | Separate Pro extension responsibility |

---

## Changelog Entry

After all tasks complete, update `CLAUDE.md` Recent Changes table:

```
| 2026-03-24 | ux | Admin listings — jt-content-toolbar wrap, jt-status-badge pills, server-side paginate_links for Spaces/Moderation/Content | class-admin.php, views/spaces.php, views/moderation.php, views/content.php, views/categories.php, views/users.php, admin.css |
```
