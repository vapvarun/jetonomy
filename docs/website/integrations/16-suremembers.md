Connect SureMembers access groups to Jetonomy spaces - when a member is granted an access group, Jetonomy Pro adds them to every linked space, and revoking the group removes them. Joining a space can also grant access groups back to the member, so the link runs both ways.

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

SureMembers restricts WordPress post-type content natively. Jetonomy spaces live in custom database tables, not posts, so SureMembers' standard content-restriction settings do not apply to them. This integration is the way to connect a SureMembers access group to a Jetonomy space.

## What You Will Learn

- How Jetonomy Pro detects SureMembers
- How to gate a space by one or more access groups
- How joining and leaving a space can grant or revoke access groups
- What happens when an access group is granted or revoked

## How Detection Works

Jetonomy Pro detects SureMembers when its `Access_Groups` and `Access` classes are available. A dedicated **SureMembers** tab then appears on every space under **Jetonomy → Spaces → Edit Space**. If SureMembers is not active, the tab shows an inactive message. Only active SureMembers access groups appear in the picker.

## Adding a SureMembers Rule to a Space

1. Go to **Jetonomy → Spaces** and open the space you want to gate.
2. Click the **SureMembers** tab.
3. In the **Linked access groups** field, select one or more access groups from the list.
4. Click **Save SureMembers Settings**.

Members who hold one of the linked access groups are admitted to the space the next time their access is evaluated. From then on, when SureMembers grants or revokes an access group, Jetonomy Pro updates the member's space membership automatically.

## What the Three Fields Do

The SureMembers tab has three settings per space.

**Linked access groups**

These are the access groups that grant membership to this space. A member who holds any of the linked access groups is added to the space. Access is checked through SureMembers' own logic, so an expired or inactive access group is treated as not held and access is removed.

**Apply access groups on join**

These extra access groups are granted to the member through SureMembers when they join the space through any route. Use this to open protected SureMembers content to members at the moment they enter the space. These access groups do not grant space access on their own.

**On leave - Remove linked access groups**

When enabled, a member who leaves the space has the linked access groups revoked in SureMembers. Leave this off if the access group also gates other SureMembers-protected content the member should keep.

## Setting the Space Role

The SureMembers tab creates the access rule at the **Member** role by default. To change the role, open the **Access Rules** tab on the same space and adjust the role for the SureMembers rule. Both tabs share the same underlying rule. The **Current access linkage** summary at the bottom of the SureMembers tab lists which access groups currently grant access to the space.

## How Reconciliation Works

SureMembers fires `suremembers_user_access_group_granted` when a member receives an access group and `suremembers_user_access_group_revoked` when one is taken away. Jetonomy Pro listens to both actions and adds or removes the member from every space that references the affected access group.

Because access is evaluated through SureMembers' own access check, an access group that has expired is treated as not held. The member is removed from the space when SureMembers fires the revoked action for them.

## Troubleshooting

**SureMembers tab is not visible on the space** - Confirm that Jetonomy Pro is active and licensed, and that SureMembers is active.

**Access group list is empty** - Confirm you have at least one active access group in **SureMembers → Access Groups**. Inactive or draft access groups do not appear in the picker.

**Member still has access after their access group was revoked** - Confirm the revocation went through SureMembers' standard API so the `suremembers_user_access_group_revoked` action fires. Code that updates the database directly without firing the action will not trigger removal.

**Member does not gain access after being granted an access group** - Confirm the grant used SureMembers' standard API so the `suremembers_user_access_group_granted` action fires. Check whether the space lists the access group under **Current access linkage** at the bottom of the SureMembers tab.

## What's Next?

Return to the [Integrations overview](00-overview.md) to see every membership, LMS, and community integration in one place.
