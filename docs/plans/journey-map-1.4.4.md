# Jetonomy 1.4.4 — Journey Map (current, free/Pro-tagged, function + UX)

Date: 2026-05-20. Branch: 1.4.4-dev. This is the CURRENT journey spec — it reflects
every fix shipped this cycle and is meant to drive testing (function AND UX), not sit stale.

**How it was built:** combo-mode journey audit (4 slices) + free-only journey pass (Pro
deactivated: owner + member) + a browser UX sweep at 390px in Reign dark. Detailed
three-lens findings live in the per-slice docs:
`journey-audit-owner-firstrun.md`, `-owner-moderation.md`, `-member-participate.md`,
`-member-find.md`, `journey-free-owner.md`, `journey-free-member.md`, `journey-audit-gap-register.md`.

**Tags:** `[free]` = works in jetonomy alone · `[pro]` = needs jetonomy-pro · `[free+pro]` = free
core, Pro decorates. **Function** = does it work. **UX** = visual/mobile/dark verified at 390px+dark.

---

## Free-only health (Pro deactivated) — VERIFIED
Owner journey: **zero free-only gaps** — no unguarded `\Jetonomy_Pro\*` anywhere; every Pro
surface is `defined('JETONOMY_PRO_VERSION')`-guarded (hides or upsell, never fatals/blanks).
Member journey: all 19 core actions work standalone. The 3 minor free-only gaps found were
fixed/noted (see bottom). So a free-only customer gets a complete, unbroken product.

---

## Owner / first-run  [mostly free]
| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Activation → setup wizard redirect | free | ✓ | ✓ |
| Wizard: category + first space | free | ✓ | ✓ |
| Permissions / trust thresholds | free | ✓ | ✓ + **now shows "what this unlocks"** (shipped) |
| Reputation points config | free | ✓ (configurable — shipped) | ✓ |
| Appearance / branding | free | ✓ | ✓ |
| Community title on home | free | ✓ **now visible** (was screen-reader-only — shipped) | ✓ 390/dark |

## Owner / daily moderation  [free core + pro rules]
| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Moderation queue (pending, flags) | free | ✓ | ✓ |
| Ban / unban member | free | ✓ | ✓ (confirm popup verified) |
| Accept best answer (as mod) | free | ✓ **mod-gated fix shipped** | ✓ |
| Un-accept answer | free | ✓ **shipped** | ✓ |
| Close / reopen topic (+ mod-reply-on-closed) | free | ✓ **shipped** | ✓ |
| Flag indicator while browsing | free | ✓ **shipped** (denormalised flag_count, links to queue) | ✓ danger badge |
| Pin / move / merge topic | free | ✓ | ✓ (merge popup verified) |
| Advanced auto-moderation rules | pro | ✓ (guarded; absent in free) | n/a free |

## Member / participate  [free core + pro engagement]
| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Join space (open/request/invite) | free | ✓ | ✓ |
| Join-request outcome notification | free | ✓ **shipped** (approved/denied) | ✓ |
| Create post (forum/qa/ideas) | free | ✓ | ✓ composer 390/dark |
| Create FEED status (no title) | free | ✓ **shipped** (page + inline + Pro-poll paths) | ✓ |
| Reply | free | ✓ | ✓ |
| Vote up/down (single post) | free | ✓ | ✓ |
| Vote from space listing | free | ✓ **wiring + arrow fix shipped** | ✓ |
| Feed-card downvote | free | ✓ **shipped** (was upvote-only) | ✓ |
| Downvote notification suppression | free | ✓ **shipped** (no "voted on your post" on downvote) | n/a |
| Accept answer (asker) | free | ✓ | ✓ |
| Edit / delete own; delete reply | free | ✓ **delete-reply fix shipped** | ✓ |
| Report content | free | ✓ | ✓ |
| Reactions / polls / private messaging / badges | pro | ✓ (guarded; absent in free) | ✓ (combo) |

## Member / find + identity  [free]
| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Search (keyword) | free | ✓ | ✓ |
| Search filters: date / tag / sort | free | ✓ | ✓ |
| Search by author (name) | free | ✓ **shipped** (UI + REST headless parity) | ✓ |
| Search private-post visibility | free | ✓ **leak fixed (security)** | n/a |
| Notifications (list, filter tabs, unread) | free | ✓ | ✓ — **minor:** system rows show "?" avatar |
| Profile (tabs, stats) | free | ✓ **N+1 fixed** | ✓ 390/dark |
| Leaderboard (+ period) | free | ✓ **period fix + pills shipped** | ✓ |
| Dark mode (Reign/BuddyX) | free | ✓ **propagation fixed** (was light tokens on dark) | ✓ |

---

## Remaining minor items (Low — not blockers)
- `[free]` Notifications: system/no-actor rows render a "?" avatar (JS path from `actor_avatar=''`); should be a typed icon. Polish.
- `[free]` `/u/:login/badges/` + `/activity/` rewrite rules exist but render the Posts tab (graceful, not broken; not in free nav).
- `[free]` New-post "Post Topic" split-button: dropdown half looks oversized at 390px. Minor CSS.

## Still TODO (net-new features, their own cards)
- `[free]` Invite-link admin UI (REST exists, no wp-admin surface).
- `[free]` "Is my community ready?" launch checklist (needs product input on the checks).

## Testing guidance
Run BOTH smoke modes before tagging 1.4.4: `free` (Pro off — confirms the free-only product)
and `combo` (both — confirms Pro decoration). 1.4.4 ships a schema change (`flag_count`,
DB 1.4.4.0) — exercise the migration on a real free AND combo upgrade.
