# Jetonomy Usability Tests

Browser-level tests that verify user flows end-to-end: click-through in a real browser, REST dispatch, DB state, email delivery, and ease-of-use metrics. Tests run against a live WordPress install using Playwright.

## What this is NOT

- Not unit tests — those live in `tests/unit/`
- Not journey contract tests — those live in `tests/unit/cli/` and `tests/pro/cli/`
- Not static analysis — PHPStan + WPCS handle that

## What this IS

Every flow file exercises the full 5-layer stack from the usability test plan (`docs/plans/2026-04-11-usability-test-plan.md`):

1. **Usability flow** — end-to-end user story
2. **Data flow** — click → REST → DB → notification → email
3. **UX flow** — Playwright click-by-click in browser
4. **Ease metrics** — clicks-to-goal, time-to-goal, errors
5. **Expectation vs delivery** — compares outcome against a YAML expectation file

If any layer breaks, the test fails at that layer with a precise error message.

## Prerequisites

**Local machine:**
- Node.js 18+
- A running WordPress install with both `jetonomy` and `jetonomy-pro` active
- The `wp` CLI on your PATH pointing at the same install
- The auto-login mu-plugin at `wp-content/mu-plugins/dev-auto-login.php`
- The mail-capture mu-plugin symlinked or copied from `tests/usability/mu-plugins/jetonomy-test-mail-capture.php`
- `define( 'JETONOMY_TEST_MAIL_CAPTURE', true );` in `wp-config.php`

**CI:** See the `phpunit-with-pro` job in `.github/workflows/ci.yml` for the reference spin-up sequence. The usability matrix job will be added in Phase 0.5.

## First-time setup

```bash
cd tests/usability
npm install                  # installs Playwright + js-yaml
npx playwright install        # installs browser binaries (Chromium by default)
```

Copy the mail capture mu-plugin into place:

```bash
cp mu-plugins/jetonomy-test-mail-capture.php \
   ../../../../../mu-plugins/jetonomy-test-mail-capture.php
```

Add the enable flag to `wp-config.php`:

```php
define( 'JETONOMY_TEST_MAIL_CAPTURE', true );
```

## Running

```bash
# Run everything (free + pro)
npm test

# Headed mode — watch the browser drive itself
npm run test:headed

# Interactive debugger (Playwright Inspector)
npm run test:debug

# Free only
npm run test:free

# Pro only
npm run test:pro

# The 5 previously-fixed Basecamp bugs
npm run test:basecamp

# Open the HTML report after a run
npm run report
```

## Environment variables

- `JETONOMY_TEST_BASE_URL` — default `http://forums.local`. Point this at your test site.
- `JETONOMY_TEST_WP_PATH` — default resolves from this directory. Override if your `wp` CLI needs a different `--path`.
- `JETONOMY_TEST_MAIL_FILE` — default `wp-content/debug-mail.jsonl`. Override if your mail capture writes elsewhere.
- `CI` — set by the runner; enables retries and disables `test.only`.

## Adding a new flow

1. **Find the Basecamp card** or user story that describes the expectation. If none exists, write one first — the point of this test layer is to encode user intent, not developer intent.

2. **Create an expectation YAML** at `expectations/cards/<card-id>.yml`:

   ```yaml
   card_id: "9773154702"
   title: "Clicking Individual Notification Does Not Mark as Read"
   user_story: |
     As a user with unread notifications, when I click one in the dropdown,
     I expect it to be marked read immediately and the badge count to
     decrement, so I can triage my notifications one at a time without
     opening the full page.
   expectations:
     badge_decremented: true
     row_flipped_to_read: true
     navigation_succeeded: true
     max_clicks_to_goal: 2
     max_time_to_goal_seconds: 10
     no_errors: true
   ```

3. **Create a flow file** at `flows/<actor>/<id>-<slug>.flow.js`. Import the
   Playwright test harness plus the helper library and follow the pattern in
   existing flows. See `flows/basecamp/9773154702-mark-notification-read.flow.js`
   for the canonical example.

4. **Run it locally** in headed mode first — `npx playwright test --headed path/to/your.flow.js` — so you can see the browser drive itself. Fix any flakiness before committing.

5. **Commit** the flow file and the expectation YAML in the same commit, with the Basecamp card ID in the commit message.

## Directory layout

```
tests/usability/
├── package.json
├── playwright.config.js
├── README.md                          ← you are here
│
├── helpers/                           ← reusable test libraries
│   ├── wp-cli.js                      ← safe execFileSync wrapper
│   ├── data-flow.js                   ← DB + REST state assertions
│   ├── email-capture.js               ← reads the mail jsonl
│   ├── ease-metrics.js                ← clicks, time, errors
│   ├── expectation-matcher.js         ← YAML → delivery diff
│   └── auto-login.js                  ← ?autologin=<user> helper
│
├── fixtures/
│   └── scenarios.js                   ← wraps `wp jetonomy scenario run`
│
├── flows/                             ← one file per user story
│   ├── basecamp/                      ← Phase 1: the 5 locked bugs
│   ├── anonymous/
│   ├── member/
│   ├── moderator/
│   ├── space-admin/
│   ├── site-admin/
│   └── pro/
│       ├── messaging/
│       ├── polls/
│       └── …
│
├── expectations/
│   └── cards/
│       ├── 9773154702.yml
│       └── …
│
├── mu-plugins/
│   └── jetonomy-test-mail-capture.php ← symlink into wp-content/mu-plugins/
│
└── reports/                           ← Playwright HTML reports (gitignored)
```

## Design notes

- **No shell strings.** Every `wp-cli` call goes through `execFileSync` with an argv array. No user input ever lands in a shell command.
- **Auto-login, not form typing.** Tests use `?autologin=<login>` to skip the WP login form entirely. Faster and less flaky.
- **Scenarios for fixtures.** Flow tests call `runScenario('space-with-pending-join-request')` rather than building their own SQL. The scenarios already handle teardown.
- **Mail capture, not SMTP.** The `pre_wp_mail` filter in the mu-plugin short-circuits delivery and writes to disk, so tests assert on body content without any real email account.
- **Serial by default.** Tests share a DB, so they run one at a time. If you need parallel, use `--workers` but scope fixtures carefully.
- **Retain traces on failure.** The Playwright config captures a trace, video, and screenshot whenever a test fails. Open the report (`npm run report`) to replay the run step-by-step.

## Known limitations

- **Single-site only.** Multisite test mode is not yet wired.
- **i18n not covered.** All assertions are against English strings.
- **No visual regression.** Pixel diffs are out of scope — see the plan for reasoning.
- **No accessibility audit.** Can be added later via `@axe-core/playwright`.
