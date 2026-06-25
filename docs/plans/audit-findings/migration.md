# Audit fixes — migration (14 findings)

## 1. [CRITICAL] `docs/website/migration/01-bbpress-import.md` — inaccurate
- **Issue:** WP-CLI command documented as `wp jetonomy import run --source=bbpress` with --batch-size/--offset; actual command is `wp jetonomy import bbpress` (positional, --dry-run only).
- **Fix:** Replace all command examples with `wp jetonomy import bbpress` and `wp jetonomy import bbpress --dry-run`. Remove the --batch-size and --offset flag examples entirely (lines 98-102) — they are not implemented.
- **Evidence:** Doc 01-bbpress-import.md:89 `wp ... jetonomy import run --source=bbpress`; lines 96,99,102 repeat --source/--batch-size/--offset. Code class-cli.php:90-108: docblock declares `<source>` positional arg and `[--dry-run]` as the ONLY flag; EXAMPLES are `wp jetonomy import bbpress`, `wp jetonomy import bbpress --dry-run`, `wp jetonomy import wpforo`. Handler reads `$source = $args[0]` (line 107) and `$dry_run = !empty($assoc_args['dry-run'])` (line 108) only. No `run` subcommand, no --source/--batch-size/--offset parsing exists.

## 2. [CRITICAL] `docs/website/migration/02-wpforo-import.md` — inaccurate
- **Issue:** WP-CLI command documented as `wp jetonomy import run --source=wpforo` with --batch-size/--offset; actual is `wp jetonomy import wpforo` (positional, --dry-run only).
- **Fix:** Change to `wp jetonomy import wpforo` and `wp jetonomy import wpforo --dry-run`. Delete the --batch-size/--offset examples.
- **Evidence:** Doc 02-wpforo-import.md:94 `wp ... jetonomy import run --source=wpforo`; lines 101,104,107 repeat --dry-run/--batch-size/--offset against the `run --source=` form. Code class-cli.php:106-108 same as above: positional `<source>`, only `[--dry-run]`. Import_Manager::run($source,...) called once (line 121); no batch/offset handling.

## 3. [MAJOR] `docs/website/migration/01-bbpress-import.md` — inaccurate
- **Issue:** Doc describes an admin Dry Run checkbox and a dry-run report (record counts, estimated time, integrity issues, 10-row mapping preview). Admin UI has no dry-run control and no such output exists.
- **Fix:** Remove step 3 'Enable Dry Run' from the admin Running-the-Import steps. Rewrite the Dry-Run Mode section to state dry-run is CLI-only (`--dry-run`) and that it outputs imported/skipped/error totals — not record-count tables, time estimates, integrity reports, or mapping previews.
- **Evidence:** Doc 01-bbpress-import.md:49 '(Optional) Enable Dry Run...' and the Dry-Run Mode section lines 56-65 listing record counts/estimated time/integrity issues/'first 10 forum-to-space mappings'. Code admin/views/import.php:140-170 — the only action buttons are Import / Re-Import / Resume Import / Start Over; there is no checkbox, toggle, or dry-run control rendered. CLI class-cli.php:117-137 — dry-run only logs 'DRY RUN -- no data will be written.' then the standard `Imported: %d, Skipped: %d, Errors: %d` summary. No counts/estimate/integrity/mapping-preview output anywhere.

## 4. [MAJOR] `docs/website/migration/02-wpforo-import.md` — inaccurate
- **Issue:** Doc describes an admin Dry Run control plus a dry-run report (board-hierarchy mapping, counts, estimated duration, encoding issues, 10-row mapping preview). None exists; dry-run is CLI-only with totals.
- **Fix:** Remove the admin Dry Run step. Rewrite Dry-Run Mode to CLI-only totals. STRONGER than auditor noted: warn that the wpForo importer does not actually honor dry-run (run() ignores the dry_run flag and writes regardless), so --dry-run on wpForo is not safe — or document this as a known limitation.
- **Evidence:** Doc 02-wpforo-import.md:61 '(Optional) Enable Dry Run...' and Dry-Run Mode section lines 66-76 (board hierarchy mapping, counts, estimated duration, encoding issues, first 10 forum-to-space mappings). Code admin/views/import.php:140-170 has no dry-run control. CLI class-cli.php:117-137 emits only imported/skipped/error totals. Additionally WPForo_Importer::run() (class-wpforo-importer.php:69-118) honors no dry_run flag at all — it always writes (Category::create, Space::create, etc.), so a CLI `--dry-run` against wpForo would still write data.

## 5. [MAJOR] `docs/website/migration/01-bbpress-import.md` — wrong-default
- **Issue:** Doc says importer processes in batches of 50 and CLI '--batch-size (default 50)'. Admin batch default is 500; CLI has no batch concept.
- **Fix:** Change 'batches of 50' to 'batches of 500'. Remove the --batch-size CLI example (flag does not exist).
- **Evidence:** Doc 01-bbpress-import.md:52 'processes records in batches of 50'; line 98 comment '# Set batch size (default 50)'. Code admin/ajax/class-import-handler.php:37 `$batch_size = absint( $_POST['batch_size'] ?? 500 )` — default 500. CLI class-cli.php:121 calls `Import_Manager::run( $source, [ 'dry_run' => $dry_run ] )` in one shot — no batching, no batch-size flag.

## 6. [MAJOR] `docs/website/migration/02-wpforo-import.md` — wrong-default
- **Issue:** Doc says importer processes in batches of 50 records. Admin default is 500, and the wpForo importer ignores batching entirely (runs whole import in the first batch).
- **Fix:** Remove 'batches of 50'. State that the wpForo importer runs as a single pass (the progress bar advances in one step), unlike bbPress which truly batches. Drop the --batch-size CLI example.
- **Evidence:** Doc 02-wpforo-import.md:64 'processes in batches of 50 records'; line 103 '# Set batch size (default 50...)'. Code class-import-handler.php:37 default 500. WPForo_Importer::run_batch() class-wpforo-importer.php:51-67 — for phase 'forums' offset 0 it calls run() (the entire import) and returns `'phase'=>'complete','done'=>true`; any other phase/offset returns done=true with 0 processed. So no real batching of 50 or 500. CLI has no batch-size flag.

## 7. [MAJOR] `docs/website/migration/01-bbpress-import.md` — inaccurate
- **Issue:** What Gets Imported lists Tags, User activity counts→Reputation, Votes, Forum moderators→Moderator role. The bbPress importer imports none of these.
- **Fix:** Remove the Tags, User activity counts→Reputation, Votes, and Forum moderators rows from the bbPress 'What Gets Imported' table (lines 20,22,23,24), or move them under 'Not imported'. Sticky topics→Pinned IS supported (is_sticky read from _bbp_topic_sticky), keep that row.
- **Evidence:** Doc 01-bbpress-import.md:20 Tags→Jetonomy Tags; :22 User activity counts→Reputation; :23 Votes→Jetonomy votes; :24 Forum moderators→Space Moderator role. Code class-bbpress-importer.php — run() (275-309) and run_batch() (51-273) only cover forums→spaces, topics→posts, replies, profiles (UserProfile::find_or_create / ensure_profile), and recount. No tag import, no reputation/activity-count assignment, no Vote::cast, no moderator-role assignment anywhere in the file.

## 8. [MAJOR] `docs/website/migration/01-bbpress-import.md` — inaccurate
- **Issue:** Doc says replies are 'Threaded up to 3 levels; deeper threads flattened.' bbPress importer creates every reply flat with no parent_id.
- **Fix:** Change the Replies note to 'Imported as flat replies on the post (bbPress reply threading is not preserved).'
- **Evidence:** Doc 01-bbpress-import.md:19 'Threaded up to 3 levels; deeper threads flattened'. Code class-bbpress-importer.php:419-428 JtReply::create payload has NO parent_id key (only post_id, author_id, content, content_plain, status, created_at). Line 413 comment 'Try grandparent (nested reply)' is followed only by `++$this->skipped; continue;` — a no-op. Confirmed there is no threading logic.

## 9. [MAJOR] `docs/website/migration/02-wpforo-import.md` — inaccurate
- **Issue:** What Gets Imported / Reputation section claim Tags, User reputation→score (1:1), and User roles (Moderator)→Space Moderator role. None of these are imported.
- **Fix:** Remove the Tags, User reputation→score (1:1), and User roles (Moderator) rows (lines 21,23,24) and delete/rewrite the Reputation paragraph (line 45). Liked posts→Vote score (line 25) IS accurate (import_likes calls Vote::cast) — keep it.
- **Evidence:** Doc 02-wpforo-import.md:21 Tags→Jetonomy Tags; :23 User reputation→score (1:1); :24 User roles (Moderator)→Space Moderator role; :45 'Jetonomy maps it directly as the starting reputation score'. Code class-wpforo-importer.php:108-113 run() calls only import_forums/import_topics/import_replies/import_likes/create_profiles. create_profiles (309-325) iterates wpforo profiles and calls ensure_profile((int)$prof->userid) only — no reputation/points value passed. No tag import, no moderator-role assignment anywhere.

## 10. [MAJOR] `docs/website/migration/02-wpforo-import.md` — inaccurate
- **Issue:** Doc says sub-forums become Jetonomy sub-spaces 'Nested up to 2 levels' and hierarchy is preserved. Importer creates every forum as a flat top-level space.
- **Fix:** Remove the 'Sub-forums → Sub-spaces, Nested up to 2 levels' row (line 18). State that all wpForo forums (including sub-forums) are flattened into spaces under the board's category; nesting is not preserved.
- **Evidence:** Doc 02-wpforo-import.md:18 'Sub-forums → Jetonomy Sub-spaces — Nested up to 2 levels'; :41 'creates ... spaces from the forums within each board' (implying hierarchy preserved). Code class-wpforo-importer.php:120-150 import_forums() iterates all forums and calls Space::create with category_id, type, title, slug, description, visibility, join_policy, sort_order — no parent-space field and no recursion. Every forum is flat under the board category. wpForo's forum parentid is never read.

## 11. [MAJOR] `docs/website/migration/02-wpforo-import.md` — inaccurate
- **Issue:** 'Handling wpForo's Custom Post Formats' section claims Standard→Forum, Q&A→Q&A, Debate→Forum space-type mapping. Every space is hard-coded type 'forum'.
- **Fix:** Delete the entire 'Handling wpForo's Custom Post Formats' section (lines 110-120). All imported spaces are type 'forum'; Q&A mapping does not exist. Also reconsider the post-import checklist line 128 'Check that Q&A spaces show accepted answers' since no Q&A spaces are created.
- **Evidence:** Doc 02-wpforo-import.md:110-120 section with mapping table (Standard→Forum, Q&A→Q&A, Debate→Forum) and 'the importer uses the board's configured type as the Jetonomy space type'. Code class-wpforo-importer.php:133 `'type' => 'forum',` is hard-coded in import_forums for every space. No board/forum type column is read; no Q&A or Debate branch exists.

## 12. [MINOR] `docs/website/migration/01-bbpress-import.md` — missing-feature
- **Issue:** A third importer (Asgaros) is registered and shown in the admin import UI, but no migration doc covers it.
- **Fix:** Add a 03-asgaros-import.md (or an Asgaros section) documenting the Asgaros importer, mirroring what the Asgaros_Importer actually does. At minimum, update the import-source intro lists in both docs to mention Asgaros.
- **Evidence:** Code class-import-manager.php:19 `self::register( 'asgaros', new Asgaros_Importer() );`. admin/views/import.php:32 empty-state text 'Jetonomy can import from bbPress, wpForo, and Asgaros'; lines 44-48 render an Asgaros source tile/button. The migration/ docs only contain 01-bbpress-import.md and 02-wpforo-import.md — no Asgaros doc.

## 13. [MINOR] `docs/website/migration/02-wpforo-import.md` — inaccurate
- **Issue:** Resume guidance implies multi-batch resume works for all sources; for wpForo run_batch is single-shot so there is no mid-import resume point.
- **Fix:** Keep resume guidance for bbPress (accurate). For the wpForo doc, qualify that the wpForo import runs as a single pass and cannot be resumed mid-run; if interrupted it must be restarted (Start Over). Adjusted to 'minor' and scoped to the wpForo doc since the bbPress resume claim is correct.
- **Evidence:** Doc 02-wpforo-import.md:87-89 'If the import pauses ... click Resume Import. Progress is stored ... Already-imported records are skipped automatically.' and 01-bbpress-import.md:78-82 'continue from the last completed batch.' Code class-wpforo-importer.php:51-67 run_batch runs the whole import in the first call (phase forums, offset 0) and returns done=true; there is no intermediate phase/offset to resume from. By contrast bbPress run_batch (class-bbpress-importer.php:51-273) genuinely advances through forums→topics→replies→profiles→recount phases, so resume IS accurate for bbPress.

## 14. [MINOR] `docs/website/migration/01-bbpress-import.md` — inaccurate
- **Issue:** Post-import checklist tells users to manually visit Settings→Permalinks and Save to flush rewrite rules; both the AJAX recount phase and the CLI import already flush automatically.
- **Fix:** Soften to optional: 'If new spaces 404, visit Settings → Permalinks and click Save to flush rewrite rules (normally done automatically on import completion).' Note the wpForo doc's permalink line (02:131) sits downstream of the single-shot run() which does NOT call flush_rewrite_rules() itself — wpForo relies on the AJAX recount phase, which run_batch short-circuits, so for wpForo the manual flush may actually be needed. Verify before softening the wpForo doc.
- **Evidence:** Doc 01-bbpress-import.md:116 'Visit Jetonomy → Settings → Permalinks and click Save to flush rewrite rules' (also 02-wpforo-import.md:131). Code class-bbpress-importer.php:257 `flush_rewrite_rules();` runs in the recount phase at completion of the AJAX batch flow. class-cli.php:146-148 calls `flush_rewrite_rules()` after a non-dry-run CLI import. So the manual step is unnecessary after a completed import.
