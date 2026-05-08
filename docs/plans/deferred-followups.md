# Jetonomy - Deferred follow-ups

Customer-facing UX gaps surfaced during the 1.4.0 / 1.4.1 cycle that did not make either release. Pulled out of the now-shipped `v1.4.0.md` plan so the remaining work has a clean home.

Each item still needs a usability + placement audit before implementation. None of these are bugs from the customer's perspective in the sense of "broken save"; they are gaps in the experience that should be closed when each one can be done with proper UX work, not as a partial fix.

| Tag | Description |
|---|---|
| Y.1 | `/community/` first-run shows "No categories yet" with no admin CTA. Add a "+ Create Space" CTA visible to caps-eligible admins. |
| Y.2 | Setup wizard step 1 only offers Forum + Q&A; should expose all four supported space types (Forum / Q&A / Ideas / Feed). |
| Y.3 | Unread badges on space cards. Absorbed by C.5(d) in the original 1.4.0 plan; ships with that read-status work. |
| Y.4 | Trust level shows number only on the profile. Add the level name plus a "next-level requirements" tooltip. |
| Y.5 | Demo data leakage. The `Demo_Seeder` had QA smoke custom fields and a "View on Jetonomy Demo" cross-link visible on every user's edit-profile / FluentCommunity bridge output. Confirm whether the leak still exists in current code; gate the FC bridge cross-link on a configured site name and drop the smoke fields from the seeder if they have not already been removed. |
| Y.6 | Quote-reply uses `mouseup` only and does not fire on touch. Needs a real touch-UX design pass (button placement, dismiss flow, viewport edge cases at 390px) before adding `touchend` handlers, not a copy-paste of the mouse path. |
| Y.7 | Ideas-type spaces do not expose the roadmap board in the space nav. |
| Y.8 | wp-admin spaces list lacks bulk delete / close / archive. Destructive bulk actions need a confirm flow and an undo path before shipping. |
| Y.9 | Leaderboard period filter. SQL already supports `?period=week|month|all`; the dropdown UI is missing. `?period=month` direct-link bounces admins back to wp-admin. UI needs to match the existing space-page sort-mode pattern, not an ad-hoc `<select>`. |
| Y.10 | Moderation flag card shows `type #id` not the post or reply title. The SQL fetch already JOINs the title-bearing tables for the deep link; one extra column + one trim renders the title preview. Lowest-risk item on this list. |

## Process notes

- These were carried over from `v1.4.0.md` (deleted in the same commit that created this file) so the open boxes did not silently outlive the release that was supposed to ship them.
- Implementation order is not fixed. Each item is independent.
- Memory rule applies: no partial fixes - audit every surface where the same UX shows up before changing one of them.
