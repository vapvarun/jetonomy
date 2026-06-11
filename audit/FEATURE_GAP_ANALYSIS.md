# Jetonomy Feature Audit & SaaS Gap Analysis (complete, code-grounded)

> Generated 2026-05-31 via a 9-domain agentic code audit (free + pro), benchmarked
> against Circle, Skool, Mighty Networks, Bettermode, and Discourse. Every "have"
> was grounded in source; "confirmed-absent" items were grepped directly.
> Manifests were stale at audit time (~100 commits behind) — regenerate before
> treating manifest-anchored gaps as final.

## 1. Executive Summary

With all 9 domains audited against code, Jetonomy is roughly **65-70% complete**
versus mainstream SaaS community-platform expectations. Its core community engine
is genuinely strong: a well-modeled content layer (posts, replies, votes,
revisions, bookmarks, tags), a deep trust/reputation/badges gamification stack,
mature moderation with a Pro rules engine and spam scoring, and an unusually broad
developer platform (~90 free + ~84 pro REST routes, 154+ hooks, webhooks, blocks,
shortcodes, WP-CLI, importers, adapters). The biggest honest gap is that Jetonomy
is a **community engine, not a SaaS platform**: no native payments/checkout, no
SSO/social login, no customer-facing billing, no native full-text search, no email
broadcast/sequence system, and no admin-action audit log. It also lacks some UX
polish (markdown, inline reply composer, link-preview cards, per-post read state)
and member-discovery basics (member directory, member search). Standout
differentiators: the criteria-based custom-badges engine with event-driven
re-evaluation, the 12+ membership/LMS adapter layer (detection-only), and the
breadth of the developer surface. Production-ready for small-to-mid WordPress-native
communities that already own a payment stack; not yet a turnkey SaaS replacement.

## 2. What We Have (confirmed strengths, deduped)

### Spaces & Organization
- Nested spaces + categories, 4 space types, 3 visibility levels (public/private/hidden), 3 join policies (open/invite/approval).
- Pinning, Q&A accepted answers, Ideas 4-lane roadmap kanban, tags, denormalized counters, server-side limit/offset pagination.

### Content & Discussions
- Post model with status (publish/draft/scheduled) and 4 post types — topic, question, idea, status feed (`includes/models/class-post.php`, `templates/views/new-post.php`).
- Threaded replies with `parent_id`, auto-incremented counters, depth-tracked rendering (`includes/models/class-reply.php`, `single-post.php`).
- Edit history / revisions (content before/after, edited_by) (`includes/models/class-revision.php`).
- Upvote/downvote with denormalized score (`includes/models/class-vote.php`, votes REST controller).
- Rich contenteditable editor, HTML stored via `wp_kses_post()` (`templates/partials/compose-fields.php`).
- Drafts + scheduled publishing via cron (`publish_scheduled()`, `list_drafts_by_user()`).
- @Mentions wired to notifications (`includes/class-mentions.php`).
- Bookmarks, media uploads (REST `POST /jetonomy/v1/media`, capability-gated), tags, oEmbed pipeline (`jetonomy_format_content()`).
- Pro engagement: emoji reactions (8 types, Interactivity API) and polls (single/multi-choice, close dates).

### Members, Profiles, Trust & Gamification
- 6 trust levels (Newcomer→Moderator) with configurable auto-promotion thresholds (`includes/trust/class-trust-levels.php`).
- Reputation system, 9-action point map, admin-configurable deltas, filter hooks (`includes/trust/class-reputation.php`).
- User profiles with stats, online status, activity tabs (posts/replies/votes/bookmarks/drafts).
- Leaderboard with reputation ranking + period filtering (all-time/month/week).
- Pro custom badges: 8-metric criteria engine, operators, all/any match modes, tiers (bronze/silver/gold), categories, reputation bonus, manual award, event-driven async re-evaluation + 6-hour cron.
- Pro custom fields extension (present; depth unverified).

### Moderation & Safety
- Flags + reasons, global + per-space queue, approve/spam/trash, global/space bans, silences, IP bans, rate limits, 90-day activity log.
- Pro: rules engine, spam scoring, AI spam detection, CAPTCHA (reCAPTCHA v3 + Turnstile), webhooks, reputation reward/penalty.

### Admin, Onboarding, Analytics, Branding & Compliance
- Admin dashboard (6 stat cards), multi-tab settings (General/Permissions/Email/Appearance/SEO/Anti-Spam), user/space/content/moderation management (`includes/admin/views/*`).
- Setup wizard, invite-link system, join request + approval flow.
- Activity log with filtering + CSV export; activity tracker for community events.
- GDPR data export AND erase via WP Privacy API, plus purge-on-delete (`includes/class-privacy.php`) — **GDPR export is present, not a gap**.
- Pro analytics dashboard with REST endpoints (overview, top-spaces, top-contributors, engagement, moderation), time ranges, CSV export.
- Pro white-label: logo, community name, footer, admin menu label/icon, accent color, custom CSS injection.
- Full i18n text domains + shipped RTL CSS (14 files); email template storage option.

### Monetization, Membership & Access
- Membership adapters (detection + space-sync only): MemberPress, PMPro (free); RCP, WooCommerce Memberships + Subscriptions, and 5 LMS adapters — LearnDash, LifterLMS, Sensei, Tutor, MasterStudy (pro).
- AccessRule engine: everyone/logged_in/role/capability/trust_level/membership; grants read/participate/full (`includes/models/class-access-rule.php`).
- Private/secret spaces, join requests (approve/deny with audit timestamps), direct invite links.
- Auto-sync of space membership when external subscriptions activate/refund/cancel.

### Developer Platform
- ~90 free + ~84 pro REST routes, 154+ hooks, webhooks (13 events, HMAC, auto-disable), 8 blocks, 9 shortcodes, 28 WP-CLI commands, 3 importers (bbPress/wpForo/Asgaros), adapter pattern, 39 theme templates, Abilities API (20), 3-layer permissions.

## 3. Critical Gaps (must-have)

> **SCOPE CORRECTION (2026-06-11, Varun).** Most of the "gaps" below are deliberately
> **out of scope for Jetonomy** — they belong to sibling products in the Wbcom suite, and
> building them into Jetonomy would duplicate those products ("waste"):
>
> - **BuddyNext** owns: member directory + member search, SSO / social login, read/unread
>   (activity) tracking, and **payments / billing UI** (BuddyNext ships membership plans).
> - **Eventonomy** owns: events / calendar / RSVP.
>
> Jetonomy stays the focused discussion / Q&A engine. The only row below that remains a
> genuine Jetonomy consideration is **native full-text search** — and even that is *in-scope
> but low priority*: the current search is already good, so pursue only on explicit request.
> Treat the rest of this section as a competitive-landscape reference, **not** a Jetonomy roadmap.

| Feature | Domain | Why it matters | Effort |
|---|---|---|---|
| Native payments / checkout (Stripe/PayPal) | Monetization | Confirmed absent (grep returned zero). No SaaS revenue engine; depends entirely on third-party membership plugins | L |
| Customer-facing subscription/billing UI | Monetization | No "My Subscriptions / renew / cancel / payment method"; users must leave the community to manage billing | L |
| SSO / social login (OAuth2) | Members | Confirmed absent — WP-native users only; enterprise/multi-community blocker | L |
| Native full-text search | Content / Cross-cutting | No FULLTEXT index; instant search is table-stakes vs Discourse/Mighty Networks | L |
| Email broadcasts / sequences | Admin/Onboarding | No broadcast, welcome sequence, or campaign system; only transactional `wp_mail()` with no log/bounce tracking | L |
| Admin-action audit log (who changed what) | Admin/Compliance | Activity log captures community events only; no log of settings/role/space/branding changes | M |
| Member directory + member search/filter | Members | Profiles discoverable only via leaderboard or direct URL; no `/members/` browse or filters | M |
| Markdown support (with code fences) | Content | Editor is HTML contenteditable only; Discourse/Circle markdown workflow expected | M |
| Inline reply composer | Content | Page-level reload per reply; no quick-reply drawer; perceived UX lag vs Slack/Discourse | M |
| Per-post/reply paywall | Monetization | Access gates spaces only; no single-content paywall (Substack/Circle parity) | M |
| AI product features beyond spam detection | Pro / Cross-cutting | Only confirmed AI use is moderation spam detection + adapter scaffold; no AI summarize/answer/compose/semantic search | M-L |
| Two-factor auth (2FA) | Admin/Compliance | No native 2FA for admins; delegated to WP security plugins | L |

> **Courses-as-content** is confirmed PARTIAL-absent — the 5 LMS adapters gate access by
> course enrollment but do NOT render lessons/progress/drip inside the community.
> "Community + courses" buyers (Skool) will see this as missing.

## 4. Expected Gaps (standard, noticed if absent)

| Feature | Domain | Why it matters | Effort |
|---|---|---|---|
| Link preview / unfurl cards | Content | Pasted URLs render as plain links; Slack/Discord/Circle unfurl | M |
| Per-post read/unread state + "new" indicators | Content | No read tracking; can't see what changed since last visit | M |
| Saved/bookmarked replies (only posts bookmarkable) | Content | Partial — bookmarks exist for posts, not replies | S |
| Reaction parity on posts (reactions appear reply-centric) | Content | Verify reactions on both posts and replies | S |
| Following members / member activity feed | Members | No follow graph; "follow" expected in Circle/Mighty | M |
| Member-to-member DMs discoverable from profile | Engagement | Pro messaging exists; ensure profile entry points | S |
| Scheduled posts UI (model supports it; surface in composer) | Content | Backend supports scheduled; expose in UI | S |
| Bulk admin actions (members/content/spaces) | Admin | One-by-one ops painful at scale | M |
| In-app no-code theming (logo/colors/domain) beyond white-label CSS | Admin/Branding | Circle/Skool point-and-click branding | M |
| Public vs private community-wide mode toggle | Admin | Single switch for SEO-open vs login-walled | M |
| Native events / calendar / RSVP | Engagement | Events are core to Circle/Mighty/Skool retention | L |
| Real-time transport (WebSocket/SSE) beyond polling | Engagement | Verify; polling-only feels dated for chat/live | M-L |
| OpenAPI spec, OAuth scoped tokens, batch endpoint, rate-limit headers | Dev platform | Developer-experience maturity | M |
| REST CRUD for moderation rules / custom fields / analytics | Dev platform | Pro config is UI-only in places | M |
| Dedicated space-name search + featured/pinned spaces on directory | Spaces | Discovery at scale | M |
| Space templates, ownership transfer, custom space metadata | Spaces | Operational flexibility | M |
| Moderation workflow maturity: notes/history, appeals, repeat-offender discipline, bulk actions, inline context, edit-history mod view, unspam recovery, user mute, newcomer hold, escalation, mod digest | Moderation | 11 sub-gaps; workflow maturity | M-L |

## 5. Differentiators (upside, mostly already ours)

- **Criteria-based custom badge engine** with event-driven async re-evaluation + cron — ahead of many competitors.
- **12+ membership/LMS adapter ecosystem** — unique in WP; lean into it as the integration story rather than building native payments first.
- **Breadth of developer surface** (REST + hooks + webhooks + CLI + Abilities API + importers) — position as the "extensible/headless community" platform.
- **Ideas roadmap kanban + Q&A accepted answers** — product-feedback and support-community use cases out of the box.
- Opportunities: AI thread summaries / semantic search (adapter scaffold already exists), native events, a Zapier/automation app.

## 6. Recommended Near-Term Roadmap (1.5.0 → 1.7.0)

**Wave 1 — 1.5.0 (cheap, high-visibility UX + discovery):**
1. Native full-text search (FULLTEXT index + search UI).
2. Member directory + member search/filter.
3. Markdown support + code fences in the editor.
4. Inline reply composer (quick-reply drawer).
5. Surface scheduled-posts UI + reply bookmarks + reactions-on-posts parity (finish existing backends).

**Wave 2 — 1.6.0 (monetization + compliance foundation):**
6. Native Stripe checkout + customer billing UI ("My Subscriptions") — start Stripe-only.
7. Per-post/reply paywall on top of the new checkout.
8. Admin-action audit log.
9. SSO / social login (Google/Apple/OAuth2).

**Wave 3 — 1.7.0 (engagement + intelligence differentiators):**
10. Native events / calendar / RSVP.
11. Email broadcasts + welcome sequences (with send log).
12. AI product features: thread summaries + semantic search (reuse existing AI adapter scaffold).
13. Moderation workflow maturity bundle.
14. Developer-experience: OpenAPI spec + scoped tokens + rate-limit headers.

## 7. Manifest Note

Manifests were **stale** at audit time — generated 2026-05-14, ~100 commits behind.
Known drift: free REST routes ~64→90, pro ~52→84, pro extensions 14→15
(site-announcements added). Code-grepped "confirmed-absent" claims (native payments,
SSO, full-text search, email broadcasts) are reliable; manifest-count-anchored items
should be re-verified after regenerating both manifests via
`/wp-plugin-onboard --refresh`.
