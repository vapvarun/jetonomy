# Jetonomy Welcome Sequence

5-email onboarding sequence for new free users. Triggered on activation (or first wp-admin visit post-install). Sends from your site's admin email via wp_mail.

**Timing:**
- Email 1: Day 0 (immediately on install)
- Email 2: Day 2
- Email 3: Day 5
- Email 4: Day 10
- Email 5: Day 14

Replace `[LINK-*]` placeholders before sending.

---

## Email 1 — Day 0: Welcome + Quick Start

### Subject Lines

**A:** Jetonomy is installed — here's how to launch your first space in 5 minutes
**B:** Your community starts here

### Preheader

The setup wizard handles everything. It takes about 5 minutes.

---

### Body

Hi [Name],

Thanks for installing Jetonomy. Your WordPress site now has everything it needs to run a community — forums, Q&A boards, and idea spaces all in one place.

Here's the fastest path to your first live space:

**Step 1 — Run the setup wizard**
Go to Jetonomy > Setup in your WordPress admin. The wizard creates your first space, sets your community URL (/community/ by default), and drops in a few sample topics so you can see how things look before going live.

[Open Setup Wizard] [LINK-SETUP-WIZARD]

**Step 2 — Choose your first space type**
- **Forum** — open discussion threads, like a traditional message board
- **Q&A** — questions with accepted answers, great for support or knowledge bases
- **Ideas** — members submit and vote on ideas, useful for product feedback boards

You can have as many spaces as you want. Most communities start with one or two and add more as they grow.

**Step 3 — Add the community page to your navigation**
Once you've gone through the wizard, add `/community/` to your site's main menu. That's it — Jetonomy inherits your theme's fonts, colors, and spacing automatically, so it'll look like part of your site from day one.

**Need help getting started?**
The docs are at [LINK-DOCS-GETTING-STARTED] and cover installation, the wizard, and your first space in detail.

Have a question? Just reply to this email.

The Wbcom Designs Team

---

## Email 2 — Day 2: Your First Q&A Space

### Subject Lines

**A:** One space type that makes your community 3x more useful
**B:** Have you tried the Q&A space yet?

### Preheader

Q&A spaces work differently — here's why that matters for your community.

---

### Body

Hi [Name],

Two days in — how's the setup going?

If you started with a Forum space, that's a great choice. But there's a second space type worth knowing about: **Q&A**.

**What makes Q&A different from a regular forum:**

In a Forum space, every reply is equal. The newest ones sit at the top (or bottom, depending on your sort), and it's up to visitors to read through everything to find the useful bits.

In a Q&A space, the question author can mark one reply as the **accepted answer**. That reply gets highlighted at the top of the thread — above everything else — and stays there. Upvotes determine the order of the rest.

The result: someone asking a question on your site gets a clear, visible answer immediately. No digging through five replies to find the one that actually helped.

**Three situations where Q&A spaces work well:**

1. **Product support** — customers ask how something works, your team (or other users) answer, you mark the best one. Future visitors with the same question find the answer immediately.

2. **Knowledge bases** — create a Q&A space for each topic area. The voted-up, accepted answers build into a searchable archive over time.

3. **Community help** — members help each other. The person who asked can accept whatever answer actually solved their problem, and that answer earns the person who wrote it reputation points.

**To create a Q&A space:**
Go to Jetonomy > Spaces > Add New, and set the space type to Q&A. Everything else works the same as a Forum space.

[Create a Q&A Space] [LINK-CREATE-SPACE]

The docs have more detail on accepted answers and how reputation points are awarded:
[LINK-DOCS-QA-SPACES]

The Wbcom Designs Team

---

## Email 3 — Day 5: Trust Levels Explained

### Subject Lines

**A:** How Jetonomy keeps your community clean without you moderating everything
**B:** Trust levels: your community's self-moderation system

### Preheader

New members are automatically rate-limited. Trusted members earn more capabilities. Here's how it works.

---

### Body

Hi [Name],

One of the more unusual things about Jetonomy is how it handles new users. No other WordPress forum plugin does this quite the same way.

**Every member starts at Trust Level 0.**

At Level 0, a new member:
- Cannot post links in their content
- Is limited to 3 posts per day
- Cannot edit posts after 30 minutes

These limits require zero configuration from you. They're on by default and they do a lot of the spam filtering work automatically — before anything goes to your moderation queue.

**Members earn higher trust by participating.**

Trust Levels 1 through 3 are earned automatically based on activity — posting, getting upvotes, having answers accepted, returning to the community over multiple days. The thresholds are configurable in Jetonomy > Settings > Trust Levels.

At Level 1, the daily post limit increases. At Level 2, posting links is allowed. At Level 3, members can edit their own content freely and follow topics to get notifications.

**Levels 4 and 5 are assigned manually.**

Level 4 is your community's moderator tier — users at this level can approve content, move posts between spaces, and resolve flags. Level 5 is for trusted moderators who can take more significant actions.

You assign Level 4 and 5 via Jetonomy > Users. Most communities only ever need a few people at these levels.

**Why this matters for you:**

Traditional moderation is reactive — someone posts something bad, you deal with it. Trust levels make your community proactive. The system holds new members to a higher standard automatically, and rewards the members who contribute consistently.

Most forums at 50+ active members find that 80–90% of moderation issues are handled by the trust system before a moderator ever sees them.

[Configure Trust Level Thresholds] [LINK-TRUST-SETTINGS]
[Trust Levels — full documentation] [LINK-DOCS-TRUST-LEVELS]

The Wbcom Designs Team

---

## Email 4 — Day 10: Power Features

### Subject Lines

**A:** Four Jetonomy features most people don't find until week three
**B:** Getting more out of Jetonomy: search filters, drafts, and topic management

### Preheader

These features exist, they're useful, and they're easy to miss.

---

### Body

Hi [Name],

Ten days in — at this point, you've probably got your spaces set up and your first members posting. Here are four features that are worth knowing about if you haven't found them yet.

**1. Search filters**

Jetonomy's search supports FULLTEXT boolean mode, which means your members can filter results by space, by post type (question, discussion, idea), by tag, and by sort order (relevance, newest, most voted). The search page lives at `/community/search/`.

If your community grows and the built-in search starts feeling slow, the search system is adapter-based — you can connect Meilisearch or Elasticsearch later without changing anything else about how Jetonomy works.

**2. Drafts**

Members can save posts as drafts before publishing. Drafts are private, auto-saved every 30 seconds while writing, and accessible from the member's profile. If someone closes the browser mid-post, their draft is waiting when they come back.

This also means you can schedule posts for future publication — useful for announcements or recurring discussions.

**3. Topic management actions**

From any post view, space moderators can:
- Pin a post to the top of the space
- Lock a post (members can read but not reply)
- Move a post to a different space
- Mark a post as resolved or closed

These actions are available via the post's dropdown menu. You don't need to go to the admin panel to manage content.

**4. Bookmarks and following**

Members can bookmark any post for later reference, accessible from their profile under "Saved." They can also follow any post to get notifications for new replies — even posts they didn't write.

Both features show up in notification preferences, so members control what they want to be notified about.

**Finding members who need a trust level upgrade?**

Go to Jetonomy > Users. Sort by Post Count or Reputation Score. If you see active contributors who are still at Level 0–2, you can manually bump them up — it's a trust you give them, and it means a lot to community members who've been consistently helpful.

[Jetonomy Admin — Users] [LINK-ADMIN-USERS]

The Wbcom Designs Team

---

## Email 5 — Day 14: Introducing Jetonomy Pro

### Subject Lines

**A:** Your community is working — here's what Pro adds when you're ready
**B:** Two weeks in: a look at what Jetonomy Pro unlocks

### Preheader

The free plugin is complete. Pro adds the tools that help larger communities run better.

---

### Body

Hi [Name],

Two weeks since you installed Jetonomy. If your community is active, you're already getting real value from the free plugin — and that's the plan. The free version is a complete forum platform, not a demo.

That said, a few things come up as communities grow that the free plugin doesn't cover. Here's an honest look at what Pro adds, and who it's actually for.

**Reactions** — 8 emoji reactions on posts and replies (thumb, heart, clap, laugh, surprise, sad, fire, check). Members engage with content without writing a reply. Useful for announcements and popular threads.

**Private messaging** — 1:1 and group conversations between members, with unread tracking. If your members are emailing each other or using external tools to communicate, this keeps them on your platform.

**Polls** — Create polls attached to any post. Single choice, multiple choice, or ranked. Polls can be set to close automatically on a date. Live results update as votes come in.

**Analytics dashboard** — Posts per day, active users, engagement rate, top contributors, top spaces. Exportable as CSV. Useful once you want to understand what's working and what's not.

**Email digest** — Instead of one email per notification, members get a daily or weekly summary. Reduces email fatigue, increases the chance people actually read their notifications.

**Custom badges** — Build badges with your own criteria (e.g., "Post 10 questions that get accepted answers," "Help 5 new members"). The badge engine auto-evaluates on a schedule. Good for recognition programs.

**Advanced moderation** — Auto-moderation rules: keyword lists, regex patterns, link limits, spam scoring. Content matching your rules gets held for review automatically.

**Webhooks** — POST to any URL when events happen in your community (new post, accepted answer, new member, flag, etc.). Use this to connect your forum to Slack, Discord, Zapier, or any custom integration.

**Is Pro right for you right now?**

If your community has fewer than 50 active members and you're not running into the walls above — you probably don't need Pro yet. Use the free plugin, grow your community, and come back when you need these features.

If you're running a support forum, a product community, or a paid membership site — Pro pays for itself quickly in reduced moderation time and member engagement.

**Pro pricing:**

| Plan | Price | Sites |
|------|-------|-------|
| Starter | $99/yr | 1 site |
| Growth | $199/yr | 5 sites |
| Agency | $399/yr | Unlimited |
| Lifetime | $599 once | Unlimited |

All plans include all Pro modules, updates for the plan term, and email support.

[See Jetonomy Pro] [LINK-PRO-PAGE]

Questions before you decide? Reply to this email — we read every response.

The Wbcom Designs Team
