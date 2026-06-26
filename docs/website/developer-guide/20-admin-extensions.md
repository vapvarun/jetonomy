Jetonomy registers its admin UI under a single top-level menu (Jetonomy → Dashboard / Spaces / Categories / Users / Moderation / Settings / Import). Every admin page exposes action and filter hooks so companion plugins and Pro extensions can inject tabs, widgets, and settings panels without patching core files.

## Admin Pages at a Glance

| Page | WP menu slug | Extension hooks |
|------|-------------|-----------------|
| Dashboard | `jetonomy-dashboard` | `jetonomy_admin_dashboard_widgets`, `jetonomy_admin_dashboard_after_stats` |
| Spaces list | `jetonomy-spaces` | *(no list-table column hooks - see Known Gap)* |
| Space edit | `jetonomy-spaces&action=edit&id=N` | `jetonomy_admin_space_edit_tabs`, `jetonomy_admin_space_edit_tab_content` |
| Settings | `jetonomy-settings` | `jetonomy_admin_settings_tabs`, `jetonomy_admin_settings_tab_content` |
| Moderation | `jetonomy-moderation` | `jetonomy_admin_moderation_tabs`, `jetonomy_admin_moderation_tab_content` |
| All pages | n/a | `jetonomy_admin_menu_label`, `jetonomy_admin_menu_icon`, `jetonomy_admin_footer_text` |

All hook signatures on this page are verified from source. Where the summary table in [02-hooks-reference.md](./02-hooks-reference.md) differs (it lists some parameters as "none"), the source file is the authoritative reference.

---

## Settings Page Tabs

The Settings page (`Jetonomy → Settings`) has eight built-in primary tabs: `general`, `permissions`, `email`, `appearance`, `seo`, `antispam`, `free-vs-pro`, and `license`. Two hooks let you add more.

### `jetonomy_admin_settings_tabs`

Fires inside the Settings page tab bar. Output a `<a class="nav-tab">` element to add a new entry.

**Source:** `includes/admin/views/settings.php`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug (from `$_GET['tab']`, defaulting to `'general'`) |

```php
add_action( 'jetonomy_admin_settings_tabs', function( string $active_tab ) {
    $class = 'acme-settings' === $active_tab ? 'nav-tab-active' : '';
    printf(
        '<a href="%s" class="nav-tab %s">%s</a>',
        esc_url( admin_url( 'admin.php?page=jetonomy-settings&tab=acme-settings' ) ),
        esc_attr( $class ),
        esc_html__( 'Acme', 'acme' )
    );
} );
```

### `jetonomy_admin_settings_tab_content`

Fires inside the Settings page content area. Guard your output with a slug check; other tab content hooks will also receive the callback.

The Settings view output-buffers this hook so any `.notice` divs you emit are automatically hoisted above the page layout. You do not need to handle hoisting yourself.

**Source:** `includes/admin/views/settings.php`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug |

```php
add_action( 'jetonomy_admin_settings_tab_content', function( string $active_tab ) {
    if ( 'acme-settings' !== $active_tab ) {
        return;
    }

    $limit = (int) get_option( 'acme_widget_limit', 5 );
    ?>
    <div class="wrap jetonomy-settings-wrap">
        <form method="post">
            <?php wp_nonce_field( 'acme_settings_save', 'acme_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="acme_widget_limit">
                            <?php esc_html_e( 'Widget limit', 'acme' ); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            type="number" min="1" max="50"
                            id="acme_widget_limit"
                            name="acme_widget_limit"
                            value="<?php echo esc_attr( $limit ); ?>"
                            class="small-text"
                        >
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
} );

// Save handler - runs before the view (admin_init fires earlier than the page render).
add_action( 'admin_init', function() {
    if (
        isset( $_POST['acme_nonce'] ) &&
        wp_verify_nonce( sanitize_key( $_POST['acme_nonce'] ), 'acme_settings_save' ) &&
        current_user_can( 'manage_options' )
    ) {
        update_option( 'acme_widget_limit', absint( $_POST['acme_widget_limit'] ?? 5 ) );
        add_settings_error( 'acme', 'acme_saved', __( 'Settings saved.', 'acme' ), 'updated' );
        set_transient( 'settings_errors', get_settings_errors(), 30 );
    }
} );
```

---

## Space Edit Page Tabs

The space edit page (`Spaces → Edit`) shows built-in tabs for General, SEO, Members, Access Rules, and Moderation. Jetonomy Pro injects its Custom Fields, Reactions, and AI tabs through these same hooks.

### `jetonomy_admin_space_edit_tabs`

Fires inside the space edit tab bar. Output a `<a class="nav-tab">` element.

**Source:** `includes/admin/views/space-edit.php`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$space` | `object` | The space row object being edited. Properties include `id`, `name`, `slug`, `description`, `status`, `type`, `settings` |

```php
add_action( 'jetonomy_admin_space_edit_tabs', function( object $space ) {
    $active_tab = sanitize_key( $_GET['tab'] ?? 'general' );
    $class      = 'acme-space-tab' === $active_tab ? 'nav-tab-active' : '';
    printf(
        '<a href="%s" class="nav-tab %s">%s</a>',
        esc_url( add_query_arg( 'tab', 'acme-space-tab' ) ),
        esc_attr( $class ),
        esc_html__( 'Acme', 'acme' )
    );
} );
```

### `jetonomy_admin_space_edit_tab_content`

Fires inside the space edit content area. Receives both the active tab slug and the full space object.

**Source:** `includes/admin/views/space-edit.php`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug |
| `$space` | `object` | The space row object |

```php
add_action( 'jetonomy_admin_space_edit_tab_content', function( string $active_tab, object $space ) {
    if ( 'acme-space-tab' !== $active_tab ) {
        return;
    }

    echo '<div class="jetonomy-tab-content">';
    printf(
        '<h3>%s %s</h3>',
        esc_html__( 'Acme settings for:', 'acme' ),
        esc_html( $space->name )
    );
    // Your per-space form here. Read/write space settings via the REST API
    // or store your own data in wp_options keyed by $space->id.
    echo '</div>';
}, 10, 2 );
```

---

## Moderation Page Tabs

The Moderation page shows Posts, Replies, and Flags tabs. Jetonomy Pro hooks its Auto-Rules tab here.

### `jetonomy_admin_moderation_tabs`

Fires inside the Moderation page tab bar.

**Source:** `includes/admin/views/moderation.php`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug (e.g. `'posts'`, `'replies'`, `'flags'`) |

### `jetonomy_admin_moderation_tab_content`

Fires inside the Moderation page content area.

**Source:** `includes/admin/views/moderation.php`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active_tab` | `string` | The currently active tab slug |

```php
// Register the moderation tab nav item.
add_action( 'jetonomy_admin_moderation_tabs', function( string $active_tab ) {
    $class = 'acme-mod-panel' === $active_tab ? 'nav-tab-active' : '';
    printf(
        '<a href="%s" class="nav-tab %s">%s</a>',
        esc_url( add_query_arg( 'tab', 'acme-mod-panel' ) ),
        esc_attr( $class ),
        esc_html__( 'Acme Rules', 'acme' )
    );
} );

// Render the moderation tab content.
add_action( 'jetonomy_admin_moderation_tab_content', function( string $active_tab ) {
    if ( 'acme-mod-panel' !== $active_tab ) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h2>' . esc_html__( 'Acme Moderation Rules', 'acme' ) . '</h2>';
    // Your moderation panel here.
    echo '</div>';
} );
```

---

## Dashboard Widgets

### `jetonomy_admin_dashboard_widgets`

Fires inside the dashboard grid column, after the built-in Recent Activity and Trending cards. Jetonomy Pro hooks its analytics panel here. No parameters.

**Source:** `includes/admin/views/dashboard.php`

```php
add_action( 'jetonomy_admin_dashboard_widgets', function() {
    echo '<div class="jetonomy-dashboard-card">';
    echo '<h2>' . esc_html__( 'Acme Stats', 'acme' ) . '</h2>';
    echo '<p>' . esc_html( acme_get_stat_summary() ) . '</p>';
    echo '</div>';
} );
```

### `jetonomy_admin_dashboard_after_stats`

Fires immediately after the top-row stat cards and before the dashboard grid. Use this for a wide banner, a secondary summary row, or a notice that should span the full column width.

**Source:** `includes/admin/views/dashboard.php`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$stats` | `array` | Site-wide totals. Keys: `total_posts` (int), `total_replies` (int), `active_spaces` (int), `users` (int), `pending_flags` (int), `posts_today` (int) |

```php
add_action( 'jetonomy_admin_dashboard_after_stats', function( array $stats ) {
    if ( $stats['pending_flags'] > 10 ) {
        printf(
            '<div class="notice notice-warning inline"><p>%s</p></div>',
            esc_html( sprintf(
                /* translators: %d: number of pending flags */
                __( '%d flags are waiting for review.', 'acme' ),
                $stats['pending_flags']
            ) )
        );
    }
} );
```

---

## Menu White-Labelling

Three filters let you rebrand how Jetonomy appears in the wp-admin sidebar. All three are filters, not actions.

### `jetonomy_admin_menu_label`

Filters the top-level admin menu label. Default: `'Jetonomy'`.

**Source:** `includes/admin/class-admin.php`

```php
add_filter( 'jetonomy_admin_menu_label', fn() => 'Community' );
```

### `jetonomy_admin_menu_icon`

Filters the admin menu icon. Default is the Jetonomy SVG glyph encoded as a `data:image/svg+xml;base64,…` URI. Return any Dashicons class string or your own `data:` URI.

**Source:** `includes/admin/class-admin.php`

```php
// Use a Dashicons icon.
add_filter( 'jetonomy_admin_menu_icon', fn() => 'dashicons-groups' );

// Or supply a custom SVG.
add_filter( 'jetonomy_admin_menu_icon', function(): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><!-- ... --></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode( $svg );
} );
```

### `jetonomy_admin_footer_text`

Filters the footer text shown at the bottom of every Jetonomy admin page, replacing the default WordPress "Thank you for creating with WordPress" line on Jetonomy pages only.

**Source:** `includes/admin/class-admin.php`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$text` | `string` | Default footer text |

**Return:** `string`

```php
add_filter( 'jetonomy_admin_footer_text', function( string $text ): string {
    return sprintf(
        /* translators: %1$s: brand name, %2$s: support link */
        __( 'Powered by %1$s &mdash; %2$s', 'acme' ),
        '<strong>' . esc_html__( 'Acme Community Suite', 'acme' ) . '</strong>',
        '<a href="https://example.com/support/" target="_blank">' . esc_html__( 'Get support', 'acme' ) . '</a>'
    );
} );
```

---

## Known Gap: Admin List-Table Columns

There is no filter to add a column to any Jetonomy admin list table (Spaces, Posts, Replies, Users, Activity Log). The column definitions in each list table are hard-coded. If you need to surface per-row data:

- Inject a notice or summary panel via `jetonomy_admin_dashboard_after_stats` (dashboard) or a custom admin page registered under the Jetonomy menu with `add_submenu_page`.
- Add a space-level panel via `jetonomy_admin_space_edit_tab_content` on a new tab.
- Store per-post or per-space data and expose it through the REST API; the admin list tables do not yet expose column hooks.

Column hooks are planned but not yet landed.

---

## What's Next?

- [Hooks Reference](./02-hooks-reference.md) - Full listing of every `jetonomy_*` action and filter
- [REST API Reference](./01-rest-api.md) - Extend or read data via the REST layer
- [Template Overrides](./03-template-overrides.md) - Customize front-end templates
