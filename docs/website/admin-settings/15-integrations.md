The Integrations tab holds the settings that keep Jetonomy and BuddyPress group activity in sync. It appears in **Jetonomy → Settings** only when BuddyPress with the Groups component is active on your site.

## What You Will Learn

- Where the Integrations tab appears and when it is visible
- What the two BuddyPress sync toggles do
- The dependency between the two toggles

![Integrations tab showing the two BuddyPress sync toggles](../images/admin-integrations.png)

Go to **Jetonomy → Settings → Integrations** to access these settings. If you do not see the tab, BuddyPress (with Groups) is not active - the tab is hidden until then.

## Broadcast topics to group activity

**Setting:** `jetonomy_bp_broadcast`
**Default:** On
**Location:** Integrations tab → BuddyPress card

When on, every new Jetonomy topic created in a space that is paired with a BuddyPress group is posted into that group's activity stream. Members browsing the group see the new discussion in their activity feed without leaving BuddyPress.

Turn it off if you want Jetonomy discussions to stay inside the community and not appear in group activity.

## Round-trip activity comments

**Setting:** `jetonomy_bp_comment_bridge`
**Default:** On
**Location:** Integrations tab → BuddyPress card

When on, comments members add to a broadcast activity item in BuddyPress are mirrored back into Jetonomy as replies on the original topic - so a conversation that starts in either place stays in sync in both.

> **Note:** This toggle depends on **Broadcast topics to group activity** being enabled. If broadcast is off, there are no broadcast items for comments to round-trip, and this setting has no effect.

## What's Next?

For step-by-step setup of the BuddyPress integration (pairing spaces with groups, member sync), see the integration guide.

[BuddyPress Integration →](../integrations/13-buddypress.md)
