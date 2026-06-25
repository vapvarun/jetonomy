Connect MemberPress membership levels to Jetonomy spaces - so paying members automatically land in the right discussion areas the moment their subscription activates.

> **Available in Jetonomy free.** The MemberPress and Paid Memberships Pro adapters ship in the free plugin - you do not need Jetonomy Pro to gate spaces by these two membership plugins. (WooCommerce, Restrict Content Pro, and all LMS integrations require Jetonomy Pro.)

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

This is the standard Access Rules flow that every membership and LMS integration in this section follows. The other integration guides link back here for the full walkthrough.

![Jetonomy Access Rules tab showing a saved membership rule with its Type, Value, Grants, and Space Role columns](images/access-rules-with-rule.png)

1. Go to **Jetonomy → Spaces** and open the space you want to gate.
2. Click the **Access Rules** tab in the space settings panel.
3. Set **Rule Type** to your MemberPress level (membership levels appear in the dropdown once MemberPress is active).
4. Pick the membership level in the **Value** field.
5. Choose what the rule **Grants** - Read, Participate, or Full (see below).
6. Choose the **Space Role** members get when they match - Viewer, Member, Moderator, or Admin (see below).
7. Click **Add Rule**. The rule appears in the table below the form.

Members who hold the selected level gain access to this space at the level you chose. Members without it see the space as locked (or hidden, depending on your space visibility setting).

> **Tip:** Add more than one rule if you want to grant access for more than one membership level. Rules are evaluated top to bottom by priority, and a member passes on the first rule they match.

## Grants and Space Role

Every Access Rule has two settings that decide *what* a matching member can do. They are the same across all integrations:

**Grants** - how much of the space the member can use:

| Grants | What the member can do |
|---|---|
| Read | View topics and replies, but not post or reply |
| Participate | Read, post topics, and reply (the usual choice for a course or paid space) |
| Full | Participate plus the space-management abilities tied to their Space Role |

**Space Role** - the role the member is given inside this one space:

| Space Role | Meaning |
|---|---|
| Viewer | Read-only presence in the space |
| Member | A regular participating member (the usual choice) |
| Moderator | Can moderate content in this space |
| Admin | Can manage this space's settings and members |

For most gated spaces - a paid membership or a course community - set **Grants: Participate** and **Space Role: Member**. Use Moderator or Admin only when you specifically want a membership tier to run the space.

> **Note:** There is no separate "Grant vs Revoke" switch. A rule always *grants* the access you choose to members who match it; members who match no rule simply do not get in. To take a level's access away, delete its rule.

## Auto-Join and Auto-Leave

When a MemberPress membership **activates**, Jetonomy automatically adds the member to any spaces whose Access Rules grant that level. They receive a welcome notification in the community.

When a membership **expires, cancels, or is paused**, Jetonomy fires `jetonomy_membership_deactivated` and removes the member from any spaces gated exclusively to that level. Their posts and replies remain intact.

This is handled by the `MemberPress_Adapter` class. It hooks `mepr-txn-status-complete` for activation, and `mepr-txn-status-refunded`, `mepr-txn-expired`, and `mepr_subscription_transition_status` for deactivation.

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

**Access rules dropdown is empty** - MemberPress may not be active. Check **Plugins → Installed Plugins** and confirm MemberPress is activated.

**Member not joining on activation** - Ensure the membership level ID in the Access Rule exactly matches the level in MemberPress. Level IDs are numeric; check the MemberPress level edit URL for the ID.

**Member still has access after expiry** - Check whether the member holds a second membership level that also grants access to the space.

## What's Next?

Learn how to gate spaces using Paid Memberships Pro, which follows the same pattern.

[Paid Memberships Pro Integration →](02-pmpro.md)
