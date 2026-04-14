# Jetonomy — Pitch Deck Outline

**Version:** 1.3.0
**Last updated:** April 2026

Text-only outline for a 12-slide pitch deck. Designer converts to Figma/Keynote/PowerPoint. Tone follows `07-brand-assets/messaging-guide.md` — specific numbers over vague claims, helpful colleague not marketing department.

Use cases:
- Sales calls with agencies and community consultants
- Conference talks at WordCamps, WordPress meetups
- Partner pitches (theme shops, hosting providers)
- Internal kickoff for new Wbcom team members

---

## Slide 1 — Title

**Headline:** Jetonomy
**Subhead:** The modern forum plugin for WordPress
**Tagline:** Forums, Q&A, and idea boards — built for communities that grow.
**Visual:** Full-bleed community home page screenshot with BuddyX theme
**Bottom:** wbcomdesigns.com/downloads/jetonomy/ • v1.3.0 • April 2026

---

## Slide 2 — The Problem

**Headline:** WordPress forum plugins were built for blogs, not communities

**Three bullets (with specific pain):**
- **Slow at scale.** bbPress stores every topic and reply in wp_posts. At 50K topics, wp_postmeta queries get painful — and they slow the whole site, not just the forum.
- **Ugly by default.** Most forum plugins ship their own CSS that fights every theme. You spend days overriding !important selectors or give up and live with a forum that looks bolted on.
- **Moderation never ends.** Every forum plugin assumes human moderators will watch every post. Active communities burn out their moderators in weeks.

**Visual:** Side-by-side screenshots — one bbPress forum on a modern theme (looking out of place), one speed test showing slow query

---

## Slide 3 — The Solution

**Headline:** Jetonomy rewrites the data layer, the design system, and the moderation model

**Three columns (big iconographic numbers):**

| 24 | 6 | 90+ |
|---|---|---|
| **custom MySQL tables** | **trust levels (0–5)** | **REST endpoints** |
| Fast queries, proper indexes, zero wp_postmeta bloat | Community moderates itself — new accounts rate-limited automatically | Full REST API — build anything on top |

**Subhead:** Built for WordPress 6.7+, PHP 8.1+. Theme-adaptive via theme.json.

---

## Slide 4 — Four in One

**Headline:** One plugin, four community modes

**2x2 grid:**
| **Forum** | **Q&A** |
|---|---|
| Classic threaded discussion. Support, general chat, announcements. | Questions get answers. Best answer rises to the top. Stack Overflow for your site. |
| **Ideas** | **Social Feed** |
| Members submit and vote. Roadmap view tracks ideas through Open → Planned → Done. | Lightweight scrollable feed. News spaces, team updates. |

**Bottom:** Mix and match on the same site. Each space has its own type, visibility, and moderators.

---

## Slide 5 — Performance at Scale

**Headline:** Sub-200ms page loads at 50,000 topics with Redis

**Left column — what we did:**
- 24 custom MySQL tables with proper composite indexes
- Denormalized counters (reply_count, vote_score, post_count stored on write)
- Cursor-based pagination on every list endpoint
- Object cache for spaces, profiles, permissions
- FULLTEXT indexes for instant search

**Right column — what it means:**
- Your community grows to 100K+ posts without a performance crisis
- No slow COUNT queries on page load
- List pages stay fast regardless of table size
- Forum traffic never impacts the rest of your WordPress site

**Visual:** Query profiler showing p50/p99 at 50K-topic scale

---

## Slide 6 — Self-Moderating Community

**Headline:** Trust levels reduce moderation work by 90%+

**Stair diagram — 6 trust levels:**
- Level 0: Newcomer — 3 posts/day limit, no links, auto-applied
- Level 1: Member — full posting, rate limits lift
- Level 2: Regular — bypass CAPTCHA, vote weight increases
- Level 3: Trusted — edit own content, flag more
- Level 4: Leader — admin-granted, minor moderation
- Level 5: Moderator — full moderation in assigned spaces

**Below the diagram:**
Zero configuration. Works from day one. Your best defense against spam is that new accounts can't reach it.

---

## Slide 7 — NEW in 1.3.0: AI Moderation

**Headline:** AI that reads every post — on your own server

**Big visual:** Ollama logo + Jetonomy logo, connected with an arrow labeled "localhost only"

**Four features:**
- **Spam detection** — scores every post for spam probability
- **Content moderation** — flags against rules you describe in plain English
- **Reply suggestions** — drafts replies for knowledge-base communities
- **Thread summaries** — pins a summary on long topics

**Privacy callout:**
Four providers supported: OpenAI, Anthropic, custom endpoint, and **self-hosted Ollama**. With Ollama, no content leaves your server. No API keys. No per-request billing. Every decision logged for compliance review.

**Subhead:** This is the biggest 1.3.0 change — and the reason buyers who wouldn't consider AI moderation before are considering it now.

---

## Slide 8 — Works With Any Theme

**Headline:** Adapts to your WordPress theme — automatically

**Left:** Screenshot grid of the same Jetonomy community rendered on 6 different themes (BuddyX, Twenty Twenty-Five, Astra, GeneratePress, Kadence, Blocksy) — each looks native to its theme

**Right:**
Jetonomy reads your theme's `theme.json` and inherits:
- Fonts (body + display)
- Brand and accent colors
- Background, text, border colors
- Border radius
- Spacing

If your theme doesn't publish theme.json, Jetonomy falls back to neutral defaults. Every template is overridable via `your-theme/jetonomy/`.

**Callout:** Zero CSS overrides. Zero iframes. Zero shortcode wrappers.

---

## Slide 9 — Free vs Pro

**Headline:** The free plugin covers everything a real community needs

**Two-column table:**

| | Free | Pro |
|---|:---:|:---:|
| Forum, Q&A, Ideas, Social Feed spaces | ✓ | ✓ |
| Voting, reputation, trust levels | ✓ | ✓ |
| Moderation queue and flagging | ✓ | ✓ |
| Full-text search | ✓ | ✓ |
| 48+ REST API endpoints | ✓ | ✓ |
| Abilities API (19 abilities) | ✓ | ✓ |
| bbPress, wpForo, Asgaros importers | ✓ | ✓ |
| **AI integration (Ollama-ready)** | — | ✓ |
| Private messaging | — | ✓ |
| Emoji reactions, polls, custom badges | — | ✓ |
| Analytics dashboard, email digest | — | ✓ |
| Webhooks, Web push, Reply by email | — | ✓ |
| SEO Pro (per-space controls) | — | ✓ |
| White label, WooCommerce, LearnDash, RCP | — | ✓ |

**Bottom:** 14 Pro modules. No feature locks in free.

---

## Slide 10 — Migration Story

**Headline:** Built-in importers for bbPress, wpForo, and Asgaros

**Three-step timeline:**
1. **Auto-detect** — Jetonomy scans your install and shows what it found: forums, topics, replies, users
2. **Dry run** — preview exactly what will be created before anything is written
3. **Import + resume** — batched processing with live progress bar; resumes from failure point if interrupted

**What migrates:**
- Forums → Spaces
- Topics → Posts (with threading preserved)
- Replies → Replies
- Users → WordPress accounts (unchanged — Jetonomy uses your existing user table)

**Callout:** 301 redirects from old bbPress URLs preserve your SEO.

---

## Slide 11 — Developer Story

**Headline:** Built for developers, not just site owners

**Four quadrants:**

**REST API**
48+ endpoints in free, 90+ with Pro. Cursor-based pagination, JSON schema validation, full OpenAPI documentation.

**WordPress Abilities API**
19 abilities in 5 categories. AI agents and automation tools discover and operate your community without custom integration code. Requires WP 6.9+.

**Adapter Pattern**
Pluggable interfaces for search (MySQL/Meilisearch/Algolia), real-time (polling/WebSockets), email (wp_mail/any ESP), AI providers, and membership (MemberPress/PMPro/WooCommerce/LearnDash/RCP/Tutor).

**Template Overrides**
Copy any template into `your-theme/jetonomy/` and customize without touching the plugin. 20+ action hooks and filters throughout. RTL stylesheet included. Translation-ready.

---

## Slide 12 — Get Started

**Headline:** Start your community today — it's free

**Big CTA button:**
Download Free from wbcomdesigns.com/downloads/jetonomy/

**Secondary CTA:**
See Pro Features → wbcomdesigns.com/downloads/jetonomy-pro/

**Three small links at the bottom:**
- Documentation: wbcomdesigns.com/docs/jetonomy/
- GitHub: github.com/wbcomdesigns/jetonomy
- Support: support.wbcomdesigns.com

**Corner tagline:** Built by Wbcom Designs — the team behind BuddyX, BuddyPress extensions, and WPMediaVerse.

---

## Speaker Notes

**On pacing:** This deck runs ~15 minutes at a sales call pace, ~8 minutes as a conference lightning talk (skip slides 5 and 11 for the short version).

**On slide 7 (AI):** Lead with the Ollama / privacy angle when pitching to regulated industries (health, legal, financial, enterprise internal). Lead with the OpenAI / Anthropic quality angle when pitching to consumer-facing community managers who want the best moderation quality and don't mind the API bill.

**On slide 6 (Trust Levels):** When audience members push back with "but my community is different," the response is: every community says that, trust levels ship with no configuration required, and you can always override the thresholds later. Show the admin setting panel in a side screenshot.

**On slide 9 (Free vs Pro):** Never apologize for the Pro price. Pro is one license, 14 modules, lifetime or annual options — it is competitively priced against any single bbPress add-on, let alone against Discourse's hosted plans.
