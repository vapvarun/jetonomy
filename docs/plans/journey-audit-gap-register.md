# Jetonomy Journey Audit — Consolidated Gap Register

> **STATUS as of 1.4.4 (current).** Current spec: `journey-map-1.4.4.md`. Nearly everything
> in this register shipped on `1.4.4-dev`:
> - **Blockers — all fixed:** private-post search leak `ff07b20`; dead listing-vote + invisible
>   arrows `e3278ed`/`7ba80c0`; feed page-composer `7e17eb0`/`52568cc`.
> - **Cards — all fixed:** C1 i18n `97e2cb9`; C2 mod parity `dc7f975`/`a4843c8`/`ceceb4d`;
>   C3 author search `2fc5e73`/`f2a9b79`; C4 flag indicator `c11ab51`.
> - **Majors — fixed:** downvote-notif `39265de`, feed-downvote `34e9b1e`, profile N+1 `a76f61d`,
>   leaderboard period `feea957`, community-title `91ceeed`, join-request notifs `5052277`,
>   trust-unlocks `efff127`; dark-mode propagation + mobile headers + avatar `ca04a4e`.
> - **Still TODO (net-new features, own cards):** invite-link admin UI; "is my community ready?"
>   checklist. **Open Low items:** see `journey-map-1.4.4.md` "Remaining minor items".
> Everything below is the ORIGINAL audit (historical); use the journey map for current state.

Date: 2026-05-20. Branch: 1.4.4-dev. Method: 4 parallel code-grounded journey audits
(owner first-run, owner moderation, member participate, member find). Source files:
`journey-audit-owner-firstrun.md`, `-owner-moderation.md`, `-member-participate.md`,
`-member-find.md`. All findings cite file:line; the two blockers below were re-verified
by hand.

## Headline

The audit **validated all 4 open Bugs cards** but surfaced **2 blockers no card covered** —
one a privacy/security leak. The journey lens was worth it: we'd have shipped 1.4.4 with a
private-post leak and broken listing-page voting.

---

## BLOCKERS — fix before 1.4.4 ships (verified by hand)

| # | Gap | Evidence | Journey |
|---|-----|----------|---------|
| B1 | **Search leaks private posts.** When any advanced filter (date/author/tag/sort) is active, `search.php` runs its own `$wpdb` query whose WHERE is only `status='publish'` — no `is_private` guard, no space-visibility/membership check. Any logged-in member adding a filter receives others' private posts + posts in private/hidden spaces. The REST controller guards this correctly (`class-search-controller.php:202-210`); the template's direct path bypasses it. | `templates/views/search.php:48-90` (no `is_private` anywhere in file) | Member find — **SECURITY** |
| B2 | **Voting from any space listing is dead.** The up/down controls on the listing card are `<span aria-hidden="true">` with no `data-wp-on--click` and no `data-post-id` — decorative only. Members can only vote after opening a thread. | `templates/partials/post-card.php:42-44` vs working pattern `single-post.php:488-507` | Member participate |

---

## MAJOR — broken or missing journey-critical (1.4.4 candidates)

Feed / participation:
- **Feed posting still blocked on the full new-post page.** `submitNewPost` hardcodes `requireTitle: true` regardless of space type, so a feed status update is rejected client-side before it reaches the server (which now derives the title). The embed path (line 2538) already does it right. NOTE: this is the *other half* of the feed bug — my server-side fix unblocked REST/inline, but the page path still blocks. `view.js:2408`.
- **Downvote missing on feed cards** — only upvote rendered (violates "respect negative voices" rule). `feed-card.php:58-66`.
- **Downvote triggers an encouraging "voted on your post" notification** — `jetonomy_after_vote` fires for -1 and the notifier doesn't check value. `class-votes-controller.php:163`, `class-notifier.php:414`.
- **Join-request outcome never notified** — no listener for approved/denied; member must revisit the space URL. `class-notifier.php`.

Moderation (maps to cards C2/C4):
- **Close/Reopen topic [C2, bigger than carded]** — `/posts/{id}/close` route + closed display exist, but: no UI trigger, **no `reopen()` method or inverse route at all**, and the closed-composer guard locks **mods** out too (no staff-reply path). `class-post.php:590`, `single-post.php:757-760`.
- **Accept-answer mod-gated in UI [C2]** — REST allows mods (`close_posts`) but `reply-card.php:172-178` shows Accept only to the post author.
- **Un-accept missing entirely [C2]** — no route/action/button; accepts are permanent from the frontend.
- **No flag indicator while browsing [C4]** — `flag_count` not on the post payload; browsing surface and mod queue are disconnected. `class-posts-controller.php:1024-1062`.
- **Ban/silence has no frontend UI** — wp-admin only.
- **Frontend mod dashboard shows only flags, not pending post/reply counts.**
- **No bulk-select in the frontend mod queue.**
- **Pro auto-rule edits bypass `jetonomy_check_content`** — update path has no hook call.
- **Admin flags table shows `post #123` with no excerpt/link** — mod can't triage without clicking through.

Find / identity:
- **Search author filter has no UI control [C3]** — API + `/users/suggest` typeahead ready; the Filters form renders only date/tag/sort. (Author must be a NAME typeahead, not a raw id.) `search.php:194-220`.
- **8 `view.js` i18n keys missing from PHP localize [C1]** — `voteFailed`, `reportPlaceholder`, `reportReplyPrompt`, `reportUserPrompt`, `reportUserPlaceholder`, `madePrivate`, `madePublic`, `failedTogglePrivate` → English leaks for non-English members. `class-template-loader.php:257-333`. (header.js is actually complete — correction to earlier assumption.)
- **Leaderboard `?period` ignored by REST + no UI pills** — `class-leaderboards-controller.php:93-97`.
- **Profile N+1** — `Space::find_by_slug()` per row in Posts/Replies/Votes tabs (20+ queries/page). `user-profile.php:249,308,496`.
- **Search result count shows page slice, not DB total.**
- **No tag subscriptions** — object_type enum is space/post only.

Owner first-run:
- **Community title hidden** — settings say "main heading" but `home.php:74-76` wraps the `<h1>` in `screen-reader-text` (invisible to sighted users). Contradiction.
- **Trust thresholds have no "what this unlocks" column** — owner can unknowingly block member space-creation. `settings.php:307-359`, data exists at `class-trust-levels.php:30-90`.
- **No invite-link admin UI** — REST exists (`/spaces/:id/invite`), no wp-admin surface.
- **No "is my community ready?" check** — owner can go live with demo data showing / email unconfigured.

## MINOR / POLISH (defer or batch)
setup-wizard slug preview hardcodes `community/`; re-running wizard duplicates category/space;
rate-limits live under Permissions not Anti-Spam; move/merge prompt for raw integer IDs;
reputation card lacks a purpose sentence; admin space form drops the type descriptions the
wizard shows; email-logo buried in Email tab; import hint copy misleading.

---

## How the 4 open Bugs cards map on

| Card | Audit verdict |
|------|---------------|
| C1 i18n sweep (9876871333) | Confirmed major; exact 8 view.js keys identified (+ other files per card). |
| C2 mod parity (9894927373) | Confirmed — but **bigger**: close needs a `reopen()` method + inverse route + mod-reply-on-closed, not just a trigger. |
| C3 advanced search (9720677262) | Confirmed; author filter is the only UI gap — must be a NAME typeahead. **But B1 (leak) is the real priority on this surface and the card never mentioned it.** |
| C4 flag-icon (9910490603) | Confirmed; indicator must be **actionable** (link to queue/resolve), not just a count. |

## Recommended fix sequence (for discussion — no code yet)

1. **Blockers first, this release:** B1 private-post leak (security), B2 listing-vote wiring,
   and the feed page-path `requireTitle` (completes the half-shipped feed fix). These are
   small, verified, and ship-stoppers.
2. **The 4 cards, with audit corrections:** C2 (full close/reopen + accept gate + un-accept),
   C4 (actionable flag indicator), C3 (author name-typeahead), C1 (i18n keys + .pot).
3. **High-value majors not yet carded:** downvote notification suppression, join-request
   notifications, feed downvote, leaderboard period, profile N+1, community-title visibility,
   trust-unlocks column, invite-link admin UI, readiness check. File these as new cards.
4. **Minor/polish:** batch into a cleanup card.

Open question: do blockers + the half-shipped feed fix go into THIS 1.4.4, with the rest as
1.4.5 — or hold 1.4.4 for the full card set too? Recommend: blockers + feed in 1.4.4 now,
cards + majors as a planned 1.4.5 so the security fix isn't gated on feature work.
