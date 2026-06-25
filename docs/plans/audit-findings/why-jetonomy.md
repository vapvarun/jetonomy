# Audit fixes — why-jetonomy (3 findings)

## 1. [MAJOR] `/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/jetonomy/docs/website/why-jetonomy/01-vs-bbpress.md` — inaccurate
- **Issue:** Comparison table claims free plugin ships 24 custom DB tables; manifest enumerates 20.
- **Fix:** Change '24 tables' to '20 tables' (or '20+ purpose-built tables') to match the free manifest.
- **Evidence:** doc 01-vs-bbpress.md:17 '| Data storage | WordPress custom post types | Custom database tables (24 tables) |'. jetonomy/audit/manifest.json tables array len=20 (jt_categories, jt_spaces, jt_posts, jt_replies, jt_votes, jt_user_profiles, jt_notifications, jt_subscriptions, jt_read_status, jt_space_members, jt_tags, jt_post_tags, jt_activity_log, jt_restrictions, jt_access_rules, jt_flags, jt_revisions, jt_join_requests, jt_invite_links, jt_bookmarks). 24 matches neither free (20) nor combined free+pro (20+18=38).

## 2. [MAJOR] `/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/jetonomy/docs/website/why-jetonomy/03-scalability.md` — inaccurate
- **Issue:** Scalability page states community data lives in 24 dedicated wp_jt_ tables; free schema defines 20.
- **Fix:** Change '24 dedicated tables' to '20 dedicated tables' to match the free manifest.
- **Evidence:** doc 03-scalability.md:24 'Jetonomy stores all community data in 24 dedicated tables with the wp_jt_ prefix.' vs jetonomy/audit/manifest.json tables array len=20. Same 24-vs-20 mismatch repeated from the bbPress page.

## 3. [MINOR] `/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/jetonomy/docs/website/why-jetonomy/01-vs-bbpress.md` — missing-feature
- **Issue:** REST endpoint counts '48+ / 90+ with Pro' materially understate actual 68 free / 127 combined.
- **Fix:** Optional: update to '68+ endpoints (127+ with Pro)' to reflect current manifest counts; not required since the existing floor claims remain accurate.
- **Evidence:** doc 01-vs-bbpress.md:27 and 02-vs-wpforo.md:27 '| REST API | Limited | 48+ endpoints (90+ with Pro) |'. Manifests: free rest.endpoints len=68, pro rest.endpoints len=59, combined=127.
