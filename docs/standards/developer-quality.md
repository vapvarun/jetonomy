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

Check: count `$wpdb` outside the allowed folders; shrink-only baseline.
Baseline: free 616 in 34 files (5 templates), Pro about 840 across the
extension classes.

## 3. One place per concept

- Author display: `Author::for_display()` (anonymity, deleted accounts).
- Permission callbacks on REST routes: `REST_Auth` helpers only.
- Capability groups: constants on `Permissions\Capabilities` (e.g. `UPLOAD_CAPS`).
- Background jobs: `Queue` (`recurring`, `next`, `cancel`) in Pro, never
  `wp_*_scheduled_*` directly.
- Pro settings: one save path in the `Extension` base class (save on
  `admin_init` or via REST, then redirect with `?jetonomy_notice=`), never a
  save inside a render method.

A second implementation of any of these is a defect, even if it works.

## 4. Size and layout

- New PHP/JS files stay under about 800 lines; a function under about 80.
- Files over 1,500 lines are split by responsibility when next touched.
- Every Pro extension uses one layout: `class-extension.php` (wiring only),
  `class-rest.php`, model class(es), `class-admin.php`, `views/`.

Baseline: free has 5 files over 2,000 lines (largest `assets/js/view.js` 4,719);
Pro has 16 files over 1,000 lines (largest `private-messaging/class-extension.php`).

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
