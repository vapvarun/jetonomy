# Browser + functional verification — 2026-07-04

All six fixes verified on `http://jetonomy.local` against a seeded private-space
dataset (guest / member / admin roles). Screenshots:
`~/Documents/work-artifacts/screenshots/2026-07/jetonomy-privacy-qa/`.

## Seeded dataset (left in place so QA can reproduce)

Users (password `QaPass!2026`):
- `qamember` (id 31) — member of the gated spaces below.
- `qaoutsider` (id 32) — member of nothing.
- `varundubey` (id 1) — admin.

Spaces + content:
- `qa-private` — private space; post **SecretWidgetQAXYZZY private topic** (id 248).
- `qa-hidden` — hidden space.
- `qa-private-ideas` — private ideas space; idea **PrivateRoadmapIdeaQA** (planned).
- `qa-public-ideas` — public ideas space; **PublicRoadmapIdeaQA** (public) + **SecretPrivateIdeaQA** (`is_private=1`).

Re-seed anytime: `wp eval-file` on the scratch seed (idempotent). Reply-by-Email
extension + its `enabled` setting were turned on for card 6.

## Results

| Card | Test | Result |
|------|------|--------|
| 1 | Guest `GET /spaces?visibility=hidden` / `=private` | **0** gated spaces (baseline no-filter = 20 public). Member sees own private spaces; admin sees all. **PASS** |
| 2 | Guest `/s/qa-private/members/` | Members-only gate, no roster. Member + admin see the 2-member roster. **PASS** |
| 3a | Guest `/s/qa-private-ideas/roadmap/` | Members-only gate, no ideas. **PASS** |
| 3b | Guest `/s/qa-public-ideas/roadmap/` | Planned column count **1** — only `PublicRoadmapIdeaQA`; `is_private` idea hidden. **PASS** |
| 4 | Guest search `SecretWidgetQAXYZZY` + advanced filter (votes/newest) | **0 results**. Member with same filter → **1 result** (the post). **PASS** |
| 5 | Guest head JSON-LD on private space + private post | **0** `ld+json` blocks / no title leak. Public space control still emits CollectionPage (3 blocks). **PASS** |
| 6 | Reply-by-Email (Pro) enabled | Real reply notification sent with **no fatal**; email carries `Reply-To: reply+<token>@…`; non-reply notifications get none; **forged token expiry rejected** (`invalid_token`). Old adapter filter fires clean. **PASS** |

Verification method: deterministic HTTP/curl assertions for REST + head-source
(cards 1, 5, and the guest direction of 2/3/4), Playwright MCP browser snapshots
for the rendered template pages (cards 2/3/4 guest + member/admin), and a
`wp eval` harness capturing `pre_wp_mail` for card 6. Code gates (php -l, REST-auth
audit, free+pro boot smoke) were green before this pass.
