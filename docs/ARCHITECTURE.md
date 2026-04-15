# Jetonomy Architecture

## System Overview

```
                    ┌─────────────────────────────────────────┐
                    │              WordPress Core              │
                    └──────────────┬──────────────────────────┘
                                   │
         ┌─────────────────────────┼──────────────────────────┐
         │                  plugins_loaded                     │
         │                                                     │
    ┌────▼────┐                                          ┌─────▼─────┐
    │ Jetonomy│  ◄── plugins_loaded ──────────────────►  │Jetonomy   │
    │  (free) │                                          │   Pro     │
    │         │  ◄── plugins_loaded:20 ──────────────►   │           │
    └────┬────┘                                          └─────┬─────┘
         │                                                     │
    Singleton                                          Extension loader
    Router                                             Adapter registration
    Models + Schema                                    License gating
    Permissions                                        Queue (Action Scheduler)
    Notifier
    Adapters
```

## Request Lifecycle (Frontend)

```
HTTP request: /community/s/general/t/hello-world/
        │
        ▼
┌──────────────┐
│    Router    │  add_rewrite_rules() → query vars → template_redirect
└──────┬───────┘
       ▼
┌──────────────┐
│Template_Loader│  render($data) → auth gates → access control
└──────┬───────┘
       ▼
┌──────────────┐
│   Template   │  templates/views/single-post.php (or theme override)
└──────┬───────┘
       ▼
┌──────────────┐
│ Interactivity│  @wordpress/interactivity store (voting, sorting, polling)
│   API Store  │  assets/js/view.js
└──────────────┘
```

Theme overrides: `theme/jetonomy/views/*.php` takes priority over `templates/views/*.php`.

`jetonomy_template_map` filter allows extensions to register new routes/templates.

## REST API Lifecycle

```
WP REST dispatch
        │
        ▼
┌──────────────────┐
│ Base_Controller   │  namespace: jetonomy/v1
│                   │  check_permission() → Permission_Engine
│                   │  permission_error() / not_found()
└──────┬───────────┘
       ▼
┌──────────────────┐
│  Model layer     │  find(), insert(), update(), delete(), count()
│                  │  Counter side-effects in create/delete
└──────┬───────────┘
       ▼
┌──────────────────┐
│  Custom MySQL    │  22 tables: wp_jt_* via dbDelta
│  tables          │  Schema: includes/db/class-schema.php
│                  │  Migrations: includes/db/class-migrator.php
└──────────────────┘
```

16 controllers, 43 endpoints. All registered under `jetonomy/v1`.

### Outbound oEmbed (v1.3.0)

Jetonomy threads live in `wp_jt_posts` — not WP CPTs — so core WP oEmbed can't resolve forum URLs. The `OEmbed_Controller` at `/wp-json/jetonomy/v1/oembed` fills the gap: parses `/community/s/{space}/t/{thread}/` URLs, looks up posts by slug with a space-slug collision guard, and returns oEmbed 1.0 JSON (default `type=rich` with a self-contained card, optional `type=link`).

`wp_oembed_add_provider()` registers the pattern on `init` so the WordPress block editor auto-embeds pasted Jetonomy URLs. Social consumers (Slack, Twitter/X, Discord, Facebook) discover the endpoint via the `<link rel="alternate" type="application/json+oembed">` auto-discovery tag emitted on every thread page alongside richer OG + Twitter Card meta.

## Data Layer

Custom MySQL tables (NOT WordPress CPTs). All prefixed `wp_jt_*`.

### Core Tables (22)

| Group | Tables |
|-------|--------|
| Structure | categories, spaces, space_members, space_tags, space_tag_map |
| Content | posts, replies, tags, post_tags, revisions |
| Engagement | votes, flags, subscriptions, read_status |
| Users | user_profiles, user_interests, activity_log |
| Access | restrictions, access_rules, join_requests, invite_links |
| System | notifications |

### Model Base Class

```
Model (abstract)
 ├── table_name()    → string (e.g. "posts")
 ├── table()         → full prefixed name (e.g. "wp_jt_posts")
 ├── find($id)       → ?object
 ├── insert($data)   → int (new ID)
 ├── update($id, $data) → bool
 ├── delete($id)     → bool
 └── count($where)   → int
```

Content stored as sanitized HTML (`wp_kses_post`) with a `content_plain` column for FULLTEXT search.

## Permission System

Three-layer resolver in `Permission_Engine::can( $user_id, $action, $space_id )`:

```
Layer 0: Global Ban Check
  │  Restriction model → is user banned?
  │  If banned → DENY
  ▼
Layer 1: WordPress Capabilities
  │  user_can( $user_id, "jetonomy_{$action}" )
  │  WP admins (manage_options) → ALLOW (bypass remaining)
  ▼
Layer 2: Space Role Check
  │  SpaceMember role for this user in this space
  │  viewer < member < moderator < admin
  │  Each role has a fixed action set (SPACE_ROLE_PERMS)
  ▼
Result: ALLOW or DENY
```

Trust levels (0-5) are separate from space roles. They gate features like messaging (requires TL >= 1) and are evaluated by the cron-based auto-evaluator.

## Adapter Pattern

Universal interfaces for swappable providers:

| Interface | Default Implementation | Purpose |
|-----------|----------------------|---------|
| `Membership_Adapter` | WP Roles | Map external memberships to space access |
| `Search_Adapter` | MySQL FULLTEXT | Pluggable search backend |
| `Realtime_Adapter` | Polling | Live updates (WebSocket-ready) |
| `Email_Adapter` | wp_mail | Email delivery |
| `AI_Adapter` | Ollama | Content moderation, spam detection |

Registered via `Adapter_Registry`. Pro adds WooCommerce, RCP, LearnDash, and Tutor LMS membership adapters.

## Notification System

```
Event hooks                          Channels
  │                                    │
  │  jetonomy_after_create_post    ┌───▼──────┐
  │  jetonomy_after_create_reply   │ Notifier │──► In-app (wp_jt_notifications)
  │  jetonomy_after_vote           │          │──► Email (via Email_Adapter)
  │  jetonomy_reply_accepted       │          │──► Web Push (Pro extension)
  │  jetonomy_trust_level_changed  │          │──► Email Digest (Pro extension)
  │  jetonomy_flag_created         └──────────┘
  │
  ▼
Notifier::register_hooks() listens to all events,
creates Notification records, dispatches to channels.
```

Subscriptions control per-user delivery. Users subscribe to spaces and posts.

## Cron Jobs

| Schedule | Task | Description |
|----------|------|-------------|
| Daily | Cache cleanup | Purge expired transients |
| Daily | Trust evaluation | Recalculate trust levels based on reputation |
| Batch | Import processing | bbPress/wpForo import in chunks |

## Frontend Stack

```
┌─────────────────────────────────────────────────────┐
│  PHP Templates (12 views + 6 partials)              │
│  Theme-overridable via theme/jetonomy/              │
├─────────────────────────────────────────────────────┤
│  WP Interactivity API (assets/js/view.js)           │
│  Stores: voting, sorting, reply actions, polling    │
├─────────────────────────────────────────────────────┤
│  CSS Custom Properties (assets/css/jetonomy.css)    │
│  --jt-* tokens → inherit from theme.json            │
│    - color (accent, text, bg, border, semantic)     │
│    - spacing (--jt-space-1..12)                     │
│    - typography (--jt-text-2xs..3xl, leading-*)     │
│    - radius, motion, trust-level colors             │
│  Dark mode via token reassignment, not per-component│
│  color-mix() with hex fallbacks                     │
├─────────────────────────────────────────────────────┤
│  Theme bridge (includes/integrations/               │
│  class-theme-integration.php)                       │
│  Reads BuddyX / BuddyX Pro / Reign Kirki mods,      │
│  injects --jt-accent, toggles .jt-dark via          │
│  body_class so accent + dark mode auto-match        │
│  the active theme.                                  │
└─────────────────────────────────────────────────────┘
```

**Design system source of truth**: `docs/DESIGN-SYSTEM.md` covers breakpoints, typography scale, component patterns, tap-target rules, and the 5 responsive anti-patterns review will reject. Every UI change must follow it or update it explicitly.

## Pro Integration

```
plugins_loaded        plugins_loaded:20
      │                       │
  Jetonomy::instance()   Jetonomy_Pro::instance()
      │                       │
  fires hooks ──────────► Extension auto-discovery
  registers adapters         includes/extensions/*/class-extension.php
  exposes filters            dir name → PascalCase namespace
                             │
                       License::can_use_extension($id)
                             │
                        boot() called only when:
                          1. Extension is in jetonomy_pro_extensions option
                          2. License tier permits it
                             │
                        Extensions register:
                          - REST routes (rest_api_init)
                          - Template hooks (jetonomy_* actions/filters)
                          - Adapter overrides
                          - Background jobs via Queue class
```

## Key Hooks for Extension Authors

### Actions

| Hook | Args | Fired when |
|------|------|------------|
| `jetonomy_after_create_post` | `$post_id, $space_id` | New post created |
| `jetonomy_after_create_reply` | `$reply_id, $post_id` | New reply created |
| `jetonomy_after_vote` | `$type, $object_id, $user_id` | Vote cast |
| `jetonomy_reply_accepted` | `$reply_id, $post_id` | Reply marked as accepted |
| `jetonomy_reputation_changed` | `$user_id, $action, $delta` | Reputation score changed |
| `jetonomy_trust_level_changed` | `$user_id, $old, $new` | Trust level promoted/demoted |
| `jetonomy_content_moderated` | `$action, $type, $id, $mod_id` | Content approved/spam/trash |
| `jetonomy_user_joined_space` | `$space_id, $user_id, $role` | User joins a space |
| `jetonomy_notification_created` | `$notif_id, $user_id, $type, ...` | Notification dispatched |
| `jetonomy_header_nav_items` | (none) | Render extra nav items |
| `jetonomy_post_actions` | `$post` | Render post action buttons |
| `jetonomy_reply_actions` | `$reply` | Render reply action buttons |
| `jetonomy_new_post_fields` | `$space` | Extra fields in new post form |
| `jetonomy_before_content` | `$data` | Before template content |
| `jetonomy_after_content` | `$data` | After template content |
| `jetonomy_sidebar_before` | `$space\|null` | Top of sidebar, before widgets |
| `jetonomy_sidebar_after_about` | `$space` | After the About card (space pages only) |
| `jetonomy_sidebar_after` | `$space\|null` | Bottom of sidebar, after widgets |
| `jetonomy_after_post_article` | `$post` | After post `<article>`, before replies |
| `jetonomy_before_replies` | `$post, $total_replies` | Above replies list (inside replies section) |
| `jetonomy_between_replies` | `$reply, $index, $post` | After each top-level reply in the list |
| `jetonomy_after_replies` | `$post, $total_replies` | Below replies list, above composer |

### Filters

| Filter | Purpose |
|--------|---------|
| `jetonomy_template_map` | Register new route/template mappings |
| `jetonomy_check_content` | Content moderation (return action or null) |
| `jetonomy_after_post_content` | Append HTML after post body |
| `jetonomy_show_sidebar` | Toggle sidebar visibility |
| `jetonomy_show_community_nav` | Toggle community nav bar |
| `jetonomy_importers` | Register custom importers |
| `jetonomy_search_query_args` | Modify search query params |
| `jetonomy_notification_email_headers` | Customize email headers |
| `jetonomy_admin_menu_label` | Change admin menu label |
| `jetonomy_profile_url` | Override profile URL generation |
