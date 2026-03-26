# Jetonomy — Free to Pro Upgrade Sequence

3-email sequence for free users who have been active for 30+ days. Triggered when a user crosses a usage threshold — e.g., community has 10+ active members, or 50+ posts. These signals indicate a real community, not a test install.

**Timing:**
- Email 1: Trigger day (threshold crossed)
- Email 2: Day 7 after Email 1 (only if no purchase)
- Email 3: Day 14 after Email 1 (only if no purchase)

Suppress the sequence if the user upgrades at any point.

---

## Email 1 — "You're doing great — here's what Pro unlocks"

### Subject Lines

**A:** Your community is growing — a few things Pro adds when you're ready
**B:** [Site name] has [X] active members. Here's what's next.

### Preheader

You don't need Pro today. But these features come up as communities grow.

---

### Body

Hi [Name],

Your Jetonomy community is running well — [X] members have posted in the last 30 days, and you've got [Y] discussions going. That's a real, active community.

The free plugin handles everything you've been doing. But since your community is clearly working, this is a good moment to know what Pro adds — for when the need comes up.

**Reactions**
Members can react to posts with 8 emoji options: thumb, heart, clap, laugh, surprise, sad, fire, and check. Reactions let people engage without writing a reply — useful for announcements, popular threads, and low-effort acknowledgment of good answers.

**Private messaging**
1:1 and group conversations between members, with unread tracking and mute options. If your members are already emailing each other or linking to external chats, this keeps those conversations on your platform where you have context and moderation capability.

**Polls**
Attach polls to any post. Single choice, multiple choice, or ranked. Polls can auto-close on a set date. Results update live as members vote. Good for gauging member opinion without running a separate survey.

**Custom badges**
The default badges (First Post, Helpful Member, etc.) are in the free plugin. Pro adds a badge builder where you define your own criteria — "Answer 10 questions that get accepted," "Post on 20 different days," "Receive 50 upvotes." The badge engine evaluates automatically on a schedule.

**Advanced moderation rules**
Write keyword lists, regex patterns, and link limit rules that hold matching content for manual review — automatically, before it ever appears publicly. Useful once your community is large enough that you can't manually review every post.

These aren't features you need on day one. They're features you want when your community is established enough to benefit from them.

[See all Pro features and pricing] [LINK-PRO-PAGE]

No pressure — reply to this email if you have questions about whether Pro is the right fit for where you are now.

The Wbcom Designs Team

---

## Email 2 — "Your community is growing — Pro grows with you"

### Subject Lines

**A:** One week later: two Pro features for communities at your stage
**B:** Analytics and webhooks — the Pro features for communities that have traction

### Preheader

Once your community has data, the analytics dashboard changes how you run it.

---

### Body

Hi [Name],

A week ago, we mentioned Jetonomy Pro. You didn't upgrade — which is fine — but your community is still growing, so let's talk about two specific Pro features that become more valuable the larger your community gets.

**Analytics dashboard**

The free plugin tells you how many posts and members you have. The Pro analytics dashboard goes further:

- Posts per day and per week over time (spot when your community is most active)
- Active users (members who posted or replied in the last 7 / 30 days)
- Engagement rate (percentage of members who participated this week vs. last)
- Top contributors — the 10 members driving the most posts, replies, and accepted answers
- Top spaces — which of your spaces is getting the most activity
- Exportable as CSV

This data matters when you're deciding where to invest time — which spaces to promote, which members to recognize, whether your community is on a growth trajectory or plateauing.

**Webhooks**

Webhooks let your community notify other systems when things happen. Configure Jetonomy to POST to any URL on events like:

- New post published
- Answer accepted in a Q&A space
- New member joined
- Post flagged for moderation
- Idea status changed

In practice, this means you can pipe new posts into a Slack channel, trigger a Zapier workflow when a member joins, or update a dashboard when moderation events happen — without writing custom WordPress code.

If you're running a membership site, a product community, or a support forum, both of these features pay back their time cost quickly.

**Pro pricing reminder:**

| Plan | Price | Sites |
|------|-------|-------|
| Starter | $99/yr | 1 site |
| Growth | $199/yr | 5 sites |
| Agency | $399/yr | Unlimited |
| Lifetime | $599 once | Unlimited |

[Get Jetonomy Pro] [LINK-PRO-PAGE]

Still not sure? Reply and tell us how you're using Jetonomy — we can tell you honestly whether Pro is the right call for your situation.

The Wbcom Designs Team

---

## Email 3 — "Last call: your community doesn't wait for you"

### Subject Lines

**A:** One last note on Jetonomy Pro (and why community momentum matters)
**B:** Your members are active — the tools that help you keep up

### Preheader

Communities grow or stall based on what happens in the first 90 days. Here's how Pro helps.

---

### Body

Hi [Name],

This is the last email in this series. We don't want to push you toward a purchase that isn't right for you — but we do want to leave you with one honest thought.

**Community momentum is easier to maintain than it is to rebuild.**

The window where your members are most engaged — posting regularly, responding, exploring the platform — is early. If that window passes without the tools to deepen engagement, communities often stall. Posts slow down. Members stop checking in.

The features in Jetonomy Pro — reactions, private messaging, polls, email digest, custom badges — aren't just extras. They're the things that give active members more ways to interact, and they give you more ways to recognize and reward the members who are showing up.

**Email digest** is worth calling out specifically. Right now, your most engaged members probably get a notification for every reply to a thread they follow. That's fine with 30 members. At 100+ members in active spaces, notification volume gets overwhelming and people start unsubscribing. The Pro email digest consolidates those into a daily or weekly summary — members choose their preference, stay informed, and don't burn out on emails.

**Where things stand:**

Your community has been running for [X] days. You have [Y] members and [Z] posts. That's real work. Pro is how you build on it.

[Get Jetonomy Pro — starting at $99/yr] [LINK-PRO-PAGE]

If you have specific questions about what Pro would add to your particular community, reply here. We'll give you a straight answer.

The Wbcom Designs Team

---

## Sequence Notes

**Suppression rules:**
- Stop sending immediately on purchase
- Do not re-trigger the sequence on reinstall if the user has already seen it
- Skip Email 3 if the user opened Email 2 but didn't click — they're aware, not interested yet; re-queue at day 30 instead

**Personalization tokens to implement:**
- `[Site name]` — from `get_bloginfo('name')`
- `[X]` — active members count (posted in last 30 days)
- `[Y]` — total posts count
- `[Z]` — posts count (Email 3 variant)
- `[LINK-PRO-PAGE]` — https://wbcomdesigns.com/downloads/jetonomy-pro/
