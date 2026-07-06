# Frequently Asked Questions

Quick answers to the questions we hear most, grouped by topic. Each answer links to the full guide so you can dig deeper. Answers cover both the free Jetonomy plugin and Jetonomy Pro (the free plugin is the documentation home for both).

## Getting Started

**What are the server requirements?**
Jetonomy needs WordPress 6.7 or newer, PHP 8.1 or newer, and MySQL 5.7 or higher (or MariaDB 10.4+). See [Installation](../getting-started/01-installation.md).

**How do I set up my first community?**
Activate the plugin, run the setup wizard, then create your first category and space. The [Setup Wizard](../getting-started/02-setup-wizard.md) and [Your First Community](../getting-started/03-first-community.md) guides walk through it end to end.

**Will Jetonomy match my theme?**
Yes. Jetonomy reads WordPress theme.json presets and adopts your theme's brand color, typography, and radius automatically, with a dedicated bridge for BuddyX, BuddyX Pro, Reign, Astra, Kadence, GeneratePress, and Blocksy. See [Theme Compatibility](../integrations/07-theme-compatibility.md).

**Can I embed the community on a regular WordPress page?**
Yes. Jetonomy ships eight Gutenberg blocks and eight shortcodes, plus a "Compose Topic" block and `[jetonomy_compose_topic]` shortcode so members can start a discussion from any page. See [Embedding the Composer](../discussions/10-embedding-the-composer.md) and [Shortcodes, Widgets & Blocks](../developer-guide/04-shortcodes-widgets-blocks.md).

## Spaces & Discussions

**What space types can I create?**
Four: Forum for general discussion, Q&A for questions with an accepted answer, Ideas for roadmap-style voting, and Feed for social-style posts. See [Space Types](../spaces-and-categories/02-space-types.md).

**Can members create and edit spaces from the front-end?**
Yes. Space owners and moderators can create and edit a space from the front-end, including title, type, visibility, join policy, category, and the "Require moderator approval for new posts" option. See [Create a Space from the Front-end](../spaces-and-categories/07-front-end-create-space.md) and [Edit a Space from the Front-end](../spaces-and-categories/08-front-end-edit-space.md).

**What is the difference between a Private space and a Hidden space?**
A Private space is still discoverable in listings and search, with a Join or Request to Join button, but only members can read its posts. A Hidden space appears in no listing, search, or navigation, and members reach it only by invite link. See [Membership & Join Policies](../spaces-and-categories/03-membership-policies.md).

**How are join requests approved?**
When a space uses the Approval Required join policy, requests go to space moderators and admins, who can approve or decline them from the space Members page on the community front-end or under Jetonomy -> Moderation -> Join Requests in wp-admin. Approving admits the member and notifies them by email. See [Membership & Join Policies](../spaces-and-categories/03-membership-policies.md).

**Does each space have an RSS feed?**
Every public space publishes an RSS 2.0 feed at `/community/s/{slug}/feed/`, auto-discovered by feed readers. Private and hidden spaces return a 404 so gated content never leaks. See [Space RSS Feeds](../discussions/11-space-rss-feeds.md).

**Can I save a draft or schedule a post?**
Yes. The composer lets members save a post as a draft to finish later, or schedule it to publish automatically at a future date and time. See [Drafts & Scheduled Posts](../discussions/05-drafts-scheduling.md).

## Moderation & Trust

**How do trust levels work?**
Members move through six trust levels, 0 to 5. Levels 1 to 3 are earned automatically when a member meets configurable thresholds; levels 4 and 5 are granted manually by an admin. See [Trust Levels](../moderation-and-trust/01-trust-levels.md).

**How do members report bad content, and where does it go?**
Members flag a post or reply, and the flag lands in the moderation queue for moderators to review and act on. See [Flagging & Reporting](../moderation-and-trust/02-flagging-reporting.md) and the [Moderation Queue](../moderation-and-trust/03-moderation-queue.md).

**Can I hold new posts for approval in a specific space?**
Yes. Turn on "Require moderator approval for new posts" on the space's settings, and new posts wait in the moderation queue until a moderator approves them. See [Edit a Space from the Front-end](../spaces-and-categories/08-front-end-edit-space.md).

**What anti-spam protection is built in?**
The free plugin supports invisible CAPTCHA (Google reCAPTCHA v3 or Cloudflare Turnstile), Akismet, and rate limiting, with trusted members exempted automatically. Jetonomy Pro adds AI-based background spam review. See [Anti-Spam](../moderation-and-trust/04-anti-spam.md) and [AI Integration](../pro-features/13-ai.md).

## Pro & Licensing

**What is the difference between free and Pro?**
The free plugin delivers the full community (spaces, discussions, moderation, trust, search, integrations, mobile API). Jetonomy Pro adds extensions such as private messaging, polls, reactions, custom badges, advanced moderation, analytics, webhooks, and AI. See [Getting Started with Pro](../pro-features/00-getting-started-pro.md).

**How do I activate my license and turn on Pro extensions?**
Paste your key under Jetonomy -> Settings -> License and click Activate, then enable each extension you want on the Extensions tab. See [Getting Started with Pro](../pro-features/00-getting-started-pro.md) and [License](../admin-settings/14-license.md).

**If I disable an extension, do I lose its data?**
No. Disabling an extension stops the feature and unregisters its hooks but preserves its tables and stored options; re-enabling restores everything. Only uninstalling the plugin removes stored data. See [Extensions](../admin-settings/13-extensions.md).

**Does the AI extension lock me into one provider or a runaway bill?**
No. The AI layer is multi-provider (OpenAI, Anthropic, self-hosted Ollama, or any OpenAI-compatible endpoint) with a fallback chain, and every cloud provider can be given a monthly spend cap in USD that stops requests once exceeded. See [AI Integration](../pro-features/13-ai.md).

## Mobile App

**Is there a mobile app?**
Yes. Jetonomy has an open-source iOS and Android app built with Expo. Use it as-is or publish your own branded build from the same code. See [Mobile App Overview](../mobile-app/00-mobile-app-overview.md) and [Get the App](../mobile-app/03-get-the-app.md).

**How do members sign in to the app?**
Through WordPress Application Passwords, a secure feature built into WordPress core, not JWT. The app password is separate from the member's real password and can be revoked at any time. See [Connect Members](../mobile-app/02-connect-members.md).

**Can the app send push notifications?**
Yes. Native push is delivered by the Pro Web Push extension, which registers each Expo device and requires Jetonomy 1.6.0 or newer and an HTTPS site. See [Native Push Setup](../mobile-app/04-native-push-setup.md).

## Integrations

**Can I gate a space by membership or a purchase?**
Yes. Access Rules connect a space to MemberPress or Paid Memberships Pro (free), or WooCommerce and Restrict Content Pro (Pro), so access follows the member's subscription or purchase automatically. See the [Integrations Overview](../integrations/00-overview.md).

**Can I gate a space by course enrolment?**
Yes, with Jetonomy Pro. Access Rules support LearnDash, Tutor LMS, LifterLMS, Sensei LMS, MasterStudy LMS, and Learnomy (by course or membership plan), adding and removing members as their enrolment changes. See the [Integrations Overview](../integrations/00-overview.md) and [Learnomy Integration](../integrations/14-learnomy.md).

**Does Jetonomy work with BuddyPress?**
Yes. When BuddyPress Groups is active, Jetonomy pairs groups with spaces for two-way member sync and can broadcast new topics to the group activity stream; the broadcast toggle lives under Jetonomy -> Settings -> Integrations. See [BuddyPress Integration](../integrations/13-buddypress.md).

## Data & Privacy

**Do I own my community data, and can I export it?**
Yes. Everything lives in your own database. Jetonomy registers WordPress core personal-data exporters and erasers (Tools -> Export/Erase Personal Data), and all content is reachable through the `jetonomy/v1` REST API. See the [REST API reference](../developer-guide/01-rest-api.md).

**What does uninstalling remove?**
Deleting the plugin runs the uninstall routine, which removes Jetonomy Pro's database tables, Pro options, Pro user meta (every `jetonomy_pro_` key), and Pro scheduled tasks. It is irreversible, so back up first. See [Getting Started with Pro](../pro-features/00-getting-started-pro.md).

**Can I permanently purge a conversation for a compliance request?**
Yes. Admins get a Jetonomy -> Conversations page in wp-admin where Purge permanently deletes a conversation and all its messages and read-state rows, firing an action for audit tooling. See [Private Messaging](../pro-features/02-private-messaging.md).

## Troubleshooting

**Can I migrate from another forum plugin?**
Yes. Built-in importers bring topics, replies, and members across from bbPress, wpForo (including multi-board installs), and Asgaros Forum; a members-only wpForo board imports as a private space with approval to join, so gated content stays gated. See the [Migration Overview](../migration/00-overview.md).

**I kept seeing "Cookie nonce is invalid" on a tab left open a long time. Is that fixed?**
Yes. When a long-lived tab's REST nonce expires, the bundled client fetches a fresh nonce against the still-valid login cookie and retries the request once, so members no longer lose a reply. See the [REST API reference](../developer-guide/01-rest-api.md).
