Connect WP Fusion CRM tags to Jetonomy spaces - when a member gains or loses a tag in your CRM, Jetonomy Pro adds or removes them from every linked space automatically. Joining a space can also apply tags back to the CRM, so the link runs both ways.

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

Jetonomy spaces live in custom database tables, not WordPress posts, so WP Fusion's built-in content-restriction meta box does not reach them. This integration is the seam that connects a CRM tag to a space.

## What You Will Learn

- How Jetonomy Pro detects WP Fusion
- How to gate a space by one or more CRM tags
- How joining and leaving a space can write tags back to the CRM
- What happens when a member's tags change in the CRM

## How Detection Works

Jetonomy Pro detects WP Fusion when the `wp_fusion()` function is available and returns a usable user object. A dedicated **WP Fusion** tab then appears on every space under **Jetonomy → Spaces → Edit Space**. If WP Fusion is not active, the tab shows an inactive message. The tag picker stays empty until WP Fusion is connected to a CRM under **WP Fusion → Settings**.

## Adding a WP Fusion Tag Rule to a Space

1. Go to **Jetonomy → Spaces** and open the space you want to gate.
2. Click the **WP Fusion** tab.
3. In the **Linked tags** field, search for and select one or more CRM tags. The picker uses WP Fusion's own searchable control, which lazy-loads tags in accounts with more than 1,000 tags.
4. Click **Save WP Fusion Settings**.

Any member who holds one of the linked tags is admitted to the space the next time Jetonomy evaluates their access. From then on, whenever a member's tags change in the CRM, Jetonomy Pro reconciles their space memberships automatically.

## What the Three Fields Do

The WP Fusion tab has three settings per space.

**Linked tags**

These are the tags that grant access to this space. A member who holds any of the linked tags is added to the space. The linked tags are also written to the **Access Rules** tab as a membership rule, so both tabs share one source of truth.

**Apply tags on join**

These extra tags are granted to the member in the CRM when they join the space through any route - accepting an invite, an admin adding them, or gaining a linked tag. Use this to tag members who enter the space so downstream CRM automations can react. These tags do not grant space access on their own.

**On leave - Remove linked tags**

When enabled, a member who leaves the space has the linked tags removed in the CRM. Leave this off if the tag also gates other content outside Jetonomy that a departing member should keep.

## Setting the Space Role

The WP Fusion tab creates the access rule at the **Member** role by default. To change the role - for example, to give certain tag holders Moderator access - open the **Access Rules** tab on the same space and adjust the role for the WP Fusion rule. Both tabs share the same underlying rule, so a change in one is reflected in the other. The **Current access linkage** summary at the bottom of the WP Fusion tab lists which tags currently grant access to the space.

## How Reconciliation Works

WP Fusion fires the `wpf_tags_modified` action whenever a member's CRM tag set changes, passing the full new tag set. Jetonomy Pro responds by walking only the WP Fusion tags that are actually referenced by a space rule, then adding the member to spaces where they now hold the tag and removing them from spaces where they no longer do.

Reconciliation is triggered by CRM updates, not by polling. Access changes take effect as soon as WP Fusion processes the tag change from the CRM.

## Troubleshooting

**WP Fusion tab is not visible on the space** - Confirm that Jetonomy Pro is active and licensed, and that WP Fusion is active.

**Tag picker is empty** - Confirm WP Fusion is connected to a CRM under **WP Fusion → Settings**. Tags are only available after a CRM connection is established.

**Member still has access after their tag was removed** - Confirm the tag removal actually triggered a sync in WP Fusion. Check the member's WP Fusion activity log. If WP Fusion did not process the change, Jetonomy Pro never receives the `wpf_tags_modified` event.

**Member does not gain access after being tagged** - Same check: confirm WP Fusion processed the tag addition and the member's WP Fusion log shows the new tag.

## What's Next?

Learn how to gate spaces by a SureMembers access group.

[SureMembers Integration →](16-suremembers.md)
