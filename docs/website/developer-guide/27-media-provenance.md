# Media Provenance

`Jetonomy\Media_Library` — `includes/class-media-library.php` — hides
community uploads from the admin Media Library by default (member uploads go
through the standard WP media library so third-party offload/compression
plugins keep working transparently) and tracks which uploads Jetonomy itself
created, so Pro's garbage collector never deletes another plugin's file.

## The provenance problem

`META_FLAG` (`_jetonomy_media`) marks "this looks like a community upload" —
including files a one-shot backfill merely *inferred* (any attachment whose
author lacks `upload_files`). That heuristic is true of every
subscriber-authored attachment on the site, **including another forum
plugin's** — wpForo, for example, inserts its own attachments authored by the
posting member. `META_FLAG` alone is fine for *hiding* a file from the admin
grid; it is not safe for *deleting* it.

## `META_ORIGIN`

```php
const META_ORIGIN = '_jetonomy_media_origin';
```

Set **only** when Jetonomy itself created the upload — recorded at write
time, never inferred afterward. This is the sole flag Pro's daily GC checks
before force-deleting an unlinked attachment. Without it, enabling
attachments mid-migration could destroy the very files an import exists to
rescue.

## `tag_upload()`

```php
Media_Library::tag_upload( int $attachment_id, int $space_id = 0 ): void
```

Call this from the code path that creates the upload (the REST media
controller, at upload time). Sets `META_FLAG` (hide from admin grid),
`META_ORIGIN = 'upload'` (GC-eligible), and `META_SPACE` when a space id is
known. Static and safe to call outside `is_admin()`.

## `is_ours()`

```php
Media_Library::is_ours( int $attachment_id ): bool
```

Returns `true` only when `META_ORIGIN === 'upload'`. This is the gate any
delete/GC routine must check before removing a file — a capability check or
`META_FLAG` presence is not sufficient. Pro's attachment GC cron
(`gc()` in `jetonomy-pro/includes/extensions/attachments/class-extension.php`)
calls this immediately before `wp_delete_attachment( $id, true )`.

## Usage example

```php
use Jetonomy\Media_Library;

// After a member uploads a file via your own code path:
Media_Library::tag_upload( $attachment_id, $space_id );

// Before any code deletes an unused/orphaned attachment:
if ( Media_Library::is_ours( $attachment_id ) ) {
    wp_delete_attachment( $attachment_id, true );
}
```

Never assume an attachment is safe to delete because it's unused and
"looks like" a community upload — check `is_ours()` first. Only files
Jetonomy itself uploaded are GC-eligible; anything merely recognized (another
plugin's media, an older member upload the backfill flagged) is someone
else's file, and being unused isn't a good enough reason to delete it.
