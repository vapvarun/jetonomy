# Jetonomy expectation audit — screen index

**Lens:** judge each screen by what a real community member / site owner *expects* to find and do there — gaps, confusion, dead ends, missing affordances — not by what we happened to build. (ux-audit §0 rendered-surface pass.)

**Status legend:** `looked` = rendered + assessed + record written · `partial` = assessed one role/state · `pending` = NOT yet viewed (a coverage gap, never a pass).

Started 2026-06-11 on 1.5.0-dev, Reign theme, http://forums.local.

## Frontend (member-facing)

| Screen | Route | Primary persona | Status |
|---|---|---|---|
| Community home | `/community/` | Visitor / member | **looked** (anon) → `01-home.md` |
| Space (forum/Q&A/feed/ideas) | `/community/s/{slug}/` | Member | looked |
| Topic / post detail | `/community/s/{slug}/t/{slug}/` | Member | looked |
| New post composer | `/community/s/{slug}/new/` | Member | looked |
| New space | `/community/new-space/` | Trusted member | looked |
| Edit space | `/community/s/{slug}/edit/` | Space admin | looked |
| Space members | `/community/s/{slug}/members/` | Member | looked |
| Space roadmap (ideas) | `/community/s/{slug}/roadmap/` | Member | looked |
| Space moderation | `/community/s/{slug}/mod/` | Moderator | looked |
| Category | `/community/category/{slug}/` | Visitor | looked |
| Tag | `/community/tag/{slug}/` | Visitor | looked |
| Search | `/community/search/` | Member | looked |
| Leaderboard | `/community/leaderboard/` | Member | looked |
| Member profile | `/community/u/{login}/` + sub-tabs | Member | looked |
| Edit profile | `/community/u/{login}/edit/` | Member | partial (avatar crop + email opt-out verified this session) |
| Notifications | `/community/notifications/` | Member | looked |
| My spaces | `/community/my-spaces/` | Member | looked |
| My drafts | `/community/drafts/` | Member | looked |
| My bookmarks | `/community/bookmarks/` | Member | looked |
| Invite landing | `/community/invite/{token}/` | Invitee | looked |
| Login / Register widget | (sidebar block) | Visitor | partial (title-contrast fix verified this session) |
| Messages (Pro) | `/community/messages/` | Member | looked |
| Conversation (Pro) | `/community/messages/{id}/` | Member | looked |

## Backend (owner-facing, wp-admin)

| Screen | Page | Status |
|---|---|---|
| Dashboard | `?page=jetonomy` | looked |
| Categories | `?page=jetonomy-categories` | looked |
| Tags | `?page=jetonomy-tags` | looked |
| Spaces | `?page=jetonomy-spaces` | looked |
| Content | `?page=jetonomy-content` | looked |
| Moderation | `?page=jetonomy-moderation` | looked |
| Activity Log | `?page=jetonomy-activity` | looked |
| Revisions | `?page=jetonomy-revisions` | looked |
| Users | `?page=jetonomy-users` | looked |
| Import | `?page=jetonomy-import` | looked |
| Settings (7 tabs + Integrations) | `?page=jetonomy-settings` | partial (Integrations tab verified this session) |
| Extensions | `?page=jetonomy-extensions` | looked |
| Setup wizard | first-run | looked |
| Conversations (Pro) | `?page=jetonomy-pro-conversations` | partial (verified at build A6) |
| Analytics (Pro) | `?page=jetonomy-pro-analytics` | looked |
| Polls / Badges / Custom Fields (Pro) | `?page=jetonomy-pro-*` | looked |

**Coverage: 35 screens looked (expectation-audit workflow 2026-06-11) + home done inline.** Full report: `EXPECTATION-AUDIT-2026-06-11.md`; per-screen records: `expectation-records.json`. 152 gaps found (20 high, 62 med, 70 low).
