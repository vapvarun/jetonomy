# Jetonomy Usability Test Master Plan

**Date:** 2026-04-11
**Scope:** Free + Pro, every user-facing flow
**Purpose:** Plan the test architecture BEFORE writing test code. Nothing ships until this plan is approved.

---

## Executive summary

**Total user-visible flows identified: 274**
- Free plugin: **92** flows across 7 actor groups
- Pro plugin: **182** flows across 14 extensions + 4 core-Pro flows
- Cross-extension composite flows: **10** (e.g. "new post submit" fans out to 8 extensions)

**Why this plan exists.** The 281 PHPUnit cases shipped in the CLI module verify that journey classes call model classes correctly. They **do not** verify that the product does what users expect. Audits against `docs/specs/2026-03-17-jetonomy-forum-plugin-design.md`, Pro's `CLAUDE.md`, and 14 Basecamp cards found that only **1 of 14 sampled cards has a real user-expectation test**. The other 13 can regress silently. This plan is the remediation — a usability-first test layer that fails when the product diverges from user intent, not when the code shape changes.

**What we are NOT building.** This is not a second layer of journey tests. The goal is to close the 5-layer gap: usability flow → data flow → UX flow → ease-of-use metrics → expectation vs delivery.

---

## The 5-layer test architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  Layer 5: EXPECTATION vs DELIVERY  (cards/*.yml)                │
│  What did the user come for? What did they get?                 │
├─────────────────────────────────────────────────────────────────┤
│  Layer 4: EASE-OF-USE METRICS      (EaseMetricsCollector)      │
│  clicks-to-goal, time-to-goal, error-count, friction points    │
├─────────────────────────────────────────────────────────────────┤
│  Layer 3: UX FLOW                  (BrowserFlowAsserter)        │
│  Playwright click-by-click: I see X, click Y, I see Z           │
├─────────────────────────────────────────────────────────────────┤
│  Layer 2: DATA FLOW                (DataFlowAsserter)           │
│  click → REST → DB → notification → email → user inbox         │
├─────────────────────────────────────────────────────────────────┤
│  Layer 1: USABILITY FLOW           (flows/*.flow.php)           │
│  End-to-end user story: discover → act → get value             │
├─────────────────────────────────────────────────────────────────┤
│  Layer 0: CONTRACT TESTS           (tests/unit/, tests/pro/)   │
│  ✅ ALREADY DONE — 281 cases covering models + journeys        │
└─────────────────────────────────────────────────────────────────┘
```

Layer 0 stays as the cheap regression floor. Layers 1–5 are the new work, built on top.

---

## Proposed directory layout

```
jetonomy/tests/usability/
├── flows/                              ← one file per user story
│   ├── anonymous/
│   │   ├── A01-view-home.flow.php
│   │   ├── A02-browse-category.flow.php
│   │   ├── A03-browse-space.flow.php
│   │   └── …
│   ├── member/
│   │   ├── C01-create-forum-post.flow.php
│   │   ├── C08-reply-to-post.flow.php
│   │   ├── C14-upvote-post.flow.php
│   │   └── …
│   ├── moderator/
│   ├── space-admin/
│   ├── site-admin/
│   └── pro/
│       ├── messaging/
│       ├── polls/
│       ├── reactions/
│       └── …                           ← one dir per Pro extension
│
├── fixtures/                           ← scenario seeders (reuse C9 runner)
│   └── loader.php
│
├── assertions/                         ← reusable test libraries
│   ├── class-data-flow-asserter.php    ← verify state at each hop
│   ├── class-email-capture-asserter.php ← intercept wp_mail, assert body
│   ├── class-template-render-asserter.php ← render template, assert DOM
│   ├── class-browser-flow-asserter.php ← Playwright driver
│   ├── class-ease-metrics-collector.php ← clicks/time/errors/friction
│   └── class-expectation-matcher.php   ← compares stated vs delivered
│
├── expectations/
│   ├── cards/                          ← one per Basecamp card
│   │   ├── 9773154702.yml              ← user stated expectation
│   │   ├── 9773039992.yml
│   │   └── …
│   └── specs/                          ← pulled from docs/specs
│       ├── trust-level-promotion.yml
│       ├── join-request-approval.yml
│       └── …
│
├── playwright.config.js                ← headed/headless, viewports
├── run-all.sh                          ← runs flows against fresh DB
└── README.md                           ← how to add a new flow
```

Pro tests live under `tests/usability/flows/pro/` in the **free plugin's** tests dir, matching the existing `tests/pro/` pattern so one `composer test:usability` runs everything.

---

## Free plugin flow inventory — 92 flows

### Actor 1: Anonymous visitor — 12 flows

| ID | Flow | Priority | Bc cards |
|---|---|---|---|
| A01 | View community home | P0 | — |
| A02 | Browse a public category | P0 | — |
| A03 | Browse a public space (forum/qa/ideas/feed) | P0 | — |
| A04 | Read a single post + replies | P0 | — |
| A05 | Browse tag page | P1 | — |
| A06 | Search read-only | P1 | — |
| A07 | View leaderboard | P1 | — |
| A08 | View user profile | P1 | — |
| A09 | Land on invite link | P1 | — |
| A10 | Attempt gated action → login wall | P0 | — |
| A11 | Register new account | P0 | — |
| A12 | Lost password flow | P1 | — |

### Actor 2: Registered user, not yet a member — 8 flows

| ID | Flow | Priority | Bc cards |
|---|---|---|---|
| B01 | First login → community redirect | P0 | — |
| B02 | Edit own profile first time | P1 | — |
| B03 | Browse space directory, pick one | P0 | — |
| B04 | **Join open-policy space (instant)** | P0 | **9773039992** |
| B05 | Request to join approval-policy space | P1 | 9725048839 |
| B06 | Rejected on invite-only attempt | P2 | — |
| B07 | Accept invite link after login | P1 | — |
| B08 | Bookmark content for later | P2 | — |

### Actor 3: Space member (TL 0–1) — 36 flows

**Content creation (14)** — C01 create forum post, C02 create Q&A question, C03 create idea, C04 create feed post, C05 save as draft, C06 edit own post, C07 delete own post, C08 reply flat, C09 reply threaded, C10 quote-to-reply, C11 edit own reply, C12 delete own reply, C13 accept answer, C14 edit with revision history.

**Voting (4)** — C15 upvote post, C16 downvote post, C17 switch/undo vote, C18 vote on reply.

**Mentions & discovery (3)** — C19 @mention user, C20 keyboard shortcuts, C21 passive read tracking.

**Notifications (4)** — C22 open dropdown, C23 **mark single notification read** [**9773154702**], C24 mark all read, C25 configure preferences.

**Subscriptions & bookmarks (6)** — C26 subscribe to space, C27 subscribe to post, C28 unsubscribe, C29 bookmark post, C30 view bookmarks, C31 remove bookmark.

**Reporting (2)** — C32 flag a post (all 5 reasons), C33 flag a reply.

**Membership & profile (3)** — C34 leave a space, C35 update bio/avatar/signature, C36 view sub-profile tabs.

### Actor 4: Trusted user (TL 2–3) — 6 flows

| ID | Flow | Priority | Bc cards |
|---|---|---|---|
| D01 | Auto-promotion to TL2/TL3 | P1 | — |
| D02 | Edit other users' posts | P1 | — |
| D03 | Create new tags inline | P2 | — |
| D04 | Downvote without restriction | P2 | — |
| D05 | Edit others' replies | P1 | — |
| D06 | Split reply into new topic | P2 | — |

### Actor 5: Space moderator — 13 flows

M01 open mod queue, M02 approve flagged content, M03 mark as spam, M04 trash flagged, M05 resolve flag without action, M06 close/lock post, M07 pin/unpin, M08 move post, M09 merge posts, M10 ban user global, M11 unban, M12 silence user, M13 IP ban.

### Actor 6: Space admin — 9 flows

| ID | Flow | Priority | Bc cards |
|---|---|---|---|
| SA01 | Review pending join requests | P0 | 9725048839 |
| SA02 | **Approve join request** | P0 | **9725048839** |
| SA03 | Deny join request | P0 | 9725048839 |
| SA04 | Create invite link | P1 | — |
| SA05 | Edit space settings | P0 | — |
| SA06 | Manage space members | P0 | — |
| SA07 | Configure access rules | P1 | — |
| SA08 | Create sub-space | P1 | — |
| SA09 | Configure roadmap columns | P2 | — |

### Actor 7: Site admin — 30 flows

GA01 plugin activation, GA02 setup wizard first visit, GA03 seed demo data, GA04 cleanup demo, GA05 admin dashboard, GA06 category CRUD, GA07 space CRUD, GA08 content management, GA09 mod queue admin-side, GA10 user list + ban/trust control, GA11 set trust level manually, GA12 general settings tab, GA13 permissions tab, GA14 email settings tab, GA15 SEO tab, GA16 appearance tab, GA17 anti-spam tab, GA18 trust level thresholds, GA19 rate limits, GA20 flush rewrite rules, GA21 import bbPress, GA22 import wpForo, GA23 import Asgaros, GA24 resume failed import, GA25 send test email, GA26 view/flush object cache, GA27 plugin deactivation, GA28 plugin uninstall, GA29 WP-CLI operations, GA30 REST API via application password.

**Plus the 2 Basecamp bugs already fixed:** **GA31** posts-per-page setting applied in space listing [9721640432], **GA32** settings defaults seeded on activation [9763494148].

### Cross-cutting — 14 flows

X01 BuddyPress profile tab, X02 login auto-redirect, X03 oEmbed rendering, X04 drag-drop image upload, X05 emoji picker, X06 code syntax highlighting, X07 Akismet pre-check, X08 shortcodes/widgets, X09 GDPR exporter/eraser, X10 nav-menu integration, X11 trust evaluator cron, X12 activity backfill cron, X13 real-time polling updates, X14 Abilities API execution.

### Known free-plugin gaps (not currently implemented, flag as P-future)

1. Trending/Top-this-week surface (mentioned in readme, no route)
2. Per-space archive/lock (only post-level close exists)
3. Integrations settings tab (readme implies, tab list has Appearance+Anti-Spam instead)
4. In-admin email template editor (hard-coded HTML wrapper in Notifier)
5. Dedicated diagnostics page (debug info scattered)
6. Multisite-aware activation path

---

## Pro plugin flow inventory — 182 flows across 14 extensions

| Extension | Flows | P0 | P1 | P2 | License tier |
|---|---|---|---|---|---|
| private-messaging | 15 | 7 | 6 | 2 | starter |
| polls | 14 | 5 | 6 | 3 | starter |
| reactions | 9 | 3 | 4 | 2 | starter |
| custom-badges | 13 | 3 | 7 | 3 | starter |
| custom-fields | 14 | 3 | 7 | 4 | starter |
| analytics | 11 | 3 | 5 | 3 | starter |
| email-digest | 14 | 4 | 7 | 3 | starter |
| web-push | 11 | 3 | 5 | 3 | starter |
| webhooks | 13 | 3 | 7 | 3 | starter |
| advanced-moderation | 13 | 4 | 6 | 3 | starter |
| seo-pro | 13 | 2 | 7 | 4 | growth |
| white-label | 12 | 2 | 6 | 4 | agency |
| reply-by-email | 11 | 3 | 5 | 3 | growth |
| ai | 19 | 4 | 9 | 6 | growth |
| **TOTAL** | **182** | **49** | **87** | **46** | |

### Hot flows (Pro P0 coverage targets — first 49 to build)

**Messaging:** PM01 open inbox, PM02 compose DM new thread, PM03 compose DM reuse direct thread, PM05 open conversation, PM06 send message in existing thread, PM08 mark conversation read, PM10 unread badge in header.

**Polls:** POL01 create poll inline on new post, POL03 vote single-choice, POL04 vote multi-choice, POL06 see tallies + percentages.

**Reactions:** REA01 first reaction, REA02 toggle off, REA04 react to reply, REA06 reaction bar renders.

**Custom Badges:** BAD02 admin create badge, BAD05 manual award, BAD06 auto-award via cron.

**Custom Fields:** CF01 admin create field, CF04 list by context, CF07 read post field values.

**Analytics:** AN01 overview dashboard, AN10 permission denied non-admin, AN11 admin page renders.

**Email Digest:** ED01 user sets frequency, ED03 daily cron fires, ED04 weekly cron fires, ED07 click unsubscribe link.

**Web Push:** WP01 grant permission + subscribe, WP02 serve service worker, WP03 fetch VAPID key, WP04 notification triggers push.

**Webhooks:** WH01 admin create webhook, WH06 event fires → dispatch, WH11 event: post.created end-to-end.

**Advanced Moderation:** AM01 admin create rule, AM06 content submission triggers evaluation, AM07 block action prevents post, AM08 hold action queues.

**SEO Pro:** SEO01 set per-space meta title, SEO10 frontend renders meta tags via wp_head.

**White Label:** WL08 frontend custom logo renders, WL12 branding tab in settings.

**Reply-by-Email:** RBE01 outbound notification gets Reply-To token, RBE03 IMAP cron polls, RBE04 inbound webhook.

**AI:** AI01 admin configure OpenAI provider, AI08 enable spam_detection, AI13 user submits post → spam verdict, AI17 REST usage endpoints.

**Pro core:** PRO01 admin activate license, PRO02 admin toggle extension, PRO03 license tier gating on boot.

### Cross-extension composite flows — 10 flows

These hit the most extensions per single user action. Highest test leverage because a single flow exercises multiple extensions' integration surface.

| ID | User action | Extensions touched | Priority |
|---|---|---|---|
| XP01 | **New post submit** | ai.spam_detection → advanced-moderation → custom-fields → polls → core post insert → webhooks → web-push → email-digest queue → reply-by-email Reply-To | **P0** |
| XP02 | New reply submit | ai.spam_detection → advanced-moderation → webhooks → web-push → email-digest → reply-by-email | P0 |
| XP03 | User reacts to post | reactions → analytics | P1 |
| XP04 | User votes on poll | polls → webhooks → analytics | P1 |
| XP05 | User creates conversation | private-messaging → web-push (via notification) | P0 |
| XP06 | User registers | webhooks → email-digest → custom-badges (cron) | P1 |
| XP07 | Flag created | advanced-moderation → webhooks → analytics | P1 |
| XP08 | Space page loads | seo-pro → white-label → custom-fields | P0 |
| XP09 | Trust level changes | webhooks → custom-badges → private-messaging (gate) | P1 |
| XP10 | Admin views dashboard | analytics → ai (usage widget) → license status | P1 |

### Pro gaps & potential bugs discovered during audit

1. **Messaging silently disabled when WPMediaVerse is active** — precondition check needed
2. **Reactions "exclusive mode"** — per-user-per-object single reaction contradicts user docs ("Slack/GitHub-style multi")
3. **Polls `allow_other`** column exists, no write path — dead field
4. **Polls no DELETE REST route** — admin-page-only
5. **Polls no one-per-post enforcement** — can create duplicates
6. **Custom fields PATCH permission is `is_user_logged_in`** — no author check, any logged-in user can edit any post's custom fields — **security bug**
7. **Direct conversation reuse race condition** — two simultaneous DMs can create duplicates
8. **Analytics queries unindexed** — `jt_posts.created_at` index needed
9. **Webhooks no delivery history surface**
10. **Advanced moderation no priority field** — "highest severity wins" not implemented
11. **SEO Pro no preview endpoint**
12. **White label custom CSS sanitization** — verify it strips `<script>`, `expression()`, `javascript:`
13. **Reply-by-email rate-limit happens after DB work**
14. **AI API keys stored unencrypted** in `jetonomy_pro_ai_settings` option
15. **AI semantic_search stub** — advertised but not wired
16. **Email digest no send-test-to-arbitrary-user** — admin can only test to self
17. **Push no subscriber-count dashboard**
18. **Custom badges no revoke REST route** — only `is_active=0` soft delete

---

## Priority rollup — 274 total flows

| Priority | Free | Pro | Total |
|---|---|---|---|
| P0 (critical, ship blocker) | 34 | 49 | **83** |
| P1 (important, feature breaker) | 42 | 87 | **129** |
| P2 (nice-to-have, corner case) | 16 | 46 | **62** |
| **Total** | **92** | **182** | **274** |

---

## What each flow file looks like (example)

To make the architecture concrete, here's what a flow file would contain. This is the proposed structure, not code to commit yet:

```php
// tests/usability/flows/member/C23-mark-notification-read.flow.php
//
// Basecamp: 9773154702
// User story: "As a user with unread notifications, when I click one in the
// dropdown, I expect that notification to be marked read immediately and
// the badge count to decrement, so I can triage my notifications one at a
// time without opening the full page."
//
// Acceptance criteria (pulled from the bug report):
// 1. Dropdown shows unread items as visually distinct (bold/highlighted)
// 2. Clicking an item navigates to the target post
// 3. The clicked item's is_read flag flips to 1 in DB
// 4. The badge count decrements by 1
// 5. Returning to the source page shows the updated count
// 6. Total clicks to complete = 2 (open dropdown + click item)
// 7. No error dialogs or 5xx responses

use Jetonomy\Tests\Usability\Flow;

return Flow::name('mark-notification-read')
    ->card('9773154702')
    ->actor('member')
    ->priority(Flow::P0)

    ->fixture(function () {
        // Seed: user with 5 unread notifications
        $this->scenario->run('notification-delivery-sweep');
    })

    ->userStory(function () {
        // Layer 3: UX FLOW — click-by-click in browser
        $this->browser->autologin(1);
        $this->browser->visit('/community/');

        // Layer 4: EASE METRICS — start timer + click counter
        $this->metrics->start();

        // Assertion: badge count = 5 before click
        $this->browser->assertSee('.jt-community-nav-badge', '5');

        // Click the bell
        $this->browser->click('.jt-notif-dropdown-wrap button');
        $this->metrics->recordClick();

        // Assertion: dropdown shows 5 unread items, all styled
        $this->browser->assertCount('.jt-notif-panel-item.unread', 5);

        // Click the first unread item
        $first = $this->browser->find('.jt-notif-panel-item.unread:first');
        $notifId = $first->attr('data-jt-notif-id');
        $first->click();
        $this->metrics->recordClick();

        // Layer 2: DATA FLOW — verify hop-by-hop state
        $this->data->assertRestCalled('PATCH', "/notifications/{$notifId}");
        $this->data->assertDbColumn('wp_jt_notifications', $notifId, 'is_read', 1);
        $this->data->assertNoEmailSent(); // no mail should fire

        // Layer 3 continued: navigation landed on target post
        $this->browser->assertUrlMatches('#/community/s/.+/t/.+/#');

        // Return to source page
        $this->browser->back();

        // Assertion: badge now shows 4
        $this->browser->assertSee('.jt-community-nav-badge', '4');

        // Assertion: clicked item no longer bold in dropdown
        $this->browser->click('.jt-notif-dropdown-wrap button');
        $this->browser->assertCount('.jt-notif-panel-item.unread', 4);

        // Layer 4: EASE METRICS — assert targets met
        $this->metrics->assertClickCount(lessThanOrEqual: 3);
        $this->metrics->assertTimeToGoal(seconds: lessThan: 10);
        $this->metrics->assertErrorCount(0);

        // Layer 5: EXPECTATION vs DELIVERY
        $this->expectation->fromCard('9773154702')
            ->matchesDelivery([
                'badge_decremented' => true,
                'row_flipped' => true,
                'navigation_succeeded' => true,
                'clicks_to_goal' => 2,
            ]);
    });
```

One file. Five layers. Reads like a user story. Fails at the layer where the problem actually lives.

---

## Execution phases (when approved)

### Phase 0 — Foundation (1 commit, ~500 LOC)
Scaffold `tests/usability/` directory, write the 6 assertion library classes, install Playwright, wire `composer test:usability` script. No flow files yet, just the harness.

### Phase 1 — Basecamp-locked flows (4 commits, ~1500 LOC total)
One commit per previously-fixed bug. Each commit ships the flow file AND `expectations/cards/{id}.yml`. Verifies the harness catches the bug when run against the v1.3.0 pre-fix commit.

- C16: 9773154702 notification click
- C17: 9773039992 Join Space button
- C18: 9725048839 join request email URL + tab
- C19: 9721640432 posts per page setting
- C20: 9763494148 settings defaults seeded

### Phase 2 — Free P0 flows (34 flows → ~12 commits, ~5000 LOC)
Group by actor. One commit per actor group. Each commit is reviewable as a cohesive set.

### Phase 3 — Pro P0 flows (49 flows → ~14 commits, ~7500 LOC)
One commit per Pro extension. Each commit also updates the cross-extension matrix entry.

### Phase 4 — Cross-extension composite flows (10 flows → 5 commits, ~2500 LOC)
XP01 and XP02 (new post / new reply fan-out) are the highest leverage — one flow file exercises 8 extensions.

### Phase 5 — Free P1 flows (42 flows → ~10 commits, ~4000 LOC)
### Phase 6 — Pro P1 flows (87 flows → ~20 commits, ~8500 LOC)
### Phase 7 — P2 flows (62 flows → ~10 commits, ~3000 LOC)

**Grand total estimate:** ~32,500 LOC across ~75 commits over 3-4 weeks of focused work.

---

## What I need approved before writing any code

1. **The 5-layer architecture** — do the layer names and responsibilities match what you're asking for? Alternative phrasings welcome.
2. **The directory layout** — `tests/usability/` in the free plugin, Pro tests under `tests/usability/flows/pro/`.
3. **Playwright as the browser driver** — alternative: wp-browser / Codeception. Playwright is faster and has better WP-auto-login support.
4. **The 274 flow inventory** — did I miss anything? Should I add more flows? Should I drop any (e.g. "GDPR exporter" feels low value)?
5. **The phasing** — start with the 5 Basecamp-locked flows as proof-of-architecture, then fan out. Or start with Phase 0 foundation and pause for checkpoint.
6. **Effort budget** — ~32k LOC / ~75 commits. Acceptable? If not, which phases to cut?
7. **The Pro gaps I surfaced** — some are security bugs (CF07 author check missing, AI keys unencrypted). Should I file these as Basecamp cards now, or fix them in-line as we build the corresponding flow tests?

---

## What this plan explicitly does NOT cover

- **Visual regression testing** (pixel diffs across themes) — out of scope
- **Load testing / concurrency** — separate discipline
- **Accessibility (a11y)** — separate test category, would need axe-core integration
- **i18n** — translation files, RTL rendering
- **Multi-site mode** — flagged as a known gap, not in this plan
- **Plugin-against-plugin compatibility** — would require a matrix of active plugins per test run
- **Performance budgets** (page weight, query count) — different tool set

These can all be added in follow-up plans once the usability foundation is in place.

---

## Ready to review

Every flow in the 274 list has a name, priority, actor, and (where applicable) a Basecamp card link. The architecture has 5 clear layers. The directory structure is concrete. The phasing is ordered by risk (bug-locked flows first).

**Pending approval on 7 decision points above before any test file gets written.**
