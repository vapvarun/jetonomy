# A11 — Community Visibility Mode (public / private)

**Branch:** `1.4.1`
**Plugin:** jetonomy (free)
**Risk:** medium — touches every public-read REST endpoint + frontend templates
**Estimated time:** 2–3 days
**Dispatched after:** A3 lands (so A11 can leverage `bin/access-matrix-check.sh` to verify both modes)
**Reference:** `plan/1.4.1-plan.md` (will be updated to add A11 row)

## Problem

Most communities run public by default — anyone can browse posts, replies, profiles. **But some operators want to run privately**: corporate forums (employees-only), paid memberships (subscribers-only), pre-launch betas, support communities (customers-only).

Today the plugin assumes public-by-default and bakes that assumption into 25+ public-read REST endpoints and every frontend template. Changing it later means touching every one of those sites; we'd rather have the toggle from day 1.

The user requirement: **"most of the community are public but sometimes they want to run it privately, we should be ready for it from day 1"**.

## Design

### Setting

Add to `jetonomy_settings` option:

```php
'community_visibility' => 'public', // 'public' (default) | 'private'
```

(Future-extensible to `'hybrid'` — some spaces public, others login-required — but **A11 ships only `public` and `private`**. Hybrid is a 1.5.0 follow-up.)

### Helper

Create `includes/class-visibility.php`:

```php
namespace Jetonomy;

final class Visibility {
    /** Returns true if the current user (or anonymous) can view ANY community content. */
    public static function can_view_community( ?int $user_id = null ): bool {
        $mode = self::get_mode();
        if ( $mode === 'public' ) return true;
        $user_id = $user_id ?? get_current_user_id();
        return $user_id > 0;  // private mode: any logged-in user, regardless of role
    }

    /** Returns the current mode: 'public' or 'private'. */
    public static function get_mode(): string {
        $settings = get_option( 'jetonomy_settings', [] );
        $mode = $settings['community_visibility'] ?? 'public';
        return in_array( $mode, [ 'public', 'private' ], true ) ? $mode : 'public';
    }

    /** REST permission helper for public-read endpoints. Returns true or WP_Error. */
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

### Where to apply the check

**Every REST endpoint currently classified as `auth: "public"` or `"public_with_handler"` in `audit/manifest.json` schema v2** must call `Visibility::rest_check` first. Examples:

- `GET /spaces` — `Spaces_Controller::list_items`
- `GET /spaces/{id}` — `Spaces_Controller::get_item`
- `GET /spaces/{id}/members` — etc.
- `GET /posts/{id}`, `GET /replies/{id}`, `GET /search`, `GET /leaderboards`
- `GET /categories`, `GET /tags`, `GET /space-tags`
- `GET /users/{id}`, `GET /users/by-login/{login}`, `GET /users/{id}/posts`
- `GET /updates`, `GET /oembed`, `GET /link-preview`

**Pattern** (via permission_callback chain):

```php
'permission_callback' => function ( $req ) {
    $vis = Visibility::rest_check( $req );
    if ( is_wp_error( $vis ) ) return $vis;
    return existing_callback( $req );  // e.g., __return_true or visibility-filter
},
```

**Routes that stay public regardless of mode** (auth + system endpoints):

- `/auth/login`, `/auth/register`, `/auth/lost-password`, `/auth/verify-email`, `/auth/resend-verification`
- `/oembed` (public oEmbed endpoint per WP convention — but content discovery is gated separately)
- `/invite/{token}` (private mode + invite token = expected to surface "join" prompt without auth)

The auth/* routes should NOT honor the private flag — otherwise users can't ever log in.

### Frontend (template-level)

Templates check `Visibility::can_view_community()` early in render. If false:
- Redirect anonymous to `wp_login_url( $request_uri )`
- Show login form inline if a login block is present on the page

Files (read first, then patch):
- `templates/views/feed.php` (or whichever is the community home)
- `templates/views/space.php`
- `templates/views/single-post.php`
- `templates/views/user-profile.php`
- Any template that loads community content

Add at the top:

```php
if ( ! \Jetonomy\Visibility::can_view_community() ) {
    \Jetonomy\Template_Loader::redirect_login_or_render_inline_form();
    return;
}
```

(The redirect helper should be added to the existing template loader class. If a login block is already on the page, render that instead of redirecting.)

### Admin UI

In the existing **Settings → General** admin page, add a section:

```
Community Visibility
  ○ Public (default)
       Anyone — including unregistered visitors — can browse content.
  ○ Private
       Only logged-in users can see anything. Anonymous visitors are
       redirected to log in.
  
  Note: switching to Private does NOT lock down /auth/* (login,
  register, password reset) or admin pages.
```

Saves to `jetonomy_settings.community_visibility`.

## Safety contract

After A11, the access matrix has a **second mode** to verify. `plan/REST_ACCESS_MATRIX.md` has been updated with a "Private mode delta" section showing what changes when the toggle flips:

- Every 🔓 anon ✅ in public mode → 🔒 401 in private mode (for community routes)
- Every 👤+ logged-in cell stays the same
- All `/auth/*` and admin endpoints unchanged regardless of mode

`bin/access-matrix-check.sh` (built in A3) **must accept a `--mode=public|private` flag** and run the same matrix against the corresponding expected codes. A11 extends the runner to support both modes (or A3's runner already does it; check before extending).

## Implementation steps

1. Read existing settings page UI: `includes/admin/views/settings.php` — find the General tab pattern
2. Read existing template loader: `includes/class-template-loader.php`
3. Read sample REST controller to see permission_callback wiring pattern: `includes/api/class-spaces-controller.php`
4. Create `includes/class-visibility.php` with the helper
5. Wire `Visibility::rest_check` into every public-read REST endpoint listed above (one method per endpoint or a shared callback wrapper — your call)
6. Add the admin UI section in settings.php; register the new setting key
7. Patch every relevant frontend template
8. Run access-matrix runner in PUBLIC mode → assert all rows pass
9. Flip setting to private, re-run runner in PRIVATE mode → assert public-read rows now return 401 for anon, 200 for logged-in
10. Flip back to public, re-run → must pass identically to step 8 (no permanent state change)
11. PHPStan level 5, WPCS, php -l
12. Run `wp jetonomy qa-actions run` smoke
13. Commit per-step with bisectable history:
    - "feat(visibility): add Visibility helper and mode setting (A11.1)"
    - "feat(visibility): apply Visibility::rest_check to public-read REST endpoints (A11.2)"
    - "feat(visibility): admin UI for community visibility mode (A11.3)"
    - "feat(visibility): frontend redirect when private mode active (A11.4)"
14. Update `plan/1.4.1-baselines/CHECKLIST.md` to mark A11 done
15. Push to origin/1.4.1

## Done criteria

- [ ] `Jetonomy\Visibility` class created with `can_view_community()`, `get_mode()`, `rest_check()`
- [ ] Setting `jetonomy_settings.community_visibility` registered, default `'public'`
- [ ] Admin UI in Settings → General with radio toggle
- [ ] All `auth: "public"` / `"public_with_handler"` REST endpoints call `Visibility::rest_check` first
- [ ] Frontend templates redirect anonymous users when mode is `private`
- [ ] Access matrix runner passes in `--mode=public` mode
- [ ] Access matrix runner passes in `--mode=private` mode (anonymous gets 401 for community routes; logged-in users get 200)
- [ ] Auth endpoints (`/auth/*`) and admin endpoints behave identically regardless of mode
- [ ] No new entries in `wp-content/debug.log`
- [ ] Smoke green in both Free-only and Free+Pro modes
- [ ] Manifest auto-refresh next time will pick up the new auth label `public_unless_private` for community routes — this is a follow-up for the next /wp-plugin-onboard --refresh; not part of A11.

## Forbidden

- ❌ Don't lock down `/auth/*` — that locks users out forever
- ❌ Don't lock down admin pages — they have their own cap gating
- ❌ Don't introduce a new option key outside `jetonomy_settings`
- ❌ Don't change behavior in the default (`'public'`) mode — sites without the toggle see zero difference
- ❌ Don't break Pro extensions that consume community routes (Pro REST routes follow the same `Visibility::rest_check` pattern via the parent controllers — no Pro changes needed for A11)
- ❌ Don't ship hybrid mode (per-space visibility); that's 1.5.0
