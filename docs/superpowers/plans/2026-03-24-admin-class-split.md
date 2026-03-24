# Admin Class Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the 2,132-line monolithic `Admin` class into a lean orchestrator + 8 focused AJAX handler classes, with zero functionality change.

**Architecture:** The existing `Admin` class keeps all menu, settings, asset, and render methods. Every `ajax_*` method moves to a dedicated `Jetonomy\Admin\Ajax\*_Handler` class that self-registers its hooks in its constructor. `Admin::__construct()` is replaced with instantiations of all handlers. All hook names, nonce names, and JSON response shapes are **unchanged**.

**Tech Stack:** PHP 8.1, WordPress AJAX (`wp_ajax_*`), custom PSR-4-style autoloader (`class-autoloader.php`)

---

## Safety Rules — Read Before Any Task

- **Never rename a hook.** e.g. `wp_ajax_jetonomy_create_category` must stay exactly that.
- **Never rename a nonce.** Front-end JS already knows nonce names.
- **Never change a JSON response shape.** Existing front-end JS parses these.
- **Move code verbatim.** Copy the full method body — no rewrites, no refactors.
- **One handler per commit.** Verify PHP syntax + page load after every task.
- **Run `php -l` on every file you touch.**

---

## File Map

### Files to create
| File | Class | Responsibility |
|------|-------|----------------|
| `includes/admin/ajax/class-categories-handler.php` | `Jetonomy\Admin\Ajax\Categories_Handler` | 4 category AJAX methods |
| `includes/admin/ajax/class-spaces-handler.php` | `Jetonomy\Admin\Ajax\Spaces_Handler` | 3 space + 3 member + 2 access-rule AJAX methods |
| `includes/admin/ajax/class-moderation-handler.php` | `Jetonomy\Admin\Ajax\Moderation_Handler` | 4 content moderation AJAX methods |
| `includes/admin/ajax/class-users-handler.php` | `Jetonomy\Admin\Ajax\Users_Handler` | 4 user management AJAX methods |
| `includes/admin/ajax/class-import-handler.php` | `Jetonomy\Admin\Ajax\Import_Handler` | 3 import AJAX methods |
| `includes/admin/ajax/class-settings-handler.php` | `Jetonomy\Admin\Ajax\Settings_Handler` | 2 settings utility AJAX methods |
| `includes/admin/ajax/class-content-handler.php` | `Jetonomy\Admin\Ajax\Content_Handler` | 6 post/reply content AJAX methods |
| `includes/admin/ajax/class-setup-handler.php` | `Jetonomy\Admin\Ajax\Setup_Handler` | 3 setup wizard AJAX methods |

### Files to modify
| File | Change |
|------|--------|
| `includes/class-autoloader.php` | Add `'Jetonomy\\Admin\\Ajax\\'` prefix before `'Jetonomy\\Admin\\'` |
| `includes/admin/class-admin.php` | Strip all `ajax_*` methods from constructor + class body; instantiate handlers |

---

## Task 1: Update Autoloader

The custom autoloader in `class-autoloader.php` maps namespace prefixes to directories. `Jetonomy\Admin\Ajax\*` classes must resolve to `includes/admin/ajax/` **before** the `Jetonomy\Admin\` prefix catches them.

**Files:**
- Modify: `includes/class-autoloader.php`

- [ ] **Step 1: Open the autoloader**

Read `includes/class-autoloader.php`. The `$map` array starts at line 8.

- [ ] **Step 2: Add the Ajax sub-namespace entry**

Insert **before** the `'Jetonomy\\Admin\\'` line:

```php
'Jetonomy\\Admin\\Ajax\\' => 'includes/admin/ajax/',
```

The map block should now read (relevant lines only):

```php
private static array $map = [
    'Jetonomy\\Models\\'          => 'includes/models/',
    'Jetonomy\\Permissions\\'     => 'includes/permissions/',
    'Jetonomy\\Trust\\'           => 'includes/trust/',
    'Jetonomy\\API\\'             => 'includes/api/',
    'Jetonomy\\Adapters\\'        => 'includes/adapters/',
    'Jetonomy\\Search\\'          => 'includes/search/',
    'Jetonomy\\Moderation\\'      => 'includes/moderation/',
    'Jetonomy\\Notifications\\'   => 'includes/notifications/',
    'Jetonomy\\Import\\'          => 'includes/import/',
    'Jetonomy\\Admin\\Ajax\\'     => 'includes/admin/ajax/',   // ← ADD THIS
    'Jetonomy\\Admin\\'           => 'includes/admin/',
    'Jetonomy\\SEO\\'             => 'includes/seo/',
    'Jetonomy\\DB\\'              => 'includes/db/',
    'Jetonomy\\DB\\Migrations\\'  => 'includes/db/migrations/',
    'Jetonomy\\'                  => 'includes/',
];
```

- [ ] **Step 3: Create the ajax subdirectory**

```bash
mkdir -p /path/to/jetonomy/includes/admin/ajax
```

- [ ] **Step 4: Syntax-check**

```bash
php -l includes/class-autoloader.php
```

Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add includes/class-autoloader.php
git commit -m "refactor: add Admin\\Ajax\\ namespace to autoloader"
```

---

## Task 2: Extract Categories_Handler

Move the 4 category AJAX methods (lines 588–737 of `class-admin.php`) into a dedicated handler.

**Files:**
- Create: `includes/admin/ajax/class-categories-handler.php`
- Modify: `includes/admin/class-admin.php` (remove 4 methods + 4 `add_action` calls from constructor)

- [ ] **Step 1: Create the handler file**

```php
<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use function Jetonomy\table;

class Categories_Handler {

    public function __construct() {
        add_action( 'wp_ajax_jetonomy_create_category',   [ $this, 'ajax_create_category' ] );
        add_action( 'wp_ajax_jetonomy_update_category',   [ $this, 'ajax_update_category' ] );
        add_action( 'wp_ajax_jetonomy_delete_category',   [ $this, 'ajax_delete_category' ] );
        add_action( 'wp_ajax_jetonomy_reorder_categories', [ $this, 'ajax_reorder_categories' ] );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function get_all_categories_nested(): array {
        // COPY VERBATIM from Admin::get_all_categories_nested() (lines 567–576)
    }

    private function get_all_categories_flat( array $categories = [], int $parent_id = 0, int $depth = 0 ): array {
        // COPY VERBATIM from Admin::get_all_categories_flat() (lines 577–587)
    }

    // ── AJAX Handlers ────────────────────────────────────────────────────────

    public function ajax_create_category(): void {
        // COPY VERBATIM from Admin::ajax_create_category() (lines 588–631)
    }

    public function ajax_update_category(): void {
        // COPY VERBATIM from Admin::ajax_update_category() (lines 632–684)
    }

    public function ajax_delete_category(): void {
        // COPY VERBATIM from Admin::ajax_delete_category() (lines 685–715)
    }

    public function ajax_reorder_categories(): void {
        // COPY VERBATIM from Admin::ajax_reorder_categories() (lines 716–737)
    }
}
```

> **Note:** The two private helper methods `get_all_categories_nested()` and `get_all_categories_flat()` are used by both the render method (stays in Admin) AND `ajax_create_category` (moves here). Check the `ajax_create_category` body — if it calls these helpers, move them here AND keep identical copies in Admin (or refactor render to build the list from `Category::get_nested()` directly). Verify at runtime.

- [ ] **Step 2: Remove from Admin class**

In `includes/admin/class-admin.php`:
1. Delete the 4 `add_action` lines in `__construct()` (lines 27–30)
2. Delete the 4 `ajax_*` method bodies (lines 588–737)
3. Delete `get_all_categories_nested()` and `get_all_categories_flat()` ONLY if they are not used by any remaining render method

- [ ] **Step 3: Instantiate in Admin constructor**

In `Admin::__construct()`, add:

```php
new Ajax\Categories_Handler();
```

- [ ] **Step 4: Syntax check both files**

```bash
php -l includes/admin/ajax/class-categories-handler.php
php -l includes/admin/class-admin.php
```

Expected: `No syntax errors detected` on both.

- [ ] **Step 5: Load test**

Navigate to `http://forums.local/wp-admin/admin.php?page=jetonomy-categories?autologin=1`

Expected: page loads, no PHP fatal errors, no console errors.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/ajax/class-categories-handler.php includes/admin/class-admin.php
git commit -m "refactor: extract Admin\\Ajax\\Categories_Handler"
```

---

## Task 3: Extract Spaces_Handler

Move the 8 space/member/access-rule AJAX methods (lines 738–1064 of `class-admin.php`).

**Files:**
- Create: `includes/admin/ajax/class-spaces-handler.php`
- Modify: `includes/admin/class-admin.php`

- [ ] **Step 1: Create the handler file**

```php
<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\AccessRule;
use function Jetonomy\table;
use function Jetonomy\now;

class Spaces_Handler {

    public function __construct() {
        // Space AJAX
        add_action( 'wp_ajax_jetonomy_create_space',        [ $this, 'ajax_create_space' ] );
        add_action( 'wp_ajax_jetonomy_update_space',        [ $this, 'ajax_update_space' ] );
        add_action( 'wp_ajax_jetonomy_delete_space',        [ $this, 'ajax_delete_space' ] );
        // Space Members AJAX
        add_action( 'wp_ajax_jetonomy_add_space_member',    [ $this, 'ajax_add_space_member' ] );
        add_action( 'wp_ajax_jetonomy_remove_space_member', [ $this, 'ajax_remove_space_member' ] );
        add_action( 'wp_ajax_jetonomy_change_member_role',  [ $this, 'ajax_change_member_role' ] );
        // Access Rules AJAX
        add_action( 'wp_ajax_jetonomy_add_access_rule',     [ $this, 'ajax_add_access_rule' ] );
        add_action( 'wp_ajax_jetonomy_delete_access_rule',  [ $this, 'ajax_delete_access_rule' ] );
    }

    public function ajax_create_space(): void {
        // COPY VERBATIM lines 738–795
    }

    public function ajax_update_space(): void {
        // COPY VERBATIM lines 796–878
    }

    public function ajax_delete_space(): void {
        // COPY VERBATIM lines 879–911
    }

    public function ajax_add_space_member(): void {
        // COPY VERBATIM lines 912–945
    }

    public function ajax_remove_space_member(): void {
        // COPY VERBATIM lines 946–963
    }

    public function ajax_change_member_role(): void {
        // COPY VERBATIM lines 964–990
    }

    public function ajax_add_access_rule(): void {
        // COPY VERBATIM lines 991–1041
    }

    public function ajax_delete_access_rule(): void {
        // COPY VERBATIM lines 1042–1064
    }
}
```

- [ ] **Step 2: Remove from Admin class** — delete 8 `add_action` lines (33–44) and 8 method bodies (lines 738–1064)

- [ ] **Step 3: Add to Admin constructor** — `new Ajax\Spaces_Handler();`

- [ ] **Step 4: Syntax check**

```bash
php -l includes/admin/ajax/class-spaces-handler.php
php -l includes/admin/class-admin.php
```

- [ ] **Step 5: Load test** — navigate to `?page=jetonomy-spaces&autologin=1`, verify page loads

- [ ] **Step 6: Commit**

```bash
git add includes/admin/ajax/class-spaces-handler.php includes/admin/class-admin.php
git commit -m "refactor: extract Admin\\Ajax\\Spaces_Handler"
```

---

## Task 4: Extract Moderation_Handler

Move 4 content moderation AJAX methods (lines 1065–1166).

**Files:**
- Create: `includes/admin/ajax/class-moderation-handler.php`
- Modify: `includes/admin/class-admin.php`

- [ ] **Step 1: Create handler**

```php
<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Flag;
use function Jetonomy\table;
use function Jetonomy\now;

class Moderation_Handler {

    public function __construct() {
        add_action( 'wp_ajax_jetonomy_approve_content', [ $this, 'ajax_approve_content' ] );
        add_action( 'wp_ajax_jetonomy_spam_content',    [ $this, 'ajax_spam_content' ] );
        add_action( 'wp_ajax_jetonomy_trash_content',   [ $this, 'ajax_trash_content' ] );
        add_action( 'wp_ajax_jetonomy_resolve_flag',    [ $this, 'ajax_resolve_flag' ] );
    }

    public function ajax_approve_content(): void {
        // COPY VERBATIM lines 1065–1086
    }

    public function ajax_spam_content(): void {
        // COPY VERBATIM lines 1087–1108
    }

    public function ajax_trash_content(): void {
        // COPY VERBATIM lines 1109–1130
    }

    public function ajax_resolve_flag(): void {
        // COPY VERBATIM lines 1131–1166
    }
}
```

- [ ] **Step 2: Remove from Admin** — 4 actions (lines 47–50) + 4 methods (1065–1166)
- [ ] **Step 3: Add `new Ajax\Moderation_Handler();`** to constructor
- [ ] **Step 4: Syntax check both files**
- [ ] **Step 5: Load test** — `?page=jetonomy-mod&autologin=1`
- [ ] **Step 6: Commit** — `"refactor: extract Admin\\Ajax\\Moderation_Handler"`

---

## Task 5: Extract Users_Handler

Move 4 user management AJAX methods (lines 1167–1310).

**Files:**
- Create: `includes/admin/ajax/class-users-handler.php`
- Modify: `includes/admin/class-admin.php`

- [ ] **Step 1: Create handler**

```php
<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\UserProfile;
use Jetonomy\Trust\Trust_Level;
use function Jetonomy\table;
use function Jetonomy\now;

class Users_Handler {

    public function __construct() {
        add_action( 'wp_ajax_jetonomy_ban_user',           [ $this, 'ajax_ban_user' ] );
        add_action( 'wp_ajax_jetonomy_unban_user',         [ $this, 'ajax_unban_user' ] );
        add_action( 'wp_ajax_jetonomy_change_trust_level', [ $this, 'ajax_change_trust_level' ] );
        add_action( 'wp_ajax_jetonomy_search_users',       [ $this, 'ajax_search_users' ] );
    }

    public function ajax_ban_user(): void {
        // COPY VERBATIM lines 1167–1227
    }

    public function ajax_unban_user(): void {
        // COPY VERBATIM lines 1228–1246
    }

    public function ajax_change_trust_level(): void {
        // COPY VERBATIM lines 1247–1276
    }

    public function ajax_search_users(): void {
        // COPY VERBATIM lines 1277–1310
    }
}
```

- [ ] **Step 2–6:** Same pattern as above. Load test: `?page=jetonomy-users&autologin=1`. Commit: `"refactor: extract Admin\\Ajax\\Users_Handler"`

---

## Task 6: Extract Import_Handler

Move 3 import AJAX methods (lines 1311–1435).

**Files:**
- Create: `includes/admin/ajax/class-import-handler.php`
- Modify: `includes/admin/class-admin.php`

- [ ] **Step 1: Create handler**

```php
<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Import\Import_Manager;

class Import_Handler {

    public function __construct() {
        add_action( 'wp_ajax_jetonomy_run_import',      [ $this, 'ajax_run_import' ] );
        add_action( 'wp_ajax_jetonomy_import_batch',    [ $this, 'ajax_import_batch' ] );
        add_action( 'wp_ajax_jetonomy_import_progress', [ $this, 'ajax_import_progress' ] );
    }

    public function ajax_run_import(): void {
        // COPY VERBATIM lines 1311–1331
    }

    public function ajax_import_batch(): void {
        // COPY VERBATIM lines 1332–1425
    }

    public function ajax_import_progress(): void {
        // COPY VERBATIM lines 1426–1435
    }
}
```

- [ ] **Step 2–6:** Same pattern. Load test: `?page=jetonomy-import&autologin=1`. Commit: `"refactor: extract Admin\\Ajax\\Import_Handler"`

---

## Task 7: Extract Settings_Handler

Move 2 settings utility AJAX methods (lines 1436–1474).

**Files:**
- Create: `includes/admin/ajax/class-settings-handler.php`
- Modify: `includes/admin/class-admin.php`

- [ ] **Step 1: Create handler**

```php
<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

class Settings_Handler {

    public function __construct() {
        add_action( 'wp_ajax_jetonomy_test_email',  [ $this, 'ajax_test_email' ] );
        add_action( 'wp_ajax_jetonomy_flush_rules', [ $this, 'ajax_flush_rules' ] );
    }

    public function ajax_test_email(): void {
        // COPY VERBATIM lines 1436–1460
    }

    public function ajax_flush_rules(): void {
        // COPY VERBATIM lines 1461–1474
    }
}
```

- [ ] **Step 2–6:** Same pattern. Load test: Settings page, trigger "Test Email" button. Commit: `"refactor: extract Admin\\Ajax\\Settings_Handler"`

---

## Task 8: Extract Content_Handler

Move 6 post/reply content management AJAX methods (lines 1583–1738).

**Files:**
- Create: `includes/admin/ajax/class-content-handler.php`
- Modify: `includes/admin/class-admin.php`

- [ ] **Step 1: Create handler**

```php
<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use function Jetonomy\table;

class Content_Handler {

    public function __construct() {
        add_action( 'wp_ajax_jetonomy_update_post',         [ $this, 'ajax_update_post' ] );
        add_action( 'wp_ajax_jetonomy_delete_post',         [ $this, 'ajax_delete_post' ] );
        add_action( 'wp_ajax_jetonomy_update_reply',        [ $this, 'ajax_update_reply' ] );
        add_action( 'wp_ajax_jetonomy_delete_reply',        [ $this, 'ajax_delete_reply' ] );
        add_action( 'wp_ajax_jetonomy_get_replies',         [ $this, 'ajax_get_replies' ] );
        add_action( 'wp_ajax_jetonomy_bulk_content_action', [ $this, 'ajax_bulk_content_action' ] );
    }

    public function ajax_update_post(): void {
        // COPY VERBATIM lines 1583–1616
    }

    public function ajax_delete_post(): void {
        // COPY VERBATIM lines 1617–1635
    }

    public function ajax_update_reply(): void {
        // COPY VERBATIM lines 1636–1666
    }

    public function ajax_delete_reply(): void {
        // COPY VERBATIM lines 1667–1682
    }

    public function ajax_get_replies(): void {
        // COPY VERBATIM lines 1683–1709
    }

    public function ajax_bulk_content_action(): void {
        // COPY VERBATIM lines 1710–1738
    }
}
```

> `render_post_replies()` (lines 1534–1582) is a private render helper used by `render_content()` — it stays in `Admin`.

- [ ] **Step 2–6:** Same pattern. Load test: `?page=jetonomy-content&autologin=1`. Commit: `"refactor: extract Admin\\Ajax\\Content_Handler"`

---

## Task 9: Extract Setup_Handler

Move 3 setup wizard AJAX methods (lines 1743–2132). `ajax_setup_create_sample` is very long (~285 lines) — copy verbatim.

**Files:**
- Create: `includes/admin/ajax/class-setup-handler.php`
- Modify: `includes/admin/class-admin.php`

- [ ] **Step 1: Create handler**

```php
<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use function Jetonomy\table;
use function Jetonomy\now;

class Setup_Handler {

    public function __construct() {
        add_action( 'wp_ajax_jetonomy_setup_save',           [ $this, 'ajax_setup_save' ] );
        add_action( 'wp_ajax_jetonomy_setup_create_sample',  [ $this, 'ajax_setup_create_sample' ] );
        add_action( 'wp_ajax_jetonomy_cleanup_sample_data',  [ $this, 'ajax_cleanup_sample_data' ] );
    }

    public function ajax_setup_save(): void {
        // COPY VERBATIM lines 1743–1792
    }

    public function ajax_setup_create_sample(): void {
        // COPY VERBATIM lines 1793–2076
    }

    public function ajax_cleanup_sample_data(): void {
        // COPY VERBATIM lines 2077–end
    }
}
```

- [ ] **Step 2–6:** Same pattern. Load test: `?page=jetonomy&autologin=1`, click through setup wizard. Commit: `"refactor: extract Admin\\Ajax\\Setup_Handler"`

---

## Task 10: Trim Admin Constructor

Replace the 30-line AJAX-registering constructor with a clean orchestrator.

**Files:**
- Modify: `includes/admin/class-admin.php`

- [ ] **Step 1: Replace constructor**

```php
public function __construct() {
    add_action( 'admin_menu', [ $this, 'add_menu' ] );
    add_action( 'admin_init', [ $this, 'register_settings' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

    // AJAX handlers — each class self-registers its wp_ajax_* hooks.
    new Ajax\Categories_Handler();
    new Ajax\Spaces_Handler();
    new Ajax\Moderation_Handler();
    new Ajax\Users_Handler();
    new Ajax\Import_Handler();
    new Ajax\Settings_Handler();
    new Ajax\Content_Handler();
    new Ajax\Setup_Handler();
}
```

- [ ] **Step 2: Remove now-unused `use` imports** from the top of `class-admin.php`

Check: any `use` statement that was only needed by a moved AJAX method. Remove only those. Keep all `use` statements still needed by render methods.

- [ ] **Step 3: Verify line count is under 750**

```bash
wc -l includes/admin/class-admin.php
```

Expected: under 750 lines.

- [ ] **Step 4: Full syntax check**

```bash
php -l includes/admin/class-admin.php
for f in includes/admin/ajax/class-*.php; do php -l "$f"; done
```

- [ ] **Step 5: Full smoke test**

Load every admin page and verify no fatals:
```
http://forums.local/wp-admin/admin.php?page=jetonomy&autologin=1
http://forums.local/wp-admin/admin.php?page=jetonomy-categories&autologin=1
http://forums.local/wp-admin/admin.php?page=jetonomy-spaces&autologin=1
http://forums.local/wp-admin/admin.php?page=jetonomy-mod&autologin=1
http://forums.local/wp-admin/admin.php?page=jetonomy-users&autologin=1
http://forums.local/wp-admin/admin.php?page=jetonomy-import&autologin=1
http://forums.local/wp-admin/admin.php?page=jetonomy-settings&autologin=1
http://forums.local/wp-admin/admin.php?page=jetonomy-content&autologin=1
```

- [ ] **Step 6: Commit**

```bash
git add includes/admin/class-admin.php
git commit -m "refactor: slim Admin constructor — delegates AJAX to handler classes"
```

---

## Definition of Done

- [ ] `wc -l includes/admin/class-admin.php` ≤ 750 lines
- [ ] Each handler in `includes/admin/ajax/` ≤ 400 lines
- [ ] `php -l` passes on all 9 files
- [ ] All 8 admin pages load without PHP warnings in `wp-content/debug.log`
- [ ] All AJAX actions still respond correctly (test at least one from each handler in browser)
- [ ] No hook names changed
- [ ] No nonce names changed
- [ ] CLAUDE.md "Recent Changes" table updated
