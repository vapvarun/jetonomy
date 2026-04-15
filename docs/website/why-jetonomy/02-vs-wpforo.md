How Jetonomy compares to wpForo — two modern forum plugins with different approaches.

![Jetonomy community home page showcasing the modern forum interface](../images/community-home.png)

## What You Will Learn

- Key architectural differences between Jetonomy and wpForo
- Feature comparison across both plugins
- Which plugin fits your use case

## Feature Comparison

| Feature | wpForo | Jetonomy |
|---------|--------|----------|
| Data storage | Custom tables | Custom tables |
| Forum layouts | 4 built-in layouts | 4 space types (forum, Q&A, ideas, feed) |
| Threaded replies | Flat + nested option | 3-level threading |
| Voting | Likes only | Upvote/downvote with reputation |
| Trust system | Manual user groups | Auto-promoting trust levels (0-5) |
| Q&A mode | Basic question/answer | Full Q&A with accepted answers |
| Idea boards | Not built-in | Built-in with status workflow |
| Real-time UI | Page reload | WordPress Interactivity API |
| Search | Built-in search | FULLTEXT with advanced filters |
| Anti-spam | reCAPTCHA v2 | reCAPTCHA v3 + Turnstile (invisible) |
| Topic management | Move topics | Move + merge + split |
| Draft posts | Not available | Save as draft + scheduling |
| REST API | Limited | 48+ endpoints (90+ with Pro) |
| Theme integration | Custom styling | CSS custom properties via theme.json |
| Membership gating | Built-in groups | Adapter system (MemberPress, PMPro, WooCommerce, LearnDash) |
| Analytics | Basic stats | Full dashboard with export (Pro) |
| Migration | N/A | Built-in wpForo importer |

## Where Jetonomy Stands Out

### WordPress-Native Architecture

wpForo was originally built as a standalone forum that happens to run inside WordPress. Jetonomy was built WordPress-first — it uses the Interactivity API, theme.json design tokens, wp_cache, WP-Cron, and the WordPress REST API as its foundation.

This means Jetonomy integrates more deeply with WordPress features like block themes, site editing, and the Abilities API.

### Trust Over Roles

wpForo uses manual user groups (similar to WordPress roles). You create groups, assign permissions, and manually move users between groups.

Jetonomy automates this entirely. New members start restricted and earn trust through participation. The community moderates itself as members advance through trust levels. You configure the thresholds once and the system handles promotions automatically.

### Invisible Anti-Spam

wpForo supports reCAPTCHA v2 — the "I'm not a robot" checkbox that interrupts every user. Jetonomy uses reCAPTCHA v3 and Cloudflare Turnstile, both invisible. Members never see a CAPTCHA, and trusted members (Trust Level 2+) are completely exempt.

### Adapter-Based Integrations

wpForo has built-in membership gating but requires wpForo-specific extensions for each membership plugin. Jetonomy uses a universal adapter pattern — MemberPress and PMPro work out of the box in free, and Pro adds WooCommerce, LearnDash, and Restrict Content Pro. Custom adapters can be built for any membership system.

## Where wpForo Works Well

wpForo is a mature product with a large installed base. It offers built-in user groups, a forum-specific SEO system, and multiple layout options. If you need a traditional forum with minimal configuration, wpForo is a solid choice.

If you are running wpForo and want to migrate, Jetonomy includes a built-in wpForo importer.

## What's Next?

- [Importing from wpForo](../migration/02-wpforo-import.md) — step-by-step migration guide
- [Scalability](03-scalability.md) — how Jetonomy handles growth
