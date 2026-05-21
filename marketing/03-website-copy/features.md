# Jetonomy - Feature Highlights

**Version:** 1.4.4
**Last updated:** May 2026

---

## CORE - Spaces, Topics, Replies, Voting

### Spaces

**What it does:** Spaces are the containers for your community. Five types are available: Forum for open discussion, Q&A where the best answer gets marked and surfaced, Ideas where members vote on what gets built next, Show & Tell for short-form content cards with an optional title and inline media, and Social Feed for short chronological posts with reactions and quick community updates. Each space can have its own visibility (public, private, or hidden), join policy (open, approval-required, or invite-only), and per-space moderators.

**Why it matters:** You can run one community with completely different rules for different parts. A public support forum, a private staff area, a customer ideas board, and a company news feed can all live on the same site under the same /community/ path - each with its own access controls.

---

### Categories and Sub-Spaces

**What it does:** Group related spaces into categories. Categories display an activity bar so members can see where conversations are happening. Spaces support up to three tiers: Category, Space, and Sub-Space.

**Why it matters:** As your community grows, structure helps members find the right place to post. Categories also let you manage whole sections of the community from a single admin view.

---

### Rich Posts and Threaded Replies

**What it does:** Posts support a full rich-text editor with tags and optional media library attachments. Replies support up to three levels of threading with collapsible branches. Long threads use smart loading: first and last replies load by default, middle replies load on demand.

**Why it matters:** Long threads stay readable. Members can follow a specific branch of conversation without scrolling through hundreds of replies. The smart loading means a thread with 400 replies is not a wall of text.

---

### Voting and Reputation

**What it does:** Members upvote and downvote posts and replies. Votes are toggleable and the current score is always accurate. Members earn reputation points: +10 per upvoted post, +5 per upvoted reply, +15 per accepted answer, −2 per downvote received, −20 when content is removed as spam.

**Why it matters:** Good answers surface. Helpful members are recognized. Spam loses reputation automatically, making the ban decision easier for moderators.

---

### Accepted Answers (Q&A Spaces)

**What it does:** In Q&A spaces, the post author can mark one reply as the accepted answer. It's highlighted at the top of the thread and earns the replier a +15 reputation bonus.

**Why it matters:** Members searching for answers find them instantly. The accepted answer is the first thing they see. Repeat questions drop because the canonical answer is visible and authoritative.

---

### Ideas and Roadmap (Ideas Spaces)

**What it does:** Members submit ideas and vote them up or down. Admins move ideas through status stages: Open, Planned, In Progress, and Done. A roadmap view shows all ideas grouped by status, so the community can see what's coming.

**Why it matters:** Your most-requested features rise to the top automatically. Members feel heard because they can see their votes influence the roadmap. Support requests ("when are you adding X?") drop because the answer is public.

---

### Real-Time Reply Banner

**What it does:** When new replies arrive while a member is reading a thread, a sticky banner appears at the bottom. Clicking it loads the new replies. No page reload required.

**Why it matters:** Active threads stay usable. Members in a live discussion don't lose their place and don't have to refresh to see new content.

---

### Sort Options

**What it does:** Replies can be sorted by Oldest, Newest, or Best (top voted). The sort preference is saved per user.

**Why it matters:** Different spaces need different defaults. A support Q&A wants Best first so the right answer surfaces. A general discussion might want Oldest for chronological flow. Members can override the default for how they prefer to read.

---

## DISCOVERY - Search, Tags, Leaderboard

### Full-Text Search

**What it does:** Search across posts, spaces, and tags using MySQL FULLTEXT in BOOLEAN MODE. Results are grouped by type (All, Posts, Spaces, Tags) with counts and content excerpts. The search system uses a swappable adapter interface. Connect Meilisearch or Elasticsearch when your community needs it, using the same API.

**Why it matters:** Members find answers before they ask questions. The adapter pattern means you can start with built-in search and upgrade to a dedicated search service later without touching any custom code.

---

### Tags

**What it does:** Posts can have multiple tags. Each tag has its own page that collects all related discussions. Spaces can have default tags. Admins can manage the global tag list.

**Why it matters:** Tags create discovery paths that cross space boundaries. A member searching for a specific topic can find discussions from multiple spaces on the same tag page.

---

### Leaderboard

**What it does:** Leaderboard page shows top contributors by posts, replies, and reputation. Rankings update in real time.

**Why it matters:** Active members get recognition. New members can find the most helpful people in the community quickly. The leaderboard also signals community health. A growing leaderboard shows an active community.

---

### User Profiles

**What it does:** Every member has a profile page showing their posts, replies, reputation, trust level, and activity history.

**Why it matters:** Members can evaluate the credibility of a contributor before acting on their advice. Profiles also give active members a sense of identity and ownership in the community.

---

## MODERATION - Trust Levels, Flagging, Anti-Spam

### Six Trust Levels

**What it does:** Members move through six trust levels: Newcomer (0), Member (1), Regular (2), Trusted (3), Leader (4), and Moderator (5). Levels 0-3 are earned automatically based on activity. Levels 4 and 5 are granted by admins.

**Why it matters:** Trust is earned, not assumed. New accounts have limited reach, which cuts spam without requiring constant moderator attention. Reliable contributors gain capabilities over time, and by the time someone reaches Level 4, the community has already shown they deserve the trust.

---

### Automatic Behavior Gates

**What it does:** Level 0 members are rate-limited to 3 posts per day and cannot post links. These restrictions lift automatically as members earn higher trust levels. No configuration required.

**Why it matters:** This is the single most effective anti-spam measure in Jetonomy. Most spam comes from new accounts. Rate-limiting new accounts and blocking links from them cuts the volume of spam reaching the moderation queue dramatically, before it's ever published.

---

### Moderation Queue

**What it does:** A single admin view showing pending posts, pending replies, flagged content, and banned users. Moderators can approve, mark as spam, or trash in one click. Marking as spam deducts 20 reputation from the author automatically.

**Why it matters:** Moderation is one screen instead of five. The most important actions take one click. Reputation deductions for spam create a natural feedback loop that discourages repeat offenses.

---

### Member Flagging

**What it does:** Any member can flag a post or reply with a reason: spam, offensive, off-topic, harassment, or other. Flagged content goes to the moderation queue automatically.

**Why it matters:** Your community becomes part of your moderation team. Moderators review flagged content rather than patrol the entire community. On an active community, this reduces the moderation surface to a small fraction of all content.

---

### Bans, Silencing, and Restrictions

**What it does:** Ban users globally or per-space, with an optional expiry date. Silence a user so they can read but not post. IP banning is available for persistent bad actors.

**Why it matters:** Not every problem requires a permanent ban. Silencing lets a member keep reading while preventing disruptive posting. Per-space bans let you remove someone from a specific part of the community without banning them entirely.

---

### Revision History

**What it does:** Every edit to a post or reply creates a revision. Moderators can review exactly what changed and when.

**Why it matters:** Edits can't be used to hide rule violations after the fact. Moderators have a complete record for any moderation decisions or disputes.

---

### Three-Layer Permission System

**What it does:** WordPress capabilities provide the baseline. Per-space roles (viewer, member, moderator, admin) add space-level control. Trust levels and access rules handle fine-grained behavior. All three layers resolve in a single permission check.

**Why it matters:** A user can be a moderator in the Support space and a regular member everywhere else. A space admin can manage their space without WordPress admin access. Membership plugin integrations can gate specific spaces without touching WordPress roles.

---

## PRO - 15 Extensions for Larger Communities

### Emoji Reactions (Pro)

**What it does:** Members can react to posts and replies with emoji. Multiple reactions per post. Reactions are tallied and displayed inline.

**Why it matters:** Not every response needs a reply. Reactions let members acknowledge content (agree, funny, helpful) without adding noise to the thread. High-reaction posts surface as useful signals.

---

### Private Messaging (Pro)

**What it does:** Members can send direct messages to each other. Full conversation threads at /community/messages/. Unread message count in the nav bar. Admins can disable messaging globally or per trust level.

**Why it matters:** Members who want to take a conversation private don't need to share contact details or leave the platform. This keeps collaboration on your site.

---

### Polls (Pro)

**What it does:** Polls can be embedded in posts. Single-choice or multiple-choice. Optional close date. Live results update as votes come in.

**Why it matters:** Quick votes without the overhead of a full Ideas space. Great for gathering opinions, running community decisions, or breaking ties.

---

### Custom Badges with Criteria Engine (Pro)

**What it does:** Create badges that are awarded automatically when a member meets defined criteria: first post, 100 replies, Trust Level 3 reached, first accepted answer, and more. Badge display on profiles and in posts.

**Why it matters:** Badges gamify participation in a way that goes beyond the automatic trust system. You can create badges specific to your community's milestones and culture. Members collect them; profiles display them as signals of expertise.

---

### Community Analytics Dashboard (Pro)

**What it does:** Dashboard showing posts over time, active members, space activity, top contributors, content growth trends, and engagement rates. Filterable by date range and space.

**Why it matters:** You can't grow what you can't measure. The analytics dashboard tells you which spaces are thriving, when your community is most active, who your top contributors are, and whether engagement is trending up or down.

---

### Advanced Auto-Moderation Rules (Pro)

**What it does:** Create rules that trigger automatic actions - hold for review, spam, or auto-approve - based on conditions like trust level, word lists, link count, and post frequency.

**Why it matters:** The built-in trust-level gates handle most spam. Pro auto-moderation handles the cases that need custom rules specific to your community: particular phrases, post patterns, or new accounts from certain domains.

---

### Email Digest (Pro)

**What it does:** Members can subscribe to a daily or weekly digest of community activity. Configurable by space and content type.

**Why it matters:** Not all members check the community every day. The digest brings them back with a summary of what they missed. Digest subscribers typically have higher long-term retention than members who rely only on individual notifications.

---

### Reply by Email (Pro)

**What it does:** Members can reply to notification emails and have their reply posted to the forum automatically. No login required.

**Why it matters:** Reduces the friction of participation. Members who receive an email notification can respond without switching context.

---

### Web Push Notifications (Pro)

**What it does:** Members can subscribe to browser push notifications. Triggers on the same events as in-community and email notifications.

**Why it matters:** Push notifications reach members even when they're not on your site. For time-sensitive discussions - support questions, active debates - push gets the right people back quickly.

---

### Webhooks (Pro)

**What it does:** Send HTTP POST requests to any URL when community events occur: new post, new reply, trust level change, accepted answer, member joined, and more. Configurable event selection and target URL per webhook.

**Why it matters:** Connect Jetonomy to your existing tools without building a custom integration. Trigger Zapier workflows, post to Slack, sync to a CRM, or update an external analytics system when community events happen.

---

### White Label (Pro)

**What it does:** Remove Jetonomy branding from the frontend and email notifications. Replace with your own product name, logo, and footer text.

**Why it matters:** Agencies building community features for clients can present Jetonomy as their own platform. SaaS products embedding a community can maintain consistent branding.

---

### Custom Fields (Pro)

**What it does:** Add custom fields to posts, user profiles, and spaces. Field types include text, textarea, select, checkbox, URL, and date.

**Why it matters:** Different communities need different data. A job board needs location and salary. A software community needs version numbers. Custom fields let you extend posts and profiles to fit your specific use case without writing code.

---

### Per-Space SEO Controls (Pro)

**What it does:** Override default SEO settings per space: custom meta title patterns, meta description, noindex toggle, and canonical URL behavior.

**Why it matters:** Some spaces should be indexed; others (private support channels, staff areas) should not. Pro SEO controls let you manage this precisely instead of using site-wide settings that affect all spaces.

---

### WooCommerce, LearnDash, and Restrict Content Pro Integrations (Pro)

**What it does:** Gate spaces by WooCommerce product purchase, LearnDash course enrollment, or Restrict Content Pro membership level. Access rules sync automatically with subscription status.

**Why it matters:** The free plugin supports MemberPress and Paid Memberships Pro. Pro expands this to three additional membership and LMS systems, covering the majority of WordPress membership and course setups.

---

### AI Integration (Pro)

**What it does:** Every new post and reply is scored by a language model before it is published. AI spam detection catches subtly rewritten affiliate spam and context-aware abuse that pattern matching misses. Content moderation flags posts against a rule set you describe in plain English. Reply suggestions draft answers for knowledge-base communities. Thread summaries pin a short summary at the top of long topics so new readers do not have to scroll.

**Providers:** OpenAI, Anthropic, any OpenAI-compatible endpoint, or self-hosted Ollama running on the same server as WordPress.

**Why it matters:** Moderation is the single biggest burden on community owners. Trust levels handle most of the work, and AI handles the gray zone: context-aware spam, subtle abuse, and the long tail of judgment calls. With the Ollama provider, everything runs on your own server. No content leaves your machine, no per-request API bill, and every decision is logged for compliance review.

---

### Site Announcements (Pro)

**What it does:** Admins can pin any post as a community-wide announcement. Pinned announcements appear at the top of every space listing across the entire site, regardless of which space the post was originally created in. Up to five active announcements at a time.

**Why it matters:** When you need to reach every community member - a rule change, a planned maintenance window, a major product update - a space-scoped sticky post leaves some members out. Site Announcements guarantee the message is visible everywhere, without spamming individual spaces or sending a blast email.

---

## DISCUSSION CONTROLS (FREE)

### Private Topics

**What it does:** Mark individual topics as private so only the author and space moderators can see them. Other members of the space cannot find or open the topic.

**Why it matters:** Support communities often have sensitive one-off conversations - account details, abuse reports, billing questions - that should not sit in a public space but do not warrant a full private message thread. Private topics handle this case without moving the conversation off-platform.

### Topic Prefixes

**What it does:** Space owners define colored labels like Bug, Question, Solved, or Announcement. Members pick a prefix when creating a topic and it appears as a colored tag in the space listing. Click any prefix to filter the listing.

**Why it matters:** Prefixes turn a wall of topic titles into a scannable, classifiable list. A support space with Bug in red, Solved in green, and Discussion in grey becomes instantly navigable for moderators and readers alike.

### Similar Topics Detection

**What it does:** As members type a new topic title, Jetonomy searches for existing topics with similar titles in the same space and shows up to five matches inline below the title field.

**Why it matters:** Most duplicate topics are created because the author did not know an existing topic already covered their question. Catching duplicates at composition time reduces cleanup work for moderators and surfaces existing answers to the author before they write.

### Quote Replies

**What it does:** Select any passage in a reply and click Quote to insert a styled blockquote into your reply composer with attribution linked back to the source. Or click Quote in a reply's action menu to quote the full body.

**Why it matters:** Long threads stay readable when each reply quotes the specific passage it is responding to. Quote Replies gives readers the context without forcing them to scroll back up a thousand replies.

---

## SPACE TYPES, SCALE, AND ACCESSIBILITY (FREE)

### Show & Tell Spaces

**What it does:** A space type designed for short-form content. Posts use inline content cards with an optional title and rich content area. No long subject lines required. Great for sharing links, screenshots, team updates, and news.

**Why it matters:** Not every community conversation fits a forum thread structure. Show & Tell gives members a low-friction way to share quick content - and gives moderators a dedicated space to separate short posts from in-depth discussions.

---

### Social Feed Spaces

**What it does:** A chronological stream of short posts with emoji reactions and quick commenting. No threading depth required. Members scroll and react rather than open threads.

**Why it matters:** Some communities want a lively, low-effort stream alongside deeper forum discussions. Social Feed gives members a familiar format for quick updates and reactions without creating noise in structured Q&A or Ideas spaces.

---

### Ideas Spaces with Real Roadmap Lanes

**What it does:** Ideas spaces include a full roadmap view with four status lanes: Planned, In Progress, Shipped, and Declined. Members can see exactly where each idea stands. Admins drag ideas between lanes or update status from the moderation view.

**Why it matters:** Telling a member "your idea is under review" is not the same as showing them it moved from Planned to In Progress this week. Visible status lanes turn the ideas board into a real product roadmap - one that builds trust because members can see their input making a difference.

---

### Q&A "Answered" Badge in Space Listings

**What it does:** Q&A spaces show an "Answered" badge on any post that has an accepted answer, visible directly in the space listing. Posts without an accepted answer are clearly distinguishable at a glance.

**Why it matters:** Members scanning the space listing can skip posts that are already resolved and focus on open questions. This reduces duplicate answers and helps new members find authoritative replies before posting.

---

### Cleanup Cron Batching for Large Communities

**What it does:** Background cleanup jobs process records in batches of 500 per run, with a filter (jetonomy_cron_batch_size) to adjust the batch size. Previously, cleanup jobs could time out on large installs with tens of thousands of rows to process.

**Why it matters:** Communities with 10,000+ posts, activity log entries, or read-status records no longer face cron timeouts or partial cleanups. The filter gives developers control over the batch size when needed.

---

### Multisite Network Activation

**What it does:** Activating Jetonomy network-wide installs the required database tables on every existing subsite and on any new subsite created in the future.

**Why it matters:** Network operators running Jetonomy across 10, 50, or 500 subsites no longer need to activate site-by-site. New subsites get fully provisioned database tables automatically.

---

### Internationalization and Accessibility

**What it does:** All user-facing strings across space listings, filter controls, bulk actions, and selection interfaces are translatable. Keyboard focus indicators are visible throughout the frontend. All filter controls, bulk action dropdowns, and select-all checkboxes have proper aria-labels.

**Why it matters:** Communities with non-English-speaking members can translate the interface via standard WordPress translation workflows. Members who navigate by keyboard or use assistive technologies get clear focus indicators and labeled controls on every surface.
