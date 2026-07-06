Jetonomy's REST API lives at `jetonomy/v1`. This page covers three extension points: adding fields to an existing response via the `jetonomy_rest_prepare_*` filters, registering a new endpoint in the same namespace, and hooking into the content moderation intercept `jetonomy_check_content`.

**Source references:**
- Response filters: `includes/api/class-posts-controller.php:1140`, `class-replies-controller.php:782`, `class-spaces-controller.php:1135`, `class-users-controller.php:250`, `class-notifications-controller.php:323`
- Auth helper: `includes/api/class-rest-auth.php`
- Moderation intercept: `includes/api/class-posts-controller.php:510`, `class-replies-controller.php:292`
- Base controller: `includes/api/class-base-controller.php`

---

## Adding a field to an existing response

Each core object type exposes a filter on the prepared response array immediately before it is returned to the client. Hook in, add your key, return the array.

### Available prepare filters

| Filter | Object type | Source |
|--------|-------------|--------|
| `jetonomy_rest_prepare_post` | Post row | `class-posts-controller.php:1140` |
| `jetonomy_rest_prepare_reply` | Reply row | `class-replies-controller.php:782` |
| `jetonomy_rest_prepare_space` | Space row | `class-spaces-controller.php:1135` |
| `jetonomy_rest_prepare_user` | `WP_User` | `class-users-controller.php:250` |
| `jetonomy_rest_prepare_notification` | Notification row | `class-notifications-controller.php:323` |

**Parameters (all five filters share this shape):**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$data` | `array` | The prepared response data array - modify and return this |
| `$object` | `object` | The raw model row (or `WP_User` for the user filter) |
| `$request` | `WP_REST_Request\|null` | The originating request; `null` in non-request contexts |

**Returns:** `array` - the modified data array.

### Example: append a gamification score to post responses

```php
add_filter( 'jetonomy_rest_prepare_post', function ( array $data, object $post, $request ): array {
    // Add a field from an external gamification plugin.
    $data['wb_gam_score'] = (int) WB_Gam\Scores::for_post( (int) $post->id );
    return $data;
}, 10, 3 );
```

### Example: append custom field values to replies

```php
add_filter( 'jetonomy_rest_prepare_reply', function ( array $data, object $reply, $request ): array {
    // Attach a "sentiment" label computed by a text-analysis service.
    $data['sentiment'] = get_post_meta( (int) $reply->id, 'my_sentiment', true ) ?: 'neutral';
    return $data;
}, 10, 3 );
```

### Example: add a badge count to user responses

```php
add_filter( 'jetonomy_rest_prepare_user', function ( array $data, \WP_User $user, $request ): array {
    $data['badge_count'] = (int) My_Badges::count_for_user( $user->ID );
    return $data;
}, 10, 3 );
```

> The frontend JavaScript reads response data via `window.jetonomyRest.restFetch`. Fields you add here are available in `result.data` without any further wiring.

---

## Registering a new `jetonomy/v1` route

Companion plugins can register additional endpoints inside the `jetonomy/v1` namespace on `rest_api_init`. This keeps all community API calls under one namespace and nonce context.

### Permission callbacks

Every mutation route (`POST`, `PUT`, `PATCH`, `DELETE`) **must** use `\Jetonomy\API\REST_Auth::auth_mutation()` as its `permission_callback`. Do not use raw closures, `is_user_logged_in()`, or `current_user_can()` - the audit script enforces this rule.

For read-only routes, use `\Jetonomy\Visibility::rest_check` (respects the Private Community setting) or `'__return_true'` for fully public data.

Since 1.6.0, `REST_Auth::auth_mutation()` (and the Pro wrapper `rest_auth_mutation()`) also hard-rejects banned users (`jetonomy_user_banned`) and users still pending email verification (`jetonomy_pending_verification`) with a 403, before the capability check runs. This holds even for Application-Password sessions. Extension authors inherit this account-status protection automatically by using the standard callback - there is nothing extra to wire.

> **Important for companion plugins:** `REST_Auth` is defined in the free plugin. If your plugin may be active before Jetonomy, resolve the class lazily inside the callback rather than in the registration call. Eager static calls at route-registration time can fatal the REST API if the free plugin loads later.

### Basic read endpoint

```php
add_action( 'rest_api_init', function () {
    register_rest_route(
        'jetonomy/v1',
        '/my-plugin/stats',
        array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => 'my_plugin_stats_handler',
            'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
        )
    );
} );

function my_plugin_stats_handler( \WP_REST_Request $request ): \WP_REST_Response {
    return rest_ensure_response( array(
        'total_posts' => My_Plugin::count_posts(),
    ) );
}
```

### Mutation endpoint with auth

```php
add_action( 'rest_api_init', function () {
    register_rest_route(
        'jetonomy/v1',
        '/my-plugin/notes/(?P<post_id>\d+)',
        array(
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => 'my_plugin_save_note',
                // Lazy resolution - safe if Jetonomy loads after this plugin.
                'permission_callback' => function ( \WP_REST_Request $r ) {
                    if ( ! class_exists( \Jetonomy\API\REST_Auth::class ) ) {
                        return new \WP_Error( 'jetonomy_not_loaded', 'Jetonomy is required.', array( 'status' => 503 ) );
                    }
                    return call_user_func( \Jetonomy\API\REST_Auth::auth_mutation( 'read' ) );
                },
                'args'                => array(
                    'post_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
                    'note'    => array( 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
                ),
            ),
        )
    );
} );

function my_plugin_save_note( \WP_REST_Request $request ): \WP_REST_Response {
    $post_id = (int) $request->get_param( 'post_id' );
    $note    = (string) $request->get_param( 'note' );
    $user_id = get_current_user_id();

    My_Plugin::save_note( $post_id, $user_id, $note );

    return rest_ensure_response( array( 'saved' => true ) );
}
```

The endpoint is then callable from `window.jetonomyRest.restFetch`:

```js
const result = await window.jetonomyRest.restFetch(
    '/my-plugin/notes/' + postId,
    { method: 'POST', body: { note: 'Great post' } }
);
```

---

## Content moderation intercept: `jetonomy_check_content`

The `jetonomy_check_content` filter fires inside the post and reply controllers immediately before a new item is saved, giving you a chance to route it to a moderation state. It is the correct place to hook an AI spam detector, a profanity filter, or a word-list checker.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$action` | `string\|null` | The current action; `null` on entry (pass-through). Return a value to override. |
| `$data` | `array` | The content data. For posts: `['title' => string, 'content' => string, ...]`. For replies: `['content' => string, ...]`. |
| `$space_id` | `int` | The space the content is being posted to |
| `$user_id` | `int` | The author's WP user ID |

**Returns:** one of:

| Return value | Effect |
|-------------|--------|
| `null` | No action - content saves normally |
| `'hold'` | Status set to `pending`; moves to the moderation queue |
| `'spam'` | Status set to `spam` |
| `'flag'` | Content publishes but a Flag record is created; it surfaces in the moderation queue for review |
| `'block'` | Request returns a 400 error to the author; content is not saved |

**Source:** `includes/api/class-posts-controller.php:510`, `class-replies-controller.php:292`

### Example: hold posts from new users

```php
add_filter( 'jetonomy_check_content', function ( $action, array $data, int $space_id, int $user_id ) {
    if ( ! is_null( $action ) ) {
        return $action; // Respect a decision already made (e.g. by a higher-priority listener).
    }

    // Hold any post from a user registered less than 24 hours ago.
    $user = get_userdata( $user_id );
    if ( $user && ( time() - strtotime( $user->user_registered ) ) < DAY_IN_SECONDS ) {
        return 'hold';
    }

    return null;
}, 10, 4 );
```

### Example: spam-detect via an external API

```php
add_filter( 'jetonomy_check_content', function ( $action, array $data, int $space_id, int $user_id ) {
    if ( ! is_null( $action ) ) {
        return $action;
    }

    $content = ( $data['title'] ?? '' ) . ' ' . ( $data['content'] ?? '' );
    $verdict = My_Spam_Service::check( $content, $user_id );

    if ( 'spam' === $verdict ) {
        return 'spam';
    }
    if ( 'suspicious' === $verdict ) {
        return 'hold';
    }

    return null;
}, 15, 4 ); // Priority 15 so it runs after trust-level checks (priority 10).
```

### Example: hard-block prohibited phrases

```php
add_filter( 'jetonomy_check_content', function ( $action, array $data, int $space_id, int $user_id ) {
    if ( 'block' === $action ) {
        return $action; // Already blocked by another listener.
    }

    $text       = strtolower( ( $data['title'] ?? '' ) . ' ' . ( $data['content'] ?? '' ) );
    $prohibited = array( 'buy cheap', 'click here to win', 'guaranteed income' );

    foreach ( $prohibited as $phrase ) {
        if ( str_contains( $text, $phrase ) ) {
            return 'block';
        }
    }

    return $action;
}, 10, 4 );
```

> Note: the `jetonomy_check_content` filter described here differs from the `jetonomy_check_content` filter in [Hooks Reference §Filter Hooks](./02-hooks-reference.md#jetonomy_check_content) which returns `true|WP_Error`. That filter is the older surface; this filter returns a string action and is the one used by the controllers for moderation routing.

---

## What's next

- [Hooks Reference](./02-hooks-reference.md) - action and filter hooks fired around every lifecycle event
- [Extend the Frontend](./17-extend-the-frontend.md) - call your new endpoint from JavaScript
- [Adapters](./05-adapters.md) - replace the email, search, or real-time adapter with a custom implementation
