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
- Migrations, the DB schema and CLI are the documented exceptions.

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
- Background jobs: `Queue` (`recurring`, `next`, `cancel`) in Pro, never
  `wp_*_scheduled_*` directly.
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
- Admin colours come from `var(--jt-admin-*)` tokens; no raw hex in `style=""`
  (email HTML and oEmbed markup are the exceptions).
- Every public hook (`jetonomy_*`) has a docblock with `@since` and `@param`.

## 6. Stability guarantees

- Anything a feature schedules is cancelled when the feature is switched off
  or stops booting (including a lapsed Pro license).
- Revocation paths (membership expiry, group leave, automations) always
  revoke; guards that protect data integrity are opt-in for manual actions.
- Tests and fixtures never leave data behind: delete through the real purge
  path in reverse order and assert counters are unchanged after a run.
- Dev-only code (the `includes/qa` harness) is excluded from the release zip.

## 7. Suppressions are a budget

`phpcs:ignore` / `@phpstan-ignore` lines are counted; most exist because of
rule 2 and disappear with it. Baseline: free 635, Pro 437.
