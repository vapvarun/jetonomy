# Cache tests — how to run & the persistent-cache requirement

These tests cover the 1.7.1 cache-conformance work (plan:
`docs/plans/1.7.1-cache-conformance.md`). Read this before trusting a green run.

## Two kinds of cache bug, two kinds of test

| Bug class | Reproduces without Redis? | Test shape here |
|---|---|---|
| **Missing bust** (a writer never `wp_cache_delete`s a key it changed) | **Yes** — within one process WP's array cache persists, so prime → write → re-read returns the stale value | single-process (all `*Test.php` in this dir) |
| **Cross-request staleness** (request A primes, request B reads stale) | **No** — needs a persistent object cache (Redis/Memcached); on the default cache every request recomputes and always looks fresh (Caching Standard §4c) | cross-process (see below) — Redis-only |

**Consequence:** the committed single-process tests are meaningful on the default
(array) object cache and do **not** skip. They catch the missing-bust class, which
is every fix in this release. Do not add a blanket "skip without Redis" guard — it
would disable valid coverage.

## Run the single-process suite

Requires the WP test library (once):

```bash
bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer test -- --group cache          # or: vendor/bin/phpunit --group cache
```

Or in Docker (`composer test:docker`), which brings its own DB.

## Local verification actually performed (2026-07-15, jetonomy.local, no Redis drop-in)

Each task was driven live through `wp eval` (single process, real DB). Observed:

| Task | Check | Result |
|---|---|---|
| T1 | `Cache::delete_many` + `flush()` | `T1_OK` — keys gone, no fatal |
| T2 | space `public`→`private` read back via **slug** key; increment busts `space:{id}`; delete busts both | `T2_OK` — `v1=public v2=private p1=0 p2=1 after=null` |
| T3 | `update_profile` / `_apply_reputation_delta` fresh after write | `T3_OK` — `rep 338→343`, bio fresh |
| T4 | GDPR `recompute_counters_after_purge` (real private method) busts each space | `T4_OK` — `primed=999 → cached_after=1` |
| T5 | `Recount::run()` flush | `T5_OK` — `primed=777 → cached_after=0` |
| T6 | privileged list uses object cache, writes no transient, bust clears it | `T6_OK` — `transient=false primed=SET after_bust=MISS` |
| T7 | `is_online` via wrapper, key `online_{id}` | `T7_OK` — bool + cached |

## Cross-process proof (Redis box — the §4c-complete verification)

On a site with a persistent object-cache drop-in (`wp-content/object-cache.php`,
Redis/Memcached), the stronger check is prime → write → read across three
separate PHP processes. `wp_using_ext_object_cache()` must be `true`.

```bash
# 1. prime (process A)
wp eval 'Jetonomy\Models\Space::find_by_slug("some-space");'
# 2. write (process B) — flip visibility in admin, or:
wp eval 'Jetonomy\Models\Space::update( ID, ["visibility"=>"private"] );'
# 3. read (process C) — MUST be private, not the primed public row
wp eval 'echo Jetonomy\Models\Space::find_by_slug("some-space")->visibility;'
```

A cross-process PHPUnit test MUST guard:

```php
if ( ! wp_using_ext_object_cache() ) {
    $this->markTestSkipped( 'Cross-request cache assertions need a persistent object cache (Standard §4c).' );
}
```

The recommended box mirrors `~/dev/buddynext-scale-docker` (WP + MySQL + Redis
drop-in). Record observed results here when run, as the 1.0.8 plan's §6.3.1 does.
