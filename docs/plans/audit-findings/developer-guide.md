# Audit fixes — developer-guide (10 findings)

## 1. [CRITICAL] `docs/website/developer-guide/05-adapters.md` — inaccurate
- **Issue:** Documents a non-existent 'Realtime' adapter type (Realtime_Adapter interface, Polling_Adapter, register_realtime()/get_realtime(), Pusher example, cheat-sheet call).
- **Fix:** Remove the entire Realtime adapter section: the Realtime_Adapter row (:14), Polling_Adapter row (:25), register_realtime/get_realtime API lines (:52, :58), Realtime Adapter Interface section, Pusher_Adapter example, the 'Realtime: Broadcast new replies' wiring example, and the register_realtime() block in the cheat sheet. Replace with the AI adapter (interface-ai-adapter.php, Ollama_AI_Adapter, register_ai/get_ai/get_all_ai).
- **Evidence:** DOC 05-adapters.md:14 (Realtime_Adapter row), :25 (Polling_Adapter row), :52 register_realtime, :58 get_realtime, :357-417 Realtime interface + Pusher example, :496-501 cheat sheet register_realtime(). CODE: includes/adapters/ contains interface-search/email/membership/ai-adapter.php ONLY (ls output: no interface-realtime, no Polling_Adapter). class-adapter-registry.php defines register_membership/register_search/register_email/register_ai and get_membership/get_email/get_search/get_ai/get_all_membership/get_all_ai — NO register_realtime or get_realtime method anywhere (lines 19-84). init_defaults() (line 89-92) registers only wp-roles + wp-mail; no Polling_Adapter. The cheat-sheet's Adapter_Registry::register_realtime() at :497 is a call to an undefined static method (fatal at runtime).

## 2. [MAJOR] `docs/website/developer-guide/05-adapters.md` — missing-feature
- **Issue:** AI adapter type (the real fourth adapter type) is entirely undocumented; doc lists Search/Email/Membership/Realtime instead.
- **Fix:** Add an AI_Adapter row to the adapter types table (interface-ai-adapter.php, controls AI text generation/moderation), document register_ai()/get_ai()/get_all_ai() in the Registry API section, and note the built-in Ollama_AI_Adapter (free) plus Pro AI adapters. This replaces the bogus Realtime row.
- **Evidence:** DOC 05-adapters.md:7-14 'The Four Adapter Types' table lists Search, Email, Membership, Realtime — no AI. CODE: includes/adapters/class-adapter-registry.php:66 register_ai(), :70 get_ai(), :82 get_all_ai(); includes/adapters/interface-ai-adapter.php exists; includes/adapters/class-ollama-ai-adapter.php is the built-in free AI adapter (file present in ls).

## 3. [MAJOR] `docs/website/developer-guide/01-rest-api.md` — wrong-route
- **Issue:** Documents 'POST /tags' as a registered route, but the Tags controller registers only GET /tags.
- **Fix:** Remove the 'POST /tags' row from the Tags table at :256. Tag creation is not exposed via REST.
- **Evidence:** DOC 01-rest-api.md:256 '| POST | /tags | Logged in (trust level 1+) | Create a tag |'. CODE: includes/api/class-tags-controller.php:25-55 register_routes() calls register_rest_route once for '/tags' with methods WP_REST_Server::READABLE (GET) only (line 33). No register_rest_route for a POST/CREATABLE method exists; comment at :52-54 notes /space-tags removal but nothing adds POST /tags.

## 4. [MAJOR] `docs/website/developer-guide/01-rest-api.md` — wrong-key
- **Issue:** POST /flags 'reason' enum documented as 'spam | off-topic | inappropriate | other' but code enum is ['spam','offensive','off_topic','harassment','other'].
- **Fix:** Change the doc comment to: 'spam | offensive | off_topic | harassment | other' (underscore for off_topic).
- **Evidence:** DOC 01-rest-api.md:344 "reason: 'spam', // spam | off-topic | inappropriate | other". CODE: includes/api/class-moderation-controller.php:104-108 'reason' => [ ... 'enum' => ['spam','offensive','off_topic','harassment','other'] ]. Mismatches: 'inappropriate' (doc) is not valid (code uses 'offensive'); 'off-topic' hyphen vs code 'off_topic' underscore; doc omits 'offensive' and 'harassment'.

## 5. [MAJOR] `docs/website/developer-guide/01-rest-api.md` — wrong-key
- **Issue:** Email Digest admin endpoints documented as requiring 'jetonomy_manage_settings' but code gates them on 'manage_options'.
- **Fix:** Change the Auth column for both /admin/digest/test and /admin/digest/stats to 'manage_options' (or 'Admin (manage_options)' to match the doc's convention elsewhere).
- **Evidence:** DOC 01-rest-api.md:574-575 Auth column 'jetonomy_manage_settings' for POST /admin/digest/test and GET /admin/digest/stats. CODE: jetonomy-pro/includes/extensions/email-digest/class-extension.php:223 'permission_callback' => $this->rest_auth_mutation( 'manage_options' ) (the /test route), :242 return current_user_can( 'manage_options' ) (the /stats route).

## 6. [MAJOR] `docs/website/developer-guide/11-abilities-api.md` — wrong-key
- **Issue:** Fifth free ability category listed as 'jetonomy-discovery' but code registers 'jetonomy-search'.
- **Fix:** Replace 'jetonomy-discovery' with 'jetonomy-search' in the category list at :18.
- **Evidence:** DOC 11-abilities-api.md:18 lists categories ending in 'jetonomy-discovery'. CODE: includes/class-abilities.php:76-82 wp_register_ability_category('jetonomy-search', [label => 'Community Search', ...]); the jetonomy/search ability uses 'category' => 'jetonomy-search' at line 1097. grep for 'jetonomy-discovery' across includes/ returns ZERO matches.

## 7. [MAJOR] `docs/website/developer-guide/02-hooks-reference.md` — inaccurate
- **Issue:** Intro claims '58 hooks in the free plugin and 9 additional hooks in Jetonomy Pro' — both counts are far too low and contradict the doc's own content and the manifests.
- **Fix:** Update the intro counts to reflect reality. Either cite the manifest figures (143 free / 24 Pro fired hooks) or count the documented headings; do not state 58/9. Keep the number a soft 'documents the most useful hooks' phrasing if exhaustive parity is not intended.
- **Evidence:** DOC 02-hooks-reference.md:1 'Jetonomy exposes 58 hooks in the free plugin and 9 additional hooks in Jetonomy Pro.' CODE/MANIFEST: jetonomy/audit/manifest.json hooks_fired length = 143; jetonomy-pro/audit/manifest.json hooks_fired length = 24. The doc itself contains 124 total '### ' hook headings (grep -c '^### ' = 124 across free+pro sections), already far above 58+9.

## 8. [MINOR] `docs/website/developer-guide/06-fluent-community-integration.md` — inaccurate
- **Issue:** Persisted State section opens 'Two WordPress options' but lists four option rows and later says 'All four options'.
- **Fix:** Change 'Two WordPress options' to 'Four WordPress options' at :32 to match the table and the four constants.
- **Evidence:** DOC 06-fluent-community-integration.md:32 'Two WordPress options hold the entire integration footprint.' vs :36-39 four table rows (jetonomy_fc_space_pairs, jetonomy_fc_tab_label, jetonomy_fc_sync_members, jetonomy_fc_broadcast) and :41 'All four options are removed on uninstall.' CODE: includes/integrations/class-fluent-community.php:52 OPT_PAIRS, :57 OPT_LABEL, :63 OPT_SYNC_MEMBERS, :69 OPT_BROADCAST — four constants.

## 9. [MINOR] `docs/website/developer-guide/01-rest-api.md` — missing-feature
- **Issue:** Private Messaging table omits dedicated POST /mute, /archive, /leave, /block and GET /messaging/recipient-suggestions routes.
- **Fix:** Add rows for POST /conversations/{id}/mute, /archive, /leave, /block and GET /messaging/recipient-suggestions. The PATCH /conversations/{id} 'Mute/unmute' description should also be reconciled with the dedicated POST /mute route.
- **Evidence:** DOC 01-rest-api.md:482-488 messaging table lists GET/POST /conversations, GET/PATCH /conversations/{id}, GET/POST /conversations/{id}/messages, GET /conversations/unread-count (PATCH described as 'Mute/unmute'). CODE: jetonomy-pro/includes/extensions/private-messaging/class-extension.php:930 '/conversations/(?P<id>\d+)/mute', :946 '/archive', :962 '/leave', :973 '/block', :891 '/messaging/recipient-suggestions' — all registered via register_rest_route but absent from the doc.

## 10. [MINOR] `docs/website/developer-guide/03-template-overrides.md` — missing-feature
- **Issue:** Views table lists 15 view files but the templates/views/ directory ships 6 more overridable views (bookmarks, drafts, my-spaces, new-space, space-edit, space-moderation).
- **Fix:** Add the six missing views to the table (bookmarks, drafts, my-spaces, new-space, space-edit, space-moderation) with their routes, or explicitly note the table is a representative subset. Bookmarks/drafts/my-spaces are real customer-facing routes worth documenting.
- **Evidence:** DOC 03-template-overrides.md:62-76 lists 15 views (home, category, space, space-members, space-roadmap, single-post, new-post, user-profile, edit-profile, leaderboard, notifications, moderation, search, tag, invite). CODE: ls templates/views/ also contains bookmarks.php, drafts.php, my-spaces.php, new-space.php, space-edit.php, space-moderation.php (21 files total).
