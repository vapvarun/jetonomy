# Jetonomy Coding Standards

**Last updated:** 2026-03-24

This is the authoritative reference for all PHP, JavaScript, and CSS in the Jetonomy plugin. If it doesn't match this guide, it's wrong.

---

## Table of Contents

1. [Architecture Principles](#architecture-principles)
2. [PHP Standards](#php-standards)
3. [CSS Standards](#css-standards)
4. [Template Standards](#template-standards)
5. [REST API Standards](#rest-api-standards)
6. [Testing Standards](#testing-standards)
7. [Git Conventions](#git-conventions)

---

## Architecture Principles

### Theme-First, Plugin-Second

The plugin NEVER sets its own fonts, colors, or base sizes. All visual properties inherit from the active WordPress theme via `theme.json` CSS custom properties.

- NO `@layer` — use `.jt-app` scoping for specificity
- NO `prefers-color-scheme: dark` — dark mode is the theme's responsibility (`.jt-dark` class only)
- Container width: `var(--wp--style--global--wide-size, 1200px)`

### WordPress Modern Stack Only

- **Frontend:** Interactivity API + REST API only. No jQuery on public pages.
- No inline `<script>` in templates — all JS in `view.js` store or `composer.js`
- No inline `style=""` in templates — all styles in `jetonomy.css`
- Form submissions: via IA store actions (generator functions) calling REST API
- **Admin:** jQuery is acceptable (WP admin standard)

### API-First

- Every feature MUST have a complete REST API
- Every response MUST include enriched data: `author_name`, `avatar`, `trust_level`, `profile_url`, `time_ago`
- REST API is the single source of truth
- Cursor-based pagination on all list endpoints
- Rate limiting on all write endpoints

### Database Performance

- Custom tables only — never CPTs or postmeta for Jetonomy content
- Denormalized counters (`reply_count`, `post_count`, `vote_score`) updated on write
- Proper indexes on every table matching actual query patterns
- `dbDelta()` for schema, versioned migrations for upgrades
- NO `SELECT *` in production queries — always specify columns

### Adapter Pattern

- Every external integration via adapter interface
- Plugin has ZERO hard dependencies on external plugins
- Third parties register via `add_filter('jetonomy_{type}_adapters', ...)`

---

## PHP Standards

### WPCS Baseline

All PHP files must follow WordPress Coding Standards:

```php
<?php
defined( 'ABSPATH' ) || exit;
```

- Tabs (not spaces) for indentation
- Yoda conditions where practical (`if ( true === $flag )`)
- All output escaped at the point of output
- All database queries use `$wpdb->prepare()` or placeholders
- Nonces required for all write operations
- Capabilities checked before any data modification

### Naming Conventions

| Type | Convention | Example |
|------|-----------|---------|
| Options | `jetonomy_*` prefix | `jetonomy_settings` |
| User meta | `jetonomy_*` prefix | `jetonomy_reputation` |
| DB tables | `jt_*` prefix (→ `wp_jt_*`) | `wp_jt_posts` |
| Hook names | `jetonomy_*` prefix | `jetonomy_post_created` |
| AJAX actions | `wp_ajax_jetonomy_*` | `wp_ajax_jetonomy_vote` |
| Asset handles | `jetonomy` or `jetonomy-{variant}` | `jetonomy-admin` |

Never rename existing hooks or AJAX actions.

### File Naming

| Type | Pattern | Example |
|------|---------|---------|
| Models | `class-{name}.php` | `class-user-profile.php` |
| Controllers | `class-{name}-controller.php` | `class-posts-controller.php` |
| Adapters | `class-{name}-adapter.php` | `class-memberpress-adapter.php` |
| Interfaces | `interface-{name}-adapter.php` | `interface-membership-adapter.php` |
| Templates | `{name}.php` | `single-post.php` |
| Partials | `{name}.php` | `reply-card.php` |

### Admin Architecture

**Admin class: max 750 lines.** The Admin class handles: render methods, menu registration, settings, and asset enqueueing only.

**AJAX handlers live in `Jetonomy\Admin\Ajax\` — NEVER directly in the Admin class.**

```
includes/admin/ajax/class-{domain}-handler.php
```

Handler class rules:
- Max 400 lines
- No render logic in handlers
- No AJAX registration in `Admin::__construct()` — only `add_menu`, `register_settings`, `enqueue_assets`, and `new Ajax\*_Handler()` calls

Current handlers:

```
Categories_Handler   Spaces_Handler      Moderation_Handler
Users_Handler        Import_Handler      Settings_Handler
Content_Handler      Setup_Handler
```

Adding a new AJAX group means creating a new handler class — not extending an existing one.

### Data Access

- All data access via model classes — no raw SQL outside `includes/db/`
  - Exception: Abilities may execute callbacks for cross-table queries
- Content stored as sanitized HTML (`wp_kses_post`), with a plain-text copy for `FULLTEXT` search
- Denormalized counters updated on write
- Permission checks via `Permission_Engine::can( $user_id, $action, $space_id )`

### Activity Logging

Activity logging goes through `Activity_Tracker` hooks — never call `ActivityLog::log()` directly in controllers.

```php
// WRONG
ActivityLog::log( $user_id, 'created_post', $post_id );

// CORRECT — fire the domain hook; Activity_Tracker handles logging
do_action( 'jetonomy_post_created', $post_id, $user_id );
```

- Demo data is tracked in the `jetonomy_demo_data` option for one-click cleanup
- Activity backfill runs once via the `jetonomy_activity_backfilled` flag

### Notifications

NEVER create notifications directly in controllers — always via hooks + Notifier.

```php
// WRONG
Notifier::notify( $user_id, 'reply', $data );

// CORRECT
do_action( 'jetonomy_reply_created', $reply_id, $post_id, $author_id );
// Notifier listens on this hook
```

### Anti-patterns

Never do any of the following:

- `SELECT *` in production queries
- CPTs or postmeta for Jetonomy content
- `wp_insert_post()` / `wp_update_post()` for community content
- Hardcoded user IDs or space IDs
- Direct `ActivityLog::log()` in controllers
- Hardcoded profile URL paths in templates (use `\Jetonomy\get_profile_url()`)

---

## CSS Standards

### Custom Properties

These come from `theme.json` — the plugin only sets fallbacks:

```css
--jt-accent:        var(--wp--preset--color--primary, #3B82F6);
--jt-text:          var(--wp--preset--color--contrast, #1a1a1a);
--jt-bg:            var(--wp--preset--color--base, #ffffff);
--jt-font:          var(--wp--preset--font-family--body, inherit);
--jt-font-heading:  var(--wp--preset--font-family--heading, inherit);
```

### Color Usage

- **Links:** `color: inherit` — NOT the accent color. Only hover uses accent.
- **Accent color:** ONLY for primary buttons, active nav underline, focus rings, count pills, and progress bars.
- **Text hierarchy:**
  - Primary: `--jt-text`
  - Secondary: `--jt-text-secondary` (70% opacity)
  - Tertiary: `--jt-text-tertiary` (50% opacity)
- Every `color-mix()` MUST have a static fallback on the line above it.

### Font Sizing

- NO hardcoded `px` — ever
- Body text: inherits from theme (no `font-size` set on `.jt-app`)
- Headings: inherit from theme's `h1`–`h6` (no `font-size` override)
- Smaller text: `rem` only — minimum `0.75rem`, `0.875rem` for secondary text
- NO `em` — it compounds in nested elements
- Decorative/emoji sizing: `em` is acceptable

### Layout

- All pages: `jt-two-col` grid (main + sidebar) — uniform across the plugin
- Forms only: single column — `jt-narrow` / `jt-narrower`
- Container: theme's `.container` class wraps all content (set by `Template_Loader`)
- Sub-nav: full-width, outside the container

### Responsive Breakpoints

```css
/* > 1024px: Full layout — main + sidebar */

@media (max-width: 1024px) {
    /* Sidebar hidden, mobile nav toggle visible */
}

@media (max-width: 640px) {
    /* Compact layout — smaller padding, simplified grids */
}
```

Every new CSS layout block must include both breakpoints where applicable.

### Interactions

Every element must have appropriate interactions:

| Element | Interaction |
|---------|-------------|
| List rows | Staggered `jt-slideUp` entrance (30ms intervals) |
| Cards (space, badge, idea) | Hover: `translateY(-2px)` + shadow |
| Topic rows (`a.jt-row`) | Hover: `translateX(3px)` |
| Buttons | `:active` `scale(0.97)` |
| Vote buttons | `:active` `scale(1.3)`, score pop on vote |
| Page titles | `jt-fadeTitle` animation |
| Empty states | Icon pulse animation |
| Nav active tab | Animated underline (`scaleX`) |
| Keyboard focus | 2px accent outline via `:focus-visible` |

All animations must respect `prefers-reduced-motion`:

```css
@media (prefers-reduced-motion: reduce) {
    /* Disable or reduce all transitions and animations */
}
```

---

## Template Standards

### Template Structure

Every view template follows this pattern:

```php
<?php
defined( 'ABSPATH' ) || exit;

// 1. Data fetching — models only, no raw SQL
$data = \Jetonomy\Models\Something::find( $id );

// 2. Breadcrumb
\Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => [ /* ... */ ] ] );

// 3. Two-column layout
?>
<div class="jt-two-col">
    <main>
        <!-- Page content -->
    </main>
    <aside class="jt-sidebar">
        <?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
    </aside>
</div>
```

### Output Escaping

All output must be escaped at the point of output:

| Context | Function |
|---------|---------|
| Text output | `esc_html()` / `esc_html_e()` |
| Attribute values | `esc_attr()` |
| URLs | `esc_url()` |
| User-generated HTML | `wp_kses_post()` |
| `data-wp-context` values | `wp_json_encode()` |

### User Display

```php
// ALWAYS use these — both are filterable
\Jetonomy\get_user_link( $user_id );   // renders avatar + name
\Jetonomy\get_profile_url( $user_id ); // profile URL

// NEVER hardcode profile URL paths
```

### Interactivity API

- `data-wp-interactive="jetonomy"` is on the `.jt-app` div — `Template_Loader` handles this
- Per-element context via `data-wp-context`
- Actions, bindings, and on-event directives only — no `onclick=""` or `addEventListener` in templates

```php
<div data-wp-context='<?php echo wp_json_encode( [ 'loading' => false, 'postId' => $post->id ] ); ?>'>
    <button
        data-wp-on--click="actions.submitVote"
        data-wp-bind--disabled="context.loading"
        aria-label="<?php esc_attr_e( 'Vote', 'jetonomy' ); ?>"
    >
        <!-- content -->
    </button>
</div>
```

### HTML Validity

- NO `<a>` nested inside `<a>` — use `<span>` for sub-elements inside links
- NO `<button>` inside `<a>`
- All `<img>` must have `alt`, `width`, `height`, `loading="lazy"`
- All interactive elements must have `aria-label`
- One `<h1>` per template content area; sidebars use `<h4>`

---

## REST API Standards

### Response Format

Every endpoint returns:

```json
{
    "data": [],
    "meta": {
        "count": 20,
        "has_more": true,
        "total": 150
    }
}
```

### Enriched Responses

All list and detail endpoints MUST include:

```json
{
    "author_name": "Jane Doe",
    "author_avatar": "https://...",
    "author_login": "janedoe",
    "trust_level": 2,
    "reputation": 340,
    "time_ago": "3 hours ago",
    "profile_url": "https://..."
}
```

### Authentication

| Operation | Auth method |
|-----------|-------------|
| Public GET (public content) | None required |
| Write operations (web) | Cookie + nonce |
| Write operations (app) | Application Password |

Permission checks always go through `Permission_Engine::can( $user_id, $action, $space_id )`.

### Error Format

```json
{
    "code": "jetonomy_forbidden",
    "message": "You do not have permission.",
    "data": { "status": 403 }
}
```

Error codes use the `jetonomy_` prefix.

### Namespaces

| Namespace | Plugin |
|-----------|--------|
| `jetonomy/v1` | Jetonomy free + Pro extensions |

---

## Testing Standards

### Every Feature Must Have

- Unit tests for model logic
- Integration tests for API endpoints
- Permission check tests (allowed case + denied case)
- Empty state handling
- Error state handling

### Test Naming

```php
public function test_create_returns_id(): void {}
public function test_find_by_slug_returns_null_for_missing(): void {}
public function test_private_space_denies_non_member(): void {}
```

Pattern: `test_{action}_{expected_outcome}`

---

## Git Conventions

- NO co-author attribution in commits
- Commit prefix required:

| Prefix | Use for |
|--------|---------|
| `feat:` | New feature |
| `fix:` | Bug fix |
| `chore:` | Maintenance, dependencies |
| `docs:` | Documentation only |
| `test:` | Tests only |
| `refactor:` | Code restructure, no behavior change |

- One concern per commit — do not mix features with fixes
- Push after every logical change — do not accumulate
