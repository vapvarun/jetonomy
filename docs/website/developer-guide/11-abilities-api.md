Expose your community to AI agents and automation through the WordPress Abilities API - 19 free abilities plus 20 more with Pro, each enforcing the same permissions as the REST API.

## What You Will Learn

- What the Abilities API is and when to use it instead of REST
- Every ability Jetonomy and Jetonomy Pro register
- How permissions work (they mirror REST exactly)
- How to execute an ability from PHP or WP-CLI

## What Is the Abilities API?

The WordPress Abilities API is a machine-readable registry of "things a site can do", each with a name, description, JSON-Schema input/output contracts, and a permission callback. AI agents, MCP servers, and automation tools discover abilities at runtime and call them without knowing your REST routes.

**Where the API comes from:** the Abilities API ships in WordPress core from version 7.0 onward. On earlier versions (6.7-6.9) it is available as the standalone **WordPress Abilities API** feature plugin - install and activate that plugin and the API works the same way.

Jetonomy registers its abilities only when the API is actually present. It hooks the API's own `wp_abilities_api_init` / `wp_abilities_api_categories_init` actions, which fire whether the API came from core (7.0+) or from the feature plugin on an older release. If neither core nor the plugin provides the API, those hooks never fire and Jetonomy simply does not register abilities - no errors, no changed behaviour, and the REST API remains the way to drive the community.

## Free Abilities

All free abilities live under the `jetonomy/` namespace, grouped into categories (`jetonomy-content`, `jetonomy-spaces`, `jetonomy-users`, `jetonomy-moderation`, `jetonomy-search`).

| Ability | What it does |
|---------|--------------|
| `jetonomy/list-spaces`, `jetonomy/get-space` | Browse spaces |
| `jetonomy/create-space`, `jetonomy/join-space`, `jetonomy/list-space-members` | Space membership |
| `jetonomy/list-posts`, `jetonomy/get-post`, `jetonomy/create-post` | Topics |
| `jetonomy/list-replies`, `jetonomy/create-reply` | Replies |
| `jetonomy/vote` | Vote on posts and replies |
| `jetonomy/search` | Full-text search |
| `jetonomy/get-activity`, `jetonomy/get-user-profile` | Member data |
| `jetonomy/list-notifications`, `jetonomy/mark-notifications-read` | Notifications |
| `jetonomy/flag-content`, `jetonomy/list-flags`, `jetonomy/moderate-content` | Moderation |

## Pro Abilities

Pro adds 20 abilities under `jetonomy-pro/` covering its extensions:

| Area | Abilities |
|------|-----------|
| Messaging | `list-conversations`, `get-conversation`, `send-message` |
| Polls | `create-poll`, `vote-poll`, `get-poll-results` |
| Reactions | `get-reactions`, `add-reaction` |
| Badges | `list-badges`, `get-user-badges`, `award-badge` |
| Custom fields | `list-custom-fields`, `set-custom-field` |
| Analytics | `get-analytics`, `export-analytics` |
| Webhooks | `list-webhooks`, `create-webhook`, `delete-webhook` |
| Moderation rules | `list-moderation-rules`, `create-moderation-rule` |

## Permissions Mirror REST

Every ability executes through the same REST stack that powers the frontend (improved in 1.5.0 - abilities previously used a separate code path). That means the permission model is identical: an agent acting as a subscriber cannot read someone else's conversation, list webhooks, or award badges, and gets the same `jetonomy_forbidden` / `rest_forbidden` errors the REST API returns. There is no separate "abilities permission system" to audit.

## Executing an Ability

From PHP:

```php
$ability = wp_get_ability( 'jetonomy/create-post' );
if ( $ability ) {
    $result = $ability->execute( [
        'space_id' => 8,
        'title'    => 'Posted by an agent',
        'content'  => 'Hello from the Abilities API.',
    ] );
}
```

Abilities that take no input are executed with no arguments: `wp_get_ability( 'jetonomy/list-spaces' )->execute()`.

From WP-CLI (WordPress 7.0+):

```
wp ability run jetonomy/search --input='{"q":"onboarding"}'
```

## What's Next?

The same operations are available over plain HTTP if you prefer direct REST calls.

[REST API →](01-rest-api.md)
