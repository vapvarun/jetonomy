# Cache tests — how to run & the persistent-cache requirement

These tests cover the 1.7.1 cache-conformance work (plan:
`../jetonomy-pro/docs/plans/free/1.7.1-cache-conformance.md` (internal plans live in Pro)). Read this before trusting a green run.

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

## Reproduce the Redis box (docker-compose)

A minimal stack — WordPress + MariaDB + Redis + the redis-cache drop-in,
bind-mounting the free + pro plugin repos. Kept OUTSIDE the repo (git repos stay
out of cloud-synced folders, per the BuddyNext 1.0.8 lesson):
`~/dev/jetonomy-redis-docker/docker-compose.yml` (db: mariadb:11, redis:
redis:7-alpine, wp: wordpress:php8.2-apache on :8099, cli: wordpress:cli-php8.2).

```bash
cd ~/dev/jetonomy-redis-docker && docker compose up -d
run(){ docker compose exec -T cli wp --allow-root "$@"; }
run core install --url=http://localhost:8099 --title=JetRedis \
    --admin_user=admin --admin_password=admin --admin_email=a@b.co --skip-email
run plugin activate jetonomy jetonomy-pro
run plugin install redis-cache --activate
run config set WP_REDIS_HOST redis
run config set WP_REDIS_PORT 6379 --raw
run redis enable          # installs the object-cache.php drop-in
run redis status          # Status: Connected, Drop-in: Valid
run eval 'echo wp_using_ext_object_cache() ? "ON" : "OFF";'   # ON
```

Each `wp eval` is a separate process = a separate request sharing Redis, so the
three-step prime → write → read below is a true cross-request test.

## Observed Redis cross-request results — RECORDED 2026-07-15

Stack: WordPress 6.x + MariaDB 11 + Redis 7 + Redis Object Cache v2.8.0 (Predis
v2.4.0), `wp_using_ext_object_cache()` = **true**. Free + Pro `1.7.1-dev`.

| # | Check (3 separate `wp eval` processes) | Observed | Verdict |
|---|---|---|---|
| T2 | prime `find_by_slug` (public) → proc B `update(visibility=private)` → proc C `find_by_slug` | proc C = **private** | PASS — slug bust persists cross-request (J1 disclosure fix holds on Redis) |
| **Neg. control** | prime `find(id)` → proc B **direct DB** `UPDATE post_count=555` (no model, no bust) → proc C `find(id)` | proc C = **0** (stale, not 555) | PASS — Redis persists cross-request, so a *real* missing bust IS caught; the tests do not pass for the wrong reason (§4c) |
| T6 | prime `list_privileged` → proc B assert store → proc C bust → read | transient **none**, object cache **set**, after bust **miss** | PASS — object cache (not transient), bust persists cross-request |

The negative control is the load-bearing check: it proves the environment
reproduces the cross-request staleness class, so the T2/T6 passes are real. T4
(GDPR) and T5 (recount) use the same `Space::bust_cache()` / `Cache::flush()`
primitives proven here, and were verified single-process on the Local site.

Tear down: `cd ~/dev/jetonomy-redis-docker && docker compose down -v`.
