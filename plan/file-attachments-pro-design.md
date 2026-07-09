# File Attachments (Pro) — Design Spec

- **Date:** 2026-07-09
- **Feature:** General file attachments on topics & replies, with rich preview (images + PDF) and a lazy PDF.js modal viewer
- **Repos touched:** `jetonomy` (free — a couple of additive display/REST hooks + hardened upload endpoint) and `jetonomy-pro` (the `attachments` extension: link model, preview cards, viewer)
- **Status:** Design in progress. Planned in parallel with Anonymous Posting (see `anonymous-posting-pro-design.md`).

## 1. Problem & Goal

Members want to share files (PDFs, documents, images) on topics and replies. Today the only upload path is the composer's **image-only** `upload_image` REST endpoint, which embeds `<img>` inline in content HTML — there is no concept of a *file attachment*, no non-image support, and no viewer.

The product bar (stated by Varun): **uploading is not enough if we can't display it well.** A raw download link is a half-feature. So the viewer is the feature — rich preview cards in the stream and a proper inline PDF reader — not just storage.

## 2. Product Decisions (locked)

| Decision | Choice |
|---|---|
| Scope | **General file attachments** (not PDF-only). Attach one or more files to a topic or a reply. |
| Allowed types | **Images + PDF + Office docs** (jpg/png/gif/webp, pdf, docx/xlsx/pptx/odt/txt/csv). **SVG excluded** (XSS vector). |
| Display fidelity | **Images + PDF get rich preview**; **Office/text/csv get a download chip**. PDF click → **PDF.js modal**. |
| Limits & gating | **Global settings only** — allowed types, max file size (default 10MB), max files per post/reply (default 5). Applies to every space when the extension is on. |
| Storage | **WP media library** (via a hardened `media_handle_upload`) + a **`jt_pro_attachments` link table** (`object_type, object_id, attachment_id, sort`). |
| Interactive layer | Server-rendered cards (zero-JS baseline) + **lazy-imported PDF.js modal**, reusing the existing `jetonomy-modals.js` / `pro-view.js` vanilla stack. |

## 3. Architecture — Pro extension with a light free seam

Attachments are a **Pro feature**: if Pro is off, no attachments exist (they were only creatable through Pro), so — unlike Anonymous Posting — there is **no safety requirement to render them when Pro is inactive**. That lets Pro own both the write and the display, through free's display hooks, with only additive free changes.

### 3.1 Free responsibilities (`jetonomy`) — additive only

1. **Hardened upload endpoint.** Generalize/duplicate the image endpoint into an allow-list-guarded upload (`upload_attachment`) that validates **extension AND MIME against an explicit allow-list** before `media_handle_upload`, enforces the size cap, and sanitizes the filename. This also fixes the current `upload_image` gap (no MIME guard — it inherits WP's per-role mime types silently). The allow-list is filterable so Pro can extend it.
2. **Two additive display hooks** if not already present: `do_action( 'jetonomy_after_post_content', $post )` and `do_action( 'jetonomy_after_reply_content', $reply )` — the anchor where Pro renders the attachment card strip. (Reuse `jetonomy_post_card_after_badges` / `jetonomy_reply_actions` if placement fits; otherwise add these.)
3. **REST attachment fields.** Expose a filter so Pro can add an `attachments[]` array to post/reply REST payloads (app parity), or register additional REST fields from Pro. No free storage change.

No free schema change, no free table. Free's only new persistent surface is the hardened endpoint.

### 3.2 Pro responsibilities (`jetonomy-pro`)

New extension `includes/extensions/attachments/class-extension.php` (id `attachments`, opt-in via `jetonomy_pro_extensions`). It wires:

1. **Link table** `jt_pro_attachments` — `id, object_type ENUM('post','reply'), object_id BIGINT, attachment_id BIGINT, sort SMALLINT, created_at`. Keys: `KEY object (object_type, object_id, sort)`, `KEY attachment (attachment_id)`. Created in `activate()`, dropped in `uninstall`.
2. **Global settings** — `jetonomy_pro_attachments` option: `{ allowed_types[], max_size_bytes, max_files }`. Rendered on the Pro settings tab; owner can extend the default allow-list (each added type is explicit + MIME-validated).
3. **Compose UX** — an "Attach files" control via `jetonomy_composer_toolbar` (shared post+reply composer). Extends `composer.js`: drag/drop + file picker, client-side type/size/count pre-check (server re-validates), upload progress, thumbnail strip, reorder + remove. On submit, the attachment IDs are linked to the new post/reply via `jetonomy_after_create_post` / `jetonomy_after_create_reply`.
4. **Server-rendered preview cards** — hooked on `jetonomy_after_post_content` / `jetonomy_after_reply_content`: an attachment strip. Per type: image → `<img>` card (native), PDF → first-page thumbnail card (page count + size) that opens the viewer, Office/text → download chip (icon + filename + size, `Content-Disposition: attachment`). Batch-loaded per feed page (one `WHERE object_id IN (...)` query — no N+1).
5. **PDF.js modal viewer** — `attachments-frontend.js` (Pro): clicking a PDF card **dynamically imports** pdf.js and opens it inside the existing `jetonomy-modals.js` modal (focus-trap, ESC, page nav, zoom). Never in the initial bundle. Fallback: "open in new tab" if pdf.js fails or native is preferred.
6. **REST** — attachments appear in post/reply payloads; a Pro endpoint `POST /jetonomy-pro/v1/attachments` (attach, capability + nonce, re-runs the allow-list) and `DELETE .../attachments/{id}` (detach; own-attachment or moderator only). Three entry points satisfied: composer (frontend), admin moderation view + settings (backend), REST (API).

## 4. Interactive Layer & Performance Justification

The heavy part (PDF.js) must earn its place. It does, because:

1. **Extends the existing stack, adds no runtime.** Reuses `jetonomy-modals.js` (modal) + `composer.js` (upload) + `pro-view.js` (Pro runtime). One new Pro module. No new framework; consistent with the plugin's vanilla frontend.
2. **Static baseline.** Cards are server-rendered HTML — zero JS to view/download attachments. Crawlable, a11y-friendly, big-site safe (no live viewers in a scroll). The interactive layer is never load-bearing for *access*.
3. **Pay-per-use.** pdf.js (~1MB) is dynamically imported only on a PDF card click — never enqueued on pages without a PDF, never in the initial bundle. Cost falls on the one user who opens a PDF, on that click.
4. **Build once, reuse everywhere.** One viewer component invoked from any PDF card (post/reply, feed/single). One a11y/focus/ESC implementation.
5. **Bounded + degrades.** Viewer invoked only for PDFs; images are native `<img>`; Office is a download chip; pdf.js failure → open-in-new-tab. Can't become dead weight.

## 5. Security (allow-list, not blocklist)

- **Double validation** — extension AND MIME sniff (`wp_check_filetype_and_ext` + `finfo`) must both match the allow-list. Reject on mismatch.
- **SVG excluded** entirely (script vector) unless a sanitizer is added later (out of scope).
- **Size cap** enforced server-side (default 10MB) regardless of client check; **count cap** per object.
- **Filename sanitized** (`sanitize_file_name`); stored via core so it lands in `uploads/` with WP's protections.
- **Non-previewable types served as downloads** (`Content-Disposition: attachment`) — never inline-executed.
- **Capability** — upload requires logged-in member with an existing content-create capability; detach requires ownership or moderator cap.
- **Orphan control** — attachments not linked within a TTL (draft abandoned) are swept by a cron GC; deleting a post/reply detaches + optionally deletes owned attachments.

## 6. Data Model

- **`jt_pro_attachments`** (new Pro table) — links WP attachment IDs to posts/replies with ordering. The only new table.
- **WP media library** (`wp_posts` attachments + `uploads/`) — actual file storage, thumbnails, and PDF first-page rasterization (Imagick + Ghostscript when the host has them).
- **`jetonomy_pro_attachments`** option — global limits + allow-list.
- No free schema change.

## 7. Big-Site Readiness (per portfolio checklist)

- **N+1 avoided** — attachments for a feed page are batch-loaded in one `IN (...)` query keyed on `(object_type, object_id)`; the `object` index covers it.
- **No unbounded render** — cards are static; pdf.js is click-to-load; a post is capped at `max_files`.
- **Thumbnails** come from core (cached) with an icon-card fallback when the host can't rasterize PDFs — the rich preview is progressive enhancement, never assumed.
- **Pagination** of the feed is unaffected; attachment strip is a bounded per-row add.
- **Caching/invalidation** — the rendered strip participates in the existing post/reply render cache; linking/detaching busts that key.

## 8. Mobile / RTL / Dark Mode / A11y

- Cards use design tokens (dark-mode + RTL safe, `margin-inline-*`); strip stacks under 480px; tap targets ≥ 40px.
- Modal viewer is keyboard-operable (focus-trap, ESC, arrow page-nav), labeled controls, and announces page count.
- Upload control is a real labeled input; drag-drop has a keyboard-reachable file-picker equivalent.
- Every card control has an aria-label; download chips are real links.

## 9. i18n / Manifests / Docs

- All strings wrapped (`Attach files`, `Download`, `Page X of Y`, size/error strings), incl. JS store keys (match the 1.6.1 i18n sweep).
- Update **free** manifest (hardened upload endpoint, the two display hooks, the REST attachment filter) and **Pro** manifest (`attachments` extension, table, option, REST routes).
- Customer docs (free `docs/website/`): "File attachments" page — enabling, limits, supported types, host requirement for PDF previews. WooCommerce-style changelog on release.

## 10. Three Entry Points (data-store rule)

- **Frontend** — composer attach UX; preview cards + PDF.js modal in feed/single.
- **Backend/admin** — Pro settings (types/size/count); attachments visible + removable in the moderation/single-item admin view.
- **REST** — attachments in post/reply payloads; attach/detach endpoints. All three ship together.

## 11. Testing / Verification

- **Upload validation matrix** — allowed vs disallowed extension/MIME, oversize, over-count, extension/MIME mismatch, SVG rejected.
- **Link lifecycle** — attach on create, detach, delete-post cascade, orphan GC.
- **Render** — image card, PDF card (with + without host rasterization → icon fallback), Office download chip; batch-load = one query (assert no N+1).
- **Viewer** — pdf.js lazy-imports on click only (assert not in initial bundle), modal a11y (focus-trap/ESC), open-in-new-tab fallback.
- **REST parity** — attachments present in app payloads; attach/detach permission checks.
- **Browser (Playwright MCP, incl. 390px)** — attach flow, card render, modal open/navigate/close, mobile stack.

## 12. Out of Scope (YAGNI)

- Inline Office-doc preview (docx/xlsx render) — download only.
- Video/audio transcoding or players.
- Cloud-storage UI (rely on existing WP offload plugins via the media library).
- Image editing/cropping in the composer.
- SVG support (needs a sanitizer; revisit separately).
- Per-space attachment rules (global only this release).
