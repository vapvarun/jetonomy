# Jetonomy 1.6.0-dev - Fixed Issues (QA cards)

Posted 2026-07-04 to the **In Testing** column of the Jetonomy board
(project 46596502, card table 9706083020, column 9706083581).

All six are privacy/security fixes from the 1.6.0 audit (`../../../audit/full-audit-2026-07-04.md`).
Code verified: `php -l` + REST-auth audit + free/pro boot smoke all pass. Not yet browser-tested - that's this QA pass.

Fetch a card: `basecamp show <id>` (or open the URL). Local body: the linked `.md`.

| # | Card | Basecamp | ID | Local file |
|---|------|----------|----|-----------|
| 1 | [Fixed 1.6.0] Private/hidden spaces leaked via GET /spaces?visibility=hidden | [open](https://app.basecamp.com/5798509/buckets/46596502/card_tables/cards/10062600415) | `10062600415` | [`card-01-spaces-rest-visibility-leak.md`](card-01-spaces-rest-visibility-leak.md) |
| 2 | [Fixed 1.6.0] Space members roster shown to non-members on private/hidden spaces | [open](https://app.basecamp.com/5798509/buckets/46596502/card_tables/cards/10062600481) | `10062600481` | [`card-02-space-members-roster-leak.md`](card-02-space-members-roster-leak.md) |
| 3 | [Fixed 1.6.0] Space roadmap ideas visible to non-members (+ private ideas on public spaces) | [open](https://app.basecamp.com/5798509/buckets/46596502/card_tables/cards/10062600486) | `10062600486` | [`card-03-space-roadmap-ideas-leak.md`](card-03-space-roadmap-ideas-leak.md) |
| 4 | [Fixed 1.6.0] Search advanced filters leaked posts from private/hidden spaces | [open](https://app.basecamp.com/5798509/buckets/46596502/card_tables/cards/10062600494) | `10062600494` | [`card-04-search-advanced-filter-leak.md`](card-04-search-advanced-filter-leak.md) |
| 5 | [Fixed 1.6.0] Private space/post titles exposed in page-head JSON-LD (SEO schema) | [open](https://app.basecamp.com/5798509/buckets/46596502/card_tables/cards/10062600507) | `10062600507` | [`card-05-jsonld-head-private-leak.md`](card-05-jsonld-head-private-leak.md) |
| 6 | [Fixed 1.6.0] Reply-by-Email: fatal on every notification email + forgeable token expiry | [open](https://app.basecamp.com/5798509/buckets/46596502/card_tables/cards/10062600517) | `10062600517` | [`card-06-reply-by-email-fatal-and-token.md`](card-06-reply-by-email-fatal-and-token.md) |

## Quick fetch
```bash
basecamp show 10062600415 -m   # card 1
basecamp show 10062600481 -m   # card 2
basecamp show 10062600486 -m   # card 3
basecamp show 10062600494 -m   # card 4
basecamp show 10062600507 -m   # card 5
basecamp show 10062600517 -m   # card 6
```

## What QA is verifying
1. **/spaces REST** - `?visibility=hidden|private` no longer leaks gated spaces to guests.
2. **Space members** - roster is members/admin-only on private+hidden spaces.
3. **Space roadmap** - gated on private/hidden; private ideas hidden from non-privileged viewers.
4. **Search** - advanced filters (date/author/tag/sort) no longer leak private-space posts.
5. **SEO head JSON-LD** - private space/post titles no longer emitted for guests.
6. **Reply-by-Email (Pro)** - enabling it no longer fatals all notification emails; token expiry is signed.
