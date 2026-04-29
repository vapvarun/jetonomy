# REST Access Matrix — login vs logout, by role

**Generated:** 2026-04-29
**Source:** `audit/manifest.json` (schema v2 from A2)
**Purpose:** Authoritative contract for who can call what. Used as a regression smoke before/after every package commit in 1.4.1.

## Why this exists

Phase A1 confirmed the source code enforces correct auth. But the manifest data alone doesn't tell a reviewer "as Bob, a logged-out visitor, what can I do?" or "as Carol, a subscriber, what's blocked?". This file is that table — explicit per-role expected behavior.

Every code change in 1.4.1 must run [`bin/access-matrix-check.sh`](#runner-script) before and after. Any divergence from the expected column = blocked merge.

## Role taxonomy (test fixtures)

| Symbol | Role | Test user | Cookie source |
|---|---|---|---|
| 🔓 | Anonymous | none | no auth header |
| 👤 | Subscriber | `test_subscriber` | `wp user generate-cookie test_subscriber` |
| ✍️ | Author / member | `test_author` | same |
| 📝 | Editor / space-admin | `test_editor` | same |
| 🛡️ | Moderator (`jetonomy_moderate`) | `test_moderator` | same |
| 👑 | Admin (`manage_options`) | `test_admin` | same |

Test users created with `bin/seed-qa-users.php` (to be added — see runner script below).

## Reading the matrix

Each row is one route+method. Cells:

- ✅ **200/201** — legitimate flow returns success
- ➡️ **302** — redirect (typically post-action)
- 🔒 **401/403** — access denied (expected)
- ❌ **500/exception** — broken; THIS IS A BUG and must be fixed before merge
- — **N/A** — not applicable (e.g., no resource owned)

For routes that depend on resource ownership (e.g., `PATCH /posts/{id}`), the matrix has two sub-rows: "owner of resource" vs "not owner".

---

## Public read endpoints (anyone can call; visibility filtering inside)

| Route | Method | 🔓 Anon | 👤 Sub | ✍️ Author | 📝 Editor | 🛡️ Mod | 👑 Admin |
|---|---|---|---|---|---|---|---|
| `/auth/verify-email` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/categories` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/categories/{id}` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/leaderboards` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/link-preview` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/oembed` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/posts/{id}` | GET (public space post) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/posts/{id}` | GET (private space post) | 🔒 403 | 🔒 403 | ✅ 200 if member | ✅ 200 | ✅ 200 | ✅ 200 |
| `/replies/{id}` | GET (public) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/search` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/space-tags` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces` | GET | ✅ 200 (public spaces only) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces/{id}` | GET (public) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces/{id}` | GET (private) | 🔒 403 | 🔒 403 | ✅ 200 if member | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces/{id}/members` | GET (public) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces/{id}/posts` | GET (public) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces/{id}/privileged-members` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces/{id}/join-requests` | GET | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 if space-admin | ✅ 200 | ✅ 200 |
| `/tags` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/tags/{id}` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/updates` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/users/by-login/{login}` | GET | ✅ 200 (public profile) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/users/{id}` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/users/{id}/posts` | GET | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/users/suggest` | GET | 🔒 403 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |

## Public rate-limited (anonymous-only flow before login)

These are the only routes accepting anonymous mutations. **All must be IP rate-limited.** A3 verifies the rate limit is in place.

| Route | Method | 🔓 Anon | 👤 Sub+ | Notes |
|---|---|---|---|---|
| `/auth/login` | POST | ✅ 200 (creates session) | ➡️ already logged in (handler returns existing session or 200) | A3: verify check_rate_limit('login') is IP-based |
| `/auth/register` | POST | ✅ 201 (creates user) | 🔒 403 (already logged in) | A3: verify check_rate_limit('register') is IP-based |
| `/auth/lost-password` | POST | ✅ 200 (always — must NOT reveal if email exists) | ✅ 200 | A3: verify response identical for known + unknown emails |
| `/auth/resend-verification` | POST | ✅ 200 (rate-limited 3/5min) | ✅ 200 | Rate limit confirmed in A1 |

## Login-required, no special cap

| Route | Method | 🔓 Anon | 👤 Sub | ✍️ Author | 📝 Editor | 🛡️ Mod | 👑 Admin |
|---|---|---|---|---|---|---|---|
| `/auth/logout` | POST | 🔒 401 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/bookmarks` | GET | 🔒 401 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/bookmarks` | POST | 🔒 401 | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 |
| `/bookmarks/{post_id}` | DELETE | 🔒 401 | ✅ 200 (own) / 🔒 403 (others) | same | same | same | same |
| `/notifications` | GET | 🔒 401 | ✅ 200 (own only) | same | same | same | same |
| `/notifications/unread-count` | GET | 🔒 401 | ✅ 200 | same | same | same | same |
| `/notifications/mark-all-read` | POST | 🔒 401 | ✅ 200 | same | same | same | same |
| `/notifications/{id}` | PATCH | 🔒 401 | ✅ 200 (own) / 🔒 403 (others) | same | same | same | same |
| `/subscriptions` | GET | 🔒 401 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/subscriptions` | POST | 🔒 401 | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 |
| `/subscriptions/{id}` | DELETE | 🔒 401 | ✅ 200 (own) / 🔒 403 | same | same | same | same |
| `/users/me` | GET | 🔒 401 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/users/{id}` | PATCH | 🔒 401 | ✅ 200 (own) / 🔒 403 (others) | same | same | same | ✅ 200 (any user via admin cap) |
| `/votes` | POST | 🔒 401 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/votes/{id}` | DELETE | 🔒 401 | ✅ 200 (own) / 🔒 403 | same | same | same | same |
| `/media/upload` | POST | 🔒 401 | 🔒 403 (no upload cap) | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 |

## Login + capability or membership gated

| Route | Method | 🔓 Anon | 👤 Sub | ✍️ Author | 📝 Editor | 🛡️ Mod | 👑 Admin |
|---|---|---|---|---|---|---|---|
| `/categories` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 201 |
| `/categories/{id}` | PATCH | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 |
| `/categories/{id}` | DELETE | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 |
| `/tags` | POST | 🔒 401 | 🔒 403 | ✅ 201 (or 403; see manage_settings) | ✅ 201 | ✅ 201 | ✅ 201 |
| `/tags/{id}` | PATCH | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 |
| `/tags/{id}` | DELETE | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 |
| `/spaces` | POST | 🔒 401 | 🔒 403 (no `jetonomy_create_spaces`) | depends on settings allowed_roles | ✅ 201 | ✅ 201 | ✅ 201 |
| `/spaces/{id}` | PATCH | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-admin | ✅ 200 | ✅ 200 |
| `/spaces/{id}` | DELETE | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-admin | ✅ 200 | ✅ 200 |
| `/spaces/{id}/members` | POST (join) | 🔒 401 | ✅ 200/201 (join_policy permitting) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces/{id}/members/{user_id}` | PATCH | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-admin | ✅ 200 | ✅ 200 |
| `/spaces/{id}/members/{user_id}` | DELETE | 🔒 401 | ✅ 200 (self) / 🔒 403 (others) | same | ✅ 200 if space-admin | ✅ 200 | ✅ 200 |
| `/spaces/{id}/invite` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 201 if space-admin | ✅ 201 | ✅ 201 |
| `/invite/{token}` | GET | ✅ 200 (preview) | ✅ 200 (preview + join) | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/spaces/{space_id}/posts` | POST | 🔒 401 | ✅ 201 (member of space) | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 |
| `/posts/{id}` | PATCH | 🔒 401 | ✅ 200 (own) / 🔒 403 (others) | ✅ 200 (own) / 🔒 403 | ✅ 200 (any) | ✅ 200 | ✅ 200 |
| `/posts/{id}` | DELETE | 🔒 401 | ✅ 200 (own) / 🔒 403 | ✅ 200 (own) / 🔒 403 | ✅ 200 (any) | ✅ 200 | ✅ 200 |
| `/posts/{id}/close` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if mod/admin | ✅ 200 | ✅ 200 |
| `/posts/{id}/pin` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if mod/admin | ✅ 200 | ✅ 200 |
| `/posts/{id}/move` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if mod | ✅ 200 | ✅ 200 |
| `/posts/{id}/merge` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if mod | ✅ 200 | ✅ 200 |
| `/posts/{id}/vote` | POST | 🔒 401 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/posts/{post_id}/replies` | POST | 🔒 401 | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 |
| `/replies/{id}` | PATCH | 🔒 401 | ✅ 200 (own) / 🔒 403 | ✅ 200 (own) / 🔒 403 | ✅ 200 (any) | ✅ 200 | ✅ 200 |
| `/replies/{id}` | DELETE | 🔒 401 | ✅ 200 (own) / 🔒 403 | same | ✅ 200 (any) | ✅ 200 | ✅ 200 |
| `/replies/{id}/accept` | POST | 🔒 401 | ✅ 200 (post-author) / 🔒 403 | same | ✅ 200 | ✅ 200 | ✅ 200 |
| `/replies/{id}/split` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if mod | ✅ 200 | ✅ 200 |
| `/replies/{id}/vote` | POST | 🔒 401 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 | ✅ 200 |
| `/users/{id}/ban` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 if mod (target ≠ admin) | ✅ 200 |
| `/users/{id}/unban` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/users/{id}/trust-level` (admin) | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 |
| `/admin/recount` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 |
| `/admin/users/trust-level` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 |
| `/admin/settings` | GET, POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 |

## Moderation routes (`jetonomy_moderate` cap)

| Route | Method | 🔓 Anon | 👤 Sub | ✍️ Author | 📝 Editor | 🛡️ Mod | 👑 Admin |
|---|---|---|---|---|---|---|---|
| `/flags` | GET | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/flags` | POST (create flag) | 🔒 401 | ✅ 201 (`jetonomy_flag` cap) | ✅ 201 | ✅ 201 | ✅ 201 | ✅ 201 |
| `/moderation/queue` | GET | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/moderation/approve/{type}/{id}` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/moderation/spam/{type}/{id}` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/moderation/trash/{type}/{id}` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/moderation/flags` | GET | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/moderation/flags/{id}/resolve` | POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/moderation/ban` | GET, POST | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/moderation/ban/{id}` | DELETE | 🔒 401 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ 200 | ✅ 200 |
| `/spaces/{id}/moderation/flags` | GET | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-mod | ✅ 200 | ✅ 200 |
| `/spaces/{id}/moderation/flags/{flag_id}/resolve` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-mod | ✅ 200 | ✅ 200 |
| `/spaces/{id}/moderation/{action}/{type}/{obj_id}` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-mod | ✅ 200 | ✅ 200 |
| `/space-moderation/queue` | GET | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-mod | ✅ 200 | ✅ 200 |
| `/space-moderation/{id}/approve` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-mod | ✅ 200 | ✅ 200 |
| `/space-moderation/{id}/spam` | POST | 🔒 401 | 🔒 403 | 🔒 403 | ✅ 200 if space-mod | ✅ 200 | ✅ 200 |

---

## AJAX (always login + admin cap)

All AJAX handlers require both login and a specific cap. Anonymous requests return `wp_die()` with -1; non-cap-holders return `403` from `check_ajax_referer` failure.

| Action | Required cap |
|---|---|
| `jetonomy_create_category` / `_update_category` / `_delete_category` / `_reorder_categories` | `jetonomy_manage_categories` |
| `jetonomy_create_tag` / `_update_tag` / `_delete_tag` / `_bulk_delete_tags` | `jetonomy_manage_settings` |
| `jetonomy_create_space` / `_update_space` / `_delete_space` / `_*_member` / `_*_role` / `_*_access_rule` | `jetonomy_manage_spaces` |
| `jetonomy_approve_content` / `_spam_content` / `_trash_content` / `_resolve_flag` / `_bulk_content_action` | `jetonomy_moderate` |
| `jetonomy_ban_user` / `_unban_user` / `_change_trust_level` | `jetonomy_manage_users` |
| `jetonomy_search_users` / `_test_email` / `_email_preview` / `_flush_rules` | `jetonomy_manage_settings` |
| `jetonomy_run_import` / `_import_batch` / `_import_progress` | `jetonomy_manage_settings` |
| `jetonomy_update_post` / `_delete_post` / `_update_reply` / `_delete_reply` / `_get_replies` | `jetonomy_manage_settings` |
| `jetonomy_approve_join_request` / `_deny_join_request` | `jetonomy_manage_spaces` |
| `jetonomy_setup_save` / `_setup_create_sample` / `_cleanup_sample_data` | `manage_options` |

**For 🔓 Anon and 👤 Subscriber**: every AJAX action above returns `0` or `403`.

## Admin pages (`is_super_admin` or specific cap)

Loaded via `add_menu_page` / `add_submenu_page`. WordPress core enforces the `capability` argument before rendering.

| Slug | Cap | Anon | Sub | Author | Editor | Mod | Admin |
|---|---|---|---|---|---|---|---|
| `jetonomy` (top-level) | `jetonomy_manage_settings` | redirect to login | 🔒 403 | 🔒 403 | 🔒 403 | 🔒 403 | ✅ |
| `jetonomy-categories` / `-tags` / `-spaces` / `-content` / `-users` / `-import` / `-settings` / `-extensions` | `jetonomy_manage_settings` | login | 🔒 | 🔒 | 🔒 | 🔒 | ✅ |
| `jetonomy-moderation` | `jetonomy_moderate` | login | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `jetonomy-setup` | `manage_options` | login | 🔒 | 🔒 | 🔒 | 🔒 | ✅ |

---

## Frontend pages (templates loaded via Router)

These have NO REST gate but the templates render conditionally based on capability. Verify the rendered HTML differs by role:

| Page | What 🔓 Anon sees | What logged-in users see | Admin extras |
|---|---|---|---|
| `/community/` (home) | Public spaces feed, "log in to participate" CTA | Member feed (subscribed spaces), compose button | Same |
| `/community/?tab=spaces` | Public spaces grid | All visible (incl. subscribed private) | All including hidden (admin sees all) |
| `/community/?tab=notifications` | Redirect to login | Personal notifications inbox | Same |
| `/community/?tab=bookmarks` (A9) | Redirect to login | Personal bookmarks list | Same |
| `/community/?tab=drafts` (A9) | Redirect to login | Personal drafts list | Same |
| `/community/spaces/{slug}` (public) | Read content, no compose | Read + compose if member | Read + compose + admin actions |
| `/community/spaces/{slug}` (private, non-member) | "Members only" message + join button | Same as anon | Full access (admin override) |
| `/community/posts/{id}` (public) | Read | Read + reply + vote | Read + reply + vote + delete/pin/close |
| `/community/posts/{id}` (private, non-member) | "Members only" | Same | Full access |
| `/community/users/{slug}` | Public profile | Same + own-profile edit button if self | Same |
| `/community/moderation` | 🔒 redirect | 🔒 redirect | ✅ if mod or admin |

---

## Runner script

Save as `bin/access-matrix-check.sh` (TBD — A3 deliverable). Iterates every row of this file, makes the HTTP call, asserts the response code matches the expected cell. Outputs:

```
PASS  GET   /spaces                 [anon]    expected=200  got=200
PASS  POST  /spaces                 [anon]    expected=401  got=401
FAIL  POST  /posts/123              [author2] expected=403  got=200  ← regression
PASS  ...
```

Commit it under `bin/` so it's part of the release-build chain. Run it:
- After each commit on `1.4.1` (manual or hooked)
- As part of `/jetonomy-smoke` execution
- Before merging to `main`

---

## How this matrix is maintained

- **A2 already populated** the per-route `auth`, `capability`, `ownership_check` fields in `audit/manifest.json`. Every refresh re-confirms.
- **This matrix is hand-curated** (the role × method permutations don't fit the current schema). Update on:
  - New REST endpoint added → add row immediately
  - Existing route changes its auth → update row
  - New role / cap introduced → add column
- **Anti-staleness check**: `bin/access-matrix-check.sh` flags any route in `audit/manifest.json` not present in this matrix (and vice versa).

## Where this lives

`plan/REST_ACCESS_MATRIX.md` — alongside the release plan since it's a living document tied to 1.4.1's safety contract. Promoted to `audit/` once schema can express the per-role permutations natively (likely 1.5.0).
