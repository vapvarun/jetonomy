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

## Community pages 404 or redirect somewhere wrong

Flush permalinks first, as above. If that does not fix it:

- Check the community base slug at **Settings -> General** does not collide with an existing WordPress page or another plugin's route.
- If only *some* routes fail - the direct messages inbox, for example - the rewrite table was likely rebuilt while a Pro extension had not yet registered its own rules. Flushing permalinks re-registers everything.
- If a topic 404s after you renamed its space, the old URL no longer exists. Space slugs are part of the topic URL.

## Email is not arriving

Jetonomy sends through WordPress's own `wp_mail()`, so it inherits whatever your site already uses for mail. It does not send directly.

1. **Test WordPress mail first**, not Jetonomy. If WordPress cannot send, nothing Jetonomy does will help. An SMTP plugin is the usual fix on shared hosting.
2. **Check the From address** at **Settings -> Email**. A From address on a domain that does not match your site fails SPF and DKIM checks and lands in spam.
3. **Check the notification is enabled** for that type - the toggles are on the same screen.
4. **Check the member has not disabled it** in their own notification preferences.

If Jetonomy's From address is being replaced by a different one, another plugin is filtering the sender site-wide. Jetonomy asserts its own sender only for its own mail and leaves other plugins' mail alone, so the reverse - your Jetonomy mail arriving as someone else's address - means the other plugin is not being as careful.

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
