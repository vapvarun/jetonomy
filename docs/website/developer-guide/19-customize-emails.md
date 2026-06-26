Jetonomy's notification emails flow through a single pipeline: event → `Notifier` → subject/body resolution → filters → HTML render → email adapter. Every stage exposes a filter so you can adjust any aspect — subject line, body text, logo, accent color, headers, or the full rendered HTML — without replacing core template files.

**Source references:**
- `Notifier::send_email_notification()`: `includes/notifications/class-notifier.php:926`
- `Notifier::render_email_template()`: `includes/notifications/class-notifier.php:1093`
- `Notifier::locate_email_template()`: `includes/notifications/class-notifier.php:1040`
- Architecture overview: `docs/developer/EMAILS.md`

---

## Notification types

Each type has its own subject + body template and its own web/email on/off toggle in user preferences.

| Type slug | When it fires |
|-----------|---------------|
| `reply_to_post` | Someone replies to a post you authored |
| `reply_to_reply` | Someone replies to a reply you authored |
| `mention` | `@you` appears in a post or reply |
| `accepted_answer` | Your reply was accepted as the answer on a Q&A post |
| `new_post_in_sub` | A new post appears in a space you are subscribed to |
| `vote_on_post` | Someone votes on your post |
| `moderation` | A moderator takes an action on your content |
| `join_request` | Someone asks to join a space you administer |
| `badge_earned` | You earn a badge (Pro) |

---

## Placeholder tokens (admin templates)

Under **Jetonomy → Settings → Email → Email Templates**, admins can override the subject and body for each type. The following tokens are substituted before the filters fire:

| Token | Expands to |
|-------|------------|
| `{site}` | Site name from `get_bloginfo('name')` |
| `{user}` | Recipient's display name |
| `{message}` | The system-computed event message |
| `{type}` | The notification type slug |
| `{url}` | The deep-link URL for the event |
| `{post_title}` | Title of the related post (when available) |
| `{actor_display_name}` | Display name of the person who triggered the event |
| `{reply_excerpt}` | Excerpt from the related reply (when available) |
| `{space_title}` | Title of the related space (when available) |

Enriched tokens (`{post_title}`, `{actor_display_name}`, `{reply_excerpt}`, `{space_title}`) fall back to an empty string when the event has no related content, so existing templates never render a literal `{token}` string.

---

## Developer filters

All filters live on `Notifier::send_email_notification()` or `Notifier::render_email_template()`. They run after admin template overrides and placeholder substitution.

### `jetonomy_email_subject`

Filter the email subject line before sending.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$subject` | `string` | Computed subject after placeholder substitution |
| `$type` | `string` | Notification type slug |
| `$user` | `WP_User` | Notification recipient |

**Returns:** `string`

**Source:** `includes/notifications/class-notifier.php:988`

```php
add_filter( 'jetonomy_email_subject', function ( string $subject, string $type, \WP_User $user ): string {
    if ( 'mention' === $type ) {
        return '[Mention] ' . $user->display_name . ' — you were tagged in the community';
    }
    return $subject;
}, 10, 3 );
```

---

### `jetonomy_email_body`

Filter the plain-text email body (the intro sentence shown above the CTA button). Placeholder substitution has already run.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$body` | `string` | Rendered body text |
| `$type` | `string` | Notification type slug |
| `$user` | `WP_User` | Notification recipient |

**Returns:** `string`

**Source:** `includes/notifications/class-notifier.php:998`

```php
add_filter( 'jetonomy_email_body', function ( string $body, string $type, \WP_User $user ): string {
    // Append a reply-by-email instruction for reply notifications.
    if ( in_array( $type, array( 'reply_to_post', 'reply_to_reply' ), true ) ) {
        $body .= "\n\nTo reply, visit the community — reply-by-email is not supported.";
    }
    return $body;
}, 10, 3 );
```

---

### `jetonomy_email_headers`

Filter the headers array passed to the email adapter for all notification emails.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$headers` | `string[]` | Array of mail header strings (RFC 2822 format) |
| `$type` | `string` | Notification type slug |
| `$user` | `WP_User` | Notification recipient |

**Returns:** `array`

**Source:** `includes/notifications/class-notifier.php:1017`

The default headers already include `List-Unsubscribe` and `List-Unsubscribe-Post` (RFC 8058). Use this filter to append additional headers:

```php
add_filter( 'jetonomy_email_headers', function ( array $headers, string $type, \WP_User $user ): array {
    $headers[] = 'X-Community-Type: ' . $type;
    $headers[] = 'X-Community-User: ' . $user->ID;
    return $headers;
}, 10, 3 );
```

---

### `jetonomy_email_html`

Filter the fully rendered HTML of the notification email immediately before it is passed to the email adapter.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$html` | `string` | Full rendered HTML string |
| `$type` | `string` | Notification type slug |
| `$user` | `WP_User` | Notification recipient |

**Returns:** `string`

**Source:** `includes/notifications/class-notifier.php:1208`

```php
add_filter( 'jetonomy_email_html', function ( string $html, string $type, \WP_User $user ): string {
    // Inject a 1×1 tracking pixel before </body>.
    $pixel_url = esc_url( add_query_arg( array(
        'type' => $type,
        'uid'  => $user->ID,
    ), 'https://analytics.example.com/pixel.gif' ) );
    $pixel = '<img src="' . $pixel_url . '" width="1" height="1" alt="" style="display:none">';
    return str_replace( '</body>', $pixel . '</body>', $html );
}, 10, 3 );
```

---

### `jetonomy_email_accent_color`

Filter the hex accent color used in notification email templates for the header bar and CTA button. Defaults to the `accent_color` setting, falling back to `#3B82F6`.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$color` | `string` | Hex color string (e.g. `'#3B82F6'`) |
| `$type` | `string` | Notification type slug |

**Returns:** `string`

**Source:** `includes/notifications/class-notifier.php:1109`

```php
// Use a red accent for moderation emails to signal urgency.
add_filter( 'jetonomy_email_accent_color', function ( string $color, string $type ): string {
    return 'moderation' === $type ? '#DC2626' : $color;
}, 10, 2 );
```

---

### `jetonomy_email_logo_url`

Filter the logo URL rendered in the email header. Return an empty string to fall back to the site name in plain text.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | `string` | Logo URL (empty string if none configured) |
| `$type` | `string` | Notification type slug |

**Returns:** `string`

**Source:** `includes/notifications/class-notifier.php:1119`

```php
add_filter( 'jetonomy_email_logo_url', function ( string $url, string $type ): string {
    // Serve a CDN-hosted 2x logo regardless of the admin setting.
    return 'https://cdn.example.com/email-logo@2x.png';
}, 10, 2 );
```

---

### `jetonomy_email_template_context`

Filter the full context array passed into the email template renderer before the template file is included. Use this to inject additional variables that your custom template files (or a per-type template override) can read.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ctx` | `array` | Template variables array (see keys below) |
| `$type` | `string` | Notification type slug |
| `$user` | `WP_User` | Notification recipient |

**Returns:** `array`

**Source:** `includes/notifications/class-notifier.php:1191`

Standard keys in `$ctx`:

| Key | Type | Description |
|-----|------|-------------|
| `type` | `string` | Notification type slug |
| `type_label` | `string` | Human-readable label (e.g. `'New Reply'`) |
| `site_name` | `string` | HTML-escaped site name |
| `home_url` | `string` | Escaped home URL |
| `community_url` | `string` | URL for the event's deep link |
| `notif_url` | `string` | URL to the notifications page |
| `unsub_url` | `string` | Signed unsubscribe URL |
| `accent` | `string` | Raw hex accent color |
| `logo_url` | `string` | Escaped logo URL (empty string if none) |
| `cta_text` | `string` | CTA button label |
| `cta_url` | `string` | CTA button URL |
| `message` | `string` | Rendered event message (raw; template escapes it) |
| `footer_text` | `string` | Footer line from settings |
| `post_title` | `string` | Related post title (empty if not applicable) |
| `actor_display_name` | `string` | Name of the person who triggered the event |
| `reply_excerpt` | `string` | Excerpt from the triggering reply |
| `space_title` | `string` | Title of the related space |
| `user` | `WP_User` | Recipient user object |

```php
add_filter( 'jetonomy_email_template_context', function ( array $ctx, string $type, \WP_User $user ): array {
    // Add the user's membership tier so the template can render a personalized footer.
    $ctx['membership_tier'] = My_Membership::get_tier_label( $user->ID );
    return $ctx;
}, 10, 3 );
```

---

### `jetonomy_email_template_path`

Filter the resolved template file path before it is included. The lookup order before this filter is: child-theme override (`yourtheme/jetonomy/emails/{type}.php`) → plugin per-type (`templates/emails/{type}.php`) → child-theme base → plugin base (`templates/emails/base.php`).

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$path` | `string` | Absolute filesystem path to the template file |
| `$type` | `string` | Notification type slug |

**Returns:** `string`

**Source:** `includes/notifications/class-notifier.php:1076`

```php
// Point a specific type to a completely custom template.
add_filter( 'jetonomy_email_template_path', function ( string $path, string $type ): string {
    if ( 'mention' === $type ) {
        return MY_PLUGIN_DIR . 'templates/email-mention.php';
    }
    return $path;
}, 10, 2 );
```

---

## Overriding a template file in a child theme

Without any PHP code, create a file at:

```
your-theme/jetonomy/emails/{type}.php
```

where `{type}` is the notification type slug with underscores replaced by hyphens — for example `reply-to-post.php` for the `reply_to_post` type. If no per-type file exists, `base.php` is used.

Inside the file you have access to all variables from the `$ctx` array above (extracted into local scope). Follow the structure of `wp-content/plugins/jetonomy/templates/emails/base.php` as your starting point.

---

## Replacing the email adapter

To send Jetonomy emails through SMTP, SendGrid, Postmark, or any other provider, register a custom adapter. The adapter must implement `\Jetonomy\Adapters\Email_Adapter` (see `includes/adapters/interface-email-adapter.php`):

```php
add_action( 'init', function () {
    \Jetonomy\Adapters\Adapter_Registry::register_email(
        'my-smtp',
        new My_Plugin\SMTP_Adapter()
    );
} );
```

```php
// My_Plugin\SMTP_Adapter:
class SMTP_Adapter implements \Jetonomy\Adapters\Email_Adapter {
    public function send(
        string $to,
        string $subject,
        string $html,
        string $plain,
        array  $headers
    ): bool {
        return My_SMTP_Service::send( $to, $subject, $html, $headers );
    }
}
```

See [Adapters](./05-adapters.md) for the full adapter pattern documentation.

---

## What's next

- [Hooks Reference — `jetonomy_notification_created`](./02-hooks-reference.md#jetonomy_notification_created) — listen for notification creation events to forward to mobile push or webhooks
- [Extend the REST API](./18-extend-the-rest-api.md) — expose notification data via a custom endpoint
- [Adapters](./05-adapters.md) — swap the email transport entirely
