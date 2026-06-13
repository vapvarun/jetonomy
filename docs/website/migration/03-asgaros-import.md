Move your existing Asgaros Forum community into Jetonomy - forums, topics, replies, and user profiles - using the built-in Asgaros importer.

![Import tool with Asgaros source selected and migration progress](../images/admin-import.png)

## What You Will Learn

- What the Asgaros importer brings over and what it leaves behind
- How Asgaros forum hierarchy maps onto Jetonomy spaces
- How to start and monitor the import
- What to verify after the import

## What Gets Imported

| Asgaros Data | Imported As | Notes |
|---|---|---|
| Forums | Jetonomy Spaces | Forum name and description preserved |
| Sub-forums | Jetonomy Sub-spaces | Parent/child forum hierarchy is preserved |
| Topics | Jetonomy Posts | Topic title + first post content preserved |
| Replies (posts) | Jetonomy Replies | Imported as flat replies on the post |
| User accounts | Linked to existing WP users | Matched by WP user ID |
| Sticky topics | Pinned posts | Preserved |
| Closed topics | Closed posts | Preserved |
| Unapproved topics | Pending posts | Topics not approved in Asgaros import as pending |

**Not imported:**
- Asgaros topic tags
- Asgaros user reputation / points
- Asgaros likes / reactions
- Asgaros moderator assignments (assign Space Moderator roles manually after import)
- Asgaros subscriptions (replaced by Jetonomy follow/subscribe)
- Custom Asgaros meta fields (use the `jetonomy_importers` filter to extend)

## Asgaros Data Structure Differences

**Forum hierarchy** - Asgaros stores forums in a single table with a `parent_forum` column. The importer creates one Jetonomy category ("Imported from Asgaros") and one space per Asgaros forum, importing parent forums before their children so the parent/child relationship is preserved as Jetonomy sub-spaces.

**Post structure** - In Asgaros, the first post of a topic and its replies all live in the `forum_posts` table. The importer promotes the first post as the Jetonomy post body and imports the remaining posts as replies.

## Pre-Import Checklist

1. **Back up your database.** The importer reads but never modifies Asgaros tables, but a backup protects against any edge cases.
2. **Activate Jetonomy** and complete the setup wizard.
3. **Keep Asgaros Forum active** during the import - the importer reads from its live tables.
4. **Disable page caching** if active, to avoid stale data during the import.

> **Tip:** For large Asgaros communities, run the import via WP-CLI to avoid browser timeouts.

## Running the Import

1. Go to **Jetonomy → Import** in your WordPress admin.
2. Select **Asgaros Forum** as the source.
3. Click **Start Import**.

The Asgaros importer runs the entire import in a single pass - the progress bar advances in one step from start to complete. Because it is single-pass, it cannot be resumed partway through; if it is interrupted, click **Start Over** to run it again.

## Estimated Import Times

| Community Size | Topics + Replies | Estimated Time |
|---|---|---|
| Small | Under 10,000 | 2–5 minutes |
| Medium | 10,000–100,000 | 10–40 minutes |
| Large | 100,000–500,000 | 1–4 hours |
| Very large | 500,000+ | Use WP-CLI |

## Running via WP-CLI

```bash
wp --path="/path/to/wordpress" jetonomy import asgaros
```

WP-CLI is the recommended way to import larger Asgaros databases - it is not subject to browser timeouts and prints an `Imported / Skipped / Errors` summary when finished.

> **Note:** The `--dry-run` flag is accepted by the command but is **not honored** by the Asgaros importer - it will still write data. Take a backup first.

## Post-Import Checklist

After the import completes:

- [ ] Visit your community home and confirm spaces match your old Asgaros forums
- [ ] Confirm that sub-forums appear as sub-spaces under their parent
- [ ] Open several posts from different spaces and verify content is intact
- [ ] Assign Space Moderator roles to your former forum moderators (moderator assignments are not imported)
- [ ] Go to **Settings → Permalinks** and click Save to flush rewrite rules (the Asgaros importer does not flush them automatically, so this step is needed if new spaces return a 404)
- [ ] Consider deactivating Asgaros after confirming the import - it is no longer needed

## What's Next?

Now that your community is live, configure who can read it and how members earn trust.

[General Settings →](../admin-settings/01-general.md)
