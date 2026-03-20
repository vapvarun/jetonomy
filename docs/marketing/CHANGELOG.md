# Jetonomy Changelog

---

## 1.0.0 — March 2026

### Initial Release

Welcome to Jetonomy. This is the first public release — a complete community platform for WordPress built from the ground up.

---

### Community Engine

- **Spaces** — Create Forum, Q&A, and Ideas spaces, each with its own behavior. Forums are for open discussion. Q&A spaces let authors mark accepted answers. Ideas spaces let members vote on requests and track status from Open to Done.
- **Categories** — Group related spaces together. Drag to reorder. Each category shows an activity bar so members can see where the conversation is happening.
- **Sub-spaces** — Organize large communities with up to three tiers: Category, Space, and Sub-Space.
- **Space visibility** — Set each space to Public (anyone can read), Private (members only), or Hidden (invite only).
- **Join policies** — Open join, approval-required join, or invite-only. Moderators handle the queue.
- **Per-space moderators** — Assign moderators to individual spaces without giving them site-wide admin access.

---

### Content and Engagement

- **Rich posts** — Create posts with a full rich-text editor, tags, and optional attachments via the WordPress media library.
- **Threaded replies** — Replies support up to three levels of threading with collapsible branches. Long threads use smart loading — first and last replies shown by default, middle available on demand.
- **Voting** — Upvote and downvote posts and replies. Votes are toggleable and the score is always current. Visual feedback on every interaction.
- **Accepted answers** — In Q&A spaces, the post author can mark one reply as the accepted answer. It's highlighted at the top and earns the replier a reputation bonus.
- **Ideas with status tracking** — Ideas spaces include a roadmap view. Admins move ideas through status stages. Members see what's planned, in progress, and shipped.
- **Tags** — Tag posts to make them easy to find. Tag pages collect all related discussions in one place.
- **Real-time new reply banner** — When new replies arrive while someone is reading a thread, a sticky banner appears at the bottom. No page reload needed.
- **Sort options** — Sort replies by Oldest, Newest, or Best (top voted). The sort preference is remembered.

---

### Trust and Reputation

- **Six trust levels** — Newcomer, Member, Regular, Trusted, Leader, and Moderator. Levels 0–3 are earned automatically based on activity. Levels 4–5 are granted by admins.
- **Reputation points** — Members earn points for upvotes (+10 per post, +5 per reply), accepted answers (+15), and lose points for downvotes (−2) and removed content (−20).
- **Automatic behavior gates** — New members (Level 0) are limited to 3 posts per day and can't post links. This cuts spam without any configuration. As members contribute, restrictions lift automatically.
- **Trust badges** — Every avatar in the community shows the member's trust level at a glance.

---

### Search and Discovery

- **Full-text search** — Search across posts, spaces, and tags. Results are filtered by tab (All, Posts, Spaces, Tags) with result counts and content excerpts.
- **Leaderboard** — See top contributors by posts, replies, and reputation. A quick way to find the most helpful community members.
- **User profiles** — Each member has a profile page showing their posts, reputation, trust level, and activity.

---

### Moderation and Safety

- **Moderation queue** — Pending posts, pending replies, flagged content, and banned users in a single admin view. Moderators can approve, mark as spam, or trash in one click.
- **Flagging** — Members can flag any post or reply with a reason (spam, offensive, off-topic, harassment, or other). Flagged content goes to the moderation queue.
- **Bans and silencing** — Ban users globally or per-space, with an optional expiry date. Silence a user so they can read but not post.
- **Auto-spam reputation** — Marking content as spam deducts 20 reputation from the author automatically.
- **Revision history** — Edits to posts and replies create a revision. Moderators can review what changed.

---

### Permissions

- **Three-layer permission system** — WordPress roles provide the baseline. Space roles (viewer, member, moderator, admin) add per-space granularity. Trust levels and access rules handle the fine details.
- **Membership plugin integration** — Gate spaces by MemberPress or Paid Memberships Pro membership level. Access rules automatically adjust when a membership activates, upgrades, or expires.
- **20 capabilities** — Fine-grained WordPress capabilities for every meaningful action, all compatible with role management plugins.

---

### Notifications

- **In-community notifications** — Bell icon with unread count. Notifications for replies to your posts, @mentions, accepted answers, vote milestones, trust level promotions, and moderator actions.
- **Email notifications** — Optional email for each notification type. Users control their own preferences.
- **Subscriptions** — Subscribe to any space or post to follow it. Unsubscribe with one click.

---

### SEO

- **Schema.org markup** — Every page gets the right structured data: DiscussionForumPosting for forum threads, QAPage with acceptedAnswer for Q&A, BreadcrumbList on every view.
- **XML sitemaps** — Spaces and posts are automatically included in WordPress core sitemaps.
- **Open Graph and Twitter cards** — Every community page generates proper social preview tags.
- **Clean URLs** — Human-readable URLs like /community/s/support/t/how-do-i-do-x/ throughout.
- **Server-side rendered HTML** — All community pages are fully rendered on the server. Search engines see complete content, not a loading spinner.

---

### Import

- **bbPress importer** — Migrate forums, topics, replies, and users from bbPress in a few clicks. Jetonomy auto-detects your bbPress installation and shows what it found before you commit.
- **wpForo importer** — Same one-click migration experience for wpForo communities.
- **Dry run mode** — Preview what the import will do before it runs. See exactly how many spaces, posts, and users will be created.
- **Progress tracking** — Large imports run with a live progress indicator. If something goes wrong, you can resume from where it stopped.

---

### For Developers and Site Builders

- **35+ REST API endpoints** — Complete REST API under the jetonomy/v1 namespace. Every feature is accessible programmatically with cursor-based pagination and JSON schema validation.
- **Template overrides** — Copy any template into your-theme/jetonomy/ and customize it. Theme updates don't overwrite your changes.
- **Action and filter hooks** — Hooks throughout the plugin for extending behavior without modifying core files.
- **WordPress Abilities API** — Jetonomy registers 18 abilities in 5 categories so that AI agents and automation tools can discover and operate the community without custom integration work. Requires WordPress 6.9+.
- **Adapter architecture** — Search, email, real-time updates, and membership integrations all use a clean adapter interface. Swap components without touching the core.
- **Clean uninstall** — Removing Jetonomy via the WordPress admin offers a complete data cleanup — all tables, options, capabilities, and scheduled jobs removed.

---

### Performance

- **Custom MySQL tables** — 21 dedicated tables with proper indexes for actual query patterns. No wp_postmeta. No global table locks on busy communities.
- **Denormalized counters** — Reply counts, post counts, and vote scores are stored directly on each record. No COUNT queries on page load.
- **Object cache support** — Jetonomy caches space data, user profiles, and permission results. Works with Redis and Memcached automatically when available.
- **Cursor-based pagination** — List endpoints use cursor pagination instead of offset. Consistent results even when new content is posted between pages.
- **Scales to 10,000+ users** — Tested and documented scale path from shared hosting to dedicated infrastructure. No architectural changes needed as you grow.
