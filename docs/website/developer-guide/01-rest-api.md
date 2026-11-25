# REST API

Jetonomy exposes a full REST API under the `jetonomy/v1` namespace: 81 routes (109 method-endpoints) in the free plugin, and 141 routes (193 method-endpoints) with Jetonomy Pro active. All endpoints return JSON and integrate with WordPress nonce authentication via the `wp_rest` nonce.

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
| POST | `/spaces` | `manage_options` | Create a space. `type` is one of `forum`, `qa`, `ideas`, `feed` (omit it to use Settings > Default Space Type); any other value returns `400`. |
| GET | `/spaces/{id}` | Public | Get a single space |
| PATCH | `/spaces/{id}` | Moderator / Admin | Update space settings. Accepts `sort_order` to place the space within its category. An unknown `type` returns `400` and nothing is saved. |
| DELETE | `/spaces/{id}` | Space admin | Remove a space. `?mode=transfer` (default) hands it to a successor and archives it, keeping all content, and returns `deleted:false`. `?mode=purge` destroys it and everything in it, returns `202`, and runs in the background. Purge additionally requires `manage_options` unless the site owner has allowed space admins to purge. |
| GET | `/spaces/{id}/members` | Public / Members only if private | List space members |
| POST | `/spaces/{id}/members` | Logged in | Join a space |
| PATCH | `/spaces/{id}/members/{user_id}` | Moderator / Admin | Change a member's role |
| DELETE | `/spaces/{id}/members/{user_id}` | Moderator / Admin | Remove a member |
| POST | `/spaces/{id}/invite` | Space admin | Generate an invite link |
| POST | `/invite/{token}` | Logged in | Accept an invite and join (POST since 2.0.3; it was GET) |
| GET | `/spaces/{id}/privileged-members` | Public | List admins and moderators of a space |
| GET | `/spaces/{id}/access-rules` | `jetonomy_manage_spaces` | List the membership access rules gating a space. Added 1.9.4. |
| POST | `/spaces/{id}/access-rules` | `jetonomy_manage_spaces` | Add an access rule (membership level / role / tag that grants access). Added 1.9.4. |
| PATCH | `/access-rules/{rule_id}` | `jetonomy_manage_spaces` | Change what a rule grants (`grants`: read or participate); its recorded role follows. Added 2.0.7. |
| DELETE | `/access-rules/{rule_id}` | `jetonomy_manage_spaces` | Remove an access rule. Note the path is top-level, not nested under `/spaces/{id}` - the rule id is globally unique. Added 1.9.4. |
| GET | `/spaces/{id}/join-requests` | Space admin | List pending requests to join this space. When a `PATCH /spaces/{id}` (or the admin screen, or `wp jetonomy space update`) changes `join_policy` / `visibility` so the space no longer takes requests, the pending ones are settled at once: approved and added as members if the space is now open, denied if it is now invite-only. Each requester gets the usual approved / not-approved notification. |
| DELETE | `/spaces/{id}/join-requests/mine` | Logged in | Withdraw your own pending request to join this space; the staff notices about it are removed. 404 when you have none. |
| POST | `/spaces/{id}/join-requests/{request_id}/approve` | Space admin | Approve a join request and add the member. The response carries a ready-to-show `message` ("Join request approved.") |
| POST | `/spaces/{id}/join-requests/{request_id}/deny` | Space admin | Deny a join request. The response carries a ready-to-show `message` ("Join request denied.") |
| GET | `/spaces/{id}/invites` | Space admin | List the space's invite links |
| DELETE | `/spaces/{id}/invites/{invite_id}` | Space admin | Revoke an invite link. Links already used stay used. |

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

> **One shape per resource (1.9.1).** A post or space is serialized by one shared routine, so the same resource comes back identical whether you read it from a list, a feed, a search result or a single-item route. Previously each controller assembled its own payload and they had drifted - a field present in a single read could be missing from the same object in a search result. If you were compensating for that in a client, you can stop.
>
> Post and reply payloads also carry **`author_last_seen_at`** (ISO 8601 string, or `null` when the author is anonymized or the value is unknown), so a client can show presence without a second request per row. The exact time goes only to the member themself and to site staff. Everyone else gets what the online dot shows: the current time while the member is online (active in the last 5 minutes), otherwise `null`. The same rule applies to `last_seen_at` on users, space members and the leaderboard.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/spaces/{space_id}/posts` | Public | List posts in a space |
| POST | `/spaces/{space_id}/posts` | Logged in | Create a post |
| GET | `/posts/{id}` | Public | Get a single post. Does not count a view |
| PATCH | `/posts/{id}` | Author / Moderator | Update a post. On a scheduled draft, `published_at` moves the publish time (site timezone unless it carries an offset; must be in the future) and `""` cancels the schedule. On a live post `published_at` backdates it and needs `manage_options`. |
| DELETE | `/posts/{id}` | Author / Moderator | Move a post to trash. `?force=true` deletes it permanently with all its replies (space moderators and admins only). Restore with `POST /spaces/{space_id}/moderation/approve/post/{id}` |
| POST | `/posts/{id}/close` | Moderator / Admin | Close (`closed: true`) or reopen (`false`); omitted, it toggles |
| POST | `/posts/{id}/pin` | Moderator / Admin | Pin (`pinned: true`) or unpin (`false`); omitted, it toggles |
| POST | `/posts/{id}/view` | Public (no nonce) | Count one view of a published topic the requester can read. Counted at most once per IP per topic every 30 minutes; returns `{ "counted": bool }`. Missing, unpublished and unreadable topics all return the same 404. The topic page calls it once per browser session; custom clients call it once when they display a topic |
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
| DELETE | `/replies/{id}` | Author / Moderator | Move a reply to trash. `?force=true` deletes it permanently (space moderators and admins only). Restore with `POST /spaces/{space_id}/moderation/approve/reply/{id}` |
| POST | `/replies/{id}/accept` | Post author / Moderator | Accept as answer. Safe to retry: repeated or simultaneous calls award the answerer's reputation once. |
| DELETE | `/replies/{id}/accept` | Post author / Moderator | Un-accept a reply, returning the topic to unanswered |
| POST | `/replies/{id}/split` | Moderator / Admin | Split this reply into a new standalone post |

**GET /posts/{post_id}/replies - parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 30 | Max 100 |
| `sort` | string | `oldest` | `oldest`, `newest`, `best` |

**POST /replies/{id}/accept**

Marks this reply as the accepted answer. Only the original post author or a moderator can call this, and only in a Q&A space (`400 jetonomy_not_qa_space` otherwise). Fires the `jetonomy_reply_accepted` action hook and awards +15 reputation to the reply author. Re-accepting the reply that is already accepted returns 200 without firing the hook or awarding again. `wp jetonomy reply accept` runs the same transaction.

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

Full-text search across Posts, Replies, Spaces, and Tags. Uses MySQL `FULLTEXT` with Boolean Mode by default. A plugin that registers a `Search_Query_Adapter` (Meilisearch, Elasticsearch, etc.) takes over this route, the search page and the app. See [05-adapters.md](./05-adapters.md).

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
| POST | `/tags` | `jetonomy_manage_settings` | Create a tag (name, optional slug/description). Added 1.9.4. |
| PATCH | `/tags/{id}` | `jetonomy_manage_settings` | Rename or edit a tag. Added 1.9.4. |
| DELETE | `/tags/{id}` | `jetonomy_manage_settings` | Delete a tag. Added 1.9.4. |

> **New in 1.9.4:** tag create/rename/delete over REST, so tag management works from the app and from integrations instead of only inside wp-admin.

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

**Target fields on each row (added in 1.8.0)**

`GET /subscriptions` returns the raw row (`id`, `user_id`, `object_type`, `object_id`, `via`, `created_at`) plus three fields resolved from whatever the subscription points at, so a client can render the target's name instead of a row of identical links:

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | The post or space title (empty string when the target is gone) |
| `slug` | string | The target's slug |
| `exists` | boolean | `false` when the target no longer exists - a hard-deleted post/space, or a post that has been trashed or marked spam. A pending or draft post the caller is subscribed to still returns `exists=true` |

These are batch-loaded - two queries per page (one for posts, one for spaces), never a lookup per row.

**`via` is the effective delivery channel (changed in 2.0.1)**

`via` reports how the caller will actually be notified about the followed item: `both`, `web`, `email`, or `none`. It is computed from the same rules the notifier uses (the member's master email opt-out, their per-type preferences, then the site's notification defaults) for the notification type the follow produces - `new_post_in_sub` for a space, `reply_to_post` for a topic. Before 2.0.1 it echoed the value stored when the follow was created (always `both`), which never affected delivery. `none` is new: the member has turned both channels off for that type.

`POST /subscriptions` still accepts `via` (`web`/`email`/`both`) for backward compatibility, and still stores it, but it does not change delivery. To change how a member is notified, update their notification preferences. The response's `via` is the effective channel, like `GET`.

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
| POST | `/flags` | Logged in (`jetonomy_flag`) | Report a post, reply, or user (`object_type: post\|reply\|user`); server rejects missing targets (404), self-reports (400), and duplicates (409) |
| GET | `/posts/{id}/flags` *(new in 1.4.1)* | Moderator | The flags raised against a specific post |
| GET | `/moderation/flags` | Moderator | List all open flags |
| POST | `/moderation/flags/{id}/resolve` | Moderator | Resolve a flag (`valid` or `dismissed`) |
| GET | `/moderation/ban` | Moderator | List active restrictions. An IP address ban carries the address in `ip`, a null `user` and a null `reason` |
| POST | `/moderation/ban` | Moderator; for `space_ban` also that space's admin, or its moderator when `moderators_can_ban` is on (since 2.1.0) | Ban a user (global ban, space ban, silence) or an IP address (`type: ip_ban` with `ip`; no `user_id`). A space moderator may target only a member or viewer on that space's roster who is not its author (`403 jetonomy_cannot_ban_space_staff`, `404 jetonomy_not_space_member`) |
| DELETE | `/moderation/ban/{id}` | Moderator; for a space ban also whoever may issue it (space admin, or space moderator when allowed) | Remove a ban. Before 2.1.0 a space admin without site caps was refused here |
| GET | `/spaces/{id}/moderation/flags` | Space Admin | List flags filed within a specific space |
| POST | `/spaces/{id}/moderation/flags/{flag_id}/resolve` | Space Admin | Resolve a flag within a specific space |
| POST | `/spaces/{id}/moderation/{action}/{type}/{obj_id}` | Space Admin | Moderate content in a specific space (`action`: `approve`, `spam`, or `trash`; `type`: `post` or `reply`). `approve` on trashed content restores it |

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
    object_type: 'post',   // post | reply | user
    object_id:   42,
    reason:      'spam',   // spam | offensive | off_topic | harassment | other
    description: 'Optional free-text detail from the reporter',
}
```

Error paths: `404 jetonomy_flag_target_missing` (target does not exist), `400 jetonomy_flag_self` (self-report), `409 jetonomy_already_flagged` (duplicate by the same member), `403 silenced` (silenced members cannot report).

**POST /moderation/ban - body**

```javascript
{
    type:       'space_ban',            // global_ban | space_ban | silence | ip_ban (required)
    user_id:    123,                    // required except for ip_ban
    space_id:   9,                      // required for space_ban; a positive integer, 0 or less answers 400
    reason:     'Repeated spam',
    expires_at: '2026-12-01 00:00:00',  // omit for a permanent ban
    ip:         '203.0.113.7',          // ip_ban only
}
```

A ban or lift sends the member no notification, writes no activity entry and fires no hook.

**GET /spaces/{id}/moderation/flags - parameters** *(1.8.0)*

Brought to parity with the global `/moderation/flags` queue: the per-space queue now honours a `status` filter and paginates.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | `pending` | `pending`, `valid`, `dismissed`, `all` |
| `limit` | int | 20 | Page size |
| `offset` | int | 0 | Offset into the result set |

The response `meta` carries `total`, `has_more`, `count`, `offset`, and `space_id`; `total` and `has_more` reflect the requested `status`, not just the default. Each flag row includes a resolved `reporter` object (`id`, `display_name`, `user_login`, `avatar_url`) plus `resolved_by_name`, so the queue can name who filed and who resolved a report. Reporters are batch-loaded (one lookup per page, not per row).

> **Dev note (1.8.0):** the shared `paginated_response()` now raises a `_doing_it_wrong()` when a route paginates (item count < total) but passes no `offset` - guarding against a "Load more" that never ends. It fires only for the genuinely-paginated, offset-missing case.

---

## Leaderboards

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/leaderboards` | Public | Get top contributors |

**Parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `all` | `all`, `month` or `week` (`month` and `week` count members active in the last 30 or 7 days) |
| `limit` | int | 20 | 1 to 100 |
| `offset` | int | 0 | Rows to skip, for paging |

Only members with reputation above 0 are ranked, highest first. Members with the same reputation share one rank, and among them the newest account is listed first (`reputation DESC, user_id DESC`). That order lets the database read the existing reputation index instead of sorting every profile, so the first pages stay fast on large communities (about 0.25 ms at 20,000 profiles, against 14 ms before 2.1.0). The win is for the first pages: a deep page still costs more, because the database works through every row before the offset (an offset of 4,990 measured about 8 ms). Page through the board with `offset`; the route takes no cursor parameter.

**Response `meta`**

| Key | Type | Description |
|-----|------|-------------|
| `count` | int | Rows in this page |
| `total` | int | Ranked members for the period |
| `offset` | int | The offset this page was read from |
| `period` | string | The period this page was built for |
| `has_more` | bool | True when another page exists |
| `cursor_next` | int or null | The offset of the next page (`offset` + `count`), or `null` on the last page. Despite the name it is not an opaque cursor: send it back as `offset` to read the next page. |

---

## Users

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/users/me` | Logged in | Get the current user's profile |
| PATCH | `/users/me` | Logged in (own account) | Update the current user's profile |
| GET | `/users/{id}` | Public | Get a user's public profile. Read-only - there is no PATCH on this route, not even for administrators. A profile is edited only by its owner through `PATCH /users/me`. |
| GET | `/users/by-login/{login}` | Public | Look up a user by username (may contain @ or spaces; URL-encode it) |
| GET | `/users/{id}/posts` | Public | List posts by this user |
| GET | `/users/suggest` | Public | Typeahead - suggest users by name or login prefix |
| GET | `/users` | Moderator | List community members. Moderator-only - the public directory is `/users/suggest`. |
| DELETE | `/users/me` | Logged in (own account) | Delete the current account. Anonymises the member's content by default; pass `delete_content: true` to erase it. Refuses accounts holding `manage_options`, and is rate limited to 5 per hour per IP. |
| GET | `/users/me/blocks` | Logged in | List the members this member has blocked |
| POST | `/users/me/blocks` | Logged in | Block a member. Their content is hidden from this member's view. |
| DELETE | `/users/me/blocks/{user_id}` | Logged in | Unblock a member |

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

**Viewer-relative moderation fields (added in 1.9.4)**

So a client never offers an action the server will reject, user objects and post/reply author objects carry server-authoritative eligibility flags for the calling user. They are compute-only and account for the real target guards (never self, never an administrator, and - for a non-admin moderator - never another moderator).

| Field | Type | Where | Description |
|-------|------|-------|-------------|
| `can_block_author` | boolean | post/reply author, user payload | Whether the viewer may block this member. `false` for the viewer's own account and when blocking is unavailable, so clients stop offering a block that would fail. |
| `can_ban` | boolean | post/reply author, user payload | Whether the viewer (a moderator) may ban or restrict this member. Mirrors the server-side target guards; the ban endpoint still enforces them. |
| `restriction` | object \| null | single-user payload | The member's newest active restriction (`{ id, type }`, e.g. `silence`), or `null`. Only populated for viewers who can moderate. |

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
| POST | `/admin/users/trust-level` | `jetonomy_manage_users` | Manually set a user's trust level. Non-admins cannot change their own level, an administrator's or a moderator's (skipped as `forbidden`). |
| GET | `/admin/demo-data` | Admin (`manage_options`) | Whether demo data is loaded: `{ active, counts: { users, categories, spaces, posts, replies } }` |
| POST | `/admin/demo-data` | Admin (`manage_options`) | Import the demo community (replaces an earlier demo set). Body `confirm: true` is required (`400` without it). While the community has spaces or topics of its own the answer is `409` (`jetonomy_demo_real_content`) unless the body also sends `force: true`. Returns `201` with the same shape plus `message` |
| DELETE | `/admin/demo-data` | Admin (`manage_options`) | Remove the demo data; your own content is untouched. Body `confirm: true` is required (`400` without it). `404` when there is none |
| GET | `/admin/settings` | Admin (`manage_options`) | Every setting the API can change, as the site uses it: `{ settings: { key: value } }`. A setting the site never saved reads as its default (since 2.1.0 never `null`), so a body read here can be posted back. `reputation_points` reads as the points every action gives (the defaults with the site's overrides on top), and every map-valued setting (`reputation_points`, `rate_limits`, `trust_thresholds`, `notification_defaults`) is a JSON object, never `[]`. Secrets (`captcha_secret_key`, `fb_app_secret`) read as `"********"` when set. This returns more keys (64 today) than one POST accepts (50), so posting the whole GET body back answers `400`: send only the keys you change, or split a full copy into two POSTs. Since 2.1.0 |
| POST | `/admin/settings` | Admin (`manage_options`) | Change settings. Body `{ "settings": { "accent_color": "#336699" } }`, at most 50 keys. Each value goes through the same sanitizer as Jetonomy > Settings, so HTML is stripped. Strings have a length cap (text 1,000 characters, addresses 2,048, `custom_css` 65,535), lists an item cap (50 items of up to 200 characters for `trust_level_names` and `frontend_space_creation_roles`, 20 for `sidebar_widgets`) and numbers the range the settings screen allows; a value outside any of them answers `400`. An on/off setting takes `true`/`false`, `1`/`0`, `"1"`/`"0"` or `"true"`/`"false"` in any letter case (`"FALSE"`, `"True"`; also inside `notification_defaults`), and anything else, `null` included, answers `400`. A number setting takes a JSON number or a string of digits with an optional minus (`"12"`, `"-3"`); `"abc"`, `"5abc"`, `"0x1A"`, `"false"` or `true` answers `400` (`rest_invalid_param`), never a silent 0 or 5. In `reputation_points` an empty string `""` puts that action back on its default. An unknown key (`jetonomy_unknown_setting`), a value outside the key's schema (`rest_invalid_param`) or more than 50 keys answers `400` and writes nothing. Sending exactly `"********"` for a secret keeps the stored one; any other all-asterisk value answers `400`. Returns `{ settings, notices }`: the cleaned values of the keys sent, and a notice for every value that was refused and kept its old one (`jetonomy_invalid_<key>` for an address, `jetonomy_setting_not_stored` otherwise). When every value sent is refused nothing is saved. Writes are serialised: a write waits up to 5 seconds for another one to finish, then answers `503` (`jetonomy_settings_busy`) without saving. Fires `jetonomy_settings_updated` with only the keys whose value changed. Stricter than the form on purpose: a `jetonomy_manage_settings` delegate can save the form but not call this route. The role capability mapping is not reachable here. Since 2.1.0 |

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

`app_enabled` is the fail-closed gate that decides whether the mobile app signs in at all. It defaults to `false` in the free plugin. Jetonomy Pro flips it to `true` through the `jetonomy_app_config` filter, whenever Pro is active. The Pro license controls updates only, so an expired or missing license does not turn the app off. When it is `false` (a free-only install), the app shows a "requires Jetonomy Pro" screen and refuses to sign in.

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

The overview's `post_change`, `reply_change`, `user_change`, `active_change` and `vote_change` are `null` when the previous period had no activity (a rise from zero has no percentage). Draw charts from `trend`; `daily_posts`, `daily_replies` and `daily_active` are deprecated since 2.0.7 and still returned for older clients.

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
| GET | `/posts/{id}/reactions` | Public | List reactions on a post. Pass `emoji` to also get `reactors` (`label`, `total`, up to 10 `names`) |
| POST | `/posts/{id}/reactions` | Logged in (`jetonomy_vote`) | Toggle an emoji reaction on a post |
| GET | `/replies/{id}/reactions` | Public | List reactions on a reply. Accepts `emoji` like the post route |
| POST | `/replies/{id}/reactions` | Logged in (`jetonomy_vote`) | Toggle an emoji reaction on a reply |

### Custom Badges (`custom-badges` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/badges` | Public | List active badges, 25 a page by default (`per_page` up to 100, `page` from 1; a zero, negative or non-numeric value falls back to the default). The response's `meta` has `total`, `page`, `per_page`, `count`, `has_more` and `cursor_next`, which since 2.1.0 is the next page number (or `null` on the last page), not an opaque cursor. |
| POST | `/badges` | Admin (`manage_options`) | Create a new badge definition |
| GET | `/badges/{id}` | Public | Get a single badge with its earned count |
| PATCH | `/badges/{id}` | Admin (`manage_options`) | Update a badge definition |
| DELETE | `/badges/{id}` | Admin (`manage_options`) | Delete (deactivate) a badge definition |
| GET | `/users/{id}/badges` | Public | List the badges a user has earned |
| POST | `/badges/{id}/award` | Admin (`manage_options`) | Manually award the badge to a user (`user_id` in body) |
| DELETE | `/badges/{id}/award` | Admin (`manage_options`) | Revoke the badge from a user (`user_id` in body) |

`GET /badges` pages with `page` (default 1) and `per_page` (default 25, max 100), not `limit` and `offset`. It is the one route where `meta.cursor_next` is a page number: it holds `page + 1` (or `null` on the last page), so send it back as `page`. Its `meta` also carries `page` and `per_page`. Out-of-range values are corrected, not rejected, and the response is still a 200: a `per_page` above 100 is clamped to 100, and zero, negative or non-numeric values fall back to the defaults (`per_page` 25, `page` 1). `meta.per_page` and `meta.page` show the values the server used. Every other paginated route returns the next offset in `cursor_next`.

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
| GET | `/settings/white-label` | Admin (`manage_options`) | Get current white-label settings (brand name, admin menu label and icon, admin footer text) |
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
| `rest_forbidden` | 401/403 | Not logged in, or a missing or expired nonce |
| `jetonomy_forbidden` | 403 | Logged in but not allowed to do this. Only given for something you can see: a topic or reply you cannot read answers `jetonomy_not_found`, and a hidden space `jetonomy_space_not_found`, instead, on write routes too |
| `jetonomy_user_banned` | 403 | The authenticated user is banned from the community (mutation routes) |
| `jetonomy_pending_verification` | 403 | The authenticated user has not confirmed their email yet (mutation routes) |
| `jetonomy_not_found` | 404 | Resource does not exist (or is not visible to you) |
| `jetonomy_space_not_found` | 404 | The space does not exist (or is hidden from you). Every route, ability and WP-CLI command that names a space answers this one code, reads and writes alike, since 2.1.0 (before, most read routes said `jetonomy_not_found`) |
| `jetonomy_validation` | 400 | Invalid or missing parameters |
| `jetonomy_rate_limited` | 429 | Too many requests from this user |

Every Jetonomy error code starts with `jetonomy_`, so a client can tell them apart from WordPress core's `rest_*` codes.

**Account-status enforcement (1.6.0)**

Every write mutation rejects banned users (`jetonomy_user_banned`) and users who still owe email verification (`jetonomy_pending_verification`), both with HTTP 403. This runs inside the shared mutation permission callback (`REST_Auth::auth_mutation()`), so it applies uniformly to every mutation route. It fires even for requests authenticated with an Application Password: those credentials are minted outside the normal login flow, so enforcing the checks here closes a bypass where a banned or unverified account could otherwise still post through the API.

---

## What's Next?

- [Hooks Reference](./02-hooks-reference.md) - React to Jetonomy events in your own plugin
- [Template Overrides](./03-template-overrides.md) - Customize the frontend without touching plugin files
- [Adapter System](./05-adapters.md) - Swap the search or email backend behind the registry
