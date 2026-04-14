# Jetonomy vs bbPress vs wpForo — Feature Comparison

**Version:** 1.3.0
**Last updated:** April 2026

---

## Notes on methodology

This comparison reflects the state of each plugin as of March 2026. bbPress is compared against its current stable release (2.6.x) plus officially supported add-ons. wpForo is compared against wpForo 2.x with its bundled add-ons. Where a feature requires a paid add-on to enable, it is noted.

This comparison is honest. Where competitors do something well, that is noted. The goal is to help you make an informed decision, not to make competitors look bad.

---

## Architecture and Performance

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Data storage | 24 custom MySQL tables | WordPress CPTs (wp_posts + wp_postmeta) | Custom tables |
| Avoids wp_postmeta bloat | Yes | No — heavy wp_postmeta use | Yes |
| Denormalized counters (no COUNT on load) | Yes | No | Partial |
| Object cache support (Redis/Memcached) | Yes | Partial (WP object cache) | Partial |
| Cursor-based pagination | Yes | No — offset only | No — offset only |
| Tested at 50K+ topics | Yes — sub-200ms with Redis | No documented scale testing | Limited documentation |
| Server-side rendered HTML | Yes — WP Interactivity API | Yes — classic PHP templates | Yes |
| PHP version requirement | 8.1+ | 7.2+ | 7.4+ |
| WordPress version requirement | 6.7+ | 5.0+ | 5.5+ |

**Honest note:** bbPress has the lightest footprint and works on the widest range of hosting. If you have a small community on older infrastructure, bbPress's lower requirements may matter.

---

## Community Structure

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Forum spaces | Yes | Yes (Forums) | Yes |
| Q&A spaces with accepted answers | Yes | No (add-on required) | Yes |
| Ideas / roadmap spaces | Yes | No | No |
| Categories for grouping spaces | Yes | Yes | Yes |
| Sub-spaces (nested) | Yes — 3 tiers | Yes | Yes |
| Space visibility (public/private/hidden) | Yes | Partial | Yes |
| Per-space join policies (open/approval/invite) | Yes | No | Partial |
| Space-specific moderators | Yes | No — moderators are site-wide | Partial |

---

## Content and Engagement

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Rich text editor (posts) | Yes | Limited | Yes |
| Threaded replies | Yes — 3 levels, collapsible | No — flat replies | Yes — configurable depth |
| Smart loading for long threads | Yes | No | No |
| Voting (posts and replies) | Yes | Partial (add-on) | Yes |
| Accepted answers | Yes | Add-on required | Yes |
| Ideas with status tracking | Yes | No | No |
| Real-time new reply banner | Yes | No | No |
| Sort replies (oldest/newest/best) | Yes | No | Yes |
| Tags on posts | Yes | No | Yes |
| Post attachments (media library) | Yes | No (add-on) | Yes |
| Emoji reactions | Jetonomy Pro | No | Add-on required |
| Polls | Jetonomy Pro | No | Add-on required |
| Private messaging | Jetonomy Pro | No (add-on) | Add-on required |

---

## Trust, Reputation, and Gamification

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Reputation points system | Yes | No | Yes |
| Trust levels (automated) | Yes — 6 levels | No | No |
| Automatic behavior gates (rate limits, link blocks) | Yes — no configuration needed | No | No |
| Trust badges on avatars | Yes | No | Partial |
| Custom badges with criteria engine | Jetonomy Pro | No | No |
| Leaderboard | Yes | No | Yes |
| User profiles with activity | Yes | Yes | Yes |

**Honest note:** wpForo has a solid reputation system that many communities have relied on for years. Jetonomy's advantage is the automated behavior gates — new account rate-limiting works from day one without any setup.

---

## Moderation and Safety

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Moderation queue | Yes — single view for all content | Partial | Yes |
| Member flagging with reasons | Yes | No | Yes |
| Ban users (global) | Yes | Yes | Yes |
| Ban users (per-space) | Yes | No | No |
| Silencing (read-only) | Yes | No | No |
| IP banning | Yes | No | Yes |
| Automatic spam reputation penalty | Yes | No | No |
| Revision history for edits | Yes | No | Yes |
| Auto-moderation rules | Jetonomy Pro | No | Add-on required |
| AI spam detection + content moderation | Jetonomy Pro (self-hosted Ollama or OpenAI/Anthropic) | No | No |
| AI reply suggestions and thread summaries | Jetonomy Pro | No | No |

---

## Permissions and Access Control

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Three-layer permission system | Yes (WP Caps + Space Roles + Trust Levels) | No — WordPress roles only | Partial (WP roles + usergroup roles) |
| Per-space roles | Yes | No | Yes (usergroups) |
| 20+ fine-grained capabilities | Yes | Partial | Yes |
| MemberPress integration | Yes (free) | Add-on required | Add-on required |
| Paid Memberships Pro integration | Yes (free) | Add-on required | Add-on required |
| WooCommerce membership gating | Jetonomy Pro | Add-on required | Add-on required |
| LearnDash enrollment gating | Jetonomy Pro | No | No |
| Role management plugin compatible | Yes | Yes | Yes |

---

## Search and Discovery

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Full-text search | Yes — MySQL FULLTEXT | Basic WordPress search | Yes |
| Search results by type with counts | Yes | No | Yes |
| Swappable search adapter (Meilisearch, etc.) | Yes | No | No |
| Tag pages | Yes | No | Yes |
| Tag filtering | Yes | No | Yes |

---

## SEO

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Schema.org markup (DiscussionForumPosting) | Yes | No | Partial |
| Schema.org QAPage with acceptedAnswer | Yes | No | No |
| BreadcrumbList structured data | Yes | No | Partial |
| Open Graph tags | Yes | No | Yes |
| Twitter card tags | Yes | No | Yes |
| XML sitemap inclusion | Yes — WP core sitemaps | Yes | Yes |
| Clean human-readable URLs | Yes | Yes | Yes |
| Per-space SEO controls (noindex, custom meta) | Jetonomy Pro | No | Partial |

---

## Developer Tools

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| REST API | Yes — 48+ endpoints (90+ with Pro) | No | Partial |
| Cursor-based pagination on API | Yes | No | No |
| JSON schema validation on API | Yes | No | No |
| Template override system | Yes — theme/jetonomy/ | Yes — theme/bbpress/ | Partial |
| Action and filter hooks | Yes — throughout | Yes — throughout | Yes |
| Adapter pattern for integrations | Yes (search, email, realtime, membership) | No | No |
| WordPress Abilities API support | Yes — 19 abilities | No | No |
| Clean uninstall (removes all data) | Yes | Partial | Yes |
| Composer autoloader | No | No | No |

**Honest note:** bbPress has an extensive ecosystem of hooks built up over 15+ years. If you need a specific hook that bbPress exposes, there is a good chance it already exists. Jetonomy's hook library is complete but newer.

---

## Import and Migration

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Import from bbPress | Yes — built in | — | No |
| Import from wpForo | Yes — built in | No | — |
| Dry run before importing | Yes | No | No |
| Progress tracking during import | Yes | No | No |
| Resume on failure | Yes | No | No |

---

## Notifications

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| In-community notification bell | Yes | No | Yes |
| Email notifications | Yes | Partial (add-on) | Yes |
| Notification preferences per user | Yes | No | Yes |
| Space and post subscriptions | Yes | Partial | Yes |
| Email digest (daily/weekly) | Jetonomy Pro | No | No |
| Reply by email | Jetonomy Pro | No | No |
| Web push notifications | Jetonomy Pro | No | No |

---

## Analytics

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Built-in analytics dashboard | Jetonomy Pro | No | Yes (partial) |
| Space-level activity data | Jetonomy Pro | No | Partial |
| Contributor reporting | Jetonomy Pro | No | Partial |
| Webhooks for external analytics | Jetonomy Pro | No | No |

**Honest note:** wpForo's built-in statistics are available on the free plugin and cover basic activity metrics. Jetonomy's analytics are Pro-only but more detailed.

---

## Pricing

| | Jetonomy | bbPress | wpForo |
|---|---|---|---|
| Free version | Yes — full-featured | Yes — full plugin | Yes — core features |
| Free on WordPress.org | No (wbcomdesigns.com) | Yes | Yes |
| Pro pricing | See wbcomdesigns.com | Add-ons sold separately | Paid plans available |
| Lifetime license available | Yes (Pro) | N/A | No |

**Honest note:** bbPress itself is free and the add-on ecosystem is broad, but individual add-ons from third parties cost money and add maintenance overhead. wpForo's paid plans bundle add-ons at a set price. Jetonomy Pro bundles all 14 modules (including AI Integration) in a single license.

---

## Summary

**Choose Jetonomy if:**
- Performance at scale matters — custom tables and denormalized counters make a measurable difference above a few thousand topics
- You want automated spam control that requires no configuration
- You need Q&A and Ideas spaces alongside standard forums
- Your developers want a clean REST API and adapter pattern
- You're migrating from bbPress or wpForo and want a built-in path

**Choose bbPress if:**
- You need maximum compatibility with older WordPress versions and hosting environments
- You're already deeply invested in the bbPress add-on ecosystem
- You need minimal overhead — bbPress has the smallest footprint of the three
- Your community is small and performance is not a concern

**Choose wpForo if:**
- You want a mature, battle-tested plugin with years of production use
- You need built-in statistics on the free plan
- You have an existing wpForo community and no reason to migrate
- You prefer wpForo's visual style and admin interface
