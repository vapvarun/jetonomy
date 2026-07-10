Let members attach images, PDFs, and documents to topics and replies, with server-rendered preview cards and a lazy-loaded inline PDF viewer.

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- How to enable File Attachments
- How to configure allowed file types, max file size, and max files per post
- What members see for images, PDFs, and documents
- How attachments are validated, cleaned up, and cascade-deleted
- How to use the REST API to attach, detach, download, and batch-read attachments

## Why File Attachments Matter

A screenshot, log file, or spec document often explains a problem faster than a paragraph of text. File Attachments lets members back up their posts and replies with real files, directly in the thread, instead of pasting external links that rot over time.

## Enabling File Attachments

1. Go to **Jetonomy → Extensions**, find **File Attachments**, and click **Enable**.
2. The composer gains an **Attach files** control - a full **Attach files** button on the new-topic form, and a compact paperclip icon in the reply toolbar.

## Configuring Allowed Types, Size, and Count

Go to **Jetonomy → Settings → Attachments** to control:

| Setting | Default | Description |
|---------|---------|--------------|
| Allowed file types | jpg, jpeg, png, gif, webp, pdf, docx, xlsx, pptx, odt, txt, csv | Grouped as Images and Documents; toggle each type independently |
| Max file size | 10 MB | Per file |
| Max files per post/reply | 5 | Enforced on both the composer and the REST endpoint |

**SVG is never available**, because it can carry executable scripts. Every upload is re-validated against this allow-list on the server - the file extension and the actual file content must both match an allowed type - so the settings are a real security boundary, not just a UI restriction.

## What Members See

Attached files render as cards under the post or reply:

- **Images** show an inline thumbnail; click to enlarge.
- **PDFs** show a first-page preview with `filename · pages · size` and an **Open viewer** button that opens the file inline, with page navigation and zoom, or in a new tab. The thumbnail needs your host's Imagick and Ghostscript support - if unavailable, the card falls back to a document icon and the file still opens and downloads normally.
- **Documents** (DOCX, XLSX, PPTX, ODT, TXT, CSV) show a download chip with the file type and size.

The cards are server-rendered on every page load, so they show correctly even with JavaScript disabled. The PDF viewer library itself is only downloaded the first time a member opens a PDF - never on a normal page load - so it does not slow down browsing.

## Data Lifecycle

- **Removing an attachment** detaches it from the post or reply; the underlying uploaded file is left alone in case it is still referenced elsewhere.
- **Deleting a topic or reply** removes its attachment links automatically.
- **Orphan cleanup** runs once a day: it drops link rows whose underlying file was removed some other way, and deletes community uploads that were never linked to any post or reply within 24 hours of being uploaded. This sweep is scoped strictly to files uploaded through the community composer, so it never touches the site owner's own WordPress media library.

## REST API

File Attachments registers these endpoints under `jetonomy-pro/v1`:

| Method | Endpoint | Description |
|--------|----------|--------------|
| `POST` | `/attachments` | Link an already-uploaded attachment to a post or reply |
| `DELETE` | `/attachments/{id}` | Detach an attachment from its post or reply |
| `GET` | `/attachments/{id}/download` | Download the file |
| `GET` | `/attachments/batch` | Batch-read attachments for many posts or replies in one call |

`{id}` on `DELETE` and the download route is the attachment **link** ID, not the underlying WordPress attachment ID. Attaching requires the `jetonomy_create_posts`, `jetonomy_create_replies`, or `jetonomy_upload_media` capability and ownership of the uploaded file; detaching requires the same ownership or the `moderate_comments` capability. The batch route requires `jetonomy_manage_settings` or `moderate_comments` and is meant for admin/moderator listing, not the member-facing composer.

**POST /attachments - body**

```json
{
  "object_type": "post",
  "object_id": 512,
  "attachment_id": 4820,
  "sort": 0
}
```

`attachment_id` is the WordPress attachment ID returned by `POST /jetonomy/v1/media`. The file is re-validated against the Attachments allow-list on attach, and the per-object file cap from the settings page is enforced server-side.

Posts and replies also carry an `attachments[]` array directly on their normal `GET`/list responses, so most clients never need to call these routes directly except to attach or detach a file. See the [REST API reference](../developer-guide/01-rest-api.md) for full payloads.

## Related

- [Extensions](../admin-settings/13-extensions.md) - where File Attachments is enabled or disabled
- [File Attachments (Getting Started)](../getting-started/file-attachments.md) - the quick member-facing overview

## What's Next?

You have now seen every Pro feature. Return to the [Pro getting-started guide](00-getting-started-pro.md) to choose which extensions to enable for your community.
