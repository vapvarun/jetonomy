# Developer quality standard (Jetonomy + Jetonomy Pro)

What a developer opening either repo should find: code that reads as one
deliberate design, where every rule is enforced by a check rather than by
memory. Each rule below has a measurable check and the baseline measured on
the 2.0.2 branch (2026-10-11). Baselines may only go down; CI fails if a count
rises.

## 1. Comments explain why, in the present tense

- A comment states the rule the code follows and the reason for it, in at most
  three lines. Long design notes belong in `docs/`.
- No tracker or plan references in code (`Basecamp 1234`, `WP2.6`, `Task 12`,
  `1.9.4 fix`) and no history (`used to`, `previously`, `now does`). Those go
  in the commit message and PR, where `git blame` finds them.
- No commented-out code. Delete it; git keeps it.

Check: `grep -rnE 'Basecamp [0-9]|WP[0-9]\.[0-9]|Task [0-9]+' includes templates assets/js`
(excluding `*.min.js`). Baseline: free 316, Pro 66 (plus history phrases).

## 2. Data access goes through models

- SQL lives in `includes/models/` (free) or a per-table model class (Pro).
  Controllers, admin screens, importers, extensions and templates call model
  methods; they never touch `$wpdb`.
- Templates receive data. A template that runs a query is a defect.
- Migrations, the DB schema and CLI are the documented exceptions, and so is
  one-time or repair code: importers, the demo seeder, GDPR export/erase,
  backfills, recount and the purge service. They run once per site, so
  moving their queries buys nothing and risks the riskiest code.
- The full-text search backend (`includes/search/`) is part of the data
  layer: it builds the search queries for posts, replies, spaces and tags in
  one place, behind a backend that can be swapped.
- An integration reading another plugin's own tables (BuddyPress group meta,
  FluentCommunity tables) keeps that read in its adapter; anything it reads
  from Jetonomy's tables goes through our models.

Check: `bin/check-data-layer.sh` (pre-commit and local CI) fails when any
file's `$wpdb` use outside the data layer grows past `audit/wpdb-baseline.txt`;
run it with `--update` after moving queries out. Baseline: free 448 uses in 33
files and none in templates; Pro 629 in 29 files, the largest the extension classes.

## 3. One place per concept

- Member-facing names: `Author::for_display()` for content authors (anonymity,
  deleted accounts) and `user_display_name()` for any other member name, so
  the owner's name-display setting applies everywhere. Admin screens that
  identify an account (name plus login) may read the account fields directly.
- Permission callbacks on mutation routes: `REST_Auth` helpers only (nonce,
  ban and verification checks). Read routes may use a one-line closure on
  `is_user_logged_in()` / `current_user_can()`.
- Capability groups: constants on `Permissions\Capabilities` (e.g. `UPLOAD_CAPS`).
- Background jobs: one queue class per plugin, never Action Scheduler or
  `wp_*_scheduled_*` called directly. Free: `\Jetonomy\Queue` (`async`,
  `async_once`, `at`, `is_queued`, `next`, `cancel`); recurring jobs are armed
  only in `Cron::ensure_scheduled()`. Pro: `\Jetonomy_Pro\Queue`, and each
  extension lists its job hooks in `jobs()` so switching it off cancels them
  all. Pro keeps its own class because it must run on older free versions.
- Pro settings: one save path in the `Extension` base class (save on
  `admin_init` or via REST, then redirect with `?jetonomy_notice=`), never a
  save inside a render method.

A second implementation of any of these is a defect, even if it works.

## 4. Layout

- Large files are normal for a plugin this size; split a file only when a
  change needs it, never as a standalone refactor before a release.
- A new Pro extension uses one layout: `class-extension.php` (wiring),
  `class-rest.php`, model class(es), `class-admin.php`, `views/`.

## 5. Consistency

- Array syntax: `array()` (WPCS default), enforced by PHPCBF.
- `WP_Error` codes: always `jetonomy_<noun>_<reason>`; the same condition
  uses the same code everywhere.
  One documented exception: `community_private` (401 when the whole community
  is members-only) predates the rule and is a public REST contract that
  headless clients match on, so it keeps its name.
- Admin colours come from `var(--jt-admin-*)` tokens; no raw hex in `style=""`
  (email HTML and oEmbed markup are the exceptions).
- Every public hook (`jetonomy_*`) has a docblock with `@since` and `@param`.

## 6. Stability guarantees

- Anything a feature schedules is cancelled when the feature is switched off
  or stops booting (including a lapsed Pro license).
- Revocation paths (membership expiry, group leave, automations) always
  revoke; guards that protect data integrity are opt-in for manual actions.
- Tests and fixtures never leave data behind and never act as a real user:
  they create throwaway users, delete through the real purge path, and the
  run fails if the row count of any jt_ table, or the admin's rows in plugins
  that listen to our hooks, differs afterwards.
- Dev-only code (the `includes/qa` harness) is excluded from the release zip.

## 7. Suppressions are a budget

`phpcs:ignore` / `@phpstan-ignore` lines are counted; most exist because of
rule 2 and disappear with it. Baseline: free 635, Pro 437.

## 8. Closing a bug fix

A fix is done when the card carries this evidence, not when the code is
written. Each rule below comes from a fix that came back from QA (2.0.2: 19
cards in one pass, most fixed on the one path the card named).

1. **Reach list first.** Before coding, list every reader and writer of the
   data or flag (grep the column, option or meta key and the function) across
   free and Pro and every entry point: front end, wp-admin, REST, Abilities,
   WP-CLI, jobs, emails and notifications, feeds, oEmbed, JSON-LD, sitemaps,
   blocks and shortcodes, uninstall. Post it on the card; tick each one as
   fixed, checked-unaffected, or not covered with the reason.
2. **Fix the class, not the instance.** Name the rule the bug broke ("private
   means author, topic author and staff only"; "a DB error is not 'nothing
   left'") and apply it wherever it holds: reads and writes, lists and single
   items, inline and queued paths. When several symptoms share a helper, fix
   the helper.
3. **Every check has a negative case and fails on the old code.** Test what
   must not happen (an unedited post shows no "edited" marker; a guest gets no
   private text; an unticked role is refused). Fixtures reach depth 3, a second
   page, blank and spaces-only input, an existing row from before the fix.
   Stash the fix once and confirm the new check fails.
4. **Run the card's acceptance bar exactly as written** and paste the output
   into the fix comment.
5. **A gate is evidence only if it can fail.** Plant one bad sample before
   relying on a script, sniff or leak check.
6. **Policy is the owner's call.** Who gets access, what money or data does,
   what is shown to whom: ask with options and a recommendation. Never narrow
   a card's scope quietly.
7. **Review your own new path.** A fix that moves data, changes an audience or
   adds an output gets the same privacy and permission check as the bug.
   Private and anonymous flags survive every copy and move.
8. **Docs follow the change.** Grep docs, help text and the readme for the
   old label, command or route, and check documented commands against
   `wp help` and the registered routes.

The fix comment states: commits, root cause (`file:line`, how established),
the reach list, what changed, exact retest steps, the acceptance output,
regression check ids, and what was not covered.
