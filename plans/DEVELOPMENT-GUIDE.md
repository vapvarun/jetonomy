# Jetonomy Development Guide

> **This is the single source of truth.** Every template, module, extension, and feature MUST follow these patterns. If it doesn't match this guide, it's wrong.

---

## 1. Architecture Principles

### 1.1 Theme-First, Plugin-Second
- The plugin NEVER sets its own fonts, colors, or base sizes
- ALL visual properties inherit from the active WordPress theme via `theme.json` CSS custom properties
- NO `@layer` — use `.jt-app` scoping for specificity
- NO `prefers-color-scheme: dark` — dark mode is the THEME's responsibility (`.jt-dark` class only)
- Container width: `var(--wp--style--global--wide-size, 1200px)` — matches theme's wide layout

### 1.2 WordPress Modern Stack Only
- **Frontend**: Interactivity API + REST API only. No jQuery on public pages.
- **No inline `<script>` in templates** — all JS in `view.js` store or `composer.js`
- **No inline `style=""`** in templates — all styles in `jetonomy.css`
- **Form submissions**: via IA store actions (generator functions) calling REST API
- **Admin**: jQuery is acceptable (WP admin standard)

### 1.3 API-First
- Every feature MUST have a complete REST API
- Every response MUST include enriched data (author name, avatar, trust level, profile URL, time_ago)
- REST API is the single source of truth — templates and apps consume the same data
- Cursor-based pagination on all list endpoints
- Rate limiting on all write endpoints

### 1.4 Database Performance
- Custom tables only (never CPTs/postmeta)
- Denormalized counters (reply_count, post_count, vote_score) — updated on write
- Proper indexes on every table for the actual query patterns
- `dbDelta()` for schema, versioned migrations for upgrades
- NO `SELECT *` in production queries — specify columns

### 1.5 Adapter Pattern
- Every external integration via adapter interface
- Plugin has ZERO hard dependencies on external plugins
- Adapters: Membership, Search, Real-time, Email
- Third parties register via `add_filter('jetonomy_{type}_adapters', ...)`

---

## 2. CSS Guidelines

### 2.1 Custom Properties (Inherited from Theme)
```css
/* These come from theme.json — we only set fallbacks */
--jt-accent: var(--wp--preset--color--primary, #3B82F6);
--jt-text: var(--wp--preset--color--contrast, #1a1a1a);
--jt-bg: var(--wp--preset--color--base, #ffffff);
--jt-font: var(--wp--preset--font-family--body, inherit);
--jt-font-heading: var(--wp--preset--font-family--heading, inherit);
```

### 2.2 Color Usage (Notion-Style Minimal)
- **Links**: `color: inherit` — NOT accent color. Only hover uses accent.
- **Accent color**: ONLY for primary buttons, active nav underline, focus rings, count pills, progress bars
- **Text hierarchy**: `--jt-text` (primary), `--jt-text-secondary` (70% opacity), `--jt-text-tertiary` (50% opacity)
- **Fallbacks**: Every `color-mix()` MUST have a static fallback on the line above

### 2.3 Font Sizing
- **NO hardcoded px** — ever
- **Body text**: inherits from theme (no font-size set on `.jt-app`)
- **Headings**: inherit from theme's h1-h6 (no font-size override)
- **Smaller text**: use `rem` only (0.75rem minimum, 0.875rem for secondary text)
- **NO `em`** — it compounds in nested elements
- **Decorative/emoji**: `em` is fine (relative to parent is intentional)

### 2.4 Layout
- **All pages**: `jt-two-col` grid (main + sidebar) — uniform
- **Forms only**: single column (no sidebar) — `jt-narrow` / `jt-narrower`
- **Container**: theme's `.container` class wraps all content (set by Template_Loader)
- **Sub-nav**: full-width (outside container)

### 2.5 Interactions (Premium UX Standard)
Every element MUST have appropriate interactions:

| Element | Interaction |
|---|---|
| List items (rows) | Staggered `jt-slideUp` entrance (30ms intervals) |
| Cards (space, badge, idea) | Hover: translateY(-2px) + shadow |
| Topic rows (`a.jt-row`) | Hover: translateX(3px) |
| Leaderboard/trending/members | Hover: background highlight |
| Buttons | `:active` scale(0.97) |
| Vote buttons | `:active` scale(1.3), score pop on vote |
| Sidebar cards | Staggered entrance (100ms intervals) |
| Page titles | `jt-fadeTitle` animation |
| Breadcrumbs | Fade in |
| Empty states | Icon pulse animation |
| Profile banner | Gradient shimmer |
| Progress bars | Grow from 0 |
| Nav active tab | Animated underline (scaleX) |
| Focus (keyboard) | 2px accent outline via `:focus-visible` |
| All animations | Respect `prefers-reduced-motion` |

### 2.6 Responsive Breakpoints
```
> 1024px: Full layout (main + sidebar)
900px:   Sidebar hidden, mobile nav toggle visible
640px:   Compact layout (smaller padding, simplified grids)
```

---

## 3. Template Guidelines

### 3.1 Template Structure
Every view template follows this pattern:
```php
<?php
defined( 'ABSPATH' ) || exit;

// 1. Data fetching (models only, no raw SQL)
$data = \Jetonomy\Models\Something::find( $id );

// 2. Breadcrumb
\Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => [...] ] );

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

### 3.2 Output Escaping (Mandatory)
- `esc_html()` / `esc_html_e()` — all text output
- `esc_attr()` — all attribute values
- `esc_url()` — all URLs
- `wp_kses_post()` — user-generated HTML content
- `wp_json_encode()` — data-wp-context values

### 3.3 User Display
- ALWAYS use `\Jetonomy\get_user_link()` for avatar + name
- ALWAYS use `\Jetonomy\get_profile_url()` for profile URLs
- Both are filterable — third parties (BuddyPress) can override
- NO hardcoded profile URL paths in templates

### 3.4 Interactivity API Usage
- Root `data-wp-interactive="jetonomy"` is on `.jt-app` div (Template_Loader handles this)
- Per-element context via `data-wp-context='<?php echo wp_json_encode([...]); ?>'`
- Actions: `data-wp-on--click="actions.actionName"`
- Bindings: `data-wp-bind--disabled="context.loading"`, `data-wp-text="context.label"`
- NO `onclick=""` or `addEventListener` in templates

### 3.5 HTML Validity
- NO `<a>` nested inside `<a>` — use `<span>` for sub-elements inside links
- NO `<button>` inside `<a>` — use `<span>` styled as button
- All `<img>` have `alt`, `width`, `height`, `loading="lazy"`
- All interactive elements have `aria-label`
- Proper heading hierarchy (one h1 per template content, sidebars use h4)

---

## 4. REST API Guidelines

### 4.1 Response Format
Every endpoint returns:
```json
{
    "data": [...] or {...},
    "meta": {
        "count": 20,
        "has_more": true,
        "total": 150
    }
}
```

### 4.2 Enriched Responses
List/detail endpoints MUST include:
```json
{
    "id": 1,
    "author_id": 5,
    "author_name": "Sarah Kim",
    "author_avatar": "https://...",
    "author_login": "sarah_kim",
    "trust_level": 3,
    "reputation": 1247,
    "time_ago": "2 hours ago",
    "profile_url": "https://.../community/u/sarah_kim/"
}
```

### 4.3 Authentication
- Public GET: no auth required for public content
- Write operations: cookie + nonce (web) or Application Password (app)
- Permission checks via `Permission_Engine::can()`
- Guest users (user_id=0) can read public spaces

### 4.4 Error Format
```json
{
    "code": "jetonomy_forbidden",
    "message": "You do not have permission.",
    "data": { "status": 403 }
}
```

---

## 5. Email / Notification Guidelines

### 5.1 Notification Types
- **Immediate (web + optional email)**: reply, mention, accepted answer, vote batch
- **Batched (digest only)**: new posts in spaces, trending, leaderboard
- **System**: welcome, trust promotion, membership change

### 5.2 Email Template Pattern
- Inline CSS only (email clients don't support external CSS)
- Use theme accent color via `get_option('jetonomy_settings')` or WP preset
- Responsive table-based layout
- One-click unsubscribe (RFC 8058)
- List-Unsubscribe header
- Plain text fallback auto-generated
- Preview available in admin

### 5.3 Notification Dispatch Chain
```
Action happens → do_action('jetonomy_after_X')
  → Notifier::on_X() catches hook
    → Notification::create() (web notification)
    → Check user email preference
      → Email_Adapter::send() (if immediate)
      → Or queue for digest (if batched)
```
NEVER create notifications directly in controllers — always via hooks + Notifier.

---

## 6. Pro Extension Guidelines

### 6.1 Extension Structure
```php
class My_Extension extends \Jetonomy_Pro\Extension {
    public function meta(): array { /* id, name, version, category */ }
    public function boot(): void { /* hooks, REST routes, CSS */ }
    public function activate(): void { /* create tables */ }
    public function deactivate(): void { /* cleanup */ }
}
```

### 6.2 Extension Rules
- Extensions NEVER modify core files — hooks only
- Extensions create own tables with `jt_pro_` prefix
- Extensions register own REST routes under `jetonomy/v1` namespace
- Extensions enqueue own CSS via `wp_add_inline_style('jetonomy', $css)` — NOT separate files
- Extensions hook into templates via action/filter hooks (e.g., `jetonomy_post_actions`)
- License tier gating via `License::can_use_extension($id)`

### 6.3 Admin Integration
- Extensions add UI into existing admin pages (via hooks), NOT separate pages
- Exception: Analytics gets its own page (large dashboard)
- Settings: add tabs to existing Settings page via `jetonomy_admin_settings_tabs`

---

## 7. Filterable Hooks (Third-Party Integration Points)

| Hook | Type | Purpose |
|---|---|---|
| `jetonomy_profile_url` | filter | Override profile URL (BuddyPress, etc.) |
| `jetonomy_header_logo` | filter | Override header logo HTML (White Label) |
| `jetonomy_admin_menu_label` | filter | Override admin menu label |
| `jetonomy_admin_menu_icon` | filter | Override admin menu icon |
| `jetonomy_show_community_nav` | filter | Hide/show community sub-nav |
| `jetonomy_template_map` | filter | Override/add template paths |
| `jetonomy_check_content` | filter | Pre-insertion content moderation |
| `jetonomy_header_nav_items` | action | Add items to community nav |
| `jetonomy_post_actions` | action | Add buttons below posts |
| `jetonomy_reply_actions` | action | Add buttons below replies |
| `jetonomy_after_post_content` | filter | Inject content after post body |
| `jetonomy_new_post_fields` | action | Add fields to new post form |
| `jetonomy_profile_after_stats` | action | Add content after profile stats |
| `jetonomy_profile_edit_fields` | action | Add fields to profile edit |
| `jetonomy_profile_display_fields` | action | Display custom fields on profile |
| `jetonomy_membership_adapters` | filter | Register membership adapters |
| `jetonomy_importers` | filter | Register import sources |

---

## 8. Testing Standards

### 8.1 Every Feature Must Have
- Unit tests for model logic
- Integration tests for API endpoints
- Permission check tests (allowed + denied)
- Empty state handling
- Error state handling

### 8.2 Test Naming
```php
public function test_create_returns_id(): void
public function test_find_by_slug_returns_null_for_missing(): void
public function test_private_space_denies_non_member(): void
```

---

## 9. Competitor Benchmarks

Before building ANY feature, check how these handle it:

| Platform | URL | What to check |
|---|---|---|
| **Discourse** | discourse.org | Trust levels, moderation, email, search |
| **Circle.so** | circle.so | Community UX, spaces, onboarding |
| **Reddit** | reddit.com | Voting, threading, engagement, scale |
| **Stack Overflow** | stackoverflow.com | Q&A, accepted answers, reputation |
| **Mighty Networks** | mightnetworks.com | Mobile, courses, monetization |
| **Flarum** | flarum.org | Lightweight, extensions, API |
| **NodeBB** | nodebb.org | Real-time, plugins |
| **Bettermode** | bettermode.com | API-first, headless |

---

## 10. File Naming Conventions

```
Models:      class-{name}.php          → class-user-profile.php
Controllers: class-{name}-controller.php → class-posts-controller.php
Adapters:    class-{name}-adapter.php   → class-memberpress-adapter.php
Interfaces:  interface-{name}-adapter.php → interface-membership-adapter.php
Templates:   {name}.php                 → single-post.php
Partials:    {name}.php                 → reply-card.php
```

---

## 11. Git Conventions

- **NO co-author attribution** in commits
- **Prefix**: `feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`
- **One concern per commit** — don't mix features with fixes
- **Push after every logical change** — don't accumulate
