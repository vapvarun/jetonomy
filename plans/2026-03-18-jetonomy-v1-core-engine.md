# Jetonomy v1.0 — Core Engine Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundational database layer, model classes, permission engine, and trust system for the Jetonomy forum plugin — everything the REST API and frontend depend on.

**Architecture:** Custom MySQL tables via `dbDelta()`, PHP model classes with query builders, 3-layer permission engine (WP capabilities + space roles + trust level gates), reputation/trust level evaluator. All data access goes through model classes — no raw SQL outside the `db/` layer.

**Tech Stack:** PHP 8.1+, WordPress 6.7+, MySQL 5.7+ / MariaDB 10.3+, PHPUnit with WP test suite, `@wordpress/scripts` for build tooling.

**Spec Reference:** `docs/superpowers/specs/2026-03-17-jetonomy-forum-plugin-design.md`

---

## Plan Scope

This plan covers **Subsystem 1: Core Engine** only. Subsequent plans:
- Plan 2: REST API Layer
- Plan 3: Frontend (Interactivity API + Preact Islands)
- Plan 4: Integrations (Membership Adapters, Import, Email)

## v1.0 File Structure (Full Plugin)

```
wp-content/plugins/jetonomy/
├── jetonomy.php                          # Main plugin file, bootstrap
├── uninstall.php                         # Clean uninstall handler
├── composer.json                         # PHP dependencies (dev: phpunit)
├── package.json                          # JS build (@wordpress/scripts)
├── phpunit.xml.dist                      # PHPUnit configuration
├── .phpcs.xml.dist                       # PHPCS WordPress standards
│
├── includes/
│   ├── class-jetonomy.php                # Main plugin class, singleton
│   ├── functions.php                     # Global helper functions
│   │
│   ├── db/
│   │   ├── class-schema.php             # Table definitions, dbDelta
│   │   ├── class-migrator.php           # Version-based migration runner
│   │   └── migrations/                  # Versioned migration files
│   │       └── class-migration-1-0-0.php
│   │
│   ├── models/
│   │   ├── class-model.php              # Abstract base model
│   │   ├── class-category.php           # Category CRUD + queries
│   │   ├── class-space.php              # Space CRUD + hierarchy
│   │   ├── class-post.php               # Post CRUD + type handling
│   │   ├── class-reply.php              # Reply CRUD + flat listing
│   │   ├── class-vote.php               # Vote CRUD + score updates
│   │   ├── class-user-profile.php       # Profile CRUD + stats
│   │   ├── class-notification.php       # Notification CRUD
│   │   ├── class-subscription.php       # Subscription CRUD
│   │   ├── class-flag.php               # Flag CRUD
│   │   ├── class-tag.php                # Tag + post_tags CRUD
│   │   ├── class-space-member.php       # Membership CRUD
│   │   ├── class-restriction.php        # Bans/silences CRUD
│   │   ├── class-revision.php           # Edit revision CRUD
│   │   ├── class-read-status.php        # Read tracking
│   │   ├── class-activity-log.php       # Activity logging
│   │   └── class-access-rule.php        # Access rule CRUD
│   │
│   ├── permissions/
│   │   ├── class-capabilities.php       # Register WP caps, role mapping
│   │   ├── class-permission-engine.php  # 3-layer permission resolver
│   │   ├── class-space-role-checker.php # Per-space role checks
│   │   ├── class-trust-gate.php         # Trust level gate checks
│   │   └── class-rate-limiter.php       # Rate limit enforcement
│   │
│   ├── trust/
│   │   ├── class-reputation.php         # Reputation calculator
│   │   ├── class-trust-evaluator.php    # Trust level evaluation
│   │   └── class-trust-levels.php       # Level definitions + thresholds
│   │
│   ├── modules/
│   │   ├── class-module.php             # Abstract module base
│   │   ├── class-forum-module.php       # Forum behavior (sort by latest)
│   │   └── class-qa-module.php          # Q&A behavior (voting, accepted)
│   │
│   ├── notifications/
│   │   ├── class-notifier.php           # Dispatch notifications
│   │   └── class-email-sender.php       # wp_mail adapter
│   │
│   ├── moderation/
│   │   ├── class-mod-queue.php          # Moderation queue queries
│   │   └── class-spam-checker.php       # Basic spam detection
│   │
│   ├── search/
│   │   └── class-fulltext-search.php    # MySQL FULLTEXT adapter
│   │
│   └── adapters/
│       ├── interface-membership.php     # Membership adapter interface
│       ├── interface-search.php         # Search adapter interface
│       ├── interface-realtime.php       # Real-time adapter interface
│       ├── interface-email.php          # Email adapter interface
│       ├── class-wp-roles-adapter.php   # Default WP roles adapter
│       └── class-polling-adapter.php    # Default polling adapter
│
├── tests/
│   ├── bootstrap.php                    # WP test suite bootstrap
│   ├── unit/
│   │   ├── models/
│   │   │   ├── CategoryTest.php
│   │   │   ├── SpaceTest.php
│   │   │   ├── PostTest.php
│   │   │   ├── ReplyTest.php
│   │   │   ├── VoteTest.php
│   │   │   └── UserProfileTest.php
│   │   ├── permissions/
│   │   │   ├── CapabilitiesTest.php
│   │   │   ├── PermissionEngineTest.php
│   │   │   ├── SpaceRoleCheckerTest.php
│   │   │   ├── TrustGateTest.php
│   │   │   └── RateLimiterTest.php
│   │   └── trust/
│   │       ├── ReputationTest.php
│   │       └── TrustEvaluatorTest.php
│   └── integration/
│       ├── db/
│       │   ├── SchemaTest.php
│       │   └── MigratorTest.php
│       └── permissions/
│           └── FullPermissionFlowTest.php
│
└── languages/
    └── jetonomy.pot                     # i18n template
```

---

## Chunk 1: Plugin Bootstrap & Database Schema

### Task 1: Plugin Bootstrap File

**Files:**
- Create: `jetonomy.php`
- Create: `includes/class-jetonomy.php`
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`

- [ ] **Step 1: Create main plugin file**

```php
<?php
/**
 * Plugin Name: Jetonomy
 * Plugin URI:  https://jetonomy.com
 * Description: Next-gen discussion platform for WordPress — forums, Q&A, and more.
 * Version:     1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author:      Jetonomy
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jetonomy
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'JETONOMY_VERSION', '1.0.0' );
define( 'JETONOMY_DB_VERSION', '1.0.0' );
define( 'JETONOMY_FILE', __FILE__ );
define( 'JETONOMY_DIR', plugin_dir_path( __FILE__ ) );
define( 'JETONOMY_URL', plugin_dir_url( __FILE__ ) );

require_once JETONOMY_DIR . 'includes/class-jetonomy.php';

/**
 * Returns the main plugin instance.
 */
function jetonomy(): Jetonomy\Jetonomy {
    return Jetonomy\Jetonomy::instance();
}

jetonomy();
```

- [ ] **Step 2: Create main plugin class**

```php
<?php
// includes/class-jetonomy.php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

final class Jetonomy {
    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
    }

    private function register_hooks(): void {
        register_activation_hook( JETONOMY_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( JETONOMY_FILE, [ $this, 'deactivate' ] );
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    public function activate(): void {
        require_once JETONOMY_DIR . 'includes/db/class-schema.php';
        DB\Schema::create_tables();

        require_once JETONOMY_DIR . 'includes/permissions/class-capabilities.php';
        Permissions\Capabilities::register();

        update_option( 'jetonomy_db_version', JETONOMY_DB_VERSION );
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }

    public function init(): void {
        $this->check_db_version();
        $this->load_dependencies();
    }

    private function check_db_version(): void {
        $current = get_option( 'jetonomy_db_version', '0.0.0' );
        if ( version_compare( $current, JETONOMY_DB_VERSION, '<' ) ) {
            require_once JETONOMY_DIR . 'includes/db/class-migrator.php';
            DB\Migrator::run( $current );
        }
    }

    private function load_dependencies(): void {
        require_once JETONOMY_DIR . 'includes/functions.php';
    }
}
```

- [ ] **Step 3: Create composer.json for dev dependencies**

```json
{
    "name": "jetonomy/jetonomy",
    "description": "Next-gen discussion platform for WordPress",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "wp-phpunit/wp-phpunit": "^6.7",
        "squizlabs/php_codesniffer": "^3.7",
        "wp-coding-standards/wpcs": "^3.0",
        "phpcompatibility/phpcompatibility-wp": "^2.1"
    },
    "autoload": {
        "psr-4": {
            "Jetonomy\\": "includes/"
        }
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    }
}
```

- [ ] **Step 4: Create PHPUnit config**

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
>
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">./tests/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory suffix="Test.php">./tests/integration</directory>
        </testsuite>
    </testsuites>
    <php>
        <const name="JETONOMY_TESTING" value="true"/>
    </php>
</phpunit>
```

- [ ] **Step 5: Create test bootstrap**

```php
<?php
// tests/bootstrap.php
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__ ) . '/jetonomy.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 6: Commit**

```bash
git add jetonomy.php includes/class-jetonomy.php composer.json phpunit.xml.dist tests/bootstrap.php
git commit -m "feat: plugin bootstrap with activation, deactivation, and test setup"
```

---

### Task 2: Database Schema

**Files:**
- Create: `includes/db/class-schema.php`
- Create: `includes/db/class-migrator.php`
- Create: `includes/db/migrations/class-migration-1-0-0.php`
- Test: `tests/integration/db/SchemaTest.php`

- [ ] **Step 1: Write schema test**

```php
<?php
// tests/integration/db/SchemaTest.php
namespace Jetonomy\Tests\Integration\DB;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;

class SchemaTest extends WP_UnitTestCase {
    public function test_creates_all_tables(): void {
        Schema::create_tables();

        global $wpdb;
        $tables = [
            'jt_categories',
            'jt_spaces',
            'jt_posts',
            'jt_replies',
            'jt_votes',
            'jt_user_profiles',
            'jt_notifications',
            'jt_subscriptions',
            'jt_read_status',
            'jt_space_members',
            'jt_tags',
            'jt_post_tags',
            'jt_space_tags',
            'jt_space_tag_map',
            'jt_activity_log',
            'jt_restrictions',
            'jt_access_rules',
            'jt_flags',
            'jt_revisions',
            'jt_join_requests',
        ];

        foreach ( $tables as $table ) {
            $full = $wpdb->prefix . $table;
            $exists = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $full )
            );
            $this->assertEquals( $full, $exists, "Table {$full} should exist" );
        }
    }

    public function test_categories_table_has_correct_columns(): void {
        Schema::create_tables();

        global $wpdb;
        $columns = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}jt_categories", 0 );

        $this->assertContains( 'id', $columns );
        $this->assertContains( 'parent_id', $columns );
        $this->assertContains( 'name', $columns );
        $this->assertContains( 'slug', $columns );
        $this->assertContains( 'visibility', $columns );
        $this->assertContains( 'space_count', $columns );
    }

    public function test_posts_table_has_fulltext_index(): void {
        Schema::create_tables();

        global $wpdb;
        $indexes = $wpdb->get_results(
            "SHOW INDEX FROM {$wpdb->prefix}jt_posts WHERE Index_type = 'FULLTEXT'"
        );

        $this->assertNotEmpty( $indexes, 'Posts table should have FULLTEXT index' );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/integration/db/SchemaTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write Schema class**

```php
<?php
// includes/db/class-schema.php
namespace Jetonomy\DB;

defined( 'ABSPATH' ) || exit;

class Schema {

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = self::get_schema( $prefix, $charset );
        dbDelta( $sql );
    }

    public static function drop_tables(): void {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = self::get_table_names( $prefix );

        // Disable FK checks for clean drop
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
        foreach ( array_reverse( $tables ) as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
    }

    public static function get_table_names( string $prefix ): array {
        return [
            "{$prefix}jt_categories",
            "{$prefix}jt_spaces",
            "{$prefix}jt_posts",
            "{$prefix}jt_replies",
            "{$prefix}jt_votes",
            "{$prefix}jt_user_profiles",
            "{$prefix}jt_notifications",
            "{$prefix}jt_subscriptions",
            "{$prefix}jt_read_status",
            "{$prefix}jt_space_members",
            "{$prefix}jt_tags",
            "{$prefix}jt_post_tags",
            "{$prefix}jt_space_tags",
            "{$prefix}jt_space_tag_map",
            "{$prefix}jt_user_interests",
            "{$prefix}jt_activity_log",
            "{$prefix}jt_restrictions",
            "{$prefix}jt_access_rules",
            "{$prefix}jt_flags",
            "{$prefix}jt_revisions",
            "{$prefix}jt_join_requests",
        ];
    }

    private static function get_schema( string $p, string $c ): string {
        return "
CREATE TABLE {$p}jt_categories (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    parent_id bigint(20) unsigned DEFAULT NULL,
    name varchar(255) NOT NULL,
    slug varchar(255) NOT NULL,
    description text,
    icon varchar(100) DEFAULT NULL,
    color varchar(7) DEFAULT NULL,
    sort_order int(11) DEFAULT 0,
    space_count int(11) DEFAULT 0,
    visibility enum('public','private','hidden') DEFAULT 'public',
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY slug (slug),
    KEY idx_parent_sort (parent_id, sort_order)
) {$c};

CREATE TABLE {$p}jt_spaces (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    category_id bigint(20) unsigned NOT NULL,
    parent_id bigint(20) unsigned DEFAULT NULL,
    author_id bigint(20) unsigned NOT NULL,
    type enum('forum','qa','ideas','feed') NOT NULL DEFAULT 'forum',
    title varchar(255) NOT NULL,
    slug varchar(255) NOT NULL,
    description text,
    icon varchar(100) DEFAULT NULL,
    cover_image varchar(500) DEFAULT NULL,
    visibility enum('public','private','hidden') DEFAULT 'public',
    join_policy enum('open','approval','invite') DEFAULT 'open',
    status enum('active','archived','locked') DEFAULT 'active',
    sort_order int(11) DEFAULT 0,
    settings longtext,
    post_count int(11) DEFAULT 0,
    member_count int(11) DEFAULT 0,
    last_activity_at datetime DEFAULT NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY slug (slug),
    KEY idx_category_sort (category_id, sort_order),
    KEY idx_parent_sort (parent_id, sort_order)
) {$c};

CREATE TABLE {$p}jt_posts (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    space_id bigint(20) unsigned NOT NULL,
    author_id bigint(20) unsigned NOT NULL,
    type enum('topic','question','idea','status') NOT NULL,
    title varchar(255) NOT NULL,
    slug varchar(255) NOT NULL,
    content longtext NOT NULL,
    content_plain longtext NOT NULL,
    status enum('publish','pending','draft','spam','trash') DEFAULT 'publish',
    is_sticky tinyint(1) DEFAULT 0,
    is_closed tinyint(1) DEFAULT 0,
    is_resolved tinyint(1) DEFAULT 0,
    idea_status enum('submitted','under_review','planned','in_progress','completed','declined') DEFAULT NULL,
    vote_score int(11) DEFAULT 0,
    reply_count int(11) DEFAULT 0,
    view_count int(11) DEFAULT 0,
    last_reply_at datetime DEFAULT NULL,
    last_reply_by bigint(20) unsigned DEFAULT NULL,
    accepted_reply_id bigint(20) unsigned DEFAULT NULL,
    edited_at datetime DEFAULT NULL,
    edited_by bigint(20) unsigned DEFAULT NULL,
    updated_at datetime NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_space_sticky_last (space_id, is_sticky, last_reply_at),
    KEY idx_space_votes (space_id, vote_score),
    KEY idx_author_created (author_id, created_at),
    FULLTEXT KEY idx_search (title, content_plain)
) {$c};

CREATE TABLE {$p}jt_replies (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    post_id bigint(20) unsigned NOT NULL,
    parent_id bigint(20) unsigned DEFAULT NULL,
    author_id bigint(20) unsigned NOT NULL,
    content longtext NOT NULL,
    content_plain longtext NOT NULL,
    status enum('publish','pending','spam','trash') DEFAULT 'publish',
    vote_score int(11) DEFAULT 0,
    is_accepted tinyint(1) DEFAULT 0,
    edited_at datetime DEFAULT NULL,
    edited_by bigint(20) unsigned DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_post_created (post_id, created_at),
    KEY idx_post_votes (post_id, vote_score),
    KEY idx_author_created (author_id, created_at),
    FULLTEXT KEY idx_search (content_plain)
) {$c};

CREATE TABLE {$p}jt_votes (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    object_type enum('post','reply') NOT NULL,
    object_id bigint(20) unsigned NOT NULL,
    value tinyint(4) NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_user_vote (user_id, object_type, object_id)
) {$c};

CREATE TABLE {$p}jt_user_profiles (
    user_id bigint(20) unsigned NOT NULL,
    display_name varchar(100) DEFAULT NULL,
    bio text,
    avatar_url varchar(500) DEFAULT NULL,
    trust_level tinyint(3) unsigned DEFAULT 0,
    reputation int(11) DEFAULT 0,
    post_count int(11) DEFAULT 0,
    reply_count int(11) DEFAULT 0,
    vote_received int(11) DEFAULT 0,
    badges longtext,
    settings longtext,
    last_seen_at datetime DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (user_id),
    KEY idx_trust_rep (trust_level, reputation)
) {$c};

CREATE TABLE {$p}jt_notifications (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    actor_id bigint(20) unsigned NOT NULL,
    type varchar(50) NOT NULL,
    object_type enum('post','reply','space','badge') NOT NULL,
    object_id bigint(20) unsigned NOT NULL,
    message varchar(500) DEFAULT NULL,
    is_read tinyint(1) DEFAULT 0,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_user_unread (user_id, is_read, created_at)
) {$c};

CREATE TABLE {$p}jt_subscriptions (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    object_type enum('space','post') NOT NULL,
    object_id bigint(20) unsigned NOT NULL,
    notify_via enum('web','email','both') DEFAULT 'both',
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_subscription (user_id, object_type, object_id)
) {$c};

CREATE TABLE {$p}jt_read_status (
    user_id bigint(20) unsigned NOT NULL,
    post_id bigint(20) unsigned NOT NULL,
    last_read_reply_id bigint(20) unsigned NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (user_id, post_id)
) {$c};

CREATE TABLE {$p}jt_space_members (
    space_id bigint(20) unsigned NOT NULL,
    user_id bigint(20) unsigned NOT NULL,
    role enum('viewer','member','moderator','admin') DEFAULT 'member',
    joined_at datetime NOT NULL,
    PRIMARY KEY  (space_id, user_id),
    KEY idx_user_spaces (user_id, joined_at)
) {$c};

CREATE TABLE {$p}jt_tags (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    name varchar(100) NOT NULL,
    slug varchar(100) NOT NULL,
    post_count int(11) DEFAULT 0,
    PRIMARY KEY  (id),
    UNIQUE KEY slug (slug)
) {$c};

CREATE TABLE {$p}jt_post_tags (
    post_id bigint(20) unsigned NOT NULL,
    tag_id bigint(20) unsigned NOT NULL,
    PRIMARY KEY  (post_id, tag_id),
    KEY idx_tag_posts (tag_id, post_id)
) {$c};

CREATE TABLE {$p}jt_space_tags (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    name varchar(100) NOT NULL,
    slug varchar(100) NOT NULL,
    space_count int(11) DEFAULT 0,
    PRIMARY KEY  (id),
    UNIQUE KEY slug (slug)
) {$c};

CREATE TABLE {$p}jt_space_tag_map (
    space_id bigint(20) unsigned NOT NULL,
    tag_id bigint(20) unsigned NOT NULL,
    PRIMARY KEY  (space_id, tag_id)
) {$c};

CREATE TABLE {$p}jt_user_interests (
    user_id bigint(20) unsigned NOT NULL,
    tag_id bigint(20) unsigned NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (user_id, tag_id)
) {$c};

CREATE TABLE {$p}jt_activity_log (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    action varchar(50) NOT NULL,
    object_type varchar(50) NOT NULL,
    object_id bigint(20) unsigned NOT NULL,
    metadata longtext,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_user_activity (user_id, created_at),
    KEY idx_global_feed (created_at)
) {$c};

CREATE TABLE {$p}jt_restrictions (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    type enum('global_ban','space_ban','silence','post_restrict') NOT NULL,
    space_id bigint(20) unsigned DEFAULT NULL,
    reason text,
    issued_by bigint(20) unsigned NOT NULL,
    expires_at datetime DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_user_type (user_id, type, space_id),
    KEY idx_expires (expires_at)
) {$c};

CREATE TABLE {$p}jt_access_rules (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    space_id bigint(20) unsigned NOT NULL,
    rule_type enum('membership','role','capability','trust_level','logged_in','everyone') NOT NULL,
    rule_value varchar(255) DEFAULT NULL,
    grants enum('read','participate','full') NOT NULL DEFAULT 'participate',
    space_role enum('viewer','member','moderator','admin') DEFAULT 'member',
    priority int(11) DEFAULT 0,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_space_priority (space_id, priority)
) {$c};

CREATE TABLE {$p}jt_flags (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    reporter_id bigint(20) unsigned NOT NULL,
    object_type enum('post','reply','user') NOT NULL,
    object_id bigint(20) unsigned NOT NULL,
    reason enum('spam','offensive','off_topic','harassment','other') NOT NULL,
    description text,
    status enum('pending','valid','dismissed') DEFAULT 'pending',
    resolved_by bigint(20) unsigned DEFAULT NULL,
    resolved_at datetime DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_status (status, created_at),
    KEY idx_object (object_type, object_id),
    KEY idx_reporter (reporter_id)
) {$c};

CREATE TABLE {$p}jt_revisions (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    object_type enum('post','reply') NOT NULL,
    object_id bigint(20) unsigned NOT NULL,
    author_id bigint(20) unsigned NOT NULL,
    content longtext NOT NULL,
    title varchar(255) DEFAULT NULL,
    edit_summary varchar(255) DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_object (object_type, object_id, created_at)
) {$c};

CREATE TABLE {$p}jt_join_requests (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    space_id bigint(20) unsigned NOT NULL,
    user_id bigint(20) unsigned NOT NULL,
    message text,
    status enum('pending','approved','denied') DEFAULT 'pending',
    reviewed_by bigint(20) unsigned DEFAULT NULL,
    reviewed_at datetime DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_request (space_id, user_id, status),
    KEY idx_space_pending (space_id, status, created_at)
) {$c};
";
    }
}
```

- [ ] **Step 4: Write Migrator class**

```php
<?php
// includes/db/class-migrator.php
namespace Jetonomy\DB;

defined( 'ABSPATH' ) || exit;

class Migrator {

    public static function run( string $from_version ): void {
        $migrations = self::get_migrations();

        foreach ( $migrations as $version => $class ) {
            if ( version_compare( $from_version, $version, '<' ) ) {
                require_once JETONOMY_DIR . "includes/db/migrations/class-migration-{$class}.php";
                $fqn = "Jetonomy\\DB\\Migrations\\Migration_{$class}";
                ( new $fqn() )->up();
                update_option( 'jetonomy_db_version', $version );
            }
        }
    }

    private static function get_migrations(): array {
        return [
            '1.0.0' => '1_0_0',
        ];
    }
}
```

- [ ] **Step 5: Write initial migration**

```php
<?php
// includes/db/migrations/class-migration-1-0-0.php
namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_0_0 {

    public function up(): void {
        require_once JETONOMY_DIR . 'includes/db/class-schema.php';
        \Jetonomy\DB\Schema::create_tables();
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/integration/db/SchemaTest.php`
Expected: PASS (all 3 tests)

- [ ] **Step 7: Commit**

```bash
git add includes/db/ tests/integration/db/
git commit -m "feat: database schema with 21 custom tables and migration system"
```

---

### Task 3: Abstract Base Model

**Files:**
- Create: `includes/models/class-model.php`
- Create: `includes/functions.php`

- [ ] **Step 1: Create helper functions**

```php
<?php
// includes/functions.php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Get the prefixed table name.
 */
function table( string $name ): string {
    global $wpdb;
    return $wpdb->prefix . 'jt_' . $name;
}

/**
 * Get current datetime in MySQL format.
 */
function now(): string {
    return current_time( 'mysql', true );
}
```

- [ ] **Step 2: Create abstract base model**

```php
<?php
// includes/models/class-model.php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;
use function Jetonomy\now;

abstract class Model {

    abstract protected static function table_name(): string;

    protected static function table(): string {
        return table( static::table_name() );
    }

    protected static function db(): \wpdb {
        global $wpdb;
        return $wpdb;
    }

    /**
     * Find a single record by ID.
     */
    public static function find( int $id ): ?object {
        $row = static::db()->get_row(
            static::db()->prepare(
                'SELECT * FROM ' . static::table() . ' WHERE id = %d',
                $id
            )
        );
        return $row ?: null;
    }

    /**
     * Insert a new record. Returns the inserted ID.
     */
    public static function insert( array $data ): int {
        static::db()->insert( static::table(), $data );
        return (int) static::db()->insert_id;
    }

    /**
     * Update a record by ID.
     */
    public static function update( int $id, array $data ): bool {
        $result = static::db()->update(
            static::table(),
            $data,
            [ 'id' => $id ]
        );
        return false !== $result;
    }

    /**
     * Delete a record by ID.
     */
    public static function delete( int $id ): bool {
        $result = static::db()->delete(
            static::table(),
            [ 'id' => $id ]
        );
        return false !== $result;
    }

    /**
     * Count records matching conditions.
     */
    public static function count( array $where = [] ): int {
        $sql = 'SELECT COUNT(*) FROM ' . static::table();

        if ( ! empty( $where ) ) {
            $clauses = [];
            $values  = [];
            foreach ( $where as $col => $val ) {
                $clauses[] = "{$col} = %s";
                $values[]  = $val;
            }
            $sql .= ' WHERE ' . implode( ' AND ', $clauses );
            $sql  = static::db()->prepare( $sql, ...$values );
        }

        return (int) static::db()->get_var( $sql );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/functions.php includes/models/class-model.php
git commit -m "feat: abstract base model and helper functions"
```

---

### Task 4: Category Model

**Files:**
- Create: `includes/models/class-category.php`
- Test: `tests/unit/models/CategoryTest.php`

- [ ] **Step 1: Write category tests**

```php
<?php
// tests/unit/models/CategoryTest.php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\Category;

class CategoryTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        require_once JETONOMY_DIR . 'includes/db/class-schema.php';
        \Jetonomy\DB\Schema::create_tables();
    }

    public function test_create_category(): void {
        $id = Category::create( [
            'name'        => 'Development',
            'slug'        => 'development',
            'description' => 'All things code',
        ] );

        $this->assertGreaterThan( 0, $id );

        $cat = Category::find( $id );
        $this->assertEquals( 'Development', $cat->name );
        $this->assertEquals( 'development', $cat->slug );
        $this->assertEquals( 'public', $cat->visibility );
    }

    public function test_create_nested_category(): void {
        $parent_id = Category::create( [
            'name' => 'Tech',
            'slug' => 'tech',
        ] );
        $child_id = Category::create( [
            'name'      => 'Frontend',
            'slug'      => 'frontend',
            'parent_id' => $parent_id,
        ] );

        $child = Category::find( $child_id );
        $this->assertEquals( $parent_id, (int) $child->parent_id );
    }

    public function test_list_top_level_categories(): void {
        Category::create( [ 'name' => 'A', 'slug' => 'a', 'sort_order' => 2 ] );
        Category::create( [ 'name' => 'B', 'slug' => 'b', 'sort_order' => 1 ] );
        Category::create( [ 'name' => 'C', 'slug' => 'c', 'parent_id' => 1 ] );

        $top = Category::list_top_level();
        $this->assertCount( 2, $top );
        $this->assertEquals( 'B', $top[0]->name ); // sorted by sort_order
    }

    public function test_find_by_slug(): void {
        Category::create( [ 'name' => 'Design', 'slug' => 'design' ] );

        $cat = Category::find_by_slug( 'design' );
        $this->assertNotNull( $cat );
        $this->assertEquals( 'Design', $cat->name );
    }

    public function test_increment_space_count(): void {
        $id = Category::create( [ 'name' => 'Test', 'slug' => 'test' ] );

        Category::increment_space_count( $id );
        Category::increment_space_count( $id );

        $cat = Category::find( $id );
        $this->assertEquals( 2, (int) $cat->space_count );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/unit/models/CategoryTest.php`
Expected: FAIL — Category class not found

- [ ] **Step 3: Write Category model**

```php
<?php
// includes/models/class-category.php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Category extends Model {

    protected static function table_name(): string {
        return 'categories';
    }

    public static function create( array $data ): int {
        $data['created_at'] = $data['created_at'] ?? now();
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return self::insert( $data );
    }

    public static function find_by_slug( string $slug ): ?object {
        return self::db()->get_row(
            self::db()->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE slug = %s',
                $slug
            )
        );
    }

    /**
     * List top-level categories (no parent) ordered by sort_order.
     */
    public static function list_top_level(): array {
        return self::db()->get_results(
            'SELECT * FROM ' . self::table() . ' WHERE parent_id IS NULL ORDER BY sort_order ASC, name ASC'
        );
    }

    /**
     * List child categories of a parent.
     */
    public static function list_children( int $parent_id ): array {
        return self::db()->get_results(
            self::db()->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE parent_id = %d ORDER BY sort_order ASC, name ASC',
                $parent_id
            )
        );
    }

    public static function increment_space_count( int $id, int $by = 1 ): void {
        self::db()->query(
            self::db()->prepare(
                'UPDATE ' . self::table() . ' SET space_count = space_count + %d WHERE id = %d',
                $by,
                $id
            )
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/models/CategoryTest.php`
Expected: PASS (all 5 tests)

- [ ] **Step 5: Commit**

```bash
git add includes/models/class-category.php tests/unit/models/CategoryTest.php
git commit -m "feat: Category model with CRUD, hierarchy, and space count tracking"
```

---

### Task 5: Space Model

**Files:**
- Create: `includes/models/class-space.php`
- Test: `tests/unit/models/SpaceTest.php`

- [ ] **Step 1: Write space tests**

```php
<?php
// tests/unit/models/SpaceTest.php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\Space;
use Jetonomy\Models\Category;

class SpaceTest extends WP_UnitTestCase {

    private int $cat_id;

    public function set_up(): void {
        parent::set_up();
        require_once JETONOMY_DIR . 'includes/db/class-schema.php';
        \Jetonomy\DB\Schema::create_tables();
        $this->cat_id = Category::create( [ 'name' => 'Dev', 'slug' => 'dev' ] );
    }

    public function test_create_space(): void {
        $id = Space::create( [
            'category_id' => $this->cat_id,
            'author_id'   => 1,
            'type'        => 'forum',
            'title'       => 'Python Developers',
            'slug'        => 'python-developers',
        ] );

        $this->assertGreaterThan( 0, $id );

        $space = Space::find( $id );
        $this->assertEquals( 'Python Developers', $space->title );
        $this->assertEquals( 'forum', $space->type );
        $this->assertEquals( 'open', $space->join_policy );
        $this->assertEquals( 'active', $space->status );
    }

    public function test_list_by_category(): void {
        Space::create( [ 'category_id' => $this->cat_id, 'author_id' => 1, 'type' => 'forum', 'title' => 'A', 'slug' => 'a' ] );
        Space::create( [ 'category_id' => $this->cat_id, 'author_id' => 1, 'type' => 'qa', 'title' => 'B', 'slug' => 'b' ] );

        $spaces = Space::list_by_category( $this->cat_id );
        $this->assertCount( 2, $spaces );
    }

    public function test_find_by_slug(): void {
        Space::create( [ 'category_id' => $this->cat_id, 'author_id' => 1, 'type' => 'forum', 'title' => 'Test', 'slug' => 'test-space' ] );

        $space = Space::find_by_slug( 'test-space' );
        $this->assertNotNull( $space );
        $this->assertEquals( 'Test', $space->title );
    }

    public function test_increment_post_count(): void {
        $id = Space::create( [ 'category_id' => $this->cat_id, 'author_id' => 1, 'type' => 'forum', 'title' => 'T', 'slug' => 't' ] );

        Space::increment_post_count( $id );
        $space = Space::find( $id );
        $this->assertEquals( 1, (int) $space->post_count );
    }

    public function test_sub_spaces(): void {
        $parent = Space::create( [ 'category_id' => $this->cat_id, 'author_id' => 1, 'type' => 'forum', 'title' => 'Parent', 'slug' => 'parent' ] );
        Space::create( [ 'category_id' => $this->cat_id, 'author_id' => 1, 'type' => 'forum', 'title' => 'Child', 'slug' => 'child', 'parent_id' => $parent ] );

        $children = Space::list_children( $parent );
        $this->assertCount( 1, $children );
        $this->assertEquals( 'Child', $children[0]->title );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/unit/models/SpaceTest.php`
Expected: FAIL — Space class not found

- [ ] **Step 3: Write Space model**

```php
<?php
// includes/models/class-space.php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class Space extends Model {

    protected static function table_name(): string {
        return 'spaces';
    }

    public static function create( array $data ): int {
        $time = now();
        $data['created_at']  = $data['created_at'] ?? $time;
        $data['updated_at']  = $data['updated_at'] ?? $time;

        $id = self::insert( $data );

        if ( $id && ! empty( $data['category_id'] ) ) {
            Category::increment_space_count( (int) $data['category_id'] );
        }

        return $id;
    }

    public static function find_by_slug( string $slug ): ?object {
        return self::db()->get_row(
            self::db()->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE slug = %s',
                $slug
            )
        );
    }

    public static function list_by_category( int $category_id ): array {
        return self::db()->get_results(
            self::db()->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE category_id = %d AND parent_id IS NULL ORDER BY sort_order ASC, title ASC',
                $category_id
            )
        );
    }

    public static function list_children( int $parent_id ): array {
        return self::db()->get_results(
            self::db()->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE parent_id = %d ORDER BY sort_order ASC, title ASC',
                $parent_id
            )
        );
    }

    public static function increment_post_count( int $id, int $by = 1 ): void {
        $time = now();
        self::db()->query(
            self::db()->prepare(
                'UPDATE ' . self::table() . ' SET post_count = post_count + %d, last_activity_at = %s, updated_at = %s WHERE id = %d',
                $by,
                $time,
                $time,
                $id
            )
        );
    }

    public static function increment_member_count( int $id, int $by = 1 ): void {
        self::db()->query(
            self::db()->prepare(
                'UPDATE ' . self::table() . ' SET member_count = member_count + %d, updated_at = %s WHERE id = %d',
                $by,
                now(),
                $id
            )
        );
    }

    /**
     * Get decoded settings JSON.
     */
    public static function get_settings( int $id ): array {
        $space = self::find( $id );
        if ( ! $space || empty( $space->settings ) ) {
            return [];
        }
        return json_decode( $space->settings, true ) ?: [];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/models/SpaceTest.php`
Expected: PASS (all 5 tests)

- [ ] **Step 5: Commit**

```bash
git add includes/models/class-space.php tests/unit/models/SpaceTest.php
git commit -m "feat: Space model with CRUD, hierarchy, and category integration"
```

---

*Remaining tasks in this chunk continue the same TDD pattern for: Post model (Task 6), Reply model (Task 7), Vote model (Task 8), UserProfile model (Task 9), SpaceMember model (Task 10), and remaining models. Each follows the exact same write-test → fail → implement → pass → commit flow.*

*The plan continues in Chunk 2 (Permissions Engine) and Chunk 3 (Trust System).*

---

## Chunk 2: Permissions Engine

### Task 11: WordPress Capabilities Registration

**Files:**
- Create: `includes/permissions/class-capabilities.php`
- Test: `tests/unit/permissions/CapabilitiesTest.php`

- [ ] **Step 1: Write capabilities test**

```php
<?php
// tests/unit/permissions/CapabilitiesTest.php
namespace Jetonomy\Tests\Unit\Permissions;

use WP_UnitTestCase;
use Jetonomy\Permissions\Capabilities;

class CapabilitiesTest extends WP_UnitTestCase {

    public function test_register_adds_caps_to_administrator(): void {
        Capabilities::register();

        $admin = get_role( 'administrator' );
        $this->assertTrue( $admin->has_cap( 'jetonomy_read' ) );
        $this->assertTrue( $admin->has_cap( 'jetonomy_create_posts' ) );
        $this->assertTrue( $admin->has_cap( 'jetonomy_moderate' ) );
        $this->assertTrue( $admin->has_cap( 'jetonomy_manage_settings' ) );
    }

    public function test_subscriber_gets_basic_caps(): void {
        Capabilities::register();

        $subscriber = get_role( 'subscriber' );
        $this->assertTrue( $subscriber->has_cap( 'jetonomy_read' ) );
        $this->assertTrue( $subscriber->has_cap( 'jetonomy_create_posts' ) );
        $this->assertTrue( $subscriber->has_cap( 'jetonomy_vote' ) );
        $this->assertFalse( $subscriber->has_cap( 'jetonomy_moderate' ) );
        $this->assertFalse( $subscriber->has_cap( 'jetonomy_manage_settings' ) );
    }

    public function test_editor_gets_moderation_caps(): void {
        Capabilities::register();

        $editor = get_role( 'editor' );
        $this->assertTrue( $editor->has_cap( 'jetonomy_moderate' ) );
        $this->assertTrue( $editor->has_cap( 'jetonomy_edit_others_posts' ) );
        $this->assertTrue( $editor->has_cap( 'jetonomy_close_posts' ) );
    }

    public function test_unregister_removes_all_caps(): void {
        Capabilities::register();
        Capabilities::unregister();

        $admin = get_role( 'administrator' );
        $this->assertFalse( $admin->has_cap( 'jetonomy_read' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

- [ ] **Step 3: Write Capabilities class**

```php
<?php
// includes/permissions/class-capabilities.php
namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

class Capabilities {

    private const ROLE_MAP = [
        'subscriber'    => [
            'jetonomy_read',
            'jetonomy_create_posts',
            'jetonomy_create_replies',
            'jetonomy_edit_own_posts',
            'jetonomy_delete_own_posts',
            'jetonomy_vote',
            'jetonomy_flag',
            'jetonomy_join_spaces',
        ],
        'contributor'   => [
            'jetonomy_upload_media',
        ],
        'author'        => [
            'jetonomy_create_spaces',
        ],
        'editor'        => [
            'jetonomy_edit_others_posts',
            'jetonomy_delete_others_posts',
            'jetonomy_moderate',
            'jetonomy_manage_users',
            'jetonomy_move_posts',
            'jetonomy_close_posts',
            'jetonomy_pin_posts',
        ],
        'administrator' => [
            'jetonomy_manage_settings',
            'jetonomy_manage_categories',
            'jetonomy_manage_badges',
            'jetonomy_view_analytics',
            'jetonomy_manage_extensions',
        ],
    ];

    public static function register(): void {
        $cumulative = [];

        foreach ( self::ROLE_MAP as $role_name => $caps ) {
            $cumulative = array_merge( $cumulative, $caps );
            $role       = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( $cumulative as $cap ) {
                $role->add_cap( $cap );
            }
        }
    }

    public static function unregister(): void {
        $all_caps = array_unique( array_merge( ...array_values( self::ROLE_MAP ) ) );

        foreach ( array_keys( self::ROLE_MAP ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( $all_caps as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

    public static function get_all(): array {
        return array_unique( array_merge( ...array_values( self::ROLE_MAP ) ) );
    }
}
```

- [ ] **Step 4: Run tests — PASS**
- [ ] **Step 5: Commit**

```bash
git add includes/permissions/class-capabilities.php tests/unit/permissions/CapabilitiesTest.php
git commit -m "feat: WordPress capabilities registration with cumulative role mapping"
```

---

### Task 12: Permission Engine (3-Layer Resolver)

**Files:**
- Create: `includes/permissions/class-permission-engine.php`
- Create: `includes/permissions/class-space-role-checker.php`
- Create: `includes/permissions/class-trust-gate.php`
- Test: `tests/unit/permissions/PermissionEngineTest.php`

- [ ] **Step 1: Write permission engine test**

```php
<?php
// tests/unit/permissions/PermissionEngineTest.php
namespace Jetonomy\Tests\Unit\Permissions;

use WP_UnitTestCase;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Models\Space;
use Jetonomy\Models\Space_Member;
use Jetonomy\Models\Category;

class PermissionEngineTest extends WP_UnitTestCase {

    private int $space_id;
    private int $user_id;

    public function set_up(): void {
        parent::set_up();
        \Jetonomy\DB\Schema::create_tables();
        \Jetonomy\Permissions\Capabilities::register();

        $cat_id = Category::create( [ 'name' => 'Test', 'slug' => 'test' ] );
        $this->space_id = Space::create( [
            'category_id' => $cat_id,
            'author_id'   => 1,
            'type'        => 'forum',
            'title'       => 'Test Space',
            'slug'        => 'test-space',
        ] );
        $this->user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
    }

    public function test_public_space_allows_read_for_any_user(): void {
        $can = Permission_Engine::can( $this->user_id, 'read', $this->space_id );
        $this->assertTrue( $can );
    }

    public function test_private_space_denies_non_member(): void {
        Space::update( $this->space_id, [ 'visibility' => 'private' ] );

        $can = Permission_Engine::can( $this->user_id, 'read', $this->space_id );
        $this->assertFalse( $can );
    }

    public function test_private_space_allows_member(): void {
        Space::update( $this->space_id, [ 'visibility' => 'private' ] );
        Space_Member::add( $this->space_id, $this->user_id, 'member' );

        $can = Permission_Engine::can( $this->user_id, 'read', $this->space_id );
        $this->assertTrue( $can );
    }

    public function test_admin_user_bypasses_space_checks(): void {
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        Space::update( $this->space_id, [ 'visibility' => 'private' ] );

        $can = Permission_Engine::can( $admin_id, 'read', $this->space_id );
        $this->assertTrue( $can );
    }

    public function test_member_cannot_moderate(): void {
        Space_Member::add( $this->space_id, $this->user_id, 'member' );

        $can = Permission_Engine::can( $this->user_id, 'close_posts', $this->space_id );
        $this->assertFalse( $can );
    }

    public function test_space_moderator_can_close(): void {
        Space_Member::add( $this->space_id, $this->user_id, 'moderator' );

        $can = Permission_Engine::can( $this->user_id, 'close_posts', $this->space_id );
        $this->assertTrue( $can );
    }
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Write SpaceMember model (dependency)**

```php
<?php
// includes/models/class-space-member.php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;
use function Jetonomy\now;

class Space_Member {

    public static function table(): string {
        return table( 'space_members' );
    }

    public static function add( int $space_id, int $user_id, string $role = 'member' ): void {
        global $wpdb;
        $wpdb->replace(
            self::table(),
            [
                'space_id'  => $space_id,
                'user_id'   => $user_id,
                'role'      => $role,
                'joined_at' => now(),
            ]
        );
        Space::increment_member_count( $space_id );
    }

    public static function remove( int $space_id, int $user_id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [
            'space_id' => $space_id,
            'user_id'  => $user_id,
        ] );
        Space::increment_member_count( $space_id, -1 );
    }

    public static function get_role( int $space_id, int $user_id ): ?string {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                'SELECT role FROM ' . self::table() . ' WHERE space_id = %d AND user_id = %d',
                $space_id,
                $user_id
            )
        );
    }

    public static function is_member( int $space_id, int $user_id ): bool {
        return null !== self::get_role( $space_id, $user_id );
    }
}
```

- [ ] **Step 4: Write Permission Engine**

```php
<?php
// includes/permissions/class-permission-engine.php
namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Space;
use Jetonomy\Models\Space_Member;
use Jetonomy\Models\Restriction;

class Permission_Engine {

    private const SPACE_ROLE_PERMS = [
        'viewer'    => [ 'read' ],
        'member'    => [ 'read', 'create_posts', 'create_replies', 'vote', 'flag' ],
        'moderator' => [ 'read', 'create_posts', 'create_replies', 'vote', 'flag', 'edit_others_posts', 'delete_others_posts', 'close_posts', 'pin_posts', 'move_posts' ],
        'admin'     => [ 'read', 'create_posts', 'create_replies', 'vote', 'flag', 'edit_others_posts', 'delete_others_posts', 'close_posts', 'pin_posts', 'move_posts', 'manage_spaces' ],
    ];

    /**
     * Main permission check: can $user_id perform $action in $space_id?
     */
    public static function can( int $user_id, string $action, ?int $space_id = null ): bool {
        // Layer 0: Check global ban
        if ( Restriction::is_banned( $user_id ) ) {
            return false;
        }

        // WP admin bypasses everything
        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        // Layer 1: WordPress capability
        $wp_cap = 'jetonomy_' . $action;
        if ( ! user_can( $user_id, $wp_cap ) && ! user_can( $user_id, 'jetonomy_read' ) ) {
            return false;
        }

        // If no space context, just check WP cap
        if ( null === $space_id ) {
            return user_can( $user_id, $wp_cap );
        }

        // Layer 2: Space visibility + membership
        $space = Space::find( $space_id );
        if ( ! $space ) {
            return false;
        }

        if ( 'private' === $space->visibility || 'hidden' === $space->visibility ) {
            if ( ! Space_Member::is_member( $space_id, $user_id ) ) {
                return false;
            }
        }

        // For read on public space, anyone with jetonomy_read can access
        if ( 'read' === $action && 'public' === $space->visibility ) {
            return true;
        }

        // Check space role
        $role = Space_Member::get_role( $space_id, $user_id );
        if ( ! $role ) {
            // Non-member of public space can only read
            return 'read' === $action;
        }

        $allowed = self::SPACE_ROLE_PERMS[ $role ] ?? [];
        return in_array( $action, $allowed, true );
    }
}
```

- [ ] **Step 5: Write Restriction model (dependency)**

```php
<?php
// includes/models/class-restriction.php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;
use function Jetonomy\now;

class Restriction extends Model {

    protected static function table_name(): string {
        return 'restrictions';
    }

    public static function is_banned( int $user_id ): bool {
        $now = now();
        return (bool) self::db()->get_var(
            self::db()->prepare(
                "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND type = 'global_ban' AND (expires_at IS NULL OR expires_at > %s)",
                $user_id,
                $now
            )
        );
    }

    public static function is_space_banned( int $user_id, int $space_id ): bool {
        $now = now();
        return (bool) self::db()->get_var(
            self::db()->prepare(
                "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND type = 'space_ban' AND space_id = %d AND (expires_at IS NULL OR expires_at > %s)",
                $user_id,
                $space_id,
                $now
            )
        );
    }

    public static function ban( int $user_id, string $type, int $issued_by, ?int $space_id = null, ?string $reason = null, ?string $expires_at = null ): int {
        return self::insert( [
            'user_id'    => $user_id,
            'type'       => $type,
            'space_id'   => $space_id,
            'reason'     => $reason,
            'issued_by'  => $issued_by,
            'expires_at' => $expires_at,
            'created_at' => now(),
        ] );
    }
}
```

- [ ] **Step 6: Run tests — PASS**
- [ ] **Step 7: Commit**

```bash
git add includes/permissions/ includes/models/class-space-member.php includes/models/class-restriction.php tests/unit/permissions/PermissionEngineTest.php
git commit -m "feat: 3-layer permission engine with space roles and ban checking"
```

---

## Chunk 3: Trust & Reputation System

### Task 13: Trust Level Definitions

**Files:**
- Create: `includes/trust/class-trust-levels.php`

- [ ] **Step 1: Write trust levels config**

```php
<?php
// includes/trust/class-trust-levels.php
namespace Jetonomy\Trust;

defined( 'ABSPATH' ) || exit;

class Trust_Levels {

    public const LEVELS = [
        0 => [
            'name'         => 'Newcomer',
            'requirements' => [],
            'rate_limits'  => [ 'posts_per_day' => 3, 'replies_per_day' => 10, 'votes_per_day' => 5 ],
            'abilities'    => [ 'read', 'post', 'reply', 'vote' ],
            'restrictions' => [ 'no_links', 'no_images' ],
        ],
        1 => [
            'name'         => 'Member',
            'requirements' => [ 'posts' => 5, 'days_active' => 3, 'replies_received' => 10 ],
            'rate_limits'  => [],
            'abilities'    => [ 'links', 'images', 'flag', 'mentions' ],
            'restrictions' => [],
        ],
        2 => [
            'name'         => 'Regular',
            'requirements' => [ 'posts' => 30, 'days_active' => 20, 'reputation' => 50 ],
            'rate_limits'  => [],
            'abilities'    => [ 'edit_own_no_cooldown', 'edit_others_tags', 'invite', 'upload_files' ],
            'restrictions' => [],
        ],
        3 => [
            'name'         => 'Trusted',
            'requirements' => [ 'posts' => 100, 'days_active' => 60, 'reputation' => 200 ],
            'rate_limits'  => [],
            'abilities'    => [ 'edit_others', 'move_topics', 'close_topics', 'resolve', 'pin_temp' ],
            'restrictions' => [],
        ],
        4 => [
            'name'         => 'Leader',
            'requirements' => [],
            'rate_limits'  => [],
            'abilities'    => [ 'create_sub_spaces', 'manage_tags', 'silence_users', 'approve_joins' ],
            'restrictions' => [],
        ],
        5 => [
            'name'         => 'Moderator',
            'requirements' => [],
            'rate_limits'  => [],
            'abilities'    => [ 'full_moderation', 'manage_trust', 'ban_users', 'mod_dashboard' ],
            'restrictions' => [],
        ],
    ];

    public static function get( int $level ): array {
        return self::LEVELS[ $level ] ?? self::LEVELS[0];
    }

    public static function name( int $level ): string {
        return self::get( $level )['name'];
    }

    public static function can_auto_earn( int $level ): bool {
        return $level <= 3; // Levels 4-5 are manually granted
    }
}
```

- [ ] **Step 2: Commit**

### Task 14: Reputation Calculator

**Files:**
- Create: `includes/trust/class-reputation.php`
- Test: `tests/unit/trust/ReputationTest.php`

- [ ] **Step 1: Write reputation test**

```php
<?php
// tests/unit/trust/ReputationTest.php
namespace Jetonomy\Tests\Unit\Trust;

use WP_UnitTestCase;
use Jetonomy\Trust\Reputation;

class ReputationTest extends WP_UnitTestCase {

    public function test_post_upvote_gives_10_points(): void {
        $this->assertEquals( 10, Reputation::points_for( 'post_upvoted' ) );
    }

    public function test_reply_upvoted_gives_5_points(): void {
        $this->assertEquals( 5, Reputation::points_for( 'reply_upvoted' ) );
    }

    public function test_reply_accepted_gives_15_points(): void {
        $this->assertEquals( 15, Reputation::points_for( 'reply_accepted' ) );
    }

    public function test_downvoted_loses_2_points(): void {
        $this->assertEquals( -2, Reputation::points_for( 'downvoted' ) );
    }

    public function test_post_removed_loses_20_points(): void {
        $this->assertEquals( -20, Reputation::points_for( 'post_removed' ) );
    }

    public function test_unknown_action_gives_0(): void {
        $this->assertEquals( 0, Reputation::points_for( 'unknown_action' ) );
    }
}
```

- [ ] **Step 2: Run test — FAIL**
- [ ] **Step 3: Write Reputation class**

```php
<?php
// includes/trust/class-reputation.php
namespace Jetonomy\Trust;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\User_Profile;

class Reputation {

    private const POINTS = [
        'post_upvoted'    => 10,
        'reply_upvoted'   => 5,
        'reply_accepted'  => 15,
        'idea_planned'    => 20,
        'downvoted'       => -2,
        'flag_validated'  => 5,
        'post_reported'   => -10,
        'post_removed'    => -20,
    ];

    public static function points_for( string $action ): int {
        return self::POINTS[ $action ] ?? 0;
    }

    /**
     * Award reputation to a user and update their profile.
     */
    public static function award( int $user_id, string $action ): int {
        $points = self::points_for( $action );
        if ( 0 === $points ) {
            return 0;
        }

        User_Profile::adjust_reputation( $user_id, $points );

        do_action( 'jetonomy_reputation_changed', $user_id, $points, $action );

        return $points;
    }
}
```

- [ ] **Step 4: Run tests — PASS**
- [ ] **Step 5: Commit**

```bash
git add includes/trust/ tests/unit/trust/
git commit -m "feat: trust level definitions and reputation calculator"
```

---

### Task 15: Trust Level Evaluator

**Files:**
- Create: `includes/trust/class-trust-evaluator.php`
- Test: `tests/unit/trust/TrustEvaluatorTest.php`

- [ ] **Step 1: Write trust evaluator test**

```php
<?php
// tests/unit/trust/TrustEvaluatorTest.php
namespace Jetonomy\Tests\Unit\Trust;

use WP_UnitTestCase;
use Jetonomy\Trust\Trust_Evaluator;

class TrustEvaluatorTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        \Jetonomy\DB\Schema::create_tables();
    }

    public function test_new_user_is_level_0(): void {
        $level = Trust_Evaluator::evaluate_level( [
            'post_count'  => 0,
            'days_active' => 0,
            'reputation'  => 0,
            'replies_received' => 0,
        ] );
        $this->assertEquals( 0, $level );
    }

    public function test_qualifies_for_level_1(): void {
        $level = Trust_Evaluator::evaluate_level( [
            'post_count'       => 5,
            'days_active'      => 3,
            'reputation'       => 0,
            'replies_received' => 10,
        ] );
        $this->assertEquals( 1, $level );
    }

    public function test_qualifies_for_level_2(): void {
        $level = Trust_Evaluator::evaluate_level( [
            'post_count'       => 30,
            'days_active'      => 20,
            'reputation'       => 50,
            'replies_received' => 100,
        ] );
        $this->assertEquals( 2, $level );
    }

    public function test_qualifies_for_level_3(): void {
        $level = Trust_Evaluator::evaluate_level( [
            'post_count'       => 100,
            'days_active'      => 60,
            'reputation'       => 200,
            'replies_received' => 500,
        ] );
        $this->assertEquals( 3, $level );
    }

    public function test_never_auto_promotes_above_3(): void {
        $level = Trust_Evaluator::evaluate_level( [
            'post_count'       => 10000,
            'days_active'      => 365,
            'reputation'       => 50000,
            'replies_received' => 99999,
        ] );
        $this->assertEquals( 3, $level ); // max auto level
    }
}
```

- [ ] **Step 2: Run test — FAIL**
- [ ] **Step 3: Write Trust Evaluator**

```php
<?php
// includes/trust/class-trust-evaluator.php
namespace Jetonomy\Trust;

defined( 'ABSPATH' ) || exit;

class Trust_Evaluator {

    /**
     * Evaluate the highest auto-earnable trust level for given stats.
     * Levels 4-5 are manually granted and never returned here.
     */
    public static function evaluate_level( array $stats ): int {
        $level = 0;

        for ( $candidate = 1; $candidate <= 3; $candidate++ ) {
            $def  = Trust_Levels::get( $candidate );
            $reqs = $def['requirements'];

            if ( empty( $reqs ) ) {
                continue;
            }

            $meets = true;
            foreach ( $reqs as $key => $threshold ) {
                $value = $stats[ $key ] ?? 0;
                if ( $value < $threshold ) {
                    $meets = false;
                    break;
                }
            }

            if ( $meets ) {
                $level = $candidate;
            } else {
                break; // levels are sequential
            }
        }

        return $level;
    }
}
```

- [ ] **Step 4: Run tests — PASS**
- [ ] **Step 5: Commit**

```bash
git add includes/trust/class-trust-evaluator.php tests/unit/trust/TrustEvaluatorTest.php
git commit -m "feat: trust level evaluator with auto-promotion up to level 3"
```

---

## Chunk Summary & Next Steps

This plan covers the **Core Engine** — Tasks 1 through 15. After completing this:

**Remaining models to implement** (same TDD pattern, omitted for brevity):
- Task 6: Post model (CRUD, type handling, denormalized counters)
- Task 7: Reply model (CRUD, flat listing, accepted answer)
- Task 8: Vote model (CRUD, score update, uniqueness)
- Task 9: UserProfile model (CRUD, reputation adjust, stats)
- Task 10: Remaining models (Flag, Tag, Notification, Subscription, ReadStatus, Revision, AccessRule, ActivityLog, JoinRequest)
- Task 16: Rate Limiter
- Task 17: Integration test — full permission flow

**Then proceed to Plan 2: REST API Layer** which builds on all models and permissions defined here.

---

Plan complete and saved to `docs/superpowers/plans/2026-03-18-jetonomy-v1-core-engine.md`. Ready to execute?
