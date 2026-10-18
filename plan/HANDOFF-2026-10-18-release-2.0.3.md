# Handoff: Jetonomy 2.0.3 release (2026-10-18)

Resume from here, on any machine. Basecamp board: project 46596502. QA handoff card for the round: 10415337901 (release note posted on it).

## State: tagged, waiting for the team to verify the zips

| | Free (`vapvarun/jetonomy`, public) | Pro (`vapvarun/jetonomy-pro`, private) |
|---|---|---|
| Release PR | #70 merged into `main` | #25 merged into `master` |
| Tag v2.0.3 | 912d15d4 | 7b4d3f2 |
| GitHub release + zip | `jetonomy-2.0.3.zip` | `jetonomy-pro-2.0.3.zip` |
| After the tag | #74 docs coverage (9758a549) | #26 `audit/security/2.0.3.md` |

- Slack `#ready-for-release` (C01MN2298JW): one post per product, asking the team to verify the zips.
- The `2.0.2` branch in both repos is the released code; Free `2.0.2` also has one test-only commit (ac47873e) that was merged with #70.

**Owner rule (2026-10-18): the team verifies the zips first; public release waits for their approval.** Until then do NOT:
- push `~/code/jetonomy-org` (commit 66f10d9 "docs(api): refresh REST catalogue for v2.0.3" is local only; the site still serves the 2.0.1 spec, 141 routes vs 142),
- press Update on Packagist for `wbcomdesigns/jetonomy` (still lists v2.0.2; the repo has no Packagist webhook, so a periodic crawl may pick the tag up on its own),
- rebuild docs.wbcomdesigns.com (on request only),
- announce to customers.

If the team finds bugs: fix on a branch, rebuild 2.0.3 in place (replace the zips on the same tag, move the tag only with the owner's OK). No 2.0.4.

## How it was verified

- PHPUnit full suite with and without Pro (1724 tests), PHPStan clean on both, GitHub CI 44 checks green.
- Plugin Check on both zips: identical to the shipped 2.0.2 zips except `test-results/.last-run.json`, now excluded by `bin/build-release.sh` and `.distignore`. The remaining errors are pre-existing scanner patterns (prepared SQL with table names, bundled Action Scheduler, `plugin_updater_detected`).
- Docker (WordPress 7.1.1, PHP 8.2, Twenty Twenty-Five, no other plugins, `JETONOMY_PRO_LICENSE_BYPASS`): clean install, upgrade from the 2.0.2 pair with content intact, member topic/reply/vote, anonymous REST carries no email-style login, Pro Extensions page, Polls on and voting, Pro without Free shows its notice and the site stays up. Stack `jt203rel` on port 8898 may still be running: `cd ~/Documents/work-artifacts/docker-smoke && docker compose -p jt203rel -f dc-rel.yml down -v`.
- Security review (Gate G3): 0 critical, 0 high, 1 medium, 4 low; all fixed or accepted before the tag. See Pro `audit/security/2.0.3.md`.
- Release walk: **short walk under the owner's exception** (`docs/qa/release-exception.json`), 16 journeys, not the full runbook. All 12 product defects it found were fixed (3438b362, f7208db7) and re-checked.

## Next cycle: start here

1. **Full journey walk** of `docs/qa/AGENT_SMOKE_RUNBOOK.md`. Walk once, after all fixes are in; never re-walk after each fix batch.
2. **Reproduce first, maybe a defect:** `c-notifications.19` / `.27`. After the bell refreshed on tab focus, the Unread tab count on the Notifications page stayed stale (8). `MemberUiAssetsTest` says the focus refresh reaches the Unread tab, so either the walk was wrong or the test misses a path.
3. **Owner decision:** `c-member-post-edit.17`. There is no `/edit` URL (editing is inline from the topic menu), so a typed `/edit` link gives a plain 404. Decide: add a route, or reword the check to expect 404.
4. **Reword the runbook checks below** (the product was right, the check text was wrong). Same pass: add the proposed checks from the walk records (`proposed_checks` in `docs/qa/walks/*.json`, 147 entries; most are duplicates across lanes, dedupe first).
5. Not run in 2.0.3 QA: Firefox, Safari iOS, RTL, a11y audit; Pro extensions other than Polls and the Extensions page; licence and EDD updater (need a real key).

### Runbook checks to reword (25)

- `c-admin-user-management.4`: the lift confirm reads "Lift this restriction? The member can post again right away." and the toast "Ban lifted."
- `c-admin-user-management.13`: silenced member: create, reply and vote REST answer 403 `jetonomy_user_silenced`; the vote button is disabled with its reason.
- `c-admin-user-management.20`: `--ip` is a real CLI flag now; with `--reason` only the command answers "--type=ip_ban needs the address to block".
- `c-anon-single-post.18`: a guest gets 404 `jetonomy_not_found`, same as a missing id (stricter than the check).
- `c-gating-read-on-private.5`: write refusals use 403 `jetonomy_forbidden` with "You cannot post in this space." (vote has the generic permission message).
- `c-gating-surfaces-agree.1`: an invite-only private space answers 200 with a named locked card ("<Space> is invite-only...") plus the description.
- `c-gating-surfaces-agree.5`: listed private spaces appear on the home grid as cards (name and link) for non-members by design; their topics never do.
- `c-member-accept-answer.1` and `.16`: the accepted reply is highlighted in place; the callout above the replies shows only when the accepted reply is on another page.
- `c-member-accept-answer.11`: merge keeps one accepted reply on the target; there is no split route or UI, so drop the split half.
- `c-member-flag.6`: the route is `POST /flags {object_type, object_id, reason}` (not `/moderation/flags`).
- `c-member-flag.9` / `.10`: a missing target answers 404 `jetonomy_flag_target_missing`.
- `c-member-flag.18`: the report dialog follows the site direction; member-written text follows its own.
- `c-member-reply-create.11`: replying to an unreadable topic answers 404 `jetonomy_not_found` (and the topic page is now the missing-topic page, f7208db7).
- `c-mod-queue-and-actions.1`: the h1 reads "Moderation Overview"; only Flags and Awaiting approval carry count badges.
- `c-mod-queue-and-actions.2`: the filter is content type (Topics/Replies); status is the separate Awaiting approval / Trash views.
- `c-mod-queue-and-actions.8`: bulk approve honours `per_page` now (3438b362); empty `items` answers 400 `jetonomy_validation`.
- `c-mod-queue-and-actions.11`: test_moderator is a site moderator (`jetonomy_moderate`), so the "moderator of two spaces only" premise is stale; fix the fixture or the persona.
- `c-mod-queue-and-actions.26`: a guest gets 401 `jetonomy_unauthorized`.
- `c-notifications.9`: the join-request notice reads "<Name> requested to join <Space>".
- `c-notifications.19` / `.27`: there is no timer by design (header.js); counts refresh on focus/visibility. See item 2 above for the open part.
- `e-polls.4`: a second poll answers 409 "This post already has a poll." (not 400).

## Open questions for the owner (from this round)

- During the walk a bulk approve published 20 held topics in Insiders/Beta and Help Desk on forums.local. Keep them or put them back in the queue?
- A banned member still gets the named ban message on a space; every other hidden thing answers like missing. Keep it?
- Importer category slug stays `imported-<source>` (a re-run must find the same category).

## Things worth knowing next time

- forums.local runs the plugin WORKING TREES at `~/Local Sites/buddynext/app/public/wp-content/plugins/{jetonomy,jetonomy-pro}`: switching branches changes the live site.
- `docs/qa/.last-smoke-pass.json` is gitignored; `bin/build-release.sh` needs it at the release version. `--skip-browser-smoke` builds a test zip without it.
- The build fails on a stale `.pot`, hooks index or runtime coverage baseline; regenerate and commit, never bypass.
- CI `coverage-gates` fails a new test that also passes on the old code. A guard test that pins existing behaviour gets `@covers-existing <reason>`.
- A test MySQL connection (76826) was left holding a lock in `wp_tests_jetonomy_c`; kill it before the next sandbox run there.
- Playwright MCP may only write inside the site root: save screenshots under `.playwright-mcp/` and move them to `~/Local Sites/forums/app/qa-artifacts/`.
