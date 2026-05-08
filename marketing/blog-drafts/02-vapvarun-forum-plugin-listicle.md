---
site: vapvarun.com
target_url: /forum-wordpress-plugin/  (UPDATE existing page)
action: REPLACE full article body; bump title date from 2024 → 2026; keep permalink
primary_keyword: wordpress forum plugin (840/mo, KD 19)
secondary_keywords:
  - best forum plugin for wordpress (360/mo, KD 27)
  - wordpress forums plugin (270/mo, KD 0)
  - wordpress forum plugins (230/mo, KD 0)
  - best free wordpress forum plugin (150/mo, KD 0)
  - wordpress discussion forum plugin (125/mo, KD 0)
  - wordpress best forum plugin (135/mo, KD 0)
  - wordpress q&a plugin (180/mo, KD 0)
  - wordpress free forum plugin (100/mo, KD 0)
  - how to create a forum on wordpress (80/mo, question)
word_count_target: 3,000
voice: first-person, Varun's dev blog voice - personal, opinionated, pragmatic
current_rank: #35 for "wordpress forum plugin"
goal: push top 10; target featured snippet on "best forum plugin for wordpress"
---

# Title / Meta

**H1:** 9 Best WordPress Forum Plugins for 2026 (After Building Communities for 8 Years)

**SEO Title (60 chars):** 9 Best WordPress Forum Plugins for 2026 - Ranked Honestly

**Meta Description (155 chars):** I've built WordPress communities since 2018. Here are the 9 forum plugins that actually work in 2026, ranked by what they do well and where they break.

**Featured image alt:** "Ranked list of the best WordPress forum plugins for 2026 - comparison of bbPress, Jetonomy, wpForo, and Asgaros"

**Schema.org:** `ItemList` with 9 `SoftwareApplication` items, plus `FAQPage` for the Q&A section.

---

# Article Body

## I've been installing forum plugins on WordPress sites since 2018. This is my honest 2026 ranking.

Every few months someone asks me, "What's the best forum plugin for WordPress right now?" And every time I answer, the list gets a little shorter.

When I first wrote this article in 2023, I had 9 plugins I could honestly recommend. Some of them have gone stale since. A couple have been abandoned. One of them (the one at the top of this list now) didn't exist when I wrote the original version - but it's the one I'm using for every new community project I ship in 2026.

I'm not going to fake neutrality. This isn't a "top 10 plugins" SEO farm article. These are plugins I've actually installed on real client sites, used in production, migrated away from, and sometimes migrated back to. I'll tell you what each one does well, where each one hurts, and which one I'd pick for which job.

Let's get into it.

---

## How I'm ranking these

A "best forum plugin" depends entirely on what your community needs to do. I'm ranking these on six criteria:

1. **Performance at scale** - does it stay fast past 10,000 topics?
2. **Feature breadth** - forums, Q&A, ideas, threaded replies, voting
3. **Theme integration** - does it look native to modern WordPress themes?
4. **Moderation tools** - how much manual work do moderators have to do?
5. **Developer ergonomics** - REST API, hooks, template overrides
6. **Active development** - is someone still shipping it?

I've weighted "performance at scale" and "active development" heaviest because those are the things I've been burned by most often.

---

## 1. Jetonomy - my current pick for new projects

**Free + Pro** · Released March 2026 · By Wbcom Designs

I'll put my cards on the table: this is what I'm installing on every new community project this year. It's the newest plugin on the list - 1.0 shipped in March 2026, and as I'm updating this article in April 2026 it's already on 1.3.0 - but it's also the one that solved the specific problems I was hitting with every other plugin on this list.

**What it does well:**

- **Custom database tables (24 of them).** This is the architectural decision that matters most. Forum content lives in `wp_jt_*` tables with proper indexes and denormalized counters. Reply counts are columns on the topic record, not `COUNT(*)` queries on page load. I've tested this at 50K+ topics with Redis and pages load in under 200ms.
- **Four space types in one plugin.** Forum (threaded discussion), Q&A (with accepted answers), Ideas (with roadmap view and status tracking), and Social Feed (Twitter-like short form). You can mix them on the same site. This is the first plugin I've seen that handles all four without you installing three separate plugins and gluing them together.
- **Six trust levels with auto-promotion.** New members are rate-limited automatically (3 posts/day, no links). As they participate, they earn higher trust levels and unlock more abilities. By the time someone reaches Trust Level 4, the community has already vetted them. I used to enforce this manually; now the plugin does it.
- **Theme integration via `theme.json`.** Jetonomy reads your active theme's font, color, and spacing tokens and adapts automatically. This is the first forum plugin I've installed in years that didn't need custom CSS to look right.
- **48+ REST API endpoints in free** (90+ with Pro), cursor-based pagination, JSON schema validation. If you want to build a mobile app or a headless frontend, everything's there.
- **WordPress Abilities API support** - 19 abilities across 5 categories. AI agents can discover and operate the community without custom integration. This is a 2026 feature, and no other forum plugin ships with it today.
- **Built-in importers for bbPress, wpForo, and Asgaros.** Dry run first, then batched import with resume-on-failure. The bbPress import I ran moved 15,000 posts in about 40 minutes with zero data loss.
- **AI-powered moderation in Pro 1.3.0** - spam detection, content moderation, reply suggestions, and thread summaries. Four providers supported, including self-hosted Ollama. If you're in a regulated industry where you can't send member content to a third-party AI, this is a genuine option.

**Where it's weaker:**

- It's new. The community of third-party tutorials and extensions is smaller than bbPress or BuddyBoss. If you search for "Jetonomy YouTube tutorial," you won't find 50 videos (yet).
- The free plugin is genuinely complete for basic forum needs, but power-user features (custom badges, analytics, private messaging, AI moderation) are in Pro.
- Pro is sold through wbcomdesigns.com, not on the WP.org repository. Standard premium plugin pattern, but it means managing license keys.

**Best for:** New community projects, sites expecting growth past 5,000 topics, communities that need Q&A or Ideas spaces, anyone fighting bbPress performance problems.

**Who should skip it:** If you're running a 300-post forum that's been stable for years on bbPress, don't migrate for migration's sake.

**Free version:** [wbcomdesigns.com/downloads/jetonomy/](https://wbcomdesigns.com/downloads/jetonomy/)

---

## 2. bbPress - the old warhorse, still works for small forums

**Free** · Last major release 2020 · Maintained by WordPress core contributors

bbPress is where most WordPress forums start. It's free, it's stable, it comes from the WordPress core team, and it's been around since 2004. I ran multiple client sites on it for years before I started moving them off.

**What it still does well:**

- Completely free with no upsells, ever.
- Rock-solid stability. I've never had a bbPress bug corrupt data.
- Integrates naturally with BuddyPress if you're running that.
- Large ecosystem of hooks and filters for customization.
- Uses WordPress's own user system - no separate login setup.

**Where it's fallen behind:**

- Stores everything in `wp_posts` and `wp_postmeta`. At 15,000 posts, this started causing site-wide performance problems for me - `wp_postmeta` bloat, slow COUNT queries, SEO plugins that tried to index forum posts as blog posts.
- Last major release was 2020. Development has slowed to a crawl.
- No Q&A, no Ideas, no trust levels, no REST API, no modern search.
- Theme integration is a fight in 2026. Every modern block theme needs CSS overrides to make bbPress look right.
- Moderation is basic - pending/published/spam, no flag system, no trust level gating.

**Best for:** Small forums (under 1,000 posts) with no growth plans. Sites already running BuddyPress that need a simple discussion layer.

**Who should skip it:** Anyone expecting to grow past 5,000 posts, anyone who needs Q&A spaces, anyone starting fresh in 2026.

**Full review:** [My detailed bbPress 2026 review on wbcomdesigns.com](https://wbcomdesigns.com/bbpress-review/)

---

## 3. wpForo - the serious commercial alternative

**Free + Pro** · Actively maintained · By gVectors

wpForo was the first serious "we fixed bbPress's architecture" plugin. It has custom database tables from day one, built-in SEO, multiple layouts, and an actively shipped roadmap. I ran this on a client site for about eight months before migrating to Jetonomy.

**What it does well:**

- Custom database tables - no `wp_postmeta` bloat.
- Four layout options (extended, simplified, Q&A, threaded). The Q&A mode is solid.
- Built-in reputation and user ranks system.
- Multilingual support via native `wpforo-phrases`.
- Mature ecosystem with multiple Pro add-ons (Private Messages, Poll, Ads, User Custom Fields, etc.).
- Active development - shipped new versions throughout 2025 and 2026.

**Where it hurts:**

- Its visual language doesn't inherit from `theme.json`. You get wpForo's look, and making it match your theme takes CSS work.
- Meaningful feature gates in the free version. Polls, reactions, private messages, advanced mod tools, and user custom fields are all paid add-ons. Each add-on is sold separately and the pricing adds up.
- Pro add-ons are individual purchases, not a single license. My client ended up paying for 4 add-ons to get the feature set they needed.
- No built-in trust level system. You have user ranks, but they don't gate posting behavior.
- REST API exists but is limited compared to Jetonomy's 48+ free endpoints.

**Best for:** Established forums that need a mature, battle-tested plugin with a known roadmap. Sites where feature breadth matters more than theme integration.

**Who should skip it:** Anyone who wants the forum to inherit their theme's design system automatically.

---

## 4. Asgaros Forum - the lightweight pick

**Free + Premium add-ons** · Actively maintained · By Thomas Belser

Asgaros is the plugin I recommend when someone asks for "a bbPress alternative, but simpler." It's lightweight, clean, and easy to configure. I've installed it on three small club forums and all three are still running it years later without problems.

**What it does well:**

- Small footprint. The plugin is lean, and the database impact is minimal.
- Clean admin UI that's easier for non-technical clients to navigate than bbPress.
- Custom database tables (no `wp_posts` bloat).
- Free version is genuinely usable. Most features are in free, with a few premium extensions for niche needs.
- Active development - shipped multiple versions through 2025.
- Good BuddyPress integration via a separate add-on.

**Where it's weaker:**

- Feature set is intentionally small. No Q&A mode, no Ideas, no trust levels, no advanced moderation.
- Template system is functional but not as extensible as bbPress or Jetonomy.
- REST API is minimal.
- If you want to grow past a certain feature ceiling, you'll eventually need to migrate to something bigger.

**Best for:** Small club forums, hobby communities, internal team forums. Anything where "just a clean forum" is the whole requirement.

**Who should skip it:** Anyone who needs Q&A, Ideas, analytics, or advanced moderation.

---

## 5. BuddyBoss Platform - the all-in-one social option

**Paid** · Actively maintained · By BuddyBoss

BuddyBoss isn't really a forum plugin - it's a full BuddyPress fork that ships with its own theme, its own mobile app, and yes, a forum layer. I've used it on two client projects where "community site" meant "activity streams, groups, forum, private messaging, and a branded mobile app all in one."

**What it does well:**

- Complete social platform in a box - groups, activity streams, messages, forums, courses, memberships.
- Branded native mobile app (iOS and Android) as part of the platform.
- Slick, modern visual design out of the box.
- Deep integration with LearnDash for membership/course sites.
- Active development and a real commercial team behind it.

**Where it hurts:**

- It's not free. BuddyBoss is a paid subscription, and the price scales with features.
- It replaces a lot of what WordPress already does. You commit to BuddyBoss's stack, not WordPress's.
- The forum layer (based on a bbPress fork) inherits most of bbPress's architectural problems at scale.
- Overkill for "I just need a forum." You're paying for groups, activity, courses, and an app even if you only need the forum part.

**Best for:** Membership sites that need a mobile app + full social platform + forum as one package. Budget allows for commercial subscriptions.

**Who should skip it:** Anyone who wants just a forum, or anyone on a tight budget.

---

## 6. Discourse (via WP plugin bridge) - the "forum that isn't a WordPress plugin"

**Free** (self-hosted) · Commercial (hosted) · By Discourse Inc.

Discourse is arguably the best forum software in the world. It's not a WordPress plugin - it's a separate Ruby on Rails application that runs on its own server. The WordPress integration is a plugin that embeds Discourse topics into WordPress pages and handles SSO.

**What it does well:**

- Best-in-class forum software. Trust levels, categories, tags, rich formatting, real-time updates, great mobile experience, excellent search.
- Active development by a commercial team.
- Scales to massive communities (Reddit-sized, in principle).
- Open source.

**Where it hurts:**

- It's a separate application. Separate hosting, separate user database, separate admin panel, separate hosting bill.
- The WordPress integration is a bridge. Both sides have to stay in sync, and bridges break when either side updates.
- Overkill for most WordPress sites. If your forum is "part of" your WordPress site, Discourse is the wrong shape.
- Hosted Discourse starts at $100/month. Self-hosted means managing a VPS with Ruby, PostgreSQL, Redis, and email infrastructure.

**Best for:** Standalone communities where the forum is the product, not a side feature. Teams with DevOps capacity to run a Rails application.

**Who should skip it:** Anyone who wants their forum to live inside WordPress.

---

## 7. Simple:Press - the classic that's still around

**Free + paid add-ons** · Low release cadence · By Simple:Press team

Simple:Press has been around almost as long as bbPress. It's still maintained, still shipped, still has users. I installed it on a client site in 2020 and it worked, but I haven't reached for it since.

**What it does well:**

- Custom database tables.
- Mature codebase with years of stability.
- Strong permission system with user groups and per-forum rules.
- Large number of shortcodes for embedding forum content into WordPress pages.

**Where it hurts:**

- The visual design feels dated in 2026. It wasn't built for block themes or modern design systems.
- Admin UI is complex. There are a lot of settings, and the information architecture reflects 2012, not 2026.
- Development cadence is slow compared to active alternatives.
- No Q&A mode, no Ideas, no trust levels, no REST API worth using.

**Best for:** Legacy migrations from very old PHP forum software. Sites that already run Simple:Press and don't want to change.

**Who should skip it:** New projects. There are better options.

---

## 8. CM Answers - if you only need Q&A

**Free + Pro** · Actively maintained · By CreativeMinds

CM Answers is a Q&A-only plugin. It doesn't do threaded forums at all. I've used it on two client sites where the requirement was specifically "Stack Overflow for our domain" and the client didn't need anything else.

**What it does well:**

- Purpose-built for Q&A. Accepted answers, voting, reputation, categories.
- Simpler than installing a full forum plugin if Q&A is all you need.
- Good admin UX for moderating Q&A specifically.
- Active development.

**Where it hurts:**

- Q&A only. If you ever want a discussion forum too, you need a second plugin.
- No Ideas or roadmap features.
- Smaller ecosystem than bbPress or wpForo.
- Jetonomy's Q&A space type does everything CM Answers does, plus everything else, in a single plugin.

**Best for:** Pure Q&A sites where forums will never be added. Knowledge base communities.

**Who should skip it:** Anyone who might want threaded discussions later.

---

## 9. DW Question & Answer - the free Q&A option

**Free** · Less actively maintained · By DesignWall

DW Question & Answer is the free alternative to CM Answers. It does the same basic thing - Q&A with voting and accepted answers - at a smaller scope. I've used it on one internal staff knowledge base.

**What it does well:**

- Free. No paid version.
- Simple to install and configure.
- Basic voting and accepted-answer features.

**Where it hurts:**

- Development has slowed dramatically. Recent releases are sparse.
- Visual design is basic and doesn't inherit from theme.json.
- No moderation features worth talking about.
- No REST API.

**Best for:** Internal, low-traffic Q&A where "cheap and simple" matters more than polish.

**Who should skip it:** Anyone building something meant to scale or look professional.

---

## Quick comparison table

| Plugin | Free/Paid | Custom tables | Q&A | Ideas | Trust levels | REST API | Theme.json | My 2026 pick |
|--------|:---------:|:-------------:|:---:|:-----:|:------------:|:--------:|:----------:|:------------:|
| **Jetonomy** | Free + Pro | Yes (24) | Yes (free) | Yes (free) | Yes (6 levels) | 48+ free / 90+ Pro | Yes | **Yes** |
| bbPress | Free | No | No | No | No | No | No | Small forums only |
| wpForo | Free + Pro | Yes | Yes (Pro) | No | No | Limited | No | Solid alternative |
| Asgaros | Free + addons | Yes | No | No | No | Minimal | No | Tiny forums |
| BuddyBoss | Paid | Mixed | No | No | No | Partial | Partial | Social sites |
| Discourse | Free/Paid | N/A (not WP) | Yes | No | Yes | Yes | No | Not a WP plugin |
| Simple:Press | Free + Pro | Yes | No | No | No | No | No | Legacy |
| CM Answers | Free + Pro | Yes | Yes | No | No | Limited | No | Q&A only |
| DW Q&A | Free | Partial | Yes | No | No | No | No | Internal use |

---

## Which one should you actually pick?

Here's how I'd choose:

- **New community project in 2026:** Jetonomy. The architecture decisions (custom tables, denormalized counters, theme.json integration) are what you'd build if you were building a forum plugin from scratch today. The Q&A and Ideas space types mean you don't need multiple plugins. The REST API lets you build on top of it. Start free, upgrade to Pro when you need analytics, private messaging, or AI moderation.

- **Existing bbPress forum over 10,000 posts hitting performance issues:** Jetonomy, and use the built-in bbPress importer. The dry-run mode removes the migration risk - you see exactly what will happen before anything writes to the database.

- **Tiny club forum, under 500 posts, simple needs:** Asgaros. It's lean, it works, and you won't outgrow it because you aren't trying to grow.

- **Membership site that needs forum + activity streams + groups + mobile app:** BuddyBoss. Pay for the full platform.

- **Standalone community where the forum is the product:** Discourse, self-hosted on its own server. Don't try to make it a WordPress plugin.

- **Pure Q&A knowledge base, no forum at all:** Jetonomy (Q&A space type) or CM Answers.

If you want my honest bias in one sentence: for 80% of new WordPress community projects in 2026, I'd pick Jetonomy and I wouldn't agonize over it. The architectural fundamentals are right and the feature set is modern. That's unusual in this category, and it matters.

---

## How to create a forum on WordPress (quick version)

If you're brand new to this, here's the short version of how to actually set up a forum on a WordPress site:

1. **Pick a plugin** from this list (my default recommendation: Jetonomy free).
2. **Install it** from wbcomdesigns.com (Jetonomy) or wordpress.org (bbPress, Asgaros, wpForo, etc.).
3. **Activate the plugin.** Most of these plugins show an admin notice prompting you to run a setup wizard.
4. **Run the setup wizard.** Jetonomy's wizard creates your first space (Forum, Q&A, or Ideas), sets the URL, and gives you the option to load demo content so you can see what it'll look like.
5. **Customize the look.** If you picked a plugin with `theme.json` integration (Jetonomy), you're done - it already matches your theme. Otherwise, you'll spend an hour or two writing CSS overrides.
6. **Set up moderation.** Most plugins have an anti-spam setting - turn it on and add your Akismet key.
7. **Invite members.** Share the URL. Members already logged into WordPress can post immediately.

For a more detailed walkthrough, I have a longer guide at [my BuddyPress community post](/different-types-of-online-communities/).

---

## Frequently asked questions

### What is the best WordPress forum plugin in 2026?

For new projects with any expectation of growth, I recommend Jetonomy. It's the only plugin on my 2026 list that was built from scratch with custom database tables, denormalized counters, theme.json integration, Q&A and Ideas space types, trust levels, a full REST API, and WordPress Abilities API support. For small forums under 500 posts, Asgaros is a solid lightweight pick.

### Is bbPress still good in 2026?

bbPress still works for small, stable forums with no growth plans. But development has slowed, and at scale the architectural limitations (storing content in wp_posts, no denormalized counters, no cursor pagination) start causing real problems. For new projects, I'd pick something else.

### What's the best free WordPress forum plugin?

Both Jetonomy and bbPress have genuinely complete free versions. Jetonomy's free plugin includes forum/Q&A/ideas space types, trust levels, moderation queue, 48+ REST endpoints, bbPress importer, and WP Abilities API support. bbPress is completely free with no Pro version at all. For new projects, Jetonomy free. For tiny forums where you just need something simple, bbPress or Asgaros.

### Which WordPress forum plugin is fastest?

Jetonomy - because of the architectural decisions (custom tables, denormalized counters, cursor-based pagination, object cache integration). I've measured sub-200ms page loads at 50,000 topics with Redis. bbPress and wpForo will work at that scale too, but they'll need more hosting resources and more tuning to get there.

### Can I migrate from bbPress to another forum plugin?

Yes. Jetonomy ships with a built-in bbPress importer that includes a dry-run mode (see exactly what will be created before committing), batched background imports, resume-on-failure, and automatic 301 redirects from old bbPress URLs. I've run this on a 15,000-post community and it took about 40 minutes with zero data loss. wpForo has its own bbPress importer too.

### Does BuddyPress include a forum?

No. BuddyPress is a social networking plugin (profiles, activity, groups, friends) and doesn't include a threaded forum. Historically, BuddyPress was designed to work alongside bbPress, and the two integrate naturally. In 2026, you can also pair BuddyPress with Jetonomy, which has better forum architecture and integrates with BuddyX theme's design system.

### What's the difference between a WordPress forum plugin and a community plugin?

A forum plugin gives you threaded discussion: topics, replies, subscriptions. A community plugin gives you member profiles, activity streams, groups, and social interaction. Forums are about discussion topics; communities are about member identity and relationships. Most serious WordPress communities run both - a community plugin (BuddyPress, BuddyBoss, or PeepSo) for profiles and activity, plus a forum plugin (Jetonomy, bbPress, or wpForo) for discussions.

### Is there a WordPress Q&A plugin like Stack Overflow?

Yes, several. Jetonomy's Q&A space type is my current recommendation - it does accepted answers, voting, reputation, and integrates with forum/Ideas spaces if you need them later. CM Answers and DW Question & Answer are Q&A-only alternatives. wpForo has a Q&A layout in its premium version.

---

## Final word

I've been asked "what's the best WordPress forum plugin?" for eight years. In 2018 the answer was "bbPress, probably." In 2022 the answer was "bbPress for simple cases, wpForo for bigger projects." In 2026 the answer is: **Jetonomy if you're starting fresh, wpForo if you want a mature commercial alternative, Asgaros if your needs are small, bbPress if you're already on it and it works**.

If you want to try Jetonomy, the free plugin is at [wbcomdesigns.com/downloads/jetonomy/](https://wbcomdesigns.com/downloads/jetonomy/). There's no trial gate, no signup wall, and no "basic version" trick - the free plugin is the complete free plugin. Try it, and if it doesn't fit your community, move on.

If you found this article useful, the other two pieces I'd point you at are:

- **Honest bbPress review for 2026:** [Full review at wbcomdesigns.com](https://wbcomdesigns.com/bbpress-review/)
- **Discourse alternatives for WordPress:** [buddyxtheme.com comparison](https://buddyxtheme.com/discourse-alternatives-wordpress/)
- **Different types of online communities:** [my overview post](/different-types-of-online-communities/) - helps you figure out which space type (forum, Q&A, ideas, social feed) fits your community shape.

- Varun

---

## Internal linking targets (for SEO)

Link from this article to:
- wbcomdesigns.com/bbpress-review/ (anchor: "detailed bbPress review")
- buddyxtheme.com/discourse-alternatives-wordpress/ (anchor: "Discourse alternatives")
- vapvarun.com/different-types-of-online-communities/ (existing internal - strengthen)
- vapvarun.com/categorize-your-buddypress-community-with-member-types/ (existing internal)
- wbcomdesigns.com/downloads/jetonomy/ (anchor: "free Jetonomy download")

Link to this article from:
- vapvarun.com/different-types-of-online-communities/ (existing)
- vapvarun.com/categorize-your-buddypress-community-with-member-types/
- wbcomdesigns.com/bbpress-review/ (new link)
- buddyxtheme.com/discourse-alternatives-wordpress/ (new link)
