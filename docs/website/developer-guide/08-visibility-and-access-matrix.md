Jetonomy 1.4.1 introduced the **Public / Private community toggle** documented in [Access Control Settings](../admin-settings/07-access-control.md). On the code side that toggle is enforced through two pieces:

1. A small helper class - `Jetonomy\Visibility` - that every front-end template and REST permission callback can call to decide "is this caller allowed to see community content right now?"
2. A shell script - `bin/access-matrix-check.sh` - that walks every public REST route as every role in both modes and asserts the responses match the documented contract.

This page covers both.

**Namespace:** `Jetonomy\`
**Source:** `includes/class-visibility.php`, `bin/access-matrix-check.sh`

---

## Why the helper exists

Before 1.4.1 the public/private check was sprinkled across templates, the template loader, and individual REST controllers. Each call site had its own "is the community private and the user a guest?" expression, and any new endpoint had to remember to repeat the pattern. The fastest way to ship a leak was to forget the check.

`Jetonomy\Visibility` consolidates that into one read of `jetonomy_settings.guest_read`. The front-end template loader and every public-read REST endpoint route through the same helper, so they answer the same question with the same logic. New endpoints opt into the gate with a single line.

The helper deliberately does **not** look at per-resource visibility (private spaces, blocked users, restricted posts). Those remain the responsibility of individual controllers - `Visibility` only answers the global "can this caller see ANY community content right now?" question.

---

## API Reference

### `Visibility::can_view_community()`

Returns `true` if the current request should be allowed to see community content, `false` otherwise. In public mode this is always `true`. In private mode it requires the caller to be authenticated.

**Returns:** `bool`

**Example - gating a custom template fragment:**

```php
add_action( 'jetonomy_sidebar_before', function () {
    if ( ! \Jetonomy\Visibility::can_view_community() ) {
        return;
    }
    echo do_shortcode( '[my_member_only_widget]' );
} );
```

The check is global, not per-resource - you do not need to pass a user ID or a post ID. Per-resource visibility (private spaces, restricted access rules) is enforced separately inside the relevant controllers.

---

### `Visibility::get_mode()`

Returns the active visibility mode as a string. Useful when you want to render a different UI in private mode (e.g. a "Members only" badge in the site header) without duplicating the option read.

**Returns:** `string` - `'public'` or `'private'`

**Example - tagging the body class:**

```php
add_filter( 'body_class', function ( array $classes ): array {
    $classes[] = 'jt-mode-' . \Jetonomy\Visibility::get_mode();
    return $classes;
} );
```

The function defaults to `'public'` on a fresh install - an unset, null, or `true` value of `guest_read` all resolve to public. Only an explicit `false` flips the community to private.

---

### `Visibility::rest_check()`

Designed to be used as a REST `permission_callback`. Returns `true` when the caller may proceed, or a 401 `WP_Error` with code `community_private` when the community is in private mode and the caller is not logged in.

**Returns:** `true|\WP_Error`

**Example - protecting your own REST route:**

```php
register_rest_route( 'my-plugin/v1', '/community-events', [
    'methods'             => 'GET',
    'callback'            => 'my_plugin_list_events',
    'permission_callback' => [ '\Jetonomy\Visibility', 'rest_check' ],
] );
```

**Example - chaining with an existing capability check:**

```php
'permission_callback' => static function ( $request ) {
    $vis = \Jetonomy\Visibility::rest_check( $request );
    if ( is_wp_error( $vis ) ) {
        return $vis;
    }
    return current_user_can( 'read' );
},
```

Anonymous calls in private mode return:

```json
{
    "code": "community_private",
    "message": "This community is private. Please log in to view content.",
    "data": { "status": 401 }
}
```

Authenticated calls fall through to your route's own permission logic.

> **Note:** `/auth/*` and `/admin/*` endpoints intentionally do not route through this helper. Locking `/auth/*` would lock users out forever, and admin endpoints have their own capability gates. Apply `Visibility::rest_check` to public-read endpoints only.

---

## Per-resource visibility (1.8.0)

`Visibility` answers the global "can this caller see ANY community content?" question. The rules below sit *underneath* it and decide which individual spaces and posts a viewer may see. As of 1.8.0 they route through shared primitives so REST, the web, and the app cannot disagree.

### One space-listing predicate for every surface

`Space::listing_visibility_sql( ?int $user_id, string $alias = '' )` (`includes/models/class-space.php:254`) is the single SQL predicate every listing surface applies - the community home, search, full-text, `Space::list_visible()` (`class-space.php:524`), and REST `GET /spaces` (`includes/api/class-spaces-controller.php:349`). Before 1.8.0 the REST listing hand-rolled a "public OR member" rule while the web went through this predicate, so the two drifted (measured live: 32 spaces on the web vs 26 from REST for the same viewer). They now share this one predicate.

What it resolves to:

- **Guest** (`user_id <= 0`): public spaces only.
- **Member:** public + `private` spaces, plus any `hidden` space they belong to.
- **Admin** (`manage_options`): all spaces (`1=1`).

Owners can widen or narrow the rule per site with the `jetonomy_space_listing_visibility_sql` filter, which now reaches REST too rather than only half the surfaces.

`private` and `hidden` are distinct states (`Space::visibility_levels()`, `class-space.php:653`): a **private** space is *discoverable but unreadable* (its card shows, its posts do not); a **hidden** space is *neither* - only members can find it. A discoverable card does not leak content: post visibility stays gated separately.

### Hidden spaces are front-end-manageable and forced invite-only

Both front-end space forms - create (`templates/views/new-space.php`) and edit (`templates/views/space-edit.php`) - offer all three visibility levels through `\Jetonomy\space_visibility_options()` (`includes/functions.php:521`), which is derived from `Space::visibility_levels()` so no surface can drift out of sync. A hidden space is forced onto the `invite` join policy by `Space::validate_visibility_join_policy()` (`class-space.php:689`) - a hidden space with an open/approval policy is a contradiction (the listing hides it while the gate lets anyone with the slug self-join). Front-end invite-link generation lives on the members page (`templates/views/space-members.php`), so an owner never has to drop into wp-admin to invite someone into a hidden space.

### Post read gate: trashed/pending content returns 403

`Permission_Engine::can_read_post( int $user_id, object $post )` (`includes/permissions/class-permission-engine.php:357`) is the one status gate, hoisted in 1.8.0 out of `single-post.php` so REST, oEmbed, JSON-LD and the updates poller all inherit it. Deleting a post is a **soft** delete (status set to `trash`, row kept), so any non-`publish` post - trashed or sitting in the moderation queue - returns `false` to anyone who is neither its author nor a space moderator. On `GET /posts/{id}` that surfaces as a `403` (`includes/api/class-posts-controller.php:360`). The author and moderators keep access, matching the established single-post contract.

### Blocked-author content is tombstoned server-side

When a viewer has blocked a post's author, the post's `title`, `content`, and `content_plain` are emptied **server-side** by `Post::apply_block_tombstone()` (`includes/models/class-post.php:241`), applied to the row inside `prepare_post()` before any field is read (`class-posts-controller.php:1136`). The `blocked_author` flag on the response is therefore **advisory only** (`class-posts-controller.php:1236`): a client that ignores it still cannot render the blocked author's words. This runs on the by-id / by-slug (deep-link) paths that bypass the list-query SQL filters; list surfaces already exclude blocked authors in SQL. Guests block nobody, so a crawler sees byte-identical public output.

---

## The Access Matrix Runner

`bin/access-matrix-check.sh` is the regression safety net that proves the helper actually works. It walks a representative subset of every Jetonomy REST route as every role, makes the real HTTP call, and asserts the response code matches the documented expectation.

### What it covers

- **78 checks** across the public REST surface
- **6 roles** - anonymous, subscriber, author, editor (space-admin), moderator, administrator
- **Both modes** - the runner can flip `jetonomy_settings.guest_read` to private for the duration of the run and restore it on exit (even if a check fails midway)

In public mode the runner asserts that anonymous reads return `200`. In private mode it asserts the same anonymous reads return `401` from the central `Visibility::rest_check` gate. Logged-in calls are mode-independent and stay unchanged in either mode.

### Running it locally

```bash
# From the plugin root:
bin/access-matrix-check.sh                # public mode (default)
bin/access-matrix-check.sh --mode=private # private community gate
bin/access-matrix-check.sh --quiet        # only show failures
```

Sample output:

```
PASS  GET   /spaces             [anon]       expected=200  got=200
PASS  GET   /spaces             [subscriber] expected=200  got=200
PASS  POST  /posts/123/vote     [anon]       expected=401  got=401
FAIL  GET   /posts/recent       [anon]       expected=401  got=200  <- regression

Summary: 77 passed, 1 failed (78 total)
```

A regression is any row where the actual response code does not match the documented expectation. Exit code is `0` on a clean run, `1` if any row fails.

### Release gating

`bin/build-release.sh` invokes the access matrix runner before producing the release zip. If any row regresses, the build aborts and no zip is produced - the gate exists so we never ship a release that quietly opens up a previously-locked endpoint or quietly locks a previously-open one.

Run the matrix locally before every commit that touches `permission_callback` wiring, the `Visibility` helper, or REST route registration.

---

## What's Next?

- [Hooks Reference](./02-hooks-reference.md) - All `jetonomy_*` actions and filters
- [REST API Reference](./01-rest-api.md) - Full endpoint listing with permission contracts
- [Modal Toolkit](./09-modal-toolkit.md) - Front-end `jetonomyConfirm` / `jetonomyAlert` / `jetonomyPrompt` API
