Move your existing wpForo community into Jetonomy - forums, topics, replies, and user profiles - using the built-in wpForo importer.

![Import tool with wpForo source selected and migration progress](../images/admin-import.png)

## What You Will Learn

- What the wpForo importer brings over and how wpForo structures map to Jetonomy
- How to prepare your site before running the import
- How to start and monitor the import
- How wpForo's multi-board and forum structures map onto Jetonomy
- What to verify after the import

## What Gets Imported

| wpForo Data | Imported As | Notes |
|---|---|---|
| Forums | Jetonomy Spaces | Forum name, slug, description preserved |
| Topics | Jetonomy Posts | Title and first post content preserved |
| Replies (posts) | Jetonomy Replies | Reply parent relationships preserved |
| User accounts | Linked to existing WP users | Matched by WP user ID |
| Liked posts | Vote score | wpForo likes mapped to upvotes |
| Pinned topics | Pinned posts | Preserved |
| Closed topics | Closed posts | Preserved |

**Not imported:**
- wpForo sub-forum hierarchy (all forums, including sub-forums, are flattened into spaces under the board's category)
- wpForo topic tags
- wpForo user reputation / points
- wpForo user roles and moderator assignments (assign Space Moderator roles manually after import)
- wpForo private conversations (different data structure; import to Jetonomy Pro private messaging separately)
- wpForo groups and group memberships (map manually to Jetonomy spaces)
- wpForo custom user groups beyond standard roles
- Embedded wpForo media that uses shortcodes instead of standard `<img>` tags

## wpForo Data Structure Differences

wpForo and Jetonomy structure their data differently in a few key areas:

**Multi-board support** - wpForo supports multiple boards, each with its own set of forums, topics, and posts stored in separate database tables (e.g., `wp_wpforo1_forums` for board 1, `wp_wpforo2_forums` for board 2). The importer automatically detects all active boards and imports each into its own Jetonomy category. Single-board installs work without any extra configuration.

**Forums and categories** - Within each board, wpForo uses a hierarchical forums table. Jetonomy separates categories (top-level groups) from spaces (discussion areas). The importer creates a Jetonomy category per board and one space per wpForo forum. Sub-forum nesting is not preserved - every forum (parent or child) becomes a flat space under the board's category.

**Post structure** - In wpForo, the first "post" of a topic is a reply in the same table. In Jetonomy, topics and replies are separate entities. The importer promotes the first wpForo post as the Jetonomy post body and imports subsequent posts as replies.

**Reputation** - wpForo reputation and points are not imported. After import, trust levels are re-evaluated by the cron job based on activity within Jetonomy and your configured thresholds.

## Pre-Import Checklist

1. **Back up your database.** The importer reads but never modifies wpForo tables, but a backup protects against any edge cases.
2. **Activate Jetonomy** and complete the setup wizard.
3. **Keep wpForo active** during the import - the importer reads from wpForo's live tables.
4. **Check your wpForo table prefix.** If wpForo uses a custom prefix, confirm it in `wpforo_boards` - the importer auto-detects it.
5. **Disable wpForo page caching** if active, to avoid stale data during the import process.

> **Tip:** If you have over 50,000 topics, use WP-CLI to run the import. Browser-based imports on large databases can time out on shared hosting.

## Running the Import

1. Go to **Jetonomy → Import** in your WordPress admin.
2. Select **wpForo** as the source.
3. Click **Start Import**.

The wpForo importer runs the entire import in a single pass - the progress bar advances in one step from start to complete. (The bbPress importer, by contrast, runs in true incremental batches.) Because it is single-pass, larger wpForo databases are best imported via WP-CLI to avoid browser timeouts.

## Dry-Run Mode

> **Important:** Dry-run is **not supported** for the wpForo importer. The `--dry-run` flag exists on the CLI command, but the wpForo importer ignores it and writes data regardless. Do not rely on `wp jetonomy import wpforo --dry-run` to preview the import without making changes - it will perform the real import. Always take a database backup before running the wpForo importer.

## Estimated Import Times

| Community Size | Topics + Replies | Estimated Time |
|---|---|---|
| Small | Under 10,000 | 2–5 minutes |
| Medium | 10,000–100,000 | 10–40 minutes |
| Large | 100,000–500,000 | 1–4 hours |
| Very large | 500,000+ | Use WP-CLI |

## Resuming a Paused Import

The wpForo import runs as a single pass, so there is no mid-import resume point. If it is interrupted (timeout, server restart, or closed browser), it cannot be resumed partway through - return to **Jetonomy → Import** and click **Start Over** to run it again. For this reason, run large wpForo imports via WP-CLI, which is not subject to browser timeouts.

## Running via WP-CLI

```bash
wp --path="/path/to/wordpress" jetonomy import wpforo
```

WP-CLI is the recommended way to import larger wpForo databases - it is not subject to browser timeouts and prints an `Imported / Skipped / Errors` summary when finished.

> **Note:** The `--dry-run` flag is accepted by the command but is **not honored** by the wpForo importer - it will still write data. Take a backup first.

## Space Types After Import

All imported wpForo forums become standard **Forum** spaces in Jetonomy. wpForo post types (Normal, Question/Answer, Debate) are not mapped to Jetonomy space types - if you want a space to behave as Q&A, change its type in **Jetonomy → Spaces** after the import.

## Post-Import Checklist

After the import completes:

- [ ] Visit your community home and confirm spaces match your old wpForo forums
- [ ] Open several posts from different spaces and verify content is intact
- [ ] Assign Space Moderator roles to your former forum moderators (moderator assignments are not imported)
- [ ] Go to **Settings → Permalinks** and click Save to flush rewrite rules (the wpForo importer does not flush them automatically, so this step is needed if new spaces return a 404)
- [ ] Remove or update any wpForo shortcodes on pages and widgets
- [ ] Consider deactivating wpForo after confirming the import - it is no longer needed

> **Note:** If your wpForo installation used a third-party plugin for member ratings or post reactions, those values are not included in the standard import. You can extend the importer using the `jetonomy_importers` filter.

## What's Next?

Migrating from Asgaros Forum instead? The process is similar with a few Asgaros-specific mappings.

[Importing from Asgaros →](03-asgaros-import.md)

Already imported? Configure who can read your community and how members earn trust.

[General Settings →](../admin-settings/01-general.md)
