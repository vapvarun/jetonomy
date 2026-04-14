# Jetonomy Forum Alternative Content — Blog Drafts

**Purpose:** Three first-person marketing articles that position Jetonomy as the forum / bbPress / Discourse alternative across wbcomdesigns.com, vapvarun.com, and buddyxtheme.com. Each piece is written with a lived-in 2026 user perspective — not a marketing team's generic listicle.

**Created:** 2026-04-11
**Research basis:** SpyFu keyword data + competitive domain audit
**Target combined addressable volume:** ~7,200 monthly searches

---

## Strategic Summary

| Site | File | Action | Primary KW | Monthly vol | KD | Current rank | Goal |
|------|------|--------|------------|-------------|----|-|---|
| wbcomdesigns.com | `01-wbcom-bbpress-review-refresh.md` | UPDATE existing `/bbpress-review/` | `bbpress` | 2,800 | 7 | **#36** | Top 10 + featured snippet |
| vapvarun.com | `02-vapvarun-forum-plugin-listicle.md` | UPDATE existing `/forum-wordpress-plugin/` | `wordpress forum plugin` | 840 | 19 | **#35** | Top 10 |
| buddyxtheme.com | `03-buddyx-discourse-alternatives.md` | NEW `/discourse-alternatives-wordpress/` | `discourse alternatives` | 220 | 20 | Greenfield | Top 10 within 90 days |

**Key insight from research:** wbcomdesigns and vapvarun already rank for their primary keywords — the play is REFRESHING existing content with Jetonomy positioning, not creating new articles. buddyxtheme has zero forum content but already ranks well for "alternatives" listicles (discord, gofundme, textnow) — natural URL pattern + voice fit.

---

## Voice Guidelines (used across all three)

- **First-person, lived-in.** "I've been running communities since 2018. Here's what actually happened..."
- **Honest criticism.** Each plugin gets real pain points, not fake problems. bbPress is praised for stability and attacked for architecture. Jetonomy is pitched but also has stated weaknesses ("it's new, smaller tutorial ecosystem").
- **Earned recommendation.** Jetonomy wins by having the right architectural answers, not by being described as "revolutionary."
- **Specific numbers over vague claims.** 24 custom tables (not "many"), 48+ REST endpoints (not "full API"), 40-minute 15,000-post migration (not "fast migration").
- **Banned words:** revolutionary, game-changing, seamless, leverage, synergy, cutting-edge. Use: fast, clean, automatic, built-in, scales, adapts.

---

## Internal Linking Map

This is the cross-site linking plan. All three articles should link to each other and to Jetonomy's product/docs pages.

```
                        ┌──────────────────────────────────────┐
                        │                                      │
        ┌───────────────┤ wbcomdesigns.com/bbpress-review/     │
        │               │ (the authority piece — most links)   │
        │               └──────────────┬───────────────────────┘
        │                              │
        │                              │
        ▼                              ▼
┌───────────────────────┐    ┌──────────────────────────────┐
│ vapvarun.com/         │◄───┤ buddyxtheme.com/             │
│ forum-wordpress-      │    │ discourse-alternatives-      │
│ plugin/               │    │ wordpress/                   │
│                       │────►                              │
└───────────┬───────────┘    └──────────────┬───────────────┘
            │                               │
            │                               │
            ▼                               ▼
      ┌─────────────────────────────────────────────┐
      │ wbcomdesigns.com/downloads/jetonomy/        │
      │ (conversion destination)                    │
      └─────────────────────────────────────────────┘
```

### Specific anchors (use these for internal links)

**From wbcomdesigns bbpress-review →**
- vapvarun: "my full breakdown of WordPress forum plugins in 2026"
- buddyxtheme: "Discourse alternatives for WordPress"
- jetonomy download: "try Jetonomy free"

**From vapvarun forum-wordpress-plugin →**
- wbcom: "detailed bbPress review"
- buddyxtheme: "Discourse alternatives"
- jetonomy download: "free Jetonomy download"

**From buddyxtheme discourse-alternatives-wordpress →**
- wbcom: "bbPress review 2026"
- vapvarun: "best WordPress forum plugins"
- jetonomy download: "Jetonomy free download"

**Existing pages that should link IN to the new content:**
- wbcomdesigns.com/mighty-networks-review-features-pricing-pros-cons/ → link to bbPress review (anchor: "if you need a free WordPress alternative")
- buddyxtheme.com/top-alternatives-to-discord-to-use/ → link to new discourse-alternatives piece (anchor: "if you meant Discourse, not Discord")
- wbcomdesigns.com/plugins/buddypress/ → link to bbPress review (anchor: "bbPress alternatives")
- vapvarun.com/different-types-of-online-communities/ → link to forum plugin listicle (anchor: "WordPress forum plugins")
- vapvarun.com/categorize-your-buddypress-community-with-member-types/ → link to forum plugin listicle

---

## Deployment Checklist (per article)

Before publishing each article:

- [ ] Confirm the target URL is correct (update = same permalink; new = pick new slug)
- [ ] Update H1 and SEO title in the CMS
- [ ] Update meta description (155 char max)
- [ ] Add `lastmod` / "last updated" date to today
- [ ] Insert FAQ schema (`FAQPage` JSON-LD) for the Q&A section
- [ ] Insert Review schema for bbpress-review, `ItemList` schema for listicles
- [ ] Replace featured image (Jetonomy screenshot or split-screen bbPress/Jetonomy comparison)
- [ ] Verify internal links point to the right pages
- [ ] Add external links (Jetonomy download, docs)
- [ ] Check mobile rendering of tables (they may need horizontal scroll wrappers)
- [ ] Submit URL to Google Search Console after publishing
- [ ] Share link from Jetonomy social accounts (Twitter thread + LinkedIn post)

---

## Post-Publish Monitoring

Track weekly for 90 days after publication:

| Metric | Tool | Target |
|--------|------|--------|
| Rank for primary KW | GSC + SpyFu | wbcom bbPress-review: #36 → top 10 |
| Rank for primary KW | GSC + SpyFu | vapvarun forum-plugin: #35 → top 10 |
| Rank for primary KW | GSC + SpyFu | buddyxtheme discourse-alts: unranked → top 20 |
| Organic clicks | GSC | +100% on refreshed articles |
| Conversion (clicks to wbcomdesigns.com/downloads/jetonomy/) | GA4 | 2% click-through from refreshed articles |
| Featured snippet for "bbpress review" | Manual SERP check | Capture on wbcom |

Re-run SpyFu domain checks monthly to verify rank movement.

---

## Additional Opportunities Identified (Not Written)

Research surfaced these secondary opportunities you might want to pursue later:

1. **"bbpress vs buddypress"** (220/mo, KD 15, buddypress.org at #1) — write a dedicated comparison page on wbcomdesigns. Natural fit because wbcom is the BuddyPress extensions shop.
2. **"bbpress vs phpbb"** (220/mo, KD 0) — much easier ranking target; short comparison post.
3. **"bbpress forum examples"** (340/mo, KD 18) — showcase page with live communities running bbPress + migration case studies.
4. **"community platform"** (400/mo, KD 38, circle.so dominant) — harder to crack but larger volume; cornerstone page on buddyxtheme.
5. **"wordpress q&a plugin"** (180/mo, KD 0) — dedicated Jetonomy Q&A space type post, targets the Q&A-only audience.

These are all high-ROI follow-ups after the initial three articles prove the positioning works.

---

## Files in this folder

- `00-README.md` — this file
- `01-wbcom-bbpress-review-refresh.md` — ~3,000 words, update `/bbpress-review/`
- `02-vapvarun-forum-plugin-listicle.md` — ~3,300 words, update `/forum-wordpress-plugin/`
- `03-buddyx-discourse-alternatives.md` — ~2,800 words, new `/discourse-alternatives-wordpress/`
