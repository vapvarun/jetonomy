# Importer Contract

`Jetonomy\Import\Importer` — `includes/import/class-importer.php` — is the
abstract base every migration source extends. Free ships three:
`BBPress_Importer`, `WPForo_Importer`, `Asgaros_Importer`
(`includes/import/class-{source}-importer.php`).

## Required methods (abstract)

Every subclass implements:

```php
get_source_name(): string
is_source_available(): bool
get_source_stats(): array
get_total_count(): int
run( array $options = [] ): array
run_batch( string $phase, int $offset, int $batch_size ): array
```

`run_batch()` is the batched-import driver: it processes rows for one
`$phase` (`'forums'|'topics'|'replies'|'profiles'|'recount'`) starting at
`$offset`, and returns `['phase', 'offset', 'done', 'processed']` so the
caller (AJAX handler or `wp jetonomy import`) can resume across requests.

## Shared helpers on the base class

### `register_body_media()`

```php
register_body_media( string $body, string $path_prefix ): int
```

Registers every file a post body references, out of the source forum's own
upload folder (e.g. `wpforo/`), into the WP media library — **without**
moving, copying, or rewriting the body. Returns the count of newly tracked
files. Use for inline `<img>`/`<a>` references left in imported HTML; use
`link_attachment()` below for the attachment box.

### `ensure_media_id()`

```php
ensure_media_id( int $known_media_id, string $file_url, string $file_name = '' ): int
```

Resolves a source attachment to a WP media ID, importing it if needed.
Reuses `$known_media_id` when the source already registered it. Otherwise
resolves `$file_url` to a real path inside `uploads/` via
`resolve_upload_path()`, validates the file type (refuses anything WP
wouldn't accept as an upload), and calls `wp_insert_attachment()`. Returns
`0` and logs an error when the file can't be recovered.

### `resolve_upload_path()`

```php
resolve_upload_path( string $file_url ): string
```

The single place any importer turns a source URL into a real path inside
`uploads/`. Matches on the URL **path** only (ignores scheme/host), because
real forum markup carries protocol-relative URLs (wpForo), stale `http://`,
or an old domain. Enforces containment with `realpath()` so a crafted
`../../../wp-config.php` path can never resolve — returns `''` for anything
outside `uploads/` or missing on disk.

### `link_attachment()`

```php
link_attachment( string $object_type, int $object_id, int $attachment_id, int $sort = 0 ): bool
```

Links a recovered media item to an imported post/reply via
[`Attachment::link()`](models.md#link). Always available since attachments
moved to free in 1.8.0 — no Pro dependency, no filter round-trip.

### `sort_rows_parents_first()`

```php
sort_rows_parents_first( array $rows, string $id_key, string $parent_key ): array
```

Reorders source forum rows so every parent is emitted before its children — a
breadth-first walk from the roots (`parent = 0`) outward. Forum hierarchy is
preserved by mapping each child's `parent_id` to its already-imported parent,
which only works if the parent was created first; feeding the raw result set
straight in dropped any child forum whose parent happened to sort later. Rows
whose parent never resolves (orphans or cycles) are appended at the end rather
than lost.

Both free importers sort forum rows parents-first before creating spaces so the
tree survives the migration, on the one-shot **and** the batched path: bbPress
calls this shared base helper (`class-bbpress-importer.php:183` and `:449`);
Asgaros runs an equivalent dependency sort in its own class
(`class-asgaros-importer.php`). Either way `parent_id` is carried onto the new
space.

### `get_errors()`

```php
get_errors(): array
```

Returns this batch's non-fatal errors as
`array<int, array{type: string, id: mixed, message: string}>` (e.g. an
attachment whose file couldn't be recovered). The AJAX handler reads this
after every batch and accumulates it into the `jetonomy_import_errors` option,
then the import report renders the count plus a sample of up to 50 rows
(`includes/admin/ajax/class-import-handler.php`,
`includes/admin/views/import.php`) — so a skipped file is reported to the
customer instead of vanishing behind a silent "Import complete!".

## Per-batch time budget

```php
start_budget(): void   // call at the top of run_batch()
budget_spent(): bool   // check inside the row loop; stop at a row boundary when true
```

A single 4000×3000 forum photo can cost ~0.9s to register (thumbnail
regeneration), so an image-heavy forum can blow past
`max_execution_time` inside one batch. `start_budget()` sets a deadline
(default 15s, filterable):

```php
/**
 * Seconds a single import batch may spend before it stops at a row boundary.
 *
 * @param float $seconds Default 15.
 */
apply_filters( 'jetonomy_import_batch_seconds', 15.0 );
```

Check `budget_spent()` between rows and return early (persisting progress)
once it trips — a batch that dies mid-request is worse than a slow one: the
id map only persists on a completed batch, so a killed batch replays and
duplicates already-imported content on resume.

## Adding a new source importer

1. Create `includes/import/class-{source}-importer.php`, extending
   `Jetonomy\Import\Importer`, implementing the abstract methods above.
2. Use `map_id()` / `get_mapped_id()` to track old-ID → new-ID across
   forums/topics/replies/profiles. If the source nests forums, run the forum
   rows through `sort_rows_parents_first()` before creating spaces so a parent
   is always mapped before its children reference it.
3. Call `start_budget()` at the top of `run_batch()`, and `budget_spent()`
   inside the per-row loop.
4. For inline body media, call `register_body_media( $body, 'your-plugin-folder' )`.
   For an explicit attachment box, resolve via `ensure_media_id()` then
   `link_attachment()`.
5. Register the importer in `Jetonomy\Import\Import_Manager::init()`, or from
   outside the plugin via the `jetonomy_importers` filter:

```php
add_filter( 'jetonomy_importers', function ( array $importers ) {
    $importers['my-forum'] = new My_Forum_Importer();
    return $importers;
} );
```
