Jetonomy exposes a full REST API under the `jetonomy/v1` namespace: 48+ endpoints in the free plugin, plus 40+ additional endpoints when Jetonomy Pro is active (90+ total). All endpoints return JSON and integrate with WordPress nonce authentication via the `wp_rest` nonce.

**Base URL:** `https://example.com/wp-json/jetonomy/v1/`

## Authentication

Public endpoints (marked **Public** below) return data without authentication. Write operations and moderation endpoints require a logged-in user and the `X-WP-Nonce` header:

```javascript
const nonce = window.wpApiSettings?.nonce
           ?? jetonomyState?.nonce;  // Injected via wp_interactivity_state()

fetch( '/wp-json/jetonomy/v1/spaces/1/posts', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
    },
    body: JSON.stringify({ title: 'My topic', content: '<p>Hello</p>' }),
} );
```

The Interactivity API store exposes `apiBase` and `_nonce` in the `jetonomy` namespace so the bundled frontend needs no extra configuration.

---

## Categories

Manage the top-level taxonomy that groups Spaces.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/categories` | Public | List all categories |
| POST | `/categories` | `manage_options` | Create a category |
| GET | `/categories/{id}` | Public | Get a single category |
| PATCH | `/categories/{id}` | `manage_options` | Update a category |
| DELETE | `/categories/{id}` | `manage_options` | Delete a category |

**GET /categories - example**

```javascript
const res  = await fetch( '/wp-json/jetonomy/v1/categories' );
const data = await res.json();
// data.data → array of category objects
// { id, name, slug, description, position, space_count }
```

---

## Spaces

Spaces are the primary containers for posts (equivalent to forums or boards).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/spaces` | Public | List spaces (paginated) |
| POST | `/spaces` | `manage_options` | Create a space |
| GET | `/spaces/{id}` | Public | Get a single space |
| PATCH | `/spaces/{id}` | Moderator / Admin | Update space settings |
| DELETE | `/spaces/{id}` | `manage_options` | Delete a space |
| GET | `/spaces/{id}/members` | Public / Members only if private | List space members |
| POST | `/spaces/{id}/members` | Logged in | Join a space |
| PATCH | `/spaces/{id}/members/{user_id}` | Moderator / Admin | Change a member's role |
| DELETE | `/spaces/{id}/members/{user_id}` | Moderator / Admin | Remove a member |
| POST | `/spaces/{id}/invite` | Moderator / Admin | Generate an invite link |
| GET | `/invite/{token}` | Public | Resolve an invite token |
| GET | `/spaces/{id}/privileged-members` | Public | List admins and moderators of a space |

**GET /spaces - parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Results per page (max 100) |
| `category_id` | int | - | Filter by category |
| `search` | string | - | Search by title |
| `orderby` | string | `position` | `position`, `title`, `member_count`, `post_count` |

```javascript
const res  = await fetch( '/wp-json/jetonomy/v1/spaces?per_page=10&category_id=3' );
const data = await res.json();
// data.data → array of space objects
// data.meta → { total, pages, page }
```

**Viewer-relative fields (added in 1.6.0)**

Every space object - in both the list and the single-space response - carries three fields that describe the calling user's relationship to the space. They are null-safe for logged-out callers (`is_member` and `is_subscribed` return `false`, `viewer_role` returns `null`).

| Field | Type | Description |
|-------|------|-------------|
| `is_member` | boolean | Whether the current user is a member of this space |
| `viewer_role` | string \| null | The space role the current user holds (for example `moderator` or `member`), or `null` when they are not a member |
| `is_subscribed` | boolean | Whether the current user is subscribed to this space for new-content notifications |

---

## Posts

Posts are individual discussion threads (topics) inside a Space.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/spaces/{space_id}/posts` | Public | List posts in a space |
| POST | `/spaces/{space_id}/posts` | Logged in | Create a post |
| GET | `/posts/{id}` | Public | Get a single post |
| PATCH | `/posts/{id}` | Author / Moderator | Update a post |
| DELETE | `/posts/{id}` | Author / Moderator | Delete a post |
| POST | `/posts/{id}/close` | Moderator / Admin | Toggle closed status |
| POST | `/posts/{id}/pin` | Moderator / Admin | Toggle pinned status |
| POST | `/posts/{id}/move` | Moderator / Admin | Move to another space |
| POST | `/posts/{id}/merge` | Moderator / Admin | Merge into another post |
| POST | `/posts/{id}/idea-status` | Space Moderator | Set the roadmap status on an idea-type post (`planned`, `in_progress`, `shipped`, `declined`) |
| GET | `/posts/drafts` | Logged in | List current user's drafts |
| GET | `/link-preview` | Public | Fetch OG metadata for a URL |

**GET /spaces/{space_id}/posts - parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Max 100 |
| `sort` | string | `latest` | `latest`, `oldest`, `votes`, `replies` |
| `type` | string | - | Filter by post type (`discussion`, `question`, `idea`) |
| `tag` | string | - | Filter by tag slug |
| `status` | string | `publish` | `publish`, `draft` (author/mod only) |

**POST /spaces/{space_id}/posts - body**

```javascript
await fetch( `/wp-json/jetonomy/v1/spaces/${spaceId}/posts`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: JSON.stringify({
        title:   'How do I configure caching?',
        content: '<p>Looking for recommendations...</p>',
        type:    'question',     // discussion | question | idea
        tags:    ['caching', 'performance'],
        status:  'publish',      // or 'draft'
    }),
} );
```

**POST /posts/{id}/move - body**

```javascript
{ target_space_id: 42 }
```

**POST /posts/{id}/merge - body**

```javascript
{ target_post_id: 17 }
```

**GET /link-preview - parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `url` | string | Yes | URL to fetch OG data for |

Response: `{ title, description, image, domain }`

**Viewer-relative fields (added in 1.6.0)**

Every post object - in the space listing, the global feed, and the single-post response - carries two fields that describe the calling user's relationship to the post. They are null-safe for logged-out callers.

| Field | Type | Description |
|-------|------|-------------|
| `is_bookmarked` | boolean | Whether the current user has bookmarked this post (`false` when logged out) |
| `viewer_vote` | integer | The current user's vote on this post: `1` (up), `-1` (down), or `0` (no vote / logged out) |

---

## Replies

Replies are threaded responses to a Post.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/posts/{post_id}/replies` | Public | List replies on a post |
| POST | `/posts/{post_id}/replies` | Logged in | Create a reply |
| GET | `/replies/{id}` | Public | Get a single reply |
| PATCH | `/replies/{id}` | Author / Moderator | Update a reply |
| DELETE | `/replies/{id}` | Author / Moderator | Delete a reply |
| POST | `/replies/{id}/accept` | Post author / Moderator | Accept as answer |
| POST | `/replies/{id}/split` | Moderator / Admin | Split this reply into a new standalone post |

**GET /posts/{post_id}/replies - parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 30 | Max 100 |
| `sort` | string | `oldest` | `oldest`, `newest`, `best` |

**POST /replies/{id}/accept**

Marks this reply as the accepted answer. Only the original post author or a moderator can call this. Fires the `jetonomy_reply_accepted` action hook and awards +15 reputation to the reply author.

```javascript
await fetch( `/wp-json/jetonomy/v1/replies/${replyId}/accept`, {
    method: 'POST',
    headers: { 'X-WP-Nonce': nonce },
} );
```

---

## Votes

Votes record up/down signals on Posts and Replies. Calling the endpoint again with the same direction removes the vote (toggle).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/posts/{id}/vote` | Logged in | Cast or toggle a post vote |
| DELETE | `/posts/{id}/vote` | Logged in | Remove vote from a post |
| POST | `/replies/{id}/vote` | Logged in | Cast or toggle a reply vote |
| DELETE | `/replies/{id}/vote` | Logged in | Remove vote from a reply |

**Body for both vote endpoints**

```javascript
{ direction: 'up' }  // or 'down'
```

Response includes `vote_score` (current net score) and `user_vote` (the caller's current vote direction or `null`).

---

## Search

Full-text search across Posts, Replies, Spaces, and Tags. Uses MySQL `FULLTEXT` with Boolean Mode by default. Swap to a custom search adapter (Meilisearch, Algolia, etc.) via the Adapter System. See [05-adapters.md](./05-adapters.md).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/search` | Public | Search across content types |

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | string | Yes | - | Query string (min 2 chars) |
| `type` | string | - | `post` | `post`, `reply`, `space`, `tag`, `all` |
| `space_id` | int | - | - | Restrict to a specific space |
| `date_from` | string | - | - | ISO date `YYYY-MM-DD` |
| `date_to` | string | - | - | ISO date `YYYY-MM-DD` |
| `author_id` | int | - | - | Filter by author's WP user ID |
| `tag` | string | - | - | Filter by tag slug |
| `sort` | string | - | `relevance` | `relevance`, `newest`, `votes` |

Using `type=all` returns a grouped response with `posts`, `spaces`, and `tags` keys.

```javascript
const params = new URLSearchParams({
    q:        'caching strategies',
    type:     'post',
    space_id: 5,
    sort:     'votes',
} );

const res  = await fetch( `/wp-json/jetonomy/v1/search?${params}` );
const data = await res.json();
// data.data → array of matching post objects
// data.meta → { total, has_more }
```

---

## Tags

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/tags` | Public | List all global tags |

> **Removed in 1.5.0:** the `GET /space-tags` route. It read tables that were never wired to any feature; tags have always been global. Existing integrations calling it receive a 404 and should switch to `GET /tags`.

---

## Notifications

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/notifications` | Logged in | List notifications for current user |
| GET | `/notifications/unread-count` | Logged in | Get unread count (cached 30s) |
| POST | `/notifications/mark-all-read` | Logged in | Mark all notifications read |
| PATCH | `/notifications/{id}` | Logged in | Mark a single notification read |
| DELETE | `/notifications/{id}` | Logged in (own only) | Delete a single notification |
| POST | `/notifications/bulk` | Logged in (own only) | Bulk mark-as-read or delete a batch of notifications |

**GET /notifications - parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | 20 | Max 50 |
| `unread_only` | bool | false | Return only unread notifications |

---

## Subscriptions

Subscriptions track which Spaces or Posts a user follows for new-content notifications.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/subscriptions` | Logged in | List current user's subscriptions |
| POST | `/subscriptions` | Logged in | Create a subscription to a space or post |
| DELETE | `/subscriptions/{id}` | Logged in | Remove a subscription |

---

## Moderation

All moderation endpoints require the `jetonomy_moderate` capability (granted to admins, editors, and users with Moderator role by default).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/moderation/queue` | Moderator | List items pending review |
| POST | `/moderation/approve/{type}/{id}` | Moderator | Approve a flagged item |
| POST | `/moderation/spam/{type}/{id}` | Moderator | Mark as spam |
| POST | `/moderation/trash/{type}/{id}` | Moderator | Send to trash |
| POST | `/moderation/bulk` *(new in 1.4.1)* | Moderator | Approve / spam / trash many posts in one call |
| POST | `/flags` | Logged in | Submit a flag on a post or reply |
| GET | `/posts/{id}/flags` *(new in 1.4.1)* | Moderator | The flags raised against a specific post |
| GET | `/moderation/flags` | Moderator | List all open flags |
| POST | `/moderation/flags/{id}/resolve` | Moderator | Resolve a flag (`valid` or `dismissed`) |
| POST | `/moderation/ban` | Moderator | Ban a user (global ban, space ban, or silence) |
| DELETE | `/moderation/ban/{id}` | Moderator | Remove a ban |
| GET | `/spaces/{id}/moderation/flags` | Space Admin | List flags filed within a specific space |
| POST | `/spaces/{id}/moderation/flags/{flag_id}/resolve` | Space Admin | Resolve a flag within a specific space |
| POST | `/spaces/{id}/moderation/{action}/{type}/{obj_id}` | Space Admin | Moderate content in a specific space (`action`: `approve`, `spam`, or `trash`; `type`: `post` or `reply`) |

Resolving a flag as `valid` applies the full resolution contract on every surface (1.5.0 fix): the flagged content is trashed, any other pending flags on the same object are cleared with it, the reporter earns +5 reputation, and the `jetonomy_flag_resolved` action fires (so Pro webhooks see the event). Earlier versions skipped these side effects when the flag was resolved through this global REST route specifically.

**POST /moderation/bulk - body**

```javascript
{
    action:     'approve',  // approve | spam | trash
    object_type: 'post',    // or 'reply'
    object_ids: [101, 104, 109, 117]
}
```

Returns per-item results so partial failures are visible:

```javascript
{
    succeeded: [101, 104, 117],
    failed:    [{ id: 109, reason: 'already_spam' }]
}
```

`{type}` in approve/spam/trash routes is either `post` or `reply`.

**POST /flags - body**

```javascript
{
    object_type: 'post',   // or 'reply'
    object_id:   42,
    reason:      'spam',   // spam | offensive | off_topic | harassment | other
}
```

**POST /moderation/ban - body**

```javascript
{
    user_id:  123,
    reason:   'Repeated spam',
    duration: 7,           // days - omit for permanent ban
}
```

---

## Leaderboards

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/leaderboards` | Public | Get top contributors |

**Parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `week` | `week`, `month`, `all-time` |
| `per_page` | int | 10 | Max 50 |
| `space_id` | int | - | Restrict to a space |

---

## Users

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/users/me` | Logged in | Get the current user's profile |
| PATCH | `/users/me` | Logged in (own account) | Update the current user's profile |
| GET | `/users/{id}` | Public | Get a user's public profile |
| PATCH | `/users/{id}` | Owner / Admin | Update a user profile |
| GET | `/users/by-login/{login}` | Public | Look up a user by login slug |
| GET | `/users/{id}/posts` | Public | List posts by this user |
| GET | `/users/suggest` | Public | Typeahead - suggest users by name or login prefix |

**PATCH /users/{id} - updatable fields**

```javascript
{
    bio:         'Forum moderator and PHP developer.',
    website:     'https://example.com',
    location:    'Berlin',
    twitter:     'janedoe',
    github:      'janedoe',
    avatar_url:  'https://example.com/avatar.jpg',
}
```

**`avatar_display` (added in 1.7.0)**

Every user object returned by `/users/me`, `/users/{id}`, and `/users/by-login/{login}` carries a read-only `avatar_display` field: the resolved URL a client should render. It is the member's `avatar_url` when set, otherwise the best available real avatar (uploaded, BuddyPress, or a hosted Gravatar). It is an empty string `''` when none of those exist - the signal for the client to render a generated initials avatar instead of a blank placeholder. `avatar_url` remains the writable field on `PATCH`; `avatar_display` is compute-only.

---

## Updates (Polling)

The Updates endpoint powers the "N new replies" banner in single-post view. It is polled periodically by the Interactivity API store.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/updates` | Public | Check for new activity since a timestamp |

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `since` | string | ISO 8601 timestamp. Returns items created after this time. |
| `post_id` | int | If provided, returns new reply count for that post |

---

## Bookmarks

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/bookmarks` | Logged in | List the current user's bookmarked posts |
| POST | `/bookmarks` | Logged in | Toggle a bookmark on a post (adds if absent, removes if present) |
| DELETE | `/bookmarks/{post_id}` | Logged in | Remove a specific bookmark by post ID |

---

## Media

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/media` | Logged in (`jetonomy_upload_media`) | Upload an image, video, or file attachment |

---

## oEmbed

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/oembed` | Public | oEmbed endpoint for forum posts - returns embed metadata for the given post URL |

---

## Authentication and Registration

These endpoints are unauthenticated and rate-limited. They are used by the headless frontend or when the native WordPress login form is not in use.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/auth/login` | Public (rate limited) | Log in and receive an authentication cookie |
| GET | `/auth/nonce` *(new in 1.5.0)* | Logged in (cookie) | Mint a fresh REST nonce for the current session |
| POST | `/auth/register` | Public (rate limited) | Register a new user account |
| POST | `/auth/lost-password` | Public (rate limited) | Request a password reset email |
| GET | `/auth/verify-email` | Public | Verify an email confirmation token |
| POST | `/auth/resend-verification` | Public (rate limited) | Resend the email verification message |

**GET /auth/nonce** backs the frontend's automatic session recovery: when a long-lived tab's REST nonce expires (403 `rest_cookie_invalid_nonce`), the bundled `restFetch` client calls this endpoint, receives a fresh nonce minted against the still-valid login cookie, and retries the original request once - so members never lose a reply to "Cookie nonce is invalid". The endpoint re-validates the login cookie itself and sends no-cache headers; it never mints a nonce for an anonymous session.

---

## Admin

These endpoints require the `manage_options` capability (administrators only).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/admin/recount` | Admin (`manage_options`) | Rebuild all denormalized counters (reply counts, vote scores, post counts) |
| POST | `/admin/users/trust-level` | Admin (`manage_options`) | Manually set a user's trust level |

---

## Mobile App

Endpoints that power the [Jetonomy mobile app](../mobile-app/00-mobile-app-overview.md). Added in 1.6.0.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/app/config` | Public | Per-site branding + feature flags the app reads on connect |
| GET | `/feed` | Public | Global feed across every space the caller can see |
| POST | `/push/register-device` | Logged in | Register this device's Expo push token for native push |
| DELETE | `/push/register-device` | Logged in | Unregister the device's push token |

**GET /app/config**

Public - the app reads it on connect to theme itself per site before a user signs in. `app_name` comes from **Settings -> General -> Community Title** (falling back to the site name). `space_label` carries the singular and plural of the configurable "Space" noun so the app labels match the web. `accent_color`, `logo_url`, and `login_bg_url` come from branding: the Pro white-label row when Pro is active, otherwise the free **Settings -> Appearance** values. The `features` map reflects which Pro extensions are active, so the app gates its UI on them.

`app_enabled` is the fail-closed gate that decides whether the mobile app signs in at all. It defaults to `false` in the free plugin. Jetonomy Pro flips it to `true` through the `jetonomy_app_config` filter, and only when the site holds a valid Pro license. When it is `false`, the app shows a "requires Jetonomy Pro" screen and refuses to sign in, so the app never runs against a free-only or unlicensed install.

Dark mode is not part of this payload - the app follows the device/OS theme.

```json
{
  "app_name":     "Course Academy",
  "space_label":  { "singular": "Space", "plural": "Spaces" },
  "accent_color": "#7C3AED",
  "logo_url":     "https://example.com/logo.png",
  "login_bg_url": "",
  "pro_active":   true,
  "app_enabled":  true,
  "features": {
    "messaging":     true,
    "reactions":     true,
    "polls":         true,
    "badges":        true,
    "custom_fields": true,
    "web_push":      true,
    "native_push":   true
  }
}
```

**GET /feed - parameters**

A single cross-space feed (the app's Home tab). Returns only posts in spaces the caller may view. The feed is offset-paginated: pass `limit` and `offset`. It does not honour the generic `after`/`before` cursor params - they are inert on this route.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `sort` | string | `hot` | `hot`, `new`, `top` |
| `limit` | int | - | Page size (max 50) |
| `offset` | int | 0 | Offset into the result set |
| `window_days` | int | 7 | For `sort=top`, the look-back window in days (0 = all-time) |

**POST /push/register-device - body**

Registers the device for native (Expo) push. `DELETE` with the same `expo_push_token` removes it.

```javascript
{
    expo_push_token: 'ExponentPushToken[xxxxxxxx]',  // required
    platform:        'ios',                           // required: ios | android
    device_name:     'My iPhone',                     // optional
}
```

> Branding is set from wp-admin - see the [Mobile App](../mobile-app/00-mobile-app-overview.md) docs for the site-owner walkthrough.

---

## Pro Endpoints

The following endpoints are available only when **Jetonomy Pro** is active and the relevant extension is enabled.

### Private Messaging (`private-messaging` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/conversations` | Logged in | List conversations |
| POST | `/conversations` | Trust Level 1+ | Start a new conversation |
| GET | `/conversations/{id}` | Participant | Get conversation details |
| PATCH | `/conversations/{id}` | Participant | Update conversation settings (`is_muted` boolean); see also the dedicated `POST /conversations/{id}/mute` |
| GET | `/conversations/{id}/messages` | Participant | List messages (paginated) |
| POST | `/conversations/{id}/messages` | Participant + TL 1+ | Send a message |
| POST | `/conversations/{id}/mute` | Participant | Mute/unmute the conversation (`muted` boolean) |
| POST | `/conversations/{id}/archive` | Participant | Archive/unarchive the conversation for the caller (`archived` boolean) |
| POST | `/conversations/{id}/leave` | Participant | Leave a group conversation |
| POST | `/conversations/{id}/block` | Participant | Block/unblock the other participant (`blocked` boolean) |
| GET | `/conversations/unread-count` | Logged in | Unread message count (30s cache) |
| GET | `/messaging/recipient-suggestions` | Logged in | Typeahead for the DM composer, scoped to shared-space members (`q` required, 3-64 chars) |

**POST /conversations - body**

```javascript
{
    participants: [4, 17],          // WP user IDs
    title:        'Project sync',   // Optional for group conversations
    message:      'Hey, quick question...',
}
```

### Analytics (`analytics` extension)

All analytics endpoints require the `jetonomy_view_analytics` capability.

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/analytics/overview` | Daily series + period comparison |
| GET | `/analytics/top-spaces` | Ranked by period activity |
| GET | `/analytics/top-contributors` | Ranked by posts + replies |
| GET | `/analytics/engagement` | Engagement rate, avg reply time, unanswered % |
| GET | `/analytics/moderation` | Flags, bans, spam actions |
| GET | `/analytics/export` | CSV download |

**Analytics parameters (all endpoints)**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `range` | string | `30d` | `7d`, `30d`, `90d`, `custom` |
| `start` | string | - | ISO date (required when `range=custom`) |
| `end` | string | - | ISO date (required when `range=custom`) |

```javascript
const res  = await fetch(
    '/wp-json/jetonomy/v1/analytics/overview?range=30d',
    { headers: { 'X-WP-Nonce': nonce } }
);
const data = await res.json();
```

### Polls (`polls` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/posts/{post_id}/poll` | Public | Get a post's poll and current results |
| POST | `/posts/{post_id}/poll` | Logged in (can post) | Create a poll on a post |
| POST | `/polls/{id}/vote` | Logged in | Cast a vote on a poll option |
| DELETE | `/polls/{id}/vote` | Logged in | Retract a vote |
| PATCH | `/polls/{id}` | Author / Moderator | Update or close a poll |

### Reactions (`reactions` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/posts/{id}/reactions` | Public | List reactions on a post |
| POST | `/posts/{id}/reactions` | Logged in (`jetonomy_vote`) | Toggle an emoji reaction on a post |
| GET | `/replies/{id}/reactions` | Public | List reactions on a reply |
| POST | `/replies/{id}/reactions` | Logged in (`jetonomy_vote`) | Toggle an emoji reaction on a reply |

### Custom Badges (`custom-badges` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/badges` | Public | List all defined badges |
| POST | `/badges` | Admin (`manage_options`) | Create a new badge definition |
| GET | `/badges/{id}` | Public | Get a single badge with its earned count |
| PATCH | `/badges/{id}` | Admin (`manage_options`) | Update a badge definition |
| DELETE | `/badges/{id}` | Admin (`manage_options`) | Delete (deactivate) a badge definition |
| GET | `/users/{id}/badges` | Public | List the badges a user has earned |
| POST | `/badges/{id}/award` | Admin (`manage_options`) | Manually award the badge to a user (`user_id` in body) |
| DELETE | `/badges/{id}/award` | Admin (`manage_options`) | Revoke the badge from a user (`user_id` in body) |

### Custom Fields (`custom-fields` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/fields` | Public | List all custom field definitions |
| POST | `/fields` | Admin (`manage_options`) | Create a custom field definition |
| PATCH | `/fields/{id}` | Admin (`manage_options`) | Update a custom field definition |
| DELETE | `/fields/{id}` | Admin (`manage_options`) | Delete a custom field definition |
| GET | `/posts/{id}/fields` | Public | Get a post's custom field values |
| PATCH | `/posts/{id}/fields` | Logged in (author / moderator) | Set custom field values on a post |
| GET | `/users/{id}/fields` | Public | Get a user's custom field values |
| PATCH | `/users/me/fields` | Logged in | Set the current user's custom field values |

### Email Digest (`email-digest` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/users/me/digest-preferences` | Logged in | Get the current user's digest frequency and topic preferences |
| PATCH | `/users/me/digest-preferences` | Logged in | Update digest frequency and topics |
| POST | `/admin/digest/test` | `manage_options` | Send a test digest email |
| GET | `/admin/digest/stats` | `manage_options` | Digest delivery statistics |

### Webhooks (`webhooks` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/webhooks` | Admin (`manage_options`) | List all registered webhook endpoints |
| POST | `/webhooks` | Admin (`manage_options`) | Register a new webhook endpoint |
| PATCH | `/webhooks/{id}` | Admin (`manage_options`) | Update a webhook endpoint |
| DELETE | `/webhooks/{id}` | Admin (`manage_options`) | Delete a webhook endpoint |
| POST | `/webhooks/{id}/test` | Admin (`manage_options`) | Send a test delivery to the endpoint |
| GET | `/webhooks/{id}/deliveries` | Admin (`manage_options`) | List recent delivery attempts for the endpoint |

### White Label (`white-label` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/settings/white-label` | Admin (`manage_options`) | Get current white-label settings (logo, colors, footer text) |
| PATCH | `/settings/white-label` | Admin (`manage_options`) | Save white-label settings |

### AI (`ai` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/ai/usage` | Admin (`manage_options`) | Get per-request AI usage statistics |
| GET | `/ai/usage/summary` | Admin (`manage_options`) | Get monthly usage summary grouped by provider |
| POST | `/ai/suggest-reply` | Logged in | Generate an AI-suggested reply for a Q&A post |

### Web Push (`web-push` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/push/subscribe` | Logged in | Register a browser push subscription |
| DELETE | `/push/subscribe` | Logged in | Remove a push subscription |
| GET | `/push/vapid-key` | Logged in | Get the public VAPID key needed to subscribe |
| GET | `/push/service-worker.js` | Public | Serves the push service-worker script |

### SEO Pro (`seo-pro` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/spaces/{id}/seo` | Space Admin | Get SEO metadata for a space (title, description, OG image) |
| POST | `/spaces/{id}/seo` | Space Admin | Save SEO metadata for a space |

### Reply by Email (`reply-by-email` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/reply-by-email/inbound` | Signature (webhook) | Inbound webhook endpoint for processing email replies - validated by a signature inside the callback, not a user session |

### Advanced Moderation (`advanced-moderation` extension)

All advanced moderation endpoints require the `manage_options` capability.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/moderation/rules` | Admin | List all auto-moderation rules |
| POST | `/moderation/rules` | Admin | Create a new auto-moderation rule |
| PATCH | `/moderation/rules/{id}` | Admin | Update an auto-moderation rule |
| DELETE | `/moderation/rules/{id}` | Admin | Delete an auto-moderation rule |
| GET | `/moderation/rules/{id}/stats` | Admin | Get trigger statistics for a specific rule |

### Analytics - additional endpoint (`analytics` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/analytics/diff-report` | Admin (`manage_options`) | Per-metric drift report comparing the direct-query path against the event-driven aggregate path; returns `from_query`, `from_events`, `drift_pct`, and `within_tolerance` for each metric |

### Community Announcements (`site-announcements` extension)

The management routes live under `jetonomy-pro/v1` and require the `manage_options` or `jetonomy_manage_spaces` capability (administrators by default).

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/site-announcements` | List the currently pinned (announced) posts |
| POST | `/site-announcements/{id}` | Pin a post site-wide (capped at 5; returns `400` past the cap) |
| DELETE | `/site-announcements/{id}` | Remove a post from the announcements |

```javascript
// Pin post 395 to the whole community
await fetch( '/wp-json/jetonomy-pro/v1/site-announcements/395', {
    method:  'POST',
    headers: { 'X-WP-Nonce': nonce },
} );
```

This is distinct from the free space-level pin (`POST /posts/{id}/pin`), which only stickies a topic within its own space.

**GET `/announcements/active`** *(added in 1.6.0)*

> **Namespace:** unlike the management routes above, this endpoint lives under `jetonomy/v1`.

A member-readable list of the currently active announcements, used by the mobile app's announcement banner. The read is public-aware: logged-out callers on a public community see the same site-wide pins the listing inject shows. Each item returns `id`, `title`, `space_id`, `url` (deep link to the post), and `created_at`.

```json
{
  "data": [
    { "id": 395, "title": "Scheduled maintenance Sunday", "space_id": 12, "url": "https://example.com/community/s/news/t/maintenance/", "created_at": "2026-07-01 09:00:00" }
  ],
  "meta": { "total": 1 }
}
```

For the full Pro endpoint reference (methods, params, and permission callbacks per extension), see the [Pro Endpoints](#pro-endpoints) section above.

### Anonymous Posting (`anonymous-posting` extension)

> **Namespace:** this route lives under `jetonomy/v1`, not `jetonomy-pro/v1` - it mirrors the free `jetonomy_author_can_reveal` filter seam rather than registering a Pro-only namespace.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/anonymous/reveal` | Admin (`manage_options`) | Reveal the real author of an anonymous post or reply |

**POST /anonymous/reveal - body**

```javascript
{
    object_type: 'post',   // or 'reply'
    object_id:   42,
}
```

Only site administrators can call this - space moderators cannot. Every successful reveal is written to the activity log (`anonymous_author_revealed`) with the real author ID, so reveals stay accountable. Returns `404` if the object is not actually anonymous, `403` if the caller lacks `manage_options`.

```json
{ "success": true, "author": { "id": 17, "name": "Jane Doe" } }
```

### File Attachments (`attachments` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/attachments` | Logged in (`jetonomy_create_posts`, `jetonomy_create_replies`, or `jetonomy_upload_media`) | Link an already-uploaded attachment to a post or reply |
| DELETE | `/attachments/{id}` | Owner / Moderator | Detach an attachment from its post or reply |
| GET | `/attachments/{id}/download` | Public | Download the file (forces `Content-Disposition: attachment` for non-image/PDF types) |
| GET | `/attachments/batch` | Admin (`jetonomy_manage_settings`) / Moderator (`moderate_comments`) | Batch-read attachments for many posts or replies in one call |

These routes are registered under `jetonomy-pro/v1`. `{id}` on `DELETE` and the download route is the attachment **link** ID, not the WordPress attachment ID.

**POST /attachments - body**

```javascript
{
    object_type:   'post',   // or 'reply'
    object_id:     101,
    attachment_id: 4820,     // WP attachment ID from POST /jetonomy/v1/media
    sort:          0,        // optional
}
```

The file is re-validated against the allow-list on attach (defence in depth), and the per-object file cap from the Attachments settings is enforced server-side.

**GET /attachments/batch - parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `object_type` | string | Yes | `post` or `reply` |
| `object_ids` | string | Yes | Comma-separated list of IDs |

Posts and replies also carry an `attachments[]` array directly on their normal `GET`/list responses (injected via `jetonomy_rest_prepare_post` / `jetonomy_rest_prepare_reply`), so most clients never need to call these routes directly except to attach or detach a file.

---

## Error Responses

All errors follow the standard WP REST API format:

```json
{
  "code":    "rest_forbidden",
  "message": "You are not allowed to do this.",
  "data":    { "status": 403 }
}
```

Common error codes:

| Code | HTTP | Meaning |
|------|------|---------|
| `rest_forbidden` | 403 | Missing capability or nonce |
| `jetonomy_user_banned` | 403 | The authenticated user is banned from the community (mutation routes) |
| `jetonomy_pending_verification` | 403 | The authenticated user has not confirmed their email yet (mutation routes) |
| `rest_not_found` | 404 | Resource does not exist |
| `validation_error` | 422 | Invalid or missing parameters |
| `rate_limited` | 429 | Too many requests from this user |

**Account-status enforcement (1.6.0)**

Every write mutation rejects banned users (`jetonomy_user_banned`) and users who still owe email verification (`jetonomy_pending_verification`), both with HTTP 403. This runs inside the shared mutation permission callback (`REST_Auth::auth_mutation()`), so it applies uniformly to every mutation route. It fires even for requests authenticated with an Application Password: those credentials are minted outside the normal login flow, so enforcing the checks here closes a bypass where a banned or unverified account could otherwise still post through the API.

---

## What's Next?

- [Hooks Reference](./02-hooks-reference.md) - React to Jetonomy events in your own plugin
- [Template Overrides](./03-template-overrides.md) - Customize the frontend without touching plugin files
- [Adapter System](./05-adapters.md) - Swap the search backend or add a real-time layer
