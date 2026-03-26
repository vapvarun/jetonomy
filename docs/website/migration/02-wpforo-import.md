Move your existing wpForo community into Jetonomy — forums, topics, replies, user profiles, and reputation data — using the built-in wpForo importer.

## What You Will Learn

- What the wpForo importer brings over and how wpForo structures map to Jetonomy
- How to prepare your site before running the import
- How to start, monitor, and resume the import
- How wpForo-specific fields (reputation, badges, user roles) are handled
- What to verify after the import

## What Gets Imported

| wpForo Data | Imported As | Notes |
|---|---|---|
| Forums | Jetonomy Spaces | Forum name, slug, description preserved |
| Sub-forums | Jetonomy Sub-spaces | Nested up to 2 levels |
| Topics | Jetonomy Posts | Title and first post content preserved |
| Replies (posts) | Jetonomy Replies | Threaded up to 3 levels; deeper threads flattened |
| Tags | Jetonomy Tags | Applied to corresponding posts |
| User accounts | Linked to existing WP users | Matched by WP user ID |
| User reputation | Reputation score | wpForo points mapped to Jetonomy score (1:1) |
| User roles (Moderator) | Space Moderator role | Per-forum moderator assignments preserved |
| Liked posts | Vote score | wpForo likes mapped to upvotes |
| Pinned topics | Pinned posts | Preserved |
| Closed topics | Closed posts | Preserved |

**Not imported:**
- wpForo private conversations (different data structure; import to Jetonomy Pro private messaging separately)
- wpForo groups and group memberships (map manually to Jetonomy spaces)
- wpForo custom user groups beyond standard roles
- Embedded wpForo media that uses shortcodes instead of standard `<img>` tags

## wpForo Data Structure Differences

wpForo and Jetonomy structure their data differently in a few key areas:

**Forums and categories** — wpForo uses a single hierarchical `boards` table for both forums and categories. Jetonomy separates categories (top-level groups) from spaces (discussion areas). The importer creates Jetonomy categories from wpForo's top-level boards and spaces from second-level boards.

**Post structure** — In wpForo, the first "post" of a topic is a reply in the same table. In Jetonomy, topics and replies are separate entities. The importer promotes the first wpForo post as the Jetonomy post body and imports subsequent posts as replies.

**Reputation** — wpForo tracks reputation as a single integer. Jetonomy maps it directly as the starting reputation score. Trust levels are re-evaluated by the cron job after import based on your configured thresholds.

## Pre-Import Checklist

1. **Back up your database.** The importer reads but never modifies wpForo tables, but a backup protects against any edge cases.
2. **Activate Jetonomy** and complete the setup wizard.
3. **Keep wpForo active** during the import — the importer reads from wpForo's live tables.
4. **Check your wpForo table prefix.** If wpForo uses a custom prefix, confirm it in `wpforo_boards` — the importer auto-detects it.
5. **Disable wpForo page caching** if active, to avoid stale data during the import process.

> **Tip:** If you have over 50,000 topics, use WP-CLI to run the import. Browser-based imports on large databases can time out on shared hosting.

## Running the Import

1. Go to **Jetonomy → Import** in your WordPress admin.
2. Select **wpForo** as the source.
3. (Optional) Enable **Dry Run** to preview the import before writing any data.
4. Click **Start Import**.

The importer processes in batches of 50 records. A progress indicator shows total records, completed batches, and estimated time remaining.

## Dry-Run Mode

Run a dry run before your first import. It analyzes your wpForo database and reports:

- Board hierarchy and how it maps to Jetonomy categories and spaces
- Total topic, reply, and user counts
- Estimated import duration
- Any encoding issues or records with missing user references
- A preview of the first 10 forum-to-space mappings

No data is written during a dry run. Run it as many times as needed.

## Estimated Import Times

| Community Size | Topics + Replies | Estimated Time |
|---|---|---|
| Small | Under 10,000 | 2–5 minutes |
| Medium | 10,000–100,000 | 10–40 minutes |
| Large | 100,000–500,000 | 1–4 hours |
| Very large | 500,000+ | Use WP-CLI |

## Resuming a Paused Import

If the import pauses (due to a timeout, server restart, or closed browser), return to **Jetonomy → Import** and click **Resume Import**. Progress is stored in the database. Already-imported records are skipped automatically.

## Running via WP-CLI

```bash
wp --path="/path/to/wordpress" jetonomy import run --source=wpforo
```

Optional flags:

```bash
# Dry run
wp jetonomy import run --source=wpforo --dry-run

# Set batch size (default 50; larger batches are faster on good servers)
wp jetonomy import run --source=wpforo --batch-size=100

# Resume from a specific batch offset
wp jetonomy import run --source=wpforo --offset=200
```

## Handling wpForo's Custom Post Formats

wpForo supports "post types" (Normal, Question/Answer, Debate). The importer maps them to Jetonomy space types:

| wpForo Board Type | Jetonomy Space Type |
|---|---|
| Standard | Forum |
| Q&A | Q&A |
| Debate | Forum (closest match) |

If you had a mix of types in a single wpForo board, the importer uses the board's configured type as the Jetonomy space type.

## Post-Import Checklist

After the import completes:

- [ ] Visit your community home and confirm spaces match your old wpForo boards
- [ ] Open several posts from different spaces and verify content is intact
- [ ] Check that Q&A spaces show accepted answers correctly
- [ ] Verify user reputation scores on a few known high-reputation members
- [ ] Confirm that forum moderators have the Moderator role in their spaces
- [ ] Go to **Settings → Permalinks** and click Save to flush rewrite rules
- [ ] Remove or update any wpForo shortcodes on pages and widgets
- [ ] Consider deactivating wpForo after confirming the import — it is no longer needed

> **Note:** If your wpForo installation used a third-party plugin for member ratings or post reactions, those values are not included in the standard import. You can extend the importer using the `jetonomy_importers` filter.

## What's Next?

Now that your community is live, configure who can read it and how members earn trust.

[General Settings →](../admin-settings/01-general.md)
