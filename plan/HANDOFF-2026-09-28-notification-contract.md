# Handoff: notification contract QA + fixes (2026-09-28)

Resume from here, on any machine. Everything below is on the Jetonomy Basecamp board (project 46596502) unless noted. Branch: 2.0.1 (free and Pro); BuddyNext 1.2.2.

## State: nothing open

| Card | What | Result | Where |
|---|---|---|---|
| 10345367462 | [Pro] custom badge notifications bypass Notifier | PASS | Done |
| 10344484657 | Bell follows ban/trash rules (one shared visibility rule) | PASS | Done |
| 10345448118 | Group several replies to one topic into one bell row | bounced twice, then PASS | Done |
| 10345514295 | Anonymous replier named in the BuddyNext bell (+ Pro email digest) | PASS (independent verifier) | Done |
| 10345514705 | (BuddyNext board 47683682) delete dead JetonomyBridgeListener::filter_visible_rows | PASS in code | Done |

## Commits (all pushed to 2.0.1 / 1.2.2)

- jetonomy: e299aacf (visibility parity + Pro badge door), d6efdfcd (topic-anchored grouped row), 776d623c (anonymous actor never named, contract payload actor_id 0, not grouped), da2dfad4 (item_type/item_id/message_single + item visibility false for a purged reply), 74d96bff (Pro digest test, lives in free repo tests/pro).
- jetonomy-pro: 89a76b2 (badge through Notifier), d3cf3da (digest masks anonymous replier).
- buddynext (their team): 660135a5 (distinct people + per-item rows), 9d38398c (old Jetonomy/MediaVerse routes deleted), 3e4f9179 (banned/blocked newest replier shrinks the row), e69347bb (read notification is history, never folds with a new one).
- Both Jetonomy repos are clean; nothing uncommitted.

## Contract as it stands (for any future work)

- `reply_to_post` named: object = topic, group_key `reply_to_post_{topic}`, plus `message_grouped`, `message_single` ({actor} token), `item_type` reply, `item_id` reply id.
- Anonymous author: `actor_id` 0, own group key per reply, none of the above (never joins a tally). Emit door: `Notifier::emit_notification_created(..., $actor_anonymous)`.
- Item visibility: `Notification::targets_visible()` with `item => true` answers false when the reply is gone; whole notifications about a missing target stay visible (removal signal deletes the row).
- Digest: Pro email-digest "Replies to Your Posts" prints "Anonymous" for anonymous replies, for every viewer (not Author::for_display, which lets admins reveal).

## Known ceilings (accepted, written on the cards)

- A globally banned ANONYMOUS member's older rows stay in the bell (no actor to ban-check). No identity exposed.
- Bell merged row shows both "and 1 other" and a "x2" chip (BuddyNext polish, not filed as a defect).
- Not covered by anyone yet: email for grouped rows; other grouped types (join requests, reactions, mentions) after BuddyNext's read-fold change; anonymous TOPIC path by email; 390px dark of the anonymous row.

## Things worth knowing next time

- The site runs the WORKING TREE; a BuddyNext dev session (public-41) edits it live. Check `git status`/`git log` of buddynext before believing a bell result.
- `/me/notifications/unread-count` is the UNSEEN count by design (mark-read does not change it).
- Space::delete() is a bare row delete by design; the real flow is Space_Purge (not wired yet). Do not file orphan space_members rows.
- Playwright MCP screenshots must use a `.playwright-mcp/` filename or they land in the WP root; move them to the site's `qa-artifacts/` (sibling of `public/`) and delete the yml snapshots.
- Verify with rows the run creates (fresh space/topic), never on demo data; the probe scripts were disposable (drive the real REST routes with rest_do_request under wp_set_current_user, read the bell through GET /buddynext/v1/me/notifications, delete what you created).
- Owner rules applied this session: think as plugin, not site data; QA verifies but Jetonomy/Pro fixes were explicitly authorised by the owner, BuddyNext fixes stay with the BN team; commit/push only when asked, no attribution trailers.

## If anything comes back

- Anonymous/bell regressions: start from the payload tests in `tests/unit/notifications/CommunityNotificationContractTest.php` and `tests/pro/extensions/EmailDigestAnonymousReplierTest.php`.
- Grouping wording/count regressions: BuddyNext GroupedItems::people() and IntegrationNotificationListener::filter_visible_rows (their card thread on 10345448118 has the design).
