=== Jetonomy - Community Forums, Q&A & Discussions ===
Contributors: wbcomdesigns, vapvarun
Tags: forum, community, discussion, Q&A, bbpress alternative
Requires at least: 6.7
Tested up to: 6.9
Stable tag: 1.9.4
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The community platform WordPress deserves. Forums, Q&A, Ideas, and social discussions - all in one fast, beautiful plugin.

== Description ==

**Jetonomy turns your WordPress site into a thriving community.** Whether you want a support forum, a Stack Overflow-style Q&A, an ideas board, or just a place for members to chat - Jetonomy handles it all without duct-taping six different plugins together.

It's built from scratch with modern WordPress tech: custom database tables (not slow post types), the WordPress Interactivity API (no jQuery, no React bundle), and a permission system that actually makes sense. The result is a community platform that feels snappy and looks great on every theme you throw at it.

If you're still running bbPress, wpForo, or Asgaros, Jetonomy ships with one-click importers for all three. Your community, your posts, your members - all move over cleanly.

---

### Four Community Types, One Plugin

**Forum** - Classic threaded discussion. Great for support, general chat, announcements.

**Q&A** - Questions get answers. Answers get voted on. The best answer floats to the top. Perfect for knowledge bases and support communities.

**Ideas** - Members submit ideas, vote, and see a roadmap. Works like UserVoice but lives inside WordPress. Ship the features your community actually wants.

**Social Feed** - Lightweight, scrollable discussion. Great for news communities or team spaces that don't need the full forum structure.

---

### Built to Be Fast at Scale

Most forum plugins store content in `wp_posts` and `wp_postmeta`. That works for 500 posts. It gets painful at 50,000. Jetonomy uses 22 purpose-built MySQL tables with proper indexes, denormalized counters, and FULLTEXT search. Your community can grow to 100,000+ posts without a performance crisis.

Every list view uses cursor-based pagination (no expensive `COUNT(*)` queries). Frequently accessed data is automatically cached with Redis or Memcached if you have them. Batch queries everywhere - no N+1 problems.

---

### A Permission System That Actually Works

Jetonomy has three layers of permissions that stack together cleanly:

1. **WordPress Capabilities** - Admins and editors get full access. Subscribers get participant access. You control the defaults.
2. **Space Roles** - Every space has its own owner, moderators, and members. A space owner can moderate their own space without being a site admin.
3. **Trust Levels (0–5)** - New users start at Level 0 (limited posting). As they participate, Jetonomy automatically promotes them to Level 1, 2, and 3 based on thresholds you configure. Levels 4 and 5 are granted manually.

The trust level system is your best spam defense. New accounts can post, but they can't spam the whole site with impunity. Regular contributors earn more abilities over time.

---

### Free Features

**Community Structure**
- Forum, Q&A, Ideas, and Social discussion types
- Categories and Spaces (sub-communities) with drag-drop ordering
- Sub-spaces for nesting communities within communities
- Join policies per space: open, request-to-join, or invite-only
- Invite links with configurable expiry dates

**Content & Editor**
- Rich text editor with bold, italic, lists, code blocks, and headings
- Drag-and-drop image upload directly into the editor
- Paste images from clipboard - they upload automatically
- @mention users with autocomplete and instant notifications
- Auto-embed YouTube, Twitter/X, Vimeo, and other oEmbed URLs
- Emoji picker for reactions in replies
- Code syntax highlighting via Prism.js (50+ languages)
- Quote-to-reply: select any text and click Reply to quote it
- Threaded replies up to 3 levels deep with collapsible threads

**Navigation & Search**
- Full-text search with instant search-as-you-type results
- Tag system with tag pages and space-level tag filters
- Keyboard shortcuts: `j`/`k` to navigate, `l` to upvote, `r` to reply, `/` to search
- Clean permalink structure: `/community/s/slug/t/post-slug/`

**Community & Reputation**
- User profiles with bio, website, location, and activity history
- User hover cards when you hover over any avatar or name
- Reputation system with points for posts, replies, votes, and accepted answers
- Trust Levels 0–5 with admin-configurable thresholds
- Leaderboard ranking community members by reputation
- Badge system (trust level badges automatic; custom badges in Pro)

**Voting**
- Upvote and downvote on posts and replies
- Accepted answers on Q&A spaces
- Idea status board (planned, in progress, complete) for Ideas spaces

**Notifications**
- In-app notification bell with unread count
- Email notifications (immediate) for replies, votes, mentions, and moderation actions
- Notification preferences per user

**Moderation**
- Flag system - members flag content, moderators review a queue
- Akismet spam detection on every post and reply
- IP address tracking for ban enforcement
- Ban and silence system: ban prevents login, silence prevents posting
- Moderator queue in wp-admin and at `/community/mod/`

**Memberships & Access Control**
- Gate spaces behind membership levels (MemberPress, Paid Memberships Pro)
- Space access rules tied to membership plans
- Works with any membership plugin via the adapter system

**Admin Tools**
- Setup wizard with two paths: start fresh or load realistic demo data
- One-click demo data cleanup
- Full content management from wp-admin (edit/delete any post or reply)
- Drag-drop category and space ordering
- Import from bbPress, wpForo, and Asgaros (batched with progress bar and resume)
- Trust level threshold configuration
- Email notification settings

**Performance**
- Object caching (auto-detects Redis/Memcached)
- Eager loading with batch queries - no N+1 database calls
- Cursor-based pagination on all REST API endpoints
- Denormalized counters (reply_count, post_count, vote_score updated on write)
- FULLTEXT indexes for instant search

**Developer Tools**
- 80 REST API endpoints at `/wp-json/jetonomy/v1/`
- 19 abilities registered with the WordPress Abilities API (WP 6.9+)
- 214 action hooks and filters for customization (102 actions, 112 filters)
- WP-CLI commands for trust level management and imports
- Template overrides: drop files in `your-theme/jetonomy/` to override any view
- RTL stylesheet included
- Translation-ready with `.pot` file

**SEO**
- Canonical URLs on every community page
- Open Graph tags (title, description, image for spaces)
- JSON-LD schema markup via Schema.org
- XML sitemap providers for spaces and posts
- Clean, SEO-friendly permalink structure

**Accessibility**
- Full WCAG accessibility audit on all templates
- Semantic HTML throughout
- Keyboard navigation support

---

### Pro Features

Jetonomy Pro extends the free plugin with power-user and enterprise features:

* **AI Integration** - Language-model-powered spam detection, content moderation, reply suggestions, and thread summaries. Pluggable providers including OpenAI, Anthropic, custom endpoints, and self-hosted Ollama (privacy-first).
* **Private Messaging** - Direct messages between members
* **Emoji Reactions** - React to posts and replies with custom emoji sets
* **Polls** - Run polls inside posts and spaces
* **Custom Fields** - Add custom fields to user profiles and posts
* **Analytics Dashboard** - See what your community talks about most, top contributors, growth trends
* **Email Digests** - Weekly/daily community digest emails
* **Advanced Auto-Moderation** - Rule-based moderation (keyword filters, rate limits, user score gates)
* **WooCommerce, Restrict Content Pro, LearnDash, Tutor LMS adapters** - Gate spaces behind courses or purchases
* **SEO Pro** - Per-space meta titles, Open Graph images, schema controls, and sitemap rules
* **Reply by Email** - Members reply to notification emails and the reply posts automatically
* **Web Push Notifications** - Browser push for replies, mentions, and moderation events
* **Webhooks** - Send HTTP POSTs to external services on community events
* **White-label branding** - Remove Jetonomy branding, use your own logo
* **Custom badge builder** - Design badges and award them manually or automatically

[Learn more about Jetonomy Pro →](https://store.wbcomdesigns.com/jetonomy-pro/)

---

### Works With Your Theme

Jetonomy reads your active theme's `theme.json` and automatically inherits fonts, colors, and spacing. No fighting with CSS specificity. No ugly white box inside your beautiful theme. The community looks like it belongs there.

Templates are fully overridable: create a `jetonomy/` folder in your theme directory and drop in any view or partial file to customize the markup.

---

== Installation ==

1. Upload the `jetonomy` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Click **Set Up Community** in the admin notice, or go to **Jetonomy → Dashboard** to launch the setup wizard

That's it. Your community lives at `/community/` (you can change the base slug in Settings).

== Screenshots ==

1. Community homepage with categories and spaces
2. Space view with topic listing and voting
3. Single post view with threaded replies
4. Q&A mode with accepted answer highlighted
5. Ideas board with roadmap view
6. Admin dashboard with statistics
7. Setup wizard - choose custom setup or demo data
8. Moderation queue with pending posts and flags

== Frequently Asked Questions ==

= Does Jetonomy replace bbPress? =

Yes. Jetonomy is a complete replacement for bbPress, built with modern WordPress architecture. It includes a one-click bbPress importer that migrates your forums, topics, replies, and user data. Your SEO is protected with 301 redirects from old bbPress URLs.

= Does it work with my theme? =

Jetonomy inherits your theme's fonts, colors, and spacing automatically using CSS custom properties and `theme.json`. The community looks native to your site, not bolted on. You can also override any template file by placing it in `your-theme/jetonomy/`.

= Will it handle my large community? =

Jetonomy was designed with scale in mind. It uses custom MySQL tables (not `wp_posts`), proper indexes, denormalized counters, and FULLTEXT search indexes. Redis and Memcached are auto-detected and used when available. Cursor-based pagination means no slow `OFFSET` queries on large datasets.

= Can I gate spaces behind paid memberships? =

Yes. Jetonomy has built-in adapters for MemberPress and Paid Memberships Pro. You can restrict any space to specific membership plans. Additional adapters for WooCommerce, Restrict Content Pro, and LearnDash are available in Pro.

= What are Trust Levels and why do they matter? =

Trust Levels are Jetonomy's built-in spam defense and community health system. New users start at Level 0 with basic posting access. As they participate (create posts, receive replies, earn reputation), Jetonomy automatically promotes them. Levels 0–3 are earned automatically based on thresholds you configure. Levels 4 and 5 (Leader and Moderator) are granted manually by admins.

The result: new spammers can't immediately flood your community, and your most trusted members earn expanded abilities over time.

= Does it have full-text search? =

Yes. Jetonomy uses MySQL's native FULLTEXT indexes for fast, relevant search results. Typing in the search bar shows instant results as you type. The search system is built on a swappable adapter pattern - developers can write custom adapters for services like Meilisearch, Elasticsearch, or Algolia without touching the plugin core.

= Can my community members moderate their own spaces? =

Yes. Every space has its own owner and can have multiple moderators. Space moderators can manage content in their space without any wp-admin access. Site admins see a global moderation queue at `/community/mod/`.

= How do I import from bbPress, wpForo, or Asgaros? =

Go to **Jetonomy → Import** in your WordPress admin. Jetonomy detects which forum plugins are installed and shows the available importers. Imports run in batches with a progress bar - you can close the browser and the import continues. If the import stops, you can resume it exactly where it left off.

= Can I create a Q&A community like Stack Overflow? =

Yes. Create a space and set its type to Q&A. Questions get posted as normal topics. Any logged-in member can post answers (replies). The original poster can mark one answer as accepted, which pins it to the top. All answers are voteable, so the community surfaces the best ones.

= Does it support right-to-left languages? =

Yes. Jetonomy includes a dedicated RTL stylesheet that loads automatically when WordPress is using a right-to-left locale. The layout, text alignment, and interactive elements all adjust correctly.

= What keyboard shortcuts does it support? =

In any post listing: `j` moves focus down, `k` moves up, `l` upvotes the focused post, `r` opens a reply, and `/` focuses the search bar. These shortcuts work exactly like you'd expect from a modern web app.

= How do invite links work? =

Space owners and moderators can generate invite links from the space's members page. Each link has a configurable expiry date. When someone visits the link, they're immediately added to the space as a member. Great for onboarding new team members or running private communities.

= Can I customize the email notifications? =

Jetonomy sends email using WordPress's built-in `wp_mail()` function, so any SMTP plugin you're using will handle delivery. Users can configure their notification preferences (which events send emails) from their profile page.

= Can developers extend Jetonomy? =

Absolutely. Jetonomy has 80 REST API endpoints (153 with Pro), 19 WordPress Abilities (WP 6.9+), 214 action hooks and filters, WP-CLI commands, and full template override support. The adapter pattern makes it straightforward to integrate external services. See the [Hooks Reference](https://store.wbcomdesigns.com/jetonomy/docs/) for the full list.

= Does it support WordPress Multisite? =

Each site in a Multisite network gets its own independent community. Network activation works. Tables are created per-site with the standard table prefix. There is no cross-site feed functionality in the free version.

== Changelog ==

= 1.9.4 - August 2026 =

Owners can rename the built-in terms and paying members now land on the space roster, alongside member-facing fixes and security hardening.

* New      - Owners can now rename Topic, Reply, Member and Category throughout the community, the same way Space could already be renamed. Set the labels under Settings, or override per site with the jetonomy_label filter.
* New      - Paying members are now added to the roster of every space their plan grants, so they appear in the Members list and count toward members-only posting. Row provenance means a lapsed plan only ever removes rows it added, never someone who joined the space directly.
* New      - Members can be identified by display name, @handle, or both, chosen under Settings > General > Member Names. Display names are not unique in WordPress; the handle always is.
* New      - Space admins can now delete or archive-and-transfer their own space from the frontend, not only from wp-admin.
* New      - Tags can now be created, renamed and deleted through the REST API, so tag management works from the app and from integrations instead of only inside wp-admin.
* New      - Space access rules can now be listed, created and deleted through the REST API, so membership gating can be configured from the app or an integration rather than only in wp-admin.
* Improve  - Members who share a display name are now told apart by their unique @handle wherever names appear.
* Improve  - The community home now tells members where posting happens instead of reading as a dead end. Reword or hide the hint with the jetonomy_home_member_hint filter.
* Improve  - Space directory cards now name the space owner.
* Improve  - A member who hits a posting rate limit now sees how many they can post and roughly when they can post again, instead of "try again later."
* Improve  - The moderation Flags queue now shows what was reported and links to it. Previously it showed only an internal id, so a moderator had to go find the content before deciding.
* Improve  - Community home and category pages now page through spaces instead of rendering every one of them, so a large directory stays fast. Set the page size with the jetonomy_spaces_per_page filter.
* Improve  - Faster threaded replies on busy topics, and a faster ban check on every anonymous submission.
* Fix      - The Settings link in notifications now scrolls to the notification preferences section instead of jumping to the top of the page, including after in-app navigation.
* Fix      - Jetonomy's own profile links (edit profile, notification settings, badges, digest) now stay on the community profile even when member profiles are pointed at another profile plugin.
* Fix      - Notifications that have no actor now read as whole sentences instead of "Someone earned a badge" and similar broken phrasing.
* Fix      - Icon-only action buttons on posts and replies now meet the 44px touch-target size on phones.
* Fix      - New topics created directly through the model or the agent interface now always take their space's type, so they no longer get the wrong type and search-engine markup after creation.
* Fix      - Sites upgrading from 1.9.2 never received the 1.9.3 content fix that matches a topic's type to the space holding it, so Q&A spaces could still publish the wrong search-engine markup. The fix now applies on upgrade.
* Fix      - The space edit form in wp-admin now has the Display order field it was missing, and the moderation queue no longer runs a separate query per row.
* Fix      - Posts held for approval now appear in the frontend moderation queue.
* Fix      - Two moderators resolving the same report at the same time no longer overwrite each other. The second now sees the report as already handled instead of silently replacing who resolved it.
* Fix      - The Role Capability Mapping table on Settings > Permissions was unreadable on phones, with role names clipped to their last few letters.
* Fix      - An expanded admin table row no longer overlaps its own cells between 482px and 782px wide.
* Fix      - Custom text for the "idea roadmap status changed" email is now saved. It was silently discarded on every save.
* Fix      - The dashboard no longer tells you to run the setup wizard when your community already has spaces and posts, which affected every site whose content arrived by import or migration.
* Fix      - Renaming Spaces in Settings now also renames it in the admin menu, instead of the menu contradicting the page.
* Fix      - Listing spaces by category through the AI-agent interface is now limited like every other listing, instead of loading the whole category.
* Security - A private reply could be read from a topic's cache by another viewer, including a logged-out visitor, because the cached thread was not keyed per viewer. Threads are now cached per viewer, so one member's permissions never decide what another sees.
* Security - The activity log is no longer readable through the AI-agent interface by members who cannot open it in wp-admin. It now requires the same permission on both.
* Security - A moderator can no longer restrict an administrator or another moderator. The moderation screen enforced this only through the REST API, so the screen's own save path let an editor lock the site owner out of their account.
* Security - Joining a space through the WordPress Abilities API now honours the space's join setting. Hidden and invite-only spaces could be joined directly, which exposed every topic inside them.
* Security - Replies arriving by email are now subject to the same rules as replies posted on the site. A banned member could still reply by email, and emailed replies could land on closed topics and in archived spaces.
* Dev      - REST user and author payloads now publish can_ban, can_block_author and any active restriction, so app clients do not offer an action that will fail.
* Dev      - Corrected the plugin manifest version and provenance stamps, which had lagged two releases behind the shipping version.
* Dev      - Consolidated the QA gate so the expected `wp jetonomy qa-actions` total is stated in one place instead of four conflicting ones.
* Dev      - Fixed the QA smoke config, which pointed at a site path that does not exist.
* Dev      - Corrected table, model, controller and template counts in the contributor documentation.
* Dev      - Removed dead documentation links and a reference to a plans directory that was never part of this project.

= 1.9.3 - August 2026 =

Removing a space keeps its content by default, members pick a display name instead of typing one, and @mentions reach members whose account was imported.

* New      - Removing a space now offers Archive or Delete permanently as separate actions, and archiving keeps every topic, reply and vote.
* New      - Permanently deleting a space asks you to type the space name first, so an irreversible action cannot be triggered by a single click.
* New      - Setting to let space admins permanently delete their own space from the community pages, off by default. In the admin area, permanent deletion follows the Manage all spaces capability.
* New      - Edit Profile collects a first name, last name and nickname, and members choose which combination to display publicly.
* New      - Spaces can be dragged into a deliberate order within a category, instead of always sorting alphabetically.
* Improve  - Permanently deleting a large space now runs in the background in batches, so it no longer times out.
* Improve  - The Revisions and Activity admin screens now collapse correctly on a phone instead of squeezing a column to a single letter per line.
* Improve  - Member post and reply counts are corrected automatically when a space is permanently deleted.
* Fix      - @mentions now resolve on a member's handle, so members whose account was imported or created from an email address are notified instead of silently missed.
* Fix      - Deleting a space left its topics, replies and member records behind in the database; both removal actions now handle a space's contents properly, and earlier damage is cleaned up automatically.
* Fix      - Deleting the account of a member who owned a space no longer leaves that space with nobody able to manage it.
* Fix      - Dragging to reorder categories on page two no longer scrambles the order of page one.
* Fix      - A Q&A or Ideas space converted from another type now produces the right search-engine markup for its posts.
* Fix      - Trust level names are now the same on the Users screen, the promotion email and the Permissions tab.
* Fix      - The bbPress and wpForo importers no longer offer a dry run they cannot honour, and the bbPress dry run no longer reports an error for every topic and reply.
* Fix      - The report control on a post now stays visible after a page refresh.
* Fix      - Invite link rows sit on one line again, and the duplicate trust level pill is gone from the profile header.
* Fix      - The Archive and Delete permanently dialogs in the admin area opened as a blank grey panel with no readable message, no visible input and no usable buttons; every Jetonomy admin screen now loads the shared style layer those dialogs depend on.
* Fix      - Dialog buttons on a phone are now full height, so the last tap before an irreversible delete is not the smallest target on the screen.
* Fix      - Permanently deleting a space reported success and removed nothing on sites where background jobs do not run; the space and its content are now deleted straight away, with only very large spaces continuing in the background.
* Fix      - The Deleting spaces setting could not be switched on. It was never saved, so the feature behind it stayed off however many times it was ticked.
* Security - Members can no longer publish under a reserved name such as Administrator, Support or your site's name, or take another member's exact name.
* Dev      - DELETE /spaces/{id} accepts mode=transfer (default) or mode=purge, and POST/PATCH /spaces accept sort_order.
* Dev      - wp jetonomy space delete accepts --mode=transfer|purge and prompts before destroying content.
* Dev      - New filters jetonomy_user_display_name, jetonomy_user_handle, jetonomy_display_name_choices and jetonomy_reserved_display_names control how members are named and identified.
* Dev      - New filter jetonomy_schema lets an extension adjust a page's primary schema.org entity instead of emitting a competing one.
* Dev      - New actions jetonomy_space_transferred and jetonomy_space_purged fire when a space changes hands or is destroyed.
* Dev      - Vote and flag state for the current viewer is published on the API, so clients stop inferring it.
* Compat   - Aligned with Jetonomy Pro 1.9.3. Install both updates together.

= 1.9.2 - August 2026 =

Read-only members stop being shown controls the server then refuses, and a member can leave a space from the same place they joined it.

* New      - A member can leave a space from the front end, the same place they joined it, instead of only being able to join.
* Fix      - A member admitted to a space by a read-only access rule was shown Vote controls, a New Topic button, the empty-space call to action and a reply box, then refused by the server on use. Each control now appears only when the member is actually allowed to use it, so a read-only member sees the content without the dead buttons.
* Fix      - A single topic in a private space now opens for a member admitted by an access rule, matching what the server allows.
* Fix      - Tag, space and author listings open again when there is no search text, instead of returning nothing.
* Dev      - Member and leaderboard payloads carry author_last_seen_at so a client can show the online dot under an avatar.
* Dev      - New AccessRule::spaces_with_level_prefix() lists every space gated on a membership-level rule, so an integration can show the spaces a member can reach by access rather than only those they have formally joined.
* Dev      - New Space::unique_slug() is the one rule for space-slug uniqueness, shared by every path that creates a space.
* Compat   - Aligned with Jetonomy Pro 1.9.2. Install both updates together.

= 1.9.1 - August 2026 =

A speed release for busy communities, and members whose role comes from another plugin can finally take part.

* Improve  - Busy communities do far less database work per page. Topic lists, sidebars, notification counts, the category tree, feeds and leaderboards are cached, and the hottest screens fetch their data in batches instead of one query per row.
* Improve  - A long topic loads only the replies on the page you are reading rather than the whole thread, so a 500-reply topic opens as quickly as a short one.
* Improve  - A space roadmap loads each column on its own instead of every idea at once.
* Improve  - New database indexes speed up topic lists, member search and access rules on large sites.
* Improve  - A member whose WordPress role comes from another plugin, such as an LMS student or a membership tier, can now take part in a space they have been added to or admitted to by a rule. Before this they could open the space and do nothing in it.
* Improve  - The Access Rules screen says who each rule type catches, gives each value box its own example instead of one generic hint, and groups membership levels under what they are.
* Improve  - Rules no longer imply they restrict people. The screen states plainly that a rule lets people in, and only visibility and join policy hold anyone back.
* Fix      - Someone admitted to a space by an access rule rather than by joining could be let in by the browser and refused by the API for the same space.
* Fix      - Space and topic counts could drift after merging topics or moving content between spaces.
* Fix      - A partly installed licence library no longer white-screens the site. The plugin loads and reports the problem instead.
* Fix      - The sidebar no longer writes a PHP warning to the error log on every page outside a space, including the community home, search, leaderboard, notifications and member profiles.
* Security - Member suggestions could be searched by email address, so a member could confirm whether an address had an account. Suggestions now match on username and display name only.
* Dev      - One serializer produces post and space payloads for every endpoint, so a feed, a search result and a single read return the same shape.
* Dev      - New GET /replies/{id}. List endpoints share one pagination contract, and post and reply payloads carry author_last_seen_at.
* Dev      - Membership adapters may return optional kind and note keys from get_all_levels() to group and describe their levels in the Access Rules picker. Adapters that do not return them are unaffected.
* Compat   - Aligned with Jetonomy Pro 1.9.1. Install both updates together.

= 1.9.0 - August 2026 =

Sell access to a space with a membership plan, and be told which plan opens it. Closes a permissions issue where an access rule could grant more than it said.

* New      - Gate a space on a membership plan, and people holding that plan get in automatically. Access starts when the plan becomes active and ends when it lapses, with nothing to sync by hand.
* New      - A visitor who cannot enter a paid space is told which plan includes it and given a link to buy it, instead of a generic "this space is private".
* New      - Connect the mobile app to your account: approve the connection once in the browser and the app gets its own access key for that account, visible and revocable any time from your profile.
* New      - Keyboard shortcuts l (upvote the focused post) and r (reply to it), which the FAQ has always documented.
* Improve  - The buy link on a gated space works with Paid Memberships Pro, MemberPress, WooCommerce Memberships, Restrict Content Pro, LearnDash, Tutor LMS, LifterLMS, Sensei, MasterStudy and Learnomy, sending people to the right plan or course. Levels that are granted rather than sold, such as a WordPress role or a CRM tag, state the requirement without a link.
* Improve  - The Access Rules screen explains what each access level unlocks, and reads your rule back in plain English as you build it.
* Improve  - An access rule now uses a single Access level rather than two settings that could contradict each other, and defaults to Participate.
* Improve  - Warn on the Access Rules screen when a rule cannot restrict anything, because the space is public or anyone can join it.
* Improve  - Admin screens are usable down to 320px, and touch targets meet 44px on phones and tablets.
* Improve  - Dropdown menus in wp-admin match the rest of the interface instead of using the operating system's own styling, where the browser supports it.
* Improve  - Every message the plugin shows can be translated, including sign-in, sign-up and password-reset feedback, and admin list statuses.
* Fix      - Search-as-you-type returns results again, and clicking one opens that topic.
* Fix      - Pressing Enter on a keyboard-focused row opens it.
* Fix      - The emoji picker can be opened from the composer toolbar.
* Fix      - Paragraph spacing is kept on topics and replies written in the composer.
* Fix      - The Conversations screen explains that messaging is dormant when another plugin handles direct messages, instead of showing a permissions error.
* Fix      - Closing the email preview with Escape returns focus to the button that opened it.
* Fix      - Activity Log and Revisions rows identify themselves on a phone rather than showing only a date or a type.
* Fix      - Roadmap columns no longer use the same colour for Planned and In Progress.
* Fix      - The "Read the full guide" link on the Users screen resolves.
* Fix      - Filled buttons rendered black text on ordinary brand colours, so New Topic, New Post and Join read wrong on a plain blue. The auto-contrast threshold moved to 0.7, and white now stays on every normal brand blue, green, purple and red.
* Fix      - Actions in a space header could not share a row with the owner's Edit space button, so each one dropped onto a line of its own. The header is a wrapping row now, centred on mobile.
* Security - An access rule could grant more than it advertised: a rule set to "Read" could record people as space admins, and running Sync Members then gave them moderation powers, including deleting other people's posts. The role a rule assigns is now capped by what the rule allows, and a membership rule can never make somebody a moderator. Rules already saved on your site are covered; no action is needed.
* Dev      - New filters: jetonomy_compose_label (the create verb per space type) and jetonomy_membership_upgrade_url (where a plan is bought). Membership adapters may implement get_level_url() to link a plan directly.
* Dev      - New filters for app connection: jetonomy_app_connect_schemes (which app URL schemes may receive a credential) and jetonomy_app_connect_bridge (which plugin owns the connect screen when several are installed). Add a scheme only for an app you ship, never a wildcard.
* Dev      - New AccessRule::spaces_for_level() answers which spaces a membership level opens, replacing thirteen hand-written copies of the same query living inside individual adapters.
* Dev      - New AccessRule::member_spaces_for_level_prefix() lists the gated spaces one member can reach, for products that want to surface them in their own account area.
* Dev      - New space_permalink() builds a space URL, so an integration in another plugin stops composing the path by hand.
* Dev      - New index rule_lookup (rule_type, rule_value) on jt_access_rules, because both lookups above were full table scans and now run on page views rather than only during provisioning. Schema milestone 1.9.1 covers sites already on 1.9.0; fresh installs get the index from CREATE TABLE.
* Compat   - Removed a finfo_close() call that PHP 8.4 deprecates, so upload checks no longer emit a notice on 8.4.

= 1.8.0 - July 2026 =

Forum imports bring attachments and hierarchy across, attachments work with or without Pro, moderation and search read the same on the app and the web, and cached data is never served stale after a change.

* New      - Import attachments and media from wpForo, bbPress, and Asgaros, not just the posts and replies.
* New      - Block another member, and report while blocking, from the mobile app and the REST API.
* New      - Delete your own account from the app (DELETE /users/me).
* New      - Generate and revoke space invite links from the front end, so an invite-only space can be run without wp-admin.
* Improve  - Attachments are shown and served by the free plugin, so a site without Pro keeps displaying its files instead of hiding them.
* Improve  - An import that cannot recover a file now lists which files were skipped instead of reporting a silent success.
* Improve  - Object cache is invalidated the moment you change a space, profile, or membership, so counts and visibility are never a few minutes stale.
* Improve  - The subscriptions API returns the title and link of what you are subscribed to, and no longer lists content that has been deleted.
* Improve  - Moderation queue items and flags name the author and reporter, honour the status filter, and page correctly on both the site-wide and per-space screens.
* Improve  - Hidden spaces can be chosen from the front-end space forms and stay hidden when you save.
* Improve  - Opening the notification bell and building the moderation queue no longer run extra queries per row.
* Fix      - Every Jetonomy page sets its own title and canonical again, instead of inheriting an unrelated page's SEO tags when another SEO plugin is active.
* Fix      - A topic's SEO title uses the title you set rather than its slug, and the title-format settings take effect.
* Fix      - Profile links in the navigation and in @mentions respect the jetonomy_profile_url filter.
* Fix      - Saving a hidden space from the front end no longer silently republishes it as public.
* Fix      - The mobile app and the website agree on which spaces exist for a given member.
* Fix      - The accent colour you pick in Appearance is applied instead of being discarded.
* Fix      - The "Create free account" and login buttons stay readable on every theme, including light brand colours.
* Fix      - The login card follows dark mode instead of rendering white on a dark page.
* Fix      - A reply notification takes you to that reply on the right page, instead of the top of the topic.
* Fix      - A blocked member's posts and replies are hidden everywhere, including the browser tab title, without dropping the innocent replies nested beneath them.
* Fix      - A deleted topic is no longer served to people who should not see it.
* Fix      - wpForo and bbPress imports keep the forum hierarchy, count progress correctly, and run in batches instead of one request that times out on large forums.
* Fix      - Migrated inline images and attachments are registered into the media library, so deleting the old forum's uploads folder can no longer break them.
* Fix      - One-click email unsubscribe links save the change; they reported success but the preference never persisted.
* Fix      - "Small" buttons render at their intended size, and the moderation Approve control renders as a button.
* Fix      - A custom accent colour stays readable on every accent-backed surface (buttons, the follow pill, level tags, avatar initials, and the new-replies banner); a light accent no longer leaves white text invisible, in every browser.
* Security - A moderator could globally ban the site administrator; banning an admin or a fellow moderator is now refused.
* Security - AI moderation logs are removed when a member is deleted or their data erased.
* Dev      - Post and reply REST payloads carry an attachments array (id, url, thumbnail, mime, name, size, type) whether or not Pro is active.
* Dev      - New WP-CLI commands "wp jetonomy privacy scan" and "wp jetonomy privacy purge-orphans" clear personal data left by accounts deleted before 1.7.1.

= 1.7.0 - July 2026 =

Foundations for two new Pro features - Anonymous Posting and File Attachments - plus avatar fallbacks, app parity, correct notification deep-links, and fuller translation coverage.

* New      - Members with no uploaded avatar now get a generated initials avatar instead of a blank placeholder, on the web and in any REST client.
* New      - User records returned by the REST API now include an avatar_display field, so a native app renders the same avatar the site does.
* Fix      - The profile-header avatar now uses the same resolver as every other avatar, so a member's initials fallback no longer disappears on their own profile.
* Fix      - BuddyPress activity-reply notifications now open the forum topic being replied to, instead of the activity feed.
* Fix      - Badge notifications now deep-link to the badges section of the recipient's profile, instead of the top of the profile.
* Fix      - Post and reply body text now share one alignment across every content type, so a reply no longer sits offset from the post it answers.
* Fix      - Vertical spacing above and below the post hashtag row is now balanced, instead of crowding the tags against the post body.
* Fix      - Compose toolbar labels, block-editor scripts, the threaded-reply toggle, and the remaining front-end script strings are now translatable.
* Security - Member media uploads are now validated against an explicit file-type allow-list with a content check, replacing behaviour where the accepted types depended on the member's role.
* Fix      - Composer and form inputs now show a single focus ring instead of a doubled outline.
* Dev      - Added the author-display resolver, the is_anonymous columns, and the upload allow-list and max-size filters that power Pro Anonymous Posting and File Attachments.
* Compat   - Aligned with Jetonomy Pro 1.7.0. Install both updates together.

Older releases (1.6.0 and earlier) are in changelog.txt, alongside this file.
WordPress.org reads the most recent entries from here; the full history stays
in the plugin so nothing is lost.
