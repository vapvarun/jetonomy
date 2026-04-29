# A8 — Email Templates editor: Reset-to-default + verification_reminder row

**Branch:** `1.4.1`
**Plugin:** jetonomy (free)
**Risk:** medium — writes to `jetonomy_email_templates` option which the Notifier reads on every send
**Estimated time:** 1 day
**Reference:** `plan/1.4.1-plan.md` row A8, `plan/1.4.1-safety-checks.md` § A8 (lines 149–168)

## Re-scoping note (important)

Original brief assumed a new admin page. **The editor already exists** in the Settings → Notifications tab (`includes/admin/views/settings.php` lines 504–600), with subject/body inputs, Preview modal, and Send-test button (`jetonomy_test_email` AJAX). Building a duplicate page would bloat the submenu (already +A6 +A7) and split the UX surface.

The actual gaps the safety check § A8 calls out are:

1. **No "Reset to default" button** — once an admin saves an override, the only way back to the shipped default is to manually empty both fields and re-save (clunky, not discoverable).
2. **`verification_reminder` row is missing** from the editor — A10 seeds the default template but the sanitizer's allowlist (`class-admin.php:249`) and the view's `$tmpl_types` (`settings.php:528`) both omit it, so admins literally cannot edit/preview it.
3. **No documented defaults visible to the admin** — admins type into placeholders without seeing what the shipped default copy is.

A8 enhances the existing editor in place. **No new admin page. No class-admin.php submenu changes** (so no contention with A6/A7).

## Implementation

### Files

```
includes/admin/views/settings.php                  ← add verification_reminder row + Reset button + default-preview line
includes/admin/class-admin.php                     ← add verification_reminder to sanitize_email_templates() allowlist
includes/admin/ajax/class-settings-handler.php     ← new ajax_email_reset() handler + helper to expose defaults
includes/notifications/class-notifier.php          ← (only if needed) expose static get_default_template($type) helper if defaults aren't already centralized
```

### Step 1 — Add `verification_reminder` to the allowlist

`class-admin.php:249-260` `sanitize_email_templates()` — append `'verification_reminder'` to `$allowed_types`. Without this, the form submit silently strips the row.

### Step 2 — Add the row to the editor

`settings.php:528` `$tmpl_types` array — append:

```php
'verification_reminder' => __( 'Verification reminder', 'jetonomy' ),
```

(Mirrors the seed in `class-jetonomy.php:286-292`.)

### Step 3 — Centralize the defaults

Add a new static method in `Notifier` (or a small new class in `includes/notifications/class-email-defaults.php`):

```php
public static function get_default_template( string $type ): array {
    $defaults = [
        'user_welcome'    => [ 'subject' => __( '[{site}] Welcome to the community', 'jetonomy' ),
                              'body'    => __( "Hi {user},\n\nWelcome to {site}! …", 'jetonomy' ) ],
        'reply_to_post'   => [ 'subject' => …, 'body' => … ],
        // … one entry per allowlist type, including verification_reminder
    ];
    return $defaults[ $type ] ?? [ 'subject' => '', 'body' => '' ];
}
```

Then refactor `class-jetonomy.php:218-224` and `:286-292` to call this instead of inlining the verification_reminder strings — single source of truth. (The seed and the editor's "default preview" must agree.)

### Step 4 — "Reset to default" button per row

In `settings.php`, inside each row's `<td class="jetonomy-email-actions">` (line 573), add:

```php
<button type="button"
        class="button button-small button-link-delete jetonomy-email-reset-btn"
        data-type="<?php echo esc_attr( $type ); ?>">
    <?php esc_html_e( 'Reset to default', 'jetonomy' ); ?>
</button>
```

Show the button only when an override exists for that type (otherwise it's a no-op — render but disable, OR omit). Hidden state preferred because clicking "Reset" with no override saved is misleading.

### Step 5 — `jetonomy_email_reset` AJAX handler

`class-settings-handler.php` — new method:

```php
public function ajax_email_reset(): void {
    check_ajax_referer( 'jetonomy_admin', 'nonce' );
    if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
    }

    $type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
    $defaults = Notifier::get_default_template( $type );
    if ( empty( $defaults['subject'] ) && empty( $defaults['body'] ) ) {
        wp_send_json_error( __( 'Unknown notification type.', 'jetonomy' ) );
    }

    $templates = get_option( 'jetonomy_email_templates', array() );
    if ( isset( $templates[ $type ] ) ) {
        unset( $templates[ $type ] );
        update_option( 'jetonomy_email_templates', $templates );
    }

    wp_send_json_success( [
        'message' => __( 'Reset to default.', 'jetonomy' ),
        'subject' => $defaults['subject'],
        'body'    => $defaults['body'],
    ] );
}
```

Register: `add_action( 'wp_ajax_jetonomy_email_reset', [ $this, 'ajax_email_reset' ] );` next to the existing `wp_ajax_jetonomy_test_email`.

### Step 6 — Wire the button (JS)

In the existing `<script>` block (settings.php:602+), add a handler that:
- POSTs `jetonomy_email_reset` with `type` and `nonce`
- On success, repopulates the row's subject + body inputs with the returned defaults (live update — no page reload)
- Shows a confirm() prompt first ("Reset {label} to default? Your custom copy will be lost.") to prevent fat-finger data loss

### Step 7 — Show "Default" preview line under each row (optional but called out by safety check)

A small read-only line under the body textarea: `<p class="description">Default subject: <code>...</code></p>`. Keeps the admin honest about what they're overriding.

## Safety checks

### PRE

```bash
# Snapshot the option BEFORE any changes
wp option get jetonomy_email_templates --format=json > /tmp/a8.before.json

# Confirm verification_reminder is currently MISSING from the editor's view list
grep -c "verification_reminder" includes/admin/views/settings.php  # expect 0 in the $tmpl_types array
grep -c "verification_reminder" includes/admin/class-admin.php     # expect 0 in $allowed_types
```

### Implement, then run quality gates

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/jetonomy"

php -l includes/admin/views/settings.php
php -l includes/admin/class-admin.php
php -l includes/admin/ajax/class-settings-handler.php
php -l includes/notifications/class-notifier.php          # if you added defaults helper there

composer phpstan
composer phpcs

wp jetonomy qa-actions run                                # 210/210
bin/access-matrix-check.sh --diff-baseline                # 78/78, no drift (no REST changes)
```

### POST per safety-check § A8

1. **Save a template** → option key updated:
   ```bash
   wp option get jetonomy_email_templates --format=json > /tmp/a8.after.json
   diff /tmp/a8.before.json /tmp/a8.after.json    # only the edited template differs
   ```
2. **Send test email** for the edited template — content reflects the new copy. Use the existing `jetonomy_test_email` AJAX (Send-test button on the row).
3. **Reset to default** button — click → confirm() → row inputs repopulate with the documented defaults; option no longer contains that key:
   ```bash
   wp option get jetonomy_email_templates --format=json | jq 'has("reply_to_post")'  # expect false after reset
   ```
4. **Trigger a real notification** (e.g. post a reply on a post the test-admin user authored, expecting `reply_to_post` email) — recipient gets either the customized version (if override saved) or the rendered default (if reset).
5. **No XSS:** put `<script>alert(1)</script>` in template body, save, send → email body has the literal string, NOT script execution. The existing `wp_kses_post` on body should already handle this; verify it still does.
6. **No regression on un-edited templates:** edit only `reply_to_post`, save → `wp option get jetonomy_email_templates --format=json | jq 'keys'` should still contain any other previously-saved overrides untouched.
7. **`verification_reminder` editable end-to-end:**
   - Visible in the editor table
   - Save a custom subject/body → persisted in the option
   - Send-test button delivers a sample email (the AJAX `sample_fixtures()` map needs a row for `verification_reminder` — add it if missing in `class-settings-handler.php`)
   - Reset → defaults restored
8. **Browser:** load Settings → Notifications tab → no JS console errors (Playwright `browser_console_messages level=error` returns 0)

## Commits

```
1. feat(email): centralize default email templates in Notifier (A8.1)
2. feat(email): editable verification_reminder row + sanitizer allowlist (A8.2)
3. feat(email): per-row "Reset to default" button + AJAX handler (A8.3)
```

## Done criteria

- [ ] `verification_reminder` is editable end-to-end (view + sanitize + save + test-send + reset)
- [ ] Reset-to-default button works on every row, removes the override key from the option, repopulates inputs with documented defaults
- [ ] No XSS regression — body still goes through `wp_kses_post`, subject through `sanitize_text_field`
- [ ] Default values are now sourced from a single helper (no duplicate strings between activate() and editor preview)
- [ ] Existing Preview + Send-test still work
- [ ] CHECKLIST `plan/1.4.1-baselines/CHECKLIST.md` marks A8 done
- [ ] 210/210 smoke + 78/78 access-matrix no drift
- [ ] Push to origin/1.4.1

## Forbidden

- ❌ Don't create a new admin page or submenu (A6 + A7 already added two; the existing Notifications tab is the right home)
- ❌ Don't change `sanitize_email_templates()` to allow HTML beyond what `wp_kses_post` permits
- ❌ Don't expand the placeholder list (`{site}`, `{user}`, `{message}`, `{type}`, `{url}`) without a corresponding Notifier substitution change — A8 is editor scope only
- ❌ Don't break the existing Preview / Send-test flows — A8 is purely additive
