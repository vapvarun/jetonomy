Jetonomy uses a universal adapter pattern for every external integration point. Instead of hard-coding a dependency on a specific search engine, email provider, membership plugin, or AI provider, each integration is represented by a PHP interface. You implement the interface, register your adapter, and Jetonomy uses it everywhere.

All adapters are managed through the static `Adapter_Registry` class (`includes/adapters/class-adapter-registry.php`).

---

## The Four Adapter Types

| Interface | Class (namespace `Jetonomy\Adapters\`) | What it controls |
|-----------|---------------------------------------|-----------------|
| `Search_Adapter` | `interface-search-adapter.php` | Keyword search for Abilities (MCP) |
| `Search_Query_Adapter` | `interface-search-query-adapter.php` | The full community search: REST, search page, app and Abilities |
| `Email_Adapter` | `interface-email-adapter.php` | Outbound notification emails |
| `Membership_Adapter` | `interface-membership-adapter.php` | Membership level checks and gating |
| `AI_Adapter` | `interface-ai-adapter.php` | AI chat completions and embeddings (text generation, moderation, semantic features) |

---

## Built-in Adapters (Free)

| Adapter Class | Type | Active When |
|---------------|------|-------------|
| `Fulltext_Search` (`Jetonomy\Search\`) | Search | Always (MySQL FULLTEXT - built-in) |
| `WP_Mail_Adapter` | Email | Always (uses `wp_mail()`) |
| `WP_Roles_Adapter` | Membership | Always (WP role-based membership fallback) |
| `MemberPress_Adapter` | Membership | MemberPress plugin is active |
| `PMPro_Adapter` | Membership | Paid Memberships Pro is active |
| `Ollama_AI_Adapter` | AI | Jetonomy Pro's AI extension has an Ollama provider configured (the class ships in free; Pro registers it) |

## Pro Adapters (Jetonomy Pro)

| Adapter Class | Type | Active When |
|---------------|------|-------------|
| `WooCommerce_Adapter` | Membership | WooCommerce Memberships is active |
| `RCP_Adapter` | Membership | Restrict Content Pro is active |
| `LearnDash_Adapter` | Membership | LearnDash is active (4.x and 5.x) |
| `Tutor_Adapter` | Membership | Tutor LMS is active |
| `LifterLMS_Adapter` | Membership | LifterLMS is active |
| `Sensei_Adapter` | Membership | Sensei LMS is active |
| `MasterStudy_Adapter` | Membership | MasterStudy LMS is active |
| `OpenAI_AI_Adapter` | AI | The AI extension is enabled with an OpenAI provider configured |
| `Anthropic_AI_Adapter` | AI | The AI extension is enabled with an Anthropic provider configured |
| `Custom_AI_Adapter` | AI | The AI extension is enabled with a custom OpenAI-compatible provider configured |

Pro registers membership adapters via `Adapter_Registry::register_membership()` and AI adapters via `Adapter_Registry::register_ai()` at `plugins_loaded` priority 20.

---

## Adapter_Registry API

```php
// Register adapters.
\Jetonomy\Adapters\Adapter_Registry::register_search( 'my-search', $adapter );
\Jetonomy\Adapters\Adapter_Registry::register_email( 'my-mailer', $adapter );
\Jetonomy\Adapters\Adapter_Registry::register_membership( 'my-membership', $adapter );
\Jetonomy\Adapters\Adapter_Registry::register_ai( 'my-ai', $adapter );
// Retrieve the active adapter (first registered adapter where is_active() returns true).
$search     = \Jetonomy\Adapters\Adapter_Registry::get_search();
$email      = \Jetonomy\Adapters\Adapter_Registry::get_email();
$membership = \Jetonomy\Adapters\Adapter_Registry::get_membership();
$ai         = \Jetonomy\Adapters\Adapter_Registry::get_ai();

// Retrieve a specific adapter by ID.
$mp     = \Jetonomy\Adapters\Adapter_Registry::get_membership( 'memberpress' );
$openai = \Jetonomy\Adapters\Adapter_Registry::get_ai( 'openai' );

// List all registered membership / AI adapters.
$all    = \Jetonomy\Adapters\Adapter_Registry::get_all_membership();
$all_ai = \Jetonomy\Adapters\Adapter_Registry::get_all_ai();
```

> **There is no realtime adapter.** Only membership, search, email, and AI adapters exist - four interfaces, four `register_*()` methods on `Adapter_Registry`, and no `register_realtime()`. Live updates (new replies, notification counts) are delivered by REST polling against the updates endpoint, not by a pluggable realtime backend. Older docs listed "real-time" among the adapter interfaces - that was never implemented. If you need push-based delivery, open an issue; do not implement the other adapter interfaces expecting a realtime seam to exist.


The Registry returns `null` when no active adapter is found for a type - always null-check before calling methods.

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

## Search Adapter Interfaces

Two interfaces, depending on how much of search your backend takes over:

| Interface | Serves |
|---|---|
| `Search_Query_Adapter` (extends `Search_Adapter`, since 2.0.1) | Everything: `GET /jetonomy/v1/search`, the community search page, the companion app and Abilities (MCP) search |
| `Search_Adapter` | Abilities (MCP) search only. The REST route, search page and app need tag, author, date and sort filters plus a total, which this interface cannot carry, so they stay on the built-in MySQL search instead of returning results that ignore the filters |

The built-in `Jetonomy\Search\Fulltext_Search` implements `Search_Query_Adapter` with MySQL `FULLTEXT`.

```php
namespace Jetonomy\Adapters;

interface Search_Adapter {
    /** Return true when this adapter is ready to handle queries. */
    public function is_active(): bool;

    /** Store or replace one published topic or reply in your index. */
    public function index( string $object_type, int $object_id, array $data ): void;

    /** Keyword search of one type ('post', 'reply' or 'space'). */
    public function search( string $query, string $type, ?int $space_id, int $limit, int $offset ): array;

    /** Drop one topic or reply from your index. */
    public function delete( string $object_type, int $object_id ): void;
}

interface Search_Query_Adapter extends Search_Adapter {
    /**
     * $args: type ('post'|'reply'|'space'|'tag'), q, space_id, date_from, date_to (Y-m-d),
     *        author_id, tag_slug, sort ('relevance'|'newest'|'votes'), limit, offset, with_total.
     * An empty q with a space, tag or author is a listing of that scope, newest first.
     *
     * @return array{items: object[], total: int}
     */
    public function query( array $args ): array;
}
```

**What `query()` must return.** Rows must already exclude anything the current viewer may not read: private topics they did not write, topics in private or hidden spaces they are not a member of, and authors they blocked. Callers page through the rows and show `total`. Post rows carry the `jt_posts` columns plus `space_title` and `space_slug`; reply rows the `jt_replies` columns; space and tag rows their table columns. `total` may be 0 when `with_total` is false.

**How your index is kept current.** While a plugin adapter is in use, Jetonomy calls `index( $type, $id, $row )` with the full row when a topic or reply is published (including when a held one is approved) or edited, and `delete( $type, $id )` when one is deleted or moderated out of `publish` (trashed, held, marked spam). Nothing is called while the built-in MySQL search is in use. Existing content is not sent retroactively: index it once from your own importer or a WP-CLI command when you switch backends.

**Which adapter is used.** If more than one is registered, name yours on the `jetonomy_search_adapter` filter. Otherwise Jetonomy uses any active adapter a plugin registered over the built-in `fulltext` one, whatever the registration order:

```php
add_filter( 'jetonomy_search_adapter', fn() => 'elasticsearch' );
```

### Example: Elasticsearch adapter

Extending `Fulltext_Search` keeps the MySQL answers for anything you choose not to handle (here: tags).

```php
<?php
namespace My_Plugin;

use Jetonomy\Search\Fulltext_Search;

class Elasticsearch_Adapter extends Fulltext_Search {

    public function __construct( private \Elasticsearch\Client $client ) {}

    public function is_active(): bool {
        return (bool) get_option( 'my_plugin_es_host' );
    }

    public function index( string $object_type, int $object_id, array $data ): void {
        $this->client->index( [ 'index' => 'jetonomy_' . $object_type, 'id' => $object_id, 'body' => $data ] );
    }

    public function delete( string $object_type, int $object_id ): void {
        $this->client->delete( [ 'index' => 'jetonomy_' . $object_type, 'id' => $object_id ] );
    }

    public function query( array $args ): array {
        if ( ! in_array( $args['type'] ?? 'post', [ 'post', 'reply' ], true ) ) {
            return parent::query( $args ); // Spaces and tags stay on MySQL.
        }
        // Build the Elasticsearch query from q, space_id, author_id, tag_slug,
        // dates and sort, including the viewer-visibility filters described above.
        $raw = $this->client->search( my_plugin_build_es_query( $args, get_current_user_id() ) );
        return [
            'items' => array_map( fn( $hit ) => (object) $hit['_source'], $raw['hits']['hits'] ?? [] ),
            'total' => (int) ( $raw['hits']['total']['value'] ?? 0 ),
        ];
    }
}
```

**Register it** (any priority after Jetonomy loads; order no longer matters):

```php
add_action( 'plugins_loaded', function () {
    if ( class_exists( '\Jetonomy\Adapters\Adapter_Registry' ) ) {
        \Jetonomy\Adapters\Adapter_Registry::register_search( 'elasticsearch', new My_Plugin\Elasticsearch_Adapter( my_plugin_es_client() ) );
    }
}, 15 );
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
        // Optional - intercept wp_mail if you want to route ALL site email through Postmark.
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

The `register_hooks()` method is where you fire `jetonomy_membership_activated` and `jetonomy_membership_deactivated` - see [02-hooks-reference.md](./02-hooks-reference.md).

### Grouping and describing your levels (1.9.1)

`get_all_levels()` may return two optional keys per level. Both are ignored if you omit them, so existing adapters keep working unchanged:

| Key | Type | Effect |
|---|---|---|
| `kind` | string | Groups the level under a heading in the Access Rules picker. Levels sharing a `kind` are listed together, which stops a long flat list of unrelated products, tiers and courses. |
| `note` | string | A short line shown under the level name, for when the name alone does not identify it. |

```php
public function get_all_levels(): array {
    return array(
        array( 'id' => 'gold', 'name' => 'Gold', 'kind' => 'Subscriptions', 'note' => 'Renews monthly' ),
        array( 'id' => 'wb-101', 'name' => 'Workshop 101', 'kind' => 'Courses' ),
    );
}
```

> **A membership rule tops out at Member** whatever access level the owner picks, so your adapter cannot be used to appoint moderators. See [Access level](../integrations/01-memberpress.md#access-level).

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

## AI Adapter Interface

```php
namespace Jetonomy\Adapters;

interface AI_Adapter {
    /** Whether this adapter is configured and ready. */
    public function is_active(): bool;

    /** Unique provider identifier (e.g. 'openai', 'anthropic', 'ollama'). */
    public function get_id(): string;

    /** Human-readable provider name. */
    public function get_name(): string;

    /**
     * Send a chat completion request.
     *
     * @param array $messages Array of ['role' => 'system'|'user'|'assistant', 'content' => string].
     * @param array $options  Optional: model, temperature, max_tokens, json_mode.
     * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}, model: string}
     * @throws \RuntimeException On API failure.
     */
    public function chat( array $messages, array $options = [] ): array;

    /**
     * Generate embeddings for text.
     *
     * @param string $text    Input text.
     * @param array  $options Optional: model.
     * @return array{embedding: float[], model: string, usage: array{total_tokens: int}}
     * @throws \RuntimeException On API failure or if provider does not support embeddings.
     */
    public function embed( string $text, array $options = [] ): array;

    /** Return supported models as id => display_name. */
    public function get_models(): array;

    /** Test the connection (validates API key + reachability). Returns ['ok' => bool, 'error'? => string, 'model'? => string]. */
    public function test(): array;
}
```

The `Ollama_AI_Adapter` class ships in the free plugin, but free registers no AI provider. Jetonomy Pro's AI extension registers it when an Ollama provider is configured, along with `OpenAI_AI_Adapter`, `Anthropic_AI_Adapter`, and `Custom_AI_Adapter` (any OpenAI-compatible endpoint). `Adapter_Registry::get_ai()` returns the first registered adapter whose `is_active()` returns `true`; pass an explicit ID to target a specific provider.

### Example: Custom AI Adapter

```php
class My_AI_Adapter implements \Jetonomy\Adapters\AI_Adapter {

    public function is_active(): bool {
        return ! empty( get_option( 'my_plugin_ai_key' ) );
    }

    public function get_id(): string {
        return 'my-ai';
    }

    public function get_name(): string {
        return 'My AI Provider';
    }

    public function chat( array $messages, array $options = [] ): array {
        $response = wp_remote_post( 'https://api.example.com/v1/chat', [
            'headers' => [
                'Authorization' => 'Bearer ' . get_option( 'my_plugin_ai_key' ),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'    => $options['model'] ?? 'default',
                'messages' => $messages,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return [
            'content' => $body['choices'][0]['message']['content'] ?? '',
            'usage'   => $body['usage'] ?? [ 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 ],
            'model'   => $body['model'] ?? ( $options['model'] ?? 'default' ),
        ];
    }

    public function embed( string $text, array $options = [] ): array {
        throw new \RuntimeException( 'Embeddings not supported.' );
    }

    public function get_models(): array {
        return [ 'default' => 'Default Model' ];
    }

    public function test(): array {
        return $this->is_active()
            ? [ 'ok' => true ]
            : [ 'ok' => false, 'error' => 'API key not configured.' ];
    }
}
```

---

## Connecting Adapters to Jetonomy Events

Adapters do not self-wire - you need to connect them to Jetonomy's lifecycle hooks to trigger indexing, emailing, or broadcasting at the right time.

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

### AI: Summarize a new post on demand

```php
add_action( 'jetonomy_after_create_post', function( int $post_id, int $space_id ) {
    $ai = \Jetonomy\Adapters\Adapter_Registry::get_ai();
    if ( ! $ai ) return;

    $post = \Jetonomy\Models\Post::find( $post_id );
    if ( $post ) {
        $result = $ai->chat( [
            [ 'role' => 'system', 'content' => 'Summarize the following topic in one sentence.' ],
            [ 'role' => 'user',   'content' => wp_strip_all_tags( $post->content ) ],
        ] );
        // Persist $result['content'] wherever you need it.
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

    // Search - replace built-in MySQL FULLTEXT.
    \Jetonomy\Adapters\Adapter_Registry::register_search(
        'meilisearch',
        new My_Plugin\Meilisearch_Adapter()
    );

    // Email - replace wp_mail for notification emails.
    \Jetonomy\Adapters\Adapter_Registry::register_email(
        'postmark',
        new My_Plugin\Postmark_Adapter()
    );

    // Membership - add a custom membership source.
    $adapter = new My_Plugin\My_Membership_Adapter();
    $adapter->register_hooks();
    \Jetonomy\Adapters\Adapter_Registry::register_membership( 'my-membership', $adapter );

    // AI - add a custom AI provider for chat completions / embeddings.
    \Jetonomy\Adapters\Adapter_Registry::register_ai(
        'my-ai',
        new My_Plugin\My_AI_Adapter()
    );
}, 15 );
```

---

## What's Next?

- [REST API Reference](./01-rest-api.md) - All 81 free routes (141 with Pro) in detail
- [Hooks Reference](./02-hooks-reference.md) - Connect your adapter to content lifecycle events
- [Template Overrides](./03-template-overrides.md) - Customize the community UI
