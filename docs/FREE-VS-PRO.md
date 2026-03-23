# Jetonomy — Free vs Pro Comparison

> Quick reference for what ships in the free WordPress.org plugin vs what requires a Pro license.

---

## Feature Comparison Table

| Feature Area | Free Core | Pro |
|---|---|---|
| **Community Types** | Forum, Q&A, Ideas | Social Feed (v2.0) |
| **Spaces & Categories** | Unlimited spaces, sub-spaces, categories, drag-sort | — |
| **Posts & Replies** | Rich editor, threaded replies (3 levels), smart loading | — |
| **Voting** | Upvote/downvote on posts & replies, toggle, animations | — |
| **Trust Levels** | 6 tiers (0-5), auto-earned 0-3, manual 4-5 | — |
| **Reputation** | Full point system (+10 post upvote, +15 accepted answer, etc.) | — |
| **Badges** | 5 default badges, display on profiles | Custom badge builder (criteria engine, auto-evaluation) |
| **Permissions** | 3-layer: WP Caps + Space Roles + Trust Levels | — |
| **Access Rules** | Role-based, capability-based, trust-level gates | — |
| **User Profiles** | Full profiles, activity history, stats | — |
| **Leaderboard** | Community leaderboard | — |
| **Search** | MySQL FULLTEXT (Boolean mode) | Meilisearch, Elasticsearch, Algolia adapters |
| **Real-time** | Polling (configurable interval) | Mercure, Pusher, Ably adapters + typing indicators + online presence |
| **Moderation** | Flag/report, mod queue (4 tabs), approve/spam/trash, ban/silence | Auto-moderation rules engine (keyword, regex, link limit, spam score) |
| **Notifications** | Web notifications (bell icon, unread badge) | — |
| **Email** | wp_mail adapter (immediate per-event) | Email Digest (daily/weekly) + ESP adapters (SendGrid, Mailgun, SES, Postmark) |
| **SEO** | Schema.org JSON-LD, XML Sitemaps, OG tags, Twitter Cards, canonical URLs | SEO Pro (per-space meta templates, sitemap controls, noindex/nofollow) |
| **Import** | bbPress, wpForo, Asgaros Forum | Discourse, phpBB, vBulletin, XenForo, Simple Machines |
| **Membership Adapters** | WP Roles, MemberPress, Paid Memberships Pro | WooCommerce Memberships, Restrict Content Pro, LearnDash |
| **Reactions** | — | 8 emoji reactions on posts & replies |
| **Polls** | — | Single/multi choice, close dates, live results |
| **Custom Fields** | — | 9 field types, 3 contexts (post/profile/space), validation engine |
| **Private Messaging** | — | 1:1 and group DMs, unread tracking, mute |
| **Analytics** | — | Dashboard (posts/day, active users, engagement rate), CSV export |
| **White Label** | — | Custom branding, logo, footer, accent color, CSS injection |
| **REST API** | 35+ endpoints, cursor-based pagination, rate limiting | Bulk operations endpoint |
| **CSS & Theming** | CSS Layers + Custom Properties, 3 color schemes, theme.json adaptive | Dark mode, theme builder |
| **Templates** | Theme-overridable via `theme/jetonomy/` | — |
| **Extensibility** | Full hook system (actions + filters), Extension API | — |
| **Admin Dashboard** | Spaces, content, users, settings, import | Extensions manager |

---

## Membership Adapter Matrix

| Adapter | Tier |
|---|---|
| WP Roles & Capabilities | Free |
| Trust Level Gates | Free |
| MemberPress | Free |
| Paid Memberships Pro | Free |
| Custom (hook-based) | Free |
| WooCommerce Memberships | Pro |
| Restrict Content Pro | Pro |
| LearnDash | Pro |

---

## Pro Modules Summary

| # | Module | Key Value |
|---|---|---|
| 1 | SEO Pro | Per-space meta templates, sitemap controls, noindex |
| 2 | White Label | Custom logo, branding, accent color, CSS injection |
| 3 | Reactions | 8 emoji reactions on posts & replies |
| 4 | Polls | Polls attached to posts, single/multi choice, auto-close |
| 5 | Email Digest | Daily/weekly digest emails, per-user preferences |
| 6 | Analytics | Community metrics dashboard, top contributors, CSV export |
| 7 | Custom Badges | Badge builder with criteria engine, auto-evaluation |
| 8 | Advanced Moderation | Auto-mod rules (keyword, regex, link limit, spam score) |
| 9 | Custom Fields | 9 field types across posts, profiles, and spaces |
| 10 | Private Messaging | 1:1 and group conversations, unread tracking |

---

## Pro Licensing Tiers

| Tier | Price | Sites | Modules |
|---|---|---|---|
| Starter | $99/yr | 1 site | Core Pro extensions |
| Growth | $199/yr | 5 sites | All Pro extensions |
| Agency | $399/yr | Unlimited | All Pro + white-label + priority support |
| Lifetime | $599 | Unlimited | All Pro, lifetime updates |

**License server:** wbcomdesigns.com (EDD Software Licensing)

---

## What's NOT in v1.0 (Planned for v2.0)

- Social Feed community type
- Real-time push via WebSockets/Mercure
- Mobile PWA
- Multisite support
- Slack/Discord bridge
- Third-party extension marketplace
- AI-powered features (hooks ready)
- Video/audio in posts
- Threaded replies beyond 3 levels
