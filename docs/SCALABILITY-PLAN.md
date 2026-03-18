# Jetonomy Scalability Plan

> Long-term solutions for 10K → 100K → 1M users. No band-aids. Proper architecture.

---

## Scale Tiers

| Tier | Users | Posts | Replies | Hosting | Key Challenge |
|---|---|---|---|---|---|
| **Small** | <1K | <10K | <50K | Shared/VPS | None — everything works |
| **Medium** | 1K-10K | 10K-100K | 50K-500K | VPS + Redis | Cron jobs, N+1 queries |
| **Large** | 10K-100K | 100K-1M | 500K-5M | Dedicated + Redis + Search | Background processing, caching |
| **Enterprise** | 100K+ | 1M+ | 5M+ | Cloud + Read replicas + CDN | Everything below + horizontal scaling |

---

## Core Plugin Scalability

### Database Indexes (Already Done)
- All 21 tables have proper indexes for actual query patterns
- Denormalized counters eliminate COUNT queries
- FULLTEXT indexes on posts + replies

### What Needs Work

#### 1. Action Scheduler Instead of WP-Cron

**Problem:** WP-Cron is unreliable at scale — it's triggered by page visits, can't handle long-running jobs, and has no retry/failure handling.

**Solution:** Use [Action Scheduler](https://actionscheduler.org/) (ships with WooCommerce, 800K+ sites use it). It provides:
- Reliable background job processing
- Automatic retry on failure
- Concurrent execution (multiple workers)
- Admin UI for monitoring jobs
- Batch processing built-in

**Implementation:**
```php
// Instead of:
wp_schedule_event( time(), 'twicedaily', 'jetonomy_trust_evaluation' );

// Use:
as_schedule_recurring_action( time(), 12 * HOUR_IN_SECONDS, 'jetonomy_trust_evaluation' );

// For batch processing:
as_enqueue_async_action( 'jetonomy_evaluate_badges_batch', [ 'offset' => 0, 'limit' => 100 ] );
```

**Fallback:** If Action Scheduler is not available, fall back to WP-Cron with smaller batch sizes.

**Files:** `includes/class-cron.php` → refactor to `includes/class-queue.php`

---

#### 2. Object Cache Layer

**Problem:** Same data fetched repeatedly on every page load (space data, user profiles, trust levels).

**Solution:** Cache layer using WordPress object cache (Redis/Memcached when available, falls back to in-memory).

**Implementation:**
```php
// New file: includes/class-cache.php
namespace Jetonomy;

class Cache {
    private const GROUP = 'jetonomy';
    private const TTL = 300; // 5 minutes default

    public static function get( string $key ) {
        return wp_cache_get( $key, self::GROUP );
    }

    public static function set( string $key, $value, int $ttl = 0 ): void {
        wp_cache_set( $key, $value, self::GROUP, $ttl ?: self::TTL );
    }

    public static function delete( string $key ): void {
        wp_cache_delete( $key, self::GROUP );
    }

    public static function flush_group(): void {
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( self::GROUP );
        }
    }
}
```

**Cache keys:**
```
jetonomy:space:{id}          → Space object (invalidate on update)
jetonomy:space:slug:{slug}   → Space object by slug
jetonomy:profile:{user_id}   → UserProfile object (invalidate on rep change)
jetonomy:perm:{user}:{space} → Permission result (TTL: 60s)
jetonomy:trending            → Trending posts (TTL: 120s)
jetonomy:leaderboard:{scope} → Leaderboard data (TTL: 300s)
```

**Invalidation:** On write operations, delete the relevant cache key.

---

#### 3. Eager Loading for API Responses

**Problem:** N+1 queries when loading post lists with author data. Loading 20 posts = 1 query for posts + 20 queries for authors + 20 for profiles.

**Solution:** Batch-load related data in a single query.

**Implementation:**
```php
// In Posts_Controller::list_items():
$posts = Post::list_by_space( $space_id, $sort, $limit, $offset );

// Batch-load all authors in one query
$author_ids = array_unique( array_column( $posts, 'author_id' ) );
$authors = self::batch_load_users( $author_ids );
$profiles = self::batch_load_profiles( $author_ids );

// Attach to each post
foreach ( $posts as &$post ) {
    $post->author = $authors[ $post->author_id ] ?? null;
    $post->profile = $profiles[ $post->author_id ] ?? null;
}
```

```php
// Helper in Base_Controller:
protected function batch_load_users( array $ids ): array {
    if ( empty( $ids ) ) return [];
    global $wpdb;
    $in = implode( ',', array_map( 'intval', $ids ) );
    $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->users} WHERE ID IN ({$in})" );
    $map = [];
    foreach ( $rows as $row ) $map[ (int) $row->ID ] = $row;
    return $map;
}

protected function batch_load_profiles( array $ids ): array {
    if ( empty( $ids ) ) return [];
    global $wpdb;
    $in = implode( ',', array_map( 'intval', $ids ) );
    $rows = $wpdb->get_results( "SELECT * FROM " . table('user_profiles') . " WHERE user_id IN ({$in})" );
    $map = [];
    foreach ( $rows as $row ) $map[ (int) $row->user_id ] = $row;
    return $map;
}
```

**Apply to:** Posts_Controller, Replies_Controller, Spaces_Controller (members), Notifications_Controller, Leaderboard queries.

---

## Pro Module Scalability

### Module: Email Digest

**Problem:** Iterates ALL users in one cron run. 10K users = 40K queries = timeout.

**Long-term solution: Batch queue processing**

```
1. Cron fires daily → enqueues batch jobs
2. Each batch: 50 users
3. For 10K users: 200 batch jobs queued
4. Action Scheduler processes batches in parallel
5. Each batch: fetch users → compile → send → mark sent
6. Failed batches auto-retry
```

**Implementation:**
```php
public function process_digest( string $frequency ): void {
    global $wpdb;

    // Count eligible users
    $total = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->usermeta}
         WHERE meta_key = %s AND meta_value = %s",
        self::META_FREQUENCY, $frequency
    ) );

    // Enqueue batches of 50
    $batch_size = 50;
    for ( $offset = 0; $offset < $total; $offset += $batch_size ) {
        as_enqueue_async_action(
            'jetonomy_pro_digest_batch',
            [ 'frequency' => $frequency, 'offset' => $offset, 'limit' => $batch_size ]
        );
    }
}

// Batch handler
public function process_digest_batch( string $frequency, int $offset, int $limit ): void {
    $users = $this->get_eligible_users( $frequency, $offset, $limit );
    foreach ( $users as $user_id ) {
        $digest = $this->compile_digest( $user_id, $frequency );
        if ( ! empty( $digest ) ) {
            $this->send_digest( $user_id, $digest, $frequency );
        }
    }
}
```

**Optimized compilation query (single query instead of 4):**
```sql
SELECT p.id, p.title, p.slug, p.vote_score, p.reply_count,
       s.slug AS space_slug, s.title AS space_title,
       u.display_name AS author_name
FROM jt_posts p
INNER JOIN jt_spaces s ON p.space_id = s.id
INNER JOIN wp_users u ON p.author_id = u.ID
WHERE p.status = 'publish'
  AND p.created_at > %s
  AND (
      p.space_id IN (SELECT object_id FROM jt_subscriptions WHERE user_id = %d AND object_type = 'space')
      OR p.author_id IN (SELECT author_id FROM jt_replies WHERE post_id IN (SELECT id FROM jt_posts WHERE author_id = %d))
  )
ORDER BY p.vote_score DESC
LIMIT 10
```

---

### Module: Custom Badges

**Problem:** Evaluator fetches ALL profiles × ALL badges = quadratic queries.

**Long-term solution: Incremental evaluation + cached stats**

**Strategy 1: Evaluate on activity, not on cron**

Instead of evaluating ALL users every 6 hours, evaluate a user when their stats change:

```php
// Hook into reputation changes
add_action( 'jetonomy_reputation_changed', function( $user_id ) {
    as_enqueue_async_action( 'jetonomy_pro_evaluate_user_badges', [ $user_id ] );
} );

// Hook into post/reply creation
add_action( 'jetonomy_after_create_post', function( $post_id, $space_id ) {
    as_enqueue_async_action( 'jetonomy_pro_evaluate_user_badges', [ get_current_user_id() ] );
} );
```

**Strategy 2: Batch evaluation with progress tracking**

```php
public function evaluate_badges(): void {
    // Get last evaluated user_id (resume from where we stopped)
    $last_id = (int) get_option( 'jetonomy_badge_eval_cursor', 0 );

    global $wpdb;
    $profiles = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$profiles_table} WHERE user_id > %d ORDER BY user_id ASC LIMIT 100",
        $last_id
    ) );

    if ( empty( $profiles ) ) {
        // All done — reset cursor for next cycle
        delete_option( 'jetonomy_badge_eval_cursor' );
        return;
    }

    foreach ( $profiles as $profile ) {
        $this->evaluate_user( $profile );
    }

    // Save cursor for next batch
    update_option( 'jetonomy_badge_eval_cursor', end( $profiles )->user_id );

    // Schedule next batch immediately
    as_enqueue_async_action( 'jetonomy_pro_evaluate_badges' );
}
```

**Strategy 3: Pre-computed stats table**

```sql
CREATE TABLE jt_pro_user_stats (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    post_count INT DEFAULT 0,
    reply_count INT DEFAULT 0,
    reputation INT DEFAULT 0,
    trust_level TINYINT DEFAULT 0,
    vote_received INT DEFAULT 0,
    days_active INT DEFAULT 0,
    accepted_answers INT DEFAULT 0,
    spaces_joined INT DEFAULT 0,
    updated_at DATETIME NOT NULL,
    INDEX idx_updated (updated_at)
);
```

Stats updated incrementally on each action. Badge evaluator reads from this table — no JOINs needed.

---

### Module: Analytics

**Problem:** Correlated subqueries in top-spaces endpoint. With 100 spaces, each row triggers 2 sub-selects.

**Long-term solution: Materialized stats tables + scheduled refresh**

```sql
-- Daily stats (refreshed by cron, never computed in real-time)
CREATE TABLE jt_pro_daily_stats (
    date DATE NOT NULL,
    space_id BIGINT UNSIGNED DEFAULT NULL,
    posts_count INT DEFAULT 0,
    replies_count INT DEFAULT 0,
    active_users INT DEFAULT 0,
    votes_count INT DEFAULT 0,
    new_users INT DEFAULT 0,
    PRIMARY KEY (date, space_id),
    INDEX idx_date (date)
);
```

**Refresh cron (runs at midnight):**
```php
public function refresh_daily_stats(): void {
    $yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

    // One query per metric, not per space
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$stats_table} (date, space_id, posts_count)
         SELECT %s, space_id, COUNT(*)
         FROM {$posts_table}
         WHERE DATE(created_at) = %s AND status = 'publish'
         GROUP BY space_id
         ON DUPLICATE KEY UPDATE posts_count = VALUES(posts_count)",
        $yesterday, $yesterday
    ) );

    // Same for replies, votes, users...
}
```

**Analytics API reads from stats table — O(1) per space instead of O(N).**

---

### Module: Reactions

**Problem:** N+1 queries when loading reactions for a post list (20 posts = 20 reaction queries).

**Long-term solution: Batch load + denormalized reaction summary**

**Option A: Denormalized JSON column on posts table**
```sql
ALTER TABLE jt_posts ADD COLUMN reactions_summary JSON DEFAULT NULL;
-- Stores: {"thumbsup": 5, "heart": 3, "laugh": 1}
-- Updated on every reaction toggle
```

**Option B: Batch load in API**
```php
// In Posts_Controller, after loading posts:
$post_ids = array_column( $posts, 'id' );
$reactions = Reactions::batch_load_summaries( $post_ids );
// Single query: SELECT object_id, emoji, COUNT(*) FROM reactions WHERE object_id IN (...) GROUP BY object_id, emoji
```

**Recommendation:** Option B (batch load) — cleaner, no schema change.

---

### Module: Private Messaging

**Problem:** Unread-count polling every 15 seconds × 10K concurrent users = 150K queries/minute.

**Long-term solution: Cached unread count + event-driven invalidation**

```php
// On new message, update sender's cache
public function on_message_sent( $conversation_id, $sender_id ): void {
    $participants = $this->get_participants( $conversation_id );
    foreach ( $participants as $uid ) {
        if ( $uid !== $sender_id ) {
            // Increment cached unread count
            $key = "jetonomy:msg_unread:{$uid}";
            $current = (int) wp_cache_get( $key, 'jetonomy' );
            wp_cache_set( $key, $current + 1, 'jetonomy', HOUR_IN_SECONDS );
        }
    }
}

// Polling endpoint reads from cache
public function rest_unread_count(): WP_REST_Response {
    $user_id = get_current_user_id();
    $cached = wp_cache_get( "jetonomy:msg_unread:{$user_id}", 'jetonomy' );

    if ( false !== $cached ) {
        return new WP_REST_Response( [ 'count' => (int) $cached ] );
    }

    // Cache miss — compute and cache
    $count = $this->compute_unread_count( $user_id );
    wp_cache_set( "jetonomy:msg_unread:{$user_id}", $count, 'jetonomy', HOUR_IN_SECONDS );
    return new WP_REST_Response( [ 'count' => $count ] );
}
```

**For real-time (v2.0):** Replace polling with Server-Sent Events or Mercure push.

---

### Module: Advanced Moderation

**Problem:** At scale, regex evaluation on every post/reply could be slow with many rules.

**Long-term solution:** Already efficient — rules are cached on first load. Only improvement needed:

```php
// Cache rules per request (already loads once from DB)
private function get_active_rules( int $space_id ): array {
    $cache_key = "jetonomy:mod_rules:{$space_id}";
    $cached = wp_cache_get( $cache_key, 'jetonomy' );
    if ( false !== $cached ) return $cached;

    $rules = $this->query_rules( $space_id );
    wp_cache_set( $cache_key, $rules, 'jetonomy', 300 );
    return $rules;
}
```

---

## Infrastructure Recommendations by Scale

### Medium (1K-10K users)

```
Required:
├── Redis or Memcached (object cache)
├── System cron replacing WP-Cron
├── Action Scheduler for background jobs
└── CDN for static assets

Recommended:
├── Batch size: 50 users per digest job
├── Cache TTL: 5 minutes for profiles, 2 minutes for stats
└── Polling interval: 15 seconds (current default)
```

### Large (10K-100K users)

```
Required:
├── Everything from Medium
├── Action Scheduler with multiple workers
├── Materialized daily stats table (analytics)
├── Batch badge evaluation (100 users per job)
├── Cached unread counts (messaging)
├── Meilisearch or Elasticsearch (Pro search adapter)
└── MySQL read replica for heavy read queries

Recommended:
├── Batch size: 100 users per digest job
├── Badge evaluation: event-driven + nightly sweep
├── Polling interval: 30 seconds
├── Edge caching for public pages (Cloudflare)
└── Database connection pooling
```

### Enterprise (100K+ users)

```
Required:
├── Everything from Large
├── Mercure or Pusher (real-time push, no polling)
├── Pre-computed user stats table
├── Reaction summaries batch-loaded or denormalized
├── Queue-based email sending (SES/SendGrid with rate limiting)
├── Horizontal scaling (multiple app servers)
├── Redis Cluster for sessions + cache
└── Database sharding or read replicas with query routing

Recommended:
├── GraphQL API layer (for mobile apps)
├── WebSocket for messaging
├── Content delivery via edge workers
└── Automated scaling based on traffic
```

---

## Implementation Priority

| Task | Scale Tier | Effort | Impact |
|---|---|---|---|
| **Add Cache class** | Medium | 2 hours | High — every module benefits |
| **Eager loading in API controllers** | Medium | 3 hours | High — eliminates N+1 |
| **Batch digest processing** | Medium | 4 hours | Critical — prevents timeout |
| **Batch badge evaluation** | Medium | 3 hours | Critical — prevents timeout |
| **Materialized analytics stats** | Large | 6 hours | High — analytics becomes instant |
| **Cached unread counts** | Large | 2 hours | High — reduces polling load |
| **Action Scheduler integration** | Large | 4 hours | High — reliable background jobs |
| **Pre-computed user stats table** | Enterprise | 8 hours | Medium — enables complex queries |
| **Reaction batch loading** | Large | 2 hours | Medium — faster post listings |
| **Moderation rules caching** | Medium | 1 hour | Low — already fast enough |

---

## Migration Path

When upgrading from Small to Medium tier:
1. Install Redis + object cache plugin
2. Activate Action Scheduler (bundled or via WooCommerce)
3. Switch WP-Cron to system cron
4. Jetonomy auto-detects and uses enhanced infrastructure

**No code changes needed by the site owner.** Jetonomy detects what's available:
```php
// Check if Action Scheduler is available
if ( function_exists( 'as_enqueue_async_action' ) ) {
    // Use Action Scheduler for batch jobs
} else {
    // Fall back to WP-Cron with smaller batches
}

// Check if object cache is persistent
if ( wp_using_ext_object_cache() ) {
    // Use aggressive caching
} else {
    // Use transients with shorter TTLs
}
```
