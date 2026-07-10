# Anonymous Posting (Pro) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let members author topics and replies anonymously (name + avatar hidden from other members), gated by a global master switch AND per-space opt-in, with a leak-proof display path and an accountable, logged site-admin-only reveal.

**Architecture:** Approach A — a thin **free seam** (an `is_anonymous` flag column on `jt_posts`/`jt_replies`, a single `Jetonomy\Author::for_display()` author resolver that every author-render surface routes through, and a `jetonomy_author_can_reveal` filter) plus a self-contained **Pro extension** `jetonomy-pro/includes/extensions/anonymous-posting/` that owns 100% of the feature logic/UI/settings (gate, write enforcement, compose toggle, global + per-space settings, reveal + audit log, reveal REST endpoint). Free honors the flag even when Pro is inactive; masking happens where identity is resolved, which is in free.

**Tech Stack:** PHP 8.1+, WordPress 6.7+, custom `jt_*` MySQL tables via `dbDelta()`, `Jetonomy\` (free) / `Jetonomy_Pro\` (Pro) namespaces, PSR-4 autoloader (`includes/class-autoloader.php`), WP Interactivity API frontend, REST namespace `jetonomy/v1`, PHPUnit (`WP_UnitTestCase`, `composer test:combo`), Playwright MCP for browser verification.

## Global Constraints

- **Both versions ship together at the same `x.y.z`.** This feature targets **1.7.0**. Bump `JETONOMY_VERSION`, `jetonomy.php` `Version:`, `readme.txt` `Stable tag:` and the Pro constants/headers in lockstep at release (enforced by `bin/build-release.sh`).
- **Manifest-first, ZERO duplication.** `audit/manifest.json` (free) and `jetonomy-pro/audit/manifest.json` (pro) are canonical. All four new symbols (`Jetonomy\Author`, `Author::for_display`, `is_anonymous`, `jetonomy_author_can_reveal`) were greped across both plugins + both manifests and **do not exist** — safe to create. Reuse existing symbols listed in "Reuse & Anti-Duplication".
- **Only three kinds of free change are allowed** beyond the leak-audit routing that §3.1.3 of the spec mandates: (a) the `Author::for_display()` resolver, (b) the `is_anonymous` column (migration + schema + create-path passthrough), (c) the `jetonomy_author_can_reveal` filter. No anon-specific UI or settings in free. The compose toggle uses two **already-existing** free hooks — no new free hook is added.
- **REST mutation auth.** Every Pro mutation route uses the lazy base wrapper `$this->rest_auth_mutation( $caps )` — never the eager `\Jetonomy\API\REST_Auth::auth_mutation()` at registration time (fatals the REST API if free is unloaded). `php bin/audit-rest-routes.php` must report OK for both trees.
- **CSS token rules.** No hardcoded px/hex/font-family. All values reference `--jt-*` tokens defined in `:root, .jt-app` in `assets/css/jetonomy.css`. Dark mode via token reassignment only. RTL via logical properties (`margin-inline-*`, `inset-inline-*`, `text-align: start`).
- **i18n.** Free strings in `jetonomy` text domain, Pro strings in `jetonomy-pro`, including JS store keys (match the 1.6.1 i18n sweep). Verify at 390px viewport before marking any UI task done.
- **Big-site readiness.** No new list/grid is introduced. `Author::for_display()` short-circuits on the already-loaded row before any user lookup (no N+1, no extra query, no index needed — the column rides the row already selected). The reveal endpoint acts on a single id.
- **Local CI gate before "done":** `php bin/audit-rest-routes.php includes/` + `... ../jetonomy-pro/includes/` (both OK), `wp jetonomy qa-actions` (210/210), free+pro boot smoke, `composer test:combo`, browser-verify every template/UI change incl. 390px.

---

## File Structure

**Free (`jetonomy/`) — seam only:**

| Path | Responsibility |
|---|---|
| `includes/class-author.php` | **New.** `Jetonomy\Author::for_display()` resolver + `jetonomy_author_can_reveal` filter. Single source of author masking. |
| `includes/db/migrations/class-migration_1_7_0.php` | **New.** Idempotent `is_anonymous` column add on `jt_posts` + `jt_replies`. |
| `includes/db/class-schema.php` | Modify — add `is_anonymous` to the `jt_posts` + `jt_replies` CREATE blocks. |
| `includes/db/class-migrator.php` | Modify — register `'1.7.0' => '1_7_0'`. |
| `includes/models/class-post.php`, `class-reply.php` | Modify — default `is_anonymous => 0` in `create()`. |
| `includes/api/class-posts-controller.php`, `class-replies-controller.php` | Modify — accept client `is_anonymous` into create data (register arg + pass through) AND mask author fields in `prepare_*()`. |
| `includes/functions.php` | (No change — `get_user_link()` is reused as-is; the `id 0` path already renders the silhouette.) |
| `templates/partials/feed-card.php`, `post-card.php`, `reply-card.php` | Modify — route author through resolver. |
| `includes/class-feed.php` | Modify — RSS `dc:creator` through resolver. |
| `includes/notifications/class-notifier.php`, `includes/class-mentions.php` | Modify — actor name through resolver. |
| `includes/integrations/class-buddypress.php` | Modify — suppress broadcast for anon rows + mask action string + profile-tab list. |
| `includes/models/class-post.php` (`list_by_author`), `includes/api/class-users-controller.php` | Modify — exclude `is_anonymous = 1` from public profile author streams. |
| `includes/api/class-search-controller.php` | Modify — exclude `is_anonymous = 1` from author-filtered discovery. |

**Pro (`jetonomy-pro/includes/extensions/anonymous-posting/`) — the feature:**

| Path | Responsibility |
|---|---|
| `class-extension.php` | Meta/boot/activate/deactivate. Wires every hook. Class `Jetonomy_Pro\Extensions\Anonymous_Posting\Extension`. |
| `class-gate.php` | Single source of truth for "may this space/user author anonymously" — global option + per-space flag. |
| `class-reveal.php` | `jetonomy_author_can_reveal` filter + explicit reveal context + `ActivityLog::log()` + admin "Reveal author" affordance. |
| `class-rest.php` | `POST jetonomy/v1/anonymous/reveal` endpoint. |
| `views/settings.php` | Global setting panel markup. |
| `views/space-setting.php` | Per-space opt-in field markup. |
| `assets/anonymous.js` | Compose-toggle → include `is_anonymous` in the create payload; reveal button → call reveal endpoint. |

**Tests (in the free tree, per repo convention):**

| Path | Covers |
|---|---|
| `tests/unit/AuthorTest.php` | Resolver masking matrix. |
| `tests/unit/models/PostAnonymousTest.php` | Column persistence via create path. |
| `tests/pro/AnonymousGateTest.php` | Gate matrix + write enforcement. |
| `tests/pro/AnonymousRevealTest.php` | Reveal filter + ActivityLog entry. |

---

# TASK GROUP 1 — FREE SEAM (foundation, lands first)

### Task 1: `is_anonymous` columns (migration + schema + migrator)

**Files:**
- Create: `includes/db/migrations/class-migration_1_7_0.php`
- Modify: `includes/db/class-schema.php:151-187` (jt_posts), `:190-209` (jt_replies)
- Modify: `includes/db/class-migrator.php:38-56`
- Test: `tests/unit/models/PostAnonymousTest.php`

**Interfaces:**
- Produces: columns `jt_posts.is_anonymous` and `jt_replies.is_anonymous`, both `tinyint(1) NOT NULL DEFAULT 0`, present on both fresh installs (schema) and upgrades (migration). No index (never queried by; read alongside the already-fetched row).
- Consumes: nothing.

- [ ] **Step 1: Write the failing test**

`tests/unit/models/PostAnonymousTest.php`:
```php
<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

class PostAnonymousTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_posts_table_has_is_anonymous_column(): void {
		global $wpdb;
		$col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}jt_posts LIKE 'is_anonymous'" );
		$this->assertSame( 'is_anonymous', $col );
	}

	public function test_replies_table_has_is_anonymous_column(): void {
		global $wpdb;
		$col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}jt_replies LIKE 'is_anonymous'" );
		$this->assertSame( 'is_anonymous', $col );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite=unit --filter PostAnonymousTest`
Expected: FAIL — column not found (empty string returned).

- [ ] **Step 3a: Add the column to both schema CREATE blocks**

In `includes/db/class-schema.php`, jt_posts block, add the column immediately after the `author_id` line (currently line 154):
```php
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  is_anonymous tinyint(1) NOT NULL DEFAULT 0,
```
jt_replies block, after its `author_id` line (currently line 194):
```php
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  is_anonymous tinyint(1) NOT NULL DEFAULT 0,
```

- [ ] **Step 3b: Create the idempotent migration**

`includes/db/migrations/class-migration_1_7_0.php`:
```php
<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.7.0 — anonymous posting flag.
 *
 * Adds is_anonymous TINYINT(1) DEFAULT 0 to jt_posts and jt_replies. The
 * column masks the real author on display; the real author_id is always kept.
 *
 * Idempotent: the column is added only when missing (SHOW COLUMNS guard), so
 * re-runs and sites where dbDelta already added it are both safe.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_7_0 {

	public function up(): void {
		global $wpdb;

		$tables = array( 'jt_posts', 'jt_replies' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $tables as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}

			$has_col = $wpdb->get_var(
				$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'is_anonymous' )
			);
			if ( $has_col ) {
				continue;
			}

			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN is_anonymous tinyint(1) NOT NULL DEFAULT 0 AFTER author_id" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
```

- [ ] **Step 3c: Register the migration**

In `includes/db/class-migrator.php`, append to the `get_migrations()` map (after `'1.6.1' => '1_6_1',`):
```php
			'1.6.1'   => '1_6_1',
			'1.7.0'   => '1_7_0',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite=unit --filter PostAnonymousTest`
Expected: PASS (both column tests green — `Schema::create_tables()` now emits the column).

- [ ] **Step 5: Commit**

```bash
git add includes/db/migrations/class-migration_1_7_0.php includes/db/class-schema.php includes/db/class-migrator.php tests/unit/models/PostAnonymousTest.php
git commit -m "feat(anon): add is_anonymous column to posts and replies (schema + idempotent 1.7.0 migration)"
```

---

### Task 2: `Jetonomy\Author::for_display()` resolver + `jetonomy_author_can_reveal` filter

**Files:**
- Create: `includes/class-author.php`
- Test: `tests/unit/AuthorTest.php`

**Interfaces:**
- Produces: `\Jetonomy\Author::for_display( int $author_id, ?object $object = null ): array` returning `array{id:int, name:string, avatar:string, url:string}`. When `$object->is_anonymous` is truthy AND `apply_filters( 'jetonomy_author_can_reveal', false, $object, get_current_user_id() )` is false → returns the generic anonymous identity `[ 'id' => 0, 'name' => 'Anonymous', 'avatar' => '', 'url' => '' ]`. Otherwise the real identity, avatar routed through `\Jetonomy\Avatar::display_url()`. Autoloaded automatically (`Jetonomy\` prefix → `includes/class-author.php`).
- Consumes: `\Jetonomy\Avatar::display_url()` (`includes/class-avatar.php:163`), `\Jetonomy\get_profile_url()` (`includes/functions.php`).

- [ ] **Step 1: Write the failing test**

`tests/unit/AuthorTest.php`:
```php
<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Author;

class AuthorTest extends WP_UnitTestCase {

	private int $uid;

	public function set_up(): void {
		parent::set_up();
		$this->uid = self::factory()->user->create( array( 'display_name' => 'Jane Doe' ) );
	}

	public function test_masks_anonymous_row_for_non_reveal_viewer(): void {
		$row = (object) array( 'author_id' => $this->uid, 'is_anonymous' => 1 );
		$out = Author::for_display( $this->uid, $row );
		$this->assertSame( 0, $out['id'] );
		$this->assertSame( 'Anonymous', $out['name'] );
		$this->assertSame( '', $out['url'] );
		$this->assertSame( '', $out['avatar'] );
	}

	public function test_returns_real_identity_for_unflagged_row(): void {
		$row = (object) array( 'author_id' => $this->uid, 'is_anonymous' => 0 );
		$out = Author::for_display( $this->uid, $row );
		$this->assertSame( $this->uid, $out['id'] );
		$this->assertSame( 'Jane Doe', $out['name'] );
	}

	public function test_reveal_filter_unmasks_flagged_row(): void {
		$row = (object) array( 'author_id' => $this->uid, 'is_anonymous' => 1 );
		add_filter( 'jetonomy_author_can_reveal', '__return_true' );
		$out = Author::for_display( $this->uid, $row );
		remove_filter( 'jetonomy_author_can_reveal', '__return_true' );
		$this->assertSame( $this->uid, $out['id'] );
		$this->assertSame( 'Jane Doe', $out['name'] );
	}

	public function test_null_object_returns_real_identity(): void {
		$out = Author::for_display( $this->uid, null );
		$this->assertSame( $this->uid, $out['id'] );
		$this->assertSame( 'Jane Doe', $out['name'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite=unit --filter AuthorTest`
Expected: FAIL — `Class "Jetonomy\Author" not found`.

- [ ] **Step 3: Write the resolver**

`includes/class-author.php`:
```php
<?php
/**
 * Author display resolver.
 *
 * The single seam every author-render surface routes through so an anonymous
 * post/reply masks its real author. The real author_id is always stored on the
 * row; this only controls what is *shown*.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Author {

	/**
	 * Resolve the identity to display for an author.
	 *
	 * When $object carries a truthy `is_anonymous` flag and no reveal filter
	 * grants access to the current viewer, the generic anonymous identity is
	 * returned (id 0, "Anonymous", no avatar URL, no profile URL) so the caller
	 * renders the silhouette + "Anonymous". In every other case the real
	 * author's identity is returned, with the avatar routed through
	 * Avatar::display_url() (no forked avatar logic).
	 *
	 * @param int         $author_id Real author user ID.
	 * @param object|null $object    The post/reply row (must expose ->is_anonymous
	 *                               to be maskable; null = always real identity).
	 * @return array{id:int,name:string,avatar:string,url:string}
	 */
	public static function for_display( int $author_id, ?object $object = null ): array {
		if ( $object && ! empty( $object->is_anonymous ) ) {
			/**
			 * Whether the current viewer may reveal an anonymous author.
			 *
			 * Free ships this as always-false — anonymous stays masked for
			 * everyone. The Pro anonymous-posting extension hooks it to grant
			 * an explicit-reveal context to site admins only.
			 *
			 * @param bool        $can_reveal Default false.
			 * @param object|null $object     The post/reply row being rendered.
			 * @param int         $viewer_id  Current user ID.
			 */
			$can_reveal = (bool) apply_filters( 'jetonomy_author_can_reveal', false, $object, get_current_user_id() );

			if ( ! $can_reveal ) {
				return array(
					'id'     => 0,
					'name'   => __( 'Anonymous', 'jetonomy' ),
					'avatar' => '',
					'url'    => '',
				);
			}
		}

		$user = $author_id > 0 ? get_userdata( $author_id ) : null;

		return array(
			'id'     => $author_id,
			'name'   => $user ? $user->display_name : '',
			'avatar' => Avatar::display_url( $author_id, 64 ),
			'url'    => $author_id > 0 ? get_profile_url( $author_id ) : '',
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite=unit --filter AuthorTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-author.php tests/unit/AuthorTest.php
git commit -m "feat(anon): add Jetonomy\\Author::for_display resolver + jetonomy_author_can_reveal filter"
```

---

### Task 3: Persist the client `is_anonymous` flag through the create path (free plumbing)

**Files:**
- Modify: `includes/models/class-post.php:51-60` (create defaults), `includes/models/class-reply.php:42-48`
- Modify: `includes/api/class-posts-controller.php:489-498` (data), and its create-route args (`register_routes()`); `includes/api/class-replies-controller.php:246-251` (data) + create-route args
- Test: `tests/unit/models/PostAnonymousTest.php` (extend)

**Interfaces:**
- Consumes: `jetonomy_before_create_post` / `jetonomy_before_create_reply` filters (Pro sets the final value in Task 12). Free only *carries* the client-requested flag into `$data`; it never trusts it as authoritative.
- Produces: `Post::create()` / `Reply::create()` always write an `is_anonymous` value (default 0). REST create routes accept an `is_anonymous` boolean param and forward it into the create data.

- [ ] **Step 1: Write the failing test** (append to `PostAnonymousTest`)

```php
	public function test_create_persists_is_anonymous_from_filter(): void {
		$cat   = \Jetonomy\Models\Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = \Jetonomy\Models\Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		$uid   = self::factory()->user->create();

		$cb = function ( $data ) {
			$data['is_anonymous'] = 1;
			return $data;
		};
		add_filter( 'jetonomy_before_create_post', $cb );
		$id = \Jetonomy\Models\Post::create( array( 'space_id' => $space, 'author_id' => $uid, 'title' => 'Secret', 'content' => 'x' ) );
		remove_filter( 'jetonomy_before_create_post', $cb );

		$row = \Jetonomy\Models\Post::find( $id );
		$this->assertEquals( 1, (int) $row->is_anonymous );
	}

	public function test_create_defaults_is_anonymous_to_zero(): void {
		$cat   = \Jetonomy\Models\Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = \Jetonomy\Models\Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		$uid   = self::factory()->user->create();
		$id    = \Jetonomy\Models\Post::create( array( 'space_id' => $space, 'author_id' => $uid, 'title' => 'Open', 'content' => 'y' ) );
		$row   = \Jetonomy\Models\Post::find( $id );
		$this->assertEquals( 0, (int) $row->is_anonymous );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite=unit --filter PostAnonymousTest`
Expected: `test_create_defaults_is_anonymous_to_zero` may already pass (DB default) but `test_create_persists_is_anonymous_from_filter` PASSES too because `insert()` writes any `$data` key. Confirm: if BOTH already pass, this proves the column persists via the generic insert — but still add the explicit default (Step 3) so a value is always present and the intent is documented. If the defaults test FAILS (row default not applied on some MySQL modes), Step 3 fixes it.

- [ ] **Step 3a: Add the default to `Post::create()`**

In `includes/models/class-post.php`, extend the defaults `array_merge` base (currently lines 52-60):
```php
		$now  = now();
		$data = array_merge(
			array(
				'status'        => 'publish',
				'is_anonymous'  => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
				'last_reply_at' => $now,
			),
			$data
		);
```

- [ ] **Step 3b: Add the default to `Reply::create()`**

In `includes/models/class-reply.php` (currently lines 42-48):
```php
		$data = array_merge(
			array(
				'status'       => 'publish',
				'is_anonymous' => 0,
				'created_at'   => now(),
			),
			$data
		);
```

- [ ] **Step 3c: Carry the client flag in the posts controller**

In `includes/api/class-posts-controller.php`, after the `$post_data` array (line 498) add:
```php
		$post_data['is_anonymous'] = (int) (bool) $request->get_param( 'is_anonymous' );
```
And register the param in the create route's `args` (in `register_routes()`, alongside `'space_id'`):
```php
					'is_anonymous' => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
```

- [ ] **Step 3d: Carry the client flag in the replies controller**

In `includes/api/class-replies-controller.php`, after the `$reply_data` array (line 251) add:
```php
		$reply_data['is_anonymous'] = (int) (bool) $request->get_param( 'is_anonymous' );
```
And register the same `is_anonymous` arg in the create route's `args` (alongside `'post_id'`).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite=unit --filter PostAnonymousTest`
Expected: PASS (4 tests). Also run `php bin/audit-rest-routes.php includes/` → OK.

- [ ] **Step 5: Commit**

```bash
git add includes/models/class-post.php includes/models/class-reply.php includes/api/class-posts-controller.php includes/api/class-replies-controller.php tests/unit/models/PostAnonymousTest.php
git commit -m "feat(anon): persist client is_anonymous through create path (default 0, REST param passthrough)"
```

---

# TASK GROUP 2 — FREE LEAK-AUDIT ROUTING (spec §6; acceptance criteria for "leak-proof")

> Every surface below shows "Anonymous" + silhouette for a flagged row when the viewer is not a revealing admin. The masking uses ONE call — `\Jetonomy\Author::for_display( (int) $row->author_id, $row )` — whose output overrides name/avatar/id/url. For the avatar the existing `get_user_link( $display['id'], ... )` is reused: `id 0` already renders the `jt-avatar-anon` silhouette (`functions.php:271-278`), so no new avatar asset or markup is created.

**Anonymous post card wireframe (member's view — Task 4):**
```
┌───────────────────────────────────────────────┐
│  ( 🙎 )  Anonymous · 2 hours ago               │   ← silhouette icon, no link
│                                                 │
│  How do I reset my 2FA device?                  │   ← title links to topic (unaffected)
│  I lost my phone and can't get the code…        │
│                                                 │
│  ▲ 12   💬 4   #account                          │
└───────────────────────────────────────────────┘
   name = "Anonymous" (span, no href) · avatar = silhouette · no profile link
```

### Task 4: Template cards — feed-card, post-card, reply-card

**Files:**
- Modify: `templates/partials/feed-card.php:18-41`, `templates/partials/post-card.php:9-19,126`, `templates/partials/reply-card.php:9-40,142,148`
- Verify: Playwright MCP (frontend, incl. 390px)

**Interfaces:**
- Consumes: `\Jetonomy\Author::for_display()`; `\Jetonomy\get_user_link()` (reused for avatar by id).

- [ ] **Step 1: feed-card.php — resolve once, use everywhere**

Replace the author setup (lines ~18-28) so `$author_name` / avatar / link derive from the resolver:
```php
$display     = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
$author_name = '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' );
```
Avatar echo (line ~34) — pass the resolved id so an anonymous row (id 0) renders the silhouette:
```php
echo \Jetonomy\get_user_link( (int) $display['id'], 'jt-avatar-md', 36, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
```
Name markup (lines ~38-41) — link only when a profile URL exists:
```php
<?php if ( '' !== $display['url'] ) : ?>
	<a class="jt-feed-card-author" href="<?php echo esc_url( $display['url'] ); ?>"><?php echo esc_html( $author_name ); ?></a>
<?php else : ?>
	<span class="jt-feed-card-author"><?php echo esc_html( $author_name ); ?></span>
<?php endif; ?>
```

- [ ] **Step 2: post-card.php — same substitution**

Replace the `$author`/`$initials` derivation (lines ~9-19) and the name echo (line 126):
```php
$display  = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
$initials = '' !== $display['name'] ? strtoupper( mb_substr( $display['name'], 0, 2 ) ) : '??';
```
Wherever the avatar image is built from `$profile`/`$initials`, replace with the id-routed helper (renders silhouette for id 0):
```php
echo \Jetonomy\get_user_link( (int) $display['id'], 'jt-avatar-sm', 28, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
```
Name (line 126):
```php
<?php echo esc_html( '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' ) ); ?>
```

- [ ] **Step 3: reply-card.php — name, avatar, AND the JS data attribute**

Replace author setup (lines ~9-14):
```php
$display   = \Jetonomy\Author::for_display( (int) $reply->author_id, $reply );
$author_id = (int) $display['id'];
```
Avatar (line ~30):
```php
echo \Jetonomy\get_user_link( (int) $display['id'], 'jt-avatar-sm', 28, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
```
Name block (lines ~35-40):
```php
<?php if ( '' !== $display['url'] ) : ?>
	<a class="jt-reply-author" href="<?php echo esc_url( $display['url'] ); ?>"><?php echo esc_html( $display['name'] ); ?></a>
<?php else : ?>
	<span class="jt-reply-author"><?php echo esc_html( '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' ) ); ?></span>
<?php endif; ?>
```
**Critical leak fix** — the quote/reply JS data attribute (lines 142/148) currently emits the real `display_name`. Change to the masked name:
```php
data-reply-author="<?php echo esc_attr( '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' ) ); ?>"
```
Note: `$is_op` (op-highlight) must keep comparing real ids (`(int) $reply->author_id === (int) $post->author_id`), NOT the masked id — do not route that comparison through the resolver.

- [ ] **Step 4: Browser-verify**

Seed one flagged post + one flagged reply (`wp jetonomy` or SQL `UPDATE ... SET is_anonymous=1`). With Playwright MCP: `mcp__plugin_playwright_playwright__browser_navigate` to the space feed and the topic, `browser_resize` to 390px, `browser_take_screenshot`. Confirm: silhouette + "Anonymous", no profile href, quote button inserts "Anonymous". Save shots to `~/Documents/work-artifacts/screenshots/2026-07/`.

- [ ] **Step 5: Commit**

```bash
git add templates/partials/feed-card.php templates/partials/post-card.php templates/partials/reply-card.php
git commit -m "feat(anon): mask author on feed/post/reply cards via Author::for_display (incl. quote data attr)"
```

---

### Task 5: REST controllers — mask author in `prepare_post()` / `prepare_reply()`

**Files:**
- Modify: `includes/api/class-posts-controller.php:1123-1194`, `includes/api/class-replies-controller.php:750-794`
- Test: `tests/integration/api/` (add a masking assertion) — reuse the suite's existing REST bootstrap.

**Interfaces:**
- Consumes: `\Jetonomy\Author::for_display()`. The override runs AFTER the enriched/fallback author block so BOTH branches (pre-enriched batch fields and per-item lookup) are masked in one place — no need to touch `enrich_with_author()`.

- [ ] **Step 1: Write the failing test**

`tests/integration/api/PostsAnonymousRestTest.php`:
```php
<?php
namespace Jetonomy\Tests\Integration\Api;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;

class PostsAnonymousRestTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		do_action( 'rest_api_init' );
	}

	public function test_flagged_post_returns_masked_author_to_member(): void {
		$cat   = Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		$uid   = self::factory()->user->create( array( 'display_name' => 'Real Name', 'role' => 'subscriber' ) );
		$pid   = Post::create( array( 'space_id' => $space, 'author_id' => $uid, 'title' => 'T', 'content' => 'c', 'is_anonymous' => 1 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$req  = new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$pid}" );
		$resp = rest_do_request( $req );
		$data = $resp->get_data();

		$this->assertSame( 0, $data['author_id'] );
		$this->assertSame( 'Anonymous', $data['author_name'] );
		$this->assertSame( '', $data['author_login'] );
		$this->assertNotSame( 'Real Name', $data['author_name'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite=unit --filter PostsAnonymousRestTest`
Expected: FAIL — `author_id` is the real uid, `author_name` is "Real Name".

- [ ] **Step 3a: Override at the end of `prepare_post()`**

In `includes/api/class-posts-controller.php`, immediately after the enriched/fallback author block (after line ~1144, before the `$data = array(...)` assembly) insert the single masking override:
```php
		// Anonymous masking — one place, overrides both the enriched batch path
		// and the per-item lookup above. Real author_id is kept on the row.
		$display = \Jetonomy\Author::for_display( $author_id, $post );
		if ( 0 === $display['id'] ) {
			$author_id     = 0;
			$author_name   = $display['name'];
			$author_avatar = $display['avatar'];
			$author_login  = '';
			$trust_level   = 0;
			$reputation    = 0;
			$profile_url   = $display['url'];
		}
```

- [ ] **Step 3b: Override at the end of `prepare_reply()`**

In `includes/api/class-replies-controller.php`, after the author block (after line ~770):
```php
		$display = \Jetonomy\Author::for_display( $author_id, $reply );
		if ( 0 === $display['id'] ) {
			$author_id     = 0;
			$author_name   = $display['name'];
			$author_avatar = $display['avatar'];
			$author_login  = '';
			$trust_level   = 0;
			$reputation    = 0;
			$profile_url   = $display['url'];
		}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite=unit --filter PostsAnonymousRestTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/api/class-posts-controller.php includes/api/class-replies-controller.php tests/integration/api/PostsAnonymousRestTest.php
git commit -m "feat(anon): mask author in REST prepare_post/prepare_reply (covers enriched + fallback paths)"
```

---

### Task 6: RSS feed `dc:creator`

**Files:**
- Modify: `includes/class-feed.php:80,88`

**Interfaces:**
- Consumes: `\Jetonomy\Author::for_display()`.

- [ ] **Step 1: Replace the author lookup (line 80) and render (line 88)**

Line 80 becomes:
```php
$display = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
```
Line 88 (`dc:creator`) becomes — preserving the feed's existing "Member" fallback for a real-but-deleted author while emitting "Anonymous" for a flagged row (the resolver returns the non-empty "Anonymous", so `?:` keeps it):
```php
<dc:creator><?php echo esc_html( '' !== $display['name'] ? $display['name'] : __( 'Member', 'jetonomy' ) ); ?></dc:creator>
```

- [ ] **Step 2: Verify**

Run: `wp jetonomy qa-actions` (feed smoke stays green). Manually fetch the space RSS URL for a space containing a flagged post; confirm `dc:creator` reads `Anonymous`.

- [ ] **Step 3: Commit**

```bash
git add includes/class-feed.php
git commit -m "feat(anon): mask RSS dc:creator for anonymous posts"
```

---

### Task 7: Notifications actor (notifier + mentions)

**Files:**
- Modify: `includes/notifications/class-notifier.php:1476-1479` (and the seed call sites that pass a raw author id at 520/540/587/612), `includes/class-mentions.php:57-59`

**Interfaces:**
- Consumes: `\Jetonomy\Author::for_display()`. The recipient's own name (line 1094 `{user}`) is NEVER masked — only the actor.

- [ ] **Step 1: Mask the actor name in `class-notifier.php`**

`get_display_name()` takes only a user id, so mask at the call sites where the object is known. In the reply-notification context builder (line 612) change:
```php
'actor_display_name' => \Jetonomy\Author::for_display( (int) $reply->author_id, $reply )['name'] ?: __( 'Someone', 'jetonomy' ),
```
Apply the same substitution at the other reply/mention actor sites (lines 520, 540, 587) using the reply/post object in scope. Leave `get_display_name()` itself (used for the recipient and other non-actor lookups) unchanged.

- [ ] **Step 2: Mask the mentions actor in `class-mentions.php`**

Lines 57-59 — the mentions notifier already receives `$actor_id`, `$object_type`, `$object_id`. Load the object to read the flag:
```php
	public static function notify( array $user_ids, int $actor_id, string $object_type, int $object_id, string $context_title, ?int $space_id = null, bool $is_private = false ): void {
		$object     = 'reply' === $object_type
			? \Jetonomy\Models\Reply::find( $object_id )
			: \Jetonomy\Models\Post::find( $object_id );
		$actor_name = \Jetonomy\Author::for_display( $actor_id, $object )['name'] ?: __( 'Someone', 'jetonomy' );
```

- [ ] **Step 3: Verify**

Run: `wp jetonomy qa-actions`. Add a lightweight assertion in `tests/pro/AnonymousRevealTest.php` (Task 16) or a notifier unit test: a reply on a flagged post produces a notification whose `actor_display_name` is "Anonymous". Manually: reply anonymously, confirm the topic author's notification reads "Anonymous replied".

- [ ] **Step 4: Commit**

```bash
git add includes/notifications/class-notifier.php includes/class-mentions.php
git commit -m "feat(anon): mask notification + mention actor name for anonymous authors"
```

---

### Task 8: BuddyPress integration

**Files:**
- Modify: `includes/integrations/class-buddypress.php:343` (broadcast), `:225-241` (action string), `:827,840` (profile-tab list)

**Interfaces:**
- Consumes: `\Jetonomy\Author::for_display()`. Free honors the flag: because a BP activity row's `user_id` is a native field driving avatar/permalink/notifications that `for_display()` cannot mask post-hoc, the **broadcast is suppressed** for anonymous posts (leak-proof by omission, matching the privacy-grade promise). Existing per-user logic (counts, auto-join) is unaffected.

**Spec ambiguity resolved:** §6.8 says the BP actor/permalink "must not expose identity." Since BP renders identity from the real `user_id`, the only reliably leak-proof choice without inventing a system user is to **not create the activity item** for anonymous posts. Documented here as the intended behavior.

- [ ] **Step 1: Suppress the broadcast for anonymous posts**

At the top of the broadcast method (immediately before the `bp_activity_add( ... 'user_id' => (int) $post->author_id ... )` at line 343), guard:
```php
		// Anonymous posts are never broadcast to the BP activity stream — a BP
		// activity row's user_id natively drives avatar/permalink/notifications
		// and cannot be masked after the fact. No activity = no leak.
		if ( ! empty( $post->is_anonymous ) ) {
			return;
		}
```

- [ ] **Step 2: Defensive mask on the action string**

`format_activity_action()` (lines 225-241) — if an anonymous item ever reaches here (legacy row, third-party call), fall back to a non-identifying actor. After `$user_link = bp_core_get_userlink( (int) $activity->user_id );` add:
```php
		$jt_post = \Jetonomy\Models\Post::find( (int) ( $activity->secondary_item_id ?? 0 ) );
		if ( $jt_post && ! empty( $jt_post->is_anonymous ) ) {
			$user_link = esc_html__( 'Anonymous', 'jetonomy' );
		}
```
(Adjust `secondary_item_id` to whichever activity meta stores the post id in this integration — verify against the `bp_activity_add()` args at line 343.)

- [ ] **Step 3: Mask the BP profile-tab topic list**

Line 827/840 — replace `get_userdata()` + display_name with the resolver:
```php
$jt_display = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
...
echo '<span>' . esc_html( '' !== $jt_display['name'] ? $jt_display['name'] : __( 'Anonymous', 'jetonomy' ) ) . '</span>';
```

- [ ] **Step 4: Verify** (only if BuddyPress is active on the test site)

Post anonymously into a BP-paired space; confirm no activity item is created and the BP profile "Forums" tab shows "Anonymous". If BP is not installed, run `wp jetonomy qa-actions` and note the surface is guarded.

- [ ] **Step 5: Commit**

```bash
git add includes/integrations/class-buddypress.php
git commit -m "feat(anon): suppress BP broadcast + mask BP action/profile list for anonymous posts"
```

---

### Task 9: Public profile author streams (spec §6.9)

**Files:**
- Modify: `includes/models/class-post.php:605-632` (`list_by_author`), `includes/api/class-users-controller.php:439,452`

**Interfaces:**
- Produces: an anonymous post never appears under the real author's public profile stream. Implemented at the query level (`AND p.is_anonymous = 0`) so both the template profile view and the REST profile endpoint are covered.

- [ ] **Step 1: Exclude anonymous rows in `Post::list_by_author()`**

In the `SELECT ... WHERE p.author_id = %d AND p.status = 'publish'{$gate_sql}` query (lines 632-…), add the flag guard:
```php
				 WHERE p.author_id = %d AND p.status = 'publish' AND p.is_anonymous = 0{$gate_sql}
```

- [ ] **Step 2: Exclude in the users-controller profile queries**

`includes/api/class-users-controller.php` lines 439 and 452 — both `WHERE p.author_id = %d AND p.status = 'publish'{$gate_sql}` clauses get `AND p.is_anonymous = 0`:
```php
				 WHERE p.author_id = %d AND p.status = 'publish' AND p.is_anonymous = 0{$gate_sql}
```

- [ ] **Step 3: Verify**

Run: `wp jetonomy qa-actions`. Add a model unit test: create one flagged + one normal post by the same user, assert `Post::list_by_author( $uid )` returns only the normal post. Manually load `/community/u/:login/` — the anonymous topic is absent.

- [ ] **Step 4: Commit**

```bash
git add includes/models/class-post.php includes/api/class-users-controller.php
git commit -m "feat(anon): exclude anonymous posts from public profile author streams"
```

---

### Task 10: Search author-filter exclusion (spec §6.5, §6.10)

**Files:**
- Modify: `includes/api/class-search-controller.php:258-260,374-376,495-497,562-564`

**Interfaces:**
- Produces: an anonymous post is not discoverable by filtering `?author=<real name>` / `?author_id=<id>`. Search result *rows* are already masked because they render through the post/reply prepare paths (Task 5); this task closes the inbound-filter correlation leak.

- [ ] **Step 1: Guard each author-filtered WHERE clause**

At each `p.author_id = %d` / `r.author_id = %d` clause used for the author filter (lines 258-260, 374-376, 495-497, 562-564), append the flag guard, e.g.:
```php
$where .= $wpdb->prepare( ' AND p.author_id = %d AND p.is_anonymous = 0', $author_id );
```
(Match the exact clause-building idiom already used at each site; only add `AND <alias>.is_anonymous = 0`.)

- [ ] **Step 2: Verify**

Run: `wp jetonomy qa-actions`. Manually: create a flagged post by user A, search `?author=<A's login>`; the anonymous post must not appear. A normal post by A still appears.

- [ ] **Step 3: Commit**

```bash
git add includes/api/class-search-controller.php
git commit -m "feat(anon): exclude anonymous posts from author-filtered search discovery"
```

> **§6.10 mention-autocomplete & §6.11 vote/reaction attribution — resolved without code:** `GET jetonomy/v1/users/suggest` lists a space's members generally; it is not scoped to a post's author, so it does not reveal *who* authored anonymously — the actual mention leak (an anonymous author's outbound mention notification) is masked in Task 7. Vote/reaction attribution surfaces render the post/reply author through the card + REST paths (Tasks 4-5); voter lists are a separate concern and unchanged. Both are covered by the leak matrix in Task 20; no new code.

---

# TASK GROUP 3 — PRO EXTENSION (`jetonomy-pro/includes/extensions/anonymous-posting/`)

### Task 11: Extension skeleton + registration + Gate

**Files:**
- Create: `jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php`
- Create: `jetonomy-pro/includes/extensions/anonymous-posting/class-gate.php`
- Test: `tests/pro/AnonymousGateTest.php`

**Interfaces:**
- Produces: class `Jetonomy_Pro\Extensions\Anonymous_Posting\Extension` (auto-discovered by `Jetonomy_Pro::load_extensions()` from dir `anonymous-posting`), booting only when its id `anonymous-posting` is in the `jetonomy_pro_extensions` option. `Gate::global_enabled(): bool`, `Gate::space_allows( int $space_id ): bool`, `Gate::can_author_anonymously( int $space_id, int $user_id ): bool` — the single source of truth for both gates.
- Consumes: `Jetonomy_Pro\Extension` base (`meta/boot/activate/is_enabled/get_id`), `\Jetonomy\Models\Space::get_settings()`.

- [ ] **Step 1: Write the failing test**

`tests/pro/AnonymousGateTest.php`:
```php
<?php
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy_Pro\Extensions\Anonymous_Posting\Gate;

class AnonymousGateTest extends WP_UnitTestCase {

	private int $space_id;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$cat            = Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$this->space_id = Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		$this->user_id  = self::factory()->user->create();
		delete_option( 'jetonomy_pro_anonymous_enabled' );
	}

	public function test_gate_requires_global_and_space_and_user(): void {
		// Global off, space off.
		$this->assertFalse( Gate::can_author_anonymously( $this->space_id, $this->user_id ) );

		// Global on only.
		update_option( 'jetonomy_pro_anonymous_enabled', true );
		$this->assertFalse( Gate::can_author_anonymously( $this->space_id, $this->user_id ) );

		// Global on + space on.
		Space::update( $this->space_id, array( 'settings' => wp_json_encode( array( 'allow_anonymous' => true ) ) ) );
		$this->assertTrue( Gate::can_author_anonymously( $this->space_id, $this->user_id ) );

		// Guest never allowed.
		$this->assertFalse( Gate::can_author_anonymously( $this->space_id, 0 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite=pro --filter AnonymousGateTest`
Expected: FAIL — `Class "Jetonomy_Pro\Extensions\Anonymous_Posting\Gate" not found`.

- [ ] **Step 3a: Write the Gate**

`jetonomy-pro/includes/extensions/anonymous-posting/class-gate.php`:
```php
<?php
namespace Jetonomy_Pro\Extensions\Anonymous_Posting;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the two anonymous-posting gates: a global master
 * switch AND a per-space opt-in. Both must be ON, and the author must be a
 * logged-in member, for anonymous authoring to be allowed.
 */
class Gate {

	public const OPTION = 'jetonomy_pro_anonymous_enabled';

	/** Global master switch. */
	public static function global_enabled(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/** Per-space opt-in, stored in the space settings JSON. */
	public static function space_allows( int $space_id ): bool {
		if ( $space_id <= 0 ) {
			return false;
		}
		$settings = \Jetonomy\Models\Space::get_settings( $space_id );
		return ! empty( $settings['allow_anonymous'] );
	}

	/** Both gates + logged-in member. */
	public static function can_author_anonymously( int $space_id, int $user_id ): bool {
		return $user_id > 0 && self::global_enabled() && self::space_allows( $space_id );
	}
}
```

- [ ] **Step 3b: Write the extension skeleton**

`jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php`:
```php
<?php
namespace Jetonomy_Pro\Extensions\Anonymous_Posting;

defined( 'ABSPATH' ) || exit;

use Jetonomy_Pro\Extension as Base_Extension;

/**
 * Anonymous Posting extension — lets members author topics and replies with
 * their name + avatar hidden from other members, gated by a global switch and
 * a per-space opt-in, with a site-admin-only, logged reveal path.
 */
class Extension extends Base_Extension {

	/** {@inheritdoc} */
	public function meta(): array {
		return array(
			'id'          => 'anonymous-posting',
			'name'        => __( 'Anonymous Posting', 'jetonomy-pro' ),
			'description' => __( 'Let members post topics and replies anonymously, per space, with an audited admin reveal.', 'jetonomy-pro' ),
			'version'     => '1.0.0',
			'requires'    => 'growth',
			'category'    => __( 'Privacy', 'jetonomy-pro' ),
			'depends_on'  => array(),
		);
	}

	/** {@inheritdoc} */
	public function activate(): void {
		if ( false === get_option( Gate::OPTION ) ) {
			add_option( Gate::OPTION, false );
		}
	}

	/** {@inheritdoc} */
	public function boot(): void {
		// Write enforcement (Task 12).
		add_filter( 'jetonomy_before_create_post', array( $this, 'enforce_post_anonymity' ), 10, 3 );
		add_filter( 'jetonomy_before_create_reply', array( $this, 'enforce_reply_anonymity' ), 10, 3 );

		// Compose toggles (Task 13).
		add_action( 'jetonomy_new_post_fields', array( $this, 'render_new_post_toggle' ), 20, 1 );
		add_action( 'jetonomy_composer_toolbar', array( $this, 'render_reply_toggle' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Reveal (Task 16) + REST (Task 17).
		( new Reveal() )->boot();
		add_action( 'rest_api_init', array( new Rest(), 'register_routes' ) );

		// Admin settings (Tasks 14-15).
		if ( is_admin() ) {
			add_action( 'jetonomy_admin_settings_tabs', array( $this, 'add_settings_tab' ) );
			add_action( 'jetonomy_admin_settings_tab_content', array( $this, 'render_settings_tab' ) );
			add_action( 'jetonomy_admin_space_edit_tabs', array( $this, 'render_space_tab_link' ) );
			add_action( 'jetonomy_admin_space_edit_tab_content', array( $this, 'render_space_tab_content' ), 10, 2 );
			add_action( 'admin_init', array( $this, 'save_global_setting' ) );
		}
	}

	// --- method bodies added in Tasks 12-16 ---
}
```
(The referenced `Reveal` and `Rest` classes land in Tasks 16-17; add empty `render_*`/`enforce_*`/`save_*` stubs now only if the boot smoke needs them — otherwise implement each in its task. To keep the boot smoke green from this commit, add minimal empty method bodies for `enforce_post_anonymity`/`enforce_reply_anonymity` returning `$data`, and no-op `render_*`/`enqueue_assets`/`save_global_setting`; each task replaces the stub with the real body.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite=pro --filter AnonymousGateTest`
Expected: PASS. Also run the Pro boot smoke: `php ../jetonomy-pro/tools/smoke-test.php` → no fatal.

- [ ] **Step 5: Commit**

```bash
git add ../jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php ../jetonomy-pro/includes/extensions/anonymous-posting/class-gate.php tests/pro/AnonymousGateTest.php
git commit -m "feat(anon-pro): scaffold anonymous-posting extension + Gate (global + per-space single source of truth)"
```

---

### Task 12: Write enforcement (never trust the client flag)

**Files:**
- Modify: `jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php` (implement `enforce_post_anonymity` / `enforce_reply_anonymity`)
- Test: `tests/pro/AnonymousGateTest.php` (extend)

**Interfaces:**
- Consumes: `jetonomy_before_create_post` ($data,$author_id,$space_id), `jetonomy_before_create_reply` ($data,$author_id,$post_id) — both already fired by free; `Gate::can_author_anonymously()`; `\Jetonomy\Models\Post::find()` (to resolve a reply's space).
- Produces: `$data['is_anonymous']` is `1` only when the request asked for it AND all gates pass; forced to `0` otherwise.

- [ ] **Step 1: Write the failing test** (append to `AnonymousGateTest`)

```php
	public function test_enforcement_forces_flag_off_when_space_disallows(): void {
		update_option( 'jetonomy_pro_anonymous_enabled', true ); // global on, space OFF
		$ext  = new \Jetonomy_Pro\Extensions\Anonymous_Posting\Extension();
		$data = $ext->enforce_post_anonymity( array( 'is_anonymous' => 1 ), $this->user_id, $this->space_id );
		$this->assertSame( 0, $data['is_anonymous'] );
	}

	public function test_enforcement_sets_flag_when_all_gates_pass(): void {
		update_option( 'jetonomy_pro_anonymous_enabled', true );
		Space::update( $this->space_id, array( 'settings' => wp_json_encode( array( 'allow_anonymous' => true ) ) ) );
		$ext  = new \Jetonomy_Pro\Extensions\Anonymous_Posting\Extension();
		$data = $ext->enforce_post_anonymity( array( 'is_anonymous' => 1 ), $this->user_id, $this->space_id );
		$this->assertSame( 1, $data['is_anonymous'] );
	}

	public function test_enforcement_ignores_client_flag_without_request(): void {
		update_option( 'jetonomy_pro_anonymous_enabled', true );
		Space::update( $this->space_id, array( 'settings' => wp_json_encode( array( 'allow_anonymous' => true ) ) ) );
		$ext  = new \Jetonomy_Pro\Extensions\Anonymous_Posting\Extension();
		$data = $ext->enforce_post_anonymity( array(), $this->user_id, $this->space_id ); // no is_anonymous requested
		$this->assertSame( 0, $data['is_anonymous'] );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite=pro --filter AnonymousGateTest`
Expected: FAIL — stub returns `$data` unchanged, so `is_anonymous` is `1`/unset, not the enforced value.

- [ ] **Step 3: Implement the two filters**

Replace the stubs in `class-extension.php`:
```php
	/**
	 * Authoritative anonymity decision for a new post. Never trusts the client
	 * flag alone — re-validates all gates server-side.
	 *
	 * @param array|\WP_Error $data      Post data (WP_Error aborts the create).
	 * @param int             $author_id Author user ID.
	 * @param int             $space_id  Target space ID.
	 * @return array|\WP_Error
	 */
	public function enforce_post_anonymity( $data, int $author_id, int $space_id ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$requested            = ! empty( $data['is_anonymous'] );
		$data['is_anonymous'] = ( $requested && Gate::can_author_anonymously( $space_id, $author_id ) ) ? 1 : 0;
		return $data;
	}

	/**
	 * Authoritative anonymity decision for a new reply. Resolves the reply's
	 * space from its parent post, then applies the same gate.
	 *
	 * @param array|\WP_Error $data      Reply data.
	 * @param int             $author_id Author user ID.
	 * @param int             $post_id   Parent post ID.
	 * @return array|\WP_Error
	 */
	public function enforce_reply_anonymity( $data, int $author_id, int $post_id ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$post                 = \Jetonomy\Models\Post::find( $post_id );
		$space_id             = $post ? (int) $post->space_id : 0;
		$requested            = ! empty( $data['is_anonymous'] );
		$data['is_anonymous'] = ( $requested && Gate::can_author_anonymously( $space_id, $author_id ) ) ? 1 : 0;
		return $data;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite=pro --filter AnonymousGateTest`
Expected: PASS (all gate + enforcement tests).

- [ ] **Step 5: Commit**

```bash
git add ../jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php tests/pro/AnonymousGateTest.php
git commit -m "feat(anon-pro): server-side write enforcement of is_anonymous (client flag never trusted)"
```

---

### Task 13: Compose toggles (new-topic + reply) + payload wiring

**Files:**
- Modify: `jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php` (`render_new_post_toggle`, `render_reply_toggle`, `enqueue_assets`)
- Create: `jetonomy-pro/includes/extensions/anonymous-posting/assets/anonymous.js`
- Verify: Playwright MCP (390px)

**Interfaces:**
- Consumes: **existing free hooks** `jetonomy_new_post_fields` ($space) fired at `templates/partials/compose-fields.php:178` (new-topic surface) and `jetonomy_composer_toolbar` ($post_id,$reply_to) fired at `templates/partials/composer.php:49` (reply surface); `Gate::can_author_anonymously()`. The JS adds `is_anonymous` to the create request body (Task 3 free controllers read it).

**Spec ambiguity resolved:** the spec said the toggle renders via `jetonomy_composer_toolbar` as a "shared post+reply composer." It is **reply-only**; new-topic creation uses `compose-fields.php`, which fires its own `jetonomy_new_post_fields` hook (the free comment there already anticipates "a future … anonymous toggle"). Both hooks already exist, so no new free hook is needed — the toggle renders on `jetonomy_new_post_fields` for topics and `jetonomy_composer_toolbar` for replies.

**Compose toolbar wireframe (both gates ON for this space):**
```
┌─ New topic ─────────────────────────────────────┐   ┌─ Reply composer ───────────────────────────┐
│ Title  [_______________________________]        │   │ [ B  I  </>  🔗  ""  🖼  ☐ Reply anonymously ]│
│ Body   [ B I </> 🔗 "" 🖼 ]                       │   │ ┌──────────────────────────────────────────┐│
│        ┌────────────────────────────────────┐   │   │ │ Write your reply… (Markdown supported)   ││
│        │ Write your topic…                   │   │   │ └──────────────────────────────────────────┘│
│        └────────────────────────────────────┘   │   │ Markdown · Ctrl+Enter        [ Post Reply ] │
│        ☐ Post anonymously                        │   └─────────────────────────────────────────────┘
│                                   [ Publish ]    │
└─────────────────────────────────────────────────┘
   checkbox is a real <input type="checkbox">, keyboard-reachable, hidden unless BOTH gates pass
```

- [ ] **Step 1: Render the new-topic toggle**

Add to `class-extension.php`:
```php
	public function render_new_post_toggle( $space ): void {
		$space_id = is_object( $space ) ? (int) ( $space->id ?? 0 ) : (int) $space;
		if ( ! Gate::can_author_anonymously( $space_id, get_current_user_id() ) ) {
			return;
		}
		?>
		<label class="jt-anon-toggle">
			<input type="checkbox" name="is_anonymous" value="1"
				class="jt-anon-toggle__input" data-jt-anon-toggle>
			<span class="jt-anon-toggle__label"><?php esc_html_e( 'Post anonymously', 'jetonomy-pro' ); ?></span>
		</label>
		<?php
	}
```

- [ ] **Step 2: Render the reply toggle**

```php
	public function render_reply_toggle( $post_id, $reply_to ): void {
		unset( $reply_to );
		$post = \Jetonomy\Models\Post::find( (int) $post_id );
		if ( ! $post || ! Gate::can_author_anonymously( (int) $post->space_id, get_current_user_id() ) ) {
			return;
		}
		?>
		<label class="jt-anon-toggle jt-anon-toggle--reply">
			<input type="checkbox" class="jt-anon-toggle__input" data-jt-anon-toggle
				aria-label="<?php esc_attr_e( 'Reply anonymously', 'jetonomy-pro' ); ?>">
			<span class="jt-anon-toggle__label"><?php esc_html_e( 'Reply anonymously', 'jetonomy-pro' ); ?></span>
		</label>
		<?php
	}
```

- [ ] **Step 3: Enqueue the JS + token-driven CSS**

```php
	public function enqueue_assets(): void {
		if ( ! get_query_var( 'jetonomy_route' ) ) {
			return;
		}
		wp_enqueue_script(
			'jetonomy-anonymous',
			plugins_url( 'assets/anonymous.js', __FILE__ ),
			array( 'jetonomy-view' ),
			'1.0.0',
			true
		);
		wp_add_inline_style( 'jetonomy', '.jt-anon-toggle{display:inline-flex;align-items:center;gap:var(--jt-radius-sm);cursor:pointer;color:var(--jt-text-secondary);font-size:.875rem;margin-inline-start:auto}.jt-anon-toggle__input{accent-color:var(--jt-accent)}.jt-anon-toggle:focus-within{outline:2px solid var(--jt-accent);outline-offset:2px;border-radius:var(--jt-radius-sm)}' );
	}
```

- [ ] **Step 4: Write the payload JS**

`jetonomy-pro/includes/extensions/anonymous-posting/assets/anonymous.js`:
```js
/**
 * Anonymous posting — include the is_anonymous flag in the create payload.
 *
 * The reply composer submits through the Interactivity API store; the new-topic
 * form posts its own fields. Both read the nearest [data-jt-anon-toggle]
 * checkbox at submit time. The server re-validates the flag (never trusted).
 */
( function () {
	'use strict';

	// New-topic form: the checkbox has name="is_anonymous" so it posts natively.
	// Reply composer: mirror the checkbox into the request the store sends.
	document.addEventListener( 'jetonomy:before-reply-submit', function ( e ) {
		var editor = e.target && e.target.closest ? e.target.closest( '.jt-editor' ) : document;
		var box    = ( editor || document ).querySelector( '[data-jt-anon-toggle]' );
		if ( box && box.checked && e.detail && e.detail.payload ) {
			e.detail.payload.is_anonymous = 1;
		}
	} );
} )();
```
(If the reply store does not yet emit `jetonomy:before-reply-submit`, add that `CustomEvent` dispatch in the store's `submitReply` action in free `assets/js/view.js` immediately before the `restFetch` POST — this is a generic, non-anon extension point consistent with the frontend-interactivity standard. Verify the exact store hook name before wiring; adjust the event name in both places to match.)

- [ ] **Step 5: Browser-verify at 390px**

Enable the extension, turn global ON + space opt-in ON. Playwright MCP: navigate to `/community/s/:slug/new/` and to a topic, `browser_resize` 390px, confirm the checkbox renders, is keyboard-reachable (Tab + Space toggles), and is ABSENT when either gate is OFF. Submit anonymously; via `browser_network_requests` confirm the POST body carries `is_anonymous`. Screenshot to `~/Documents/work-artifacts/screenshots/2026-07/`.

- [ ] **Step 6: Commit**

```bash
git add ../jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php ../jetonomy-pro/includes/extensions/anonymous-posting/assets/anonymous.js
git commit -m "feat(anon-pro): compose toggles on new-topic + reply, gated, with payload wiring"
```

---

### Task 14: Global setting panel

**Files:**
- Modify: `jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php` (`add_settings_tab`, `render_settings_tab`, `save_global_setting`)
- Create: `jetonomy-pro/includes/extensions/anonymous-posting/views/settings.php`
- Verify: Playwright MCP

**Interfaces:**
- Consumes: `jetonomy_admin_settings_tabs` ($active_tab) fired at `includes/admin/views/settings.php:25`, `jetonomy_admin_settings_tab_content` ($active_tab) at `:34` — the reactions/seo-pro save idiom (own `<form>`, own nonce, `update_option`, no options.php).
- Produces: `jetonomy_pro_anonymous_enabled` (bool) option.

**Global setting panel wireframe:**
```
Jetonomy ▸ Settings ▸ [ General | Permissions | … | Anonymous ]
┌─ Anonymous Posting ─────────────────────────────────────────┐
│  Master switch for anonymous authoring.                     │
│                                                             │
│  [✔] Enable anonymous posting                               │
│      Members can post/reply anonymously ONLY in spaces      │
│      where a space admin has also turned it on.             │
│                                                             │
│                                   [ Save Changes ]          │
└─────────────────────────────────────────────────────────────┘
```

- [ ] **Step 1: Tab link + content + save**

```php
	public function add_settings_tab( string $active_tab ): void {
		$url = admin_url( 'admin.php?page=jetonomy-settings&tab=anonymous' );
		?>
		<a href="<?php echo esc_url( $url ); ?>"
			class="nav-tab <?php echo 'anonymous' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Anonymous', 'jetonomy-pro' ); ?>
		</a>
		<?php
	}

	public function render_settings_tab( string $active_tab ): void {
		if ( 'anonymous' !== $active_tab ) {
			return;
		}
		$enabled = Gate::global_enabled();
		require __DIR__ . '/views/settings.php';
	}

	public function save_global_setting(): void {
		if ( ! isset( $_POST['_jt_anon_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_jt_anon_nonce'] ) ), 'jetonomy_pro_anonymous_settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_option( Gate::OPTION, ! empty( $_POST['jetonomy_pro_anonymous_enabled'] ) );
	}
```

- [ ] **Step 2: The view**

`jetonomy-pro/includes/extensions/anonymous-posting/views/settings.php`:
```php
<?php
/**
 * Global anonymous-posting setting.
 *
 * @package Jetonomy_Pro
 * @var bool $enabled Current master-switch state.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="jt-settings-card">
	<div class="jt-settings-card__head">
		<p class="jt-settings-card__title"><?php esc_html_e( 'Anonymous Posting', 'jetonomy-pro' ); ?></p>
		<p class="jt-settings-card__desc"><?php esc_html_e( 'Master switch for anonymous authoring. Members can only post anonymously in spaces where a space admin has also turned it on.', 'jetonomy-pro' ); ?></p>
	</div>
	<form method="post" action="">
		<?php wp_nonce_field( 'jetonomy_pro_anonymous_settings', '_jt_anon_nonce' ); ?>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable anonymous posting', 'jetonomy-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="jetonomy_pro_anonymous_enabled" value="1" <?php checked( $enabled ); ?>>
							<?php esc_html_e( 'Allow members to post topics and replies anonymously.', 'jetonomy-pro' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( __( 'Save Changes', 'jetonomy-pro' ) ); ?>
	</form>
</div>
```

- [ ] **Step 3: Verify**

Playwright MCP: navigate to `admin.php?page=jetonomy-settings&tab=anonymous`, toggle on, Save, reload, confirm persisted (`get_option` via `wp option get jetonomy_pro_anonymous_enabled`).

- [ ] **Step 4: Commit**

```bash
git add ../jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php ../jetonomy-pro/includes/extensions/anonymous-posting/views/settings.php
git commit -m "feat(anon-pro): global anonymous-posting settings tab"
```

---

### Task 15: Per-space opt-in field

**Files:**
- Modify: `jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php` (`render_space_tab_link`, `render_space_tab_content`)
- Create: `jetonomy-pro/includes/extensions/anonymous-posting/views/space-setting.php`
- Verify: Playwright MCP

**Interfaces:**
- Consumes: `jetonomy_admin_space_edit_tabs` ($space) at `includes/admin/views/space-edit.php:57`, `jetonomy_admin_space_edit_tab_content` ($active_tab,$space) at `:552` — the **seo-pro** persist pattern (decode space settings, set key, `$wpdb->update( \Jetonomy\table('spaces') )`). Read/write also flows through the space REST payload automatically (spaces-controller merges incoming `settings` and exposes decoded `settings` at `:1129`) — no free REST change needed for app parity.
- Produces: `settings.allow_anonymous` (bool) inside the space settings JSON. No schema change.

**Per-space opt-in field wireframe:**
```
Spaces ▸ Edit "Product Feedback" ▸ [ General | Members | Access | … | Anonymous ]
┌─ Anonymous Posting ─────────────────────────────────────────┐
│  [✔] Allow anonymous posts in this space                    │
│      Requires the global switch (Settings ▸ Anonymous).     │
│      When both are on, members see a "Post anonymously"     │
│      checkbox in this space's composer.                     │
│                                        [ Save ]             │
└─────────────────────────────────────────────────────────────┘
```

- [ ] **Step 1: Tab link + content + save (seo-pro pattern)**

```php
	public function render_space_tab_link( object $space ): void {
		$url        = admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . absint( $space->id ) );
		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'general' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<a href="<?php echo esc_url( $url . '&tab=anonymous' ); ?>"
			class="nav-tab <?php echo 'anonymous' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Anonymous', 'jetonomy-pro' ); ?>
		</a>
		<?php
	}

	public function render_space_tab_content( string $active_tab, object $space ): void {
		if ( 'anonymous' !== $active_tab ) {
			return;
		}
		if ( isset( $_POST['jetonomy_pro_anon_space_save'] ) && check_admin_referer( 'jetonomy_pro_anon_space_nonce' ) ) {
			$this->save_space_setting( (int) $space->id, ! empty( $_POST['allow_anonymous'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'jetonomy-pro' ) . '</p></div>';
		}
		$settings = \Jetonomy\Models\Space::get_settings( (int) $space->id );
		$allow    = ! empty( $settings['allow_anonymous'] );
		require __DIR__ . '/views/space-setting.php';
	}

	private function save_space_setting( int $space_id, bool $allow ): void {
		$settings                     = \Jetonomy\Models\Space::get_settings( $space_id );
		$settings['allow_anonymous']  = $allow;
		\Jetonomy\Models\Space::update( $space_id, array( 'settings' => wp_json_encode( $settings ) ) );
	}
```
(Reuses `Space::get_settings()` + `Space::update()` — the model round-trips arbitrary keys, so `array_merge` semantics are preserved by reading-then-writing the whole decoded array.)

- [ ] **Step 2: The view**

`jetonomy-pro/includes/extensions/anonymous-posting/views/space-setting.php`:
```php
<?php
/**
 * Per-space anonymous opt-in.
 *
 * @package Jetonomy_Pro
 * @var bool $allow Current per-space state.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="jt-settings-card">
	<div class="jt-settings-card__head">
		<p class="jt-settings-card__title"><?php esc_html_e( 'Anonymous Posting', 'jetonomy-pro' ); ?></p>
		<p class="jt-settings-card__desc"><?php esc_html_e( 'Requires the global switch under Settings ▸ Anonymous. When both are on, members see a "Post anonymously" checkbox in this space.', 'jetonomy-pro' ); ?></p>
	</div>
	<form method="post" action="">
		<?php wp_nonce_field( 'jetonomy_pro_anon_space_nonce' ); ?>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allow anonymous posts', 'jetonomy-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="allow_anonymous" value="1" <?php checked( $allow ); ?>>
							<?php esc_html_e( 'Allow anonymous posts in this space', 'jetonomy-pro' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( __( 'Save', 'jetonomy-pro' ), 'primary', 'jetonomy_pro_anon_space_save' ); ?>
	</form>
</div>
```

- [ ] **Step 3: Verify**

Playwright MCP: navigate to a space edit screen, open the Anonymous tab, toggle on, Save, reload, confirm persisted. Verify via REST: `GET /jetonomy/v1/spaces/:id` returns `settings.allow_anonymous: true`; `PATCH` with `{"settings":{"allow_anonymous":false}}` clears it (proves app parity through the existing merge).

- [ ] **Step 4: Commit**

```bash
git add ../jetonomy-pro/includes/extensions/anonymous-posting/class-extension.php ../jetonomy-pro/includes/extensions/anonymous-posting/views/space-setting.php
git commit -m "feat(anon-pro): per-space allow_anonymous opt-in (space settings JSON, REST parity)"
```

---

### Task 16: Reveal + audit logging

**Files:**
- Create: `jetonomy-pro/includes/extensions/anonymous-posting/class-reveal.php`
- Modify: `class-extension.php` boot (already wires `( new Reveal() )->boot();`)
- Test: `tests/pro/AnonymousRevealTest.php`

**Interfaces:**
- Produces: `Reveal::boot()` registers `add_filter( 'jetonomy_author_can_reveal', ... , 10, 3 )` returning true ONLY for `manage_options` users INSIDE an explicit reveal context; `Reveal::reveal( string $object_type, int $object_id ): array` performs the reveal (sets context, resolves real author, logs, returns the real identity); `Reveal::render_post_reveal_button( $post )` on `jetonomy_post_actions` and reply equivalent. Explicit-reveal context means normal admin browsing still shows "Anonymous".
- Consumes: `\Jetonomy\Author::for_display()`, `\Jetonomy\Models\ActivityLog::log()`, `\Jetonomy\Models\Post::find()` / `Reply::find()`.

**Admin single-post reveal wireframe (site admin viewing an anonymous topic):**
```
┌───────────────────────────────────────────────┐
│  ( 🙎 )  Anonymous · 2 hours ago               │
│                                                 │
│  How do I reset my 2FA device?                  │
│  …                                              │
│  ▲ 12   💬 4      [ 🔓 Reveal author ]  ← admin only, aria-labelled <button>
└───────────────────────────────────────────────┘
       click → POST /jetonomy/v1/anonymous/reveal → logs + swaps label to "Revealed: Jane Doe"
```

- [ ] **Step 1: Write the failing test**

`tests/pro/AnonymousRevealTest.php`:
```php
<?php
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Author;
use Jetonomy_Pro\Extensions\Anonymous_Posting\Reveal;

class AnonymousRevealTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		( new Reveal() )->boot();
	}

	private function make_anon_post( int $author ): int {
		$cat   = Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		return Post::create( array( 'space_id' => $space, 'author_id' => $author, 'title' => 'T', 'content' => 'c', 'is_anonymous' => 1 ) );
	}

	public function test_admin_browsing_still_sees_anonymous(): void {
		$author = self::factory()->user->create( array( 'display_name' => 'Jane Doe' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post = Post::find( $this->make_anon_post( $author ) );
		// No explicit reveal context → masked even for an admin.
		$this->assertSame( 'Anonymous', Author::for_display( $author, $post )['name'] );
	}

	public function test_explicit_reveal_returns_real_author_and_logs(): void {
		global $wpdb;
		$author = self::factory()->user->create( array( 'display_name' => 'Jane Doe' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$pid = $this->make_anon_post( $author );

		$result = ( new Reveal() )->reveal( 'post', $pid );

		$this->assertSame( $author, $result['id'] );
		$this->assertSame( 'Jane Doe', $result['name'] );

		$logged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_activity_log WHERE action = %s AND object_id = %d",
				'anonymous_author_revealed',
				$pid
			)
		);
		$this->assertSame( 1, $logged );
	}

	public function test_non_admin_reveal_is_denied(): void {
		$author = self::factory()->user->create();
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member );
		$pid    = $this->make_anon_post( $author );
		$result = ( new Reveal() )->reveal( 'post', $pid );
		$this->assertArrayHasKey( 'error', $result );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite=pro --filter AnonymousRevealTest`
Expected: FAIL — `Class "...Reveal" not found`.

- [ ] **Step 3: Write the Reveal class**

`jetonomy-pro/includes/extensions/anonymous-posting/class-reveal.php`:
```php
<?php
namespace Jetonomy_Pro\Extensions\Anonymous_Posting;

defined( 'ABSPATH' ) || exit;

/**
 * Admin reveal of an anonymous author, with an audit-log entry per reveal.
 *
 * Reveal is an EXPLICIT action: the jetonomy_author_can_reveal filter only
 * returns true while an admin is actively revealing (self::$context true), so
 * ordinary admin browsing still shows "Anonymous".
 */
class Reveal {

	/** True only during an explicit reveal operation. */
	private static bool $context = false;

	public function boot(): void {
		add_filter( 'jetonomy_author_can_reveal', array( $this, 'can_reveal' ), 10, 3 );
		add_action( 'jetonomy_post_actions', array( $this, 'render_post_reveal_button' ), 20, 1 );
		add_action( 'jetonomy_reply_actions', array( $this, 'render_reply_reveal_button' ), 20, 1 );
	}

	/**
	 * Grant reveal only to site admins inside an explicit reveal context.
	 *
	 * @param bool        $default   Default false.
	 * @param object|null $object    Row being rendered.
	 * @param int         $viewer_id Current user ID.
	 */
	public function can_reveal( bool $default, ?object $object, int $viewer_id ): bool {
		unset( $object );
		if ( ! self::$context ) {
			return $default;
		}
		return user_can( $viewer_id, 'manage_options' );
	}

	/**
	 * Perform an explicit reveal: resolve the real author, log it, return it.
	 *
	 * @param string $object_type 'post' | 'reply'.
	 * @param int    $object_id   Row ID.
	 * @return array{id:int,name:string}|array{error:string}
	 */
	public function reveal( string $object_type, int $object_id ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'forbidden' );
		}

		$row = 'reply' === $object_type
			? \Jetonomy\Models\Reply::find( $object_id )
			: \Jetonomy\Models\Post::find( $object_id );

		if ( ! $row || empty( $row->is_anonymous ) ) {
			return array( 'error' => 'not_anonymous' );
		}

		$author_id = (int) $row->author_id;

		self::$context = true;
		$display       = \Jetonomy\Author::for_display( $author_id, $row );
		self::$context = false;

		\Jetonomy\Models\ActivityLog::log(
			get_current_user_id(),
			'anonymous_author_revealed',
			$object_type,
			$object_id,
			array( 'real_author' => $author_id )
		);

		return array(
			'id'   => (int) $display['id'],
			'name' => (string) $display['name'],
		);
	}

	public function render_post_reveal_button( $post ): void {
		$this->render_button( 'post', $post );
	}

	public function render_reply_reveal_button( $reply ): void {
		$this->render_button( 'reply', $reply );
	}

	private function render_button( string $type, $row ): void {
		if ( ! is_object( $row ) || empty( $row->is_anonymous ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<button type="button" class="jt-btn jt-btn-ghost jt-anon-reveal" data-jt-reveal-type="%1$s" data-jt-reveal-id="%2$d" aria-label="%3$s">%4$s</button>',
			esc_attr( $type ),
			absint( $row->id ),
			esc_attr__( 'Reveal the anonymous author', 'jetonomy-pro' ),
			esc_html__( 'Reveal author', 'jetonomy-pro' )
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite=pro --filter AnonymousRevealTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add ../jetonomy-pro/includes/extensions/anonymous-posting/class-reveal.php tests/pro/AnonymousRevealTest.php
git commit -m "feat(anon-pro): admin reveal with explicit-context gate + activity-log audit"
```

---

### Task 17: Reveal REST endpoint

**Files:**
- Create: `jetonomy-pro/includes/extensions/anonymous-posting/class-rest.php`
- Modify: `class-extension.php` boot (already wires `add_action( 'rest_api_init', array( new Rest(), 'register_routes' ) );`)
- Modify: `jetonomy-pro/includes/extensions/anonymous-posting/assets/anonymous.js` (reveal button → endpoint)

**Interfaces:**
- Produces: `POST jetonomy/v1/anonymous/reveal` with body `{ object_type: 'post'|'reply', object_id: int }`, admin-only via `$this->rest_auth_mutation( 'manage_options' )`, returns `{ id, name }` and logs the reveal (delegates to `Reveal::reveal()`).
- Consumes: base `Jetonomy_Pro\Extension::rest_auth_mutation()`, `Reveal::reveal()`.

**Spec ambiguity resolved:** the spec text wrote `POST /jetonomy-pro/v1/anonymous/reveal`, but **every** Pro extension registers into the `jetonomy/v1` namespace (there is no `jetonomy-pro/v1` route anywhere, and `bin/audit-rest-routes.php` expects the shared namespace). The route is therefore `POST jetonomy/v1/anonymous/reveal`.

- [ ] **Step 1: Write the REST class**

`jetonomy-pro/includes/extensions/anonymous-posting/class-rest.php`:
```php
<?php
namespace Jetonomy_Pro\Extensions\Anonymous_Posting;

defined( 'ABSPATH' ) || exit;

use Jetonomy_Pro\Extension as Base_Extension;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reveal REST endpoint — the API mirror of the admin reveal affordance
 * (three-entry-point rule). Admin-only, logs every reveal.
 */
class Rest extends Base_Extension {

	/** {@inheritdoc} — this shim class only carries the base REST helper. */
	public function meta(): array {
		return array(
			'id'          => 'anonymous-posting',
			'name'        => 'Anonymous Posting REST',
			'description' => '',
			'version'     => '1.0.0',
			'requires'    => 'growth',
			'category'    => 'Privacy',
		);
	}

	/** {@inheritdoc} */
	public function boot(): void {}

	public function register_routes(): void {
		register_rest_route(
			'jetonomy/v1',
			'/anonymous/reveal',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'reveal' ),
					'permission_callback' => $this->rest_auth_mutation( 'manage_options' ),
					'args'                => array(
						'object_type' => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( 'post', 'reply' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'object_id'   => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	public function reveal( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new Reveal() )->reveal(
			(string) $request->get_param( 'object_type' ),
			(int) $request->get_param( 'object_id' )
		);

		if ( isset( $result['error'] ) ) {
			$status = 'forbidden' === $result['error'] ? 403 : 404;
			return new WP_REST_Response( array( 'success' => false, 'code' => $result['error'] ), $status );
		}

		return new WP_REST_Response( array( 'success' => true, 'author' => $result ), 200 );
	}
}
```
(Note: `Rest` extends `Base_Extension` only to inherit `rest_auth_mutation()`; its `meta()`/`boot()` are inert and it is NOT registered as a discoverable extension — only `class-extension.php` is discovered by the loader, per the `dir/class-extension.php` convention.)

- [ ] **Step 2: Wire the reveal button JS**

Append to `assets/anonymous.js`:
```js
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-jt-reveal-id]' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var body = {
			object_type: btn.getAttribute( 'data-jt-reveal-type' ),
			object_id: parseInt( btn.getAttribute( 'data-jt-reveal-id' ), 10 )
		};
		window.wp.apiFetch( {
			path: '/jetonomy/v1/anonymous/reveal',
			method: 'POST',
			data: body
		} ).then( function ( res ) {
			if ( res && res.success ) {
				btn.textContent = btn.getAttribute( 'data-jt-revealed-label' ) || ( 'Revealed: ' + res.author.name );
				btn.disabled = true;
			}
		} );
	} );
```
(Depends on `wp-api-fetch`; add it to the `enqueue_assets` dependency array: `array( 'jetonomy-view', 'wp-api-fetch' )`.)

- [ ] **Step 3: Verify**

Run: `php bin/audit-rest-routes.php ../jetonomy-pro/includes/` → OK (accepts `rest_auth_mutation`). REST test: as admin, `POST /jetonomy/v1/anonymous/reveal {object_type:'post',object_id:<flagged>}` → 200 with real author + one new `jt_activity_log` row; as subscriber → 403. Playwright MCP: as admin, load the flagged topic, click "Reveal author", confirm the label swaps to the real name.

- [ ] **Step 4: Commit**

```bash
git add ../jetonomy-pro/includes/extensions/anonymous-posting/class-rest.php ../jetonomy-pro/includes/extensions/anonymous-posting/assets/anonymous.js
git commit -m "feat(anon-pro): POST jetonomy/v1/anonymous/reveal endpoint (admin-only, logged)"
```

---

# TASK GROUP 4 — MANIFESTS, i18n/A11y/DARK-RTL, DOCS, VERIFICATION

### Task 18: Manifest updates (free + pro)

**Files:**
- Modify: `audit/manifest.json` (free), `jetonomy-pro/audit/manifest.json` (pro)

**Interfaces:** documentation only — keep manifests in sync with the shipped hooks/routes/options.

- [ ] **Step 1: Free manifest delta**

Add to the free manifest: the helper `Jetonomy\Author::for_display` (class `includes/class-author.php`); the filter `jetonomy_author_can_reveal` under `hooks_fired` (fired in `class-author.php`); the new REST param `is_anonymous` on the post + reply create routes; the new column `is_anonymous` on `jt_posts`/`jt_replies` under the tables inventory; migration `1.7.0`.

- [ ] **Step 2: Pro manifest delta**

Add to the Pro manifest: extension `anonymous-posting` (id, name, category Privacy); option `jetonomy_pro_anonymous_enabled`; per-space settings key `allow_anonymous`; REST route `POST jetonomy/v1/anonymous/reveal`; `free_filters_hooked`: `jetonomy_author_can_reveal`, `jetonomy_before_create_post`, `jetonomy_before_create_reply`; `free_actions_hooked`: `jetonomy_new_post_fields`, `jetonomy_composer_toolbar`, `jetonomy_post_actions`, `jetonomy_reply_actions`, `jetonomy_admin_settings_tabs`, `jetonomy_admin_settings_tab_content`, `jetonomy_admin_space_edit_tabs`, `jetonomy_admin_space_edit_tab_content`.

- [ ] **Step 3: Commit**

```bash
git add audit/manifest.json ../jetonomy-pro/audit/manifest.json
git commit -m "docs(anon): sync free + pro manifests with anonymous-posting feature"
```

---

### Task 19: i18n, a11y, dark-mode/RTL, customer docs

**Files:**
- Modify: `languages/jetonomy.pot` (regen), `jetonomy-pro/languages/jetonomy-pro.pot` (regen)
- Create: `docs/website/getting-started/anonymous-posting.md`
- Verify: Playwright MCP (dark mode + RTL)

- [ ] **Step 1: i18n sweep**

Confirm every new string uses the correct domain: free strings ("Anonymous", "Member") in `jetonomy`; Pro strings ("Post anonymously", "Reply anonymously", "Reveal author", "Allow anonymous posts in this space", "Enable anonymous posting") in `jetonomy-pro`. Regenerate POTs: `grunt i18n` (or `wp i18n make-pot . languages/jetonomy.pot` in each plugin). No bare/concatenated strings; JS labels use the same domains where applicable.

- [ ] **Step 2: a11y check**

Compose checkbox is a real `<input type="checkbox">` inside a `<label>`, keyboard-reachable (Tab/Space), `:focus-within` outline present. Reveal control is a `<button>` with `aria-label`. Verify with Playwright MCP keyboard navigation + `browser_snapshot` (accessibility tree shows labeled controls).

- [ ] **Step 3: dark-mode + RTL**

The inline CSS uses only `--jt-*` tokens and logical properties (`margin-inline-start`). Playwright MCP: toggle `.jt-dark` (or the theme's dark switch) and set `<html dir="rtl">`; screenshot the composer toggle + reveal button in all four combinations (light/dark × LTR/RTL) at 390px. Confirm no raw hex/px leaked (`grep -nE '#[0-9a-fA-F]{3,6}|[0-9]+px' ` over the new inline style returns nothing but token refs).

- [ ] **Step 4: Customer docs (GitHub-only, no publishing)**

`docs/website/getting-started/anonymous-posting.md`: how to enable globally (Settings ▸ Anonymous), how to opt a space in (Space ▸ Edit ▸ Anonymous), member experience (the "Post/Reply anonymously" checkbox), and the admin reveal (site admins only, every reveal is logged). Do NOT call any docs publishing/sync tool.

- [ ] **Step 5: Commit**

```bash
git add languages/jetonomy.pot ../jetonomy-pro/languages/jetonomy-pro.pot docs/website/getting-started/anonymous-posting.md
git commit -m "docs(anon): i18n POT regen + anonymous-posting customer doc"
```

---

### Task 20: Full verification (leak matrix + REST parity + regression)

**Files:** none (verification only). Uses Playwright MCP + `wp jetonomy qa-actions` + `composer test:combo`.

- [ ] **Step 1: Automated suites**

Run: `composer test:combo` (all new + existing tests green), `wp jetonomy qa-actions` (210/210), `php bin/audit-rest-routes.php includes/` and `... ../jetonomy-pro/includes/` (both OK), free+pro boot smoke.

- [ ] **Step 2: Leak matrix (Playwright MCP)**

Seed a flagged topic + flagged reply by user A. For each of the spec §6 surfaces, view as **user B (member)** and as a **space moderator** (non-admin) and confirm "Anonymous" + silhouette, no profile link, no real name in DOM (`browser_snapshot` + a `grep` of the rendered HTML for A's display name must return nothing):
  1. Feed / post card  2. Reply card (incl. quote button)  3. REST `GET /posts/:id`  4. REST `GET /replies?post_id=`  5. Search `?author=<A>` (post absent)  6. Space RSS `dc:creator`  7. Reply notification to the topic author ("Anonymous replied")  8. BuddyPress activity (no item) + BP profile tab  9. `/community/u/A/` profile stream (topic absent)  10. mention notification actor  11. post-author attribution on the card.
Do each at desktop AND 390px. Save shots to `~/Documents/work-artifacts/screenshots/2026-07/`.

- [ ] **Step 3: Admin reveal + audit**

As site admin: normal browsing shows "Anonymous"; click "Reveal author" → real name appears; confirm exactly one `jt_activity_log` row with `action = 'anonymous_author_revealed'` and `metadata.real_author = A`. Repeat via REST for parity.

- [ ] **Step 4: Regression**

Author A edits and deletes their own anonymous topic/reply (ownership checks pass; item stays anonymous through the edit). Confirm A still receives reply/vote notifications on their own anonymous post. Turn the Pro extension OFF → composer toggle disappears, no new anonymous rows can be created, existing anonymous rows stay masked (free honors the flag). Confirm post/trust counts still accrue to A server-side.

- [ ] **Step 5: Final commit / release-notes stub**

```bash
git add readme.txt ../jetonomy-pro/readme.txt
git commit -m "docs(anon): 1.7.0 changelog entries (free + pro, WooCommerce-style)"
```
Changelog bullets (both readmes, action-prefix, no emoji/em-dash):
```
* New      - Anonymous posting (Pro): members can post topics and replies anonymously, per space, with a site-admin-only audited reveal.
* New      - Global + per-space controls for anonymous authoring; both must be on for a space to allow it.
* Dev      - New free seam: Jetonomy\Author::for_display() author resolver + jetonomy_author_can_reveal filter.
```

---

## Reuse & Anti-Duplication

Every existing symbol reused (verified by reading the file), and confirmation (grep across both plugins + both manifests) that no new symbol duplicates an existing one:

**Reused (do NOT reimplement):**
- `\Jetonomy\Avatar::display_url( int, int )` — `includes/class-avatar.php:163`. The resolver wraps it for the real-author avatar; no forked avatar logic.
- `\Jetonomy\get_user_link( int, string, int, bool )` — `includes/functions.php:269`. Reused for card avatars; its existing `id 0 / unknown-user` branch (`:271-278`) already renders the `jt-avatar-anon` silhouette, so anonymous avatars need no new asset or markup.
- `\Jetonomy\get_profile_url()` — used by the resolver for the real-author URL.
- `\Jetonomy\Models\ActivityLog::log( int, string, string, int, array )` — `includes/models/class-activity-log.php:24`. Reused for the reveal audit (a deliberate direct call for an admin audit event).
- `\Jetonomy\Models\Space::get_settings( int )` / `Space::update( int, array )` — `includes/models/class-space.php:527`. Reused to read/write `allow_anonymous` in the space settings JSON (no schema change; model round-trips arbitrary keys).
- `Jetonomy_Pro\Extension` base — `jetonomy-pro/includes/class-extension.php` (`meta/boot/activate/is_enabled/get_id/rest_auth_mutation`).
- Free write-path filters already fired: `jetonomy_before_create_post` (`class-post.php:46`), `jetonomy_before_create_reply` (`class-reply.php:37`).
- Free compose hooks already fired: `jetonomy_new_post_fields` (`templates/partials/compose-fields.php:178`, new topic), `jetonomy_composer_toolbar` (`templates/partials/composer.php:49`, reply).
- Free frontend action hooks: `jetonomy_post_actions` / `jetonomy_reply_actions` (used by reactions) — reused to place the reveal button.
- Free admin hooks: `jetonomy_admin_settings_tabs` / `_tab_content` (`includes/admin/views/settings.php:25,34`), `jetonomy_admin_space_edit_tabs` / `_tab_content` (`includes/admin/views/space-edit.php:57,552`). Save idiom copied from the **reactions** (global) and **seo-pro** (per-space) extensions.
- Migration idempotency shape copied from `Migration_1_6_0` (`SHOW COLUMNS` guard variant).
- Test conventions copied from `tests/unit/models/SpaceTest.php` (`WP_UnitTestCase`, `set_up()`, `Schema::create_tables()`).

**Confirmed NOT pre-existing (safe to create — greped code + both manifests):**
- `Jetonomy\Author` / `Author::for_display` / `class-author.php` — zero matches; only named in the design doc.
- `is_anonymous` — zero code/schema/manifest matches; only in the design doc.
- `jetonomy_author_can_reveal` — never fired or hooked anywhere.
- `allow_anonymous` — only a throwaway arbitrary key in `tests/unit/models/SpaceTest.php:239-250` proving `Space::get_settings()` round-trips unknown keys; reusing the name for the real feature is harmless.
- Pro extension id `anonymous-posting` / class `Jetonomy_Pro\Extensions\Anonymous_Posting\Extension` — dir does not exist.
- Option `jetonomy_pro_anonymous_enabled` — not present.
- Route `jetonomy/v1/anonymous/reveal` — not present.

---

## Big-Site Readiness Checklist (per portfolio rule)

- **No new list/grid.** The reveal acts on a single object; the moderation queue that gains the reveal button already paginates.
- **No N+1.** `Author::for_display()` operates on the already-loaded row; anonymous rows short-circuit before any `get_userdata()` call. REST masking overrides the existing (already-batched via `enrich_with_author`) author fields — no added per-row query.
- **No extra query / no index.** `is_anonymous` rides the row already selected; it is never a `WHERE`/`ORDER BY`/`JOIN` column, so no index is added (spec §4).
- **Bounded reveal.** The reveal endpoint reads one id; no unbounded scan.
- **Caching/concurrency.** Reveal is idempotent and re-checks `is_anonymous` (a since-de-flagged row returns `not_anonymous`); multi-admin reveals each log independently.

---

## Self-Review

**1. Spec coverage** — every spec section maps to a task:

| Spec § | Requirement | Task |
|---|---|---|
| §2 store real id, mask on display | resolver + column | 1, 2 |
| §2 posts AND replies | passthrough + enforcement both paths | 3, 12 |
| §2 global AND per-space | Gate | 11, 14, 15 |
| §2 reveal admins only + logged | Reveal + REST | 16, 17 |
| §2 degradation (Pro off → masked) | free honors flag | 2, 4-10 |
| §3.1.1 column + migration + create whitelist | column + create passthrough | 1, 3 |
| §3.1.2 resolver | Author::for_display | 2 |
| §3.1.3 route every surface | 11 surfaces | 4-10, 20 |
| §3.1.4 only new free seam = resolver + filter | (confirmed; compose reuses existing hooks) | 2, 13 |
| §3.2.1 global setting | 14 |
| §3.2.2 per-space + REST parity | 15 |
| §3.2.3 compose toggles gated | 13 |
| §3.2.4 write enforcement | 12 |
| §3.2.5 reveal + logging | 16 |
| §3.2.6 reveal REST | 17 |
| §4 data model | 1 (columns), 15 (settings key), 16 (log) |
| §5 write path end-to-end | 3, 12, 13 |
| §6.1-6.11 leak surfaces | 4 (1-2,11), 5 (3-4), 10 (5,10), 6 (6), 7 (7,10), 8 (8), 9 (9) |
| §7 edit/delete/notify/deactivate/uninstall | 20 (regression); uninstall documented (Task 19 doc + Pro uninstall untouched) |
| §8 big-site | Big-Site checklist + Task 20 |
| §9 i18n/a11y/dark/RTL | 13, 19 |
| §10 manifests + docs | 18, 19 |
| §11 testing/verification | 1-3,5,11,12,16 (unit/integration) + 20 (browser/REST/regression) |
| §12 out of scope | (no tasks — correctly omitted) |

**Gap addressed:** §7 uninstall — the Pro extension's `deactivate()` is a no-op and the extension has no uninstall routine that touches free's column or masked rows; documented in Task 19's doc. If a Pro `uninstall.php` exists, add a note there NOT to drop `is_anonymous` or the option beyond Pro's own — captured as a doc line in Task 19.

**2. Placeholder scan** — no "TBD/TODO/handle edge cases/similar to Task N/add error handling" left; every code step shows full code. The two intentional "verify the exact hook/event name against the store before wiring" notes (Task 13 store event, Task 8 BP activity meta key) are explicit verification steps, not placeholders — each names the file and the fallback.

**3. Type/signature consistency** — checked across tasks:
- `Author::for_display( int, ?object ): array{id,name,avatar,url}` used identically in Tasks 2, 4, 5, 6, 7, 8, 16.
- `Gate::can_author_anonymously( int $space_id, int $user_id ): bool` used identically in Tasks 11, 12, 13.
- `Reveal::reveal( string $object_type, int $object_id ): array` returns `{id,name}` on success / `{error}` on failure — consumed consistently by Task 17 REST.
- `is_anonymous` is `int (0|1)` in DB/create/enforcement; read as truthy (`! empty`) in the resolver and masks — consistent.
- REST param `is_anonymous` (boolean, `rest_sanitize_boolean`) in Task 3 matches the JS payload key in Task 13.
- Reveal route path `jetonomy/v1/anonymous/reveal` matches between Task 17 registration and the Task 17 JS `apiFetch` path.

No inconsistencies found.
