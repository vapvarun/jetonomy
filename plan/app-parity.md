# Plugin ↔ app functionality catalogue

**Living document.** Update a row in the PR that moves it.

| | |
|---|---|
| **Plugin** | Jetonomy 1.9.0 (Free) + Pro |
| **App** | `~/apps/jetonomy-app` · `main` |
| **First built** | 2026-08-08 |
| **Live routes** | 113 (`GET /wp-json/jetonomy/v1` on `forums.local`) |
| **Called by the app** | **105 of 113** |
| **App screens** | 45 |
| **App `❌ Missing`** | **0** |
| **Owed by the plugin** | 2 (both small — below) |

## Why this file exists

The capability catalogue is **plugin-owned** (`CAPABILITIES.md`, per rule 7 of the
`wbcom-mobile-app` skill). The app never re-enumerates features — it maps coverage against this
spine. This file is the other direction: **what does the plugin still owe the app**, which the
app-side gate cannot answer on its own.

The companion release gate is `jetonomy-app/docs/FEATURE-COVERAGE.md` and blocks on any `❌ Missing`
row.

## Method

| Source | Used for |
|---|---|
| `CAPABILITIES.md` (45 rows, verified 2026-08-01 against 1.9.0) | The capability spine |
| Live `GET /wp-json/jetonomy/v1` | Ground truth for routes **and for every `enum`** |
| App `api/` (39 modules) + `app/` (45 screens) | What the app actually calls |

**Confidence:** route- and enum-level, probed live. **No runtime behaviour was exercised** — see the
honesty section at the end.

---

## Headline

Jetonomy is the fleet's reference app and its coverage shows it: **105 of 113 live routes are already
called**, and all 8 that are not are deliberate. There is no missing-feature backlog here.

**What this exercise found instead is one faithfulness bug and two plugin-side gaps** — which is the
point of building the matrix for an app that already works.

---

## The 8 uncalled routes — all correct

| Route | Why |
|---|---|
| `/auth/login`, `/auth/nonce`, `/auth/app-connect` | The app uses **WP core Application Passwords** (skill rule 1). These serve the web client. |
| `/auth/verify-email` | Emailed link, opened in a browser. |
| `/learnomy/course-discussion` | Cross-plugin integration, not a member capability of this app. |
| `/moderation/approve\|spam\|trash/{type}/{id}` | Superseded by `/moderation/bulk`, which the app uses for single and multi selection alike. |

**Do not "close" any of these.** Each would duplicate something that already works.

---

## What the plugin owes the app

### 1. The `viewer` space role is unreachable from the app — and the app is the wrong place to fix it first

The plugin has four space roles and offers all four on the web:

- validated as `array('viewer','member','moderator','admin')` — `includes/admin/ajax/class-spaces-handler.php:285`
- rendered as a Viewer option in wp-admin — `includes/admin/views/space-edit.php:256`
- accepted by the live REST enum on `/spaces/{id}/members/{user_id}`

The app's `types/space.ts:8` has it right. Its UI constant
(`components/MemberRow.tsx:11` `ROLE_ORDER`) lists only three, so a space admin cannot grant Viewer
from the app, and a member already set to Viewer on the web is rendered against a list that does not
contain their role.

**App-side fix is one line.** The plugin-side question is the durable one: nothing publishes the role
list, so every client hardcodes it and any future role repeats this. Publishing roles in
`/app/config` — from the same array `class-spaces-handler.php` already validates against — is what
stops it recurring. Same shape as Listora's post-status card.

### 2. Moderation rule types and actions are not enum-validated

`app/manage/rules.tsx` declares `['pattern','keyword','link_count']` and
`['flag','spam','trash','hold']`. The REST route does **not** declare an `enum` for either, so the
server accepts anything and the two lists can drift silently in either direction.

Every other vocabulary in this product *is* enum-validated, which is why the mechanical faithfulness
diff below could clear five of seven lists outright. Adding the enum to the route makes this row
checkable the same way — and turns a silent drift into a 400.

---

## Faithfulness — the check that earns this file

Absence is what a coverage matrix catches. **Divergence** is what it does not: a screen that exists,
works, and offers something the site never said still scores ✅. Every hardcoded list in app source
was diffed against the live route schema's `enum`:

| App list | Server enum | Result |
|---|---|---|
| Post types (6) | `POST /spaces/{id}/posts` | ✅ exact |
| Idea statuses (4) | `/posts/{id}/idea-status` | ✅ exact |
| Space visibility (3) | `/categories` | ✅ exact |
| Space join policy (3) | `/spaces` | ✅ exact |
| Flag status filters (4) | `/moderation/flags` | ✅ exact |
| Space roles (3) | `/spaces/{id}/members/{user_id}` | ⚠️ app missing `viewer` |
| Mod rule types + actions | *none declared* | ⚠️ unvalidated |

Site-owned vocabulary is otherwise respected properly: `space_label.singular/plural` comes from
`/app/config`, so the app never decides on its own authority what a Space is called. Branding,
attachment limits and all 10 feature flags are read from config too.

**A note on how nearly this went wrong.** A first pass at the route diff reported 30 uncalled routes,
including `/search` — on an app that has a `search.tsx` screen. The extraction regex was matching one
call shape and missing others. The real number is 8. A tool that produces a list is not evidence;
the list has to be checked against the thing it claims about.

---

## Correctly out of scope

| Area | Why |
|---|---|
| Site settings | The app configures nothing — every setting lives on the website and the app reflects it (skill rule 11). |
| Web-only auth paths | Core Application Passwords are the contract. |

**Note:** unlike most apps in the fleet, Jetonomy **does** ship moderation and admin screens
(13 of them). That is a considered difference, not a rule-7 violation: in a community product,
moderation is a member-adjacent activity that happens on a phone in the minutes after a
notification. It is capability-gated, not hidden.

---

## Verification status

| Level | State |
|---|---|
| Route reachability | ✅ 113 routes probed live |
| Enum faithfulness | ✅ mechanical, all 7 hardcoded lists |
| Screen inventory | ✅ 45 screens read |
| **Runtime behaviour** | ❌ **not exercised.** Nothing here proves a flow works end to end |
| Ban gate (skill rule 2) | ❌ untested — a banned member holding a valid app password must 403 on every write. This is the contract test this plugin's own permission engine exists to satisfy, and it has never been run against the app |
| Native push | ❌ provable only on a real build |

Next session: run the member flows against the live API with a real Application Password, and test
the ban gate first — it is the one with a security consequence.
