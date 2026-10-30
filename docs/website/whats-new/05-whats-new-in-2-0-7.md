---
title: "What's New in 2.0.7"
category: "whats-new"
order: 5
---

# What's New in 2.0.7

Access rules that are harder to get wrong, a membership gate that tells visitors exactly how to get in, address fields that never save a broken value, Divi Theme Builder support, and translations redone in all 10 languages.

> **Note:** Jetonomy and Jetonomy Pro are released together. Jetonomy Pro 2.0.7 requires Jetonomy 2.0.7, so update both at the same time.

## Access rules

- **Change a grant in place.** Each rule in the Access Rules list has a grant select, so you can move a rule between Read and Participate without deleting it.
- **Built to avoid accidents.** The form starts on who the rule lets in, Add Rule stays off until the rule is complete, and a rule that would open a private space to everyone asks you to confirm first.
- **Two clear levels.** Rules grant Read or Participate. The old Full level never gave a member more than Participate (moderation comes from a person's role on the Members tab), so it is removed and saved Full rules become Participate on upgrade. Nobody's access changes.
- **Retired plans are flagged.** A rule on a deleted, trashed or unpublished course, product or membership plan shows "no longer available" in the rules list, for every membership plugin.
- **Search every level.** The level picker searches the server as you type, so courses and plans past the first 500 can be chosen.

## The membership gate

- **One clear sentence for paid plans.** A space sold through a membership says "Your access starts as soon as your membership is active, and ends if it lapses."
- **A button for each way in.** When a space is included with a course and a group, each gets its own button, labelled with its kind.
- **Honest when nothing is left.** If every plan a space is included with has been deleted or unpublished, visitors see "This space is not open to join right now", never a plan id.
- **Members see why they are in.** The space header names the course or plan that gives a member access.

## Settings that never save a broken value

- Address fields (terms, privacy and support pages, logos, the default share image, space covers and avatars) refuse anything that is not a full address, say which field, and keep the value you had.
- Addresses starting with `javascript:` or `data:` are refused instead of silently clearing the image.

## Other improvements

- **Divi Theme Builder.** Global headers and footers built in the Divi Theme Builder now show on community pages, with their styles.
- **Community addresses in any case.** `/COMMUNITY/` and other case variants redirect to the lower-case address instead of showing a 404.
- **Votes.** Removing a vote takes back the reputation and the notification it gave.
- **Moderation.** The ban dialog explains what Global Ban and Silence do, and ban, silence and lift show the reason when they fail. Silenced and banned members cannot start a space.
- **BuddyNext.** A member suspended in BuddyNext is read-only in the forum, and a member blocked in BuddyNext is blocked here too.
- **Privacy.** On a private community a signed-out visitor gets no answer from any action, including polls, reactions and attachment downloads.
- **Accessibility.** Admin text meets WCAG AA contrast on every Jetonomy screen, and Edit Profile section links wrap on phones.
- **Translations.** All 10 languages were re-translated with one glossary per language and checked to 99% or more. German keeps the capital letter on nouns mid-sentence.

## What's new in Pro

- **Extensions say where to set them up.** Each extension that is on links to its settings, and switching one on names the next step.
- **One switch per extension.** Email Digest, Web Push and Reply by Email are switched on and off only from the Extensions page. If you had unticked their old Enable box, the extension is switched off on upgrade, so nothing starts on its own.
- **Reply by Email** says "Not set up yet" and what is missing until it can read replies.
- **Settings never say saved over a refused value.** Reply by Email, Email Digest, Web Push, AI, SEO Pro and the branding icon keep the stored value and name the field.
- **WooCommerce.** Subscriptions pending cancellation keep access until the term ends.
- **Polls and reactions** follow the community privacy setting.
