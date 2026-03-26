# Jetonomy — Next-Gen WordPress Forum Plugin Design Spec

**Date**: 2026-03-17
**Status**: Design Complete (Spec reviewed, critical issues fixed, ready for user review)
**Approach**: Interactivity-Native (Approach 1)

---

## Table of Contents

1. [Plugin Identity & Architecture Overview](#section-1-plugin-identity--architecture-overview)
2. [Database Schema](#section-2-database-schema)
3. [Trust Levels & Gamification](#section-3-trust-levels--gamification)
4. [REST API Design](#section-4-rest-api-design)
5. [Capabilities & Permission System](#section-4b-capabilities--permission-system)
6. [Membership Plugin Integration Layer](#section-4c-membership-plugin-integration-layer)
7. [Frontend Architecture](#section-5-frontend-architecture)
8. [Extension & Pro Architecture](#section-6-extension--pro-architecture)
9. [Migration & Import Strategy](#section-7-migration--import-strategy)
10. [Email & Notification System](#section-8-email--notification-system)
11. [SEO Strategy](#section-9-seo-strategy)
12. [Admin Dashboard](#section-10-admin-dashboard)
13. [Performance & Caching Strategy](#section-11-performance--caching-strategy)
14. [Testing Strategy](#section-12-testing-strategy)

---

## Design Decisions Summary

| Decision | Choice | Rationale |
|---|---|---|
| Architecture | Modular monolith, adapter-based | Zero hard dependencies on external plugins |
| Data storage | Custom MySQL tables | EAV (wp_postmeta) cannot scale to millions of records |
| API | WP REST API (cursor-based pagination) | Enables mobile apps, headless, integrations |
| Frontend (read) | WP Interactivity API (SSR + hydration) | ~10KB, SEO-friendly, WordPress-native |
| Frontend (write) | Preact islands (lazy-loaded) | Rich editor, moderation UI, complex interactions |
| Real-time | Progressive: polling (free) → Mercure/Pusher (Pro) | Works on any hosting, upgradeable |
| Search | MySQL FULLTEXT (free) → Meilisearch/ES (Pro) | Start simple, upgrade when needed |
| Community types | Forum, Q&A, Ideas, Social Feed | Unified "Space" abstraction with swappable behaviors |
| Monetization | Freemium: free core + Pro extensions | Generous free tier drives adoption |
| Membership | Universal adapter interface | Works with any membership plugin |
| Permissions | 3-layer: WP Caps + Space Roles + Trust Levels | Global control, per-space autonomy, earned trust |
| CSS | CSS Layers + Custom Properties | No framework dependency, theme-safe |
| Threading | Adjacency list + PHP tree construction | Simple, performant for forum-sized threads |

### Priority Order for Community Types

1. Forum (classic discussion) — foundation
2. Q&A (voting + accepted answers)
3. Ideas (voting, status tracking, roadmap)
4. Social Feed (activity stream, follows, reactions)

---

## Section 1: Plugin Identity & Architecture Overview

**Plugin Name**: Jetonomy (working name — "jeton" means token/badge, "onomy" suggests a system/economy)

**Tagline**: Next-gen discussion platform for WordPress

**Target**: Medium-scale communities (up to ~1M posts), architecturally sound enough that a SaaS business could be built on top of it. We're not building the SaaS — we're building the engine.

**Architecture**: Modular monolith with clean boundaries

```
+---------------------------------------------------+
|                  WordPress Core                     |
+-----------+-----------+-----------+--------+-------+
|  Forum    |   Q&A     |  Ideas    |  Feed  |       |
|  Module   |  Module   |  Module   | Module |       |
+-----------+-----------+-----------+--------+       |
|            Core Engine                              |
|  +--------+--------+---------+----------+          |
|  | Posts  | Votes  | Trust   | Notifi-  |          |
|  | System | System | Levels  | cations  |          |
|  +--------+--------+---------+----------+          |
|  | Spaces | Tags   | Moder-  | Search   |          |
|  |        |        | ation   |          |          |
|  +--------+--------+---------+----------+          |
+----------------------------------------------------+
|            REST API Layer (v1)                       |
+----------------------------------------------------+
|       Custom Tables (MySQL/MariaDB)                 |
+----------------------------------------------------+
|  Frontend: Interactivity API + Preact Islands       |
+----------------------------------------------------+
```

### Key Concepts

- **Spaces** = containers (a forum, a Q&A board, an idea board, a feed). Each Space has a `type` that determines its behavior.
- **Posts** = the universal content unit (topic, question, idea, status update). Type determined by the parent Space.
- **Replies** = responses to Posts (threaded via adjacency list).
- **Modules** = pluggable behavior layers that activate based on Space type.
- **Categories** = top-level organizational grouping for Spaces.

### Core Design Principle: Universal Adapter Architecture

Every external integration follows the same adapter contract. The plugin core has zero hard dependencies on any external plugin. Everything is swappable.

```
Adapter types:
- Membership plugins    -> who gets access
- Email marketing       -> digest notifications via their ESP
- LMS/Course plugins    -> course-gated spaces
- eCommerce             -> product support forums
- Social login          -> registration/auth providers
- Spam protection       -> Akismet, CleanTalk, custom
- Media handling        -> where uploads go (local, S3, cloud)
- Search providers      -> MySQL, Meilisearch, Elasticsearch, Algolia
- Real-time providers   -> Polling, Mercure, Pusher, Ably
- Analytics             -> what tracking fires on events
```

---

## Section 2: Database Schema

Custom tables designed for forum-specific access patterns. Denormalized counters eliminate expensive COUNT queries.

### Core Tables

#### wp_jt_categories

```sql
CREATE TABLE wp_jt_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(100),
    color VARCHAR(7),
    sort_order INT DEFAULT 0,
    space_count INT DEFAULT 0,
    visibility ENUM('public','private','hidden') DEFAULT 'public',
    created_at DATETIME NOT NULL,
    INDEX idx_parent_sort (parent_id, sort_order),
    FOREIGN KEY (parent_id) REFERENCES wp_jt_categories(id) ON DELETE SET NULL
);
```

#### wp_jt_spaces

```sql
CREATE TABLE wp_jt_spaces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    type ENUM('forum','qa','ideas','feed') NOT NULL DEFAULT 'forum',
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(100),
    cover_image VARCHAR(500),
    visibility ENUM('public','private','hidden') DEFAULT 'public',
    join_policy ENUM('open','approval','invite') DEFAULT 'open',
    status ENUM('active','archived','locked') DEFAULT 'active',
    sort_order INT DEFAULT 0,
    settings JSON,
    post_count INT DEFAULT 0,
    member_count INT DEFAULT 0,
    last_activity_at DATETIME,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_category_sort (category_id, sort_order),
    INDEX idx_parent_sort (parent_id, sort_order),
    FOREIGN KEY (category_id) REFERENCES wp_jt_categories(id),
    FOREIGN KEY (parent_id) REFERENCES wp_jt_spaces(id) ON DELETE SET NULL
);
```

#### wp_jt_posts

```sql
CREATE TABLE wp_jt_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    type ENUM('topic','question','idea','status') NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    content_plain LONGTEXT NOT NULL,
    status ENUM('publish','pending','draft','spam','trash') DEFAULT 'publish',
    is_sticky TINYINT(1) DEFAULT 0,
    is_closed TINYINT(1) DEFAULT 0,
    is_resolved TINYINT(1) DEFAULT 0,
    idea_status ENUM('submitted','under_review','planned','in_progress','completed','declined') NULL,
    vote_score INT DEFAULT 0,
    reply_count INT DEFAULT 0,
    view_count INT DEFAULT 0,
    last_reply_at DATETIME NULL,
    last_reply_by BIGINT UNSIGNED NULL,
    accepted_reply_id BIGINT UNSIGNED NULL,
    edited_at DATETIME NULL,
    edited_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_space_sticky_last (space_id, is_sticky DESC, last_reply_at DESC),
    INDEX idx_space_votes (space_id, vote_score DESC),
    INDEX idx_author_created (author_id, created_at DESC),
    FULLTEXT idx_search (title, content_plain),
    FOREIGN KEY (space_id) REFERENCES wp_jt_spaces(id)
);
```

#### wp_jt_replies

```sql
CREATE TABLE wp_jt_replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    content LONGTEXT NOT NULL,
    content_plain LONGTEXT NOT NULL,
    status ENUM('publish','pending','spam','trash') DEFAULT 'publish',
    vote_score INT DEFAULT 0,
    is_accepted TINYINT(1) DEFAULT 0,
    edited_at DATETIME NULL,
    edited_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_post_created (post_id, created_at ASC),
    INDEX idx_post_votes (post_id, vote_score DESC),
    INDEX idx_author_created (author_id, created_at DESC),
    FULLTEXT idx_search (content_plain),
    FOREIGN KEY (post_id) REFERENCES wp_jt_posts(id),
    FOREIGN KEY (parent_id) REFERENCES wp_jt_replies(id) ON DELETE SET NULL
);
```

#### wp_jt_votes

```sql
CREATE TABLE wp_jt_votes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    object_type ENUM('post','reply') NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    value TINYINT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_user_vote (user_id, object_type, object_id)
);
```

### Engagement & Trust Tables

#### wp_jt_user_profiles

```sql
CREATE TABLE wp_jt_user_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    display_name VARCHAR(100),
    bio TEXT,
    avatar_url VARCHAR(500),
    trust_level TINYINT UNSIGNED DEFAULT 0,
    reputation INT DEFAULT 0,
    post_count INT DEFAULT 0,
    reply_count INT DEFAULT 0,
    vote_received INT DEFAULT 0,
    badges JSON,
    settings JSON,
    last_seen_at DATETIME,
    created_at DATETIME NOT NULL,
    INDEX idx_trust_rep (trust_level, reputation DESC)
);
```

#### wp_jt_notifications

```sql
CREATE TABLE wp_jt_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    object_type ENUM('post','reply','space','badge') NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    message VARCHAR(500),
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_user_unread (user_id, is_read, created_at DESC)
);
```

#### wp_jt_subscriptions

```sql
CREATE TABLE wp_jt_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    object_type ENUM('space','post') NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    notify_via ENUM('web','email','both') DEFAULT 'both',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_subscription (user_id, object_type, object_id)
);
```

#### wp_jt_read_status

```sql
CREATE TABLE wp_jt_read_status (
    user_id BIGINT UNSIGNED NOT NULL,
    post_id BIGINT UNSIGNED NOT NULL,
    last_read_reply_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, post_id)
);
```

#### wp_jt_space_members

```sql
CREATE TABLE wp_jt_space_members (
    space_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('viewer','member','moderator','admin') DEFAULT 'member',
    joined_at DATETIME NOT NULL,
    PRIMARY KEY (space_id, user_id),
    INDEX idx_user_spaces (user_id, joined_at DESC)
);
```

#### wp_jt_tags (post-level tags)

```sql
CREATE TABLE wp_jt_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    post_count INT DEFAULT 0
);

CREATE TABLE wp_jt_post_tags (
    post_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    INDEX idx_tag_posts (tag_id, post_id)
);
```

#### wp_jt_space_tags (space discovery tags)

```sql
CREATE TABLE wp_jt_space_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    space_count INT DEFAULT 0
);

CREATE TABLE wp_jt_space_tag_map (
    space_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (space_id, tag_id)
);
```

#### wp_jt_user_interests

```sql
CREATE TABLE wp_jt_user_interests (
    user_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, tag_id)
);
```

#### wp_jt_activity_log

```sql
CREATE TABLE wp_jt_activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    object_type VARCHAR(50) NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    metadata JSON,
    created_at DATETIME NOT NULL,
    INDEX idx_user_activity (user_id, created_at DESC),
    INDEX idx_global_feed (created_at DESC)
);
```

### Grouping & Classification

3-tier hierarchy for audience distribution:

```
Category (top-level)   ->   "Programming Languages"
  Space                ->     "Python Developers"
    Sub-Space          ->       "Django Framework"
                                "Data Science with Python"
                                "Python Beginners"
```

Discovery paths:
- **Browse by structure**: Category -> Space -> Sub-Space -> Posts
- **Browse by interest tags**: Tag "machine-learning" shows Spaces across multiple Categories
- **Personalized feed**: User's interests + joined spaces -> algorithmic feed
- **Trending / Discover**: Active spaces, rising posts, popular tags

---

## Section 3: Trust Levels & Gamification

### Trust Level System (Progressive Privileges)

```
Level 0: Newcomer (default)
- Can read all public spaces
- Rate-limited: 3 posts/day, 10 replies/day, 5 votes/day
- Cannot post links or images (anti-spam)
- Posts may require approval in some spaces

Level 1: Member (auto-earned)
- Requirements: 5+ posts, 3+ days active, 10+ replies received
- Can post links and images
- Can flag/report content
- Can use @mentions
- No rate limits on posting
- Can create polls

Level 2: Regular (auto-earned)
- Requirements: 30+ posts, 20+ days active, 50+ reputation
- Can edit own posts without cooldown
- Can edit tags on others' posts
- Can access "Regulars" lounge space (if enabled)
- Can invite users to private spaces
- Can upload file attachments

Level 3: Trusted (auto-earned)
- Requirements: 100+ posts, 60+ days active, 200+ reputation
- Can edit others' posts (wiki-style)
- Can move topics between spaces
- Can close/reopen topics
- Can mark topics as resolved (Q&A)
- Can pin topics temporarily
- Posts never require approval

Level 4: Leader (granted by moderators/admins)
- All Level 3 abilities
- Can create sub-spaces
- Can manage space tags
- Can silence/suspend users (temporary)
- Can approve join requests
- Can set topic templates per space

Level 5: Moderator (granted by admins)
- Full moderation powers
- Can manage user trust levels
- Can access moderation dashboard
- Can manage space settings
- Can ban users
- Can access spam queue
```

### Reputation System

```
Action                              Points
Your post gets upvoted              +10
Your reply gets upvoted             +5
Your reply accepted (Q&A)           +15
Your idea marked "planned"          +20
You upvote others                    0
You get downvoted                   -2
Your flag is validated by mod       +5
You earn a badge                    +5 to +50
Your post gets reported (validated) -10
Your post removed by mod            -20
```

### Badge System

#### Tables

```sql
CREATE TABLE wp_jt_badges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(100),
    tier ENUM('bronze','silver','gold') NOT NULL,
    category ENUM('participation','quality','community','special') NOT NULL,
    criteria JSON,
    reputation_bonus INT DEFAULT 0,
    is_repeatable TINYINT(1) DEFAULT 0,
    created_at DATETIME NOT NULL
);

CREATE TABLE wp_jt_user_badges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    badge_id BIGINT UNSIGNED NOT NULL,
    earned_at DATETIME NOT NULL,
    metadata JSON,
    INDEX idx_user_badges (user_id, earned_at DESC)
);
```

#### Example Badges

| Badge | Tier | Criteria | Category |
|---|---|---|---|
| First Post | Bronze | Create first post | Participation |
| Conversation Starter | Bronze | Topic with 10+ replies | Quality |
| Helpful Answer | Silver | 3 accepted answers in Q&A | Quality |
| Idea Maker | Silver | 5 ideas reached "planned" | Quality |
| Community Builder | Silver | Invite 10 users who reach Level 1 | Community |
| Trusted Voice | Gold | Reach Trust Level 3 | Community |
| Top Contributor | Gold | #1 reputation in a space for 30 days | Quality |
| Founding Member | Gold | First 50 members of a space | Special |

### Leaderboards

```sql
CREATE TABLE wp_jt_leaderboards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope ENUM('global','space','category') NOT NULL,
    scope_id BIGINT UNSIGNED NULL,
    period ENUM('weekly','monthly','alltime') NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    rank INT NOT NULL,
    score INT NOT NULL,
    computed_at DATETIME NOT NULL,
    UNIQUE KEY uniq_leaderboard (scope, scope_id, period, user_id),
    INDEX idx_leaderboard_rank (scope, scope_id, period, rank)
);
```

Leaderboards are materialized tables refreshed via WP-Cron, not computed on-the-fly.

---

## Section 4: REST API Design

### Namespace & Versioning

```
Base: /wp-json/jetonomy/v1/
```

### Endpoints

#### Spaces

```
GET    /spaces                          - list (filter by category, type, tag)
GET    /spaces/:id                      - single space with stats
POST   /spaces                          - create (Level 4+ or admin)
PATCH  /spaces/:id                      - update
DELETE /spaces/:id                      - trash (admin only)
GET    /spaces/:id/members              - list members with roles
POST   /spaces/:id/members              - join / request to join
DELETE /spaces/:id/members/:user_id     - leave / remove
PATCH  /spaces/:id/members/:user_id     - change role
```

#### Categories

```
GET    /categories                      - list (nested)
GET    /categories/:id                  - single with child spaces
POST   /categories                      - create (admin)
PATCH  /categories/:id                  - update
DELETE /categories/:id                  - delete
```

#### Posts

```
GET    /spaces/:id/posts                - list posts in space
GET    /posts/:id                       - single post
POST   /spaces/:id/posts                - create post
PATCH  /posts/:id                       - update
DELETE /posts/:id                       - trash
POST   /posts/:id/close                 - close/lock (Level 3+)
POST   /posts/:id/pin                   - sticky (Level 3+)
POST   /posts/:id/move                  - move to different space (Level 3+)
```

#### Replies

```
GET    /posts/:id/replies               - list (flat or threaded)
POST   /posts/:id/replies               - create
PATCH  /replies/:id                     - update
DELETE /replies/:id                     - trash
POST   /replies/:id/accept             - mark accepted (Q&A)
```

#### Voting

```
POST   /posts/:id/vote                  - upvote/downvote { value: 1 | -1 }
DELETE /posts/:id/vote                  - remove vote
POST   /replies/:id/vote               - upvote/downvote
DELETE /replies/:id/vote               - remove vote
```

#### Users & Profiles

```
GET    /users/me                        - current user profile + stats
GET    /users/:id                       - public profile
PATCH  /users/me                        - update own profile
GET    /users/:id/posts                 - user's posts
GET    /users/:id/replies               - user's replies
GET    /users/:id/badges                - earned badges
GET    /users/:id/activity              - activity feed
```

#### Notifications

```
GET    /notifications                   - current user's notifications
PATCH  /notifications/:id              - mark read
POST   /notifications/mark-all-read    - bulk mark read
GET    /notifications/unread-count      - lightweight poll endpoint
```

#### Subscriptions

```
GET    /subscriptions                   - current user's subscriptions
POST   /subscriptions                   - subscribe
DELETE /subscriptions/:id              - unsubscribe
```

#### Search

```
GET    /search?q=term&type=post|reply|space  - unified search
```

#### Moderation

```
GET    /moderation/queue                - pending/flagged (Level 5+)
POST   /moderation/approve/:type/:id   - approve content
POST   /moderation/spam/:type/:id      - mark spam
GET    /moderation/flags                - flagged content
POST   /flags                          - flag content (Level 1+)
```

#### Tags

```
GET    /tags                            - post tags
GET    /space-tags                      - space discovery tags
```

#### Real-Time

```
GET    /updates?since=timestamp&scope=space|post|global  - polling endpoint
```

#### Leaderboards

```
GET    /leaderboards?scope=global|space&period=weekly|monthly|alltime
```

#### Ideas-Specific

```
PATCH  /posts/:id/idea-status           - update idea status (moderator+)
GET    /spaces/:id/roadmap              - ideas grouped by status
```

### API Patterns

- **Cursor-based pagination**: `?cursor=eyJpZCI6MTUwfQ&limit=20&sort=latest`
- **Sparse fieldsets**: `?_fields=id,title,vote_score,reply_count,author`
- **Embedded relations**: `?_embed=author,last_reply,tags`
- **Bulk operations** (Pro): `POST /bulk { "action": "move", "post_ids": [1,2,3], "space_id": 10 }`

### Authentication

- Web frontend: Cookie + X-WP-Nonce header
- Mobile apps: Application Passwords (WP 5.6+)
- Third-party: OAuth 2.0 (Pro extension)

### Rate Limiting

```
POST endpoints: 30/minute (Level 0: 5/minute)
GET endpoints: 120/minute
Vote endpoints: 60/minute
Search: 20/minute

Stored in object cache, not DB.
Response headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset
Exceeded: HTTP 429 with Retry-After header
```

---

## Section 4b: Capabilities & Permission System

### Three-Layer Permission Architecture

```
Layer 1: WordPress Capabilities (global)
  "What can this user do across the entire plugin?"

Layer 2: Space Roles (per-space)
  "What role does this user have in THIS space?"

Layer 3: Trust Level Gates (earned)
  "Has this user earned the right to do this?"
```

### Permission Resolution Flow

```
1. Is user globally banned? -> DENY
2. Does user have the WP capability? -> NO: DENY
3. Check access rules for this space:
   a. Does user match any membership rule? -> Apply grants
   b. Does user match any role/cap rule? -> Apply grants
   c. Does user match trust_level rule? -> Apply grants
   d. Is space public? -> Basic read access
   e. Is space private + no rule match? -> DENY
4. Check space role (Layer 2)
5. Check trust level gates (Layer 3)
6. Check rate limits
7. ALLOW
```

### Layer 1: WordPress Capabilities

```
Content:
  jetonomy_read, jetonomy_create_posts, jetonomy_create_replies,
  jetonomy_edit_own_posts, jetonomy_edit_others_posts,
  jetonomy_delete_own_posts, jetonomy_delete_others_posts,
  jetonomy_upload_media

Voting:
  jetonomy_vote, jetonomy_flag

Space:
  jetonomy_create_spaces, jetonomy_manage_spaces, jetonomy_join_spaces

Moderation:
  jetonomy_moderate, jetonomy_manage_users, jetonomy_move_posts,
  jetonomy_close_posts, jetonomy_pin_posts

Admin:
  jetonomy_manage_settings, jetonomy_manage_categories,
  jetonomy_manage_badges, jetonomy_view_analytics,
  jetonomy_manage_extensions
```

### Default WP Role Mapping

```
Subscriber:    read, create_posts, create_replies, edit_own_posts,
               delete_own_posts, vote, flag, join_spaces
Contributor:   + upload_media
Author:        + create_spaces
Editor:        + edit_others_posts, delete_others_posts, moderate,
               move_posts, close_posts, pin_posts
Administrator: All capabilities
```

### Layer 2: Space Roles

```
viewer:    read only (announcements, pre-approval)
member:    read + post + reply + vote + flag
moderator: + edit/delete others, close, pin, move, approve members, manage tags, silence
admin:     + edit space, manage roles, create sub-spaces, delete space, invite, set join policy
```

Space permission overrides stored in `wp_jt_spaces.settings` JSON:

```json
{
  "permissions": {
    "who_can_post": "member",
    "who_can_reply": "viewer",
    "who_can_vote": "member",
    "who_can_see_members": "member",
    "require_approval": false,
    "min_trust_level_to_post": 0,
    "allowed_post_types": ["topic", "question"],
    "allow_anonymous_read": true
  }
}
```

### Sub-Space Permission Inheritance

Sub-spaces inherit the HIGHER of:
- User's explicit sub-space role
- User's parent space role

Space admin automatically has admin in all sub-spaces. Users cannot be demoted below parent role. Global WP admin overrides everything.

### Bans & Restrictions Table

```sql
CREATE TABLE wp_jt_restrictions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('global_ban','space_ban','silence','post_restrict') NOT NULL,
    space_id BIGINT UNSIGNED NULL,
    reason TEXT,
    issued_by BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_user_type (user_id, type, space_id),
    INDEX idx_expires (expires_at)
);
```

---

## Section 4c: Membership Plugin Integration Layer

### Adapter Interface

```php
interface Jetonomy_Membership_Adapter {
    public function is_active(): bool;
    public function get_user_levels(int $user_id): array;
    public function user_has_level(int $user_id, string $level_id): bool;
    public function get_all_levels(): array;
    public function get_level_label(string $level_id): string;
    public function register_hooks(): void;
}
```

### Access Rules Table

```sql
CREATE TABLE wp_jt_access_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    rule_type ENUM('membership','role','capability','trust_level','logged_in','everyone') NOT NULL,
    rule_value VARCHAR(255) NULL,
    grants ENUM('read','participate','full') NOT NULL DEFAULT 'participate',
    space_role ENUM('viewer','member','moderator') DEFAULT 'member',
    priority INT DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_space_priority (space_id, priority DESC)
);
```

### How Access Rules Stack

Rules checked top-down by priority. First matching rule wins. No match + public space = default read access. No match + private space = DENY.

### Membership Event Hooks

- **Activated**: Auto-join user to matching spaces, set role, send welcome notification, log activity
- **Deactivated/Expired**: Downgrade to "viewer" (don't remove), posts remain visible, grace period option (7 days read-only)
- **Upgraded**: Unlock additional spaces, upgrade roles, send notification

### Shipped Adapters

| Adapter | Included In |
|---|---|
| WP Roles & Capabilities | Core (free) |
| Trust Level gates | Core (free) |
| MemberPress | Core (free) |
| Paid Memberships Pro | Core (free) |
| WooCommerce Memberships | Pro |
| Restrict Content Pro | Pro |
| Ultimate Member | Pro |
| LearnDash | Pro |
| LifterLMS | Pro |
| BuddyBoss | Pro |
| Custom (hook-based) | Core (free) |

### Use Cases

```
Freemium Community:
  "General Discussion"     -> access: everyone (free)
  "SEO Tips"               -> access: logged_in
  "Advanced Strategies"    -> access: membership = "pro" ($29/mo)
  "1-on-1 Mastermind"      -> access: membership = "premium" ($99/mo)

WooCommerce Product Support:
  "ThemeX Support"         -> access: woo_product = "theme-x"
  "PluginY Support"        -> access: woo_product = "plugin-y"
  "Pre-Sales Questions"    -> access: everyone

LearnDash Course Integration:
  "Module 1 Discussion"    -> access: learndash_course = "101"
  "Alumni Network"         -> access: learndash_course_complete = "101"
```

---

## Section 5: Frontend Architecture

### Interactivity API + Preact Island Strategy

```
Page structure:
- WordPress Theme (header, nav, footer, sidebar) - rendered by theme
- Jetonomy Content Area (data-wp-interactive="jetonomy")
  - Interactivity API blocks: space list, post list, reply list, sidebar, vote buttons,
    notifications, user cards, tag filters, infinite scroll, breadcrumbs, search
  - Preact Islands (lazy-loaded): reply composer (Tiptap), post editor, @mention
    autocomplete, media upload, moderation dashboard, admin settings, idea kanban,
    emoji picker, advanced search
```

### What Uses IA vs Preact Islands

**Interactivity API** (server-rendered, hydrated, ~10KB):
Read-heavy, state-light interactions — space listing, post listing, reply display, vote buttons, subscribe toggles, notification bell, user cards, tag filtering, infinite scroll, read/unread indicators, client-side navigation.

**Preact Islands** (client-rendered, lazy-loaded):
Complex interactions needing component composition or third-party libraries — reply/post editor (Tiptap/ProseMirror), @mention autocomplete, media upload with drag-drop, moderation dashboard, admin settings, idea kanban, emoji picker, advanced search filters.

### Shared Store

One global store shared between IA blocks AND Preact islands via `store('jetonomy', { state, actions })`. Both read/write to the same reactive state. Preact islands communicate with IA through Preact Signals (same reactivity system).

### URL Structure

```
/community/                              -> Space directory
/community/category/programming/         -> Category view
/community/s/python-developers/          -> Space view
/community/s/python-developers/t/how-to-use-decorators/  -> Single post
/community/s/python-developers/members/  -> Member directory
/community/s/python-developers/roadmap/  -> Ideas roadmap
/community/u/johndoe/                    -> User profile
/community/u/johndoe/posts/              -> User's posts
/community/u/johndoe/badges/             -> User's badges
/community/notifications/                -> Notification center
/community/search/?q=decorators          -> Search results
/community/leaderboard/                  -> Global leaderboard
/community/mod/                          -> Moderation dashboard
```

Implementation: Single WP page with `[jetonomy]` shortcode OR custom WP block. Routing via rewrite rules -> PHP router -> template. Client-side navigation via IA router for subsequent loads.

### Progressive Loading

1. **First paint**: PHP renders full HTML, IA state serialized as JSON. No JS needed for first paint.
2. **Hydration**: IA runtime (~10KB) loads async, hydrates interactivity. Preact islands NOT loaded.
3. **On demand**: Reply composer Preact island lazy-loads (~30KB) only when user clicks "Reply".
4. **Navigation**: IA router intercepts clicks, fetches new HTML via AJAX, merges changed content region. No full page reload.

### CSS Architecture

CSS Layers + Custom Properties. No Tailwind/Bootstrap dependency. **Theme-adaptive by default.**

Design philosophy: "Invisible UI" — inherits theme fonts/colors via `theme.json` CSS custom properties, falls back to clean neutral defaults. Like Notion/Linear — content-first, minimal chrome.

```css
@layer jetonomy-base {
  /* Inherits from WP theme.json, falls back to own defaults */
  --jt-font-body: var(--wp--preset--font-family--body, system-ui, sans-serif);
  --jt-font-heading: var(--wp--preset--font-family--heading, var(--jt-font-body));
  --jt-accent: var(--wp--preset--color--primary, #3B82F6);
  --jt-text: var(--wp--preset--color--contrast, #1a1a1a);
  --jt-bg: var(--wp--preset--color--base, #ffffff);
  --jt-border: var(--wp--preset--color--outline, rgba(0,0,0,0.08));
  --jt-radius: var(--wp--custom--border-radius, 8px);
}
@layer jetonomy-components { /* .jt-post-card, .jt-reply, etc. */ }
@layer jetonomy-utilities { /* .jt-sr-only, .jt-truncate, etc. */ }
```

Admin can override: inherit theme fonts (on/off), inherit theme colors (on/off), custom accent picker, density (compact/comfortable/spacious), border radius slider.

Total CSS: ~15-20KB (compressed ~4KB). Theme CSS can override without !important.

### Mobile-First

```
Mobile  (< 640px):  single column, bottom nav, pull-to-refresh, swipe gestures, FAB
Tablet  (640-1024px): two columns, sidebar collapses
Desktop (> 1024px): full layout, sidebar visible
PWA-ready: manifest.json, service worker for offline read
```

---

## Section 6: Extension & Pro Architecture

### Plugin Structure

```
wp-content/plugins/
  jetonomy/              <- FREE CORE (wordpress.org)
    includes/
      core/              <- engine (posts, replies, votes, spaces)
      api/               <- REST API controllers
      db/                <- schema, migrations, queries
      modules/           <- community type behaviors (forum, qa, ideas, feed)
      trust/             <- trust levels, reputation, badges
      permissions/       <- capabilities, space roles, access rules
      adapters/          <- adapter interfaces + free adapters
      notifications/     <- web + basic email
      moderation/        <- flags, queue, basic tools
      search/            <- FULLTEXT search
    blocks/              <- Gutenberg blocks
    assets/              <- JS (IA modules), Preact islands, CSS
    templates/           <- PHP render templates
    languages/           <- i18n

  jetonomy-pro/          <- PRO (licensed, separate plugin)
    includes/
      license/           <- license validation
      extensions/        <- Pro feature modules
        private-messaging/
        advanced-moderation/
        analytics/
        custom-fields/
        reactions/
        polls/
        announcements/
        seo-pro/
        import-export/
        custom-badges/
        white-label/
      adapters/          <- Pro adapters (WooCommerce, RCP, UM, LearnDash, etc.)
      integrations/      <- Slack bridge, Discord bridge, Zapier webhooks
```

### Extension Registration

Every Pro extension self-registers by extending `Jetonomy_Extension` with `meta()`, `boot()`, `activate()`, `deactivate()` methods. Core discovers and manages them.

### Core Hook System

Comprehensive hooks at every meaningful point:
- Content lifecycle (before/after create, update, delete for posts and replies)
- Voting (before/after vote, score changed)
- Trust & reputation (reputation changed, trust level changed, badge earned)
- Space events (user joined/left, role changed, space created/archived)
- Access & permissions (check_access, check_permission, membership_access_resolved)
- Moderation (content flagged, approved, spam detected, user banned/silenced)
- Notifications (before/after send, channels filter)
- Search (index, query, results filters)
- Display/template (post card meta, reply actions, profile tabs, sidebar, before/after content)

### Free vs Pro Split

**Free core** includes: All 4 community types, unlimited spaces/categories, rich editor, @mentions, trust levels, reputation, 20 default badges, leaderboards, user profiles, WP role mapping, space roles, access rules, MemberPress + PMPro adapters, web notifications, basic email (wp_mail), flag/report + mod queue, ban/silence, MySQL FULLTEXT search, polling real-time, full REST API, 3 color schemes, CSS custom properties.

**Pro** adds: File attachments, polls, emoji reactions, custom fields, custom badge builder, WooCommerce/RCP/UM/LearnDash adapters, email digest, push notifications (PWA), ESP adapters, auto-moderation rules, AI spam, bulk mod tools, Meilisearch/ES/Algolia, Mercure/Pusher/Ably, typing indicators, online presence, private messaging, Slack/Discord bridge, Zapier webhooks, analytics dashboard, import from bbPress/wpForo/Discourse, white-label, SEO Pro, theme builder, dark mode.

### Pro Licensing

```
Starter ($99/yr):  1 site, core Pro extensions
Growth ($199/yr):  5 sites, all Pro extensions
Agency ($399/yr):  unlimited sites, all Pro + white-label + priority support
Lifetime ($599):   unlimited sites, all Pro, lifetime updates
```

### Third-Party Extension API

Third-party developers can build extensions using the same `Jetonomy_Extension` base class and hook system as Pro. Same API surface, same registration pattern.

---

## Section 7: Migration & Import Strategy

### Supported Import Sources

**Priority 1 (Free core):** bbPress (100K+ installs), wpForo (20K+), Asgaros (10K+)

**Priority 2 (Pro):** Discourse, phpBB, vBulletin, XenForo, Simple Machines, Simple:Press

**Priority 3 (Pro):** Circle.so, Mighty Networks, BuddyBoss (note: BuddyBoss is essentially bbPress bundled with BuddyPress)

### Migration Architecture

Source Adapters → Transformer Pipeline (normalize, map users, map spaces, convert HTML, remap IDs) → Importer (validate, batch insert, re-index, recount)

Migration State tracking: progress (X of Y records), ID mapping table (old_id → new_id), error log with skip/retry, resume from interruption, dry-run mode.

### Data Mapping

```
bbPress → Jetonomy:
  wp_posts (type=forum)    → wp_jt_spaces
  wp_posts (type=topic)    → wp_jt_posts
  wp_posts (type=reply)    → wp_jt_replies
  wp_postmeta              → denormalized columns + JSON settings
  bbpress roles            → wp_jt_space_members

wpForo → Jetonomy:
  wp_wpforo_forums         → wp_jt_categories + wp_jt_spaces
  wp_wpforo_topics         → wp_jt_posts
  wp_wpforo_posts          → wp_jt_replies
  wp_wpforo_profiles       → wp_jt_user_profiles
  wp_wpforo_likes          → wp_jt_votes
  wp_wpforo_subscribes     → wp_jt_subscriptions

Discourse → Jetonomy:
  categories               → wp_jt_categories
  topics                   → wp_jt_posts
  posts                    → wp_jt_replies
  users                    → wp_users + wp_jt_user_profiles
  trust_levels             → wp_jt_user_profiles.trust_level
  badges                   → wp_jt_user_badges
  likes                    → wp_jt_votes
```

### Migration Temporary Table

```sql
CREATE TABLE wp_jt_migration_map (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL,
    source_type VARCHAR(50) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','imported','skipped','error') DEFAULT 'pending',
    error_message TEXT NULL,
    UNIQUE KEY uniq_source (source, source_type, source_id),
    INDEX idx_target (target_type, target_id)
);
```

Dropped after migration completion.

### Migration UX

Admin → Jetonomy → Settings → Import:
1. Select source → 2. Auto-detect data ("Found: 12 forums, 3,847 topics...") → 3. Map source forums to Jetonomy categories/spaces → 4. Options (import users?, votes?, redirects?, dry run?) → 5. Dry run (validate, report errors) → 6. Execute (batch import with progress bar via WP-Cron) → 7. Post-import (recount, rebuild search index) → 8. URL redirects (.htaccess rules for old → new URLs)

---

## Section 8: Email & Notification System

### Notification Types

**Immediate:** reply to your post, reply to subscribed thread, @mention, accepted answer, idea status change, badge earned, vote batch ("5 people upvoted"), mod action, join request result, private message (Pro)

**Batched (digest):** new posts in followed spaces, trending posts, weekly leaderboard changes, new members, space recommendations

**System:** welcome email, trust level promotion, membership access change, space announcement, admin broadcast

### Notification Preferences (per-user)

Stored in `wp_jt_user_profiles.settings` JSON. Per-type toggle for web and email (immediate/digest/none). Digest frequency: daily/weekly. Quiet hours with timezone. Email format: html/plain.

### Email Template System

Templates in `templates/emails/`: base layout, per-notification-type templates, digest templates, welcome, trust promotion, admin broadcast. Override-able via theme directory. Responsive HTML email. Plain-text fallback. Preview in admin.

### Digest Engine

WP-Cron scheduled. Per user: collect unsent notifications, group by type, rank by engagement, cap at 10-15 items, include one "discover" item, render with user locale, queue via email adapter, mark as emailed.

Schedule options: real-time (rate-limited), hourly batch, daily digest (default), weekly digest, none (web-only).

### Email Adapter Interface

```php
interface Jetonomy_Email_Adapter {
    public function is_active(): bool;
    public function send(string $to, string $subject, string $html, string $plain): bool;
    public function send_batch(array $messages): array;
    public function get_stats(): array;
    public function register_hooks(): void;
}
```

Free: wp_mail adapter. Pro: SendGrid, Mailgun, Amazon SES, Postmark.

### Unsubscribe Handling

Every email: one-click unsubscribe (RFC 8058), List-Unsubscribe header, manage preferences link, per-thread unsubscribe option.

---

## Section 9: SEO Strategy

### Server-Side Rendering

Every page fully rendered HTML via WP Interactivity API SSR. Crawlers see complete content without JS. Title tags, meta descriptions, canonical URLs all PHP-generated.

### URL Structure

```
/community/                                          → Forum index
/community/category/programming/                     → Category
/community/s/python-developers/                      → Space
/community/s/python-developers/t/how-to-decorators/  → Single post
/community/u/johndoe/                                → User profile (noindex)
/community/search/?q=decorators                      → Search (noindex)
/community/tags/machine-learning/                    → Tag archive
```

Clean slugs, breadcrumbs in HTML + structured data, canonical URLs, rel next/prev pagination, configurable base slug.

### Structured Data (Schema.org)

- **Forum posts**: `DiscussionForumPosting` with interaction statistics and comments
- **Q&A posts**: `QAPage` with `Question` + `acceptedAnswer` (enables Google rich results)
- **Breadcrumbs**: `BreadcrumbList` on every page

### XML Sitemap

Hook into WP core sitemap (wp_sitemaps, WP 5.5+). Custom providers for spaces, posts, categories. Respect visibility. Priority by engagement. Lastmod from last_reply_at. 2,000 URLs per file.

### SEO Pro (Pro Extension)

Per-space meta templates, Open Graph / Twitter Cards, internal linking suggestions, noindex controls, redirect manager, Yoast/RankMath integration, Search Console integration, hreflang for multilingual.

---

## Section 10: Admin Dashboard

### Dashboard Layout

```
Jetonomy Admin (WP Sidebar)
├── Dashboard          → overview stats, quick actions
├── Spaces             → CRUD spaces, categories, ordering
├── Moderation         → queue, flags, spam, bans
├── Users              → profiles, trust levels, reputation
├── Badges             → view/manage (custom builder in Pro)
├── Analytics (Pro)    → engagement metrics, graphs
├── Import             → migration wizard
├── Extensions         → manage Pro extensions, adapters
└── Settings           → general, permissions, email, SEO, real-time, appearance, integrations
```

### Settings Sections

- **General**: base slug, default space type, registration/guest settings, posts/replies per page, thread display/depth
- **Permissions**: trust level thresholds, rate limits, role mapping, approval toggle, trust minimums for links/media
- **Email**: adapter selection, digest settings, template preview, from name/email, test button
- **SEO**: meta templates, sitemap settings, noindex toggles, schema markup, canonical, breadcrumbs
- **Real-time**: adapter selection, polling interval, push credentials (Pro), presence/typing toggles (Pro)
- **Appearance**: color scheme, CSS overrides, layout options, density, dark mode (Pro), custom CSS
- **Integrations**: active adapters, membership config, spam protection, media storage (Pro), webhooks

### Moderation Panel

Queue with tabs (Pending/Flagged/Spam/Banned). Bulk actions. Per-item preview with user info and flag reasons. Quick actions. Filter by space/type/date/flags. Auto-refresh.

User management: search/filter, trust level + reputation + post count + flags view, promote/demote/ban/silence/reset actions, full activity history.

---

## Section 11: Performance & Caching Strategy

### Caching Layers

**Layer 1 — Object Cache (Redis/Memcached):**
Individual objects with invalidation. Spaces, posts, replies (until modified). User profiles (5 min). Permissions (60s). Unread counts (30s). Notification count (15s). Rate limits (60s).

Invalidation: new reply → invalidate parent post + space list + author profile. Vote → invalidate target. New post → invalidate space list + category. Trust change → invalidate all user permission caches.

**Layer 2 — Query Result Cache:**
Space topic listing page 1 (30s). Trending posts (120s). Leaderboard (via cron). Tag listing (300s). Search results (60s).

**Layer 3 — Fragment Cache:**
User profile card (5 min). Space sidebar (2 min). Category tree (10 min). Badge showcase (10 min).

**Layer 4 — Edge Cache (CDN):**
Static assets (1 year, hashed). Public listings logged-out (60s + stale-while-revalidate). Single post logged-out (30s). API GET public (10s). Authenticated (no-cache).

### Query Optimization

1. Covering indexes for topic listings
2. Denormalized counters (no COUNT queries)
3. Cursor pagination (constant time)
4. Batch loading (avoid N+1)
5. Lazy aggregation (view counts via Redis INCR, flush to MySQL every 5 min)
6. Connection pooling for high-traffic

### Background Processing (WP-Cron)

Leaderboard: 6h. Digest emails: daily. Trust evaluation: 12h. Badge evaluation: 6h. View count flush: 5 min. Restriction cleanup: hourly. Search index sync: 15 min. Activity log pruning: weekly (90 days). Stale notification cleanup: weekly (30 days).

High-traffic: system cron, Action Scheduler for queues, WP-CLI commands for manual triggers.

### Scaling Tiers

**Small** (<10K posts, shared hosting): MySQL FULLTEXT, 15s polling, WP-Cron, no object cache needed. ~50-100 concurrent users.

**Medium** (10K-500K posts, VPS): Redis required, Meilisearch optional, 10s polling, system cron, CDN. ~500-2,000 concurrent users.

**Large** (500K-5M posts, dedicated/cloud): Redis + Meilisearch/ES required, Mercure/Pusher, MySQL read replica, edge caching, Action Scheduler, horizontal scaling. ~5,000-20,000 concurrent users.

---

## Section 12: Testing Strategy

### Test Pyramid

- **Unit Tests** (~500, PHPUnit): Models, trust evaluator, reputation calculator, badge evaluator, permission checkers, helpers (slug, cursor, sanitizer, rate limiter)
- **Integration Tests** (~200, PHPUnit + WP test suite): REST API endpoints, database schema/migration/performance, cross-layer permissions, import adapters, hook firing
- **E2E Tests** (~20 journeys, Playwright): forum browsing, post creation, Q&A workflow, ideas workflow, voting, trust levels, moderation, membership gating, notifications, mobile, migration

### CI/CD Pipeline (GitHub Actions)

```
lint: PHPCS (WP standards) + ESLint + Stylelint
unit-tests: PHPUnit, matrix PHP 8.1/8.2/8.3/8.4
integration-tests: wp-env, matrix WP 6.7/6.8/6.9 x PHP 8.1/8.3
e2e-tests: wp-env + Playwright (critical journeys on PR, full on main merge)
build: @wordpress/scripts, block compilation, bundle size check
release: semver, changelog, .zip for wordpress.org + Pro distribution
```

### WP-CLI Test Commands

```
wp jetonomy test --seed        → create test data
wp jetonomy test --clean       → remove test data
wp jetonomy test --benchmark   → performance benchmarks
wp jetonomy test --permissions → verify all permission combinations
```

---

## Spec Review Fixes (Post-Review Addendum)

### Fix C1: Missing `wp_jt_flags` table

```sql
CREATE TABLE wp_jt_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id BIGINT UNSIGNED NOT NULL,
    object_type ENUM('post','reply','user') NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    reason ENUM('spam','offensive','off_topic','harassment','other') NOT NULL,
    description TEXT NULL,
    status ENUM('pending','valid','dismissed') DEFAULT 'pending',
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_status (status, created_at DESC),
    INDEX idx_object (object_type, object_id),
    INDEX idx_reporter (reporter_id)
);
```

### Fix C2: Space role ENUM mismatch

`wp_jt_access_rules.space_role` updated to: `ENUM('viewer','member','moderator','admin')` — now matches `wp_jt_space_members.role`.

### Fix C3: Schema migration strategy

```
Schema versioning via wp_options:
  'jetonomy_db_version' = '1.0.0'

On plugin activation and admin_init:
  1. Read current DB version from options
  2. Compare against plugin's JETONOMY_DB_VERSION constant
  3. If lower, run sequential migration files:
     db/migrations/1.0.0.php → 1.1.0.php → 1.2.0.php
  4. Each migration uses dbDelta() for safe ALTER TABLE
  5. Update DB version in options after each migration
  6. Log migration results to wp_jt_activity_log
```

### Fix C4: Content format specification

`content` column stores **sanitized HTML** (output of Tiptap editor, processed through `wp_kses_post()`). `content_plain` stores `wp_strip_all_tags()` output for FULLTEXT search and email excerpts.

REST API accepts: `content` as HTML string. Returns: `content` (HTML) and `content_plain` (text). Mobile clients can request `?_fields=content_plain` for lightweight responses.

### Fix C5: Missing revision history table

```sql
CREATE TABLE wp_jt_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    object_type ENUM('post','reply') NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    content LONGTEXT NOT NULL,
    title VARCHAR(255) NULL,
    edit_summary VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_object (object_type, object_id, created_at DESC)
);
```

REST endpoint: `GET /posts/:id/revisions` and `GET /replies/:id/revisions`. Revert endpoint: `POST /revisions/:id/revert` (Level 3+).

### Fix I2: Discourse migration mapping corrected

```
Discourse → Jetonomy (corrected):
  categories           → wp_jt_categories + wp_jt_spaces (each category becomes a Space)
  sub-categories       → wp_jt_spaces with parent_id (sub-spaces)
  topics               → wp_jt_posts
  posts                → wp_jt_replies
```

### Fix I5: GDPR compliance

Implement WordPress privacy hooks:
- `wp_privacy_personal_data_exporters`: export all user data from Jetonomy tables
- `wp_privacy_personal_data_erasers`: anonymize or delete user data across all 16+ tables
- On WP user deletion: anonymize posts/replies (set author to "Deleted User"), remove profile, votes, subscriptions, notifications, read status, space memberships

### Fix I6: Polls clarification

Polls are a **Pro feature only**. Remove "Can create polls" from Trust Level 1 in free core. Trust Level 1 gains: "Can use @mentions" instead.

### Fix I7: Missing join requests table

```sql
CREATE TABLE wp_jt_join_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    message TEXT NULL,
    status ENUM('pending','approved','denied') DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_request (space_id, user_id, status),
    INDEX idx_space_pending (space_id, status, created_at)
);
```

### Fix I8: Votes value constraint

`wp_jt_votes.value` changed to: `ENUM(1, -1)` or `TINYINT NOT NULL CHECK (value IN (1, -1))`.

### Fix I9: Uninstall behavior

- **Deactivation**: disable all hooks, cron jobs, REST routes. Data preserved.
- **Uninstall** (via WordPress uninstall): show confirmation dialog with two options:
  - "Keep data" (default): remove plugin files only
  - "Delete everything": drop all `wp_jt_*` tables, remove options, remove capabilities, clean user meta

### Fix I10: Multisite note

v1.0 targets single-site installations only. Tables are per-site (use `$wpdb->prefix`). Multisite support (network activation, shared communities across subsites) is a v2.0 feature. The schema is designed to be multisite-compatible via the prefix pattern.

---

## Release Phasing

### v1.0 (MVP — ship first)
- Forum + Q&A community types
- Custom tables, REST API, cursor pagination
- Trust levels (0-5) + reputation system
- 3-layer permissions (WP caps + space roles + trust gates)
- Categories + Spaces + Sub-spaces
- Flat replies (threaded replies in v1.1)
- WP Interactivity API frontend + Preact editor island
- Voting (up/down on posts and replies)
- Basic notifications (web + wp_mail)
- MemberPress + PMPro adapters
- Polling real-time
- MySQL FULLTEXT search
- Basic moderation (flags, queue, ban/silence)
- SEO (SSR, schema markup, sitemaps)
- Admin settings panel
- bbPress + wpForo import (free)
- Theme-adaptive CSS (inherit from theme.json)

### v1.1
- Threaded replies (add `depth` column, tree rendering)
- Badges system (tables, evaluator, display)
- Leaderboards (materialized table, cron)
- Email digest engine
- More membership adapters (WooCommerce, RCP)
- Discourse import

### v1.2
- Ideas module (kanban, status tracking, roadmap)
- Emoji reactions (Pro)
- Polls (Pro)
- Custom fields (Pro)
- Private messaging (Pro)

### v2.0
- Social Feed module
- Real-time push (Mercure/Pusher/Ably)
- Advanced search (Meilisearch/Algolia)
- Slack/Discord bridge
- Mobile PWA
- Multisite support
- Third-party extension marketplace

---

## Competitive Positioning

No current WordPress forum plugin has:
- REST API
- Modern JS frontend
- Real-time capabilities
- Interactivity API integration
- Universal membership adapter
- Trust level system
- Cursor-based pagination

Jetonomy would be the first WordPress forum plugin architecturally competitive with standalone solutions like Discourse, while remaining 100% WordPress-native.
