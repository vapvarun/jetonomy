# Jetonomy Journey Audit - Consolidated Gap Register

Date: 2026-05-23. Branch: `1.4.4-dev`. Method: rollup of the four code-grounded
journey audits (owner first-run, owner moderation, member participate, member find),
the free-only journey passes (`journey-free-owner.md`, `journey-free-member.md`),
and the per-fix browser verification in `1.4.4-verification.md`. Per-journey docs
remain the source of truth for their individual entries; this file is the index.

---

## 1.4.4 release headline

The 1.4.4-dev cycle closes both blockers found by the journey audit (private-post
search leak, dead listing-page voting), every Bugs-column card that was open at
audit time (C1 i18n sweep, C2 moderator parity, C3 author search, C4 flag
indicator), the broader moderation parity work that the audit re-scoped (full
close + reopen + accept-gate + un-accept), the feed half-fix (full new-post page
path now derives titles for feed spaces, matching the embed and REST paths), and
a long tail of UX gaps that members and owners hit every day.

For customers this means:

- Members no longer see other people's private posts when they apply a date,
  tag, sort, or author filter on the search page.
- Members can vote, downvote, and accept answers from every surface they
  encounter, not just the single-post page.
- Site owners get a complete launch experience: visible community title, "what
  this unlocks" hints on the trust-level table, working background jobs on
  quiet sites, a working License page from the zip alone, and a one-click "Edit
  this space" admin-bar entry from any community page.
- Site moderators can close and reopen topics, accept and un-accept best
  answers, see flag counts inline while browsing, and trust that recurring
  moderation jobs (trust re-evaluation, expired bans, scheduled posts, activity
  pruning, notification cleanup, verification reminders) run on schedule even
  when the site is quiet.
- Reporters see a clear "already reported" state on a post they have flagged,
  and a dismissed false report restores the -10 reputation hit so bad-faith
  reporters cannot chip away at someone's standing.
- Pro extensions no longer dump "called incorrectly" notices on every admin
  page load, and auto-awarded badges land within seconds of qualifying
  activity instead of waiting up to six hours for the safety-net cron.

Five small items remain open and are documented below. None block release.

---

## Closed in 1.4.4

### Blockers - shipped

| Gap | What the customer sees now |
|-----|---------------------------|
| Private posts leaked through filtered search paths | Members searching with a date / author / tag / sort filter no longer receive other people's private posts. The template path now applies the same visibility guard the REST path always did. |
| Voting from any space listing was dead | Up and down arrows on space-listing cards now register a vote, update the score inline, and respect the same trust-level gates as the single-post view. Arrows are also visible (no longer rendered as decorative spans). |
| Feed status without a title was blocked on the full new-post page | Members posting a status update in a feed space can now hit Post and have it land. The page, the inline composer, and the Pro-poll composer all derive a title from the body. |

### Bugs-column cards - shipped

| Card | What the customer sees now |
|------|---------------------------|
| C1 i18n sweep | Vote-failure toasts, report prompts (post / reply / user), and visibility-toggle toasts appear in the member's language. Eight previously English-only keys are now translated, and the .pot is regenerated. |
| C2 moderator parity (re-scoped) | Moderators can accept and un-accept a best answer, close and reopen a topic, and reply on a closed topic when they hold the moderator capability. Members continue to see the closed-composer guard as before. |
| C3 author search | Members can filter search results by author name through a typeahead in the advanced-filters form. The REST endpoint behaves identically for headless clients. |
| C4 flag indicator | Moderators see a flag-count badge inline while browsing, linked to the moderation queue. The badge clears when the underlying flag is resolved or dismissed. |

### Owner first-run gaps - shipped

| Gap | What the customer sees now |
|-----|---------------------------|
| Community title was hidden in screen-reader-only markup | The main heading on the community home page is visible to sighted visitors at every breakpoint, matching what the setting label promised. |
| Trust thresholds had no "what this unlocks" hint | The trust-level settings table shows the capability that unlocks at each level so owners do not unknowingly block space-creation or other member powers. |
| License JS 404'd on customer installs | The License page opens and the Manage License button works from a clean zip install. No composer or npm step is required. Build script refuses to ship a zip missing the SDK. |
| WP-Cron dropped jobs on quiet sites | Trust re-evaluation, expired-ban cleanup, scheduled-post publishing, activity pruning, notification cleanup, and verification reminders fire on schedule even when no one is browsing. All six free hooks now run through Action Scheduler. |
| Space-admin had to navigate to wp-admin to edit a space | From any page inside a space they administer, owners and space-admins now see "Edit this space" in the WordPress admin bar Community menu and land on the front-end edit page in one click. |
| Admin "Unban user" confirm did not look destructive | The Banned Users modal uses the design-system tokens: ghost Cancel, danger Confirm. The visual contract matches the rest of the plugin. |

### Member participation gaps - shipped

| Gap | What the customer sees now |
|-----|---------------------------|
| Downvotes triggered an encouraging "voted on your post" notification | Downvotes no longer fire a notification to the author. Upvotes still do. The notifier checks the value before dispatching. |
| Feed cards rendered upvote only, with no downvote affordance | Feed cards render both arrows. Members can express agreement or disagreement at equal visual weight, matching the "respect both voices" rule. |
| Join-request outcome was never notified | Members who request to join a space now receive a notification when the request is approved or denied. They no longer have to revisit the space URL to check status. |
| Delete-reply from the three-dot menu threw a ReferenceError | The Delete option on the reply kebab works. The author and moderators can remove a reply from the menu without a page reload. |
| Reporter double-submitted the same report | The Report button shows an inline "already reported" state with a tooltip when the current viewer has an open flag. A second click returns a toast, not the reason form. State updates inline after a successful first report. |
| Custom profile fields dropped values on save | Typing into a Pro custom field, saving the profile, and reloading the edit page returns the same value. No more data loss. |

### Moderator daily-life gaps - shipped

| Gap | What the customer sees now |
|-----|---------------------------|
| Topic close had no reopen path; closed topics locked moderators out | Moderators can close a topic, reopen it later, and reply on it while closed. The reopen route, the inverse method, and the staff-reply path all exist. |
| Accept-answer was author-only in the UI even though REST allowed mods | Moderators see and use the Accept Answer affordance on any topic, matching the REST contract. |
| Un-accept-answer was missing entirely | A previously accepted answer can now be un-accepted by the author or a moderator. The decision is reversible. |
| No flag indicator while browsing | The post card surfaces a flag-count badge for moderators, with denormalised counts kept in sync. Flag counts are part of the post payload. |
| Award Badge picker fired two confirm popups | Awarding a badge in wp-admin opens exactly one confirm dialog. The orphan modal is gone. |
| Automatic badges lagged up to six hours | Posting, replying, getting an answer accepted, voting, gaining reputation, and trust-level promotion all enqueue an immediate per-user badge re-evaluation. The six-hour cron still runs as a safety net. |
| Report -> dismiss did not restore the -10 reputation hit | A moderator dismissing a false report restores the full -10 to the author. The "report bomb" attack is closed. |

### Member find and identity gaps - shipped

| Gap | What the customer sees now |
|-----|---------------------------|
| Search author filter had no UI control | The advanced-filters form includes an author typeahead that resolves selected names to the underlying user ID. Members no longer need a raw URL with a numeric ID. |
| Eight `view.js` i18n keys were English-only | Vote failure, report prompts, and visibility-toggle toasts render in the member's language. |
| Leaderboard `?period` was ignored by REST and invisible in the UI | The leaderboard page shows All time / This Month / This Week pills. The REST endpoint applies the same period filter so headless clients behave identically. |
| Profile tabs ran N+1 space lookups per row | Profile Posts, Replies, and Votes tabs no longer issue one extra SELECT per row. Profile pages load fast for members with hundreds of contributions. |
| Dark mode tokens leaked light values on themes that own the toggle | Dark mode propagates correctly under Reign and BuddyX. The mobile header and avatar treatment match the active theme's dark surface. |

### Cross-cutting infrastructure - shipped

| Gap | What the customer sees now |
|-----|---------------------------|
| Conversation kebab on `/messages/{id}/` rendered icon-only | The kebab menu shows Mute notifications, Archive conversation, and Block user as labelled items. |
| Messages "New" button did not open the compose pane | Clicking New on `/messages/` opens the compose form with recipient field, textarea, and Send. |
| Action Scheduler called `as_*` before its data store finished initializing | No "called incorrectly" notices on admin page loads. Pro extensions and free's `Cron::ensure_scheduled` both wait for the AS data store. |

---

## Open after 1.4.4

### Member find and identity (Low)

- Search result counts show the slice rendered on the page rather than the
  true database total. A search that matches 200 posts still displays "20
  results", which understates discoverability on busy spaces.
- Tag subscriptions are not available. A member interested in a topic area
  has to revisit the tag page manually because the subscription model only
  supports spaces and posts.
- The notifications list renders a "?" avatar on system or no-actor rows. The
  notification still reads correctly; the placeholder is a polish item.

### Owner first-run (net-new features, own cards)

- There is no admin UI for invite links. The REST endpoint exists, but an
  owner who wants to issue an invite has to use REST directly or wait for the
  wp-admin surface to land.
- There is no "Is my community ready?" launch checklist. An owner can go live
  with demo data still showing or with email unconfigured and there is no
  pre-flight surface that flags it.

### Polish

- The new-post split button "Post Topic" dropdown half looks oversized at
  390px. Members can still post; it is a visual rough edge on the smallest
  viewport.

---

## Coverage map

| Source doc | What it covers | Closed in 1.4.4 | Open after 1.4.4 |
|------------|----------------|-----------------|------------------|
| `journey-audit-owner-firstrun.md` | Wizard, branding, trust thresholds, License page, recurring jobs, admin bar, danger styling | 6 | Invite-link UI, readiness checklist (carded as net-new features) |
| `journey-audit-owner-moderation.md` | Queue, ban, accept / un-accept, close / reopen, flag indicator, badge tooling, recurring jobs | 8 | None |
| `journey-audit-member-participate.md` | Vote, feed status, downvote, join-request, delete-reply, report state | 7 | None |
| `journey-audit-member-find.md` | Search (keyword + filters + author + visibility), profile, leaderboard, i18n, dark mode | 9 | Search count, tag subscriptions, system-row avatar (Low) |
| `1.4.4-verification.md` | Cross-cutting: bundled libs, AS migration, Pro custom-field save, kebab labels, compose pane | 3 | None |

---

## Notes for the next release planner

- The journey lens was worth keeping. The two blockers it surfaced (private-
  post leak and dead listing votes) were not on any card, and one of them was
  a security issue. Run a journey pass before every release.
- "Bigger than carded" is a real pattern: C2 in particular needed a full
  close + reopen + accept-gate + un-accept package, not just a trigger
  button. Allow the audit pass to re-scope a card rather than treating the
  card text as fixed.
- Two open items (invite-link admin UI, readiness checklist) are genuine
  net-new features and belong on their own scoped cards rather than in this
  register. The polish items are batchable into a single cleanup card
  whenever the next UX sweep ships.
