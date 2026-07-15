# Jetonomy REST API Reference

Generated from the Jetonomy 1.8.0 (free) source by reading every `register_rest_route()` call in `includes/api/*.php`, and from Jetonomy Pro's `includes/extensions/*/class-*.php`. This is the human-readable companion to [`openapi.json`](./openapi.json) — the OpenAPI 3.1 file is the authoritative, machine-readable contract (exact schemas, every parameter's type/enum/default). Load `openapi.json` into [Swagger UI](https://swagger.io/tools/swagger-ui/) or [Redoc](https://github.com/Redocly/redoc) for an interactive browser.

## Base URLs

| Namespace | Base URL | Used by |
|---|---|---|
| `jetonomy/v1` | `https://your-site.com/wp-json/jetonomy/v1` | Free core, and most Pro extensions (Private Messaging, Analytics, Polls, Reactions, Custom Fields, Custom Badges, Webhooks, White Label, Web Push, AI, Advanced Moderation, Email Digest, SEO Pro, Reply By Email, Anonymous Posting, and the public `GET /announcements/active` read) |
| `jetonomy-pro/v1` | `https://your-site.com/wp-json/jetonomy-pro/v1` | Pro's **Attachments** extension (`/attachments*`), and the Site Announcements **admin/management** routes (`/site-announcements`, `/site-announcements/{id}`) |

The `jetonomy-pro/v1` split is not documented anywhere in the plugin's own manifest notes — it was found by reading `includes/extensions/attachments/class-rest.php` and `includes/extensions/site-announcements/class-extension.php` directly. Every other Pro extension registers into the shared `jetonomy/v1` namespace.

## Authentication

Jetonomy supports two authentication modes, both handled by `\Jetonomy\API\REST_Auth::auth_mutation()` (free) and its lazy Pro wrapper `Extension::rest_auth_mutation()`:

### 1. Cookie + nonce (the web app)

The browser holds the WordPress `logged_in` session cookie. Every **mutating** request (`POST`/`PATCH`/`DELETE`) additionally requires an `X-WP-Nonce` header carrying a valid `wp_rest` nonce:

```
X-WP-Nonce: <nonce>
```

Get one from `wp_create_nonce('wp_rest')` server-side (localized into the page on load), or refresh it client-side via:

```
GET /wp-json/jetonomy/v1/auth/nonce
```

A missing/invalid nonce on a cookie-authenticated request returns `403 rest_cookie_invalid_nonce`. Cookie-authenticated requests are detected by the presence of `$_COOKIE` **and the absence** of an `Authorization` header — see the next section.

### 2. Application Passwords (the mobile app + external integrations)

WordPress core [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) sent as HTTP Basic auth:

```
Authorization: Basic base64(username:application_password)
```

Header-authenticated requests skip the cookie-nonce check entirely (Application Passwords authenticate per-request and don't carry a WP session). They still go through the same login, ban/silence, and pending-email-verification gates as cookie auth — `REST_Auth::auth_mutation()` is the single choke point for both.

### Account-status gates (both modes)

On every mutation route, `auth_mutation()` also enforces:

- **Banned accounts** are rejected (`403 jetonomy_user_banned`) — closes an Application Password bypass that existed before 1.6.0, where a banned member's `authenticate` filter never ran for header-authed requests.
- **Pending-email-verification accounts** are rejected (`403 jetonomy_pending_verification`) unless the route explicitly opts out (`allow_unverified`) — currently only `DELETE /users/me` (a member must always be able to delete an account they created, verified or not).

### Public / private community mode

Independent of the above, `Settings → General → Community Access` toggles the whole community between **public** (default — anyone can read) and **private** (every read requires login). This is enforced by `\Jetonomy\Visibility::rest_check()` and wraps almost every `GET` route's `permission_callback`. In private mode, a logged-out `GET` returns `401 community_private` instead of the resource. `/auth/*` and most `/moderation/*`/`/admin/*` routes are exempt (they have their own gates and must never lock users out).

### Rate limiting

`POST /auth/login`, `/auth/register`, `/auth/lost-password`, `/auth/resend-verification`, and `DELETE /users/me` are all IP-bucketed via a shared `Base_Controller::check_rate_limit()` transient (login: 5/min; register: 5/min; lost-password & resend-verification: 3/5min; delete-account: 5/hour). Exceeded limits return `429 jetonomy_rate_limited`.

## Pagination

Two shapes appear across the API, both bounded (no unbounded `SELECT *`):

- **Offset-based** (`limit` + `offset`, most list endpoints): response body is `{ "data": [...], "meta": { "count", "has_more", "cursor_next", "total", "offset" } }`. `X-WP-Total` and `X-WP-TotalPages` headers are set on most collection routes.
- **Cursor-based** (`after`/`before` on posts/replies/messages): the base `get_collection_params()` schema declares `after`/`before` alongside legacy `offset` — cursor is preferred for large datasets since `OFFSET` on a 100k-row table degrades linearly.

Default `limit` is usually 20 (search defaults 20, capped 50; leaderboards default 20, capped 100; media default 24). Always check the specific endpoint's `args` schema in `openapi.json` for the real min/max/default.

## Error shape

Every error is a standard `WP_Error`-shaped JSON body:

```json
{ "code": "jetonomy_forbidden", "message": "You do not have permission to perform this action.", "data": { "status": 403 } }
```

## Resources

### Spaces

Sub-communities. `POST /spaces` requires `manage_options` OR `jetonomy_create_spaces` OR a WP role the admin opted into (`Settings → General → frontend_space_creation_roles`). Space-level writes (`PATCH`/`DELETE` on a space, member-role changes, invites) require the caller to hold the `admin` per-space role (independent of WP capabilities) or `manage_options`.

```
GET    /spaces                                          List (visibility-filtered)
POST   /spaces                                           Create
GET    /spaces/{id}                                       Get
PATCH  /spaces/{id}                                        Update (space admin)
DELETE /spaces/{id}                                        Delete (space admin)
GET    /spaces/{id}/members                                List members
POST   /spaces/{id}/members                                Join
GET    /spaces/{id}/privileged-members                     Admins/moderators (public read)
GET    /spaces/{id}/join-requests                          Pending requests (space mod)
POST   /spaces/{id}/join-requests/{request_id}/approve      Approve
POST   /spaces/{id}/join-requests/{request_id}/deny         Deny
PATCH  /spaces/{id}/members/{user_id}                      Change role (space admin)
DELETE /spaces/{id}/members/{user_id}                       Leave/remove
POST   /spaces/{id}/invite                                  Generate invite link (space admin)
GET    /invite/{token}                                     Accept invite
```

Example — create a space:

```bash
curl -X POST https://your-site.com/wp-json/jetonomy/v1/spaces \
  -H "Content-Type: application/json" -H "X-WP-Nonce: $NONCE" --cookie "$COOKIES" \
  -d '{"title":"Product Feedback","type":"ideas","visibility":"public","join_policy":"open"}'
```

```json
{ "id": 42, "title": "Product Feedback", "slug": "product-feedback", "type": "ideas", "visibility": "public", "join_policy": "open", "member_count": 1, "post_count": 0, "is_member": true, "viewer_role": "admin", "..." : "..." }
```

### Posts

Topics/questions/ideas/status-updates, always nested under a space for create/list. 1.8.0 adds an `attachments` array to every post payload (free, whether or not Pro is active — see below).

```
GET    /spaces/{space_id}/posts    List (space-scoped, sort=latest|popular|oldest|newest|unanswered)
POST   /spaces/{space_id}/posts    Create
GET    /posts/drafts               My drafts
GET    /posts/{id}                 Get
PATCH  /posts/{id}                  Update / publish a draft
DELETE /posts/{id}                  Trash
POST   /posts/{id}/close            Close/reopen to new replies
POST   /posts/{id}/pin              Pin/unpin (capped per space)
POST   /posts/{id}/move             Move to another space
POST   /posts/{id}/merge            Merge into another post
POST   /posts/{id}/idea-status      Set roadmap status (Ideas spaces only)
POST   /posts/{id}/vote             Vote
DELETE /posts/{id}/vote              Remove my vote
GET    /link-preview                OG metadata for a URL
```

Example response fragment (`GET /posts/{id}`), showing the 1.8.0 `attachments` shape:

```json
{
  "id": 501, "title": "How do I configure SSO?", "type": "question", "status": "publish",
  "content": "<p>...</p>", "viewer_vote": 0, "is_bookmarked": false,
  "attachments": [
    { "id": 88, "link_id": 12, "url": "https://.../screenshot.png", "thumb": "https://.../screenshot-300x200.png",
      "mime": "image/png", "name": "screenshot.png", "size": 84213, "type": "image", "ext": "PNG", "is_image": true }
  ]
}
```

### Replies

Threaded, up to 3 levels via `parent_id`, nested under a post for create/list.

```
GET    /posts/{post_id}/replies    List (sort=oldest|newest|best)
POST   /posts/{post_id}/replies    Create
PATCH  /replies/{id}               Update (no GET on this route)
DELETE /replies/{id}               Trash
POST   /replies/{id}/accept        Mark accepted answer (Q&A spaces)
DELETE /replies/{id}/accept        Un-accept
POST   /replies/{id}/split         Split into a new post (moderator)
POST   /replies/{id}/vote          Vote
DELETE /replies/{id}/vote           Remove my vote
```

### Users

```
GET    /users/me                   My full profile
PATCH  /users/me                    Update my profile
DELETE /users/me                    Delete my account (Apple 5.1.1(v) / GDPR Art. 17)
GET    /users/{id}                 Public profile by ID
GET    /users/by-login/{login}     Public profile by username
GET    /users/{id}/posts           A user's public posts
GET    /users/suggest              @mention typeahead (login required)
```

`DELETE /users/me` example:

```bash
curl -X DELETE https://your-site.com/wp-json/jetonomy/v1/users/me \
  -H "Content-Type: application/json" -H "X-WP-Nonce: $NONCE" --cookie "$COOKIES" \
  -d '{"password":"correct horse battery staple","confirm":"DELETE","delete_content":false}'
```

```json
{ "deleted": true, "user_id": 88, "content_policy": "anonymized" }
```

By default content is **anonymized** (author reassigned to a `0`/tombstone; existing replies still render, showing `[deleted]`). Pass `delete_content: true` to hard-delete every post/reply the member authored instead.

### Blocks (member-to-member blocking)

```
GET    /users/me/blocks              List who I've blocked
POST   /users/me/blocks              Block a user ({user_id})
DELETE /users/me/blocks/{user_id}     Unblock (idempotent)
```

### Auth

```
POST /auth/login                 Sign in (rate-limited)
POST /auth/register              Create an account (rate-limited, anti-spam)
POST /auth/lost-password         Request a reset email (rate-limited)
GET  /auth/verify-email          Consume an email verification link
GET  /auth/nonce                 Fresh wp_rest nonce for this session
POST /auth/resend-verification   Resend the verification link (rate-limited)
```

### Notifications

```
GET    /notifications                     List (filter=all|unread|mentions|replies|votes|badges)
GET    /notifications/unread-count        Unread count (cached 15s)
POST   /notifications/mark-all-read       Mark all read
PATCH  /notifications/{id}                 Mark one read
DELETE /notifications/{id}                  Delete one (scoped to me)
POST   /notifications/bulk                Bulk mark-read/delete ({action, ids})
```

### Subscriptions

```
GET    /subscriptions          List mine (now includes title/slug/exists of the target, 1.8.0)
POST   /subscriptions          Subscribe to a space or post ({object_type, object_id, via})
DELETE /subscriptions/{id}      Unsubscribe
```

### Search

```
GET /search?q=...&type=post|reply|space|tag|all&sort=relevance|newest|votes&limit=&offset=
```

MySQL FULLTEXT boolean-mode search. `type=all` returns `{posts, spaces, tags}` grouped, with `limit`/`offset` applying to the `posts` group only. 1.7.1 added real `limit`/`offset` pagination (previously hardcoded to 20/10 per type) plus `X-WP-Total`/`X-WP-TotalPages` headers.

### Categories, Tags, Leaderboards, Bookmarks

```
GET/POST     /categories                 List (nested) / create (jetonomy_manage_categories)
GET/PATCH/DELETE /categories/{id}        Get / update / delete (jetonomy_manage_categories)
GET          /tags                       List post tags (GET only — no write routes)
GET          /leaderboards               Reputation ranking (period=all|month|week)
GET/POST     /bookmarks                  List mine / toggle a bookmark
DELETE       /bookmarks/{post_id}         Remove
```

### Moderation

```
GET    /moderation/queue                          Pending+spam queue (jetonomy_moderate)
POST   /moderation/approve/{type}/{id}             Approve
POST   /moderation/spam/{type}/{id}                Mark spam (reputation penalty)
POST   /moderation/trash/{type}/{id}               Trash
GET/POST /flags                                    List (mod) / report content
GET    /moderation/flags                           List flags for moderation
POST   /moderation/flags/{id}/resolve              Resolve (valid trashes + rewards reporter)
POST   /moderation/bulk                            Bulk approve/spam/trash
GET    /posts/{id}/flags                           Flags on one post
GET/POST /moderation/ban                           List / issue ban-silence-spaceban
DELETE /moderation/ban/{id}                        Lift a restriction
GET    /spaces/{id}/moderation/flags               Space-scoped flag queue (space mod)
POST   /spaces/{id}/moderation/flags/{flag_id}/resolve
POST   /spaces/{id}/moderation/{approve|spam|trash}/{type}/{obj_id}
```

Banning has three built-in integrity guards: nobody can restrict themselves, nobody can restrict a site administrator, and a moderator (not an admin) cannot restrict another moderator.

### Media

```
POST /media    Upload an image (multipart, upload_files|jetonomy_upload_media|jetonomy_create_posts|jetonomy_create_replies)
GET  /media    List community media library uploads (jetonomy_manage_settings)
```

### Updates, OEmbed, Admin

```
GET  /updates    Poll for activity since a timestamp (scope=global|space|post)
GET  /oembed     oEmbed 1.0 JSON for a forum thread URL (Slack/X/Discord unfurl)
POST /admin/recount               Rebuild counters (manage_options)
POST /admin/users/trust-level     Bulk-set trust level (manage_options)
```

### App / Feed (mobile app surface)

```
GET /app/config    Public branding + Pro feature flags (pre-login theming)
GET /feed          Global cross-space home feed (sort=hot|new|top)
```

---

## Pro Resources (`jetonomy/v1` unless noted)

### Private Messaging

```
GET/POST     /conversations                          List mine / start a new one
GET/PATCH    /conversations/{id}                      Get / mute
GET/POST     /conversations/{id}/messages             List / send (cursor: before)
GET          /conversations/unread-count              Cached 30s
POST         /conversations/{id}/mute                 Toggle mute
POST         /conversations/{id}/archive              Toggle archive
POST         /conversations/{id}/leave                Leave (group)
POST         /conversations/{id}/block                Block the other side (direct)
GET          /messaging/recipient-suggestions          Typeahead (shared-space scoped)
```

Requires trust level ≥ 1 to send (site admins/moderators bypass). A new direct message additionally requires the sender and recipient to share at least one space, and neither side may have blocked the other.

### Reactions

```
GET/POST /posts/{id}/reactions      Get counts / toggle ({emoji})
GET/POST /replies/{id}/reactions    Get counts / toggle
```

Single-reaction-per-user model — re-adding a different emoji swaps the prior one. `POST` requires `jetonomy_vote`.

### Polls

```
GET/POST /posts/{post_id}/poll    Get / create ({question, type, options[], closes_at})
PATCH    /polls/{id}              Close/reopen (poll creator or moderator)
POST     /polls/{id}/vote         Cast/switch vote
DELETE   /polls/{id}/vote          Remove my vote
```

One poll per post (409 on a second create). Multiple-choice `POST /polls/{id}/vote` treats `option_ids` as the desired **final set** (idempotent reconcile), not a per-option toggle.

### Custom Fields

```
GET/POST         /fields                    List (public) / create (manage_options)
PATCH/DELETE      /fields/{id}               Update / deactivate (manage_options)
GET/PATCH        /posts/{id}/fields          Read (public) / write (author/mod)
GET              /users/{id}/fields          Read
PATCH            /users/me/fields            Write my own
```

Field types: `text, textarea, number, email, url, select, checkbox, radio, date`. Contexts: `post, profile, space`. Write endpoints accept a raw JSON object of `{slug: value}` and validate/sanitize per field type; invalid values are reported per-field in a `400` without blocking the rest of the save.

### Custom Badges

```
GET/POST          /badges                  List active (paginated) / create (manage_options)
GET/PATCH/DELETE  /badges/{id}              Get (with earned_count) / update / deactivate
GET               /users/{id}/badges        A user's earned badges
POST/DELETE       /badges/{id}/award        Manual award / revoke ({user_id})
```

Auto-award runs on an event-driven queue (post/reply/vote/reputation/trust-level changes) plus a 6-hour cron sweep. Criteria: 8 metrics (`post_count`, `reply_count`, `reputation`, `trust_level`, `vote_received`, `days_active`, `accepted_answers`, `spaces_joined`) × 5 operators × `all`/`any` match mode.

### Webhooks

```
GET/POST         /webhooks                  List / create (manage_options)
PATCH/DELETE      /webhooks/{id}             Update / delete
POST             /webhooks/{id}/test         Send a test delivery
GET              /webhooks/{id}/deliveries   Paginated delivery log (30-day retention)
```

13 event slugs: `post.created/updated/deleted`, `reply.created/updated/deleted`, `user.registered`, `user.trust_level_changed`, `vote.cast`, `flag.created/resolved`, `space.member_joined/left`. Payloads are HMAC-signed (`X-Jetonomy-Signature: sha256=...`), event name in `X-Jetonomy-Event`. Auto-disabled after 5 consecutive delivery failures.

### White Label

```
GET/PATCH /settings/white-label    Branding (manage_options)
```

### Site Announcements

```
GET  /jetonomy/v1/announcements/active                 Public/app read of active pins
GET  /jetonomy-pro/v1/site-announcements                Admin: list pinned post IDs (manage_options)
POST /jetonomy-pro/v1/site-announcements/{id}            Admin: pin a post (capped at 5)
DELETE /jetonomy-pro/v1/site-announcements/{id}           Admin: unpin
```

Note the namespace split — the public read stays on the shared `jetonomy/v1` namespace; the admin write surface is on `jetonomy-pro/v1`.

### Web Push

```
GET          /push/vapid-key              Public VAPID key
GET          /push/service-worker.js      Service worker script
POST/DELETE  /push/subscribe              Browser PushSubscription
POST/DELETE  /push/register-device        Native (Expo) push token
```

### AI

```
GET  /ai/usage             Usage stats (manage_options)
GET  /ai/usage/summary     By-provider summary (manage_options)
POST /ai/suggest-reply     Suggest a reply draft ({post_id}, logged in + can read the post)
```

### Advanced Moderation

```
GET/POST         /moderation/rules             List / create rules (manage_options)
PATCH/DELETE      /moderation/rules/{id}        Update / delete
GET              /moderation/rules/{id}/stats   Trigger stats
```

### Email Digest

```
GET/PATCH /users/me/digest-preferences    My frequency + subscribed types
POST      /admin/digest/test              Send a test digest to me (manage_options)
GET       /admin/digest/stats             Send statistics (manage_options)
```

### SEO Pro

```
GET/PATCH /spaces/{id}/seo    Per-space meta title/description/OG image (space admin)
```

### Reply By Email

```
POST /reply-by-email/inbound    Inbound email webhook — signature/token-verified, not a WP capability
```

### Anonymous Posting

```
POST /anonymous/reveal    Reveal the real author of an anonymous post/reply (manage_options, audit-logged)
```

### Attachments — `jetonomy-pro/v1` namespace

```
POST   /attachments               Link an uploaded WP media item to a post/reply
DELETE /attachments/{id}           Detach a link (id = link_id, not the WP media ID)
GET    /attachments/{id}/download  Force-download (Content-Disposition, public read following the parent's visibility)
GET    /attachments/batch          Batch read for an admin content-view page
```

The attachment **link storage and REST payload injection are free** as of 1.7.1/1.8.0 — `Jetonomy\Models\Attachment` and the `attachments` array on `Post`/`Reply` responses work with or without Pro. Pro's `attachments` extension adds the upload composer UI, size/type policy, and the `/jetonomy-pro/v1/attachments*` link/detach/download management routes.

---

## Changelog note (1.8.0)

- Post and reply REST payloads now always carry an `attachments` array (free).
- `POST /users/me/blocks`, `DELETE /users/me/blocks/{user_id}` — block/report a member.
- `DELETE /users/me` — in-app account deletion.
- Object cache is invalidated immediately on space/profile/membership writes.
- `GET /subscriptions` now returns `title`/`slug`/`exists` for each subscribed target.
- `GET /search` gained real `limit`/`offset` pagination and `X-WP-Total`/`X-WP-TotalPages` headers (previously hardcoded per-type limits).

See `openapi.json` for the exact parameter/response contract of every route above.
