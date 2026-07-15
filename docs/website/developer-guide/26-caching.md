# Caching Reference

`Jetonomy\Cache` — `includes/class-cache.php` — is the single object-cache
wrapper every model routes through (group `jetonomy`, default TTL 300s). Do
not add per-service `wp_cache_*` calls or a second cache group; extend this
wrapper instead.

## API

### `get()` / `set()` / `delete()`

```php
Cache::get( string $key )
Cache::set( string $key, $value, int $ttl = 0 ): void   // ttl 0 = DEFAULT_TTL (300s)
Cache::delete( string $key ): void
```

Thin wrappers over `wp_cache_get/set/delete()` scoped to the `jetonomy` group.

### `delete_many()`

```php
Cache::delete_many( string[] $keys ): void
```

Busts several keys in one call — for writers that must invalidate more than
one key whose value changed (e.g. a space row is served under both
`space:{id}` and `space:slug:{slug}`, so a single write invalidates both).

### `remember()` / `remember_object()`

```php
Cache::remember( string $key, callable $callback, int $ttl = 0 )
Cache::remember_object( string $key, callable $callback, int $ttl = 0 ): ?object
```

Standard cache-aside: return the cached value, or call `$callback()`, cache
it, and return it. Use `remember_object()` for any read with an `?object`
return contract — some persistent object-cache backends (Redis/Memcached)
materialise a cached `null` as `''` on the next read, which `remember()`
alone would hand back as a string and fatal a typed caller. `remember_object()`
coerces any non-object hit back to `null`.

### `flush()`

```php
Cache::flush(): void
```

Flushes the whole `jetonomy` group. **Not a per-write path** — this is for
one-shot admin/CLI/import recomputes whose set-based writes can't name the
rows they touched, and the admin "Clear cache" action. Guarded by
`wp_cache_supports( 'flush_group' )`; falls back to a full `wp_cache_flush()`
on a persistent drop-in without group support, and is a no-op with no
persistent cache (nothing to flush).

### `is_persistent()`

```php
Cache::is_persistent(): bool
```

Wraps `wp_using_ext_object_cache()`.

## Invalidation rule: bust after the write, in the write method

**Every write method busts the exact keys whose value it changed, immediately
after the database write completes — never before.** Busting first is a
re-prime race: a concurrent read between the bust and the write re-caches the
stale value, and it stays stale for the full TTL.

A set-based `UPDATE ... WHERE id IN (...)` cannot invalidate what it doesn't
name — bust each affected id explicitly, or call `Cache::flush()` for a
one-shot admin/CLI/import path that can't enumerate the rows cheaply.

Member-visible state a user just changed (their own post count, a space they
just made private) must never rely on TTL expiry alone — bust the exact key.

## Reference pattern: `Space::bust_cache()`

`includes/models/class-space.php`:

```php
/**
 * Bust every object-cache key that serves a space row: the id key and,
 * when given, the slug->id mapping key. Row data lives once, under
 * space:{id}; find_by_slug() caches only the stable slug->id mapping under
 * space:slug:{slug}. Callers bust AFTER the DB write.
 */
public static function bust_cache( int $id, ?string $slug = null ): void {
    $keys = [ "space:{$id}" ];
    if ( ! empty( $slug ) ) {
        $keys[] = "space:slug:{$slug}";
    }
    Cache::delete_many( $keys );
}
```

Callers invoke it after every write that changes a space row: `update()`
(old + new slug on rename), the hard `delete()` override, and both counter
increments (id-only — the slug mapping doesn't change on a count bump). This
is the model to follow for any new cached row: one `bust_cache( $id, ...)`
helper on the model, called from every writer, after the write, never a
listener on a `do_action` (a listener misses any caller that doesn't fire the
action — REST, AJAX, CLI, import, and Abilities callers all mutate a space,
and only busting inside the model methods catches all of them).

```php
public static function update( int $id, array $data ): bool {
    $old_slug = ( parent::find( $id )->slug ?? null );
    $result   = parent::update( $id, $data );
    self::bust_cache( $id, $old_slug );
    if ( ! empty( $data['slug'] ) && $data['slug'] !== $old_slug ) {
        Cache::delete( "space:slug:{$data['slug']}" ); // rename invalidates the new key too
    }
    return $result;
}
```
