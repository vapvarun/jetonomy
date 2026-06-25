I'll synthesize these per-screen records into a prioritized report. Let me analyze the data directly.

# Jetonomy 1.6.0 — Per-Screen Expectation Audit Synthesis

## 1. Executive Summary

The single biggest expectation gap across Jetonomy is that **interactive-looking controls don't tell the user when they're gated** — vote buttons, follow buttons, and CTAs render as live affordances to logged-out visitors but fail silently on click, with no disabled state, tooltip, or login nudge. This pattern recurs on at least five public-facing screens and is the most likely place a real first-time visitor gets confused and bounces. The second recurring theme is **empty states that name a problem but offer no way out**: across drafts, bookmarks, search, leaderboard, tag, category, profile tabs, and several admin screens, the copy says "nothing here" but provides no CTA, no "how do I create one," or a CTA wired to the wrong (or undefined) CSS class so it renders as plain text. Third, **dead ends and missing primary actions** strand users mid-task: there's no way to remove a bookmark from the bookmarks page, no way to create a post from a tag page, no path forward from the empty Import screen, and a "Conversation not found" admin page with no back link. Fourth, **owner action surfaces don't connect alerts to actions** — the admin dashboard shows a "Pending Flags" count with warning styling but no link to review them, and destructive moderation buttons fire without confirmation. Finally, the cross-cutting onboarding gaps from the home screen (no welcome/value-prop, empty and active spaces look identical, no recency signals, demo cruft public) reappear in category and space listings, confirming this is a product-wide first-run problem, not a one-page miss.

## 2. Cross-Cutting Themes

**A. Gated controls with false affordance (silent failure on click).**
Screens: Space (Forum), Space (Q&A), Space (Ideas/Roadmap), Topic/Post detail, Tag listing, Member profile, Messaging conversation list (trust-level gate).
Why a real person cares: A logged-out or low-trust visitor sees vote chevrons, a "Follow" button, or a "New" button, clicks, and nothing happens — no error, no "log in to vote." They conclude the site is broken, not that they need to sign in. This is the highest-frequency, lowest-cost-to-fix bounce trigger in the product.

**B. Empty states with no next step / broken CTA.**
Screens: My Drafts (CTA renders unstyled via undefined `jt-btn-primary`; also no "how to create a draft"), My Bookmarks, Search (no-results), Leaderboard, Tag listing, Category listing, Member profile tabs (Replies/Votes), Q&A space (generic copy, not "ask a question"), Admin Spaces, Admin Dashboard recent activity, Admin Conversations, Admin Import, Admin Activity Log, Setup wizard.
Why a real person cares: An empty screen is the moment a user most needs guidance. "No drafts yet" without telling them how to make one, or a CTA that looks like plain text, leaves them stuck at the exact point they were ready to act.

**C. Dead ends — missing the primary action the page exists for.**
Screens: My Bookmarks (cannot unbookmark from the bookmarks list — the page's entire purpose), Tag listing (cannot create a post with the tag), Admin Conversations ("not found" has no back link), Admin Import (empty state has no path to install a source or read docs), My Spaces (member cards have no actions, no leave option), Leaderboard sidebar (self-referential "View full leaderboard" link).
Why a real person cares: The user came to this page to do one thing; the page doesn't let them do it without leaving and hunting elsewhere. That's friction that reads as a bug.

**D. Recency / activity signals absent.**
Screens: Home (known), Category listing (totals only, no "active 2 days ago"), Space forum (counts but no per-post freshness beyond timestamp), Admin Users ("Last Seen" relative-only, can't spot churn), Admin Conversations (raw ISO timestamps, no relative time).
Why a real person cares: Visitors decide where to engage based on "is this alive?" Showing only lifetime totals makes a dormant space look identical to a thriving one — the same problem flagged on the home screen, now confirmed product-wide.

**E. Destructive actions without confirmation / unexpected reloads.**
Screens: Admin Moderation (Spam/Trash one-click, no confirm), Admin Users (ban/silence + trust-level save trigger silent page reloads, modal closes before confirming success), Admin Spaces (delete is a bare `href="#"` link).
Why a real person cares: An owner moderating in bulk can irreversibly delete content with a stray click, or lose track of what happened across three surprise page reloads. This erodes trust in the admin tools.

**F. First-run / onboarding orientation missing.**
Screens: Home (known), Setup wizard (no real success confirmation, no reserved-slug guard), Roadmap (no column-meaning legend), Leaderboard (no "how reputation is earned"), Admin Dashboard (passive empty state, no "create first space").
Why a real person cares: New owners and members can't form a mental model of how the product works, so they hesitate or misconfigure (e.g., a slug that collides with `/wp-admin`).

**G. No-feedback async actions (in-flight / success silence).**
Screens: Notifications (disabled button, no styling/toast), Admin Tags (bulk delete no spinner), Admin Analytics (bare "Loading…"), Admin Settings (test email), Space Members (role change/ban no spinner or success styling), new-post & new-space composers (hydration-dependent error display).
Why a real person cares: Click, nothing visibly changes, so the user clicks again or assumes it failed — double-submits and uncertainty.

## 3. Prioritized Gap List

### Quick wins (high impact, low effort — cluster these for an early 1.6.0 batch)
These are mostly one CTA wire-up, one class rename, or one guard:

| Severity | Screen | Gap | Fix direction |
|---|---|---|---|
| HIGH | My Drafts | Empty-state CTA uses undefined `jt-btn-primary` → renders as plain unstyled text, not a button | Change class to `jt-btn-fill`; one-line fix, but verify rendered button in browser. Audit all `jt-btn-primary` usages portfolio-wide. |
| HIGH | My Drafts | Empty state doesn't tell member how to create a draft; CTA points to "Browse" not "Create a post" | Repoint CTA to new-post entry, copy "Start a draft from any space." |
| HIGH | Admin Dashboard | "Pending Flags" KPI card has warning styling but isn't a link; no "Review Flags" quick action | Wrap card in `<a>` to moderation page; add "Review Flags" button to Quick Actions. |
| HIGH | Admin Conversations | "Conversation not found" is a dead end with no back link | Render the same back link that valid detail views use. |
| HIGH | Tag listing | No way to create a post with this tag from the tag page | Add "Start a discussion with this tag" CTA (prefill tag in composer). |

### High severity

| Severity | Screen | Gap | Fix direction |
|---|---|---|---|
| HIGH | Space (Ideas/Roadmap) | Vote buttons render as live `<span>` for anonymous users — false affordance, no `aria-disabled`, parent hover implies clickable | Gate vote UI: show login-linked state or `aria-disabled` + tooltip "Log in to vote." Apply same fix across all vote surfaces. |
| HIGH | My Bookmarks | No way to remove a bookmark from the bookmarks list — the page's core purpose is a dead end | Add unbookmark action to post-card in bookmarks context (REST + JS already exist). |
| HIGH | My Spaces | Member-only space cards have no actions, no role/status badge, no Leave — look unfinished | Add a footer action bar (Open, Leave) and a member/visibility badge to member cards. |
| HIGH | Admin Moderation | Spam/Trash on pending posts/replies fire destructive actions with no confirmation | Add confirm dialog (reuse `confirmAsync` already used for Unban). |
| HIGH | Admin Users | Ban/silence + trust-level save trigger silent page reloads; modal closes before confirming success | Add confirmation copy; show explicit success state; gate reload or replace with in-place UI update. |
| HIGH | Admin Spaces | New Space form has no error display element — submission errors fail silently | Add the `[data-jt-error]` element the JS already queries. |
| HIGH | Admin Extensions (Pro) | No license-tier lock state shown; owner enables a higher-tier extension and gets silent failure | Apply existing `--locked` class via `License::can_use_extension()`; show tier badge + disabled toggle. |
| HIGH | Admin Settings | License tab renders empty card if Pro hook doesn't fire; Integrations tab blank if URL-navigated without BP; nested-form data loss | Add fallback content for delegated hooks; guard Integrations content with a "requires BuddyPress Groups" message; fix nested-form HTML. |
| HIGH | Admin Import | Empty state has no path forward (no install links/docs); results container never populated — no success/error summary | Add source-install/docs links; render an import results + error summary block. |
| HIGH | Setup Wizard | Step 3 gives no real confirmation setup saved; Step 1 doesn't guard reserved slugs (`wp-admin`, `admin`) | Show explicit "Community created" confirmation; validate slug against reserved WP routes. |

### Medium severity

| Severity | Screen | Gap | Fix direction |
|---|---|---|---|
| MED | Space (Forum) | Active sort pill not visually distinct enough; long post titles wrap with no excerpt fallback | Strengthen active-pill contrast; add excerpt/preview line to post cards. |
| MED | Space (Q&A) | Generic "No posts yet" empty state, not "Ask the first question" | Pass Q&A-specific empty copy by space type. |
| MED | Space (Ideas) | "Log in to post" CTA rendered as subordinate ghost button next to sort pills | Promote to filled button; give it visual primacy. |
| MED | Topic/Post detail | Reply vote buttons rendered clickable for anonymous users without login gate (unlike post votes) | Gate reply voting to match post voting (read-only span for guests). |
| MED | New Post Composer | Error alert relies on Interactivity hydration — silent failure if JS fails; draft/schedule validation only toasts, doesn't set `submitError` | Server-render error fallback; unify schedule validation into the alert area. |
| MED | New Space | No char counters on maxlength fields; cover-image upload failure doesn't block submit | Add live counters; re-validate cover on submit or surface a persistent warning. |
| MED | Space Members | Ban success has no styled feedback; reputation hidden entirely when no profile row (ambiguous vs "0") | Style banned state; show "0 rep" fallback. |
| MED | Space Roadmap | Empty columns say generic "No ideas here yet"; no legend explaining columns; no "suggest idea" CTA | Column-specific empty copy; add a one-line legend; add suggest-idea CTA. |
| MED | Category listing | Space cards lack descriptive aria-label; show totals only, no recency | Add aria-label with type/counts; surface `last_activity_at`. |
| MED | Search | No-results state has no "clear / try again" CTA; 2-char minimum is invisible to the user | Add a reset/CTA to the empty state; show minlength hint. |
| MED | Leaderboard | Empty state has no CTA; no explanation of how reputation is earned | Add CTA + a "how rep works" link/tooltip. |
| MED | Member profile | Replies/Votes empty tabs give zero guidance (Drafts/Bookmarks do) | Add parallel "do X and it appears here" copy + CTA. |
| MED | Notifications | Disabled action buttons have no in-flight styling; no success toast after bulk/mark-all | Add `:disabled` styling for `.jt-btn`; add success feedback. |
| MED | Messaging (Pro) | Empty state has no inline "Start a conversation" CTA; right-panel empty state doesn't say how; trust-gate only errors post-submit | Add inline CTA; surface trust requirement before compose. |
| MED | Invite landing | "Invalid invite link" doesn't disambiguate broken/expired/used; login panel uses inline styles, feels like a fallback | Map the three error codes to distinct copy; use empty-state styling for the login panel. |
| MED | Admin Content | Bulk action gives no result summary before full reload; inline edit gives no input-constraint hint | Show "N items moved to trash" summary; add edit-field hint. |
| MED | Admin Activity Log | No checkbox/bulk-action pattern; user filter requires numeric ID, not search | Add row-select export; add user autocomplete. |
| MED | Admin Categories | Delete link looks identical to Edit; no indication what happens to spaces in a deleted category | Style delete as danger; explain space-orphaning consequence. |
| MED | Admin Tags | Bulk delete has no loading feedback; confirmation doesn't state post-detach consequence | Add spinner/disabled state; clarify confirmation copy. |
| MED | Admin Revisions | Back link in detail view easy to miss; "Original" revision faint with no way to view original snapshot | Make back link a prominent button; clarify/allow viewing original. |
| MED | Admin Analytics | Bare "Loading…" with no spinner; default range unlabeled; vague fetch-error; partial-load gaps silent | Add loading animation; label active range; differentiate errors + retry; flag failed sections. |
| MED | Admin Conversations | No message preview in list (can't triage); raw ISO timestamps; empty/0-message detail has no placeholder | Add last-message preview column; relative timestamps; detail empty-state message. |

### Low severity (polish — batch where cheap)

| Severity | Screen | Gap | Fix direction |
|---|---|---|---|
| LOW | Space (Forum) | Sidebar widgets vanish when empty; "Load More" gives no count/progress; guest vote buttons silent | Optional "nothing yet" hints; add "showing X of Y"; tie to the global vote-gate fix. |
| LOW | Space (Q&A) | Accepted-answer callout relies on CSS alone for prominence; "Unanswered" sort meaning undocumented | Add a structural/label marker; tooltip clarifying "no accepted answer." |
| LOW | Space (Ideas)/Roadmap | Shipped/Declined empty columns generic; mobile 1-col kanban gives no hint a 4-col board exists | Column-specific copy; mobile affordance hint. |
| LOW | Topic/Post detail | Anonymous reply-vote/follow buttons lack disabled affordance/tooltip | Fold into the vote-gate fix. |
| LOW | New Post / New Space | No inline per-field validation; similar-topics panel no loading state; icon-picker "Show more" no count; description optional unmarked | Add inline validation, loading hints, counts, "(optional)" labels. |
| LOW | Category | "General" category has no description; layout feels incomplete | Add description or a graceful no-description layout. |
| LOW | Search | Active filters stay collapsed on link-arrival; "Tag" input ambiguous | Auto-expand when filters active; clarify tag-filter label. |
| LOW | Leaderboard | Self-referential sidebar link; online-status inconsistent between sidebar and main list | Suppress link on own page; align online indicators. |
| LOW | Member profile | "Votes" tab label ambiguous; no "back to community" for anonymous viewers | Disambiguate label/tooltip; add a return link. |
| LOW | Notifications | "Select all" only covers current page | Document scope or add cross-page select. |
| LOW | My Drafts | Sidebar shows global widgets on a personal page; voting UI on unpublished drafts | Scope sidebar to personal context; hide/disable voting on drafts. |
| LOW | My Bookmarks | Generic "Browse the community" CTA; global sidebar not management-oriented | Context-aware CTA + sidebar. |
| LOW | Invite | Meta-refresh redirect feels like a crash; "Log in" CTA when user may need to register; search icon for broken link | JS redirect with "redirecting…"; add register path; use a link-broken icon. |
| LOW | Admin Dashboard | Stat-card hover implies clickability that isn't there; System Info has no update check | Make cards link or remove hover affordance. |
| LOW | Admin Content | Generic "no posts match filters" even on a truly empty forum; inline edit sprawls on small screens | Distinguish empty vs filtered; constrain edit form height. |
| LOW | Admin Moderation | "Valid (Trash)" label conflates status + action; minimal empty-state copy | Clearer label; add orienting empty copy. |
| LOW | Admin Activity Log | No filter summary; timezone/inclusivity not communicated; non-sortable columns not indicated; filter form not mobile-responsive | Add filter summary line, timezone hint, sortable affordance, mobile reflow. |
| LOW | Admin Users | "View Profile" goes to wp-admin user editor, not community profile; search scope unexplained | Link to `/community/u/:login/`; clarify search placeholder. |
| LOW | Admin Categories | Thin empty-state copy; slug help text missing | Enrich copy; add slug help. |
| LOW | Admin Settings | Test-email button no inline feedback; reset-button visibility JS-stale after save | Add inline feedback; re-sync button state on save. |
| LOW | Admin Extensions | Static category counts feel like they need a refresh; dependencies not surfaced | Inline count update; show depends_on. |
| LOW | Admin Conversations | Single-page result hides pagination with no "this is all" affordance; purge link low salience in list | Add completeness cue; strengthen destructive-action styling. |

## 4. Per-Screen Appendix

**Space (Forum) — General Discussion.** A visitor expects orientation, obvious actions, and recency signals. Present: header, sort pills, voting post list, sidebar, empty states, "Load More." Top gap: guest vote buttons and a weakly distinct active sort pill — false/unclear affordances that read as broken on click.

**Space (Q&A) — Help & Support.** A member expects questions with answered/needs-answer status and an obvious "ask" action. Present: status pills, accepted-answer callout, role-gated Accept button. Top gap: the empty state uses generic "No posts yet" copy instead of inviting a first question.

**Space (Feed) — Showcase.** A member expects identity, browsable posts, activity level, and how to contribute. Present: full feed cards, sort, login CTA, sidebar, robust state handling. Top gap: none flagged — this screen is the model the others should match.

**Space (Ideas/Roadmap) — Feature Requests.** A member expects a clear post-idea action, votable list with status, and Ideas↔Roadmap navigation. Present: tabs, status pills, kanban, sidebar. Top gap (high): anonymous vote buttons are live-looking spans with no disabled/aria state — clicking silently fails.

**Topic/Post Detail.** A member expects content, engagement affordances, sorted replies, and a clear compose path. Present: full post, threaded replies, Accept button, login prompts. Top gap: reply vote buttons aren't login-gated like post votes, so guests get a silent failure.

**New Post Composer.** A member expects clear orientation, helpful fields, obvious submit/cancel, and submission feedback. Present: type-aware fields, scheduler, captcha, validation. Top gap: error feedback depends on Interactivity hydration and drafts/schedule errors only toast — failures can go invisible.

**New Space.** A trusted member expects clear field guidance, feedback, and an escape route. Present: two-column form, icon/cover pickers, error box. Top gap: no char counters and a cover-upload failure that doesn't block submit, so users post without the cover they thought attached.

**Space Members.** A member expects a roster with roles and admin manage/ban tools. Present: paginated cards, role dropdown, ban button, rep score. Top gap: ban success has no styled feedback and reputation vanishes (vs "0") when no profile row exists.

**Space Roadmap.** A member expects orientation on status columns, a path to submit ideas, and clear interaction feedback. Present: 4-column kanban, responsive collapse. Top gap: generic empty-column copy plus no legend explaining the workflow, and no "suggest an idea" CTA from this view.

**Category Listing.** A visitor expects category orientation, browsable spaces, recency/join signals, and a real empty-state CTA. Present: header, space grid, sidebar, 404 handling. Top gap: space cards lack descriptive aria-labels and any recency signal — active and dormant spaces look identical.

**Tag Listing.** A visitor expects tagged posts, sort, and a way to read or contribute. Present: header, sort pills, post cards, pagination, empty state. Top gap (high): no way to create a post with the tag from the page — the primary "engage with this tag" action is missing.

**Search.** A member expects clear input, filters, transparent results, and helpful empty states. Present: filter pills, advanced filters, grouped results, two empty states. Top gap: the no-results state has no clear/retry CTA and the 2-char minimum is invisible.

**Leaderboard.** A member expects ranked contributors, an explanation of reputation, period filters, and profile links. Present: ranked rows, medals, period pills, sidebar. Top gap: no explanation of how reputation is earned and an empty state with no CTA.

**Member Profile.** A member expects identity, content sections, owner-gated features, and guiding empty states. Present: header, stats, badges, gated tabs. Top gap: Replies/Votes empty tabs offer zero guidance while Drafts/Bookmarks do — inconsistent and unhelpful.

**Notifications.** A member expects a filterable list, read/dismiss actions, counts, and feedback on actions. Present: tabbed filters, per-row and bulk actions, mark-all. Top gap: disabled action buttons have no in-flight styling and no success confirmation — actions feel unresponsive.

**My Spaces.** A member expects their spaces with clear actions, role/status, and next steps. Present: privileged cards with full actions; member cards bare. Top gap (high): member-only cards have no action bar, no badges, no Leave — they look unfinished and dead-end.

**My Drafts.** A member expects their drafts with edit/publish/delete and guidance to create one. Present: card grid or empty state, sidebar. Top gap (high): the empty-state CTA renders unstyled (undefined `jt-btn-primary`) and doesn't explain how to create a draft.

**My Bookmarks.** A member expects to view and manage (remove) bookmarks. Present: card list or empty state, pagination, sidebar. Top gap (high): no unbookmark control on the list — the page's core management purpose is a dead end.

**Invite Landing.** An invitee expects to know why a link failed and what to do, or a clear accept path. Present: invalid/expired/login/success states. Top gap: "Invalid invite link" doesn't disambiguate cause or offer next steps.

**Messaging Conversation List (Pro).** A member expects conversations at a glance, an obvious "start new" CTA, and mobile usability. Present: split layout, unread dots, New button, pagination. Top gap: empty state has no inline CTA and the trust-level gate only errors after the user tries to send.

**Admin Dashboard (Owner).** An owner expects at-a-glance KPIs, action paths for alerts, and empty-state guidance. Present: 6 KPI cards, activity table, quick actions. Top gap (high): the "Pending Flags" alert isn't clickable and has no Quick Action — the owner can see the problem but can't act on it.

**Admin Spaces.** An owner expects clear create/list/edit/delete with feedback and safe deletes. Present: list, filters, row actions, JS-confirmed delete. Top gap (high): the New Space form has no error element, so submission errors fail silently.

**Admin Content.** An owner expects filtering, bulk actions, inline edit, safe deletes, and meaningful empty states. Present: tabbed list, filters, bulk select, modal confirms. Top gap: bulk actions reload with no result summary, leaving owners unsure what changed.

**Admin Moderation.** A moderator expects fast review with clear actions and safeguards. Present: four tabs, AJAX actions, unban confirmation. Top gap (high): Spam/Trash fire destructive actions with no confirmation (while Unban has one).

**Admin Activity Log.** An owner expects audit visibility, intuitive filtering, result feedback, and accessible export. Present: list table, filters, CSV export. Top gap: no row-select bulk pattern and a numeric-ID-only user filter (no name search).

**Admin Users.** An owner expects member health, the ability to spot problems, and confirmed destructive actions. Present: sortable table, search/filter, trust/ban/silence. Top gap (high): destructive actions cause silent page reloads and modals close before confirming success.

**Admin Categories.** An owner expects add/list, edit/delete with confirmation, empty states, and reordering. Present: add form, list table, modal edit, drag handles. Top gap: the delete link is visually identical to edit and gives no indication what happens to a category's spaces.

**Admin Tags.** An owner expects bulk-scale tag management with feedback and safe deletes. Present: split layout, table, bulk delete with confirm. Top gap: bulk delete has no in-flight loading feedback, inviting double-clicks.

**Admin Revisions.** An owner expects an audit trail with search, readable diffs, and clear navigation. Present: list and detail modes, diff toggles, filters. Top gap: the detail-view back link is easy to miss and the original revision can't be viewed as a snapshot.

**Admin Import.** An owner expects to see sources, start a migration, track progress, and recover. Present: empty/populated/progress states, backup warning. Top gap (high): the empty state offers no path forward and the results container is never populated — no success or error summary.

**Admin Settings.** An owner expects fully populated tabs, obvious saves, and feedback. Present: sidebar tabs, grouped cards, settings-error handling. Top gap (high): the License tab can render blank if the Pro hook doesn't fire, and a nested-form bug risks silently dropping edits.

**Admin Extensions (Pro).** An owner expects to see all extensions, activation state, toggles, and license-tier visibility. Present: card grid, category filter, toggles, success notice. Top gap (high): no license-tier lock state — owners can try to enable a tier they don't have and hit a silent failure.

**Setup Wizard.** A new owner expects a guided 3-step setup with clear next actions, error recovery, and visible progress. Present: 3 steps, progress indicator, validation, success CTAs. Top gap (high): Step 3 gives no real confirmation the setup saved, and Step 1 doesn't guard reserved slugs like `wp-admin`.

**Admin Analytics (Pro).** An owner expects health metrics with clear loading/empty/error states and next steps. Present: stat cards, chart, top spaces/contributors, REST-populated. Top gap: bare "Loading…" with no spinner and a default range that isn't labeled — the owner can't tell stalled from loading.

**Admin Conversations (Pro).** An owner expects a triageable conversation list with moderation/purge, context, and clear empty states. Present: paginated list, detail view, purge with confirm. Top gap (high): "Conversation not found" is a dead end with no back link; the list also lacks a message preview for triage.