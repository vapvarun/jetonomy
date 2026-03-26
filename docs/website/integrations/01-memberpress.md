Connect MemberPress membership levels to Jetonomy spaces — so paying members automatically land in the right discussion areas the moment their subscription activates.

![Jetonomy admin settings panel for configuring integrations](../images/admin-settings.png)

## What You Will Learn

- How Jetonomy detects and communicates with MemberPress
- How to gate a space by membership level using Access Rules
- What happens when a membership activates or expires
- How to test the integration before going live

## How Detection Works

Jetonomy checks for MemberPress automatically on every page load. No configuration needed. When MemberPress is active, the MemberPress adapter registers itself with Jetonomy's Adapter Registry and enables the Access Rules UI inside each space's settings.

> **Note:** If you activate MemberPress after Jetonomy, navigate to **Jetonomy → Settings** and save once. This triggers adapter re-registration.

## Setting Up an Access Rule

1. Go to **Jetonomy → Spaces** and open the space you want to gate.
2. Click the **Access Rules** tab in the space settings panel.
3. Click **Add Rule**.
4. Set **Rule Type** to **MemberPress Level**.
5. Select the membership level from the dropdown.
6. Choose the access action: **Grant** or **Revoke**.
7. Click **Save Space**.

Members who hold the selected level gain access to this space. Members without it see the space as locked (or hidden, depending on your space visibility setting).

> **Tip:** Combine multiple rules if you want to grant access to more than one membership level. Each rule is evaluated independently — a member passes if they match any Grant rule.

## Auto-Join and Auto-Leave

When a MemberPress membership **activates**, Jetonomy automatically adds the member to any spaces whose Access Rules grant that level. They receive a welcome notification in the community.

When a membership **expires, cancels, or is paused**, Jetonomy fires `jetonomy_membership_deactivated` and removes the member from any spaces gated exclusively to that level. Their posts and replies remain intact.

This is handled by the `MemberPress_Adapter` class, which hooks into MemberPress's `mepr-event-transaction-completed` and `mepr-event-subscription-expired` events.

## Visibility Behavior

| Space Visibility | Non-member sees... |
|---|---|
| Public | Space listed, content visible, locked from posting |
| Private | Space listed with lock icon, content hidden |
| Hidden | Space not listed at all |

## Developer Hook

Both membership events fire the Jetonomy standard hooks you can use in your own code:

```php
// Fires when a MemberPress membership activates.
add_action( 'jetonomy_membership_activated', function( int $user_id, string $level_id, string $adapter ) {
    // $adapter will be 'memberpress'
    if ( 'memberpress' === $adapter ) {
        // Custom logic here.
    }
}, 10, 3 );
```

## Troubleshooting

**Access rules dropdown is empty** — MemberPress may not be active. Check **Plugins → Installed Plugins** and confirm MemberPress is activated.

**Member not joining on activation** — Ensure the membership level ID in the Access Rule exactly matches the level in MemberPress. Level IDs are numeric; check the MemberPress level edit URL for the ID.

**Member still has access after expiry** — Check whether the member holds a second membership level that also grants access to the space.

## What's Next?

Learn how to gate spaces using Paid Memberships Pro, which follows the same pattern.

[Paid Memberships Pro Integration →](02-pmpro.md)
