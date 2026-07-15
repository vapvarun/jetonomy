# Hooks & Filters Reference

Custom hooks Jetonomy fires so themes and extensions (including Jetonomy Pro) can
extend behavior without editing plugin files. This page currently covers the
attachment seam added in 1.8.0. See `includes/class-attachments.php` and
`includes/models/class-attachment.php` for the source of truth.

## Attachment filters

These three filters are the entire seam between free's basic attachment
renderer and Pro's richer one. Pro hooks all three in
`jetonomy-pro/includes/extensions/attachments/class-extension.php`; a site
without Pro still shows images inline and other files as download links.

### `jetonomy_attachment_card`

Filters one attachment's card HTML before it's added to the list.

```php
apply_filters( 'jetonomy_attachment_card', string $card, array $attachment, string $object_type, int $object_id ): string
```

- `$card` — free's default `<li>` markup (image card or file card).
- `$attachment` — hydrated attachment array, see [`Attachment::hydrate()`](models.md#hydrate).
- `$object_type` — `'post'` or `'reply'`.
- `$object_id` — the post or reply id.

Fired once per attachment inside `Attachments::render()`. Pro returns its own
card (lightbox image, inline PDF.js viewer, typed chip) instead of free's.

```php
add_filter( 'jetonomy_attachment_card', function ( $card, $attachment, $object_type, $object_id ) {
    if ( 'pdf' === $attachment['type'] ) {
        return my_render_pdf_card( $attachment );
    }
    return $card;
}, 10, 4 );
```

### `jetonomy_attachments_class`

Filters the wrapper `<ul>` class name, default `jt-attachments`.

```php
apply_filters( 'jetonomy_attachments_class', string $class ): string
```

Let a richer renderer swap in its own container class so its own CSS targets
the strip, without colliding with free's default styling.

### `jetonomy_rest_attachment_data`

Filters one attachment's REST/render data array.

```php
apply_filters( 'jetonomy_rest_attachment_data', array $data, string $object_type, int $object_id ): array
```

Fired once per row inside `Attachment::payload_for()` — the same call that
backs both the frontend card renderer and the REST payload, so the two can
never drift. `$data` is the shape documented in
[`Attachment::hydrate()`](models.md#hydrate). Pro uses this to swap in a
gated download URL for non-image files and to add PDF page counts.

## REST payload — `attachments`

As of 1.8.0, `jetonomy_rest_prepare_post` and `jetonomy_rest_prepare_reply`
(existing REST payload filters) carry an `attachments` array, injected by
`Attachments::inject_post_payload()` / `inject_reply_payload()` in
`includes/class-attachments.php`. This is free — a site without Pro still
serves attachment data to the mobile app and any REST consumer.

Each item in `attachments` has this shape:

| Key | Type | Description |
|---|---|---|
| `id` | int | WordPress media (attachment) ID. |
| `link_id` | int | The `jt_attachments` row id (use this to unlink). |
| `url` | string | Full-size file URL. |
| `thumb` | string | Thumbnail/medium image URL, or `''` for non-images. |
| `mime` | string | MIME type. |
| `name` | string | Real filename with extension. |
| `size` | int | File size in bytes, `0` if unknown. |
| `type` | string | `'image'`, `'pdf'`, or `'file'`. |
| `ext` | string | Uppercased file extension (e.g. `PDF`). |
| `is_image` | bool | Convenience flag, `true` when `type === 'image'`. |

Note the naming reads backwards on purpose: `id` is the WP media ID, `link_id`
is the `jt_attachments` row id. That's the shape the mobile app already
consumed from Pro before the table moved to free, and is not worth breaking
clients over.
