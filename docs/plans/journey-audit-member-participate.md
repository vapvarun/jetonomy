# Journey Audit: End-User / Member Participation

> **STATUS as of 1.4.4-dev (current).** Current spec: `journey-map-1.4.4.md`. Core actions are `[free]`; reactions/polls/messaging/badges/custom-fields are `[pro]` (guarded, absent in free).

Branch: `1.4.4-dev` | Auditor: code-grounded, read-only | Date: 2026-05-23

---

## What got easier on 1.4.4-dev for members

- The Edit Profile page now saves the custom profile fields the Pro Custom Fields extension renders. Whatever you type into a `jt_cf[...]` input persists through the save and reloads with the value still in place. Earlier the values silently vanished.
- The Report button on a post now reflects what you already did. If you already filed an open flag on a post, the button reads "You have reported this" and a second click shows a short toast instead of re-opening the reason form.
- A dismissed report does not leave a bruise. When a moderator dismisses a flag, the 10 points you lost at report time are returned to your reputation. Honest mod outcomes are now reflected in your score.
- Badges arrive within seconds of qualifying instead of waiting on a 6-hour cron. Posting, replying, getting your answer accepted, voting, gaining reputation, or hitting a new trust level all enqueue a per-user badge re-evaluation right then.
- Conversation actions have readable labels. The kebab menu on a single conversation now shows "Mute notifications", "Archive conversation", and "Block user" instead of icon-only rows. On `/community/messages/` the "New" button opens the compose form (recipient + textarea + Send) right there.
- Space admins (whether or not they are site admins) see an "Edit this space" link in the admin bar on any page inside a space they administer. One click goes to the front-end edit page without detouring through wp-admin.

---

## Closed in 1.4.4-dev

- **Custom profile fields used to silently drop.** Saving the Edit Profile page now persists every `jt_cf[slug]` value through a Pro endpoint that runs after the main profile save returns 200. Multi-checkbox groups are joined comma-separated to match storage.
- **Report button used to look the same whether or not you had already reported.** The button now carries `data-flagged` + the "You have reported this" label for reporters with an open flag, and a re-click yields a toast rather than the reason form. Successful first reports flip the state inline so a same-render second click also short-circuits.
- **A dismissed false report used to leave permanent reputation damage.** When a moderator dismisses a flag, the 10 reputation points the author lost at report time are restored.
- **Auto-badges used to take up to 6 hours.** Posting, replying, having an answer accepted, voting, reputation changes, and trust-level promotions now enqueue an Action Scheduler job that evaluates the acting user's badges within seconds.
- **Conversation kebab used to render as icon-only rows.** The single-conversation menu now shows "Mute notifications", "Archive conversation", "Block user".
- **The /messages/ "New" button used to lead nowhere visible.** It now opens the compose form (recipient typeahead + textarea + Send) on the same page.
- **Space admins had no fast path to edit their space.** The admin bar Community menu now shows "Edit this space" on every page inside a space, linking to the front-end edit screen.
- **Feed status without a title** was already shipped in 1.4.4: posting a status update in a feed space no longer requires a title.
- **Vote from space listing dead** was already shipped: post-card upvote / downvote on the space listing now work.
- **Feed-card downvote missing** was already shipped: feed cards now expose both upvote and downvote.
- **Downvote-as-encouragement notification** was already shipped: authors no longer get a "someone voted on your post" notification for a -1 vote.
- **Join-request outcome notifications** were already shipped: members are notified when their request is approved or denied.
- **Delete-reply from the three-dot menu** was already shipped (was a JS ReferenceError before).

---

## Open

| Gap | Severity | Why it still matters | Fix direction |
|-----|----------|----------------------|---------------|
| Report dialog still uses a single free-text `reason` of "other". A reason category picker (spam / off-topic / harassment) would aid moderator triage. | Minor | Polish; not blocking. Moderators can still read the description. | Optional radio above the existing description field. |
| TL0 default rate limit (3 posts / 10 replies / 5 votes per session) can wall off legitimate first-day contributors. | Minor | Tight defaults erode the "help people post" rule. The limit is admin-configurable today; the default itself is the gap. | Raise TL0 post default to 5 or document the trade-off in setup. |
| Private messaging requires TL1 with no inline explanation. New users at TL0 click DM and get a silent denial. | Minor | Intentional gate, but undocumented in the UI. | Tooltip / hover-card on the DM CTA: "Reach Member level to message". |

---

## Full audit table

| Moment | Customer Expectation | Current Reality | Status |
|--------|---------------------|-----------------|--------|
| **Discover & join - open space** | Click "Join Space" on an open-visibility space, become a member instantly. | `space.php` renders `.jt-join-btn`; `view.js` POSTs `/spaces/{id}/members`; `class-spaces-controller.php` adds member immediately. | Works. |
| **Discover & join - approval-required space** | Submit a join request with an optional note; see "Awaiting Approval" inline; learn when approved or denied. | `class-spaces-handler.php` fires `jetonomy_join_request_approved` / `_denied`; `class-notifier.php` listens on both and delivers in-app + email notifications to the requester. | Works (closed in 1.4.4). |
| **Discover & join - invite link** | Click an invite URL, see a confirmation page, join the space. | `class-spaces-controller.php` registers `GET /invite/{token}`; `use_invite` handler validates token + expiry + max-uses and adds the member. | Works. |
| **Read a thread** | Open a post, read body and paginated replies; nested children collapsed. | `single-post.php` paginates replies via `Reply::get_threaded`. | Works. |
| **Create a post - non-feed space** | Title required, body required, optional tags / prefix / private. | `new-post.php` wires `actions.submitNewPost`; REST `POST /spaces/{id}/posts`. | Works. |
| **Create a post - feed / status space** | No title friction; just write a status and hit Post Status. | `submitNewPost` reads `ctx.spaceType` and only requires a title outside feed spaces. | Works (shipped in 1.4.4). |
| **Feed listing - inline content** | Feed-space post body visible inline; upvote + reply count visible. | `feed-card.php` renders full body inline with both vote affordances. | Works. |
| **Feed listing - downvote affordance** | Feed card carries both upvote and downvote. | `feed-card.php` renders the down chevron mirroring `reply-card.php`. | Works (shipped in 1.4.4). |
| **Post listing - vote on post card** | Upvote / downvote a post from the space listing without opening it. | `post-card.php` now renders `<button>` elements with `data-wp-on--click` and `data-post-id`. | Works (shipped in 1.4.4). |
| **Reply - create reply** | Type in the inline composer and submit. | `view.js` submits via `POST /posts/{id}/replies` with optimistic update. | Works. |
| **Reply - reply-to / quote** | Click Reply on a reply to thread; click Quote to insert a blockquote. | `reply-card.php` exposes both; `view.js` handles each. | Works. |
| **Vote up/down on reply** | Both available; self-replies hide the downvote. | `reply-card.php` hides downvote for self; `view.js` votes via REST with revert on error. | Works. |
| **Vote notification - downvotes** | Do not receive a "someone voted" notification for a downvote. | `class-notifier.php` checks the vote value and skips the notification on -1. | Works (shipped in 1.4.4). |
| **Accept answer (QA)** | Post author marks a reply as accepted; reply gets the green badge; question shows "Answered". | `view.js` calls `POST /replies/{id}/accept`; non-authors do not see the button. | Works. |
| **Mark idea status** | Moderator sets roadmap status on an ideas post. | `single-post.php` renders the picker for moderators; `view.js` calls `POST /posts/{id}/idea-status`. | Works. |
| **React (Pro)** | Click an emoji reaction; positive AND negative reactions present. | Pro `reactions` extension keeps both `thumbsup` and `thumbsdown` at equal weight. | Works. |
| **Edit own post** | Author edits inline; formatting preserved on save. | `view.js editPost` seeds contenteditable with `bodyEl.innerHTML`; saves via `PATCH /posts/{id}`. | Works. |
| **Edit own reply** | Author edits inline; formatting preserved. | `view.js editReply` matches the post pattern. | Works. |
| **Delete own post** | Author deletes; counters decrement; redirect to space. | `view.js deletePost` confirms, calls `DELETE /posts/{id}`. | Works. |
| **Delete own reply** | Author deletes; reply removed from thread. | `view.js deleteReply` confirms, calls `DELETE /replies/{id}`. | Works (shipped in 1.4.4 - was a JS ReferenceError before). |
| **Report / flag a post - first time** | Click flag, type a reason, submit; toast confirms. | `view.js flagPost` calls `POST /flags` with `object_type='post'`. | Works. |
| **Report / flag a post - already reported** | Button tells me I already reported it before I waste effort retyping. | Button reads "You have reported this" when the viewer has an open flag; second click yields a toast. | Works (closed in 1.4.4). |
| **Report / flag a reply** | Same as post flag but on the reply. | `view.js flagReply` calls `POST /flags` with `object_type='reply'`. | Works. |
| **Bookmark a post** | Click bookmark; saved to /community/bookmarks/; icon toggles. | `view.js toggleBookmark` reconciles state from server. | Works. |
| **Save as draft** | Choose "Save as draft" from publish menu; draft appears in my drafts list. | `view.js selectSaveDraft` posts with `status='draft'`; `GET /posts/drafts` returns the list. | Works. |
| **Earn reputation** | Upvote awards configured points; downvote deducts; accepted answer awards bonus; dismissed false reports refund the author. | `class-reputation.php` defines `POINTS_MAP`; `class-flags-controller.php` refunds 10 on dismiss. | Works (refund closed in 1.4.4). |
| **Trust level progression** | Earn TL1 after the configured posts + active days + replies received; gain edit and upload. | `class-trust-evaluator.php` runs on cron and on reputation changes. | Works. |
| **Earn badge (Pro)** | Activity triggers badge evaluation; badge appears on profile within seconds; notification received. | Posting / replying / accepted-answer / vote / reputation / trust-level changes each enqueue `jetonomy_pro_badges_eval_user` via Action Scheduler. | Works (closed in 1.4.4 - used to be 6h cron). |
| **Edit own profile - core fields** | Display name, bio, avatar save and persist on reload. | `class-users-controller.php PATCH /users/me` persists the core fields. | Works. |
| **Edit own profile - Pro custom fields** | Custom fields rendered by the Pro Custom Fields extension save and persist on reload. | After the core save returns 200, the edit handler PATCHes every `jt_cf[slug]` value to a Pro endpoint. Multi-checkbox groups join comma-separated. | Works (closed in 1.4.4). |
| **Get notified - reply / mention** | Get a notification with a deep link. | `class-notifier.php` dispatches `reply_to_post`, `reply_to_reply`, `mention`. | Works. |
| **Get notified - join request outcome** | Get a notification when the request is approved or denied. | `class-notifier.php on_join_request_approved` / `_denied` handlers wired. | Works (shipped in 1.4.4). |
| **Subscriber TL0 - rate limit UX** | Hit a rate limit and see a clear, actionable message. | REST returns 400 with a translated string; `view.js` toasts it. | Works. |
| **Self-downvote block** | Author cannot downvote their own content. | REST + template enforce. | Works. |
| **Anonymous visitor read** | Read public-space posts without logging in. | `Visibility::rest_check`; `space.php` renders without login gate for public/open. | Works. |
| **Private messaging (Pro) - start a DM** | Start a DM to any member who shares a space. | Pro `private-messaging` extension; TL1 gate. | Works. |
| **Conversation (Pro) - kebab menu** | Open a conversation, click the kebab, see labelled actions. | Single-conversation kebab renders "Mute notifications", "Archive conversation", "Block user". | Works (closed in 1.4.4). |
| **Conversation (Pro) - start new from /messages/** | Click "New" on /messages/, see the compose form open. | Compose wrap renders inline (recipient + textarea + Send). | Works (closed in 1.4.4). |
| **Space admin - edit a space I administer** | One click from any space context page to the front-end edit screen. | Admin bar Community menu shows "Edit this space" on space, members, roadmap, moderation, new-post, and single-post pages. | Works (closed in 1.4.4). |
