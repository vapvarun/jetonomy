# Jetonomy Emails — Developer Guide

How Jetonomy sends notification emails, what admins can change in the UI,
and every filter/hook available to developers.

---

## 1. Architecture

```
┌───────────────────────────────────────────┐
│  Event source                             │
│  (Notifier::notify on hooks,              │
│   Mentions::notify, integrations)         │
└──────────┬────────────────────────────────┘
           ▼
┌───────────────────────────────────────────┐
│  Notifier::send_email_notification()      │
│  • Builds unsubscribe URL + token         │
│  • Resolves subject/body template         │
│    (admin override > default)             │
│  • Substitutes placeholders               │
│  • Applies filters                        │
│  • Renders branded HTML                   │
└──────────┬────────────────────────────────┘
           ▼
┌───────────────────────────────────────────┐
│  Email adapter (via Adapter_Registry)     │
│  Default: WP_Mail_Adapter → wp_mail()     │
└───────────────────────────────────────────┘
```

## 2. Notification types

Each type has its own subject + body template and its own web/email on-off
toggle in user preferences. Current set:

| Type | When fired |
| --- | --- |
| `reply_to_post` | Someone replies to a post you authored |
| `reply_to_reply` | Someone replies to a reply you authored |
| `mention` | `@you` in a post or reply |
| `accepted_answer` | Your answer was accepted on a Q&A post |
| `new_post_in_sub` | A new post in a space you're subscribed to |
| `badge_earned` | You earned a badge (Pro) |
| `vote_on_post` | Someone voted on your post |
| `moderation` | A moderator took an action on your content |
| `join_request` | Someone asked to join a space you admin |

Integrators adding a new type: fire `jetonomy_notify($user_id, $type, ...)`
with a new type string; the template layer falls back to the default
`[Site] message` subject until the admin overrides it.

## 3. What admins can customize

**Jetonomy → Settings → Email**:

- **Email Sender**: From Name, From Email, Logo image URL
- **Footer text**: Free-form line rendered below the footer links in every email
- **Notification Defaults**: per-type web/email on/off (members inherit these on first use and can override in their own prefs)
- **Email Templates**: per-type Subject + Body/Intro override. Leave blank to use the default.

Placeholders supported in the Subject and Body fields:

| Placeholder | Expands to |
| --- | --- |
| `{site}` | Site name from `get_bloginfo('name')` |
| `{user}` | Recipient display name |
| `{message}` | The system-computed message for this event |
| `{type}` | The notification type id (e.g. `reply_to_post`) |
| `{url}` | The deep-link URL for this event (post, reply, space, etc.) |

## 4. Developer filters

All live on `Notifier`. Lowest → highest precedence (admin UI runs inside the
template resolution, plugin filters run right after).

### Subject + body

```php
add_filter( 'jetonomy_email_subject', function ( $subject, $type, $user ) {
    if ( 'mention' === $type ) {
        return sprintf( '[@mention] %s just pinged you', $user->display_name );
    }
    return $subject;
}, 10, 3 );

add_filter( 'jetonomy_email_body', function ( $body, $type, $user ) {
    return $body . "\n\nReply-by-email: reply@forum.example.com";
}, 10, 3 );
```

### Brand

```php
// Change accent color per type or in dark mode
add_filter( 'jetonomy_email_accent_color', function ( $hex, $type ) {
    return 'moderation' === $type ? '#DC2626' : $hex;
}, 10, 2 );

// Point every email at a CDN-hosted logo, bypassing the admin setting
add_filter( 'jetonomy_email_logo_url', fn() => 'https://cdn.example.com/logo@2x.png' );
```

### Headers

```php
add_filter( 'jetonomy_email_headers', function ( $headers, $type, $user ) {
    $headers[] = 'X-Jetonomy-Type: ' . $type;
    $headers[] = 'X-Jetonomy-User: ' . $user->ID;
    return $headers;
}, 10, 3 );
```

### Final HTML

```php
add_filter( 'jetonomy_email_html', function ( $html, $type, $user ) {
    // Inject a tracking pixel per type.
    $pixel = '<img src="' . esc_url( trackable_url( $type, $user->ID ) ) . '" width="1" height="1" alt="" style="display:none" />';
    return str_replace( '</body>', $pixel . '</body>', $html );
}, 10, 3 );
```

### Replacing the email adapter (SMTP, SendGrid, Postmark, etc.)

```php
add_action( 'init', function () {
    \Jetonomy\Adapters\Adapter_Registry::register_email(
        'postmark',
        new My_Plugin\Postmark_Adapter()
    );
} );
```

`Postmark_Adapter` must implement `\Jetonomy\Adapters\Email_Adapter` —
see `includes/adapters/interface-email-adapter.php`.

## 5. Avoiding double-sends

Jetonomy owns its branded flow for every **community event**. WordPress core
handles three emails that we deliberately do not replicate:

| Event | Owned by | Notes |
| --- | --- | --- |
| New user registration | **WP core** (`wp_new_user_notification`) + Jetonomy's own branded welcome | The Login block calls `do_action('jetonomy_user_registered', $user_id)` so integrators can hang a branded welcome here. We do **not** call `wp_send_new_user_notifications()` — that would duplicate core's default admin-new-user email. |
| Lost password | WP core | Initiated from `/wp-login.php?action=lostpassword`, which the Login block links to. Jetonomy never intercepts. |
| Email change confirmation | WP core | Initiated from `wp-admin` profile or `/community/u/:login/edit/`. |

If your integration wants to replace WP core's welcome email too, remove
the core action and hang your own on `jetonomy_user_registered`:

```php
remove_action( 'register_new_user', 'wp_send_new_user_notifications' );
add_action( 'jetonomy_user_registered', 'my_plugin_send_branded_welcome' );
```

## 6. Testing emails

Local by Flywheel ships Mailpit per site. For `forums.local`:

- **API / UI**: `http://127.0.0.1:10112/`
- **SMTP**: `127.0.0.1:10113`

Note: `wp-cli` on a Local site does **not** inherit PHP-FPM's `sendmail_path`,
so any email triggered via `wp --path=… eval …` or a CLI command will not
reach Mailpit. Always test email flows via the web runtime (admin-ajax,
REST, or a browser-triggered action) — this is codified in `docs/qa/QA_RELEASE_CHECKLIST.md`.

## 7. Default templates reference

For every type, the out-of-the-box subject is:

```
[{site}] {message}
```

And the body is just `{message}` wrapped in the branded envelope. Override
either per-type via the admin UI, or globally via the filters listed above.
