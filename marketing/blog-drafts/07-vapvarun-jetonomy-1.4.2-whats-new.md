---
site: vapvarun.com
target_url: /jetonomy-1-4-2-whats-new/  (NEW post)
action: PUBLISH new post in "WordPress Development" category
primary_keyword: jetonomy 1.4.2
secondary_keywords:
  - wordpress forum plugin update
  - show and tell wordpress community
  - wordpress ideas roadmap plugin
  - wordpress multisite forum plugin
  - jetonomy pro webhooks
  - wordpress community plugin 2026
word_count_target: 2,400
voice: first-person, Varun's dev blog - personal, pragmatic, improvement-focused
audience: Wbcom customers, WordPress plugin developers, community admins evaluating forum plugins
through_line: community.wbcomdesigns.com - our own Jetonomy install, running in production since April 2026
goal: document the 1.4.2 release with the same honest retrospective format as the 1.3.x post
schema: BlogPosting
---

# Title / Meta

**H1:** Jetonomy 1.4.2: Show & Tell Spaces, a Real Ideas Roadmap, and What We Fixed

**SEO Title (60 chars):** Jetonomy 1.4.2 - Show & Tell, Ideas Roadmap, Webhooks Fixed

**Meta Description (155 chars):** Jetonomy 1.4.2 ships Show & Tell spaces, a status-tracked Ideas roadmap, Q&A Answered badges, multisite support, and Pro webhook reliability. Here's what changed and why.

**Featured image alt:** "Jetonomy 1.4.2 release - Show & Tell space type screenshot alongside Ideas roadmap with status badges"

---

# Article Body

## Everything in 1.4.2 came from something we hit on our own community first.

That's been true since 1.3.0. We run [community.wbcomdesigns.com](https://community.wbcomdesigns.com/) on Jetonomy in production - it's where Wbcom product support happens in the open. When something doesn't work right there, it becomes a ticket. When a pattern of questions shows up repeatedly, it becomes a feature. The 1.4.2 changelog reads like a list of things that annoyed me while moderating our own community.

Here's what we shipped, and why.

---

## Show & Tell: the space type that was missing

Every community I've set up in the last two years has eventually needed the same thing: a space for lightweight sharing that doesn't fit the forum format. Not a structured discussion. Not a Q&A thread. Something more like "here's what I built, take a look."

The usual workaround is an "Off Topic" or "Showcase" forum space, but it never quite works. Forum spaces are designed for threaded back-and-forth, and a "look at this thing I made" post doesn't want to be a thread. It wants to be a card you browse, react to, and optionally comment on.

Show & Tell is a new space type in 1.4.2 that does exactly that. Short-form content cards with an optional title. An inline feed layout where you can browse posts without clicking through each one. Voting and replies still work, so the best content surfaces. It sits alongside Forum, Q&A, and Ideas spaces - you add one where it makes sense for your community.

On community.wbcomdesigns.com, we added a Show & Tell space for customers to share sites they've built with BuddyX and Reign. The engagement on those posts is consistently higher than the equivalent "show off your site" thread in a Forum space. The format fits the content.

**When to use Show & Tell:**
- Showcase spaces for your users' projects and sites built with your product
- Work-in-progress posts that invite early feedback without the structure of a formal thread
- Internal team update spaces in private communities
- Any situation where "here's a thing" is the whole message and a forum thread adds friction

It's in the free plugin. To create one, go to Jetonomy > Spaces > Add New and set the type to Show & Tell.

---

## Ideas spaces now have a real status-tracked roadmap

Ideas spaces have let members submit and vote on suggestions since 1.0. What they didn't have was any way for you to respond at the space level - to say "we picked this one up" or "this is done" in a way that members could see.

In 1.4.2, each idea has a status: Planned, In Progress, Shipped, or Declined. You set it from the admin when you review an idea or when its status changes. Members get a notification when you update the status. The space listing shows an Answered badge on any idea that has moved off Open.

The practical difference: your Ideas space stops being a suggestion box and starts being a public-facing roadmap. Members can see what you're actually working on, what you've shipped, and what you've decided not to do (and ideally why - you can add a note when you set Declined).

I added this after spending a week on our own community watching members resubmit ideas we'd already internally scoped and decided on. They had no way of knowing. Every member who submitted a duplicate idea was a member who didn't trust that their voice had been heard. Status tracking fixes that.

**What this changes for community operators:**
- Reduces duplicate submissions on ideas you've already reviewed
- Builds visible accountability into your roadmap process
- Gives members a reason to watch for status notifications, which increases retention

The Ideas roadmap is free in 1.4.2. The `/community/s/:slug/roadmap/` URL now renders a status-grouped view of all ideas in the space.

---

## Q&A: Answered badges on the space listing

The accepted-answer mechanic has always worked well inside Q&A threads. The question author marks a reply as accepted; it pins to the top; future visitors with the same question find it immediately. Good for support forums, good for knowledge bases.

The problem: from the space listing view, there was no visible indication of which questions had already been answered. A member browsing the space couldn't tell at a glance whether their question was already in there. They'd either scroll through looking for it (and miss it) or post a duplicate.

1.4.2 adds an Answered badge to the space listing for any thread with an accepted answer. Members can also filter the listing by Answered or Unanswered. Your Q&A space now reads as a knowledge base at a glance - the answered questions are visually distinct from the open ones.

On our own support community, this single change reduced the "I know I asked this before but I can't find it" replies by a noticeable amount in the week after we deployed. Small UX improvement, genuine behavioral effect.

---

## Scale: cron batching for large communities

This one is short but important if you run a community with tens of thousands of posts.

Jetonomy's cleanup cron jobs - the scheduled tasks that archive old notifications, clean up expired drafts, prune orphaned records - were previously unbounded. On a large community, these tasks would occasionally time out on shared hosting or constrained environments, leaving the cleanup half-finished and sometimes throwing an error to the debug log.

In 1.4.2, cleanup crons process a maximum of 500 rows per run. Each scheduled run picks up where the last one left off. Large communities get fully cleaned up in multiple passes rather than timing out in one. The batch size is filterable via `jetonomy_cron_batch_size` if you need to tune it:

```php
add_filter( 'jetonomy_cron_batch_size', function() {
    return 250; // smaller batches for constrained environments
} );
```

We added this after hitting the timeout on community.wbcomdesigns.com when the notification table crossed 50,000 rows. Standard fix, should have been there earlier.

---

## Multisite: one activation, everything configured

If you run a WordPress multisite network and you've been putting off adding Jetonomy because of the per-site setup overhead - 1.4.2 removes that obstacle.

Network-activating Jetonomy now automatically installs the required database tables on every existing subsite. New subsites created after activation also get the tables installed automatically, via the `wp_initialize_site` hook.

Pro extensions follow the same pattern: Pro 1.4.2 installs extensions on every subsite at activation, not just the main site.

For agencies running client networks, or for companies running multi-brand communities on a single WordPress install, this is a meaningful quality-of-life improvement.

---

## Pro 1.4.2: webhooks restored, 13 contract bugs closed

For Jetonomy Pro users, the biggest change in 1.4.2 is that webhooks work again.

A regression in an earlier release broke several webhook event listeners. Posts were going out, ideas were getting status updates, members were joining - but the corresponding webhook events weren't firing. If you were routing these events to Slack, Discord, Zapier, or a custom integration endpoint, you would have noticed: the notifications stopped.

All 8 listeners have been updated and verified in 1.4.2. The event types that now fire correctly:

- New post published
- New reply published
- Answer accepted in Q&A space
- New member joined
- Join request approved
- Post flagged for moderation
- Idea status changed
- Private message received

---

Pro 1.4.2 also closes 13 contract bugs. I'll spare you the full list, but here's what "contract bug" means in practice: these are cases where a feature was documented to behave in a certain way and didn't. Not crashes, not data loss - just the wrong output in a situation the customer expected to work correctly. Each one was filed because someone hit it in production and it cost them time.

A few worth calling out:

**Private messaging composer is now fully translatable.** Every string in the composer has a proper i18n wrapper. If you're running a non-English community, the messaging UI will now follow your language files.

**Pattern-input fields have correct aria-labels.** Accessibility gap that affected users navigating with screen readers. Fixed.

**In-product modals handle focus correctly.** When a modal opens, focus moves to it. When it closes, focus returns to the triggering element. Standard accessibility behavior that wasn't quite right before.

---

## The improvement you can't see: i18n and a11y across the board

1.4.2 includes an accessibility and translation pass across a large number of free plugin surfaces that had gaps. Visible keyboard focus indicators are now present everywhere - forms, buttons, links, interactive elements. Aria-labels are present on interactive controls that didn't have them. Many strings that were previously hardcoded in PHP or JavaScript are now wrapped in `__()` and `_e()` for translation.

None of this is glamorous. All of it matters if you're building a community for an international or diverse audience, or if any of your members use assistive technology.

---

## What's next

1.4.2 closes the 1.4.x branch. The work that shipped here - new space types, Ideas roadmap, multisite hardening - sets up the next phase of what I want to build: better tools for community operators to understand and act on what's happening in their community.

That means the analytics work that's been on the backlog for a while. More on that when it's ready.

For now: update to 1.4.2. The free plugin is on WordPress.org. Pro is at [store.wbcomdesigns.com/jetonomy-pro](https://store.wbcomdesigns.com/jetonomy-pro/). If you want to see Jetonomy running in production, [community.wbcomdesigns.com](https://community.wbcomdesigns.com/) is the best demo we'll ever ship.

- Varun

---

# Publishing checklist

- [ ] `BlogPosting` schema - `datePublished: 2026-05-09`, `author: Varun Dubey`, `publisher: Vapvarun`
- [ ] Featured image: Show & Tell space screenshot + Ideas roadmap with status badges, side by side or collage
- [ ] Internal link to `/jetonomy-1-3-0-to-1-3-5-what-we-improved/` (the 1.3.x retrospective) - one, in opening, anchor: "six Jetonomy releases"
- [ ] Internal link to `/forum-wordpress-plugin/` - one, in closing, anchor: "best WordPress forum plugins"
- [ ] External link to `community.wbcomdesigns.com` (dofollow) - appears 2+ times
- [ ] External link to `store.wbcomdesigns.com/jetonomy-pro/`
- [ ] External link to WordPress.org Jetonomy plugin page
- [ ] Tags: `jetonomy`, `wordpress-plugin`, `forum-plugin`, `release-notes`, `community-plugin`, `multisite`, `wbcom`
- [ ] Category: `WordPress Development`
- [ ] Excerpt: "Jetonomy 1.4.2 ships Show & Tell spaces, a status-tracked Ideas roadmap, Q&A Answered badges, multisite network activation, and a full Pro webhook restoration. Here's what changed and why it matters."
- [ ] OG image: featured image at 1200x630
- [ ] Twitter card: `summary_large_image`

# Promotion checklist

- [ ] Cross-post excerpt to Varun's LinkedIn - hook: the Show & Tell use case + Ideas roadmap for product teams
- [ ] Twitter/X thread: 1 opener + 5 release tweets + 1 community.wbcomdesigns.com CTA (use `SOCIAL-POSTS-1.4.2.md` thread)
- [ ] Post to r/WordPress (self-post, full article, no affiliate links)
- [ ] Newsletter: "From the team" - 1.4.2 update, link to this post
- [ ] Pin an announcement topic on community.wbcomdesigns.com linking to this article
- [ ] Add to `jetonomy/marketing/CHANGELOG.md` as the canonical 1.4.2 retrospective
