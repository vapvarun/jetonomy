Bringing an existing forum into Jetonomy? This page covers everything that is the same across all three importers - which source to pick, how to read the import screen, and the checklist to run after any import. Then follow the guide for your specific forum software.

![Jetonomy Import screen showing detected forum sources with stat previews and Import buttons](../images/admin-import.png)

## Which Importer Do I Need?

Jetonomy ships with three built-in importers. Pick the one that matches the forum plugin you are moving away from:

| You are coming from | Use this guide |
|---|---|
| bbPress | [Importing from bbPress](01-bbpress-import.md) |
| wpForo | [Importing from wpForo](02-wpforo-import.md) |
| Asgaros Forum | [Importing from Asgaros Forum](03-asgaros-import.md) |

These three are the only built-in sources. Developers can add support for other forum software through the `jetonomy_importers` filter - see the developer reference for details.

## Before Any Import: Back Up

**Always take a full database backup before importing, no matter which source you use.** The importers read from your old forum's tables and never modify them, but importing creates new records in Jetonomy and cannot be automatically undone. A backup is your safety net if you want to start fresh.

Keep your old forum plugin (bbPress, wpForo, or Asgaros) **active** during the import - each importer reads directly from that plugin's live tables. You can deactivate it once you have confirmed the import looks right.

## Browser or WP-CLI?

You can run any import two ways. Use this to decide:

- **Browser (Jetonomy → Import)** - the simplest option, with a live progress bar. Best for small to medium communities (under roughly 50,000 topics + replies). The risk on large databases is a browser or server timeout part-way through.
- **WP-CLI (command line)** - the reliable option for large communities, because it is not subject to browser timeouts. Run it from your server's command line (SSH). The valid source values are `bbpress`, `wpforo`, and `asgaros` (all lowercase):

  ```bash
  wp jetonomy import bbpress
  wp jetonomy import wpforo
  wp jetonomy import asgaros
  ```

  If you run the command on a managed host where you must point at the WordPress install, add `--path`:

  ```bash
  wp --path="/path/to/wordpress" jetonomy import bbpress
  ```

  If you type a source name that does not exist, the command lists the valid ones back to you.

## Reading the Import Screen

When you open **Jetonomy → Import**, each forum plugin that Jetonomy detects appears as its own card. Here is what every part of the card means:

- **No Forum Data Detected** - if none of bbPress, wpForo, or Asgaros is installed with content, you see this empty state instead of cards. Install and add content to one of those plugins, then return.
- **Stat preview** - each detected source shows a live count of what it found (for example Forums, Topics, Replies). This is read straight from your old forum so you can confirm Jetonomy sees your data before you start.
- **Status badge** - one badge per card tells you the card's state:
  - **Available** - detected and ready to import; this is the normal first-time state.
  - **Previously Imported** - you have already run this import once. The card shows the date of the last import and how many records it brought over.
  - **Import Interrupted** - a browser import stopped before finishing. The card offers **Resume Import** to continue, or **Start Over** to begin again.
- **Re-Import warning** - once a source shows **Previously Imported**, its button changes to **Re-Import** and the card warns that *re-importing may create duplicate content*. Clicking it asks you to confirm first. Only re-import if the previous import had a problem - running it a second time on top of a successful import will duplicate your topics and replies.
- **Progress tracker** - while an import runs, a five-step tracker shows where it is: **Forums → Topics → Replies → Profiles → Finalize**, with a percentage progress bar underneath.

## After Any Import

These steps apply to every source. The individual guides list the same checklist with source-specific notes, but the essentials are:

- [ ] Visit your community home and confirm your spaces match your old forums.
- [ ] Open several posts and confirm the content and replies came across intact.
- [ ] **Re-assign moderators.** No importer brings over moderator assignments - set Space Moderator roles manually under **Jetonomy → Spaces**.
- [ ] **Flush permalinks if spaces 404.** Go to **Jetonomy → Settings → Permalinks** and click Save. (The bbPress importer does this for you automatically; wpForo and Asgaros do not, so do it by hand if new spaces return a 404.)
- [ ] **Clean up old shortcodes.** If your pages or widgets used your old forum's shortcodes, remove or replace them - they will print raw shortcode text while the old plugin is still active.
- [ ] Once everything checks out, you can deactivate the old forum plugin.

## What's Next?

Ready to import? Start with the guide for your forum software:

- [Importing from bbPress](01-bbpress-import.md)
- [Importing from wpForo](02-wpforo-import.md)
- [Importing from Asgaros Forum](03-asgaros-import.md)
