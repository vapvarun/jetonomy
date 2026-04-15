# Jetonomy — LinkedIn Posts (Launch Week)

5 posts. LinkedIn rewards longer, narrative-driven content more than any other platform. Each post here is written to be read in full — not skimmed.

Links are embedded inline. Review before publishing.

**Note:** The launch announcement (Post 1) and "Why We Built Jetonomy" (Post 2) posts in `SOCIAL-POSTS.md` cover the broad professional audience. The 5 posts below go deeper — they're written for specific audiences: developers, educators, community managers, and decision-makers evaluating tools.

---

## Post 1 — Launch Announcement (Professional, Problem-Solution)

We spent the last year building a WordPress forum plugin. Here's the problem we were actually solving.

Not the surface problem — "WordPress doesn't have a modern forum plugin." That's true but boring.

The real problem: WordPress is used by 43% of websites. It has a massive ecosystem for publishing, e-commerce, membership, and learning management. But the community layer — the part where users talk to each other — has been frozen in place since roughly 2012.

bbPress, the most-used forum plugin, stores topics as WordPress posts and replies as post metadata. That design made sense when WP was mostly blogs. It degrades badly when you have 50,000 posts and need to load a space listing page with reply counts, member counts, last-post timestamps, and vote scores for 20+ topics. The queries are slow. The caches get complicated. The performance ceiling is low.

We built Jetonomy to fix that at the data layer.

24 custom MySQL tables, designed for the actual query patterns of forum software. Denormalized counters (reply counts, vote scores) updated on write, not computed on read. Cursor-based pagination so list endpoints stay fast regardless of dataset size. A caching layer that uses Redis when available and degrades gracefully when it isn't.

On top of that:
- WordPress Interactivity API frontend — no jQuery, server-rendered HTML that hydrates, SEO-friendly
- CSS that inherits from theme.json — no overrides needed to match your theme
- Three space types: Forum, Q&A (with accepted answers), Ideas (community roadmap with status tracking)
- Three-layer permission system: WP capabilities, per-space roles, trust levels
- bbPress and wpForo importer

The free plugin is at wbcomdesigns.com. Jetonomy Pro adds 10 modules (messaging, analytics, polls, custom badges, webhooks, and more) starting at $99/yr.

If you build community features on WordPress, I'd genuinely value your take on it.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #WebDevelopment #OpenSource #CommunityPlatform

---

## Post 2 — "Why We Built Jetonomy" (Story Format)

Three years ago, a client came to us with one of those requests that sounds simple until you start working on it.

"We want a community forum for our membership site."

We looked at the options.

bbPress: solid reputation, but the last meaningful architectural change was in 2011. Fine for small communities, slow at scale.

wpForo: more features, more modern UI, but heavy and opinionated about design.

BuddyPress: we know it well — we've built plugins on top of it for years — but it's a social network layer, not a focused forum tool. More commitment than this client needed.

Hosted SaaS alternatives: good products, but you're on their infrastructure, their domain, their pricing model. Our clients own their data and their brand.

We couldn't find what we wanted, so we started building it.

That was the first version of what became Jetonomy. It ran in production for one client, then two, then five. We hit the rough edges. We rebuilt the parts that didn't hold up. We designed the thing we wished had existed when we started the search.

A few decisions we made that I think differentiate it:

The data model. We made the call early to use custom tables instead of fitting forum data into WordPress's existing schema. It was more work upfront and more maintenance burden long-term. But it meant the plugin would scale to real community sizes without a performance ceiling.

The frontend. We used the WordPress Interactivity API — the modern approach WordPress itself has embraced — rather than a jQuery-heavy SPA or a server-side-only approach. Server-rendered HTML that hydrates means your forum pages are indexed by search engines. Voting and sorting and infinite scroll work without full page reloads.

The trust system. Most forum plugins treat all members equally except admins and moderators. We built a trust level system where members earn capabilities by contributing. New accounts are automatically rate-limited and blocked from posting links. Trusted members unlock more. Moderators are elevated manually. It removes a huge chunk of spam and low-quality content without any configuration.

We released Jetonomy 1.0 yesterday. It's free at wbcomdesigns.com.

If you're building on WordPress and you've wanted a community layer that actually fits the modern platform — I'd love to hear what you think after you try it.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #ProductLaunch #Community #WebDevelopment

---

## Post 3 — Developer-Focused (REST API and Architecture)

If you're a WordPress developer or agency building community features for clients, here's the technical picture on Jetonomy — the new forum plugin we released this week.

**Database layer**
24 custom MySQL tables — not WordPress post types or post meta. Tables are designed for forum query patterns, with proper composite indexes. Counters (reply_count, vote_score, post_count) are denormalized and updated on write. Cold queries against a 50,000-post community stay under 300ms. With Redis object caching, under 50ms.

**REST API**
42 endpoints at `jetonomy/v1`. Cursor-based pagination on every list endpoint — not offset-based. Cursor pagination is stable when new content is added between page requests, which offset-based pagination is not. Full rate limiting at the API layer. Response shapes are consistent and documented.

**Frontend**
WordPress Interactivity API with `@wordpress/interactivity` directives. No jQuery, no custom framework. The server renders full HTML — pages are indexable by search engines. Client-side interactions (voting, sorting, loading replies) hydrate the existing HTML. It's the architecture WordPress itself recommends for modern plugin UIs.

**Permissions**
Three-layer system resolving in a single function call: `Permission_Engine::can($user_id, $action, $space_id)`.
Layer 1: WordPress capabilities (20 custom caps mapped to WP roles).
Layer 2: Per-space roles (viewer, member, moderator, admin — each space has its own role assignments).
Layer 3: Trust level gates (Level 0 can't post links, Level 3 can edit freely, etc.) + membership adapter rules.

**Extensibility**
Full action and filter hook system throughout. Template overrides via `your-theme/jetonomy/` directory — any template file can be replaced by placing a copy in the theme. CSS custom properties only — no hardcoded values, so themes that define their colors via theme.json get automatic adaptation without CSS overrides.

The Extension API is available for Pro — same pattern Jetonomy Pro uses internally to add modules.

PHP 8.1+, WP 6.7+, zero jQuery dependencies, Composer autoloader.

Docs at [LINK-DOCS]. Free plugin at https://wbcomdesigns.com/downloads/jetonomy/.

Happy to answer developer questions in comments.

#WordPress #WPDev #REST #WordPressPlugin #PHP

---

## Post 4 — Education Use Case

If you run an online course or learning platform on WordPress, here's a use case for Jetonomy worth thinking about.

Most LMS setups handle the learning path well — courses, lessons, quizzes, completion tracking. What they often don't handle well is the discussion layer. The place where students ask questions, instructors answer, peers help peers.

A lot of platforms bolt on a Facebook group or a Slack workspace. Both work, but both have the same problem: the conversation happens off your platform. You don't own it. It's not indexed on your domain. When students search for answers, they're searching in a separate tool rather than the place they're already learning.

Jetonomy's Q&A spaces fit neatly into an LMS setup:

Create one Q&A space per course or module. Students ask questions; instructors or TAs mark the best answers as accepted. The accepted answer rises to the top of every thread — visible immediately to the next student with the same question. Over a term, a course builds up a searchable Q&A archive that reduces repetitive questions and lightens instructor load.

The trust level system is useful here too. New students start at Level 0 — rate-limited, can't post links. As they engage with the course and get helpful answers voted up, they earn higher trust levels and unlock more capabilities. It mirrors the natural progression of a student becoming a peer mentor.

Jetonomy Pro adds the LearnDash adapter — gate specific spaces to specific course enrollments.

The free plugin includes MemberPress and Paid Memberships Pro adapters.

If you're running courses on WordPress and the community discussion piece hasn't quite worked, this is worth a look.

Free plugin: https://wbcomdesigns.com/downloads/jetonomy/
Pro (LearnDash + LMS integrations): https://wbcomdesigns.com/downloads/jetonomy-pro/

#WordPress #LMS #OnlineLearning #LearnDash #EdTech

---

## Post 5 — Feature Comparison (Jetonomy vs. Alternatives)

People building community features on WordPress have had roughly the same four options for a decade. Here's an honest comparison, as someone who has worked with all of them.

**bbPress**
The oldest and most widely deployed WordPress forum plugin. Stores topics as a custom post type and replies as sub-posts in wp_posts. Works reliably for small communities. Performance degrades with scale because the data model wasn't designed for forum query patterns. The project is maintained but not actively developed — the last major feature addition was years ago. If you have an existing bbPress community and it's working fine, there's no urgent reason to move.

**wpForo**
More features than bbPress, more actively developed, more modern-looking UI. Still uses the WordPress database architecture (custom tables for forum data, but still closely coupled to WP conventions). Design is more opinionated and harder to customize to match a specific theme. Good choice if you want a feature-rich solution and the default design works for your context.

**BuddyPress**
Not really a forum plugin — it's a social network layer with activity streams, friend connections, groups, and optional discussion components. Powerful and flexible, but it's a bigger commitment than a forum plugin. Builds a parallel user layer on top of WordPress. If you want a full social community (profiles, connections, activity feed, groups, messaging), BuddyPress plus BuddyPress extensions is a reasonable path.

**Jetonomy (what we built)**
Custom MySQL tables designed specifically for forum query patterns. Trust level system that auto-moderates new users. Three space types (Forum, Q&A, Ideas). WordPress Interactivity API frontend. CSS that inherits from theme.json. 48+ REST API endpoints with cursor pagination.

Where we're weaker: we're brand new. bbPress and wpForo have years of community support, tutorials, and hosting-provider documentation. We have 1.0 and a commitment to maintain it seriously.

Where we're better: performance at scale, theme integration, Q&A and Ideas space types, trust levels, REST API design.

If you're starting fresh, or if you've been frustrated by the options above, it's worth trying Jetonomy. The free plugin is complete — not a trial.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #CommunityManagement #ForumPlugin #bbPress #WebDevelopment

---

## POST-LAUNCH / v1.3.0 REFRESH — April 2026

Two LinkedIn posts for the v1.3 AI release. Professional tone, technical depth, aimed at developers and community operations leads.

---

### Post 6 — AI Moderation on Your Own Server (v1.3.0 Launch)

Every "AI moderation" feature I've seen for community software sends member content to OpenAI. For regulated industries — health, legal, financial, enterprise internal — that is a non-starter.

Jetonomy 1.3 ships with a pluggable AI adapter that supports four providers, and the one I care about most is **self-hosted Ollama**.

Here is what it looks like in practice:

1. You install Ollama on the same server as WordPress.
2. You pull a model (llama3.1:8b is a good default — ~5GB).
3. In **Jetonomy → Settings → AI Integration**, you enter `http://localhost:11434` as the base URL.
4. You turn on AI spam detection, content moderation, reply suggestions, or thread summaries — each can use a different provider if you want.

Every new post and reply is scored by the model before publish. Low-confidence results go to the moderation queue with a reason the model generated. High-confidence clean posts are published as normal. High-confidence spam is rejected.

No content leaves your server. No API keys. No per-request bill. Every decision is logged to `wp_jt_pro_ai_log` for compliance review.

We ship OpenAI, Anthropic, and a Custom adapter as well — use them if you want quality over privacy. But Ollama is why I think this release matters for the WordPress community.

Two weeks after launch. Jetonomy 1.3.0.

https://wbcomdesigns.com/downloads/jetonomy-pro/

#WordPress #AI #Ollama #Privacy #CommunityManagement

---

### Post 7 — Post-Launch Velocity Note

14 days after shipping Jetonomy 1.0, we have three point releases out:

- **1.1** — theme compatibility polish across 12 themes, design token bridge
- **1.2** — Private Topics, Topic Prefixes, Similar Topics detection, Quote Replies (all free)
- **1.3** — AI adapter layer, Ollama support, AI spam detection, content moderation, reply suggestions, thread summaries (Pro)

Plus two supporting things nobody saw:
- GitHub Actions CI pipeline (PHP lint, WPCS, PHPStan level 5, Plugin Check)
- Nine Basecamp bug fixes from early users

One of the concerns I heard in the week after launch was: "Will you keep shipping, or is this another plugin that peaks at 1.0?" I understand why people ask — the WordPress plugin graveyard is deep. The answer is: we ship, we have CI that blocks regressions, and we have an open Basecamp board that tracks every issue.

If you were waiting to see whether Jetonomy would prove it was a serious project before recommending it — this is the moment.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #OpenSource #BuildInPublic #ForumPlugin
