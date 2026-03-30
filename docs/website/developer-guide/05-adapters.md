Jetonomy uses a universal adapter pattern for every external integration point. Instead of hard-coding a dependency on a specific search engine, email provider, membership plugin, or real-time service, each integration is represented by a PHP interface. You implement the interface, register your adapter, and Jetonomy uses it everywhere.

All adapters are managed through the static `Adapter_Registry` class (`includes/adapters/class-adapter-registry.php`).

---

## The Four Adapter Types

| Interface | Class (namespace `Jetonomy\Adapters\`) | What it controls |
|-----------|---------------------------------------|-----------------|
| `Search_Adapter` | `interface-search-adapter.php` | Full-text search for posts, replies, spaces |
| `Email_Adapter` | `interface-email-adapter.php` | Outbound notification emails |
| `Membership_Adapter` | `interface-membership-adapter.php` | Membership level checks and gating |
| `Realtime_Adapter` | `interface-realtime-adapter.php` | Live event broadcasting to connected clients |

---

## Built-in Adapters (Free)

| Adapter Class | Type | Active When |
|---------------|------|-------------|
| `Fulltext_Search` (`Jetonomy\Search\`) | Search | Always (MySQL FULLTEXT — built-in) |
| `WP_Mail_Adapter` | Email | Always (uses `wp_mail()`) |
| `WP_Roles_Adapter` | Membership | Always (WP role-based membership fallback) |
| `Polling_Adapter` | Realtime | Always (long-polling fallback via `/updates` endpoint) |
| `MemberPress_Adapter` | Membership | MemberPress plugin is active |
| `PMPro_Adapter` | Membership | Paid Memberships Pro is active |

## Pro Adapters (Jetonomy Pro)

| Adapter Class | Type | Active When |
|---------------|------|-------------|
| `WooCommerce_Adapter` | Membership | WooCommerce Memberships is active |
| `RCP_Adapter` | Membership | Restrict Content Pro is active |
| `LearnDash_Adapter` | Membership | LearnDash is active |
| `Tutor_Adapter` | Membership | Tutor LMS is active |

Pro registers these via `Adapter_Registry::register_membership()` at `plugins_loaded` priority 20.

---

## Adapter_Registry API

```php
// Register adapters.
\Jetonomy\Adapters\Adapter_Registry::register_search( 'my-search', $adapter );
\Jetonomy\Adapters\Adapter_Registry::register_email( 'my-mailer', $adapter );
\Jetonomy\Adapters\Adapter_Registry::register_membership( 'my-membership', $adapter );
\Jetonomy\Adapters\Adapter_Registry::register_realtime( 'my-pusher', $adapter );

// Retrieve the active adapter (first registered adapter where is_active() returns true).
$search     = \Jetonomy\Adapters\Adapter_Registry::get_search();
$email      = \Jetonomy\Adapters\Adapter_Registry::get_email();
$membership = \Jetonomy\Adapters\Adapter_Registry::get_membership();
$realtime   = \Jetonomy\Adapters\Adapter_Registry::get_realtime();

// Retrieve a specific adapter by ID.
$mp = \Jetonomy\Adapters\Adapter_Registry::get_membership( 'memberpress' );

// List all registered membership adapters.
$all = \Jetonomy\Adapters\Adapter_Registry::get_all_membership();
```

The Registry returns `null` when no active adapter is found for a type — always null-check before calling methods.

**Registration timing:** Register your adapters at `plugins_loaded`. Use priority 9 if you want your adapter to override a built-in default (e.g. replacing built-in search). Use priority 15 for additive adapters that do not need to override defaults (e.g. adding a new membership source):

```php
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( '\Jetonomy\Adapters\Adapter_Registry' ) ) {
        return; // Jetonomy not active.
    }
    \Jetonomy\Adapters\Adapter_Registry::register_search(
        'meilisearch',
        new My_Plugin\Meilisearch_Adapter()
    );
}, 15 );
```

---

## Search Adapter Interface

```php
namespace Jetonomy\Adapters;

interface Search_Adapter {
    /** Return true when this adapter is ready to handle queries. */
    public function is_active(): bool;

    /** Index a document. Called when a post or reply is created or updated. */
    public function index( string $object_type, int $object_id, array $data ): void;

    /**
     * Execute a search query.
     *
     * @param string   $query      The search string.
     * @param string   $type       'post', 'reply', or 'space'.
     * @param int|null $space_id   Optional space filter.
     * @param int      $limit      Max results (default 20).
     * @param int      $offset     Pagination offset.
     * @return array               Array of result objects with at least: id, title (posts/spaces) or content (replies).
     */
    public function search( string $query, string $type, ?int $space_id, int $limit, int $offset ): array;

    /** Remove a document from the index. Called when a post or reply is deleted. */
    public function delete( string $object_type, int $object_id ): void;
}
```

### Example: Custom Elasticsearch Adapter

```php
<?php
namespace My_Plugin;

use Jetonomy\Adapters\Search_Adapter;

class Elasticsearch_Adapter implements Search_Adapter {

    private \Elasticsearch\Client $client;

    public function __construct() {
        // Build your Elasticsearch client here.
        $this->client = \Elasticsearch\ClientBuilder::create()
            ->setHosts( [ get_option( 'my_plugin_es_host', 'localhost:9200' ) ] )
            ->build();
    }

    public function is_active(): bool {
        // Only activate when the Elasticsearch host option is set and the client connects.
        $host = get_option( 'my_plugin_es_host' );
        return ! empty( $host ) && $this->client->ping();
    }

    public function index( string $object_type, int $object_id, array $data ): void {
        $this->client->index( [
            'index' => 'jetonomy_' . $object_type,
            'id'    => $object_id,
            'body'  => $data,
        ] );
    }

    public function search( string $query, string $type, ?int $space_id, int $limit, int $offset ): array {
        $must = [
            [ 'multi_match' => [ 'query' => $query, 'fields' => [ 'title^3', 'content' ] ] ],
        ];

        if ( $space_id ) {
            $must[] = [ 'term' => [ 'space_id' => $space_id ] ];
        }

        $params = [
            'index' => 'jetonomy_' . $type,
            'body'  => [
                'query' => [ 'bool' => [ 'must' => $must ] ],
                'from'  => $offset,
                'size'  => $limit,
            ],
        ];

        $raw = $this->client->search( $params );

        // Map Elasticsearch hits to the flat object array Jetonomy expects.
        return array_map(
            fn( $hit ) => (object) array_merge( $hit['_source'], [ 'id' => (int) $hit['_id'] ] ),
            $raw['hits']['hits'] ?? []
        );
    }

    public function delete( string $object_type, int $object_id ): void {
        $this->client->delete( [
            'index' => 'jetonomy_' . $object_type,
            'id'    => $object_id,
        ] );
    }
}
```

**Register it:**

```php
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( '\Jetonomy\Adapters\Adapter_Registry' ) ) {
        return;
    }
    \Jetonomy\Adapters\Adapter_Registry::register_search(
        'elasticsearch',
        new My_Plugin\Elasticsearch_Adapter()
    );
}, 15 );
```

Jetonomy will call `is_active()` on every registered search adapter and use the first one that returns `true`. Because the built-in `Fulltext_Search` adapter always returns `true`, register your custom adapter before the defaults are initialized — or override it by making sure your adapter is registered first.

The built-in defaults are initialized at `plugins_loaded` priority 10 via `Adapter_Registry::init_defaults()`. Registering at priority 15 means your adapter is added after the defaults, but since `get_search()` iterates in insertion order, you need to register at priority **9** if you want your adapter to take precedence:

```php
add_action( 'plugins_loaded', function() {
    // Priority 9 — runs before Jetonomy's init_defaults() at priority 10.
    \Jetonomy\Adapters\Adapter_Registry::register_search( 'elasticsearch', new My_Plugin\Elasticsearch_Adapter() );
}, 9 );
```

---

## Email Adapter Interface

```php
namespace Jetonomy\Adapters;

interface Email_Adapter {
    public function is_active(): bool;

    /**
     * Send a single transactional email.
     *
     * @param string   $to            Recipient email address.
     * @param string   $subject       Email subject line.
     * @param string   $html          HTML body.
     * @param string   $plain         Plain-text fallback.
     * @param string[] $extra_headers Additional mail headers.
     * @return bool True on success.
     */
    public function send( string $to, string $subject, string $html, string $plain, array $extra_headers = [] ): bool;

    /**
     * Send a batch of emails.
     *
     * @param array $messages Array of ['to', 'subject', 'html', 'plain'] arrays.
     * @return array          Results array indexed by recipient.
     */
    public function send_batch( array $messages ): array;

    /** Register any hooks needed (e.g. intercepting wp_mail for logging). */
    public function register_hooks(): void;
}
```

### Example: Postmark Adapter

```php
class Postmark_Adapter implements \Jetonomy\Adapters\Email_Adapter {

    public function is_active(): bool {
        return ! empty( get_option( 'my_plugin_postmark_token' ) );
    }

    public function send( string $to, string $subject, string $html, string $plain, array $extra_headers = [] ): bool {
        $token = get_option( 'my_plugin_postmark_token' );
        $from  = get_option( 'admin_email' );

        $response = wp_remote_post( 'https://api.postmarkapp.com/email', [
            'headers' => [
                'Accept'                  => 'application/json',
                'Content-Type'            => 'application/json',
                'X-Postmark-Server-Token' => $token,
            ],
            'body' => wp_json_encode( [
                'From'     => $from,
                'To'       => $to,
                'Subject'  => $subject,
                'HtmlBody' => $html,
                'TextBody' => $plain,
            ] ),
        ] );

        return ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
    }

    public function send_batch( array $messages ): array {
        $results = [];
        foreach ( $messages as $msg ) {
            $results[ $msg['to'] ] = $this->send( $msg['to'], $msg['subject'], $msg['html'], $msg['plain'] );
        }
        return $results;
    }

    public function register_hooks(): void {
        // Optional — intercept wp_mail if you want to route ALL site email through Postmark.
    }
}
```

---

## Membership Adapter Interface

```php
namespace Jetonomy\Adapters;

interface Membership_Adapter {
    public function is_active(): bool;

    /** Return all active membership level IDs for a user. */
    public function get_user_levels( int $user_id ): array;

    /** Check whether a user has a specific membership level. */
    public function user_has_level( int $user_id, string $level_id ): bool;

    /** Return all available membership levels as ['id' => ..., 'name' => ...] objects. */
    public function get_all_levels(): array;

    /** Return the human-readable label for a level ID. */
    public function get_level_label( string $level_id ): string;

    /** Register any hooks needed for lifecycle events (e.g. activation/deactivation). */
    public function register_hooks(): void;
}
```

The `register_hooks()` method is where you fire `jetonomy_membership_activated` and `jetonomy_membership_deactivated` — see [02-hooks-reference.md](./02-hooks-reference.md).

### Example: Custom Membership Adapter

```php
class My_Membership_Adapter implements \Jetonomy\Adapters\Membership_Adapter {

    public function is_active(): bool {
        return defined( 'MY_MEMBERSHIP_VERSION' );
    }

    public function get_user_levels( int $user_id ): array {
        return (array) get_user_meta( $user_id, 'my_membership_levels', true );
    }

    public function user_has_level( int $user_id, string $level_id ): bool {
        return in_array( $level_id, $this->get_user_levels( $user_id ), true );
    }

    public function get_all_levels(): array {
        return my_membership_get_all_plans(); // Your own function.
    }

    public function get_level_label( string $level_id ): string {
        return my_membership_get_plan_name( $level_id ) ?? $level_id;
    }

    public function register_hooks(): void {
        // Fire Jetonomy's membership hooks so space access is updated automatically.
        add_action( 'my_membership_activated', function( int $user_id, string $plan_id ) {
            do_action( 'jetonomy_membership_activated', $user_id, $plan_id );
        }, 10, 2 );

        add_action( 'my_membership_cancelled', function( int $user_id, string $plan_id ) {
            do_action( 'jetonomy_membership_deactivated', $user_id, $plan_id );
        }, 10, 2 );
    }
}
```

---

## Realtime Adapter Interface

```php
namespace Jetonomy\Adapters;

interface Realtime_Adapter {
    public function is_active(): bool;

    /**
     * Broadcast an event to all clients subscribed to a channel.
     *
     * @param string $channel Channel name (e.g. 'post.42', 'space.7').
     * @param string $event   Event type (e.g. 'new-reply', 'post-updated').
     * @param array  $data    Event payload.
     */
    public function publish( string $channel, string $event, array $data ): void;

    /**
     * Return configuration passed to the frontend JavaScript client.
     * Keys depend on your provider (e.g. 'key', 'cluster' for Pusher).
     *
     * @return array
     */
    public function get_client_config(): array;
}
```

The built-in `Polling_Adapter` uses the `/updates` REST endpoint as a fallback. If you register a WebSocket-based adapter (Pusher, Ably, Soketi), the frontend Interactivity API store picks up the client config from `get_client_config()` and switches to push-based updates automatically.

### Example: Pusher Adapter

```php
class Pusher_Adapter implements \Jetonomy\Adapters\Realtime_Adapter {

    private \Pusher\Pusher $pusher;

    public function __construct() {
        $this->pusher = new \Pusher\Pusher(
            get_option( 'my_plugin_pusher_key' ),
            get_option( 'my_plugin_pusher_secret' ),
            get_option( 'my_plugin_pusher_app_id' ),
            [ 'cluster' => get_option( 'my_plugin_pusher_cluster', 'mt1' ), 'useTLS' => true ]
        );
    }

    public function is_active(): bool {
        return class_exists( '\Pusher\Pusher' )
            && ! empty( get_option( 'my_plugin_pusher_key' ) );
    }

    public function publish( string $channel, string $event, array $data ): void {
        $this->pusher->trigger( $channel, $event, $data );
    }

    public function get_client_config(): array {
        return [
            'key'     => get_option( 'my_plugin_pusher_key' ),
            'cluster' => get_option( 'my_plugin_pusher_cluster', 'mt1' ),
        ];
    }
}
```

---

## Connecting Adapters to Jetonomy Events

Adapters do not self-wire — you need to connect them to Jetonomy's lifecycle hooks to trigger indexing, emailing, or broadcasting at the right time.

### Search: Index content on create/update

```php
add_action( 'jetonomy_after_create_post', function( int $post_id, int $space_id ) {
    $search = \Jetonomy\Adapters\Adapter_Registry::get_search();
    if ( ! $search ) return;

    $post = \Jetonomy\Models\Post::find( $post_id );
    if ( $post ) {
        $search->index( 'post', $post_id, [
            'title'      => $post->title,
            'content'    => wp_strip_all_tags( $post->content ),
            'space_id'   => $post->space_id,
            'author_id'  => $post->author_id,
            'created_at' => $post->created_at,
        ] );
    }
}, 10, 2 );

add_action( 'jetonomy_post_deleted', function( int $post_id ) {
    $search = \Jetonomy\Adapters\Adapter_Registry::get_search();
    $search?->delete( 'post', $post_id );
} );
```

### Realtime: Broadcast new replies

```php
add_action( 'jetonomy_after_create_reply', function( int $reply_id, int $post_id ) {
    $rt = \Jetonomy\Adapters\Adapter_Registry::get_realtime();
    if ( ! $rt ) return;

    $reply = \Jetonomy\Models\Reply::find( $reply_id );
    if ( $reply ) {
        $rt->publish( 'post.' . $post_id, 'new-reply', [
            'reply_id'   => $reply_id,
            'author_id'  => $reply->author_id,
            'created_at' => $reply->created_at,
        ] );
    }
}, 10, 2 );
```

---

## Summary: Registration Cheat Sheet

```php
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( '\Jetonomy\Adapters\Adapter_Registry' ) ) {
        return;
    }

    // Search — replace built-in MySQL FULLTEXT.
    \Jetonomy\Adapters\Adapter_Registry::register_search(
        'meilisearch',
        new My_Plugin\Meilisearch_Adapter()
    );

    // Email — replace wp_mail for notification emails.
    \Jetonomy\Adapters\Adapter_Registry::register_email(
        'postmark',
        new My_Plugin\Postmark_Adapter()
    );

    // Membership — add a custom membership source.
    $adapter = new My_Plugin\My_Membership_Adapter();
    $adapter->register_hooks();
    \Jetonomy\Adapters\Adapter_Registry::register_membership( 'my-membership', $adapter );

    // Realtime — replace long-polling with WebSockets.
    \Jetonomy\Adapters\Adapter_Registry::register_realtime(
        'pusher',
        new My_Plugin\Pusher_Adapter()
    );
}, 9 ); // Priority 9 ensures search adapter runs before built-in defaults at priority 10.
```

---

## What's Next?

- [REST API Reference](./01-rest-api.md) — All 61+ endpoints in detail
- [Hooks Reference](./02-hooks-reference.md) — Connect your adapter to content lifecycle events
- [Template Overrides](./03-template-overrides.md) — Customize the community UI
