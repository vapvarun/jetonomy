# Jetonomy Hooks Reference

This is every public action and filter hook Jetonomy exposes. Whether you're building a Pro extension, a custom integration, or just tweaking behavior in your theme's `functions.php` — this is your starting point.

Hooks are organized by category. Each entry shows the hook name, what it does, its parameters, where in the codebase it fires, and a working usage example.

---

## Content Hooks

These hooks fire around post and reply lifecycle events. Use them to trigger side effects — logging, external notifications, custom counters, whatever your project needs.

---

### `jetonomy_after_create_post`

Fires immediately after a new post is successfully created and saved.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post_id` | `int` | The ID of the newly created post. |
| `$space_id` | `int` | The ID of the space the post belongs to. |

**File:** `includes/api/class-posts-controller.php`, `includes/class-abilities.php`

**Example:**

```php
add_action( 'jetonomy_after_create_post', function( int $post_id, int $space_id ) {
    // Send a Slack notification when a new post is created.
    my_plugin_notify_slack( 'new_post', $post_id, $space_id );
}, 10, 2 );
```

---

### `jetonomy_after_create_reply`

Fires immediately after a new reply is successfully created and saved. This is also the hook the built-in Notifier listens to for sending reply notifications to post authors and subscribers.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply_id` | `int` | The ID of the newly created reply. |
| `$post_id` | `int` | The ID of the post being replied to. |

**File:** `includes/api/class-replies-controller.php`, `includes/class-abilities.php`

**Example:**

```php
add_action( 'jetonomy_after_create_reply', function( int $reply_id, int $post_id ) {
    // Log reply creation to an external analytics service.
    my_analytics_track( 'reply_created', [
        'reply_id' => $reply_id,
        'post_id'  => $post_id,
        'user_id'  => get_current_user_id(),
    ] );
}, 10, 2 );
```

---

### `jetonomy_reply_accepted`

Fires when a post author marks a reply as the accepted answer. Only fires in Q&A spaces. The built-in Notifier listens to this hook to congratulate the reply author.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply_id` | `int` | The ID of the accepted reply. |
| `$post_id` | `int` | The ID of the question post. |

**File:** `includes/api/class-replies-controller.php`

**Example:**

```php
add_action( 'jetonomy_reply_accepted', function( int $reply_id, int $post_id ) {
    // Award a special badge when a reply gets accepted.
    my_badges_award( get_reply_author_id( $reply_id ), 'answer-accepted' );
}, 10, 2 );
```

---

### `jetonomy_after_vote`

Fires after a vote is cast or changed on a post or reply. The built-in Notifier listens to this hook to notify content authors about votes. The Reputation system also listens here to award reputation points.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$object_type` | `string` | Either `'post'` or `'reply'`. |
| `$object_id` | `int` | The ID of the voted-on post or reply. |
| `$voter_id` | `int` | The WordPress user ID of the person who voted. |

**File:** `includes/api/class-votes-controller.php`, `includes/class-abilities.php`

**Example:**

```php
add_action( 'jetonomy_after_vote', function( string $object_type, int $object_id, int $voter_id ) {
    if ( 'post' === $object_type ) {
        // Sync updated vote score to a search index.
        my_search_sync_post( $object_id );
    }
}, 10, 3 );
```

---

## Moderation Hooks

Use these hooks to react to moderation decisions or plug in your own content screening rules.

---

### `jetonomy_content_moderated`

Fires when a moderator takes an action (approve, spam, or trash) on a post or reply. The built-in Notifier listens to this hook to inform content authors of moderation decisions.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$action` | `string` | One of `'approved'`, `'spam'`, or `'trash'`. |
| `$object_type` | `string` | Either `'post'` or `'reply'`. |
| `$object_id` | `int` | The ID of the moderated content. |
| `$moderator_id` | `int` | The WordPress user ID of the moderator. |

**File:** `includes/api/class-moderation-controller.php`, `includes/class-abilities.php`, `includes/admin/class-admin.php`

**Example:**

```php
add_action( 'jetonomy_content_moderated', function( string $action, string $object_type, int $object_id, int $moderator_id ) {
    if ( 'spam' === $action ) {
        // Flag the author's account in your spam tracking system.
        $author_id = get_content_author_id( $object_type, $object_id );
        my_spam_tracker_flag_user( $author_id );
    }
}, 10, 4 );
```

---

### `jetonomy_check_content` (filter)

Filters new post and reply content before it is saved. This is where Pro's auto-moderation rules plug in. Return a moderation action string to intercept the submission.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$action` | `string\|null` | Default `null`. Return `'flag'`, `'hold'`, `'block'`, or `'spam'` to trigger that action. |
| `$data` | `array` | Content data array. Contains `'title'` (posts only) and `'content'` keys. |
| `$space_id` | `int` | The space ID where the content is being posted. |
| `$user_id` | `int` | The author's WordPress user ID. |

**Returns:** `string|null` — One of `'flag'`, `'hold'` (sets status to pending), `'block'` (returns error to user), `'spam'`, or `null` (no action).

**File:** `includes/api/class-posts-controller.php`, `includes/api/class-replies-controller.php`

**Example:**

```php
add_filter( 'jetonomy_check_content', function( $action, array $data, int $space_id, int $user_id ) {
    // Hold posts from Level 0 users for manual review.
    $profile = Jetonomy\Models\UserProfile::find_by_user( $user_id );
    if ( $profile && 0 === (int) $profile->trust_level ) {
        return 'hold';
    }
    return $action;
}, 10, 4 );
```

---

## User and Trust Hooks

These hooks fire when a user's standing in the community changes.

---

### `jetonomy_trust_level_changed`

Fires when a user's trust level is promoted or demoted. The built-in Notifier listens to this hook to send a congratulations notification on promotion. This hook fires both from the hourly cron job (automatic evaluation) and from WP-CLI trust level commands.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | The WordPress user ID. |
| `$old_level` | `int` | The trust level before the change (0–5). |
| `$new_level` | `int` | The trust level after the change (0–5). |

**File:** `includes/class-cron.php`, `includes/class-cli.php`

**Example:**

```php
add_action( 'jetonomy_trust_level_changed', function( int $user_id, int $old_level, int $new_level ) {
    if ( $new_level > $old_level && $new_level >= 3 ) {
        // Assign a WordPress role when a user reaches Level 3.
        $user = get_userdata( $user_id );
        if ( $user ) {
            $user->add_role( 'forum_trusted' );
        }
    }
}, 10, 3 );
```

---

### `jetonomy_reputation_changed`

Fires after a user's reputation score is adjusted. The delta can be positive (reputation awarded) or negative (reputation deducted).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | The WordPress user ID. |
| `$action` | `string` | The action that triggered the change. One of: `post_upvoted`, `reply_upvoted`, `reply_accepted`, `idea_planned`, `downvoted`, `flag_validated`, `post_reported`, `post_removed`. |
| `$delta` | `int` | Points added (positive) or removed (negative). |

**File:** `includes/trust/class-reputation.php`

**Example:**

```php
add_action( 'jetonomy_reputation_changed', function( int $user_id, string $action, int $delta ) {
    // Sync reputation score to a gamification plugin.
    my_gamification_sync_score( $user_id, $delta );
}, 10, 3 );
```

---

### `jetonomy_user_joined_space`

Fires when a user joins a space for the first time. Does not fire when an existing member's role is updated.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$space_id` | `int` | The space ID. |
| `$user_id` | `int` | The WordPress user ID of the new member. |
| `$role` | `string` | The role assigned. One of `'owner'`, `'moderator'`, or `'member'`. |

**File:** `includes/models/class-space-member.php`

**Example:**

```php
add_action( 'jetonomy_user_joined_space', function( int $space_id, int $user_id, string $role ) {
    // Send a welcome email when someone joins a specific space.
    if ( 42 === $space_id ) {
        wp_mail(
            get_userdata( $user_id )->user_email,
            'Welcome to the Community!',
            'Thanks for joining. Here is how to get started...'
        );
    }
}, 10, 3 );
```

---

## Notification Hooks

These hooks let adapters and integrations participate in the notification lifecycle.

---

### `jetonomy_membership_activated`

Fires when a MemberPress or Paid Memberships Pro membership is activated for a user.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | The WordPress user ID. |
| `$level_id` | `string` | The membership plan/level identifier. |
| `$adapter` | `string` | Which adapter fired this — `'memberpress'` or `'pmpro'`. |

**File:** `includes/adapters/class-member-press-adapter.php`, `includes/adapters/class-pmpro-adapter.php`

**Example:**

```php
add_action( 'jetonomy_membership_activated', function( int $user_id, string $level_id, string $adapter ) {
    // Grant access to a gated space when a membership is activated.
    Jetonomy\Models\SpaceMember::add( MY_PREMIUM_SPACE_ID, $user_id, 'member' );
}, 10, 3 );
```

---

### `jetonomy_membership_deactivated`

Fires when a MemberPress or Paid Memberships Pro membership is cancelled or expires.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | The WordPress user ID. |
| `$level_id` | `string` | The membership plan/level identifier. |
| `$adapter` | `string` | Which adapter fired this — `'memberpress'` or `'pmpro'`. |

**File:** `includes/adapters/class-member-press-adapter.php`, `includes/adapters/class-pmpro-adapter.php`

**Example:**

```php
add_action( 'jetonomy_membership_deactivated', function( int $user_id, string $level_id, string $adapter ) {
    // Remove a user from a gated space when their membership lapses.
    Jetonomy\Models\SpaceMember::remove( MY_PREMIUM_SPACE_ID, $user_id );
}, 10, 3 );
```

---

## Template Hooks

These hooks fire inside Jetonomy's PHP templates. Use them to inject custom HTML into the community frontend without overriding entire template files.

---

### `jetonomy_post_meta_fields`

Fires inside the single post view, below the post body. Use this to display custom field values on a post.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post` | `object` | The Jetonomy post object (stdClass with all post columns). |

**File:** `templates/views/single-post.php`

**Example:**

```php
add_action( 'jetonomy_post_meta_fields', function( object $post ) {
    $product_id = get_post_meta( $post->id, '_related_product', true );
    if ( $product_id ) {
        echo '<div class="jt-post-meta">Related product: ' . get_the_title( $product_id ) . '</div>';
    }
} );
```

---

### `jetonomy_post_actions`

Fires at the bottom of the post action bar (alongside vote buttons and view count). Use this to add custom action buttons.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post` | `object` | The Jetonomy post object. |

**File:** `templates/views/single-post.php`

**Example:**

```php
add_action( 'jetonomy_post_actions', function( object $post ) {
    if ( is_user_logged_in() ) {
        echo '<button class="jt-act" onclick="myBookmark(' . (int) $post->id . ')">Bookmark</button>';
    }
} );
```

---

### `jetonomy_reply_actions`

Fires at the bottom of each reply card, after the built-in reply controls. Use this to add custom actions to individual replies.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply` | `object` | The Jetonomy reply object (stdClass with all reply columns). |

**File:** `templates/partials/reply-card.php`

**Example:**

```php
add_action( 'jetonomy_reply_actions', function( object $reply ) {
    echo '<button class="jt-act" data-reply-id="' . (int) $reply->id . '">Report</button>';
} );
```

---

### `jetonomy_profile_display_fields`

Fires inside the user profile view, below the default stats section. Use this to display custom profile information.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | The WordPress user ID of the profile being viewed. |

**File:** `templates/views/user-profile.php`

**Example:**

```php
add_action( 'jetonomy_profile_display_fields', function( int $user_id ) {
    $company = get_user_meta( $user_id, 'company', true );
    if ( $company ) {
        echo '<p class="jt-profile-company">' . esc_html( $company ) . '</p>';
    }
} );
```

---

### `jetonomy_profile_edit_fields`

Fires inside the Edit Profile form, below the default fields. Use this to add custom inputs to the profile editing page.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | The WordPress user ID of the member editing their profile. |

**File:** `templates/views/edit-profile.php`

**Example:**

```php
add_action( 'jetonomy_profile_edit_fields', function( int $user_id ) {
    $company = get_user_meta( $user_id, 'company', true );
    echo '<div class="jt-field">';
    echo '<label for="jt-company">Company</label>';
    echo '<input type="text" id="jt-company" name="jt_company" value="' . esc_attr( $company ) . '">';
    echo '</div>';
} );
// Remember to also hook into REST API or a form submission handler to save the value.
```

---

### `jetonomy_profile_after_stats`

Fires inside the user profile view, directly after the reputation/stats row but before the custom fields area.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | The WordPress user ID of the profile being viewed. |

**File:** `templates/views/user-profile.php`

---

### `jetonomy_new_post_fields`

Fires inside the new post form, below the default editor fields. Use this to add custom inputs to the post creation form.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$space` | `object` | The Jetonomy space object for the space the post is being created in. |

**File:** `templates/views/new-post.php`

---

### `jetonomy_header_nav_items`

Fires inside the community header navigation bar, after the built-in nav links. Use this to add custom navigation items.

**Parameters:** None.

**File:** `templates/partials/header.php`

**Example:**

```php
add_action( 'jetonomy_header_nav_items', function() {
    echo '<a href="/community/resources/" class="jt-nav-link">Resources</a>';
} );
```

---

## Admin Hooks

These hooks let Pro extensions and custom plugins add UI panels into the Jetonomy admin screens without touching core files.

---

### `jetonomy_admin_dashboard_after_stats`

Fires on the Jetonomy dashboard page, after the stat cards at the top. Use this to add your own stat cards or dashboard widgets.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$stats` | `array` | The current dashboard stats array (post_count, reply_count, member_count, etc.). |

**File:** `includes/admin/views/dashboard.php`

---

### `jetonomy_admin_dashboard_widgets`

Fires inside the dashboard grid, for adding additional dashboard widgets below the built-in Recent Activity and Top Posts cards.

**Parameters:** None.

**File:** `includes/admin/views/dashboard.php`

---

### `jetonomy_admin_settings_tabs`

Fires inside the Settings page tab navigation. Use this to add your own settings tabs.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug. |

**File:** `includes/admin/views/settings.php`

**Example:**

```php
add_action( 'jetonomy_admin_settings_tabs', function( string $active_tab ) {
    $class = 'my-tab' === $active_tab ? 'nav-tab-active' : '';
    echo '<a href="?page=jetonomy-settings&tab=my-tab" class="nav-tab ' . esc_attr( $class ) . '">My Extension</a>';
} );
```

---

### `jetonomy_admin_settings_tab_content`

Fires inside the Settings page form, where tab content is rendered. Output your HTML for custom settings tabs here.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug. |

**File:** `includes/admin/views/settings.php`

**Example:**

```php
add_action( 'jetonomy_admin_settings_tab_content', function( string $active_tab ) {
    if ( 'my-tab' !== $active_tab ) return;
    echo '<table class="form-table"><tr><th>My Option</th><td>...</td></tr></table>';
} );
```

---

### `jetonomy_admin_moderation_tabs`

Fires inside the Moderation page tab navigation. Use this to add custom moderation tabs (e.g., a custom reports queue).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug. |

**File:** `includes/admin/views/moderation.php`

---

### `jetonomy_admin_moderation_tab_content`

Fires inside the Moderation page where tab content renders.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug. |

**File:** `includes/admin/views/moderation.php`

---

### `jetonomy_admin_space_edit_tabs`

Fires inside the space edit page tab navigation. Use this to add custom tabs to the space settings screen.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$space` | `object` | The Jetonomy space object being edited. |

**File:** `includes/admin/views/space-edit.php`

---

### `jetonomy_admin_space_edit_tab_content`

Fires inside the space edit page where tab content renders.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug. |
| `$space` | `object` | The Jetonomy space object being edited. |

**File:** `includes/admin/views/space-edit.php`

---

### `jetonomy_admin_render_extensions`

Fires on the Jetonomy → Extensions admin page. Pro uses this to render extension cards with activate/deactivate controls.

**Parameters:** None.

**File:** `includes/admin/class-admin.php`

---

### `jetonomy_admin_render_license`

Fires on the Jetonomy → License admin page. Pro uses this to render the license key input.

**Parameters:** None.

**File:** `includes/admin/class-admin.php`

---

## Filter Hooks

Filters let you modify data before Jetonomy uses it — without overriding any files.

---

### `jetonomy_profile_url`

Filters the URL that Jetonomy uses when linking to a user's profile. Override this to point to BuddyPress, BuddyBoss, Ultimate Member, or any other profile system.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | `string` | The default Jetonomy profile URL (`/community/u/username/`). |
| `$user_id` | `int` | The WordPress user ID. |
| `$user` | `\WP_User` | The full WP_User object. |

**Returns:** `string` The profile URL to use.

**File:** `includes/functions.php`

**Example — BuddyPress integration:**

```php
add_filter( 'jetonomy_profile_url', function( string $url, int $user_id, \WP_User $user ) {
    if ( function_exists( 'bp_core_get_user_domain' ) ) {
        return bp_core_get_user_domain( $user_id );
    }
    return $url;
}, 10, 3 );
```

---

### `jetonomy_template_map`

Filters the route-to-template file mapping. Use this to register custom routes (for Pro extensions) or override which template file a route uses.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$template_map` | `array` | Associative array of `route => template_file` pairs. Template paths may be relative (resolved against plugin/theme dirs) or absolute. |

**Returns:** `array` Modified template map.

**File:** `includes/class-template-loader.php`

**Example — registering a custom route:**

```php
add_filter( 'jetonomy_template_map', function( array $map ) {
    $map['my-custom-route'] = '/path/to/my-plugin/templates/my-template.php';
    return $map;
} );
```

---

### `jetonomy_after_post_content`

Filters HTML output that appears directly after the post body on the single post view. Return an HTML string to inject content (related posts, ads, CTAs, etc.).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$html` | `string` | Default empty string. |
| `$post` | `object` | The Jetonomy post object. |

**Returns:** `string` HTML to output after the post content.

**File:** `templates/views/single-post.php`

**Example:**

```php
add_filter( 'jetonomy_after_post_content', function( string $html, object $post ) {
    return $html . '<div class="my-cta">Join the conversation — create an account today.</div>';
}, 10, 2 );
```

---

### `jetonomy_importers`

Filters the list of registered importers. Use this to register a custom importer for a forum platform Jetonomy doesn't natively support.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$importers` | `array` | Array of registered importer instances, keyed by importer ID. Each value must implement `Jetonomy\Import\Importer`. |

**Returns:** `array` Modified importers array.

**File:** `includes/import/class-import-manager.php`

**Example:**

```php
add_filter( 'jetonomy_importers', function( array $importers ) {
    $importers['my-platform'] = new My_Platform_Importer();
    return $importers;
} );
```

---

### `jetonomy_show_community_nav`

Filters whether the community header navigation bar is displayed. Return `false` to hide the nav entirely (useful when embedding community content in a custom layout).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$show` | `bool` | Default `true`. |

**Returns:** `bool`

**File:** `templates/partials/header.php`

**Example:**

```php
add_filter( 'jetonomy_show_community_nav', '__return_false' );
```

---

### `jetonomy_admin_menu_label`

Filters the label text for the Jetonomy top-level admin menu item. Useful for white-labeling.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$label` | `string` | Default `'Jetonomy'`. |

**Returns:** `string`

**File:** `includes/admin/class-admin.php`

---

### `jetonomy_admin_menu_icon`

Filters the Dashicons icon class for the Jetonomy admin menu item.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$icon` | `string` | Default `'dashicons-groups'`. |

**Returns:** `string`

**File:** `includes/admin/class-admin.php`

---

## Tips for Extension Developers

**Namespace your hooks.** If you're building a Pro extension or third-party add-on, prefix your own hook callbacks and any hooks you introduce with your plugin's slug.

**Check capabilities before outputting admin UI.** If your `jetonomy_admin_dashboard_widgets` callback outputs sensitive data, wrap it in `if ( current_user_can( 'jetonomy_manage_settings' ) )`.

**Use the adapter pattern for integrations.** If you're integrating an external service (search, email, real-time), implement the appropriate interface from `includes/adapters/` and register your adapter via `Jetonomy\Adapters\Adapter_Registry`. This is cleaner than hooking into content lifecycle hooks directly.

**Template overrides vs. action hooks.** For injecting small pieces of HTML in specific spots, action hooks are cleaner than full template overrides. Full template overrides (via `your-theme/jetonomy/`) are best when you need to change the overall structure of a view.
