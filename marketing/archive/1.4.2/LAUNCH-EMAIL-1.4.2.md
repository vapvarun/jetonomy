# Jetonomy 1.4.2 Launch Email Sequence

Three emails for the 1.4.2 release sequence. Send Email 1 on release day, Email 2 three days later, Email 3 seven days later.

**Audience:** Existing free users (primary), plus warm list of WordPress developers and community builders.
**Suppress if:** User has already purchased Pro at any point before Email 3.

---

## Email 1 - Day 0: What's New in Jetonomy 1.4.2

### Subject Line Options

1. Jetonomy 1.4.2 is out - Show & Tell spaces, Ideas roadmap, and multisite support
2. New: three features your community has been asking for
3. What we shipped in Jetonomy 1.4.2

### Preheader Text

Show & Tell spaces, a real status-tracked Ideas roadmap, Q&A Answered badges, and Pro webhooks working again.

---

### Body

Hi [Name],

Jetonomy 1.4.2 is live. Here's what's in it.

---

**Show & Tell - a new space type**

Forum spaces are for structured discussion. Q&A spaces are for questions with accepted answers. Ideas spaces are for roadmap feedback.

Show & Tell is for everything in between: the quick project update, the screenshot worth sharing, the work-in-progress that doesn't need a full thread. Short-form content cards with optional titles, displayed in an inline feed layout.

It's the space type that fills the gap between a forum and a social feed. Free in 1.4.2.

---

**Ideas spaces now have a real roadmap**

When a member submits an idea and you review it, you can now set a status: Planned, In Progress, Shipped, or Declined. Members get notified when the status changes. The space listing shows an Answered badge on any idea that has moved off Open.

If you've been using Ideas spaces as a suggestion box - this turns them into a public-facing product roadmap. Your community can see exactly where each idea stands.

---

**Q&A spaces: Answered badges on the listing view**

The accepted-answer mechanic already worked inside individual threads. In 1.4.2, the space listing now shows an Answered badge on any thread that has an accepted answer. Members can filter for answered or unanswered questions.

Your Q&A space now reads as a knowledge base at a glance, not just a pile of open questions.

---

**Scale: cron batching for large communities**

Cleanup cron jobs now process a maximum of 500 rows per run. Previously, on communities with tens of thousands of posts, scheduled cleanup tasks would time out. 1.4.2 fixes that. The batch size is adjustable via the `jetonomy_cron_batch_size` filter if you need to tune it for your environment.

---

**Multisite: network activation installs everywhere**

If you run a WordPress multisite network, activating Jetonomy at the network level now automatically installs the required database tables on every existing subsite and every new subsite going forward. No per-site setup required.

---

**Free update available now:**

[Download Jetonomy 1.4.2] [https://wbcomdesigns.com/downloads/jetonomy/]

Jetonomy Pro 1.4.2 is also live - more on that in the next email.

Questions? Reply here.

The Wbcom Designs Team

---

## Email 2 - Day 3: A Closer Look at Show & Tell

### Subject Line Options

1. What Show & Tell spaces are actually for (and when to use one)
2. The new Jetonomy space type: short-form content, done right
3. Show & Tell: three community use cases worth trying

### Preheader Text

It's not a forum. It's not a social feed. It fills the gap between the two.

---

### Body

Hi [Name],

Three days ago we shipped Show & Tell spaces in Jetonomy 1.4.2. Today I want to spend more time on what they're actually for - because when I say "short-form content," I don't think that tells the full story.

---

**The gap Show & Tell fills**

Most community platforms give you two modes: structured discussion (forum threads) or unstructured conversation (activity feeds, chat channels). Both have a place. But there's a third thing communities want that neither handles well.

Call it "show and share." The community member who just finished a project and wants to share a screenshot. The developer who built something on your API and wants to post a quick demo. The writer who wants to share a draft and get a reaction without starting a formal thread.

Forum spaces feel too heavy for this. An "off-topic" forum thread buries quickly and gets no engagement. An activity feed is the opposite problem - everything disappears in the scroll.

Show & Tell spaces give that content its own place. Short-form cards. Optional title. Inline layout that doesn't require clicking through to read. Voting and replies still work, so the best posts surface naturally.

---

**Three use cases worth trying**

**Community showcase.** If you run a plugin, theme, or tool - a Show & Tell space is a natural place for your users to share what they've built with it. Portfolio posts, finished projects, before-and-after screenshots. The community browses, votes, and learns from each other.

**Work in progress.** Some communities thrive on the "here's what I'm working on" post. Not a finished tutorial - something in progress, inviting feedback. Show & Tell is better for this than a forum thread because it reads as lower-stakes. People share earlier, get feedback earlier.

**Team updates.** For internal or semi-internal communities, Show & Tell is a lightweight way for team members to share progress without a full meeting or announcement. A short post with a screenshot, a quick update on a deliverable. Lower friction than a formal post.

---

**How to add a Show & Tell space**

Go to Jetonomy > Spaces > Add New. Set the space type to Show & Tell. Everything else works the same as any other space type.

[Create a Show & Tell Space] [LINK-CREATE-SPACE]

See you in three days for a note on Pro.

The Wbcom Designs Team

---

## Email 3 - Day 7: Jetonomy Pro 1.4.2 - Webhooks Back Online, Multisite Ready

### Subject Line Options

1. Jetonomy Pro 1.4.2: webhooks are working again
2. Pro users: the 1.4.2 reliability update you've been waiting for
3. Multisite-ready, webhook-reliable, fully updated

### Preheader Text

All 8 webhook listeners updated. Pro extensions install on every subsite. 13 contract bugs closed.

---

### Body

Hi [Name],

If you're on Jetonomy Pro, here's what 1.4.2 means for you specifically.

---

**Webhooks are working again**

Pro's webhook system had a regression introduced in an earlier release where several event listeners stopped firing. In 1.4.2, all 8 webhook listeners have been updated and tested. Webhooks for new posts, accepted answers, new members, flag events, and idea status changes are all firing correctly.

If you were routing events to Slack, Discord, Zapier, or a custom endpoint and the notifications stopped arriving, update to 1.4.2 and they will resume.

---

**Pro extensions install on every multisite subsite**

When you network-activate Jetonomy on a multisite, Pro now installs its extensions on every subsite automatically - both the ones that exist at activation time and the ones created afterward. You no longer need to manually configure each subsite separately.

---

**13 contract bugs closed**

1.4.2 closes 13 bugs where a feature was documented or expected to behave in a certain way but did not. These are not crash bugs - they are cases where the wrong thing happened in a way that took time and support tickets to diagnose. Each one is fixed.

---

**Accessibility improvements**

- Private messaging composer is fully translatable (all strings have proper i18n wrappers)
- Pattern-input fields have correct aria-labels
- In-product modals handle focus correctly

---

**Update Jetonomy Pro now:**

[Update to Jetonomy Pro 1.4.2] [LINK-PRO-DOWNLOAD]

Not on Pro yet? Here's an honest look at whether it's right for you:

- If your community has fewer than 50 active members: the free plugin handles everything. Come back when you're ready.
- If you're routing events to external tools (Slack, Zapier, Discord): Pro webhooks are back and reliable in 1.4.2.
- If you run a multisite network: Pro + multisite is now a solid setup.
- If moderation is taking significant time: Pro's AI moderation layer (Ollama-compatible for self-hosted) handles the volume.

[See Jetonomy Pro features and pricing] [LINK-PRO-PAGE]

Questions? Reply to this email.

The Wbcom Designs Team

---

## Notes

**Suppression rules:**
- Stop sending after purchase at any point in the sequence
- Do not re-send to users who already received and completed a previous launch sequence

**Personalization:**
- `[Name]` from `user_meta.first_name`, fallback "there"
- `[LINK-CREATE-SPACE]` - link to the Jetonomy > Spaces > Add New admin page
- `[LINK-PRO-DOWNLOAD]` - link to the Pro plugin download in the customer account
- `[LINK-PRO-PAGE]` - https://wbcomdesigns.com/downloads/jetonomy-pro/
