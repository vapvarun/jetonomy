# Jetonomy Changelog

---

## 1.4.2 - May 2026

A scale and multisite release. Three new content types finish off, multisite networks now get tables on every subsite, cleanup crons no longer time out on large communities, and a long sweep of accessibility, translation, and admin-flow fixes. On the Pro side, webhooks fire again and 13 silent contract bugs are closed.

### New content types (Free)

- **Show & Tell spaces** - short-form feed with optional title and inline content cards. Built for product showcases, weekly wins, and image-led discussion.
- **Ideas with a real roadmap** - Ideas spaces ship a roadmap view with status lanes: planned, in progress, shipped, declined. Members see exactly what is coming and what shipped.
- **Q&A "Answered" badge** - Q&A space owners can pin the accepted answer, and the space list now shows an "Answered" badge so members know which threads are resolved at a glance.

### Performance and scale (Free)

- Cleanup cron handlers (trust evaluation, expired restrictions, old notifications, scheduled posts) now process at most 500 rows per run. Filterable via `jetonomy_cron_batch_size`. Sites with large activity logs no longer time out during cleanup.

### Multisite (Free + Pro)

- Network activation now creates the required tables on every existing subsite, and on every new subsite created later. Previously only the current blog got tables. Pro extensions install on every subsite the same way.

### Reliability (Pro)

- **Webhooks fire again.** 8 webhook listeners updated to match the renamed core Jetonomy hooks. Zapier, Make, and n8n integrations resume traffic immediately on update. No customer action required.
- **13 silent contract bugs closed.** White Label filters, Custom Fields filters, and webhook lifecycle hooks now fire end-to-end where free Jetonomy expects them.
- Defensive guards added to Pro admin save scripts so a partial save cannot leave the form stuck.

### Translations and accessibility (Free + Pro)

- Composer, login block, IA state, banned-member notice, header escape hint, prefix builder, and admin import flow are fully translatable. Private messaging composer translatable on the Pro side.
- Visible keyboard focus indicators everywhere; aria-labels added to filter, bulk-action, and select-all controls. Pattern inputs in Advanced Moderation properly labelled for screen readers.
- Native browser confirm dialogs swapped for in-product modals across both admin surfaces.

### Fixes (Free)

- Posts/replies-per-page setting now controls the actual list length AND the Load More click count.
- Vote controls hide (not just disable) when an admin disables voting on a space.
- Idea status changes notify the right people across the activity log, email digest, and in-app inbox.
- Import progress AJAX requires the right capability, not just a nonce.
- Setup wizard redirect skips under WP-CLI and REST contexts so automation does not get bounced to the wizard.
- Settings save confirmations no longer disappear before the user can see them.

---

## 1.3.0 - April 2026

### AI Integration (Pro)

The biggest addition since launch. Jetonomy Pro now ships with a full AI layer that reads every new post and reply for spam, abuse, and rule violations before it is published - and does it on whichever provider you prefer.

- **Four providers supported** - OpenAI, Anthropic, any OpenAI-compatible endpoint, and **self-hosted Ollama** running on your own server.
- **AI-powered spam detection** - catches the spam that pattern matching misses: subtly rewritten affiliate spam, context-aware abuse, posting patterns designed to pad history on clean accounts.
- **Content moderation from plain-English rules** - describe your rules in a few sentences and the model reads every post against them. Violations go to the moderation queue with an explanation the model generated.
- **Reply suggestions** - on knowledge-base communities, the model can draft a reply the member can accept, edit, or ignore. Nothing is ever sent without human approval.
- **Thread summaries** - long topics (30+ replies) get an auto-generated summary pinned at the top. Cached so each summary is generated once per content state.
- **Usage and cost tracking** - dashboard card showing requests, tokens, spend, and error rates by provider and feature.
- **Privacy-first** - with the Ollama provider, no content leaves your server. No external network calls. No API keys. Every decision logged for compliance review.

### Free plugin additions

- **AI adapter layer in the free plugin** - the pluggable adapter system for AI providers ships in the free plugin so third-party extensions can register their own providers.
- **Pattern-based AI spam detection in free** - a lightweight free spam detector uses Ollama if available.
- **GitHub Actions CI pipeline** - every pull request runs PHP lint, WPCS, PHPStan level 5, and WordPress Plugin Check.

### Reliability and quality

- WP_Error checks at all model caller sites - hooks that return WP_Error no longer cascade into fatals.
- Vote operations wrapped in DB transactions.
- Spaces N+1 query eliminated - visibility filter moved to a SQL JOIN.
- InnoDB engine enforced on all custom tables.
- Daily activity log pruning with safe batch loop.
- PHPStan level 5 with zero errors. Plugin Check compliance. All output properly escaped.

### Fixes

- Double reply counter increment on new reply.
- Space settings cache invalidation.
- Permission callback consolidation.
- PHP 8.1 compatibility - bool return type on internal method.
- Nine Basecamp bug fixes - fatal BP compat, notifications, report UI, pagination, and more.

---

## 1.2.0 - April 2026

### Discussion controls (free)

Four features that give members and space owners finer control over how topics are created and read.

- **Private Topics** - mark a topic as private so only the author and space moderators can see it. Other members cannot find or open it.
- **Topic Prefixes** - space owners define colored labels like Bug, Question, Solved, or Announcement. Members pick a prefix when creating a topic and it shows as a colored tag in the space listing.
- **Similar Topics detection** - as members type a new topic title, Jetonomy searches the space for similar existing titles and shows up to five matches inline.
- **Quote Replies** - select any passage in a reply and click Quote to insert a styled blockquote into your reply composer with attribution back to the source.

### Improvements

- Before-delete hooks on all models for extension authors.
- Query args filters on all model list methods.
- Base slug 301 redirect for SEO - changing your community base slug no longer breaks existing links.
- Eliminated leftover patch code in BP helpers and Space::get_posts_per_page().

---

## 1.0.0 - March 2026

### Initial Release

Welcome to Jetonomy. This is the first public release - a complete community platform for WordPress built from the ground up.

---

### Community Engine

- **Spaces** - Create Forum, Q&A, and Ideas spaces, each with its own behavior. Forums are for open discussion. Q&A spaces let authors mark accepted answers. Ideas spaces let members vote on requests and track status from Open to Done.
- **Categories** - Group related spaces together. Drag to reorder. Each category shows an activity bar so members can see where the conversation is happening.
- **Sub-spaces** - Organize large communities with up to three tiers: Category, Space, and Sub-Space.
- **Space visibility** - Set each space to Public (anyone can read), Private (members only), or Hidden (invite only).
- **Join policies** - Open join, approval-required join, or invite-only. Moderators handle the queue.
- **Per-space moderators** - Assign moderators to individual spaces without giving them site-wide admin access.

---

### Content and Engagement

- **Rich posts** - Create posts with a full rich-text editor, tags, and optional attachments via the WordPress media library.
- **Threaded replies** - Replies support up to three levels of threading with collapsible branches. Long threads use smart loading - first and last replies shown by default, middle available on demand.
- **Voting** - Upvote and downvote posts and replies. Votes are toggleable and the score is always current. Visual feedback on every interaction.
- **Accepted answers** - In Q&A spaces, the post author can mark one reply as the accepted answer. It's highlighted at the top and earns the replier a reputation bonus.
- **Ideas with status tracking** - Ideas spaces include a roadmap view. Admins move ideas through status stages. Members see what's planned, in progress, and shipped.
- **Tags** - Tag posts to make them easy to find. Tag pages collect all related discussions in one place.
- **Real-time new reply banner** - When new replies arrive while someone is reading a thread, a sticky banner appears at the bottom. No page reload needed.
- **Sort options** - Sort replies by Oldest, Newest, or Best (top voted). The sort preference is remembered.

---

### Trust and Reputation

- **Six trust levels** - Newcomer, Member, Regular, Trusted, Leader, and Moderator. Levels 0–3 are earned automatically based on activity. Levels 4–5 are granted by admins.
- **Reputation points** - Members earn points for upvotes (+10 per post, +5 per reply), accepted answers (+15), and lose points for downvotes (−2) and removed content (−20).
- **Automatic behavior gates** - New members (Level 0) are limited to 3 posts per day and can't post links. This cuts spam without any configuration. As members contribute, restrictions lift automatically.
- **Trust badges** - Every avatar in the community shows the member's trust level at a glance.

---

### Search and Discovery

- **Full-text search** - Search across posts, spaces, and tags. Results are filtered by tab (All, Posts, Spaces, Tags) with result counts and content excerpts.
- **Leaderboard** - See top contributors by posts, replies, and reputation. A quick way to find the most helpful community members.
- **User profiles** - Each member has a profile page showing their posts, reputation, trust level, and activity.

---

### Moderation and Safety

- **Moderation queue** - Pending posts, pending replies, flagged content, and banned users in a single admin view. Moderators can approve, mark as spam, or trash in one click.
- **Flagging** - Members can flag any post or reply with a reason (spam, offensive, off-topic, harassment, or other). Flagged content goes to the moderation queue.
- **Bans and silencing** - Ban users globally or per-space, with an optional expiry date. Silence a user so they can read but not post.
- **Auto-spam reputation** - Marking content as spam deducts 20 reputation from the author automatically.
- **Revision history** - Edits to posts and replies create a revision. Moderators can review what changed.

---

### Permissions

- **Three-layer permission system** - WordPress roles provide the baseline. Space roles (viewer, member, moderator, admin) add per-space granularity. Trust levels and access rules handle the fine details.
- **Membership plugin integration** - Gate spaces by MemberPress or Paid Memberships Pro membership level. Access rules automatically adjust when a membership activates, upgrades, or expires.
- **20 capabilities** - Fine-grained WordPress capabilities for every meaningful action, all compatible with role management plugins.

---

### Notifications

- **In-community notifications** - Bell icon with unread count. Notifications for replies to your posts, @mentions, accepted answers, vote milestones, trust level promotions, and moderator actions.
- **Email notifications** - Optional email for each notification type. Users control their own preferences.
- **Subscriptions** - Subscribe to any space or post to follow it. Unsubscribe with one click.

---

### SEO

- **Schema.org markup** - Every page gets the right structured data: DiscussionForumPosting for forum threads, QAPage with acceptedAnswer for Q&A, BreadcrumbList on every view.
- **XML sitemaps** - Spaces and posts are automatically included in WordPress core sitemaps.
- **Open Graph and Twitter cards** - Every community page generates proper social preview tags.
- **Clean URLs** - Human-readable URLs like /community/s/support/t/how-do-i-do-x/ throughout.
- **Server-side rendered HTML** - All community pages are fully rendered on the server. Search engines see complete content, not a loading spinner.

---

### Import

- **bbPress importer** - Migrate forums, topics, replies, and users from bbPress in a few clicks. Jetonomy auto-detects your bbPress installation and shows what it found before you commit.
- **wpForo importer** - Same one-click migration experience for wpForo communities.
- **Dry run mode** - Preview what the import will do before it runs. See exactly how many spaces, posts, and users will be created.
- **Progress tracking** - Large imports run with a live progress indicator. If something goes wrong, you can resume from where it stopped.

---

### For Developers and Site Builders

- **48+ REST API endpoints** - Complete REST API under the jetonomy/v1 namespace. Every feature is accessible programmatically with cursor-based pagination and JSON schema validation.
- **Template overrides** - Copy any template into your-theme/jetonomy/ and customize it. Theme updates don't overwrite your changes.
- **Action and filter hooks** - Hooks throughout the plugin for extending behavior without modifying core files.
- **WordPress Abilities API** - Jetonomy registers 19 abilities in 5 categories so that AI agents and automation tools can discover and operate the community without custom integration work. Requires WordPress 6.9+.
- **Adapter architecture** - Search, email, real-time updates, and membership integrations all use a clean adapter interface. Swap components without touching the core.
- **Clean uninstall** - Removing Jetonomy via the WordPress admin offers a complete data cleanup - all tables, options, capabilities, and scheduled jobs removed.

---

### Performance

- **Custom MySQL tables** - 24 dedicated tables with proper indexes for actual query patterns. No wp_postmeta. No global table locks on busy communities.
- **Denormalized counters** - Reply counts, post counts, and vote scores are stored directly on each record. No COUNT queries on page load.
- **Object cache support** - Jetonomy caches space data, user profiles, and permission results. Works with Redis and Memcached automatically when available.
- **Cursor-based pagination** - List endpoints use cursor pagination instead of offset. Consistent results even when new content is posted between pages.
- **Scales to 10,000+ users** - Tested and documented scale path from shared hosting to dedicated infrastructure. No architectural changes needed as you grow.
