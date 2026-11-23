---
title: "What's New in 2.1.0"
category: "whats-new"
order: 6
---

# What's New in 2.1.0

Space cards with a Follow button, a sidebar you arrange yourself, a bottom bar on phones, moderators who can ban in their own space, and a contrast and accessibility pass over every control.

> **Note:** Jetonomy and Jetonomy Pro are released together. Jetonomy Pro 2.1.0 requires Jetonomy 2.1.0, so update both at the same time. Going back to 2.0.7 after upgrading is not supported.

## For your members

- **Space cards with Follow.** Space cards on the home, category and search pages show a cover strip and a Follow button beside the space title.
- **A bottom bar on phones.** Members get Home, Spaces, New, Alerts and Me; guests get Home, Search and Sign in. New outside a space opens "Start a topic in...".
- **Hide my online status.** A member can switch off their online dot and last-seen time. Other members then see neither, and the member does not see theirs either. Moderators and admins can still see when members were last active.
- **Easier to tap.** Tag chips, Follow buttons, sidebar links and menu rows are at least 44px on phones, and More menus stay attached to their button.
- **Their own language.** Emails go out in the recipient's language, and times such as "5 minutes ago" are translated as one phrase.

## For site owners

- **Sidebar widgets.** Under **Settings > Appearance**, choose which community sidebar widgets show and drag them into the order you want.
- **Select all in the role matrix.** Each role on the **Permissions** tab has Select all and Clear all.
- **Space moderators can ban.** A new setting, off by default, lets a space moderator ban a member of their own space and lift those bans. Moderators can never ban a space admin, the space owner, another moderator or a site administrator. Only administrators can change the setting.
- **Why an item is held.** The moderation queue shows the reason each item is waiting.
- **Leaderboard ties.** Members with the same reputation are listed newest first.
- **Trust levels.** Every threshold on the settings screen is checked, replies count, and levels never go down on their own. `wp jetonomy trust-evaluate --dry-run` lists who would be promoted and changes nothing.
- **Sample data.** Load Sample Data asks first when the community already has content of its own.
- **Private communities.** A private community is left out of the sitemap and robots.txt.

## Settings that save what you meant

- **A rate limit of 0 means off.** Sites that stored 0 were blocking every new member on that action. They now have no cap on it.
- **Empty and out-of-range numbers.** An empty number field saves the default instead of 0. A number outside the allowed range saves the nearest allowed value, and a notice says so. Reputation points work the same way.
- **Verification reminder.** The reminder defaults to 24 hours on a site that never saved it, and the "translation loaded too early" notice after an upgrade is gone.
- **Honest saves.** A refused value says so instead of "Saved", and two saves made close together keep each other's changes.

## Contrast and accessibility

- Every control edge, focus ring and label meets WCAG AA contrast in light and dark mode, with any accent colour and on any host theme.
- Text in a light accent colour is darker so it stays readable, and buttons on the BuddyX coral accent get a black label.
- Controls and labels no longer fade out with transparency.
- The sidebar search field and the block theme Search button have a visible outline.
- Every confirm dialog is named for screen readers, and each Members page action names the member it acts on.
- A very large page number in an address no longer breaks community pages, admin lists or the media library.

## Background work and uninstall

- **Nothing promised is lost.** Scheduled work, such as a scheduled post, is kept when Jetonomy is deactivated and runs after you activate it again.
- **Lighter page views.** Jetonomy bundles Action Scheduler 4.0.0, does no scheduler work on ordinary page views, and trims its own finished job history.
- **Clean uninstall.** Deleting Jetonomy always clears its scheduled work, transients, cache and rewrite rules. With data removal on, it also deletes the tables, options, capabilities, member meta and the licence update cache.
- **Older PHP.** On PHP older than 8.1, Jetonomy pauses with an admin notice instead of breaking the site.

## For developers

- **One error code for a missing space.** Every REST response for a space that does not exist now uses `jetonomy_space_not_found`. It used to be `jetonomy_not_found` or `space_not_found`, so update any client that matched the old codes.
- **`jetonomy_loaded`.** Fires once when Jetonomy has finished booting, with the main plugin object. Pro adds `jetonomy_pro_loaded`.
- **New hooks.** `jetonomy_held_item_notes` (filter) and `jetonomy_space_members_purged` (action).
- **One scheduler.** `\Jetonomy\Queue` schedules for Jetonomy and Jetonomy Pro. Recurring jobs register through the `jetonomy_recurring_jobs` filter.
- **One settings writer.** The settings form, the REST settings route and `wp jetonomy config set` share one sanitizer.
- **WP-CLI.** Bad numbers and flags are refused, and every whole-number argument states its minimum.

## What's new in Pro

- **Licence controls updates only.** Every Pro feature keeps working without a valid licence. Licence notices name the reason, a valid licence shows no renewal reminder, and Pro's notices show only on Jetonomy screens.
- **Attachments admin.** A list of uploaded files with filters and delete. It never deletes a file Jetonomy did not upload. `wp jetonomy-pro attachments lock-private` locks every existing private-space file at once.
- **Poll results timing.** Choose when members see results: always, after they vote, or after the poll closes.
- **Private profile fields.** Custom profile fields that only the member and moderators see.
- **Badges.** Delete a badge permanently, with a confirm that says how many members hold it, and restore deleted default badges.
- **Webhooks.** Deliveries from private and hidden spaces are opt-in per webhook. Deliveries wait 15 seconds again, filterable with `jetonomy_pro_webhook_timeout`.
- **Site announcements.** Pinning an announcement notifies members once.
- **Email digest.** Written in each recipient's language, test digests included.
- **Accessibility.** PDF attachment cards have a visible outline, and the image lightbox is named and has a Close button.
- **Developers.** `GET /badges` pages by page number, and its `cursor_next` is the next page number.
