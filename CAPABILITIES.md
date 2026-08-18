# Jetonomy - Capabilities

Buyer-level roll-up: what this plugin can actually do, in the words a site owner
would use. The [manifest](audit/manifest.json) lists the parts (80 REST routes,
225 hooks, 22 tables); this file says what the parts add up to.

Every row is verified against code, with the file that delivers it. Status
values: **YES** shipped and complete, **YES-beta** shipped but young,
**PARTIAL** works with a real caveat stated in the row, **NO** not in the free
plugin (Pro rows live in `../jetonomy-pro/CAPABILITIES.md`).

Verified 2026-08-18 against 1.9.3. When you add or remove a capability, update
this file in the same commit.

## Running a community

| Can it... | Status | Delivered by |
|---|---|---|
| Run a classic threaded forum | YES | `includes/models/class-post.php`, `class-reply.php` |
| Run a Q&A space where answers are voted and one is accepted | YES | `accepted_reply_id` on posts, `includes/models/class-post.php:329` |
| Run an ideas board with a status roadmap | YES | `includes/api/class-posts-controller.php` (idea-status route), roadmap view in `templates/views/` |
| Run a lightweight social feed | YES | `includes/class-feed.php`, `includes/api/class-feed-controller.php` |
| Organise content into categories and sub-communities (spaces) | YES | `includes/models/class-category.php`, `class-space.php` |
| Nest spaces inside spaces | YES | parent handling in `includes/models/class-space.php` |

## Access, membership and privacy

| Can it... | Status | Delivered by |
|---|---|---|
| Make a space public, private or hidden | YES | `includes/class-visibility.php` |
| Require approval or an invite to join a space | YES | `includes/models/class-join-request.php`, `class-invite-link.php` |
| Generate and revoke invite links from the front end | YES | `includes/api/class-spaces-controller.php` (invites routes) |
| Remove a space without destroying other members' topics and replies | YES | `DELETE /spaces/{id}` defaults to `mode=transfer`; `includes/models/class-space.php` (`hand_over`, `resolve_successor`) |
| Permanently delete a space and everything in it, as a controlled opt-in | YES | `mode=purge` gated on `allow_space_admin_purge`; `includes/class-space-purge.php` batches it through Action Scheduler |
| Keep a space manageable when its owner deletes their account | YES | `includes/class-privacy.php` transfers to a surviving space admin, or parks it with the site admin |
| Order spaces deliberately within a category | YES | `sort_order` on `POST`/`PATCH /spaces`, drag-reorder in `includes/admin/views/spaces.php` |
| Gate a space behind a membership plan | YES | `includes/adapters/interface-membership-adapter.php` with MemberPress (`class-member-press-adapter.php`) and Paid Memberships Pro (`class-pmpro-adapter.php`) |
| Gate on any other membership plugin | PARTIAL | The adapter interface is public and Pro ships more adapters; free ships MemberPress and PMPro only. Anything else needs an adapter. |
| Tell a blocked visitor which plan opens the space | YES | 1.9.0 upgrade-link path, `jetonomy_membership_upgrade_url` |
| Let members delete their own account and data | YES | `DELETE /users/me`, `includes/class-privacy.php` |
| Answer a GDPR export/erase request | YES | `includes/class-privacy.php:16` registers WP core exporters and erasers |

## Moderation and trust

| Can it... | Status | Delivered by |
|---|---|---|
| Give moderators a queue of flagged content | YES | `includes/api/class-moderation-controller.php`, `/community/mod/` |
| Let a space have its own moderators, separate from site admins | YES | `includes/api/class-space-moderation-controller.php`, space roles |
| Stop members publishing under a reserved or impersonating name | YES | `includes/functions.php` (`display_name_choices`, `is_reserved_display_name`), enforced in `PATCH /users/me` |
| Let members choose a public name from their own fields, WordPress-style | YES | `templates/views/edit-profile.php` first/last/nickname plus a "Display name publicly as" select |
| Lock member names to administrators | YES | `lock_member_names` setting, enforced in `includes/api/class-users-controller.php` |
| Rename every member on screen without touching stored data | YES | `jetonomy_user_display_name` filter, honoured by ~39 display surfaces |
| Agree with a partner plugin on what an @handle is | YES | `jetonomy_user_handle` (emit) paired with `jetonomy_resolve_mention_handles` (resolve), both on `user_nicename` |
| Ban or silence a member | YES | `includes/models/class-restriction.php` (ban blocks login, silence blocks posting) |
| Auto-promote members as they participate | YES | Trust levels 0-5, `includes/trust/class-trust-evaluator.php` |
| Catch spam automatically | PARTIAL | Akismet is wired in free; AI-based detection is Pro. |
| Show who changed what | YES | `includes/models/class-activity-log.php`, `class-revision.php` |

## Migrating in

| Can it... | Status | Delivered by |
|---|---|---|
| Import from bbPress | YES | `includes/import/class-bbpress-importer.php` |
| Import from wpForo | YES | `includes/import/class-wpforo-importer.php` |
| Import from Asgaros | YES | `includes/import/class-asgaros-importer.php` |
| Import attachments and hierarchy, not just text | YES | 1.8.0; the importers link media through `includes/models/class-attachment.php` |
| Resume a large import without duplicating | YES | Batched with progress, `includes/import/class-importer.php` |

## Platform and extensibility

| Can it... | Status | Delivered by |
|---|---|---|
| Be driven entirely over REST | YES | 80 routes under `jetonomy/v1`, `includes/api/` |
| Back a mobile app | YES | `includes/api/class-app-config-controller.php`; app sign-in via Application Password, `includes/integrations/class-app-connect.php` |
| Be automated from the terminal | YES | 14 WP-CLI command roots, `includes/cli/` |
| Be extended without forking | YES | 214 hooks (102 actions, 112 filters), see `docs/website/developer-guide/02a-hooks-index.md` |
| Be themed to match the site | YES | `--jt-*` token layer in `assets/css/jetonomy-tokens.css`, adopts the host theme's brand colour |
| Have its templates overridden | YES | `includes/class-template-loader.php`, drop files in `your-theme/jetonomy/` |
| Work right-to-left | YES | Logical CSS properties plus generated `*-rtl.css` |
| Be used through the block editor | YES | 8 blocks, 8 shortcodes |
| Expose itself to AI agents | YES | 19 abilities via the WordPress Abilities API (WP 6.9+), `includes/class-abilities.php` |

## Scale and operations

| Can it... | Status | Delivered by |
|---|---|---|
| Hold a large community without slowing down | YES | 22 purpose-built tables with indexes and denormalised counters, not `wp_posts`; `includes/db/class-schema.php` |
| Paginate large lists cheaply | YES | Cursor-based pagination on list endpoints |
| Use Redis or Memcached when present | YES | `includes/class-cache.php` (auto-detects an external object cache) |
| Survive an upgrade without manual DB work | YES | Versioned migrations, `includes/db/class-migrator.php` |
| Be checked before release | YES | `wp jetonomy qa-actions` (259 live checks), 476 unit tests, six CI jobs - see README "Quality gates" |

## Not in free

Private messaging, emoji reactions, polls, custom fields, custom badges,
analytics, AI moderation, webhooks, web push, email digest, reply-by-email, SEO
Pro, white label, advanced moderation, anonymous posting, attachment uploads and
site announcements are Jetonomy Pro. See `../jetonomy-pro/CAPABILITIES.md`.

Attachment *display* is free (1.8.0) - a site that drops Pro keeps showing files
already attached; it loses the upload composer, lightbox, PDF viewer and limits.
