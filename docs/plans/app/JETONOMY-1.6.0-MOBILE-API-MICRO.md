# Jetonomy 1.6.0 — Mobile API, Micro File-by-File Build Plan

**Status:** SHIPPED in 1.6.0 - verified 2026-07-04. All sections (app-config, feed, push/register-device, prepare_space viewer context) present in code.
**Parent contract:** [`JETONOMY-1.6.0-MOBILE-API.md`](./JETONOMY-1.6.0-MOBILE-API.md)
**Consumer:** [`vapvarun/jetonomy-app`](https://github.com/vapvarun/jetonomy-app) (React Native / Expo)
**Branches:** free `jetonomy@1.6.0-dev` · pro `jetonomy-pro@1.6.0-dev`
**Auth:** WP core Application Passwords only. **No JWT / `/auth/token` endpoint is added.**

This document is the "missing plugin layer" for the app. Every item below is grounded in the
current code: route registration matches `register_rest_route` conventions in
`includes/api/class-spaces-controller.php` / `class-posts-controller.php`; permission callbacks
reuse `\Jetonomy\Visibility::rest_check` (public-aware read) and
`\Jetonomy\API\REST_Auth::auth_mutation('read')` (login + nonce/app-password for writes); new
controllers are auto-loaded by the `$classes` map in `includes/api/class-api.php`; Pro work
extends the existing `web-push` extension (`Jetonomy_Pro\Extension` base, `Queue::async`).

Backward-compatibility rule for every contract enrichment: **add fields, never rename or remove.**
All new `prepare_*` keys are additive and null-safe for logged-out callers.

---

## A. Controller auto-load wiring (free)

**File (edit):** `includes/api/class-api.php`
Add two entries to the `$classes` map (keys are kebab → `class-<key>-controller.php`):

```php
'app-config' => 'App_Config_Controller',
'feed'       => 'Feed_Controller',
```

No other change — the loop already `require_once`'s `class-app-config-controller.php` /
`class-feed-controller.php`, news up the FQN, and calls `register_routes()`.

---

## 1. `GET /app/config` — white-label + feature block (free)

**File (new):** `includes/api/class-app-config-controller.php`
**Branch:** free 1.6.0-dev

```php
namespace Jetonomy\API;
class App_Config_Controller extends Base_Controller {
    protected $rest_base = 'app/config';

    public function register_routes() {
        register_rest_route(
            $this->namespace,            // 'jetonomy/v1'
            '/app/config',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_config' ),
                'permission_callback' => '__return_true', // public: pre-login theming
            )
        );
    }

    public function get_config( \WP_REST_Request $request ): \WP_REST_Response { /* … */ }

    private function feature_flags(): array { /* reads jetonomy_pro_extensions */ }
    private function branding(): array { /* reads jetonomy_pro_white_label + jetonomy_settings */ }
}
```

**Permission:** `__return_true` (public read — matches `/push/vapid-key`, `/push/service-worker.js`).

**Sourcing (free cannot call Pro classes — read shared options + a capability probe):**
- `pro_active` = `defined( 'JETONOMY_PRO_VERSION' )` (set in pro bootstrap).
- Branding: `get_option( 'jetonomy_pro_white_label', array() )` when `pro_active`, fields
  `accent_color`, `logo_url` (fallback `header_logo_url`), plus `login_bg_url` (white-label
  has no bg field today → read `$wl['login_bg_url'] ?? ''`, default `''`). When pro inactive
  or empty, fall back to `get_option( 'jetonomy_settings', array() )` accent + `''`.
- `dark_mode_default` = `(bool) ( $settings['dark_mode_default'] ?? false )` from
  `jetonomy_settings`.
- `features.*`: `$ext = (array) get_option( 'jetonomy_pro_extensions', array() );` then map ids
  `private-messaging→messaging, reactions, polls, custom-badges→badges, custom-fields,
  web-push, web-push→native_push` to `in_array( $id, $ext, true )`. (Native push ships inside
  the web-push extension — item 3 — so it gates on the same id.)

**Return shape (200):**
```json
{ "accent_color":"#3B82F6", "logo_url":"…", "login_bg_url":"…", "dark_mode_default":false,
  "pro_active":true,
  "features":{ "messaging":true,"reactions":true,"polls":true,"badges":true,
               "custom_fields":true,"web_push":true,"native_push":true } }
```

**Filter:** wrap the assembled array in
`apply_filters( 'jetonomy_app_config', $data, $request )` before returning (lets pro/site
owners override branding or force-flag a feature). Document the filter in the manifest
`hooks_fired`.

**Manifest delta (free, `rest.endpoints[]`):**
```json
{ "route":"/app/config", "methods":["GET"],
  "handler":"App_Config_Controller::get_config",
  "permission":"__return_true", "auth":"public",
  "purpose":"White-label branding + feature flags for the mobile app (pre-login theming)" }
```
Also add `jetonomy_app_config` to the free manifest `hooks_fired` list.

**Test stub (free) — `tests/Api/AppConfigTest.php`:**
```php
it( 'serves app config publicly with a features block', function () {
    $res  = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/app/config' ) );
    $data = $res->get_data();
    expect( $res->get_status() )->toBe( 200 );
    expect( $data )->toHaveKeys( [ 'accent_color','logo_url','login_bg_url','dark_mode_default','pro_active','features' ] );
    expect( $data['features'] )->toHaveKeys( [ 'messaging','reactions','polls','badges','custom_fields','web_push','native_push' ] );
} );

it( 'lets jetonomy_app_config override accent_color', function () {
    add_filter( 'jetonomy_app_config', fn( $d ) => array_merge( $d, [ 'accent_color' => '#000000' ] ) );
    $res = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/app/config' ) );
    expect( $res->get_data()['accent_color'] )->toBe( '#000000' );
} );
```

---

## 2. `GET /feed` — global cross-space home feed (free)

Home has no global feed today; only `/spaces/{space_id}/posts` exists. Add a model query +
thin controller. **Big-site rule:** cursor/offset pagination, visibility gated in SQL, no N+1.

### 2a. Model query — `includes/models/class-post.php` (edit)
**Branch:** free 1.6.0-dev

Today `Post::list_by_space_visible()` is space-scoped and `Post::list_trending( $limit,
$space_id=null, $window_days )` is the only cross-space query (hot only, no pagination).
Add a paginated, sortable cross-space method that reuses the existing visibility helpers
(`Space::content_visibility_sql()` + `Fulltext_Search::visibility_clause()` already used by
`list_trending`):

```php
/**
 * Global cross-space feed, visibility-gated for $user_id (0 = anonymous → public spaces only).
 *
 * @param int    $user_id  Viewer (0 = logged-out).
 * @param string $sort     'hot' | 'new' | 'top'.
 * @param int    $limit    Page size (clamped 1..50).
 * @param int    $offset   Offset for pagination.
 * @param int    $window_days  Only used by 'top' (default 7; 0 = all-time).
 * @return array{posts:object[], total:int}
 */
public static function list_global_feed( int $user_id, string $sort = 'hot',
    int $limit = 20, int $offset = 0, int $window_days = 7 ): array;
```

- WHERE: `status='publish' AND is_private=0` + the visibility clause (member-or-public spaces).
- ORDER BY: `hot` → `hot_score` expression already in `list_trending`
  (`(vote_score + reply_count*2) / POW(hours_old+2, 1.5)`); `new` → `published_at DESC, id DESC`;
  `top` → `vote_score DESC, id DESC` (with `published_at >= NOW()-INTERVAL window_days DAY`
  when `window_days>0`).
- `total` via a parallel `SELECT COUNT(*)` with the same WHERE (for `X-WP-Total`/`TotalPages`).
- Index check: relies on existing `(status, created_at, space_id)`, `(vote_score)`,
  `(reply_count)` keys used by `list_trending`. If `top`'s `published_at` filter is not
  covered, add `KEY idx_feed_top (status, is_private, published_at)` in the schema + a manifest
  table note (capture as a TODO in the commit body if deferred).

### 2b. Controller — `includes/api/class-feed-controller.php` (new)

```php
namespace Jetonomy\API;
class Feed_Controller extends Base_Controller {
    protected $rest_base = 'feed';

    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/feed',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'list_items' ),
                'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
                'args'                => array_merge(
                    $this->get_collection_params(),
                    array(
                        'sort' => array(
                            'type'    => 'string',
                            'default' => 'hot',
                            'enum'    => array( 'hot', 'new', 'top' ),
                        ),
                        'window_days' => array( 'type' => 'integer', 'default' => 7, 'minimum' => 0 ),
                    )
                ),
            )
        );
    }

    public function list_items( \WP_REST_Request $request ) {
        $pagination = $this->get_pagination( $request );
        $sort       = sanitize_key( (string) $request->get_param( 'sort' ) );
        $result     = \Jetonomy\Models\Post::list_global_feed(
            get_current_user_id(),
            in_array( $sort, array( 'hot', 'new', 'top' ), true ) ? $sort : 'hot',
            max( 1, min( 50, (int) $pagination['limit'] ) ),
            (int) $pagination['offset'],
            (int) $request->get_param( 'window_days' )
        );
        $posts = $this->enrich_with_author( $result['posts'] );           // batch author load, no N+1
        $items = array_map( array( $this, 'prepare_feed_post' ), $posts ); // see note
        return $this->paginated_response( $items, array(
            'total'  => $result['total'],
            'offset' => (int) $pagination['offset'],
        ) );
    }
}
```

**`prepare_feed_post`:** to avoid duplicating the ~40-line `Posts_Controller::prepare_post`,
the cleanest long-term move is to **promote `prepare_post()` from `private` to `protected`** on
`Posts_Controller` and have `Feed_Controller extends Posts_Controller` (it already needs the
same enrichment + the item-5 fields below). That keeps one source of truth for the post shape
and automatically gives the feed the `is_bookmarked` / `viewer_vote` fields from item 5.
(Alternative if inheritance is undesirable: extract `prepare_post` into a `Post_Presenter`
trait shared by both controllers.)

**Permission:** `\Jetonomy\Visibility::rest_check` — public-aware; logged-out callers get the
public-space slice, members get their full visibility set (gating is in SQL, item 2a).

**Return shape:** standard `paginated_response` envelope (`{ data:[…post…], meta:{ total, … } }`)
with `X-WP-TotalPages` header — identical to `/spaces/{id}/posts`.

**Manifest delta (free):**
```json
{ "route":"/feed", "methods":["GET"],
  "handler":"Feed_Controller::list_items",
  "permission":"Visibility::rest_check", "auth":"public",
  "purpose":"Global cross-space home feed (sort=hot|new|top), visibility-gated, paginated" }
```

**Test stub (free) — `tests/Api/FeedTest.php`:**
```php
it( 'returns a paginated global feed and excludes private-space posts for anon', function () {
    $res = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/feed' ) );
    expect( $res->get_status() )->toBe( 200 );
    expect( $res->get_data() )->toHaveKey( 'data' );
    expect( (int) $res->get_headers()['X-WP-TotalPages'] )->toBeGreaterThanOrEqual( 1 );
} );

it( 'accepts sort=new|top and rejects an unknown sort', function () {
    foreach ( [ 'hot','new','top' ] as $s ) {
        $r = new WP_REST_Request( 'GET', '/jetonomy/v1/feed' ); $r->set_param( 'sort', $s );
        expect( rest_do_request( $r )->get_status() )->toBe( 200 );
    }
} );
```

---

## 3. `POST` + `DELETE /push/register-device` — native Expo push (pro)

Extend the existing **web-push** extension. Native Expo tokens are a separate transport from
browser `PushSubscription`; do **not** overload `/push/subscribe`.

**File (edit):** `includes/extensions/web-push/class-extension.php`
**Branch:** pro 1.6.0-dev

### 3a. New table (separate from `push_subscriptions`)
Add to `create_table()` (or a new `create_device_table()` called from `activate()`):
```sql
CREATE TABLE {prefix}jt_pro_push_devices (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  expo_push_token varchar(255) NOT NULL,
  platform varchar(16) NOT NULL,           -- 'ios' | 'android'
  device_name varchar(190) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  UNIQUE KEY uniq_token (expo_push_token)
) {charset};
```
Helper accessor: `$this->table( 'push_devices' )` → `wp_jt_pro_push_devices`.

### 3b. Routes (add inside `register_routes()`)
```php
register_rest_route(
    $ns, '/push/register-device',
    array(
        array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_register_device' ),
            'permission_callback' => \Jetonomy\API\REST_Auth::auth_mutation( 'read' ),
            'args'                => array(
                'expo_push_token' => array( 'type' => 'string', 'required' => true ),
                'platform'        => array( 'type' => 'string', 'required' => true, 'enum' => array( 'ios', 'android' ) ),
                'device_name'     => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ),
        array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'rest_unregister_device' ),
            'permission_callback' => \Jetonomy\API\REST_Auth::auth_mutation( 'read' ),
            'args'                => array(
                'expo_push_token' => array( 'type' => 'string', 'required' => true ),
            ),
        ),
    )
);
```

**Method signatures + stores** (mirror `rest_subscribe`/`store_subscription`):
```php
public function rest_register_device( WP_REST_Request $r ): WP_REST_Response|WP_Error;   // 201 { registered:true, created:bool }
public function rest_unregister_device( WP_REST_Request $r ): WP_REST_Response;           // 200 { unregistered:true }
private function store_device( int $user_id, string $token, string $platform, string $device_name ): bool; // INSERT … ON DUPLICATE KEY → update platform/name/user
private function remove_device( int $user_id, string $token ): void;                      // DELETE WHERE user_id AND expo_push_token
private function get_user_devices( int $user_id ): array;                                 // SELECT * WHERE user_id
```
Validate token shape (`ExpoPushToken[…]` / `ExponentPushToken[…]`) → 400 on mismatch.

### 3c. Expo fan-out in the notifier (and fix the pre-existing browser bug)

**Root-cause finding (must fix here):** `jetonomy_notification_created` fires **7 scalar args**
(`class-notifier.php:911` and `class-mentions.php:88`):
`( $notification_id, $user_id, $type, $object_type, $object_id, $message, $url )`.
The current `add_action( 'jetonomy_notification_created', …, 10, 1 )` binds
`on_notification_created( $notification )` and then reads `$notification->user_id` /
`->message` — i.e. it receives the **int `$notification_id`** and silently bails. **The
existing browser web-push delivery is therefore dead.** Re-bind to the real signature:

```php
add_action( 'jetonomy_notification_created', array( $this, 'on_notification_created' ), 10, 7 );

public function on_notification_created(
    int $notification_id, int $user_id, string $type,
    string $object_type, int $object_id, string $message, string $url
): void {
    if ( ! $this->is_enabled() ) { return; }
    // (existing browser fan-out, now fed real $user_id/$message/$url) …
    // NEW: native Expo fan-out
    $devices = $this->get_user_devices( $user_id );
    if ( empty( $devices ) ) { return; }
    $deep = $this->deep_link_for( $object_type, $object_id ); // ['type'=>'post|reply|conversation','id'=>int]
    foreach ( $devices as $d ) {
        Queue::async( self::ASYNC_HOOK_EXPO, array( array(
            'token' => $d->expo_push_token,
            'title' => $this->get_settings()['notification_title'] ?: get_bloginfo( 'name' ),
            'body'  => $message,
            'data'  => $deep,           // { type, id } deep-link payload
        ) ) );
    }
}
```

**Deep-link mapping** (`deep_link_for()`):
`post → {type:'post'}`, `reply → {type:'reply'}`, messaging notification `object_type`
(`conversation`/`message`) → `{type:'conversation'}`; default falls back to `post`. `id` =
`$object_id`.

**Expo sender** — new async hook + handler:
```php
private const ASYNC_HOOK_EXPO = 'jetonomy_pro_send_expo_push';
add_action( self::ASYNC_HOOK_EXPO, array( $this, 'do_send_expo_push' ), 10, 1 );

public function do_send_expo_push( array $args ): void {
    $resp = wp_remote_post( 'https://exp.host/--/api/v2/push/send', array(
        'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'to'    => $args['token'],
            'title' => $args['title'],
            'body'  => $args['body'],
            'data'  => $args['data'],   // { type, id } → app routes the deep link
            'sound' => 'default',
        ) ),
        'timeout' => 15,
    ) );
    // On DeviceNotRegistered ticket error → delete the token row (mirror the 410 cleanup
    // already done for browser endpoints in do_send_push()).
}
```
Cancel `ASYNC_HOOK_EXPO` in `deactivate()` alongside `ASYNC_HOOK`.

**Return shapes:** `POST` → `201 { registered:true, created:bool }`;
`DELETE` → `200 { unregistered:true }`.

**Manifest delta (pro, `rest.endpoints[]`):**
```json
{ "route":"/push/register-device", "methods":["POST","DELETE"],
  "handler":"Web_Push\\Extension::rest_register_device,rest_unregister_device",
  "permission":"REST_Auth::auth_mutation", "auth":"login_required",
  "purpose":"Register/unregister an Expo native push token (separate transport from /push/subscribe)" }
```
Add to pro manifest `tables[]`:
```json
{ "name":"{prefix}jt_pro_push_devices", "extension":"web-push",
  "purpose":"Expo native push device tokens (user_id, expo_push_token, platform, device_name)" }
```
Add `jetonomy_pro_send_expo_push` to the web-push subsystem's async hooks note.

**Test stub (pro) — `tests/Extensions/WebPush/RegisterDeviceTest.php`:**
```php
it( 'registers and unregisters an Expo device for the current user', function () {
    wp_set_current_user( $this->member_id );
    $post = new WP_REST_Request( 'POST', '/jetonomy/v1/push/register-device' );
    $post->set_param( 'expo_push_token', 'ExponentPushToken[abc123]' );
    $post->set_param( 'platform', 'ios' );
    expect( rest_do_request( $post )->get_status() )->toBe( 201 );

    $del = new WP_REST_Request( 'DELETE', '/jetonomy/v1/push/register-device' );
    $del->set_param( 'expo_push_token', 'ExponentPushToken[abc123]' );
    expect( rest_do_request( $del )->get_status() )->toBe( 200 );
} );

it( 'fans out an Expo send with a deep-link payload on jetonomy_notification_created', function () {
    // register device, swap Queue with a spy, fire the 7-arg action, assert
    // ASYNC_HOOK_EXPO enqueued with data => { type:'post', id:$post_id }.
} );

it( 'rejects an unauthenticated register-device call', function () {
    wp_set_current_user( 0 );
    $post = new WP_REST_Request( 'POST', '/jetonomy/v1/push/register-device' );
    $post->set_param( 'expo_push_token', 'ExponentPushToken[x]' ); $post->set_param( 'platform', 'ios' );
    expect( rest_do_request( $post )->get_status() )->toBe( 401 );
} );
```

---

## 4. `prepare_space()` enrichment — membership context (free)

**File (edit):** `includes/api/class-spaces-controller.php` → `prepare_space()`
**Branch:** free 1.6.0-dev · **additive, backward-compatible**

Append three viewer-relative fields before the `jetonomy_rest_prepare_space` filter
(all null-safe for `$user_id === 0`):
```php
$uid = get_current_user_id();
$data['is_member']     = $uid ? \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $uid ) : false;
$data['viewer_role']   = $uid ? \Jetonomy\Models\SpaceMember::get_role( (int) $space->id, $uid ) : null; // 'viewer'|'member'|'moderator'|'admin'|null
$data['is_subscribed'] = $uid ? \Jetonomy\Models\Subscription::is_subscribed( $uid, 'space', (int) $space->id ) : false;
```
Verified signatures: `SpaceMember::is_member( int $space_id, int $user_id )`,
`SpaceMember::get_role( int $space_id, int $user_id ): ?string`,
`Subscription::is_subscribed( int $user_id, string $object_type, int $object_id ): bool`.

**Perf note (big-site):** `prepare_space` runs per row in `list_items`. These are 3 indexed
point lookups per space; on the spaces list (≤ page size, default ~20) this is bounded. If a
future grid pushes page size higher, batch via `SpaceMember::list_user_spaces( $uid )` once and
hydrate from a set — capture as a TODO if not done now.

**Manifest delta:** update the `/spaces` and `/spaces/{id}` endpoint `audit_notes` /
response-shape note to list the three new fields (no route change).

**Test stub (free) — `tests/Api/SpaceShapeTest.php`:**
```php
it( 'adds membership context to the space object', function () {
    wp_set_current_user( $this->member_id );
    $res = rest_do_request( new WP_REST_Request( 'GET', "/jetonomy/v1/spaces/{$this->space_id}" ) );
    $d   = $res->get_data();
    expect( $d )->toHaveKeys( [ 'is_member','viewer_role','is_subscribed' ] );
    expect( $d['is_member'] )->toBeTrue();
} );
it( 'returns safe defaults for a logged-out viewer', function () {
    wp_set_current_user( 0 );
    $d = rest_do_request( new WP_REST_Request( 'GET', "/jetonomy/v1/spaces/{$this->public_space_id}" ) )->get_data();
    expect( $d['is_member'] )->toBeFalse(); expect( $d['viewer_role'] )->toBeNull();
} );
```

---

## 5. `prepare_post()` enrichment — bookmark + viewer vote (free)

**File (edit):** `includes/api/class-posts-controller.php` → `prepare_post()`
**Branch:** free 1.6.0-dev · **additive, backward-compatible**

Append before the `jetonomy_rest_prepare_post` filter:
```php
$uid = get_current_user_id();
$data['is_bookmarked'] = $uid ? \Jetonomy\Models\Bookmark::is_bookmarked( $uid, (int) $post->id ) : false;
$data['viewer_vote']   = $uid ? (int) ( \Jetonomy\Models\Vote::get_user_vote( $uid, 'post', (int) $post->id ) ?? 0 ) : 0; // -1|0|1
```
Verified signatures: `Bookmark::is_bookmarked( int $user_id, int $post_id ): bool`,
`Vote::get_user_vote( int $user_id, string $object_type, int $object_id ): ?int` (null → `0`).

**N+1 caution (big-site):** the feed (item 2) and `/spaces/{id}/posts` call `prepare_post` per
row. Two point lookups per post is acceptable for a 20–50 page, but to stay honest at 2000-row
scale add batch helpers and prefer them when an id-set is already in hand:
- `Bookmark::bookmarked_ids( int $user_id, array $post_ids ): int[]`
- `Vote::user_votes_map( int $user_id, string $object_type, array $object_ids ): array<int,int>`
Have `enrich_with_author()`'s callers seed `$post->viewer_vote` / `$post->is_bookmarked` from
these maps (same pre-enrich pattern already used for author fields), and let `prepare_post`
use the pre-set value when present. Wire the batch path in the same commit for `list_items` +
feed; the per-row fallback stays for single `get_item`. (If batch is deferred, record as a TODO
in the commit body — per the big-site checklist.)

**Manifest delta:** update `/posts/{id}` + `/spaces/{id}/posts` response-shape notes to list
`is_bookmarked`, `viewer_vote` (no route change).

**Test stub (free) — `tests/Api/PostShapeTest.php`:**
```php
it( 'exposes viewer bookmark + vote on a post', function () {
    wp_set_current_user( $this->member_id );
    \Jetonomy\Models\Vote::cast( $this->member_id, 'post', $this->post_id, 1 );
    \Jetonomy\Models\Bookmark::toggle( $this->member_id, $this->post_id );
    $d = rest_do_request( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->post_id}" ) )->get_data();
    expect( $d['is_bookmarked'] )->toBeTrue();
    expect( $d['viewer_vote'] )->toBe( 1 );
} );
it( 'returns viewer_vote 0 / is_bookmarked false for anon', function () {
    wp_set_current_user( 0 );
    $d = rest_do_request( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$this->public_post_id}" ) )->get_data();
    expect( $d['viewer_vote'] )->toBe( 0 ); expect( $d['is_bookmarked'] )->toBeFalse();
} );
```

---

## 6. Site-announcements — member read path (pro)

**Finding:** there is **no** announcements REST controller in **free**; announcements are the Pro
`site-announcements` extension (`includes/extensions/site-announcements/`). The app banner needs a
**member-readable** list route. Audit the extension's existing routes:

**File (edit):** `includes/extensions/site-announcements/class-extension.php`
**Branch:** pro 1.6.0-dev

- If the only read route is gated to `manage_options` (admin CRUD), **add** a member read route:
```php
register_rest_route(
    'jetonomy/v1', '/announcements/active',
    array(
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => array( $this, 'rest_list_active' ),
        'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ), // public-aware read
    )
);
public function rest_list_active( WP_REST_Request $r ): WP_REST_Response; // active, non-expired, audience-visible announcements
```
- If a member/public read route **already exists**, this item is a no-op beyond confirming the
  permission callback is `Visibility::rest_check` (not `manage_options`) and adding a contract
  test. **Verify before writing code** — do not duplicate an existing route.

**Return shape:** `{ data:[ { id, title, body, level, dismissible, starts_at, ends_at } ], meta:{ total } }`.

**Manifest delta (pro):** ensure the member read route is recorded with
`"permission":"Visibility::rest_check","auth":"public"` (or `login_required` if the extension
scopes announcements to members only).

**Test stub (pro) — `tests/Extensions/SiteAnnouncements/MemberReadTest.php`:**
```php
it( 'lets a non-admin member read active announcements', function () {
    wp_set_current_user( $this->member_id ); // NOT manage_options
    $res = rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/announcements/active' ) );
    expect( $res->get_status() )->toBe( 200 );
    expect( $res->get_data() )->toHaveKey( 'data' );
} );
```

---

## 7. Digest preferences — verify, spec only if missing

**Finding:** `/users/me/digest-preferences` does **not** exist in free. Email digest is the Pro
`email-digest` extension; free only exposes `PATCH /users/me` with a `notification_preferences`
sub-object + `email_opt_out` boolean (`includes/api/class-users-controller.php`).

**Decision:** **Verify in the Pro `email-digest` extension first.** If a digest route exists,
record it in the pro manifest and add a contract test. If missing, spec it on the **Pro**
email-digest extension (digest cadence is a Pro concept; keep it out of free):

**File (edit, only if missing):** `includes/extensions/email-digest/class-extension.php`
**Branch:** pro 1.6.0-dev
```php
register_rest_route(
    'jetonomy/v1', '/users/me/digest-preferences',
    array(
        array( 'methods' => 'GET',   'callback' => array( $this, 'rest_get_prefs' ),
               'permission_callback' => \Jetonomy\API\REST_Auth::auth_mutation( 'read' ) ),
        array( 'methods' => 'PATCH', 'callback' => array( $this, 'rest_update_prefs' ),
               'permission_callback' => \Jetonomy\API\REST_Auth::auth_mutation( 'read' ),
               'args' => array(
                   'frequency' => array( 'type' => 'string', 'enum' => array( 'off','daily','weekly' ) ),
                   'topics'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
               ) ),
    )
);
```
Store in user meta (`jetonomy_pro_digest_prefs`). Return `{ frequency, topics }`.

**Manifest delta (pro):** add the route if newly created. **Do not add to free.**

**Test stub (pro) — `tests/Extensions/EmailDigest/DigestPrefsTest.php`:**
```php
it( 'reads and updates the current user digest preferences', function () {
    wp_set_current_user( $this->member_id );
    $patch = new WP_REST_Request( 'PATCH', '/jetonomy/v1/users/me/digest-preferences' );
    $patch->set_param( 'frequency', 'weekly' );
    expect( rest_do_request( $patch )->get_status() )->toBe( 200 );
    expect( rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/users/me/digest-preferences' ) )->get_data()['frequency'] )->toBe( 'weekly' );
} );
```

---

## 8. Auth hardening — ban / verification in permission callbacks (free)

No new endpoint. Because Application Passwords mint credentials outside Jetonomy, the ban /
email-verification gates must be enforced in `REST_Auth::auth_mutation()` (the shared write
permission callback). **Verify** that `auth_mutation('read')` rejects a banned user and a
pending-verification user on write routes; if not, add the check there (one place covers
`POST /posts`, `/replies`, votes, `/conversations`, and the new `/push/register-device`).

**File (verify / edit):** `includes/api/class-rest-auth.php`
**Branch:** free 1.6.0-dev

**Contract test stub (free) — `tests/Api/AuthHardeningTest.php`:**
```php
it( 'blocks a banned user holding a valid app password on writes (403)', function () {
    wp_set_current_user( $this->banned_user_id );
    $r = new WP_REST_Request( 'POST', "/jetonomy/v1/spaces/{$this->space_id}/posts" );
    $r->set_param( 'content', 'x' );
    expect( rest_do_request( $r )->get_status() )->toBe( 403 );
} );
it( 'blocks a pending-verification user where the web flow blocks them', function () { /* … 403 … */ } );
```

---

## 9. Manifest drift corrections (no behavior change — manifest only)

### Free — `audit/manifest.json` (`rest.endpoints[]`)
Each route entry uses keys `route, methods, handler, permission, auth, capability,
ownership_check, purpose, audit_notes`.

| Drift | Current manifest | Correct to | Grounded in |
|---|---|---|---|
| `GET /replies/{id}` listed but not served | `/replies/(?P<id>\d+)` methods include `GET`, handler includes `get_item` | drop `GET` from `methods`; drop `get_item` from `handler` (only `PATCH`+`DELETE` → `update_item,delete_item`) | `class-replies-controller.php` registers only PATCH + DELETE |
| split method name | `Replies_Controller::split_item` | `Replies_Controller::split_reply` | method is `split_reply()` on `/replies/{id}/split` |
| accept route | `/replies/{id}/accept` methods `["POST"]`, handler `accept_answer` | methods `["POST","DELETE"]`, handler `accept_reply,unaccept_reply` | code registers POST→`accept_reply` **and** DELETE→`unaccept_reply` |
| `/users/suggest` permission | `permission:"__return_true"`, `auth:"public"` | `permission:"is_user_logged_in"`, `auth:"login_required"` | callback is `fn() => is_user_logged_in()` |

### Pro — `audit/manifest.json` (`rest.endpoints[]`)

**Reactions** — replace the stale generic block:
| Current (stale) | Correct (served) |
|---|---|
| `GET /reactions` → `rest_get_reactions` | `GET /posts/(?P<id>\d+)/reactions` → `get_post_reactions` |
| `POST /reactions` → `rest_toggle_reaction` | `POST /posts/(?P<id>\d+)/reactions` → `toggle_reaction` |
|  | `GET /replies/(?P<id>\d+)/reactions` → `get_reply_reactions` |
|  | `POST /replies/(?P<id>\d+)/reactions` → `toggle_reply_reaction` |

**Polls** — replace the stale block:
| Current (stale) | Correct (served) |
|---|---|
| `GET /polls/{id}` → `rest_get_poll` | `GET /posts/(?P<post_id>\d+)/poll` → `get_poll` |
| `POST /polls` → `rest_create_poll` | `POST /posts/(?P<post_id>\d+)/poll` → `create_poll` |
| `POST /polls/{id}/votes` → `rest_vote_poll` | `POST /polls/(?P<id>\d+)/vote` → `vote` |
|  | `DELETE /polls/(?P<id>\d+)/vote` → `unvote` |
|  | `PATCH /polls/(?P<id>\d+)` → `update_poll` |

> **Verify-before-write:** re-confirm the reactions/polls route blocks against the extension
> source at refresh time (line numbers move). Prefer regenerating via
> `/wp-plugin-onboard --refresh` over hand-editing if the divergence is large, then diff.

**No test needed** for manifest-only edits; the contract-audit baseline + `/wp-plugin-onboard`
refresh covers them. Re-run the contract audit after editing.

---

## Definition of done (this plan)

- [ ] Free: `App_Config_Controller`, `Feed_Controller` + `Post::list_global_feed()`; `class-api.php` map updated.
- [ ] Free: `prepare_space` (+3 fields), `prepare_post` (+2 fields), additive + batch path for lists.
- [ ] Free: `/users/suggest` + replies manifest drift fixed; auth-hardening contract tests green.
- [ ] Pro: `/push/register-device` POST+DELETE, `jt_pro_push_devices` table, Expo fan-out, **browser-push 7-arg handler bug fixed**, deep-link payload.
- [ ] Pro: announcements member read route verified/added; digest-preferences verified/added.
- [ ] Pro: reactions/polls manifest reconciled to served routes.
- [ ] Both manifests refreshed; contract-audit baseline updated; runbook D-rows added for `/app/config`, `/feed`, `/push/register-device`, `/announcements/active`.
```
