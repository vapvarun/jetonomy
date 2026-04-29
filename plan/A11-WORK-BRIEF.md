# A11 — Community Visibility Mode (REST enforcement gap)

**Branch:** `1.4.1`
**Plugin:** jetonomy (free)
**Risk:** medium — adds gates to 25+ public-read REST endpoints
**Estimated time:** 1–2 days (REVISED DOWN from 2–3 — most of the feature already exists)
**Dispatched after:** A3 (so the access-matrix runner can verify both modes)

## Status of the existing implementation (audited 2026-04-29)

The Community Access toggle **already exists** in production at `Settings → General`:

> **Community Access**
> ◯ Public community — Anyone can read topics and replies. Visitors must log in to post, reply, or vote.
> ◉ Private community — Only logged-in members can view any forum content. Everyone else is redirected to the login page.

**Setting key:** `jetonomy_settings.guest_read` (boolean; default `true` = public)
**DB migration:** `includes/db/migrations/class-migration_1_2_4.php`
**Setup wizard default:** set in `includes/admin/ajax/class-setup-handler.php`

### What works ✅
- **Setting UI** — radio toggle in Settings → General (lines 265–288 of `includes/admin/views/settings.php`)
- **Frontend redirect** — `includes/class-template-loader.php` lines 31–36 redirect anonymous users to the login page when `guest_read` is off, before any template renders

### What's broken 🚨
- **REST API does NOT honor `guest_read`** — confirmed by `grep -rn "guest_read" includes/api/` returning zero matches.
- **Consequence**: even in "Private community" mode, an anonymous request to `/wp-json/jetonomy/v1/spaces`, `/posts/{id}`, `/search`, `/categories`, `/users/{id}`, `/leaderboards`, etc. returns content. Site looks private (visitors redirected) but the API is wide open. **This is a real privacy regression for any operator who flipped to private mode.**
- **Helper class missing** — the `guest_read` check is duplicated inline in template-loader; will be duplicated 25+ more times in REST controllers if we don't centralize it now.

## Revised scope

A11 is no longer "build the toggle from scratch". A11 is:

1. **Create the helper** — centralize the `guest_read` check
2. **Apply it to every public-read REST endpoint**
3. **Refactor template-loader** to use the helper (cosmetic; same behavior)
4. **Verify both modes** via the A3 access-matrix runner

## Implementation

### Step 1 — Create `includes/class-visibility.php`

```php
namespace Jetonomy;

final class Visibility {
    /** True if the caller can view ANY community content. */
    public static function can_view_community(): bool {
        if ( self::get_mode() === 'public' ) return true;
        return is_user_logged_in();
    }

    /** Returns 'public' or 'private'. */
    public static function get_mode(): string {
        $settings = get_option( 'jetonomy_settings', [] );
        // guest_read defaults to true (public) — unset/null/true → public; explicit false → private
        $public = ! isset( $settings['guest_read'] ) || ! empty( $settings['guest_read'] );
        return $public ? 'public' : 'private';
    }

    /** REST permission_callback helper. Returns true or WP_Error 401. */
    public static function rest_check( \WP_REST_Request $req ) {
        if ( self::can_view_community() ) return true;
        return new \WP_Error(
            'community_private',
            __( 'This community is private. Please log in to view content.', 'jetonomy' ),
            [ 'status' => 401 ]
        );
    }
}
```

Register the autoloader for `Jetonomy\Visibility` (likely already covered by the namespaced autoloader in `includes/class-autoloader.php` — verify).

### Step 2 — Wrap public-read REST permission_callbacks

For every endpoint where `audit/manifest.json` shows `auth: "public"` or `auth: "public_with_handler"`, the existing `permission_callback` becomes a chain:

```php
'permission_callback' => static function ( $req ) {
    $vis = \Jetonomy\Visibility::rest_check( $req );
    if ( is_wp_error( $vis ) ) return $vis;
    return existing_callback( $req );  // e.g., __return_true, or the visibility-filter check
},
```

**Routes to gate** (from manifest schema v2):

| Controller | Route | Method |
|---|---|---|
| `Spaces_Controller` | `/spaces` | GET |
| `Spaces_Controller` | `/spaces/{id}` | GET |
| `Spaces_Controller` | `/spaces/{id}/members` | GET |
| `Spaces_Controller` | `/spaces/{id}/privileged-members` | GET |
| `Spaces_Controller` | `/spaces/{space_id}/posts` | GET |
| `Posts_Controller` | `/posts/{id}` | GET |
| `Posts_Controller` | `/link-preview` | GET |
| `Posts_Controller` | `/posts/{post_id}/replies` | GET |
| `Replies_Controller` | `/replies/{id}` | GET |
| `Categories_Controller` | `/categories`, `/categories/{id}` | GET |
| `Tags_Controller` | `/tags`, `/tags/{id}`, `/space-tags` | GET |
| `Users_Controller` | `/users/{id}`, `/users/{id}/posts`, `/users/by-login/{login}` | GET |
| `Search_Controller` | `/search` | GET |
| `Leaderboards_Controller` | `/leaderboards` | GET |
| `Updates_Controller` | `/updates` | GET |
| `OEmbed_Controller` | `/oembed` | GET |
| `Spaces_Controller` | `/invite/{token}` | GET |

**Routes that stay public regardless of mode:**

- `/auth/login`, `/auth/register`, `/auth/lost-password`, `/auth/verify-email`, `/auth/resend-verification` — locking these breaks login flow itself
- All admin REST endpoints — already cap-gated, no extra check needed
- All `/moderation/*` endpoints — already require `jetonomy_moderate`
- Anything classified `auth: "login_required"` in the manifest — already requires login by definition

### Step 3 — Refactor template-loader (cosmetic)

Replace lines 31–36 in `includes/class-template-loader.php`:

```php
// Before
$settings            = get_option( 'jetonomy_settings', array() );
$is_public_community = ! isset( $settings['guest_read'] ) || ! empty( $settings['guest_read'] );
if ( ! $is_public_community && ! is_user_logged_in() ) {

// After
if ( ! \Jetonomy\Visibility::can_view_community() ) {
```

Behavior identical; eliminates the inline duplication.

### Step 4 — Verify with the access-matrix runner

A3 ships `bin/access-matrix-check.sh` with a `--mode` flag. After A11 lands:

```bash
# Public mode — default; everything passes
wp option update jetonomy_settings '{"guest_read":true}' --format=json
bin/access-matrix-check.sh --mode=public

# Private mode — anonymous gets 401 for community routes
wp option update jetonomy_settings '{"guest_read":false}' --format=json
bin/access-matrix-check.sh --mode=private

# Restore
wp option update jetonomy_settings '{"guest_read":true}' --format=json
```

**Expected delta in private mode:**
- All routes in the table above: anonymous now gets `401` instead of `200`
- All logged-in cells unchanged
- All `/auth/*` and admin routes unchanged regardless of mode
- All `auth: "login_required"` routes unchanged regardless of mode

### Step 5 — PHPStan + WPCS + smoke
- `composer phpstan` → 0 errors
- `composer phpcs` → 0 errors
- `wp jetonomy qa-actions run` → green
- `php -l` on every modified file

## Commits (bisectable)

```
1. feat(visibility): add Jetonomy\Visibility helper centralizing guest_read check
2. refactor(template-loader): use Visibility::can_view_community (no behavior change)
3. feat(visibility): apply Visibility::rest_check to public-read REST endpoints (A11)
```

## Done criteria

- [ ] `Jetonomy\Visibility` class exists with `can_view_community()`, `get_mode()`, `rest_check()`
- [ ] All public-read REST endpoints wrap their permission_callback with `Visibility::rest_check`
- [ ] template-loader uses the helper (no behavior change)
- [ ] Access-matrix runner passes in `--mode=public`
- [ ] Access-matrix runner passes in `--mode=private` (anonymous community routes → 401)
- [ ] `/auth/*` and admin routes behave identically regardless of mode
- [ ] PHPStan, WPCS, smoke all green
- [ ] `plan/1.4.1-baselines/CHECKLIST.md` updated to mark A11 ✅ DONE
- [ ] Push to origin/1.4.1

## Forbidden

- ❌ Don't change the setting key (`guest_read` is the existing field; renaming breaks all existing private-mode sites on upgrade)
- ❌ Don't change the default behavior in public mode — sites without changes see no difference
- ❌ Don't add a new admin UI — the existing radio toggle is the UI; just wire REST to honor it
- ❌ Don't lock down `/auth/*` — that locks users out forever
- ❌ Don't lock down admin pages — they have their own cap gating

## Manifest update (out-of-band, after A11 lands)

Once REST endpoints honor `guest_read`, the next `/wp-plugin-onboard --refresh` should bump the schema's `auth` field for affected routes from `public` to a new value like `public_unless_private` or `community_visible`. This is a manifest-schema improvement, not part of A11. Capture as a follow-up.
