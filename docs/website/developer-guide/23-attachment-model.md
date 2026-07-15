# Models Reference

Data-access models live in `includes/models/` and are the only supported way
to read or write Jetonomy's custom tables — no raw `$wpdb` outside
`includes/db/`. This page documents `Jetonomy\Models\Attachment`.

## Attachment

`Jetonomy\Models\Attachment` — `includes/models/class-attachment.php`

Links WordPress media items to posts/replies, with ordering and a batch
primer so rendering N reply cards issues zero per-row queries. Table:
`jt_attachments` (free-owned since 1.8.0; it's the renamed `jt_pro_attachments`
— same schema, no rows copied). Free carries the link so a site that drops
Pro still shows its attachments; Pro adds the upload composer, size/type
limits, and richer previews on top of the same table.

### `link()`

```php
Attachment::link( string $object_type, int $object_id, int $attachment_id, int $sort = 0 ): int
```

Attaches a WP media item to a post or reply. Returns the `jt_attachments` row
id. Idempotent — linking the same file to the same object twice is a no-op
(backed by a UNIQUE KEY on `object_type, object_id, attachment_id`), so a
resumed or re-run import can't double-attach a file.

### `get_for()`

```php
Attachment::get_for( string $object_type, int $object_id ): array
```

All link rows for one object, ordered by `sort` then `id`. Request-cached per
`[type][object_id]`.

### `get_for_many()`

```php
Attachment::get_for_many( string $object_type, int[] $object_ids ): array
```

Batch-loads attachments for many objects of one type in **one** query and
seeds the per-object cache, keyed by object id (empty array for objects with
none). Use when rendering a page of N posts/replies.

### `prime_for_post()`

```php
Attachment::prime_for_post( int $post_id ): void
```

The N+1 guard for a single-post view: loads attachments for **every reply on
a post** in one query (`JOIN jt_replies`), so rendering N reply cards costs
zero further attachment queries. Called by `Attachments::render_post()`
before the reply loop. Seeds every reply on the post, including ones with no
attachments, so a later `get_for()` call never re-queries.

### `count_for()`

```php
Attachment::count_for( string $object_type, int $object_id ): int
```

`SELECT COUNT(*)` for one object. Use for a badge/count display — never
`count( Attachment::get_for(...) )`.

### `unlink()`

```php
Attachment::unlink( int $link_id ): bool
```

Removes one link row by its own id (not the media id).

### `unlink_all()`

```php
Attachment::unlink_all( string $object_type, int $object_id ): int
```

Removes every link row for an object (post/reply cascade delete). Returns
the number of rows deleted.

### `hydrate()`

```php
Attachment::hydrate( object $row ): ?array
```

Turns a `jt_attachments` row into the canonical attachment shape consumed by
both the frontend card renderer and the REST payload — one shape, so the web
and the app can never drift. Returns `null` when the underlying media item
was deleted (render nothing rather than a broken card). See the shape table
in [Hooks Reference — REST payload](hooks.md#rest-payload--attachments).

### `payload_for()`

```php
Attachment::payload_for( string $object_type, int $object_id ): array
```

Hydrates every attachment on an object and runs each through the
[`jetonomy_rest_attachment_data`](hooks.md#jetonomy_rest_attachment_data)
filter. Backs both `Attachments::render()` and the REST `attachments` field.

## Usage example

```php
use Jetonomy\Models\Attachment;

// Attach an already-uploaded media item to a reply.
$link_id = Attachment::link( 'reply', $reply_id, $media_id, $sort = 0 );

// Render a page of replies without N+1 attachment queries.
Attachment::prime_for_post( $post_id );
foreach ( $replies as $reply ) {
    $attachments = Attachment::get_for( 'reply', $reply->id ); // no extra query
}
```
