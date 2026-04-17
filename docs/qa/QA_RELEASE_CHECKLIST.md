# Jetonomy — QA Release Checklist

Gate for every tagged release of `jetonomy` (free). All items must be ✅
before merging the release branch or pushing the zip to store.

---

## 1 — Before the release branch is cut

- [ ] `composer test` green (PHPUnit — unit, integration, security, concurrency, error-paths, pro)
- [ ] `composer phpstan` clean: `[OK] No errors`
- [ ] `wp plugin check jetonomy --severity=error --format=count` returns only items already in the known-pre-existing list (no new regressions)
- [ ] WPCS ruleset passes on changed files (`vendor/bin/phpcs` for touched paths)
- [ ] Every Basecamp card targeting this release is either in **In Testing** or **Done**
- [ ] Every **In Testing** card has a verification comment with evidence (screenshot + DB check)

## 2 — Version + changelog

- [ ] `Version:` header in `jetonomy.php` matches the tag
- [ ] `JETONOMY_VERSION` constant matches
- [ ] `readme.txt` `Stable tag:` matches
- [ ] `readme.txt` has a new `= x.y.z — MonthYYYY =` section listing every user-visible change
- [ ] If schema changed: `JETONOMY_DB_VERSION` bumped + matching migration file committed
- [ ] Tested at least one Basecamp-cited repro URL against the deploy

## 3 — Fresh install (clean Local site)

- [ ] Activate → setup wizard offered
- [ ] Complete wizard → tables created (`wp db tables --all-tables | grep ^wp_jt_` shows 22+ tables in free)
- [ ] Default spaces + categories seeded (or wizard skip path works)
- [ ] `/community/` renders, no fatals in `wp-content/debug.log`
- [ ] Home loads without console errors (`browser_console_messages`)

## 4 — Upgrade from previous release

- [ ] On a site running the previous version: activate the new zip → migrator runs silently
- [ ] `wp option get jetonomy_db_version` → new constant value
- [ ] Previously-created posts still render (inc. posts from before the paragraph fix)
- [ ] Previously-created replies still render
- [ ] No new warnings in debug.log
- [ ] `jetonomy_activity_backfilled` still `1`; no double backfill

## 5 — Browser smoke (desktop 1440px + mobile 390px)

Walk `docs/qa/UX_AUDIT.md` — every ❌ must have a Basecamp card before merge.

## 6 — Email testing (Local mailcatcher)

Local by Flywheel ships with **Mailpit / Mailhog** on the mail tab. For each of these actions, verify the email **lands in the mailcatcher** with expected subject + body:

- [ ] User registers → welcome email (if enabled)
- [ ] New reply to a followed post → reply-notification email
- [ ] @mention in a reply → mention email
- [ ] Space join request → admin notification email
- [ ] Join request approved → member notification email
- [ ] Trust-level promotion → member notification (if enabled)
- [ ] Password reset from the Login block's "Forgot password" link
- [ ] Unsubscribe link at the bottom of an email disables the right notification type (check `user_profile.settings.notifications`)

Pro (if installed):
- [ ] New private message → recipient email
- [ ] Daily/weekly digest → subscribed user email (trigger via `wp jetonomy-pro digest send --user=<id>`)

## 7 — `wp jetonomy qa-actions`

- [ ] `wp --path=. jetonomy qa-actions` → 210/210 smoke checks pass (or the known-flaky list is unchanged)

## 8 — Theme compat spot-check

For each of BuddyX, BuddyX Pro, Reign, Astra, GeneratePress, Twenty Twenty-Four:

- [ ] Activate theme → `/community/` renders, no visual breakage
- [ ] Dark mode (if supported) flips colors cleanly

## 9 — Release packaging

- [ ] `composer install --no-dev --optimize-autoloader` in a clean clone
- [ ] `dist/jetonomy.zip` built; size sanity-check vs previous release (< 20% jump without explanation)
- [ ] Install the built zip on a throwaway Local site → activates without errors

## 10 — Post-merge

- [ ] Tag `v{version}` pushed
- [ ] GitHub release drafted with copy from `readme.txt` changelog
- [ ] `dist/` uploaded to the store / update server
- [ ] Basecamp cards moved from **In Testing** → **Done**
- [ ] Internal Slack #releases post with highlights + card links
