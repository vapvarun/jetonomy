Move your existing bbPress community into Jetonomy - forums, topics, replies, user data, and vote history - using the built-in importer.

![Import tool interface with source selection and progress tracking](../images/admin-import.png)

## What You Will Learn

- What data the bbPress importer brings over and what it leaves behind
- How to prepare your site before running the import
- How to start the import, monitor progress, and resume if it stops
- How to use the CLI dry-run option to validate before writing data
- What to verify after the import completes

## What Gets Imported

| bbPress Data | Imported As | Notes |
|---|---|---|
| Forums | Jetonomy Spaces | Forum description → space description |
| Topics | Jetonomy Posts | Topic title + content preserved |
| Replies | Jetonomy Replies | Imported as flat replies on the post (bbPress reply threading is not preserved) |
| User accounts | Linked to existing WP users | Matched by user ID |
| Sticky topics | Pinned posts | Preserved |

**Not imported:**
- bbPress topic tags
- bbPress user activity counts / reputation
- bbPress votes (no standard bbPress vote data is read)
- Forum moderator assignments (assign Space Moderator roles manually after import)
- bbPress subscriptions (replaced by Jetonomy follow/subscribe)
- bbPress private messages (import to Jetonomy Pro private messaging separately)
- Custom bbPress meta fields (use the `jetonomy_importers` filter to extend)
- Forum avatars (WordPress avatars carry over via Gravatar/WP user accounts)

## Pre-Import Checklist

Complete these steps before starting the import:

1. **Back up your database.** The importer does not modify bbPress tables, but a backup is essential.
2. **Activate Jetonomy** and complete the setup wizard. Your community base URL should be set.
3. **Keep bbPress active** during the import. The importer reads directly from bbPress tables.
4. **Set your server timeout high.** Large imports (100,000+ records) take time. Increase `max_execution_time` in `php.ini` or use WP-CLI (recommended for large sites).
5. **Disable other heavy plugins** during import if your server is resource-constrained.

> **Tip:** For large communities (10,000+ topics), run the import via WP-CLI to avoid browser timeouts entirely. See the WP-CLI section below.

## Running the Import

1. Go to **Jetonomy → Import** in your WordPress admin.
2. Select **bbPress** as the source.
3. Click **Start Import**.

The importer processes records in batches of 500. A progress bar shows completion percentage, current batch, and estimated time remaining.

Do not close the browser tab while the import is running. If the page refreshes or you navigate away, the import will pause - but can be resumed (see below).

## Dry-Run Mode

Dry-run mode is available via WP-CLI only (`--dry-run`). It runs the import logic without writing any data and reports the imported / skipped / error totals it would have produced:

```bash
wp jetonomy import bbpress --dry-run
```

The summary line looks like `[DRY RUN] Import complete. Imported: 1240, Skipped: 12, Errors: 0`. No database writes are made, so you can run it as many times as you need.

## Estimated Import Times

| Community Size | Topics + Replies | Estimated Time |
|---|---|---|
| Small | Under 10,000 | 2–5 minutes |
| Medium | 10,000–100,000 | 10–40 minutes |
| Large | 100,000–500,000 | 1–4 hours |
| Very large | 500,000+ | Use WP-CLI |

These are estimates for a typical shared hosting server. Dedicated servers will be significantly faster.

## Resuming a Paused Import

If the import stops (browser closed, timeout, server restart), return to **Jetonomy → Import**. The importer stores its progress in the database. Click **Resume Import** to continue from the last completed batch.

You can safely resume multiple times. Records that were already imported are skipped.

## Running via WP-CLI

For large communities, WP-CLI is more reliable than the browser-based importer:

```bash
wp --path="/path/to/wordpress" jetonomy import bbpress
```

The only flag is `--dry-run`:

```bash
# Dry run — validate and count without writing any data
wp jetonomy import bbpress --dry-run
```

WP-CLI runs without a browser timeout limit and prints an `Imported / Skipped / Errors` summary when the import finishes.

## Post-Import Checklist

After the import completes, verify the following:

- [ ] Navigate to your community home - spaces should match your old bbPress forums
- [ ] Open several posts and confirm content and replies are intact
- [ ] Check that user profiles show post counts
- [ ] Assign Space Moderator roles to your former forum moderators (moderator assignments are not imported)
- [ ] Test creating a new post as a regular user
- [ ] If new spaces return a 404, visit **Jetonomy → Settings → Permalinks** and click Save to flush rewrite rules (normally done automatically on import completion)
- [ ] If you used bbPress shortcodes on pages, remove or replace them - they will output raw shortcode text now that bbPress is still active

> **Note:** After a successful import, you can deactivate bbPress. Your community data is now in Jetonomy's tables and bbPress is no longer needed.

## What's Next?

Migrating from wpForo? The process is similar with a few wpForo-specific field mappings.

[Importing from wpForo →](02-wpforo-import.md)
