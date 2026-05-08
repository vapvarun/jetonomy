# Jetonomy - Facebook Posts (Launch Week)

5 posts. Facebook rewards conversational, community-oriented content. These posts lean into engagement questions, use cases, and accessible explanations over technical depth.

The 3 posts in `SOCIAL-POSTS.md` cover the launch announcement, space types, and beta tester call-out. The 5 posts below cover complementary angles - trust levels, migration, member stories, engagement questions - designed for a WordPress user and small business owner audience.

Links are embedded inline. Post links in comments when possible to avoid Facebook's link-in-post penalty on reach.

---

## Post 1 - Community-Focused Launch Announcement

We just launched Jetonomy - a free WordPress forum plugin - and we want to tell you what makes it different from what's already out there.

Most WordPress forum plugins were built for the way WordPress worked in 2010. They store your discussions as WordPress posts, which means community pages slow down as your community grows. Your forum also tends to look like a plugin sitting on top of your site - mismatched fonts, different button styles, a distinct visual break from the rest of your design.

We built Jetonomy differently.

Community pages are served from custom database tables - designed specifically for forum data. They stay fast. And the design system reads your theme's settings automatically, so the forum inherits your fonts, colors, and spacing without any custom CSS.

Here's what you get in the free plugin:
- Forum spaces (classic discussion threads)
- Q&A spaces (best answer rises to the top, accepted by the person who asked)
- Ideas spaces (members submit and vote on ideas, you track status)
- Trust levels - new members are automatically rate-limited to prevent spam, trusted members earn more capabilities
- Full moderation tools
- Import from bbPress and wpForo

Whether you're running a support community, a membership forum, a product feedback board, or just want your members talking to each other - Jetonomy handles it.

Download link is in the comments.

What kind of community are you thinking about building? Drop it below - happy to tell you whether Jetonomy is the right fit.

#WordPress #CommunityBuilding #ForumPlugin #SmallBusiness

---

## Post 2 - Engagement Question: What's Your Biggest Forum Frustration?

Quick question for the WordPress community:

If you've tried running a forum or community board on your WordPress site - what went wrong?

We hear a few things come up over and over:

"The forum looked totally out of place on my site, no matter what I tried with CSS."
"It was fine with 50 members, then it got slow and I didn't know why."
"Spam was a constant battle - I gave up moderating."
"bbPress worked but it felt frozen in time."
"The free version was basically unusable and the Pro was expensive."

We built Jetonomy to address all of those. But we'd love to know what's actually been the most painful part for you.

Drop a comment below. And if you haven't tried Jetonomy yet - the free plugin is linked in the first comment.

---

## Post 3 - Feature Demo: Trust Levels

Here's one of the more interesting things Jetonomy does that most forum plugins don't: trust levels.

When someone creates a new account on your community, they start at Trust Level 0. At Level 0:
- They can post a maximum of 3 times per day
- They can't include links in their posts
- They can't edit their own posts after 30 minutes

You don't configure this. It's on by default. And it handles a huge percentage of spam and low-quality content automatically.

As members participate - posting, getting upvotes, coming back over multiple days - they earn higher trust levels. Level 2 lets them post links. Level 3 lets them edit freely. Levels 4 and 5 are moderator tiers you assign manually.

Why does this matter?

Without trust levels, every new member has the same capabilities as someone who's been contributing for months. That's an invitation for bots and drive-by spammers. Most forum platforms try to solve this with CAPTCHA, email verification, and manual approval - all of which create friction for legitimate new members too.

Jetonomy's trust system creates a natural ramp. New members are limited, but not blocked. As they contribute, they earn more. The community moderates itself, largely.

Try Jetonomy free at wbcomdesigns.com - link in comments.

What's your current approach to spam prevention on community platforms? We're curious what's worked and what hasn't.

#WordPress #CommunityManagement #ForumPlugin #SpamPrevention

---

## Post 4 - Migration Story (Migrating from bbPress)

If you've been running a bbPress community for a few years, you know what it's like: it works, but it's starting to feel dated.

The design doesn't match your current theme. Pages load a bit slower than the rest of your site. The admin interface hasn't really changed. And every time you look for new features, the bbPress development timeline hasn't moved much.

We built Jetonomy's importer specifically for this situation.

Here's how a migration from bbPress works:

1. Install Jetonomy on your site (it can run alongside bbPress during migration)
2. Go to Jetonomy > Import > bbPress
3. The importer scans your bbPress install and shows you a summary: X forums, Y topics, Z replies, and the user count
4. Run a dry-run - no data is changed, you just see what will be created
5. Run the actual import

Forums become Jetonomy spaces. Topics become posts. Replies become replies. Author history is preserved. Most migrations under 10,000 posts finish in under 10 minutes.

After migration, your old bbPress pages still exist until you deactivate bbPress. You can run both simultaneously during a transition period.

If you've been on bbPress and you've been waiting for a reason to move - this is a reasonable one.

Free plugin link in comments. And if you've done a forum migration before, we'd love to hear how it went.

#WordPress #bbPress #Migration #CommunityBuilding

---

## Post 5 - User Story: Q&A Spaces for Support Communities

Let's say you run a software product, a service, or a course - and your users ask you the same questions over and over.

You've probably tried a few things: FAQ pages (users don't read them), email support (doesn't scale), Facebook groups (messy, off-brand, you don't own it), Slack communities (great for real-time, bad for async Q&A that needs to be searchable later).

Here's how a Jetonomy Q&A space changes that dynamic:

A user asks a question in the space. You (or another community member) answer. You mark the best answer as accepted - it gets highlighted at the top of the thread. Votes push the other helpful replies up.

Three months later, a new user has the same question. They search, find the thread, and see the accepted answer at the top. They don't have to email you. They don't have to ask in a Facebook group and wait for someone to notice. The answer is right there.

Over time, your Q&A space becomes a living knowledge base - written by your community, curated by accepted answers and votes. The more people use it, the better it gets.

This is why we built Q&A as a first-class space type in Jetonomy, not just a plugin add-on.

If this sounds like something your community needs - link to the free plugin is in the comments.

What's your current go-to for async support questions? Curious what's working for other communities.

#WordPress #CustomerSupport #CommunityManagement #ForumPlugin

---

## POST-LAUNCH / v1.3.0 REFRESH - April 2026

Two community-focused Facebook posts for v1.3 AI launch. Conversational tone.

---

### Post 6 - Jetonomy 1.3 is Out - AI Moderation, Your Way

Quick update for anyone running a community on Jetonomy:

**Jetonomy 1.3 is live** and the big change is AI integration in Pro. I know "AI moderation" can sound vague, so here is what it actually does:

- Reads every new post and reply before publish and scores it for spam probability
- Flags content against rules you describe in plain English ("no political attacks, no personal insults")
- Drafts reply suggestions for knowledge-base spaces
- Generates summaries on long threads so new readers don't scroll forever

**The part I'm most proud of:** it supports self-hosted Ollama. That means you can run everything on your own server - no data leaves your machine, no API keys, no per-request bill. For communities with privacy-sensitive members, this is a real option for the first time.

We also support OpenAI and Anthropic if you prefer to pay for the best model quality.

If you're running Jetonomy Pro already, update to 1.3 and find **Jetonomy → Settings → AI Integration** to configure your provider. If you're on free, this is the release that makes Pro worth considering.

Free: https://wbcomdesigns.com/downloads/jetonomy/
Pro: https://wbcomdesigns.com/downloads/jetonomy-pro/

#WordPress #Community #AI

---

### Post 7 - A Note On Shipping Velocity

When we launched Jetonomy 1.0 two weeks ago, a few people messaged us privately with the same question: is this a "launch and then silence" plugin, or are you going to keep working on it?

Fair question. The WordPress plugin world is full of abandoned projects.

Here is what we've shipped in 14 days:

- 1.1: theme compatibility polish across 12 themes
- 1.2: Private Topics, Topic Prefixes, Similar Topics, Quote Replies (all in free)
- 1.3: full AI integration for Pro (with self-hosted Ollama support)
- Plus: GitHub Actions CI, nine bug fixes from early users, onboarding docs

And behind the scenes: the Basecamp board where we track every issue is public to paying customers. You can see what we're working on and what's next.

We're in this for the long haul. If you were waiting for momentum proof before trying Jetonomy - you have it now.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #Community #ForumPlugin
