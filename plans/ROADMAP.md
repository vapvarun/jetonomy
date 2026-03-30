# Jetonomy — Development Roadmap

## v1.0.0 — Foundation (Released March 2026)

The complete forum engine built from scratch for WordPress.

### Core Engine
- 22 custom MySQL tables via `dbDelta()` — no CPTs
- 15 model classes with denormalized counters
- 3-layer permission system: WP Caps → Space Roles → Trust Levels
- Cache layer with eager loading and cursor-based pagination
- Centralized activity tracker via hooks

### Frontend
- 12 template views + 6 partials (theme-overridable)
- WordPress Interactivity API for voting, sorting, polling
- CSS Custom Properties inheriting from theme.json
- RTL stylesheet support
- Dark mode via token cascade

### REST API
- 42 endpoints across 15 controllers
- Cursor-based pagination for scale
- Rate limiting per trust level

### Content & Moderation
- 4 space types: Forum, Q&A, Ideas, Feed
- Trust levels 0–5 with auto-evaluation
- Flagging, moderation queue, anti-spam
- Threaded replies up to 3 levels

### Integrations (Free)
- MemberPress adapter — membership enrollment sync
- PMPro adapter — level-based space access
- WP Roles adapter — role-based gating
- bbPress importer — forums, topics, replies, votes
- wpForo importer — forums, topics, replies, likes, profiles
- Asgaros Forum importer — forums, topics, replies

### Pro Extensions (13)
- Private Messaging, Analytics, Reactions, Polls, Custom Badges
- Custom Fields, Webhooks, Advanced Moderation, White Label
- Email Digest, Web Push, Reply By Email, SEO Pro

---

## v1.0.1 — Theme Compatibility (Released March 2026)

- `.container` → `.jt-container` rename to prevent CSS collisions
- Dynamic `--jt-container-width` from theme settings
- Sub-nav inside container, page title suppression
- Tested across 12 WordPress themes

---

## v1.1.0 — LMS Integrations & Premium Admin UX (Released March 2026)

### LMS Adapters (Pro) — 5 new integrations
All verified against actual plugin source code, not documentation.

| LMS | Version Tested | CPT | Enrollment Hook |
|-----|---------------|-----|-----------------|
| LearnDash | 5.0.4 | sfwd-courses / groups | learndash_update_course_access |
| Tutor LMS | 3.9.7 | courses | tutor_after_enrolled |
| LifterLMS | 9.2.1 | course / llms_membership | llms_user_enrolled_in_course |
| Sensei LMS | 4.25.2 | course | sensei_course_enrolment_status_changed |
| MasterStudy LMS | 3.7.22 | stm-courses | add_user_course |

### Access Rules UX Overhaul
- Adapter-specific rule types in dropdown (e.g. "Tutor Course" instead of generic "Membership")
- Searchable autocomplete — scales to 1000+ courses
- Human-readable labels in rules table (course names, not IDs)
- Sync Members button — pull in existing enrolled students with one click
- Priority column hidden for cleaner admin

### Auto-Create Spaces for Courses (Pro)
- Per-LMS toggle in Settings → Integrations
- Publishing a course auto-creates a private discussion space
- Access rule and course author → space admin set automatically

### Membership Deactivation Behavior
- All adapters now fully remove space membership on deactivation
- Content preserved — only access revoked
- Re-enrollment restores access automatically

### Import Improvements
- wpForo multi-board support — each board imports to its own category
- Asgaros importer fixed — all column mappings verified against actual plugin schema

### Other
- Configurable Community Title setting (H1 on home page)
- H1 heading fix for SEO and accessibility

---

## v1.2.0 — Forum UX Features (Planned)

### 1. Private Topics
Per-topic visibility in public spaces. Users mark individual topics as private — only the author + moderators/admins can see them. Use case: sensitive questions in course forums, support requests.

### 2. Topic Prefixes
Colored labels like "Bug", "Suggestion", "Solved" shown before topic titles. Admin configures per space. Leverages existing tags with `is_prefix` flag — no new table.

### 3. Similar Topics (Duplicate Detection)
As user types a topic title, show matching topics below the field. Space-first search with toggle to expand community-wide. FULLTEXT match, debounced input, top 5 results.

### 4. Quote Replies
Click "Quote" on any reply → quoted text auto-inserted into editor as styled blockquote with author attribution.

### 5. Bookmarks
Extend existing subscriptions table with a type flag. Save topics to read later — separate from notification subscriptions.

### 6. BuddyPress Integration
BuddyPress/BuddyBoss group ↔ space mapping. Group members auto-synced. Forum tab in group pages. Activity stream integration. User profile forum stats.

### 7. Abilities API — 100% Coverage
Every REST endpoint gets a corresponding WordPress Ability. Currently 19 free + 20 Pro = 39 abilities. Adding ~15 missing CRUD abilities so AI agents can fully operate the forum.

---

## Future Considerations

| Feature | Notes |
|---------|-------|
| LMS Bridge Extension | Course↔space auto-linking, instructor→moderator, lesson Q&A, completion→reputation |
| Instructor Dashboard | Per-course discussion analytics — questions per lesson, response times |
| Scheduled Posts | Post with future date → auto-publish |
| Post Templates | Starter content for Q&A, idea submission |
| AI Moderation | LLM-based spam detection in Advanced Moderation |
| Semantic Search | Vector embeddings via Search Adapter interface |
