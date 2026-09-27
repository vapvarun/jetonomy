---
title: Troubleshooting
description: Start here when something is not working - a member cannot post, a page 404s, email is not arriving, or an integration stopped granting access.
order: 0
---

# Troubleshooting

Start here when something is not working. Most problems fall into one of the areas below.

If you have an exact error message, go straight to the [Error Messages reference](01-error-messages.md) and search for the wording the member saw. That page lists every error Jetonomy can produce, with the cause and the fix.

## Before anything else

Two checks resolve a surprising share of reports:

**Flush permalinks.** Go to **Settings -> Permalinks** in WordPress and click Save without changing anything. Jetonomy's community URLs are virtual routes, and they stop resolving if the rewrite table is rebuilt without them. This is the fix for community pages that 404 after you deactivate and reactivate plugins, change the community base slug, or migrate a site.

**Turn on the debug log.** Add `define( 'WP_DEBUG', true );` and `define( 'WP_DEBUG_LOG', true );` to `wp-config.php`, reproduce the problem, then read `wp-content/debug.log`. Jetonomy writes an error code alongside the failing request, which usually identifies the surface even when the on-screen message is generic.

## A member cannot post

Work down this list in order:

1. **Are they banned or silenced?** Check **Jetonomy -> Users**. A banned member cannot read or post; a silenced one can read but not post.
2. **Is the topic closed, or the space Archived or Locked?** Closed topics accept no member replies, though moderators can still reply. Archived and Locked spaces accept no new posts at all.
3. **Have they hit a rate limit?** Limits apply only to **Trust Level 0** members. Levels 1 and up have none. The window runs 24 hours from their *last* attempt, so a member who keeps retrying keeps resetting it. Caps are at **Settings -> Permissions**.
4. **Do they have permission in that space?** Private spaces require membership. Check their role on the space's Members tab.

See the [Error Messages reference](01-error-messages.md#blocked-from-posting) for the exact wording of each case.

## A member cannot see a space

This is a different report from "cannot post" - the space itself is missing from listings, search, or navigation. Work down this list:

1. **Is the space Private or Hidden?** A Private space still shows in listings and search with a lock icon; only its content is gated. A Hidden space is withheld everywhere - no listing, no search, no navigation - and its direct URL returns a 404 to anyone who is not a member or a site admin. If a member says a space "does not exist" but you know it does, it is almost always Hidden and they are not on it. See [Membership & Join Policies](../spaces-and-categories/03-membership-policies.md#hidden).
2. **Is the space's category Private or Hidden?** A space is never more visible than its own category. A Public space filed under a Hidden (or Private) category is withheld from listings, search, tag pages and feeds the same way a Hidden space would be, even though the space's own visibility says Public. Check the category at **Jetonomy -> Categories**. See [Categories](../spaces-and-categories/00-categories.md#visibility-options).
3. **Is the member actually a member?** For a Private or Hidden space, only members (and site admins) can read its content, regardless of what the category allows. Add them on the space's **Members** tab, or through an [Access Rule](../spaces-and-categories/04-space-settings.md#access-rules-for-membership-gated-spaces) if access should follow a membership or role instead.

A member who is already a member of a space always sees it in their own listings, even if you later hide its category - membership overrides both checks above.

## Community pages 404 or redirect somewhere wrong

Flush permalinks first, as above. If that does not fix it:

- Check the community base slug at **Settings -> General** does not collide with an existing WordPress page or another plugin's route.
- If only *some* routes fail - the direct messages inbox, for example - the rewrite table was likely rebuilt while a Pro extension had not yet registered its own rules. Flushing permalinks re-registers everything.
- If a topic 404s after you renamed its space, the old URL no longer exists. Space slugs are part of the topic URL.
- If you changed the community base slug, old links redirect to the new base automatically. An old link that opens a different page instead means a real page or another plugin already uses the old slug, and Jetonomy leaves that page alone.

## Theme layout looks broken, or the page shows double headers

See [Theme Compatibility - Troubleshooting](../integrations/07-theme-compatibility.md#troubleshooting).

## Search shows no results

1. **Mixing short and long words?** MySQL/MariaDB FULLTEXT indexes do not store words shorter than 4 characters. When a search contains at least one word of 4+ characters, the short words are dropped and only the longer words are matched - `QA workflow` searches for `workflow`. A search made only of short words (`QA`, `v2`, `cri`) still works: it falls back to a plain substring match, just without relevance ranking. See [Search & Filters](../search-and-discovery/01-search-filters.md).
2. **Content in Private or Hidden spaces never appears in search for non-members.** It is excluded from the query itself, not filtered out afterward - a member sees those results once they actually join the space.
3. **There is no search index to rebuild.** Search runs live against your database on every request. If the words are long enough and the content is visible to the searcher, it is found - there is nothing to reindex or fall out of sync.

## Mobile app sign-in fails

Work down this list in order:

1. **Are Application Passwords available on the site?** They require HTTPS. On an HTTP site (including most local development), WordPress disables them entirely and sign-in cannot work. See [Connect Members](../mobile-app/02-connect-members.md).
2. **Has a security plugin disabled them?** Some hardening plugins turn off Application Passwords site-wide. Check that plugin's own settings.
3. **Check the exact error text.** `jetonomy_app_passwords_unavailable`, `jetonomy_app_bad_scheme`, and `jetonomy_app_bridge_expired` each point at a different cause - Application Passwords disabled, a mismatched custom URL scheme on a white-labelled build, or an expired connect link that needs restarting from the app. See [Error Messages](01-error-messages.md#signing-in-and-registering).

## Page caching

Community pages work with page caching plugins and host caches (LiteSpeed Cache, WP Rocket, WP Super Cache and similar). Visitors who are not logged in get cacheable pages, so a busy public forum is served from the cache. Pages for logged-in members are still sent as uncached, so each member sees their own notifications, drafts and private spaces.

If new topics or replies take a while to appear for logged-out visitors, that is your page cache's lifetime at work. Shorten the cache lifetime for the community path, or purge the cache, in your caching plugin's settings.

## Email is not arriving

Jetonomy sends through WordPress's own `wp_mail()`, so it inherits whatever your site already uses for mail. It does not send directly.

1. **Test WordPress mail first**, not Jetonomy. If WordPress cannot send, nothing Jetonomy does will help. An SMTP plugin is the usual fix on shared hosting.
2. **Check the From address** at **Settings -> Email**. A From address on a domain that does not match your site fails SPF and DKIM checks and lands in spam.
3. **Check the notification is enabled** for that type - the toggles are on the same screen.
4. **Check the member has not disabled it** in their own notification preferences.

If Jetonomy's From address is being replaced by a different one, another plugin is filtering the sender site-wide. Jetonomy asserts its own sender only for its own mail and leaves other plugins' mail alone, so the reverse - your Jetonomy mail arriving as someone else's address - means the other plugin is not being as careful.

## Members are not getting notifications

1. **Check the type-specific toggle, not one on/off switch.** Every notification type has its own in-app and email toggle - there is no single "notifications on/off". Admins set the site-wide default per type at **Settings -> Email**; each member can then override any type for themselves on their own profile. See [Notifications](../notifications/01-notifications.md#per-user-notification-preferences).
2. **Did they turn on "Pause all email notifications"?** This snoozes every email type at once while in-app notifications keep working. It is a pause, not an unsubscribe - the member's per-type choices underneath it are preserved and come back when they turn it off again.
3. **Did they click Unfollow, not "mute"?** Jetonomy has no mute action. Following a space subscribes a member to its new-topic notifications; clicking **Following** to toggle it back to **Follow** (or Unfollow from **My Subscriptions**) is what stops them - and a member is never notified about a space or topic they never followed in the first place. See [Bookmarks & Following](../discussions/04-bookmarks-following.md#following-spaces).
4. **Expecting browser push?** Web Push is a Jetonomy Pro extension and requires HTTPS - it does nothing on an HTTP site even with the extension enabled, and it does not exist on the free plugin at all.
5. **Missing @mention notifications?** Mentions are a normal notification type. Check the **Mention** row in the site-wide defaults at **Settings -> Email**, and in the member's own notification preferences on their profile - either one can turn it off.

## An integration stopped granting access

Each integration has its own troubleshooting section, because the failure modes differ by plugin:

| Integration | |
|---|---|
| MemberPress | [Troubleshooting](../integrations/01-memberpress.md) |
| Paid Memberships Pro | [Troubleshooting](../integrations/02-pmpro.md) |
| WooCommerce | [Troubleshooting](../integrations/03-woocommerce.md) |
| LearnDash | [Troubleshooting](../integrations/04-learndash.md) |
| Restrict Content Pro | [Troubleshooting](../integrations/05-rcp.md) |
| BuddyNext | [Troubleshooting](../integrations/06-buddynext.md) |
| Tutor LMS | [Troubleshooting](../integrations/08-tutor-lms.md) |
| LifterLMS | [Troubleshooting](../integrations/09-lifterlms.md) |
| Sensei LMS | [Troubleshooting](../integrations/10-sensei-lms.md) |
| MasterStudy LMS | [Troubleshooting](../integrations/11-masterstudy-lms.md) |
| Learnomy | [Troubleshooting](../integrations/14-learnomy.md) |
| WP Fusion | [Troubleshooting](../integrations/15-wp-fusion.md) |
| SureMembers | [Troubleshooting](../integrations/16-suremembers.md) |

Common to all of them: access rules are evaluated when the member loads the space, not cached indefinitely, so a membership change should take effect on their next page load. If it does not, confirm the rule points at the level or tag the member actually holds - a renamed membership level leaves the rule pointing at an id that no longer matches.

## Admin menu items are missing, or show "Not allowed"

1. **Does the account's WordPress role hold the right capability?** The **Moderation** screen needs `jetonomy_moderate`. Every other Jetonomy admin screen - Dashboard, Spaces, Categories, Users, Settings, Import, Community Media - needs `jetonomy_manage_settings`, which only Administrators have by default. A moderator who can see Moderation but nothing else is working as designed. Check or grant either capability on the [Role Capability Mapping](../admin-settings/18-role-capabilities.md) grid.
2. **Looking for a Pro-only screen (Extensions, License, a Pro settings tab)?** Those menu items are added by Jetonomy Pro itself and only appear when Pro is installed **and** active - they are never present on a free-only install.
3. **Pro menu item is there but the feature underneath says it is unavailable?** Check **Settings -> License** - an expired or missing license closes every Pro extension even though the Extensions grid still shows each toggle. See [License](../admin-settings/14-license.md).
4. **License is valid but one specific feature is still missing?** Check it is switched on at **Jetonomy -> Extensions** - extensions are enabled individually, separately from licensing. See [Extensions](../admin-settings/13-extensions.md).

## A feature is missing after updating

1. **Check the extension is still switched on** at **Jetonomy -> Extensions**. An update never re-enables an extension you had turned off, and disabling one preserves its data rather than removing it.
2. **Check the license** at **Settings -> License**. An expired license closes every Pro extension even though the Extensions grid still shows each one's toggle - the license, not the toggle, decides whether the code actually runs.
3. **Clear any page cache.** A page cached before the update can keep serving the old markup to logged-out visitors until the cache expires or is purged.

## Pro features are not available

Check **Jetonomy -> Settings -> License** first. The Pro gate is all-or-nothing: a valid licence unlocks every extension, and an expired one closes them all once the grace window ends. There is no per-feature tier.

If the licence is active but a specific feature is missing, check it is switched on at **Jetonomy -> Extensions** - extensions are individually enabled, separately from licensing.

See [License](../admin-settings/14-license.md) for activation problems.

## Import did not finish

Imports run in batches and can be resumed - reopen **Jetonomy -> Import** and it picks up where it stopped rather than starting over or duplicating what it already moved.

If the import finished but content looks wrong, check space visibility before assuming data loss. A members-only board in the source forum should import as a private space; if it came through public, fix the space visibility rather than re-importing.

See [Migration](../migration/00-overview.md) for the per-source guides.

## Reporting a bug

If none of the above fits, include these when you report it - they turn a guess into a diagnosis:

- What you did, what you expected, what happened instead
- The exact error text, or the error code from the debug log
- Jetonomy and Jetonomy Pro version numbers, and whether both are active
- Your active theme, and whether the problem survives switching to a default theme
- Whether the problem survives deactivating other plugins
