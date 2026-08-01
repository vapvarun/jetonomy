Gate Jetonomy spaces by Paid Memberships Pro subscription level - with automatic access granted on activation and revoked on cancellation or expiry.

> **Available in Jetonomy free.** The Paid Memberships Pro adapter ships in the free plugin - Jetonomy Pro is not required to gate spaces by PMPro levels.

![Jetonomy admin settings showing integration configuration options](../images/admin-settings.png)

## What You Will Learn

- How Jetonomy detects Paid Memberships Pro (PMPro)
- How to set up an Access Rule tied to a PMPro level
- What triggers the auto-join and auto-leave behavior
- The hook names you can use for custom logic

## How Detection Works

Jetonomy detects PMPro automatically when the plugin is active. The PMPro adapter registers with Jetonomy's Adapter Registry and unlocks the **Access Rules** tab in space settings. No manual connection step is required.

> **Note:** If you activate PMPro after Jetonomy is already running, go to **Jetonomy → Settings** and save the page once to trigger adapter re-registration.

## Setting Up an Access Rule

1. Go to **Jetonomy → Spaces** and open the space you want to gate.
2. Open the **Access Rules** tab in the space settings panel.
3. Set **Rule Type** to your Paid Memberships Pro level (PMPro levels appear in the dropdown once PMPro is active).
4. Pick the PMPro level in the **Value** field.
5. Set the **Access level** to **Participate** - the default, and the right answer for a standard gated space.
6. Click **Add Rule**. The rule appears in the table below the form.

Members who hold the selected PMPro level gain access to the space immediately. You can stack multiple rules - access is granted if the member matches any rule.

> **Tip:** For a full explanation of the **Access level** (Read / Participate / Full) choices, see [Access level](01-memberpress.md#access-level) in the MemberPress guide - they work the same for every integration.

## Access Follows the Subscription

Access is worked out when someone opens the space, by reading your Access Rules against the levels they hold right now. There is nothing to synchronise.

**On activation** - the member can open the space on their next page load. The hook `jetonomy_membership_activated` fires with `$adapter = 'pmpro'`.

**On cancellation or expiry** - they lose access just as immediately. The hook `jetonomy_membership_deactivated` fires with `$adapter = 'pmpro'`. Their posts and replies stay exactly as they are.

The adapter hooks into PMPro's `pmpro_after_change_membership_level` action to detect both events.

> **The roster is separate.** A member let in by a rule does not appear in the space's Members list or member count until you press **Sync Members**, and those rows are not removed again when a level lapses. Access is always correct either way - see [Access Follows the Subscription](01-memberpress.md#access-follows-the-subscription) for the full explanation.

A visitor without the level is shown which plan opens the space, with a button to PMPro checkout with that level already selected.

## Visibility Behavior

| Space Visibility | Non-member sees... |
|---|---|
| Public | Space listed, content readable, posting locked |
| Private | Space listed with lock icon, content hidden |
| Hidden | Space not listed at all |

## Developer Hook

```php
// Fires when a PMPro membership deactivates.
add_action( 'jetonomy_membership_deactivated', function( int $user_id, string $level_id, string $adapter ) {
    if ( 'pmpro' === $adapter ) {
        // Remove user from any related external system.
        my_crm_remove_access( $user_id, $level_id );
    }
}, 10, 3 );
```

## Troubleshooting

**Level dropdown is empty in Access Rules** - Confirm PMPro is active and that you have at least one membership level created in **Memberships → Membership Levels**.

**Member not removed on cancellation** - PMPro has several cancellation states (expired, admin-cancelled, non-renewing). Jetonomy listens to all of them via `pmpro_after_change_membership_level` where the new level is `0`.

**Multiple levels, unexpected access** - If a user holds more than one PMPro level, Jetonomy evaluates all rules. Access persists as long as any single rule still matches an active level.

## What's Next?

Gate spaces using a WooCommerce product purchase or subscription - available in Jetonomy Pro.

[WooCommerce Integration →](03-woocommerce.md)
