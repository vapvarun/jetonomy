The Wbcom stack section of the Integrations tab lets you install companion plugins from the Wbcom family without leaving WordPress. Each companion adds a distinct capability alongside your Jetonomy community. Installing is one click for free companions; Pro companions require your own valid license from the store.

## What You Will Learn

- Where to find the companion installer
- Which companion plugins are available
- How the install and activate flow works
- How to extend the catalog with the `jetonomy_companions` filter

Go to **Jetonomy → Settings → Integrations** to access the companion cards. The tab is only visible when BuddyPress with Groups is not the only active integration; the companion cards appear on all installs.

## Available Companions

Jetonomy ships with a built-in catalog of six companions. Each card shows a status badge (Connected, Installed, or Not installed) and the action that matches that status.

**WB Gamification**
Points, badges, and leaderboards for posts, replies, and community activity. Adds gamified recognition directly into your community alongside trust levels.

**MediaVerse**
A social media layer with photo and video feeds alongside your community discussions. Members can post media alongside their text topics.

**BuddyNext**
Community engine that adds profiles, activity feeds, and member spaces. Pairs with Jetonomy to give members a social home next to their discussion spaces.

**Learnomy**
Sell and deliver online courses, then gate community spaces on course enrollment. Uses Jetonomy's built-in Learnomy access adapter so course completion unlocks spaces automatically.

**WP Career Board**
Job listings and applicant management with employer profiles. Job posts can surface as discussion topics in your community.

**Listora**
Member-submitted directory listings. Member listings are shared into community content alongside discussions.

## How Install and Activate Works

Each companion card shows one of three states:

- **Connected** - the companion is installed and active. Jetonomy detects it automatically via a capability probe, and the "what this unlocks" line appears on the card. No action is needed.
- **Installed, activate** - the plugin files are on disk but the plugin is not active. A single "Activate" button activates it without leaving the settings screen.
- **Not installed** - the plugin is not on disk. An "Install free" button triggers the one-click install.

When you click "Install free", Jetonomy posts to the store at `wbcomdesigns.com` using the companion's built-in free distribution key, retrieves the signed download URL, hands it to WordPress's own plugin installer, and then activates the plugin in one flow. You are redirected back to the Integrations tab with a success or error notice. Free companions use a baked-in key and do not require you to enter any license. Pro companions are not listed here with an install button; their product page link opens the store so you can purchase and install manually.

Once a companion is active, Jetonomy's integration code detects it on every load and enables the matching features. Deactivating a companion from the standard Plugins screen returns those features to their unconnected state; no Jetonomy data is removed.

## Extending the Catalog

The companion catalog is filterable. Pro plugins and third-party integrations can add their own entries:

```php
add_filter( 'jetonomy_companions', function( array $companions ): array {
    $companions['my-plugin'] = [
        'label'     => 'My Plugin',
        'why'       => 'Short description of what it adds.',
        'detect'    => static fn() => defined( 'MY_PLUGIN_VERSION' ),
        'free'      => [
            'item_id'  => 0,      // EDD item ID; 0 disables one-click install.
            'key'      => '',
            'basename' => 'my-plugin/my-plugin.php',
        ],
        'store_url' => 'https://example.com/my-plugin/',
        'unlocks'   => 'What lights up in Jetonomy when this is active.',
    ];
    return $companions;
} );
```

Set `item_id` to `0` to suppress the one-click install button and show only the "Learn more" store link. The `detect` callable is what Jetonomy uses to determine whether the companion is active - it should return `true` when the companion's capability is live.
