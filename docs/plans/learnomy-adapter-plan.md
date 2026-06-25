# Learnomy LMS Adapter — Implementation Plan

**Target version:** 1.5.0-dev
**Status:** IMPLEMENTED + verified against Learnomy 1.1.1 (2026-06-14). Learnomy free+pro were installed in the forums dev site; the full public contract was confirmed with **no Learnomy-side changes needed**. Adapter: `jetonomy-pro/includes/adapters/class-learnomy-adapter.php`, registered in `class-jetonomy-pro.php`; 3 free-side config edits done (space-edit prefix map, class-admin labels, settings role regex). Verified end-to-end: enrolled user gated TRUE / non-enrolled FALSE, label + catalog resolve, member-sync adds/removes on `learnomy_student_enrolled` / `_unenrolled` / `_enrollment_expired` + `learnomy_subscription_created|cancelled|expired`. Real symbols: `Enrollment::is_enrolled()` / `get_distinct_for_user()`, `Subscription::get_active_for_user()`, `Course::list_published()`/`find()`, `MembershipPlan::get_active()`/`find()` — Learnomy uses custom `wp_lrn_*` tables (NOT CPTs), so labels come from `->title`.

## 0. What is verified true in the repo right now

- **Engine is generic** — `includes/models/class-access-rule.php:115-127` `membership` case iterates ALL registered adapters (`$adapter->is_active() && $adapter->user_has_level($user_id, $rule->rule_value)`). **No change needed.**
- **JS autocomplete is adapter-agnostic** — `assets/js/admin.js:700-778` reads `window.jetonomyAdmin.membershipAdapters` + `get_all_levels()`. **No change needed.**
- **Interface** — `includes/adapters/interface-membership-adapter.php`: `is_active()`, `get_user_levels()`, `user_has_level()`, `get_all_levels()`, `get_level_label()`, `register_hooks()`.
- **Registry** — `Adapter_Registry::register_membership(string $id, Membership_Adapter $adapter)`.
- **Best template** — `jetonomy-pro/includes/adapters/class-lifterlms-adapter.php` (dual level type: course + membership, exactly like Learnomy's `lrn_course_` + `lrn_membership_`). NOT Sensei (single-type).
- **Learnomy NOT installed here.** Site-wide grep for `learnomy` / `lrn_course_` / `lrn_membership_` across plugins/themes/mu-plugins returns ZERO plugin code. Every Learnomy-side symbol below is **PROVISIONAL** and must be confirmed against real Learnomy source.

## 1. Architecture decisions

- **Standalone-safe:** every adapter method gates on `is_active()` (a Learnomy capability probe). The adapter is only constructed + `register_hooks()`-ed inside a `defined()`/`function_exists()` gate in the Pro bootstrap. Jetonomy keeps booting when Learnomy is absent.
- **Contract rule (confirmed across all adapters): Jetonomy NEVER reads third-party tables directly.** Call PUBLIC Learnomy helpers only — never `SELECT ... FROM wp_lrn_*`. The only allowed `$wpdb` use is against Jetonomy's own `jt_access_rules` in `sync_user_spaces()`.

## 2. Files to change

### 2.1 NEW — `jetonomy-pro/includes/adapters/class-learnomy-adapter.php`
The real work. Skeleton in §3. Copy structure from `class-lifterlms-adapter.php` (dual-type).

### 2.2 `jetonomy-pro/includes/class-jetonomy-pro.php` (register_pro_adapters, ~lines 778-834)
```php
require_once JETONOMY_PRO_DIR . 'includes/adapters/class-learnomy-adapter.php';
// ...
if ( defined( 'LEARNOMY_VERSION' ) ) { // PROVISIONAL — confirm real constant
    $lrn = new Jetonomy_Pro\Adapters\Learnomy_Adapter();
    \Jetonomy\Adapters\Adapter_Registry::register_membership( 'learnomy', $lrn );
    $lrn->register_hooks();
}
```

### 2.3 `jetonomy/includes/admin/views/space-edit.php` ($adapter_prefix_map, ~364-377)
```php
'lrn_course_'     => array( 'learnomy', __( 'Learnomy Course', 'jetonomy' ) ),
'lrn_membership_' => array( 'learnomy', __( 'Learnomy Membership', 'jetonomy' ) ),
```

### 2.4 `jetonomy/includes/admin/class-admin.php` ($adapter_labels, ~777-788)
```php
'learnomy' => __( 'Learnomy Course', 'jetonomy' ),
```

### 2.5 `jetonomy/includes/admin/views/settings.php` (role-group regex, ~216)
Add `lrn_` to the lms_memberships bucket:
```php
} elseif ( preg_match( '/^(ld_|tutor_|lms_|lrn_|instructor|teacher|student|group_leader|memberpress|pmpro|wlm_)/i', $jt_rk ) ) {
```

### 2.6 / 2.7 NO CHANGE — `class-access-rule.php` (generic), `admin.js` (agnostic).

### 2.8 Manifest sync — add Learnomy to `jetonomy-pro/audit/manifest.json` membership-adapters inventory + a `generated` delta, same change set.

## 3. Adapter skeleton (namespace `Jetonomy_Pro\Adapters`, `implements Membership_Adapter`)

Level formats: `lrn_course_{id}`, `lrn_membership_{plan_id}`. All Learnomy symbols PROVISIONAL (card references `Learnomy\Models\Enrollment`, filter `learnomy_membership_has_access`, REST `GET /learnomy/v1/courses` + admin `/learnomy/v1/admin/membership-plans`, CPTs `lrn_courses` / `lrn_membership_plans`).

- **`is_active()`** — the standalone guard. Probe a PUBLIC class/function, not a table:
  `return defined('LEARNOMY_VERSION') && class_exists('\\Learnomy\\Models\\Enrollment') && function_exists('learnomy_user_can_access_course');` (all PROVISIONAL). If the required helpers are missing → returns false → adapter inert → Jetonomy fully standalone.
- **`get_user_levels($user_id)`** — union of enrolled course levels + active membership-plan levels via PUBLIC API, bounded (`limit => 2000` like LifterLMS).
- **`user_has_level($user_id, $level_id)`** — branch on prefix. Course → `learnomy_user_can_access_course($user_id, $course_id)`. Membership → public per-plan check, or `apply_filters('learnomy_membership_has_access', false, $user_id, $plan_id)` ONLY after confirming arg order/semantics in real source. This is the hot path (engine calls it per-rule with a single id — O(1) per rule).
- **`get_all_levels()`** — BIG-SITE BOUNDED. Hard cap `apply_filters('jetonomy_learnomy_max_levels', 500)`, `fields => ids`, `no_found_rows => true`, never `-1`. Distinguish `(Learnomy Course)` / `(Learnomy Membership)` in labels. Autocomplete is convenience only — `user_has_level()` works for any id regardless.
- **`get_level_label($level_id)`** — strip prefix, `get_post($id)->post_title`, fallback to raw id.
- **`register_hooks()`** — member-sync on enrollment change. Bind Learnomy's enroll/unenroll/membership-change actions to handlers that call `sync_user_spaces()` (copy verbatim from LifterLMS:155-218) + fire `jetonomy_membership_activated|deactivated($user_id, $level_id, 'learnomy')`. **The enrollment-changed hook is the #1 unknown — the card names no such action; member-sync is dead without it.**

## 4. Big-site

- `get_all_levels()` hard-capped (default 500, filterable), bounded query — the explicit requirement.
- `get_user_levels()` enrollment fetch limited (2000); hot path is per-rule `user_has_level()` (single id), already O(1).
- `sync_user_spaces()` fan-out bounded by # of access rules referencing the level id. No cache in v1 (matches existing adapters).

## 5. Depends on / Blocked by (HARD BLOCKERS)

1. **Learnomy installed + source readable** in the build env. Confirm the real version constant (`LEARNOMY_VERSION` is PROVISIONAL).
2. **Learnomy must expose PUBLIC helpers** (Jetonomy forbidden from `lrn_*` table reads):
   - (a) Course access check — `learnomy_user_can_access_course($user_id, $course_id): bool`. REQUIRED for `user_has_level()`.
   - (b) Active-membership check + a way to LIST a user's active plan ids (boolean alone insufficient for enumeration).
   - (c) Catalog listing (bounded) — courses + membership plans with titles.
   - (d) **Enrollment-changed action hook(s)** on enroll/unenroll/membership activate/lapse, passing `$user_id` + course/plan id. REQUIRED for member-sync. **Top unknown — not named in the card.**
3. **If any of (a)-(d) is missing, file a PAIRED Learnomy-side card** to add the public helper/hook BEFORE adapter coding starts. The `learnomy_membership_has_access` filter is a read check, not an event — it does NOT satisfy (d).

## 6. Test plan

- **Standalone (Learnomy DEACTIVATED — most important):** Free+Pro boot smoke, `wp jetonomy qa-actions` 210/210, `audit-rest-routes.php` both OK, space-edit + autocomplete render with zero Learnomy entries and no notices.
- **With Learnomy ACTIVE:** `learnomy` in registry; rule dropdown shows "Learnomy Course"; autocomplete lists courses/plans with correct suffixes; saving stores `lrn_course_{id}`/`lrn_membership_{id}`; `get_level_label()` round-trips; access enforcement (enrolled can read, non-enrolled blocked); member-sync add/remove on enroll/unenroll + `jetonomy_membership_activated|deactivated` fired with `'learnomy'`.
- **Big-site:** seed >500 courses; assert `get_all_levels()` ≤ cap, bounded query, no timeout.
- **CI/static:** `php -l`, PHPStan (baseline), WPCS via pre-commit; manifest delta present.
