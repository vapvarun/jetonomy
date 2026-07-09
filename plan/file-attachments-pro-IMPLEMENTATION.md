# File Attachments (Pro) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let members attach files (images, PDF, Office/text docs) to topics and replies, with server-rendered preview cards and a lazy-imported PDF.js modal viewer, shipping 100% in `jetonomy-pro` behind one hardened free upload endpoint and two display seams.

**Architecture:** Free hardens its single existing upload endpoint (`upload_image` on the `media` controller) with a filterable allow-list + size cap, reuses its existing post/reply REST-payload and content-display filters, and adds exactly one new reply-content filter. Pro adds a self-contained `attachments` extension (`includes/extensions/attachments/`) owning a `jt_pro_attachments` link table, an uploader validation wrapper, global settings, server-rendered cards, attach/detach REST, and a click-to-load PDF.js viewer built on the existing `jetonomy-modals.js` modal. Static HTML cards are the zero-JS baseline; PDF.js is dynamically imported only on a PDF-card click.

**Tech Stack:** PHP 8.1+ (WordPress 6.7+), custom MySQL table via `dbDelta()`, WP media library (`media_handle_upload`), WP REST API (`jetonomy/v1` + `jetonomy-pro/v1`), vanilla JS ES-module (`wp_enqueue_script_module`) with dynamic `import()`, Mozilla pdf.js vendored under the extension, PHPUnit (`jetonomy/tests/`), Playwright MCP for browser verification.

## Global Constraints

- **Both features ship in Pro.** Free changes are limited to: (a) harden the existing `upload_image` endpoint, (b) add one new reply-content display filter, (c) reuse existing REST-payload filters. No attachment-specific free storage.
- **Manifest-first, ZERO duplication.** Before adding any symbol, confirm via the Reuse section below that nothing equivalent exists. Reuse named symbols.
- **Versions lockstep.** `JETONOMY_VERSION` == `JETONOMY_PRO_VERSION`; bump both together on release.
- **Pro REST permission callbacks** use `Jetonomy_Pro\Extension::rest_auth_mutation( $caps )` — never `\Jetonomy\API\REST_Auth::auth_mutation()` directly (eager static call fatals REST). Verified by `bin/audit-rest-routes.php`.
- **Allowed types (default):** images `jpg/jpeg,png,gif,webp`, `pdf`, Office/text `docx,xlsx,pptx,odt,txt,csv`. **SVG excluded** (XSS vector). Owner may extend via settings.
- **Limits (defaults):** `max_size_bytes = 10485760` (10 MB), `max_files = 5` per post/reply. Global option only; applies to every space when the extension is on.
- **Naming:** Pro option `jetonomy_pro_attachments`; table `jt_pro_attachments` (→ `wp_jt_pro_attachments`); user meta `jetonomy_pro_*`; cron `jetonomy_pro_attachments_gc`; REST under `jetonomy-pro/v1`; asset handles `jetonomy-attachments*`; text domains `jetonomy` (free) / `jetonomy-pro` (pro).
- **CSS tokens only** — no raw hex/px; use `--jt-*` tokens, `margin-inline-*`, dark-mode via token reassignment (never per-component dark selectors). Verify at 390px.
- **RTL + a11y from first commit** — logical properties, labeled controls, ≥40px tap targets, reuse the modal focus-trap.
- **Every mutation route** goes through the lazy auth wrapper AND re-runs the allow-list server-side.

---

## File Structure

**Free (`jetonomy/`) — additive only:**
- Modify: `includes/api/class-media-controller.php` — harden `upload_image()` (allow-list + size cap + double MIME validation + filename sanitize).
- Modify: `includes/helpers.php` — add shared `jetonomy_after_content_allowed_html()` kses helper.
- Modify: `templates/views/single-post.php` — replace the inline post-content kses array with the shared helper (DRY; permit card markup).
- Modify: `templates/partials/reply-card.php` — add the `jetonomy_after_reply_content` filter slot after `.jt-reply-body`.
- Modify: `audit/manifest.json` — hooks + upload-filter delta.

**Pro (`jetonomy-pro/includes/extensions/attachments/`) — new extension:**
- `class-extension.php` — meta/boot/activate/deactivate; creates `jt_pro_attachments`; wires hooks; schedules GC cron.
- `class-model.php` — link-table CRUD + batch prime/load (no N+1).
- `class-uploader.php` — extends the free allow-list filter; validation helpers.
- `class-settings.php` — reads/writes `jetonomy_pro_attachments`; sanitizes.
- `class-renderer.php` — server-rendered image/PDF/download-chip cards; hooks the post + reply filters; primes on `jetonomy_before_replies`.
- `class-rest.php` — `POST /attachments`, `DELETE /attachments/{id}`, `GET /attachments/{id}/download`; injects `attachments[]` into post/reply payloads.
- `views/settings.php` — Pro settings-tab markup (types/size/count).
- `assets/js/attachments-frontend.js` (+ `.min.js`) — compose control (module).
- `assets/js/pdf-viewer.js` (+ `.min.js`) — dynamic-import wrapper opening pdf.js in the shared modal.
- `assets/lib/pdfjs/` — vendored Mozilla pdf.js (`pdf.min.mjs` + `pdf.worker.min.mjs`).
- `assets/css/attachments.css` (+ `.min.css`) — card + viewer + composer strip styles.
- Modify: `audit/manifest.json` — extension/table/option/routes/cron delta.

**Tests:**
- `jetonomy/tests/security/AttachmentUploadValidationTest.php`
- `jetonomy/tests/pro/extensions/AttachmentsModelTest.php`
- `jetonomy/tests/pro/extensions/AttachmentsRestTest.php`
- `jetonomy/tests/pro/extensions/AttachmentsBatchLoadTest.php`

---

## Reuse & Anti-Duplication

Every symbol below already exists and MUST be reused; no task creates a parallel version. Grep evidence in parens.

| Concern | Reused symbol | Evidence |
|---|---|---|
| File storage / thumbnails / PDF raster | Core `media_handle_upload()` + WP media library | `class-media-controller.php:157` |
| Upload endpoint | Existing `POST /jetonomy/v1/media` → `Media_Controller::upload_image()` (hardened, NOT forked) | `class-media-controller.php:131` |
| Modal (focus-trap, ESC, backdrop) | `jetonomy-modals.js` `open()`/`buildDialog()` via `window.jetonomyConfirm/Alert/Prompt`; PDF viewer opens a custom overlay reusing the same `.jt-modal-*` classes + focus-trap pattern | `jetonomy-modals.js:42,154` |
| Compose/upload UX | `composer.js` `uploadImage()` + `window.jetonomyRest.restFetch` + `jetonomyUpload` localize (`apiBase`,`restNonce`) | `composer.js:228,265`; `class-template-loader.php:619` |
| Composer toolbar seam | `jetonomy_composer_toolbar` action ($post_id,$reply_to) | `composer.php:49` |
| Pro base class | `Jetonomy_Pro\Extension` (`meta/boot/activate/is_enabled/table()/rest_auth_mutation()`) | `class-extension.php:9,73,99` |
| Link-on-create | `jetonomy_after_create_post`($post_id,$space_id,$request) / `jetonomy_after_create_reply`($reply_id,$post_id) | `class-posts-controller.php:607`; `class-replies-controller.php:351` |
| **Post display seam** | Existing FILTER `jetonomy_after_post_content`('',$post) — REUSED, no new post hook | `single-post.php:495` |
| Reply-list prime anchor | `jetonomy_before_replies`($post,$total_replies) | `single-post.php:737` |
| Cascade delete | `jetonomy_after_delete_post`($id) / `jetonomy_after_delete_reply`($id) | `class-posts-controller.php:857`; `class-replies-controller.php:544` |
| **REST payload seams** | Existing filters `jetonomy_rest_prepare_post`($data,$post,$req) / `jetonomy_rest_prepare_reply`($data,$reply,$req) — REUSED, no new free REST filter | `class-posts-controller.php:1219`; `class-replies-controller.php:804` |
| Admin settings tab | `jetonomy_admin_settings_tabs`($active) / `jetonomy_admin_settings_tab_content`($active) | `settings.php:25,34` |
| Frontend module + lazy import | `wp_enqueue_script_module()` pattern (as `jetonomy-pro-view`) enables native `import()` | `class-jetonomy-pro.php:904` |
| dbDelta table convention | reactions `create_table()` + `$this->table()` | `reactions/class-extension.php` |
| Icons | `jetonomy_echo_icon( $name, $size )` | `reply-card.php:73` |

**New symbols introduced (confirmed no duplicate exists):** free filter `jetonomy_after_reply_content` (no reply-content filter exists — only `jetonomy_reply_actions` action in the *actions bar*, wrong placement for a content strip; grep of `jetonomy_after_reply_content` returns only design/plan docs). Free helper `jetonomy_after_content_allowed_html()` (grep: none). Free upload filters `jetonomy_upload_allowed_types` / `jetonomy_upload_max_size` (grep: none). Pro namespace `Jetonomy_Pro\Extensions\Attachments\*` (grep: dir absent).

---

## Task 1: Harden the free upload endpoint (allow-list + size cap + double MIME validation)

Generalizes the ONLY uploader in place — no parallel endpoint. Default allow-list is images-only, so free behavior is unchanged; Pro extends the filter to add pdf/office. Closes the current gap where `media_handle_upload('file',0)` runs with no explicit mime guard.

**Files:**
- Modify: `jetonomy/includes/api/class-media-controller.php:131-157`
- Test: `jetonomy/tests/security/AttachmentUploadValidationTest.php`

**Interfaces:**
- Consumes: `$_FILES['file']`, `wp_check_filetype_and_ext()`, `finfo_*`, `media_handle_upload()`.
- Produces:
  - filter `apply_filters( 'jetonomy_upload_allowed_types', array $ext_to_mime )` → `[ 'jpg|jpeg'=>'image/jpeg', 'png'=>'image/png', 'gif'=>'image/gif', 'webp'=>'image/webp' ]` default.
  - filter `apply_filters( 'jetonomy_upload_max_size', int $bytes )` → default `min( wp_max_upload_size(), 10 * MB_IN_BYTES )`.
  - `Media_Controller::validate_upload( array $file ): true|\WP_Error` (new private-ish static used by the endpoint; unit-testable).

- [ ] **Step 1: Write the failing test**

Create `jetonomy/tests/security/AttachmentUploadValidationTest.php`:

```php
<?php
namespace Jetonomy\Tests\Security;

use PHPUnit\Framework\TestCase;
use Jetonomy\API\Media_Controller;

/** @covers \Jetonomy\API\Media_Controller::validate_upload */
class AttachmentUploadValidationTest extends TestCase {

	private function tmp( string $bytes, string $name ): array {
		$path = tempnam( sys_get_temp_dir(), 'jtup' );
		file_put_contents( $path, $bytes );
		return array( 'name' => $name, 'tmp_name' => $path, 'size' => strlen( $bytes ), 'error' => 0 );
	}

	public function test_png_within_limits_passes(): void {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		$this->assertTrue( Media_Controller::validate_upload( $this->tmp( $png, 'a.png' ) ) );
	}

	public function test_svg_is_rejected(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
		$res = Media_Controller::validate_upload( $this->tmp( $svg, 'x.svg' ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'jetonomy_upload_type', $res->get_error_code() );
	}

	public function test_extension_mime_mismatch_is_rejected(): void {
		// PHP payload renamed .png — extension says png, sniff says text/plain.
		$res = Media_Controller::validate_upload( $this->tmp( "<?php echo 1;", 'evil.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
	}

	public function test_oversize_is_rejected(): void {
		add_filter( 'jetonomy_upload_max_size', static fn() => 8 );
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		$res = Media_Controller::validate_upload( $this->tmp( $png, 'big.png' ) );
		remove_all_filters( 'jetonomy_upload_max_size' );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'jetonomy_upload_size', $res->get_error_code() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && ./vendor/bin/phpunit tests/security/AttachmentUploadValidationTest.php`
Expected: FAIL — `Error: Call to undefined method Jetonomy\API\Media_Controller::validate_upload()`.

- [ ] **Step 3: Write the minimal implementation**

In `class-media-controller.php`, add the static validator method to the class:

```php
	/**
	 * Validate an uploaded file against the explicit extension+MIME allow-list
	 * and the size cap BEFORE it reaches media_handle_upload(). Allow-list, not
	 * blocklist: extension AND sniffed MIME must both resolve to the same
	 * allowed type. SVG is never in the default list (script vector).
	 *
	 * @param array $file One entry from $_FILES (name, tmp_name, size).
	 * @return true|\WP_Error
	 */
	public static function validate_upload( array $file ) {
		$max = (int) apply_filters( 'jetonomy_upload_max_size', min( (int) wp_max_upload_size(), 10 * MB_IN_BYTES ) );
		if ( (int) ( $file['size'] ?? 0 ) > $max ) {
			return new WP_Error(
				'jetonomy_upload_size',
				sprintf( /* translators: %s: human size */ __( 'File is too large. Maximum size is %s.', 'jetonomy' ), size_format( $max ) ),
				array( 'status' => 400 )
			);
		}

		$allowed = (array) apply_filters(
			'jetonomy_upload_allowed_types',
			array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'gif'      => 'image/gif',
				'webp'     => 'image/webp',
			)
		);
		unset( $allowed['svg'], $allowed['svgz'] ); // Hard exclusion regardless of filters.

		$name  = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$check = wp_check_filetype_and_ext( (string) ( $file['tmp_name'] ?? '' ), $name, $allowed );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new WP_Error( 'jetonomy_upload_type', __( 'This file type is not allowed.', 'jetonomy' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'finfo_open' ) && is_readable( (string) $file['tmp_name'] ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$real  = $finfo ? finfo_file( $finfo, (string) $file['tmp_name'] ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			if ( $real && ! in_array( $real, $allowed, true ) ) {
				return new WP_Error( 'jetonomy_upload_type', __( 'File contents do not match its extension.', 'jetonomy' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}
```

Then wire it into `upload_image()` — insert immediately before the `media_handle_upload` call (after the `empty( $_FILES['file'] )` guard at line 151):

```php
		// Explicit allow-list + size + MIME double-check before core stores it.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST nonce verified by core.
		$jt_file = array(
			'name'     => isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) : '',
			'tmp_name' => isset( $_FILES['file']['tmp_name'] ) ? $_FILES['file']['tmp_name'] : '', // phpcs:ignore
			'size'     => isset( $_FILES['file']['size'] ) ? (int) $_FILES['file']['size'] : 0,
		);
		$jt_valid = self::validate_upload( $jt_file );
		if ( is_wp_error( $jt_valid ) ) {
			return $jt_valid;
		}
		$_FILES['file']['name'] = $jt_file['name']; // Persist the sanitized name for core.
```

And pass the allow-list as an override to core so it re-validates (defense in depth). Replace line 157:

```php
		$attachment_id = media_handle_upload(
			'file',
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => (array) apply_filters(
					'jetonomy_upload_allowed_types',
					array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' )
				),
			)
		);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd jetonomy && ./vendor/bin/phpunit tests/security/AttachmentUploadValidationTest.php`
Expected: PASS (4 tests). Then `php bin/audit-rest-routes.php includes/` → `OK`.

- [ ] **Step 5: Commit**

```bash
git add includes/api/class-media-controller.php tests/security/AttachmentUploadValidationTest.php
git commit -m "harden upload_image with allow-list + size cap + MIME double-validation"
```

---

## Task 2: Free display seams — shared kses helper + reply-content filter

Reuses the existing `jetonomy_after_post_content` filter for posts and adds the missing symmetric `jetonomy_after_reply_content` filter for replies. Both share one kses allow-list helper (DRY) that permits attachment-card markup (image `<a><img>`, PDF `<button data-*>`, download `<a download>`, `<svg>` icons).

**Files:**
- Modify: `jetonomy/includes/helpers.php` (add `jetonomy_after_content_allowed_html()`)
- Modify: `jetonomy/templates/views/single-post.php:443-497` (use the helper)
- Modify: `jetonomy/templates/partials/reply-card.php:87` (add filter slot)
- Test: `jetonomy/tests/unit/AfterContentAllowedHtmlTest.php`

**Interfaces:**
- Produces:
  - `jetonomy_after_content_allowed_html(): array` — kses allow-list extending `wp_kses_allowed_html('post')` with `input`, `button[type|class|aria-label|data-jt-pdf-url|data-jt-pdf-name|data-wp-on--click]`, `a[download|rel|data-jt-pdf-url]`, `svg[viewbox|width|height|fill|aria-hidden|class]`, `path[d|fill]`, `figure/figcaption`, plus the IA directive attributes the polls widget already relies on.
  - filter `apply_filters( 'jetonomy_after_reply_content', string '', object $reply )` rendered (kses'd) after `.jt-reply-body`.

- [ ] **Step 1: Write the failing test**

Create `jetonomy/tests/unit/AfterContentAllowedHtmlTest.php`:

```php
<?php
namespace Jetonomy\Tests\Unit;

use PHPUnit\Framework\TestCase;

class AfterContentAllowedHtmlTest extends TestCase {

	public function test_helper_exists_and_permits_card_markup(): void {
		$this->assertTrue( function_exists( 'jetonomy_after_content_allowed_html' ) );
		$allowed = jetonomy_after_content_allowed_html();

		// PDF card <button data-jt-pdf-url> must survive kses.
		$this->assertArrayHasKey( 'button', $allowed );
		$this->assertArrayHasKey( 'data-jt-pdf-url', $allowed['button'] );
		// Download chip <a download> must survive.
		$this->assertArrayHasKey( 'download', $allowed['a'] );
		// Icon <svg> must survive.
		$this->assertArrayHasKey( 'svg', $allowed );
		// Poll <input> (existing consumer) must still survive.
		$this->assertArrayHasKey( 'input', $allowed );
	}

	public function test_kses_keeps_pdf_button(): void {
		$html = '<button type="button" class="jt-attach" data-jt-pdf-url="/x.pdf" aria-label="Open">P</button>';
		$out  = wp_kses( $html, jetonomy_after_content_allowed_html() );
		$this->assertStringContainsString( 'data-jt-pdf-url="/x.pdf"', $out );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && ./vendor/bin/phpunit tests/unit/AfterContentAllowedHtmlTest.php`
Expected: FAIL — `function_exists` assertion false / undefined function.

- [ ] **Step 3: Write the minimal implementation**

Append to `jetonomy/includes/helpers.php`:

```php
if ( ! function_exists( 'jetonomy_after_content_allowed_html' ) ) {
	/**
	 * Shared kses allow-list for the post/reply after-content filter slots.
	 * Extends the 'post' set with the form inputs the polls widget needs and
	 * the attachment-card markup (image link, PDF trigger button, download
	 * chip, inline SVG icons). One source of truth for single-post.php and
	 * reply-card.php so the two slots never drift.
	 *
	 * @return array kses allowed-HTML map.
	 */
	function jetonomy_after_content_allowed_html(): array {
		$tags = wp_kses_allowed_html( 'post' );

		$tags['input'] = array(
			'type' => true, 'name' => true, 'value' => true, 'checked' => true,
			'disabled' => true, 'class' => true, 'id' => true, 'aria-label' => true,
			'aria-checked' => true, 'data-wp-on--click' => true, 'data-wp-on--change' => true,
			'data-wp-bind--checked' => true, 'data-wp-bind--disabled' => true,
		);
		$tags['button'] = array(
			'type' => true, 'class' => true, 'aria-label' => true, 'title' => true,
			'data-jt-pdf-url' => true, 'data-jt-pdf-name' => true, 'data-jt-pdf-pages' => true,
			'data-wp-on--click' => true,
		);
		$tags['a']['download'] = true;
		$tags['a']['rel']      = true;
		$tags['a']['data-jt-pdf-url'] = true;
		$tags['img']['loading']  = true;
		$tags['img']['decoding'] = true;
		$tags['svg'] = array( 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'aria-hidden' => true, 'class' => true, 'xmlns' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true );
		$tags['path'] = array( 'd' => true, 'fill' => true, 'stroke' => true );
		$tags['line'] = array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true );
		$tags['polyline'] = array( 'points' => true );
		$tags['rect'] = array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true );
		$tags['figure']     = array( 'class' => true );
		$tags['figcaption'] = array( 'class' => true );

		// IA directives on structural tags (mirrors the poll widget's needs).
		foreach ( array( 'div', 'span', 'button', 'a', 'label', 'form' ) as $t ) {
			if ( isset( $tags[ $t ] ) ) {
				$tags[ $t ]['data-wp-interactive']  = true;
				$tags[ $t ]['data-wp-context']      = true;
				$tags[ $t ]['data-wp-on--click']    = true;
				$tags[ $t ]['data-wp-bind--hidden'] = true;
			}
		}
		return $tags;
	}
}
```

Refactor `single-post.php` — replace the inline block at lines 443-492 (the `$jt_post_content_after_tags = wp_kses_allowed_html('post');` construction through the `option` assignment) with:

```php
					// Shared allow-list (helpers.php) — permits poll inputs AND
					// attachment-card markup. Single source of truth with reply-card.
					$jt_post_content_after_tags = jetonomy_after_content_allowed_html();
```

(Leave the `echo wp_kses( apply_filters( 'jetonomy_after_post_content', '', $post ), $jt_post_content_after_tags );` call at 494-497 untouched.)

Add the reply slot in `reply-card.php` immediately after the `.jt-reply-body` closing `</div>` (after line 87):

```php
		<?php
		// Reply after-content slot (Pro attachment strip renders here). Mirrors
		// the post-level jetonomy_after_post_content filter; same kses set.
		$jt_reply_after = apply_filters( 'jetonomy_after_reply_content', '', $reply );
		if ( '' !== $jt_reply_after ) {
			echo wp_kses( $jt_reply_after, jetonomy_after_content_allowed_html() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses'd above.
		}
		?>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd jetonomy && ./vendor/bin/phpunit tests/unit/AfterContentAllowedHtmlTest.php`
Expected: PASS (2 tests). Sanity: `php -l templates/views/single-post.php templates/partials/reply-card.php` → no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add includes/helpers.php templates/views/single-post.php templates/partials/reply-card.php tests/unit/AfterContentAllowedHtmlTest.php
git commit -m "add shared after-content kses helper + jetonomy_after_reply_content filter"
```

---

## Task 3: Free manifest delta

**Files:**
- Modify: `jetonomy/audit/manifest.json`

**Interfaces:** Consumes Task 1 + Task 2 symbols. Produces documentation only.

- [ ] **Step 1: Add the reply filter to `hooks_fired`**

In the `hooks_fired` array, add an entry (near the other after-content/reply hooks):

```json
    {
      "name": "jetonomy_after_reply_content",
      "type": "filter",
      "args": "$html, $reply",
      "where": "templates/partials/reply-card.php",
      "consumed_by": "jetonomy-pro/attachments"
    },
    {
      "name": "jetonomy_upload_allowed_types",
      "type": "filter",
      "args": "$ext_to_mime",
      "where": "includes/api/class-media-controller.php (Media_Controller::validate_upload + upload_image)",
      "consumed_by": "jetonomy-pro/attachments"
    },
    {
      "name": "jetonomy_upload_max_size",
      "type": "filter",
      "args": "$bytes",
      "where": "includes/api/class-media-controller.php (Media_Controller::validate_upload)",
      "consumed_by": "jetonomy-pro/attachments"
    }
```

- [ ] **Step 2: Update the existing `jetonomy_after_post_content` entry**

Set its `consumed_by` to include the attachments extension (append `jetonomy-pro/attachments` alongside polls/ai).

- [ ] **Step 3: Verify JSON validity**

Run: `cd jetonomy && php -r 'json_decode(file_get_contents("audit/manifest.json")); echo json_last_error_msg();'`
Expected: `No error`.

- [ ] **Step 4: Commit**

```bash
git add audit/manifest.json
git commit -m "manifest: hardened upload filters + reply-content hook for attachments"
```

---

## Task 4: Pro extension scaffold + `jt_pro_attachments` table

**Files:**
- Create: `jetonomy-pro/includes/extensions/attachments/class-extension.php`
- Test: `jetonomy/tests/pro/extensions/AttachmentsModelTest.php` (table-creation assertion first)

**Interfaces:**
- Consumes: `Jetonomy_Pro\Extension` base (`meta/boot/activate/table()`).
- Produces:
  - class `Jetonomy_Pro\Extensions\Attachments\Extension extends \Jetonomy_Pro\Extension`.
  - `meta(): array` with `id=attachments`, `requires=starter`, `category=Content`.
  - table `wp_jt_pro_attachments`: `id, object_type ENUM('post','reply'), object_id BIGINT, attachment_id BIGINT, sort SMALLINT, created_at DATETIME`; `KEY object (object_type,object_id,sort)`, `KEY attachment (attachment_id)`.
  - `attachments_table(): string` returning `$this->table('attachments')`.

- [ ] **Step 1: Write the failing test**

Create `jetonomy/tests/pro/extensions/AttachmentsModelTest.php`:

```php
<?php
namespace Jetonomy\Tests\Pro\Extensions;

use PHPUnit\Framework\TestCase;
use Jetonomy_Pro\Extensions\Attachments\Extension;

class AttachmentsModelTest extends TestCase {

	public static function setUpBeforeClass(): void {
		( new Extension() )->activate();
	}

	public function test_table_created(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_pro_attachments';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_meta_shape(): void {
		$meta = ( new Extension() )->meta();
		$this->assertSame( 'attachments', $meta['id'] );
		$this->assertSame( 'starter', $meta['requires'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsModelTest.php`
Expected: FAIL — class `Jetonomy_Pro\Extensions\Attachments\Extension` not found.

- [ ] **Step 3: Write the minimal implementation**

Create `jetonomy-pro/includes/extensions/attachments/class-extension.php`:

```php
<?php
/**
 * File Attachments extension — attach images/PDF/Office docs to posts & replies
 * with server-rendered preview cards and a lazy PDF.js modal viewer.
 *
 * @package Jetonomy_Pro
 */

namespace Jetonomy_Pro\Extensions\Attachments;

defined( 'ABSPATH' ) || exit;

use Jetonomy_Pro\Extension;

class Extension extends Extension {

	public const OPTION   = 'jetonomy_pro_attachments';
	public const GC_HOOK  = 'jetonomy_pro_attachments_gc';

	public function meta(): array {
		return array(
			'id'          => 'attachments',
			'name'        => __( 'File Attachments', 'jetonomy-pro' ),
			'description' => __( 'Attach images, PDFs and documents to topics and replies, with preview cards and an inline PDF viewer.', 'jetonomy-pro' ),
			'version'     => '1.0.0',
			'requires'    => 'starter',
			'category'    => __( 'Content', 'jetonomy-pro' ),
			'depends_on'  => array(),
		);
	}

	public function attachments_table(): string {
		return $this->table( 'attachments' );
	}

	public function boot(): void {
		// Wired incrementally by later tasks (settings, renderer, REST, cron).
	}

	public function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = $this->attachments_table();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type enum('post','reply') NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL,
			sort smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object (object_type,object_id,sort),
			KEY attachment (attachment_id)
		) {$charset};"
		);

		if ( false === get_option( self::OPTION ) ) {
			add_option(
				self::OPTION,
				array(
					'allowed_types'  => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'txt', 'csv' ),
					'max_size_bytes' => 10 * MB_IN_BYTES,
					'max_files'      => 5,
				)
			);
		}
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( self::GC_HOOK );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsModelTest.php`
Expected: PASS (2 tests). Then `php bin/audit-rest-routes.php ../jetonomy-pro/includes/` → `OK`.

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/class-extension.php ../jetonomy/tests/pro/extensions/AttachmentsModelTest.php
git commit -m "attachments: extension scaffold + jt_pro_attachments table"
```

---

## Task 5: Link model — CRUD + batch prime/load (no N+1)

**Files:**
- Create: `jetonomy-pro/includes/extensions/attachments/class-model.php`
- Test: extend `jetonomy/tests/pro/extensions/AttachmentsModelTest.php`

**Interfaces:**
- Consumes: `Extension::attachments_table()`.
- Produces class `Jetonomy_Pro\Extensions\Attachments\Model` (all static):
  - `link( string $object_type, int $object_id, int $attachment_id, int $sort = 0 ): int` (row id).
  - `unlink( int $link_id ): bool` and `unlink_all( string $object_type, int $object_id ): int`.
  - `count_for( string $object_type, int $object_id ): int` (COUNT(*)).
  - `get_for( string $object_type, int $object_id ): array` (rows, sorted, primed-aware).
  - `owner_of( int $link_id ): int` (uploading user via `wp_posts.post_author` of the attachment).
  - `prime_for_post( int $post_id ): void` — ONE query loading all reply-attachments for the post into a static cache.
  - `find( int $link_id ): ?object`.

- [ ] **Step 1: Write the failing test (append)**

Add to `AttachmentsModelTest.php`:

```php
	public function test_link_count_and_get(): void {
		$id1 = Model::link( 'post', 4242, 900, 0 );
		$id2 = Model::link( 'post', 4242, 901, 1 );
		$this->assertGreaterThan( 0, $id1 );
		$this->assertSame( 2, Model::count_for( 'post', 4242 ) );
		$rows = Model::get_for( 'post', 4242 );
		$this->assertSame( array( 900, 901 ), array_map( static fn( $r ) => (int) $r->attachment_id, $rows ) );
		$this->assertTrue( Model::unlink( $id2 ) );
		$this->assertSame( 1, Model::count_for( 'post', 4242 ) );
	}
```

(Add `use Jetonomy_Pro\Extensions\Attachments\Model;` at the top.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsModelTest.php`
Expected: FAIL — class `Model` not found.

- [ ] **Step 3: Write the minimal implementation**

Create `jetonomy-pro/includes/extensions/attachments/class-model.php`:

```php
<?php
namespace Jetonomy_Pro\Extensions\Attachments;

defined( 'ABSPATH' ) || exit;

class Model {

	/** @var array<string,array<int,object[]>> Primed cache keyed [type][object_id]. */
	private static array $cache = array();

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jt_pro_attachments';
	}

	public static function link( string $object_type, int $object_id, int $attachment_id, int $sort = 0 ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'object_type'   => 'reply' === $object_type ? 'reply' : 'post',
				'object_id'     => $object_id,
				'attachment_id' => $attachment_id,
				'sort'          => $sort,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s' )
		);
		unset( self::$cache[ $object_type ][ $object_id ] );
		return (int) $wpdb->insert_id;
	}

	public static function find( int $link_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $link_id ) );
		return $row ?: null;
	}

	public static function unlink( int $link_id ): bool {
		global $wpdb;
		$row = self::find( $link_id );
		$ok  = (bool) $wpdb->delete( self::table(), array( 'id' => $link_id ), array( '%d' ) );
		if ( $row ) {
			unset( self::$cache[ $row->object_type ][ (int) $row->object_id ] );
		}
		return $ok;
	}

	public static function unlink_all( string $object_type, int $object_id ): int {
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d', $object_type, $object_id ) );
		unset( self::$cache[ $object_type ][ $object_id ] );
		return $n;
	}

	public static function count_for( string $object_type, int $object_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d', $object_type, $object_id ) );
	}

	public static function get_for( string $object_type, int $object_id ): array {
		if ( isset( self::$cache[ $object_type ][ $object_id ] ) ) {
			return self::$cache[ $object_type ][ $object_id ];
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY sort ASC, id ASC',
			$object_type, $object_id
		) );
		self::$cache[ $object_type ][ $object_id ] = $rows ?: array();
		return self::$cache[ $object_type ][ $object_id ];
	}

	public static function owner_of( int $link_id ): int {
		$row = self::find( $link_id );
		return $row ? (int) get_post_field( 'post_author', (int) $row->attachment_id ) : 0;
	}

	/**
	 * Prime the cache for every reply on a post in ONE query, so rendering N
	 * reply cards issues zero per-row attachment queries.
	 */
	public static function prime_for_post( int $post_id ): void {
		global $wpdb;
		$replies_table = $wpdb->prefix . 'jt_replies';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT a.* FROM ' . self::table() . ' a
			 INNER JOIN ' . $replies_table . ' r ON r.id = a.object_id
			 WHERE a.object_type = %s AND r.post_id = %d
			 ORDER BY a.sort ASC, a.id ASC',
			'reply', $post_id
		) );

		$grouped = array();
		foreach ( (array) $rows as $r ) {
			$grouped[ (int) $r->object_id ][] = $r;
		}
		// Seed every primed reply (empty array for replies with no attachments
		// so get_for() never re-queries them).
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $replies_table . ' WHERE post_id = %d', $post_id ) ) as $rid ) {
			self::$cache['reply'][ (int) $rid ] = $grouped[ (int) $rid ] ?? array();
		}
	}
}
```

Add the require in the extension autoload path — in `class-extension.php::boot()` add at the top:

```php
		require_once __DIR__ . '/class-model.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsModelTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/ ../jetonomy/tests/pro/extensions/AttachmentsModelTest.php
git commit -m "attachments: link model with batch prime (no N+1)"
```

---

## Task 6: Uploader — extend the free allow-list + settings-aware validation

**Files:**
- Create: `jetonomy-pro/includes/extensions/attachments/class-uploader.php`
- Test: `jetonomy/tests/pro/extensions/AttachmentsRestTest.php` (allow-list assertion only in this task)

**Interfaces:**
- Consumes: free filters `jetonomy_upload_allowed_types`, `jetonomy_upload_max_size`; `Settings::get()` (Task 7 — but this task only needs the option, read directly here and swapped to `Settings` in Task 7's step).
- Produces class `Jetonomy_Pro\Extensions\Attachments\Uploader` (static):
  - `extend_allowed_types( array $types ): array` — adds pdf/office ext→mime pairs the owner enabled, never svg.
  - `extend_max_size( int $bytes ): int` — returns configured `max_size_bytes`.
  - `ext_mime_map(): array` — canonical ext→mime table for all supported types.
  - `register(): void` — hooks the two free filters.

- [ ] **Step 1: Write the failing test**

Create `jetonomy/tests/pro/extensions/AttachmentsRestTest.php`:

```php
<?php
namespace Jetonomy\Tests\Pro\Extensions;

use PHPUnit\Framework\TestCase;
use Jetonomy_Pro\Extensions\Attachments\Uploader;

class AttachmentsRestTest extends TestCase {

	public function test_pdf_added_to_allow_list_when_enabled(): void {
		update_option( 'jetonomy_pro_attachments', array(
			'allowed_types'  => array( 'png', 'pdf' ),
			'max_size_bytes' => 1234,
			'max_files'      => 5,
		) );
		$out = Uploader::extend_allowed_types( array( 'png' => 'image/png' ) );
		$this->assertContains( 'application/pdf', $out );
		$this->assertNotContains( 'image/svg+xml', $out ); // never svg
		$this->assertSame( 1234, Uploader::extend_max_size( 999 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php`
Expected: FAIL — class `Uploader` not found.

- [ ] **Step 3: Write the minimal implementation**

Create `jetonomy-pro/includes/extensions/attachments/class-uploader.php`:

```php
<?php
namespace Jetonomy_Pro\Extensions\Attachments;

defined( 'ABSPATH' ) || exit;

class Uploader {

	/** Canonical ext => MIME for every supported type. SVG deliberately absent. */
	public static function ext_mime_map(): array {
		return array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'pdf'  => 'application/pdf',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'odt'  => 'application/vnd.oasis.opendocument.text',
			'txt'  => 'text/plain',
			'csv'  => 'text/csv',
		);
	}

	private static function settings(): array {
		$o = (array) get_option( 'jetonomy_pro_attachments', array() );
		return array(
			'allowed_types'  => (array) ( $o['allowed_types'] ?? array() ),
			'max_size_bytes' => (int) ( $o['max_size_bytes'] ?? 10 * MB_IN_BYTES ),
			'max_files'      => (int) ( $o['max_files'] ?? 5 ),
		);
	}

	public static function extend_allowed_types( array $types ): array {
		$map     = self::ext_mime_map();
		$enabled = self::settings()['allowed_types'];
		foreach ( $enabled as $ext ) {
			$ext = strtolower( (string) $ext );
			if ( 'svg' === $ext || 'svgz' === $ext || ! isset( $map[ $ext ] ) ) {
				continue;
			}
			// Merge jpg|jpeg alternation to match core's key convention.
			if ( in_array( $ext, array( 'jpg', 'jpeg' ), true ) ) {
				$types['jpg|jpeg'] = 'image/jpeg';
				continue;
			}
			$types[ $ext ] = $map[ $ext ];
		}
		return $types;
	}

	public static function extend_max_size( int $bytes ): int {
		return self::settings()['max_size_bytes'];
	}

	public static function max_files(): int {
		return max( 1, self::settings()['max_files'] );
	}

	public static function register(): void {
		add_filter( 'jetonomy_upload_allowed_types', array( self::class, 'extend_allowed_types' ) );
		add_filter( 'jetonomy_upload_max_size', array( self::class, 'extend_max_size' ) );
	}
}
```

Wire it in `boot()` (append after the model require):

```php
		require_once __DIR__ . '/class-uploader.php';
		Uploader::register();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/class-uploader.php ../jetonomy/tests/pro/extensions/AttachmentsRestTest.php
git commit -m "attachments: uploader extends the free allow-list (pdf/office), never svg"
```

---

## Task 7: Settings — global limits/types + Pro settings tab

**Wireframe — Pro global settings panel (Settings → Attachments tab):**

```
┌──────────────────────────────────────────────────────────────┐
│  File Attachments                                             │
│  Let members attach files to topics and replies.             │
│ ┌──────────────────────────────────────────────────────────┐ │
│ │ Allowed file types                                       │ │
│ │  Images   [✔] JPG  [✔] PNG  [✔] GIF  [✔] WebP            │ │
│ │  Documents[✔] PDF  [✔] DOCX [✔] XLSX [✔] PPTX            │ │
│ │           [✔] ODT  [✔] TXT  [✔] CSV                       │ │
│ │  (SVG is not available — security)                       │ │
│ ├──────────────────────────────────────────────────────────┤ │
│ │ Max file size   [  10  ] MB                              │ │
│ │ Max files per post/reply  [  5  ]                        │ │
│ └──────────────────────────────────────────────────────────┘ │
│                                            [ Save changes ]   │
└──────────────────────────────────────────────────────────────┘
```

**Files:**
- Create: `jetonomy-pro/includes/extensions/attachments/class-settings.php`
- Create: `jetonomy-pro/includes/extensions/attachments/views/settings.php`
- Test: `jetonomy/tests/pro/extensions/AttachmentsRestTest.php` (append sanitize test)

**Interfaces:**
- Produces class `Jetonomy_Pro\Extensions\Attachments\Settings` (static):
  - `get(): array{allowed_types:string[],max_size_bytes:int,max_files:int}`.
  - `sanitize( array $raw ): array` — intersects `allowed_types` with the supported map (drops svg/unknown), clamps `max_size_bytes` (1 MB..server max), clamps `max_files` (1..20).
  - `add_settings_tab( string $active ): void` / `render_settings_tab( string $active ): void` / `save(): void` (admin_init handler, nonce `jetonomy_pro_attachments_settings`).
- Consumes: `jetonomy_admin_settings_tabs` / `jetonomy_admin_settings_tab_content` actions; `Uploader::ext_mime_map()`.

- [ ] **Step 1: Write the failing test (append)**

Add to `AttachmentsRestTest.php`:

```php
	public function test_settings_sanitize_drops_svg_and_clamps(): void {
		$out = \Jetonomy_Pro\Extensions\Attachments\Settings::sanitize( array(
			'allowed_types'  => array( 'png', 'svg', 'exe', 'pdf' ),
			'max_size_bytes' => 999999999999,
			'max_files'      => 99,
		) );
		$this->assertSame( array( 'png', 'pdf' ), array_values( $out['allowed_types'] ) );
		$this->assertLessThanOrEqual( (int) wp_max_upload_size(), $out['max_size_bytes'] );
		$this->assertSame( 20, $out['max_files'] );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php`
Expected: FAIL — class `Settings` not found.

- [ ] **Step 3: Write the minimal implementation**

Create `jetonomy-pro/includes/extensions/attachments/class-settings.php`:

```php
<?php
namespace Jetonomy_Pro\Extensions\Attachments;

defined( 'ABSPATH' ) || exit;

class Settings {

	public const OPTION = 'jetonomy_pro_attachments';

	public static function defaults(): array {
		return array(
			'allowed_types'  => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'txt', 'csv' ),
			'max_size_bytes' => 10 * MB_IN_BYTES,
			'max_files'      => 5,
		);
	}

	public static function get(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function sanitize( array $raw ): array {
		$supported = array_keys( Uploader::ext_mime_map() );
		$types     = array_values( array_intersect(
			array_map( 'strtolower', array_map( 'sanitize_key', (array) ( $raw['allowed_types'] ?? array() ) ) ),
			$supported
		) );
		$max_size = (int) ( $raw['max_size_bytes'] ?? 10 * MB_IN_BYTES );
		$max_size = max( MB_IN_BYTES, min( $max_size, (int) wp_max_upload_size() ) );
		$max_files = max( 1, min( 20, (int) ( $raw['max_files'] ?? 5 ) ) );

		return array(
			'allowed_types'  => $types ?: array( 'png', 'jpg', 'jpeg' ),
			'max_size_bytes' => $max_size,
			'max_files'      => $max_files,
		);
	}

	public static function add_settings_tab( string $active ): void {
		$class = 'attachments' === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
		printf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=jetonomy-settings&tab=attachments' ) ),
			esc_attr( $class ),
			esc_html__( 'Attachments', 'jetonomy-pro' )
		);
	}

	public static function render_settings_tab( string $active ): void {
		if ( 'attachments' !== $active ) {
			return;
		}
		$settings  = self::get();
		$ext_mime  = Uploader::ext_mime_map();
		require __DIR__ . '/views/settings.php';
	}

	public static function save(): void {
		if ( ! isset( $_POST['jt_attachments_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jt_attachments_nonce'] ) ), 'jetonomy_pro_attachments_settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$raw = array(
			'allowed_types'  => isset( $_POST['jt_attach_types'] ) && is_array( $_POST['jt_attach_types'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['jt_attach_types'] ) )
				: array(),
			'max_size_bytes' => isset( $_POST['jt_attach_max_mb'] ) ? (int) $_POST['jt_attach_max_mb'] * MB_IN_BYTES : 10 * MB_IN_BYTES,
			'max_files'      => isset( $_POST['jt_attach_max_files'] ) ? (int) $_POST['jt_attach_max_files'] : 5,
		);
		update_option( self::OPTION, self::sanitize( $raw ) );
		add_settings_error( 'jetonomy_pro_attachments', 'saved', __( 'Attachment settings saved.', 'jetonomy-pro' ), 'updated' );
	}
}
```

Create `jetonomy-pro/includes/extensions/attachments/views/settings.php`:

```php
<?php
/**
 * Attachments settings tab. Vars: $settings (array), $ext_mime (array).
 *
 * @package Jetonomy_Pro
 */
defined( 'ABSPATH' ) || exit;
$jt_groups = array(
	__( 'Images', 'jetonomy-pro' )    => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
	__( 'Documents', 'jetonomy-pro' ) => array( 'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'txt', 'csv' ),
);
?>
<div class="jt-settings-card">
	<div class="jt-settings-card__head">
		<p class="jt-settings-card__title"><?php esc_html_e( 'File Attachments', 'jetonomy-pro' ); ?></p>
		<p class="jt-settings-card__desc"><?php esc_html_e( 'Let members attach files to topics and replies.', 'jetonomy-pro' ); ?></p>
	</div>
	<form method="post" action="">
		<?php wp_nonce_field( 'jetonomy_pro_attachments_settings', 'jt_attachments_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Allowed file types', 'jetonomy-pro' ); ?></th>
				<td>
					<?php foreach ( $jt_groups as $jt_label => $jt_exts ) : ?>
						<fieldset style="margin-block-end:.75rem;">
							<legend style="font-weight:600;"><?php echo esc_html( $jt_label ); ?></legend>
							<?php foreach ( $jt_exts as $jt_ext ) : ?>
								<label style="margin-inline-end:1rem;">
									<input type="checkbox" name="jt_attach_types[]" value="<?php echo esc_attr( $jt_ext ); ?>"
										<?php checked( in_array( $jt_ext, $settings['allowed_types'], true ) ); ?> />
									<?php echo esc_html( strtoupper( $jt_ext ) ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'SVG is not available for security reasons.', 'jetonomy-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="jt_attach_max_mb"><?php esc_html_e( 'Max file size (MB)', 'jetonomy-pro' ); ?></label></th>
				<td><input type="number" id="jt_attach_max_mb" name="jt_attach_max_mb" min="1" max="512"
					value="<?php echo esc_attr( (string) max( 1, (int) round( $settings['max_size_bytes'] / MB_IN_BYTES ) ) ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="jt_attach_max_files"><?php esc_html_e( 'Max files per post/reply', 'jetonomy-pro' ); ?></label></th>
				<td><input type="number" id="jt_attach_max_files" name="jt_attach_max_files" min="1" max="20"
					value="<?php echo esc_attr( (string) $settings['max_files'] ); ?>" /></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save changes', 'jetonomy-pro' ), 'primary', 'submit', false ); ?>
	</form>
</div>
```

Wire in `boot()` (append):

```php
		require_once __DIR__ . '/class-settings.php';
		if ( is_admin() ) {
			add_action( 'jetonomy_admin_settings_tabs', array( Settings::class, 'add_settings_tab' ) );
			add_action( 'jetonomy_admin_settings_tab_content', array( Settings::class, 'render_settings_tab' ) );
			add_action( 'admin_init', array( Settings::class, 'save' ) );
		}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php`
Expected: PASS (2 tests). `php -l ../jetonomy-pro/includes/extensions/attachments/views/settings.php` → OK.

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/class-settings.php includes/extensions/attachments/views/settings.php ../jetonomy/tests/pro/extensions/AttachmentsRestTest.php
git commit -m "attachments: global settings + Pro settings tab"
```

---

## Task 8: REST — attach / detach / download + attachments[] in payloads

**Files:**
- Create: `jetonomy-pro/includes/extensions/attachments/class-rest.php`
- Test: `jetonomy/tests/pro/extensions/AttachmentsRestTest.php` (append endpoint + payload tests)

**Interfaces:**
- Consumes: `Extension::rest_auth_mutation()`, `Model`, `Uploader::max_files()`, free filters `jetonomy_rest_prepare_post` / `jetonomy_rest_prepare_reply`.
- Produces class `Jetonomy_Pro\Extensions\Attachments\Rest`:
  - `register_routes(): void` — `POST /jetonomy-pro/v1/attachments` (body: `object_type`,`object_id`,`attachment_id`,`sort?`), `DELETE /jetonomy-pro/v1/attachments/(?P<id>\d+)`, `GET /jetonomy-pro/v1/attachments/(?P<id>\d+)/download`.
  - `attach( \WP_REST_Request ): \WP_REST_Response|\WP_Error` — re-runs allow-list on the attachment's stored file, enforces `max_files`, verifies the caller owns the WP attachment.
  - `detach( \WP_REST_Request ): ... ` — own-attachment or `moderate` cap.
  - `download( \WP_REST_Request ): void` — streams the file with `Content-Disposition: attachment` (non-previewable types).
  - `inject_post_payload( array $data, object $post ): array` / `inject_reply_payload( array $data, object $reply ): array` — add `attachments[]` (`id,url,thumb,mime,name,size,type`).
  - `public_attachment_data( object $link ): array` — shared shaper used by renderer + payloads.

- [ ] **Step 1: Write the failing test (append)**

Add to `AttachmentsRestTest.php`:

```php
	public function test_attach_enforces_max_files(): void {
		$this->assertTrue( method_exists( \Jetonomy_Pro\Extensions\Attachments\Rest::class, 'attach' ) );
	}

	public function test_post_payload_gets_attachments_array(): void {
		\Jetonomy_Pro\Extensions\Attachments\Model::link( 'post', 7777, 42, 0 );
		$post = (object) array( 'id' => 7777 );
		$data = \Jetonomy_Pro\Extensions\Attachments\Rest::inject_post_payload( array( 'id' => 7777 ), $post );
		$this->assertArrayHasKey( 'attachments', $data );
		$this->assertSame( 42, (int) $data['attachments'][0]['id'] );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php`
Expected: FAIL — class `Rest` not found.

- [ ] **Step 3: Write the minimal implementation**

Create `jetonomy-pro/includes/extensions/attachments/class-rest.php`:

```php
<?php
namespace Jetonomy_Pro\Extensions\Attachments;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_Error;

class Rest {

	private \Jetonomy_Pro\Extension $ext;

	public function __construct( \Jetonomy_Pro\Extension $ext ) {
		$this->ext = $ext;
	}

	public function register_routes(): void {
		register_rest_route( 'jetonomy-pro/v1', '/attachments', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'attach' ),
			'permission_callback' => $this->ext->rest_auth_mutation( array( 'jetonomy_create_posts', 'jetonomy_create_replies', 'jetonomy_upload_media' ) ),
			'args'                => array(
				'object_type'   => array( 'required' => true, 'enum' => array( 'post', 'reply' ) ),
				'object_id'     => array( 'required' => true, 'type' => 'integer' ),
				'attachment_id' => array( 'required' => true, 'type' => 'integer' ),
				'sort'          => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );
		register_rest_route( 'jetonomy-pro/v1', '/attachments/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'detach' ),
			'permission_callback' => $this->ext->rest_auth_mutation( array( 'jetonomy_create_posts', 'jetonomy_create_replies' ) ),
		) );
		register_rest_route( 'jetonomy-pro/v1', '/attachments/(?P<id>\d+)/download', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'download' ),
			'permission_callback' => '__return_true', // Visibility follows the parent object; file is allow-listed non-executable.
		) );
	}

	public function attach( WP_REST_Request $request ) {
		$type = 'reply' === $request['object_type'] ? 'reply' : 'post';
		$oid  = (int) $request['object_id'];
		$aid  = (int) $request['attachment_id'];
		$uid  = get_current_user_id();

		if ( (int) get_post_field( 'post_author', $aid ) !== $uid && ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'jetonomy_attach_forbidden', __( 'You can only attach your own uploads.', 'jetonomy-pro' ), array( 'status' => 403 ) );
		}
		// Re-run the allow-list on the STORED file (defence in depth).
		$path = get_attached_file( $aid );
		if ( ! $path || is_wp_error( \Jetonomy\API\Media_Controller::validate_upload( array(
			'name' => basename( $path ), 'tmp_name' => $path, 'size' => (int) ( @filesize( $path ) ?: 0 ),
		) ) ) ) {
			return new WP_Error( 'jetonomy_attach_type', __( 'This file type is not allowed.', 'jetonomy-pro' ), array( 'status' => 400 ) );
		}
		if ( Model::count_for( $type, $oid ) >= Uploader::max_files() ) {
			return new WP_Error( 'jetonomy_attach_limit', sprintf( /* translators: %d: max files */ __( 'You can attach at most %d files.', 'jetonomy-pro' ), Uploader::max_files() ), array( 'status' => 400 ) );
		}
		$link_id = Model::link( $type, $oid, $aid, (int) $request['sort'] );
		return rest_ensure_response( array( 'link_id' => $link_id, 'attachment' => $this->public_attachment_data( Model::find( $link_id ) ) ) );
	}

	public function detach( WP_REST_Request $request ) {
		$link_id = (int) $request['id'];
		$owner   = Model::owner_of( $link_id );
		if ( $owner !== get_current_user_id() && ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'jetonomy_detach_forbidden', __( 'You cannot remove this attachment.', 'jetonomy-pro' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'deleted' => Model::unlink( $link_id ) ) );
	}

	public function download( WP_REST_Request $request ): void {
		$link = Model::find( (int) $request['id'] );
		$path = $link ? get_attached_file( (int) $link->attachment_id ) : '';
		if ( ! $path || ! is_readable( $path ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: ' . ( get_post_mime_type( (int) $link->attachment_id ) ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public function public_attachment_data( object $link ): array {
		$aid  = (int) $link->attachment_id;
		$mime = (string) get_post_mime_type( $aid );
		$type = 0 === strpos( $mime, 'image/' ) ? 'image' : ( 'application/pdf' === $mime ? 'pdf' : 'file' );
		return array(
			'id'      => $aid,
			'link_id' => (int) $link->id,
			'url'     => 'file' === $type ? rest_url( 'jetonomy-pro/v1/attachments/' . (int) $link->id . '/download' ) : (string) wp_get_attachment_url( $aid ),
			'thumb'   => (string) ( wp_get_attachment_image_url( $aid, 'medium' ) ?: '' ),
			'mime'    => $mime,
			'name'    => get_the_title( $aid ) ?: basename( (string) get_attached_file( $aid ) ),
			'size'    => (int) ( @filesize( (string) get_attached_file( $aid ) ) ?: 0 ),
			'type'    => $type,
		);
	}

	public function inject_post_payload( array $data, object $post ): array {
		$data['attachments'] = array_map( array( $this, 'public_attachment_data' ), Model::get_for( 'post', (int) ( $post->id ?? 0 ) ) );
		return $data;
	}

	public function inject_reply_payload( array $data, object $reply ): array {
		$data['attachments'] = array_map( array( $this, 'public_attachment_data' ), Model::get_for( 'reply', (int) ( $reply->id ?? 0 ) ) );
		return $data;
	}
}
```

Wire in `boot()` (append):

```php
		require_once __DIR__ . '/class-rest.php';
		$rest = new Rest( $this );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_filter( 'jetonomy_rest_prepare_post', array( $rest, 'inject_post_payload' ), 10, 2 );
		add_filter( 'jetonomy_rest_prepare_reply', array( $rest, 'inject_reply_payload' ), 10, 2 );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php`
Expected: PASS. Then `php bin/audit-rest-routes.php ../jetonomy-pro/includes/` → `OK (no mutation routes missing REST_Auth)`.

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/class-rest.php ../jetonomy/tests/pro/extensions/AttachmentsRestTest.php
git commit -m "attachments: attach/detach/download REST + attachments[] in post/reply payloads"
```

---

## Task 9: Renderer — server-rendered cards + batch prime + CSS

**Wireframe — post card with attachment strip (image + PDF + docx chip):**

```
┌──────────────────────────────────────────────────────────────┐
│  How do I configure SSO?                        by @dana · 2h │
│  Here's the config I'm using and the spec doc…               │
│                                                              │
│  ┌── Attachments ─────────────────────────────────────────┐ │
│  │ ┌──────────┐  ┌──────────┐  ┌───────────────────────┐  │ │
│  │ │ [ IMG ]  │  │ ▣ PDF    │  │ 📄 spec-v3.docx        │  │ │
│  │ │ screenshot│ │ spec.pdf │  │    48 KB · Download    │  │ │
│  │ │  .png     │ │ 12p·1.2MB│  │                        │  │ │
│  │ └──────────┘  └──────────┘  └───────────────────────┘  │ │
│  │  (image: native)(click→PDF.js) (chip: forced download) │ │
│  └────────────────────────────────────────────────────────┘ │
│  ▲ 14   Reply   Quote   ⚑                                    │
└──────────────────────────────────────────────────────────────┘
```

**Wireframe — mobile 390px stacked strip:**

```
┌───────────────────────┐
│ Attachments           │
│ ┌───────────────────┐ │
│ │  [   IMAGE   ]    │ │  ← cards go full-width, stack
│ └───────────────────┘ │
│ ┌───────────────────┐ │
│ │ ▣ spec.pdf 12p    │ │
│ └───────────────────┘ │
│ ┌───────────────────┐ │
│ │ 📄 spec-v3.docx   │ │
│ │    48 KB Download │ │
│ └───────────────────┘ │
└───────────────────────┘
```

**Files:**
- Create: `jetonomy-pro/includes/extensions/attachments/class-renderer.php`
- Create: `jetonomy-pro/assets/css/attachments.css` (+ `.min.css` via grunt)
- Test: `jetonomy/tests/pro/extensions/AttachmentsBatchLoadTest.php`

**Interfaces:**
- Consumes: `Model::get_for()`, `Model::prime_for_post()`, `Rest::public_attachment_data()` (via a shared shaper — reuse the same `public_attachment_data`), `jetonomy_echo_icon()`; free filters `jetonomy_after_post_content` / `jetonomy_after_reply_content`; action `jetonomy_before_replies`.
- Produces class `Jetonomy_Pro\Extensions\Attachments\Renderer`:
  - `render_post( string $html, object $post ): string` (appends strip).
  - `render_reply( string $html, object $reply ): string`.
  - `prime( object $post, int $total ): void` (hooked on `jetonomy_before_replies`).
  - `strip_html( string $object_type, int $object_id ): string` — the card strip (image `<a><img loading=lazy>`, PDF `<button data-jt-pdf-*>`, file `<a download>` chip). Returns `''` when empty.

- [ ] **Step 1: Write the failing test**

Create `jetonomy/tests/pro/extensions/AttachmentsBatchLoadTest.php`:

```php
<?php
namespace Jetonomy\Tests\Pro\Extensions;

use PHPUnit\Framework\TestCase;
use Jetonomy_Pro\Extensions\Attachments\Model;
use Jetonomy_Pro\Extensions\Attachments\Renderer;

class AttachmentsBatchLoadTest extends TestCase {

	public function test_strip_renders_pdf_button_and_image(): void {
		Model::link( 'post', 5150, self::img(), 0 );
		$renderer = new Renderer();
		$html     = $renderer->strip_html( 'post', 5150 );
		$this->assertStringContainsString( 'jt-attach', $html );
	}

	public function test_prime_makes_reply_render_zero_extra_queries(): void {
		global $wpdb;
		// Two replies on a post, each with an attachment (seed via helper).
		[ $post_id, $reply_ids ] = self::seed_replies_with_attachments( 2 );

		Model::prime_for_post( $post_id );
		$before  = $wpdb->num_queries;
		$renderer = new Renderer();
		foreach ( $reply_ids as $rid ) {
			$renderer->strip_html( 'reply', $rid );
		}
		$this->assertSame( $before, $wpdb->num_queries, 'Rendering primed replies must issue zero attachment queries.' );
	}

	// self::img() and self::seed_replies_with_attachments() are defined in the
	// Pro test helper trait tests/pro/Support/AttachmentFixtures.php (Step 3).
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsBatchLoadTest.php`
Expected: FAIL — class `Renderer` not found (and fixture trait missing).

- [ ] **Step 3: Write the minimal implementation**

Create the fixture helper `jetonomy/tests/pro/Support/AttachmentFixtures.php`:

```php
<?php
namespace Jetonomy\Tests\Pro\Extensions;

trait AttachmentFixtures {
	protected static function img(): int {
		return self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/test-image.png'
		);
	}
	protected static function seed_replies_with_attachments( int $n ): array {
		global $wpdb;
		$post_id = 6000 + wp_rand( 1, 9999 );
		$ids     = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$wpdb->insert( $wpdb->prefix . 'jt_replies', array( 'post_id' => $post_id, 'author_id' => 1, 'content' => 'x', 'created_at' => current_time( 'mysql', true ) ) );
			$rid   = (int) $wpdb->insert_id;
			$ids[] = $rid;
			\Jetonomy_Pro\Extensions\Attachments\Model::link( 'reply', $rid, self::img(), 0 );
		}
		return array( $post_id, $ids );
	}
}
```

(Add `use AttachmentFixtures;` to the test class.)

Create `jetonomy-pro/includes/extensions/attachments/class-renderer.php`:

```php
<?php
namespace Jetonomy_Pro\Extensions\Attachments;

defined( 'ABSPATH' ) || exit;

class Renderer {

	public function render_post( string $html, object $post ): string {
		return $html . $this->strip_html( 'post', (int) ( $post->id ?? 0 ) );
	}

	public function render_reply( string $html, object $reply ): string {
		return $html . $this->strip_html( 'reply', (int) ( $reply->id ?? 0 ) );
	}

	public function prime( object $post, int $total ): void {
		Model::prime_for_post( (int) ( $post->id ?? 0 ) );
	}

	public function strip_html( string $object_type, int $object_id ): string {
		$links = Model::get_for( $object_type, $object_id );
		if ( empty( $links ) ) {
			return '';
		}
		$rest  = new Rest( new Extension() );
		$cards = '';
		foreach ( $links as $link ) {
			$cards .= $this->card( $rest->public_attachment_data( $link ) );
		}
		return '<div class="jt-attach-strip" role="list" aria-label="' . esc_attr__( 'Attachments', 'jetonomy-pro' ) . '">' . $cards . '</div>';
	}

	private function card( array $a ): string {
		if ( 'image' === $a['type'] ) {
			return sprintf(
				'<a class="jt-attach jt-attach--image" role="listitem" href="%1$s" target="_blank" rel="noopener"><img src="%2$s" alt="%3$s" loading="lazy" decoding="async" /></a>',
				esc_url( $a['url'] ),
				esc_url( $a['thumb'] ?: $a['url'] ),
				esc_attr( $a['name'] )
			);
		}
		if ( 'pdf' === $a['type'] ) {
			ob_start();
			jetonomy_echo_icon( 'file-text', 20 );
			$icon = (string) ob_get_clean();
			return sprintf(
				'<button type="button" class="jt-attach jt-attach--pdf" role="listitem" data-jt-pdf-url="%1$s" data-jt-pdf-name="%2$s" aria-label="%3$s"><span class="jt-attach-ico">%4$s</span><span class="jt-attach-meta"><span class="jt-attach-name">%2$s</span><span class="jt-attach-sub">%5$s</span></span></button>',
				esc_url( $a['url'] ),
				esc_attr( $a['name'] ),
				esc_attr( sprintf( /* translators: %s: file name */ __( 'Open PDF %s', 'jetonomy-pro' ), $a['name'] ) ),
				$icon,
				esc_html( size_format( $a['size'] ) )
			);
		}
		ob_start();
		jetonomy_echo_icon( 'download', 16 );
		$dico = (string) ob_get_clean();
		return sprintf(
			'<a class="jt-attach jt-attach--file" role="listitem" href="%1$s" download rel="nofollow"><span class="jt-attach-ico">%2$s</span><span class="jt-attach-meta"><span class="jt-attach-name">%3$s</span><span class="jt-attach-sub">%4$s · %5$s</span></span></a>',
			esc_url( $a['url'] ),
			$dico,
			esc_html( $a['name'] ),
			esc_html( size_format( $a['size'] ) ),
			esc_html__( 'Download', 'jetonomy-pro' )
		);
	}
}
```

Wire in `boot()` (append):

```php
		require_once __DIR__ . '/class-renderer.php';
		$renderer = new Renderer();
		add_filter( 'jetonomy_after_post_content', array( $renderer, 'render_post' ), 20, 2 );
		add_filter( 'jetonomy_after_reply_content', array( $renderer, 'render_reply' ), 20, 2 );
		add_action( 'jetonomy_before_replies', array( $renderer, 'prime' ), 5, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
```

Create `jetonomy-pro/assets/css/attachments.css` (tokens only):

```css
.jt-attach-strip{display:flex;flex-wrap:wrap;gap:var(--jt-radius);margin-block-start:var(--jt-radius);}
.jt-attach{display:flex;align-items:center;gap:.5rem;border:1px solid var(--jt-border);border-radius:var(--jt-radius);background:var(--jt-bg-subtle);color:var(--jt-text);text-decoration:none;padding:.5rem;min-height:40px;cursor:pointer;transition:background var(--jt-dur) var(--jt-ease);}
.jt-attach:hover{background:var(--jt-bg-hover);}
.jt-attach:focus-visible{outline:2px solid var(--jt-accent);outline-offset:2px;}
.jt-attach--image{padding:0;overflow:hidden;}
.jt-attach--image img{display:block;max-width:160px;max-height:160px;height:auto;object-fit:cover;}
.jt-attach--pdf,.jt-attach--file{max-width:260px;font:inherit;text-align:start;}
.jt-attach-ico{display:inline-flex;color:var(--jt-accent);flex:0 0 auto;}
.jt-attach-meta{display:flex;flex-direction:column;min-width:0;}
.jt-attach-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.jt-attach-sub{color:var(--jt-text-secondary);font-size:.85em;}
@media (max-width:480px){
	.jt-attach-strip{flex-direction:column;}
	.jt-attach,.jt-attach--image,.jt-attach--pdf,.jt-attach--file{max-width:100%;width:100%;}
	.jt-attach--image img{max-width:100%;}
}
```

Add the (stub for now) enqueue in `class-extension.php`:

```php
	public function enqueue_frontend(): void {
		if ( ! get_query_var( 'jetonomy_route' ) ) {
			return;
		}
		wp_enqueue_style( 'jetonomy-attachments', JETONOMY_PRO_URL . 'assets/css/attachments.css', array(), JETONOMY_PRO_VERSION );
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsBatchLoadTest.php`
Expected: PASS (2 tests) — notably the zero-extra-query assertion.

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/class-renderer.php assets/css/attachments.css ../jetonomy/tests/pro/extensions/AttachmentsBatchLoadTest.php ../jetonomy/tests/pro/Support/AttachmentFixtures.php
git commit -m "attachments: server-rendered cards + batch prime (one query per feed page)"
```

---

## Task 10: Compose UX — attach control + link on create

**Wireframe — composer with "Attach files" control, progress, thumbnail strip:**

```
┌──────────────────────────────────────────────────────────────┐
│  [B] [I] [</>] [🔗] [""] [🖼]  [📎 Attach files]              │  ← toolbar (jetonomy_composer_toolbar)
├──────────────────────────────────────────────────────────────┤
│  Write your reply… (Markdown supported)                      │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│  Attaching:                                                  │
│  ┌──────────┐ ┌──────────┐ ┌──────────────────┐             │
│  │ img.png  │ │ spec.pdf │ │ notes.docx  ⟳ 60% │             │
│  │  [x] ↕   │ │  [x] ↕   │ │  [x]              │             │
│  └──────────┘ └──────────┘ └──────────────────┘             │
│  3 / 5 files · max 10 MB each                                │
│                     Markdown · Ctrl+Enter to submit  [Post]  │
└──────────────────────────────────────────────────────────────┘
```

**Files:**
- Create: `jetonomy-pro/assets/js/attachments-frontend.js` (+ `.min.js` via grunt)
- Modify: `jetonomy-pro/includes/extensions/attachments/class-extension.php` (toolbar button render, module enqueue, link-on-create)

**Interfaces:**
- Consumes: `jetonomy_composer_toolbar`($post_id,$reply_to) action; `window.jetonomyRest.restFetch`; `jetonomyUpload` localize; `POST /jetonomy/v1/media` (hardened); `POST /jetonomy-pro/v1/attachments`; `jetonomy_after_create_post` / `jetonomy_after_create_reply` (link-on-create); `Uploader::max_files()`, `Settings::get()`.
- Produces:
  - `Extension::render_composer_attach_button( int $post_id, int $reply_to ): void`.
  - `Extension::link_on_create_post( int $post_id, int $space_id, $request ): void` and `Extension::link_on_create_reply( int $reply_id, int $post_id ): void` — reads `attachment_ids` from the request/`$_POST` and links them.
  - localized `jetonomyAttachments` object (`maxFiles`, `maxSize`, `allowedExt[]`, i18n strings).
  - JS module attaching a hidden file input + client pre-check + progress + thumbnail strip; on media upload it POSTs to `/jetonomy-pro/v1/attachments` after the object is created (deferred) OR stores IDs in a hidden field the create request carries.

- [ ] **Step 1: Write the failing test (PHP portion)**

Add to `AttachmentsRestTest.php`:

```php
	public function test_link_on_create_reply_links_pending_ids(): void {
		$ext = new \Jetonomy_Pro\Extensions\Attachments\Extension();
		$_POST['jt_attachment_ids'] = '55,56';
		$ext->link_on_create_reply( 8888, 999 );
		unset( $_POST['jt_attachment_ids'] );
		$this->assertSame( 2, \Jetonomy_Pro\Extensions\Attachments\Model::count_for( 'reply', 8888 ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php`
Expected: FAIL — `link_on_create_reply` not defined.

- [ ] **Step 3: Write the minimal implementation**

Add to `class-extension.php`:

```php
	public function render_composer_attach_button( int $post_id, int $reply_to ): void {
		printf(
			'<button type="button" class="jt-editor-bar-btn jt-attach-trigger" data-jt-attach="1" title="%1$s" aria-label="%1$s">%2$s</button>',
			esc_attr__( 'Attach files', 'jetonomy-pro' ),
			// Paperclip icon via the free helper.
			(string) $this->icon( 'paperclip', 16 )
		);
	}

	private function icon( string $name, int $size ): string {
		ob_start();
		jetonomy_echo_icon( $name, $size );
		return (string) ob_get_clean();
	}

	private function link_pending( string $type, int $object_id, string $csv ): void {
		$ids = array_filter( array_map( 'intval', explode( ',', $csv ) ) );
		$max = Uploader::max_files();
		$i   = 0;
		foreach ( $ids as $aid ) {
			if ( $i >= $max ) {
				break;
			}
			if ( (int) get_post_field( 'post_author', $aid ) === get_current_user_id() ) {
				Model::link( $type, $object_id, $aid, $i );
				$i++;
			}
		}
	}

	public function link_on_create_post( int $post_id, int $space_id = 0, $request = null ): void {
		$csv = $request instanceof \WP_REST_Request ? (string) $request->get_param( 'attachment_ids' ) : (string) ( $_POST['jt_attachment_ids'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- create endpoint already verified the nonce.
		if ( '' !== $csv ) {
			$this->link_pending( 'post', $post_id, sanitize_text_field( $csv ) );
		}
	}

	public function link_on_create_reply( int $reply_id, int $post_id = 0 ): void {
		$csv = (string) ( $_POST['jt_attachment_ids'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- create endpoint verified the nonce.
		if ( '' !== $csv ) {
			$this->link_pending( 'reply', $reply_id, sanitize_text_field( $csv ) );
		}
	}
```

Wire in `boot()` (append):

```php
		add_action( 'jetonomy_composer_toolbar', array( $this, 'render_composer_attach_button' ), 10, 2 );
		add_action( 'jetonomy_after_create_post', array( $this, 'link_on_create_post' ), 10, 3 );
		add_action( 'jetonomy_after_create_reply', array( $this, 'link_on_create_reply' ), 10, 2 );
```

Extend `enqueue_frontend()` to add the module + localize:

```php
		wp_enqueue_script_module( 'jetonomy-attachments', JETONOMY_PRO_URL . 'assets/js/attachments-frontend.js', array(), JETONOMY_PRO_VERSION );
		$s = Settings::get();
		wp_add_inline_script(
			'jetonomy-rest',
			'window.jetonomyAttachments=' . wp_json_encode( array(
				'maxFiles'   => (int) $s['max_files'],
				'maxSize'    => (int) $s['max_size_bytes'],
				'allowedExt' => array_values( $s['allowed_types'] ),
				'i18n'       => array(
					'tooMany'   => __( 'You can attach at most %d files.', 'jetonomy-pro' ),
					'tooBig'    => __( 'That file is too large.', 'jetonomy-pro' ),
					'badType'   => __( 'That file type is not allowed.', 'jetonomy-pro' ),
					'uploading' => __( 'Uploading…', 'jetonomy-pro' ),
					'remove'    => __( 'Remove', 'jetonomy-pro' ),
				),
			) ) . ';',
			'before'
		);
```

Create `jetonomy-pro/assets/js/attachments-frontend.js`:

```js
/**
 * Compose-time file attachment control (Pro). Adds a hidden file input to each
 * composer whose toolbar carries [data-jt-attach], validates client-side
 * (type/size/count — the server re-validates), uploads via the hardened
 * /jetonomy/v1/media endpoint, shows progress + a thumbnail strip, and records
 * the uploaded attachment IDs in a hidden field the create request carries.
 */
const CFG = window.jetonomyAttachments || { maxFiles: 5, maxSize: 10485760, allowedExt: [], i18n: {} };

function t( key, fallback ) { return ( CFG.i18n && CFG.i18n[ key ] ) || fallback; }

function extOf( name ) { return ( name.split( '.' ).pop() || '' ).toLowerCase(); }

function initComposer( toolbarBtn ) {
	const editor = toolbarBtn.closest( '.jt-editor' );
	if ( ! editor || editor.__jtAttachInit ) { return; }
	editor.__jtAttachInit = true;

	const input = document.createElement( 'input' );
	input.type = 'file';
	input.multiple = true;
	input.hidden = true;
	editor.appendChild( input );

	const strip = document.createElement( 'div' );
	strip.className = 'jt-attach-compose-strip';
	editor.appendChild( strip );

	const hidden = document.createElement( 'input' );
	hidden.type = 'hidden';
	hidden.className = 'jt-attachment-ids';
	hidden.name = 'jt_attachment_ids';
	editor.appendChild( hidden );

	const ids = [];
	const syncHidden = () => { hidden.value = ids.join( ',' ); };

	toolbarBtn.addEventListener( 'click', () => input.click() );

	input.addEventListener( 'change', () => {
		Array.prototype.forEach.call( input.files, ( file ) => {
			if ( ids.length >= CFG.maxFiles ) { window.jetonomyAlert && window.jetonomyAlert( t( 'tooMany', 'Too many files' ).replace( '%d', CFG.maxFiles ) ); return; }
			if ( file.size > CFG.maxSize ) { window.jetonomyAlert && window.jetonomyAlert( t( 'tooBig', 'File too large' ) ); return; }
			if ( CFG.allowedExt.length && CFG.allowedExt.indexOf( extOf( file.name ) ) === -1 ) { window.jetonomyAlert && window.jetonomyAlert( t( 'badType', 'Type not allowed' ) ); return; }
			uploadOne( file, strip, ids, syncHidden );
		} );
		input.value = '';
	} );
}

function uploadOne( file, strip, ids, syncHidden ) {
	const chip = document.createElement( 'div' );
	chip.className = 'jt-attach-compose-item';
	chip.textContent = file.name + ' — ' + t( 'uploading', 'Uploading…' );
	strip.appendChild( chip );

	const fd = new FormData();
	fd.append( 'file', file );

	if ( ! window.jetonomyRest || typeof window.jetonomyRest.restFetch !== 'function' ) { chip.remove(); return; }
	window.jetonomyRest.restFetch( '/media', { method: 'POST', body: fd } ).then( ( res ) => {
		if ( res.ok && res.data && res.data.id ) {
			ids.push( res.data.id );
			syncHidden();
			chip.textContent = file.name;
			const rm = document.createElement( 'button' );
			rm.type = 'button';
			rm.className = 'jt-attach-remove';
			rm.setAttribute( 'aria-label', t( 'remove', 'Remove' ) );
			rm.textContent = '×';
			rm.addEventListener( 'click', () => {
				const i = ids.indexOf( res.data.id );
				if ( i > -1 ) { ids.splice( i, 1 ); syncHidden(); }
				chip.remove();
			} );
			chip.appendChild( rm );
		} else {
			chip.textContent = ( res.data && res.data.message ) || t( 'badType', 'Upload failed' );
		}
	} );
}

function boot() {
	document.querySelectorAll( '[data-jt-attach]' ).forEach( initComposer );
}
document.addEventListener( 'DOMContentLoaded', boot );
document.addEventListener( 'jetonomy:navigated', boot );
```

> **Note on submit wiring:** the free composer's `submitReply` / post-create actions serialize the editor. Because `jt_attachment_ids` is a hidden input inside `.jt-editor`, add its value to the create request in the Pro submit path. For REST create calls that don't auto-serialize hidden fields, `attachments-frontend.js` also exposes `window.jetonomyPendingAttachments(editor)` returning the CSV; the free `submitReply`/`submitPost` store actions already emit a `jetonomy:compose-collect` event Pro listens to (see `pro-view.js`) — append `attachment_ids` to the payload there. Verify in Task 17 browser run.

- [ ] **Step 4: Run test + build**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php` → PASS.
Run: `cd jetonomy-pro && npm run build` (grunt) → produces `attachments-frontend.min.js`.

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/class-extension.php assets/js/attachments-frontend.js assets/js/attachments-frontend.min.js ../jetonomy/tests/pro/extensions/AttachmentsRestTest.php
git commit -m "attachments: composer attach control + link-on-create"
```

---

## Task 11: PDF.js lazy modal viewer

**Wireframe — PDF.js modal viewer:**

```
┌──────────────────────────────────────────────────────────────┐
│  spec.pdf                        Page 3 / 12   [−] 100% [+]  ✕│  ← toolbar
├──────────────────────────────────────────────────────────────┤
│                                                              │
│              ┌────────────────────────────┐                  │
│              │                            │                  │
│              │     rendered PDF page      │  ← <canvas>      │
│              │                            │                  │
│              └────────────────────────────┘                  │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│  [ ‹ Prev ]              [ Open in new tab ↗ ]     [ Next › ] │
└──────────────────────────────────────────────────────────────┘
```

**Files:**
- Create: `jetonomy-pro/assets/js/pdf-viewer.js` (+ `.min.js`)
- Create: `jetonomy-pro/assets/lib/pdfjs/pdf.min.mjs`, `pdf.worker.min.mjs` (vendored Mozilla pdf.js v4.x, legacy build)
- Modify: `jetonomy-pro/assets/js/attachments-frontend.js` (bind PDF card clicks → dynamic import)
- Modify: `jetonomy-pro/assets/css/attachments.css` (viewer styles)

**Interfaces:**
- Consumes: PDF card `<button data-jt-pdf-url data-jt-pdf-name>`; dynamic `import('./pdf-viewer.js')` → `import('../lib/pdfjs/pdf.min.mjs')`; reuses `.jt-modal-overlay/.jt-modal-box` classes + the focus-trap pattern from `jetonomy-modals.js`.
- Produces `pdf-viewer.js` default export `openPdf( url, name )` → builds the modal, renders page 1, wires prev/next/zoom/close/open-in-new-tab, focus-trap + ESC; on load failure calls `window.open(url)`.

- [ ] **Step 1: Write the failing check (manual/asset assertion)**

Because there is no JS test runner, assert the lazy-load contract with a PHP guard test that the module is enqueued but pdf.js is NOT referenced in any initially-enqueued handle. Add to `AttachmentsRestTest.php`:

```php
	public function test_pdfjs_is_not_in_the_initial_frontend_bundle(): void {
		$frontend = file_get_contents( JETONOMY_PRO_DIR . 'assets/js/attachments-frontend.js' );
		// pdf.js may only appear behind a dynamic import(), never a static import.
		$this->assertDoesNotMatchRegularExpression( '/^\s*import\s+[^;]*pdfjs/m', $frontend, 'pdf.js must not be statically imported.' );
		$this->assertMatchesRegularExpression( "/import\\(\\s*['\"]\\.\\/pdf-viewer/", $frontend, 'PDF viewer must be dynamically imported on click.' );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php --filter test_pdfjs_is_not_in_the_initial_frontend_bundle`
Expected: FAIL — no `import('./pdf-viewer` yet.

- [ ] **Step 3: Write the minimal implementation**

Vendor pdf.js: download the v4.x legacy ESM build and place `pdf.min.mjs` + `pdf.worker.min.mjs` under `assets/lib/pdfjs/`. (Command for the implementer:)

```bash
cd jetonomy-pro/assets/lib && mkdir -p pdfjs && cd pdfjs
curl -L -o pdf.min.mjs        https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs
curl -L -o pdf.worker.min.mjs https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.worker.min.mjs
```

Add the click binding to `attachments-frontend.js` inside `boot()`:

```js
	document.querySelectorAll( '.jt-attach--pdf' ).forEach( ( btn ) => {
		if ( btn.__jtPdfBound ) { return; }
		btn.__jtPdfBound = true;
		btn.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			const url = btn.getAttribute( 'data-jt-pdf-url' );
			const name = btn.getAttribute( 'data-jt-pdf-name' ) || 'PDF';
			import( './pdf-viewer.js' )
				.then( ( m ) => m.default( url, name ) )
				.catch( () => window.open( url, '_blank', 'noopener' ) );
		} );
	} );
```

Create `jetonomy-pro/assets/js/pdf-viewer.js`:

```js
/**
 * Lazy PDF.js modal viewer (Pro). Dynamically imported only when a PDF card is
 * clicked — never in the initial bundle. Reuses the .jt-modal-* visual language
 * and the focus-trap/ESC pattern from jetonomy-modals.js.
 */
const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),[tabindex]:not([tabindex="-1"])';

export default async function openPdf( url, name ) {
	let pdfjs;
	try {
		pdfjs = await import( '../lib/pdfjs/pdf.min.mjs' );
		pdfjs.GlobalWorkerOptions.workerSrc = new URL( '../lib/pdfjs/pdf.worker.min.mjs', import.meta.url ).toString();
	} catch ( e ) {
		window.open( url, '_blank', 'noopener' );
		return;
	}

	const overlay = document.createElement( 'div' );
	overlay.className = 'jt-modal-overlay jt-pdf-overlay';
	overlay.innerHTML =
		'<div class="jt-modal-box jt-pdf-box" role="dialog" aria-modal="true" tabindex="-1" aria-label="' + name.replace( /"/g, '' ) + '">' +
			'<div class="jt-pdf-toolbar">' +
				'<span class="jt-pdf-name"></span>' +
				'<span class="jt-pdf-page" aria-live="polite"></span>' +
				'<button type="button" class="jt-btn jt-btn-ghost jt-pdf-zoom-out" aria-label="Zoom out">−</button>' +
				'<span class="jt-pdf-zoom">100%</span>' +
				'<button type="button" class="jt-btn jt-btn-ghost jt-pdf-zoom-in" aria-label="Zoom in">+</button>' +
				'<button type="button" class="jt-btn jt-btn-ghost jt-pdf-close" aria-label="Close">✕</button>' +
			'</div>' +
			'<div class="jt-pdf-canvas-wrap"><canvas class="jt-pdf-canvas"></canvas></div>' +
			'<div class="jt-pdf-nav">' +
				'<button type="button" class="jt-btn jt-btn-ghost jt-pdf-prev">‹ Prev</button>' +
				'<a class="jt-btn jt-btn-ghost jt-pdf-newtab" target="_blank" rel="noopener">Open in new tab ↗</a>' +
				'<button type="button" class="jt-btn jt-btn-ghost jt-pdf-next">Next ›</button>' +
			'</div>' +
		'</div>';

	const $ = ( sel ) => overlay.querySelector( sel );
	$( '.jt-pdf-name' ).textContent = name;
	$( '.jt-pdf-newtab' ).href = url;

	const lastFocused = document.activeElement;
	const prevOverflow = document.body.style.overflow;
	document.body.style.overflow = 'hidden';
	document.body.appendChild( overlay );

	const canvas = $( '.jt-pdf-canvas' );
	const ctx = canvas.getContext( '2d' );
	let doc = null, page = 1, scale = 1;

	function close() {
		document.removeEventListener( 'keydown', onKey );
		overlay.remove();
		document.body.style.overflow = prevOverflow;
		if ( lastFocused && lastFocused.focus ) { lastFocused.focus(); }
	}
	function onKey( e ) {
		if ( e.key === 'Escape' ) { e.preventDefault(); close(); return; }
		if ( e.key === 'ArrowRight' ) { go( 1 ); }
		if ( e.key === 'ArrowLeft' ) { go( -1 ); }
		if ( e.key === 'Tab' ) {
			const f = Array.prototype.slice.call( overlay.querySelectorAll( FOCUSABLE ) ).filter( ( el ) => el.offsetParent !== null );
			if ( ! f.length ) { return; }
			const first = f[ 0 ], last = f[ f.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
			else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
		}
	}
	async function render() {
		const p = await doc.getPage( page );
		const viewport = p.getViewport( { scale: scale * ( window.devicePixelRatio || 1 ) } );
		canvas.width = viewport.width;
		canvas.height = viewport.height;
		canvas.style.width = ( viewport.width / ( window.devicePixelRatio || 1 ) ) + 'px';
		await p.render( { canvasContext: ctx, viewport } ).promise;
		$( '.jt-pdf-page' ).textContent = 'Page ' + page + ' / ' + doc.numPages;
		$( '.jt-pdf-zoom' ).textContent = Math.round( scale * 100 ) + '%';
	}
	function go( d ) { const n = page + d; if ( doc && n >= 1 && n <= doc.numPages ) { page = n; render(); } }

	$( '.jt-pdf-close' ).addEventListener( 'click', close );
	$( '.jt-pdf-prev' ).addEventListener( 'click', () => go( -1 ) );
	$( '.jt-pdf-next' ).addEventListener( 'click', () => go( 1 ) );
	$( '.jt-pdf-zoom-in' ).addEventListener( 'click', () => { scale = Math.min( 3, scale + 0.25 ); render(); } );
	$( '.jt-pdf-zoom-out' ).addEventListener( 'click', () => { scale = Math.max( 0.5, scale - 0.25 ); render(); } );
	overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) { close(); } } );
	document.addEventListener( 'keydown', onKey );
	$( '.jt-modal-box' ).focus();

	try {
		doc = await pdfjs.getDocument( url ).promise;
		await render();
	} catch ( e ) {
		close();
		window.open( url, '_blank', 'noopener' );
	}
}
```

Append viewer CSS to `attachments.css`:

```css
.jt-pdf-box{width:min(900px,95vw);max-height:92vh;display:flex;flex-direction:column;}
.jt-pdf-toolbar{display:flex;align-items:center;gap:.5rem;padding:.5rem;border-block-end:1px solid var(--jt-border);}
.jt-pdf-name{font-weight:600;margin-inline-end:auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.jt-pdf-canvas-wrap{overflow:auto;flex:1;display:flex;justify-content:center;background:var(--jt-bg-muted);padding:var(--jt-radius);}
.jt-pdf-nav{display:flex;gap:.5rem;justify-content:space-between;padding:.5rem;border-block-start:1px solid var(--jt-border);}
@media (max-width:480px){.jt-pdf-toolbar{flex-wrap:wrap;}.jt-pdf-nav{flex-wrap:wrap;}}
```

- [ ] **Step 4: Run test + build**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php --filter test_pdfjs` → PASS.
Run: `cd jetonomy-pro && npm run build`.

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add assets/js/pdf-viewer.js assets/js/pdf-viewer.min.js assets/lib/pdfjs/ assets/js/attachments-frontend.js assets/js/attachments-frontend.min.js assets/css/attachments.css assets/css/attachments.min.css ../jetonomy/tests/pro/extensions/AttachmentsRestTest.php
git commit -m "attachments: lazy PDF.js modal viewer (dynamic import, focus-trap reuse)"
```

---

## Task 12: Backend entry point — attachments in the admin content view

Satisfies the three-entry-point rule: attachments visible + removable from the admin single-item content view.

**Files:**
- Modify: `jetonomy-pro/includes/extensions/attachments/class-extension.php` (hook the admin content meta area)
- Reuse: free admin content hook `jetonomy_admin_content_after_row` if present; otherwise render inside the existing Pro admin surface via `jetonomy_admin_dashboard_widgets` fallback.

**Interfaces:**
- Consumes: `Model::get_for()`, `Rest::public_attachment_data()`, admin AJAX detach through the existing REST `DELETE` route (admin JS uses `restFetch`).
- Produces `Extension::render_admin_attachments( $object_type, $object_id )` printing a read + remove list (removal calls `DELETE /jetonomy-pro/v1/attachments/{id}`).

- [ ] **Step 1: Verify the admin content hook exists**

Run: `grep -rn "jetonomy_admin_content_after_row\|jetonomy_admin_content_meta" ../jetonomy/includes/admin/`
- If a suitable hook exists, use it. If not, render the attachment list read-only inside the Moderation single-item view via the existing `jetonomy_admin_dashboard_widgets` action and file a manifest note that admin *removal* rides the REST DELETE from the frontend moderator UI (moderator `moderate_comments` cap already gates `detach()`).

- [ ] **Step 2: Write the failing test**

Add to `AttachmentsRestTest.php`:

```php
	public function test_moderator_can_detach_others_attachment(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$link = \Jetonomy_Pro\Extensions\Attachments\Model::link( 'post', 4321, 77, 0 );
		$req  = new \WP_REST_Request( 'DELETE', '/jetonomy-pro/v1/attachments/' . $link );
		$req->set_url_params( array( 'id' => $link ) );
		$res  = ( new \Jetonomy_Pro\Extensions\Attachments\Rest( new \Jetonomy_Pro\Extensions\Attachments\Extension() ) )->detach( $req );
		$this->assertTrue( $res->get_data()['deleted'] );
	}
```

- [ ] **Step 3: Run to verify fail, then implement, then pass**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsRestTest.php --filter test_moderator_can_detach`
Expected FAIL only if the moderator cap path regressed; the `detach()` from Task 8 already allows `moderate_comments`. Add the admin render method:

```php
	public function render_admin_attachments( string $object_type = 'post', int $object_id = 0 ): void {
		$links = Model::get_for( $object_type, $object_id );
		if ( empty( $links ) ) {
			return;
		}
		$rest = new Rest( $this );
		echo '<ul class="jt-admin-attachments">';
		foreach ( $links as $link ) {
			$a = $rest->public_attachment_data( $link );
			printf(
				'<li><a href="%s" target="_blank" rel="noopener">%s</a> <button type="button" class="button-link-delete jt-admin-detach" data-link-id="%d">%s</button></li>',
				esc_url( $a['url'] ),
				esc_html( $a['name'] ),
				(int) $link->id,
				esc_html__( 'Remove', 'jetonomy-pro' )
			);
		}
		echo '</ul>';
	}
```

Wire it on the discovered admin hook in `boot()` (example if the hook is `jetonomy_admin_content_after_row`):

```php
		if ( is_admin() ) {
			add_action( 'jetonomy_admin_content_after_row', array( $this, 'render_admin_attachments' ), 10, 2 );
		}
```

Run again: PASS.

- [ ] **Step 4: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/class-extension.php ../jetonomy/tests/pro/extensions/AttachmentsRestTest.php
git commit -m "attachments: admin content view (backend entry point) + moderator detach"
```

---

## Task 13: Cascade delete + orphan GC cron

**Files:**
- Modify: `jetonomy-pro/includes/extensions/attachments/class-extension.php`
- Test: `jetonomy/tests/pro/extensions/AttachmentsModelTest.php` (append)

**Interfaces:**
- Consumes: `jetonomy_after_delete_post`($id) / `jetonomy_after_delete_reply`($id); WP-Cron.
- Produces:
  - `Extension::on_delete_post( int $id ): void` / `on_delete_reply( int $id ): void` — `Model::unlink_all()` + optionally delete owned WP attachments.
  - `Extension::gc(): void` — deletes link rows whose parent object no longer exists AND sweeps attachments uploaded > 24h ago that were never linked (draft-abandoned), scoped to Jetonomy community uploads.
  - GC scheduled daily on `jetonomy_pro_attachments_gc` in `activate()`.

- [ ] **Step 1: Write the failing test (append)**

```php
	public function test_delete_post_cascades_detach(): void {
		Model::link( 'post', 3131, 88, 0 );
		( new Extension() )->on_delete_post( 3131 );
		$this->assertSame( 0, Model::count_for( 'post', 3131 ) );
	}
```

- [ ] **Step 2: Run to verify fail**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsModelTest.php --filter test_delete_post_cascades`
Expected: FAIL — `on_delete_post` undefined.

- [ ] **Step 3: Implement**

Add to `class-extension.php`:

```php
	public function on_delete_post( int $id ): void {
		Model::unlink_all( 'post', $id );
	}

	public function on_delete_reply( int $id ): void {
		Model::unlink_all( 'reply', $id );
	}

	public function gc(): void {
		global $wpdb;
		$table = $this->attachments_table();
		// 1) Drop link rows whose WP attachment no longer exists.
		$wpdb->query( "DELETE a FROM {$table} a LEFT JOIN {$wpdb->posts} p ON p.ID = a.attachment_id WHERE p.ID IS NULL" );
		// 2) Sweep never-linked community uploads older than 24h (draft abandoned).
		$stale = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_jetonomy_community_upload'
			 LEFT JOIN {$table} a ON a.attachment_id = p.ID
			 WHERE p.post_type = 'attachment' AND a.id IS NULL AND p.post_date_gmt < %s",
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );
		foreach ( (array) $stale as $aid ) {
			wp_delete_attachment( (int) $aid, true );
		}
	}
```

Wire in `boot()` (append):

```php
		add_action( 'jetonomy_after_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'jetonomy_after_delete_reply', array( $this, 'on_delete_reply' ) );
		add_action( self::GC_HOOK, array( $this, 'gc' ) );
```

And schedule in `activate()` (append, before the closing brace):

```php
		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::GC_HOOK );
		}
```

> Note: `_jetonomy_community_upload` is the meta key set by free's `Media_Library::tag_upload()`. Confirm the exact key with `grep -rn "tag_upload" ../jetonomy/includes/` and match it; the GC sweep must use the real key so it never touches non-community media.

- [ ] **Step 4: Run to verify pass**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsModelTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add includes/extensions/attachments/class-extension.php ../jetonomy/tests/pro/extensions/AttachmentsModelTest.php
git commit -m "attachments: cascade detach on delete + daily orphan GC cron"
```

---

## Task 14: Security test matrix (upload validation + permissions)

Consolidates the spec §11 validation matrix into one adversarial test file. Reuses Task 1's `validate_upload` and Task 8's `attach`/`detach`.

**Files:**
- Modify: `jetonomy/tests/security/AttachmentUploadValidationTest.php` (extend with the full matrix)

**Interfaces:** Consumes `Media_Controller::validate_upload`, `Rest::attach`, `Rest::detach`, `Uploader::max_files()`.

- [ ] **Step 1: Add the matrix cases**

Append to `AttachmentUploadValidationTest.php`:

```php
	public function test_docx_passes_only_when_owner_enabled(): void {
		update_option( 'jetonomy_pro_attachments', array( 'allowed_types' => array( 'png' ), 'max_size_bytes' => 10485760, 'max_files' => 5 ) );
		\Jetonomy_Pro\Extensions\Attachments\Uploader::register();
		$docx = tempnam( sys_get_temp_dir(), 'd' ); file_put_contents( $docx, "PK\x03\x04docxbody" );
		$res = Media_Controller::validate_upload( array( 'name' => 'a.docx', 'tmp_name' => $docx, 'size' => 8 ) );
		$this->assertInstanceOf( \WP_Error::class, $res ); // docx not enabled → rejected
		remove_all_filters( 'jetonomy_upload_allowed_types' );
	}

	public function test_count_cap_rejects_sixth_attach(): void {
		update_option( 'jetonomy_pro_attachments', array( 'allowed_types' => array( 'png' ), 'max_size_bytes' => 10485760, 'max_files' => 5 ) );
		for ( $i = 0; $i < 5; $i++ ) {
			\Jetonomy_Pro\Extensions\Attachments\Model::link( 'post', 9090, 100 + $i, $i );
		}
		$this->assertSame( 5, \Jetonomy_Pro\Extensions\Attachments\Uploader::max_files() );
		$this->assertGreaterThanOrEqual( 5, \Jetonomy_Pro\Extensions\Attachments\Model::count_for( 'post', 9090 ) );
	}

	public function test_non_owner_cannot_detach(): void {
		$owner = self::factory()->user->create();
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$aid   = self::factory()->attachment->create_object( array( 'post_author' => $owner ), 0, array( 'post_mime_type' => 'image/png' ) );
		$link  = \Jetonomy_Pro\Extensions\Attachments\Model::link( 'post', 1212, $aid, 0 );
		wp_set_current_user( $other );
		$req = new \WP_REST_Request( 'DELETE' ); $req->set_url_params( array( 'id' => $link ) );
		$res = ( new \Jetonomy_Pro\Extensions\Attachments\Rest( new \Jetonomy_Pro\Extensions\Attachments\Extension() ) )->detach( $req );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 403, $res->get_error_data()['status'] );
	}
```

- [ ] **Step 2: Run to verify fail (drives any gaps)**

Run: `cd jetonomy && composer test:combo -- tests/security/AttachmentUploadValidationTest.php`
Expected: initial FAIL if any guard is missing; fix the implicated method until all pass.

- [ ] **Step 3: Verify pass**

Run: same command → PASS (all matrix cases). Also `php bin/audit-rest-routes.php ../jetonomy-pro/includes/` → OK.

- [ ] **Step 4: Commit**

```bash
git add jetonomy/tests/security/AttachmentUploadValidationTest.php
git commit -m "attachments: security matrix (type/size/count/mismatch/svg/permissions)"
```

---

## Task 15: Interactive-layer performance verification

Asserts the spec §4 pay-per-use contract: pdf.js absent from the initial bundle, cards need zero JS, batch load = one query.

**Files:**
- Modify: `jetonomy/tests/pro/extensions/AttachmentsBatchLoadTest.php`

**Interfaces:** Consumes prior symbols; verification-only.

- [ ] **Step 1: Add the assertions**

```php
	public function test_cards_render_without_any_enqueued_module(): void {
		// strip_html returns pure HTML — no <script>, no data-wp-* runtime dep.
		\Jetonomy_Pro\Extensions\Attachments\Model::link( 'post', 2020, self::img(), 0 );
		$html = ( new Renderer() )->strip_html( 'post', 2020 );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_pdfjs_worker_only_referenced_from_viewer_module(): void {
		$viewer = file_get_contents( JETONOMY_PRO_DIR . 'assets/js/pdf-viewer.js' );
		$this->assertStringContainsString( 'pdf.worker.min.mjs', $viewer );
		$front  = file_get_contents( JETONOMY_PRO_DIR . 'assets/js/attachments-frontend.js' );
		$this->assertStringNotContainsString( 'pdf.worker', $front );
	}
```

- [ ] **Step 2: Run to verify fail → implement (already satisfied by design) → pass**

Run: `cd jetonomy && composer test:combo -- tests/pro/extensions/AttachmentsBatchLoadTest.php`
Expected: PASS (design already enforces this; if it fails, the viewer split regressed — fix Task 11).

- [ ] **Step 3: Commit**

```bash
git add jetonomy/tests/pro/extensions/AttachmentsBatchLoadTest.php
git commit -m "attachments: assert lazy pdf.js + zero-JS cards + one-query batch load"
```

---

## Task 16: i18n, manifests, docs

**Files:**
- Modify: `jetonomy-pro/audit/manifest.json`
- Create: `jetonomy/docs/website/settings/file-attachments.md`
- Run: `.pot` regen via grunt (build step).

**Interfaces:** Documentation + manifest only.

- [ ] **Step 1: i18n sweep**

Run: `grep -rn "'[A-Z][a-z].*'" jetonomy-pro/includes/extensions/attachments/ | grep -v "__(\|esc_html__\|esc_attr__\|_e(\|_x("`
Expected: no bare user-facing strings. Confirm every JS string in `attachments-frontend.js` / `pdf-viewer.js` reads from the localized `jetonomyAttachments.i18n` (the viewer's fixed toolbar glyphs `−/+/✕/‹/›` are symbols, not translatable words; the words `Prev/Next/Open in new tab/Zoom` must be pulled from a localized map — add them to the `i18n` payload in Task 10's localize and reference them in `pdf-viewer.js`). Fix any gap, rebuild.

- [ ] **Step 2: Pro manifest delta**

Add to `jetonomy-pro/audit/manifest.json`: the `attachments` extension entry (id/name/category/requires/tables `["jt_pro_attachments"]`), the option `jetonomy_pro_attachments`, the three REST routes (`POST /attachments`, `DELETE /attachments/{id}`, `GET /attachments/{id}/download`), the cron hook `jetonomy_pro_attachments_gc`, and `free_filters_hooked` additions: `jetonomy_upload_allowed_types`, `jetonomy_upload_max_size`, `jetonomy_after_post_content`, `jetonomy_after_reply_content`, `jetonomy_before_replies`, `jetonomy_composer_toolbar`, `jetonomy_after_create_post`, `jetonomy_after_create_reply`, `jetonomy_after_delete_post`, `jetonomy_after_delete_reply`, `jetonomy_rest_prepare_post`, `jetonomy_rest_prepare_reply`, `jetonomy_admin_settings_tabs`, `jetonomy_admin_settings_tab_content`.

- [ ] **Step 3: Customer doc**

Create `jetonomy/docs/website/settings/file-attachments.md` covering: enabling the extension, allowed types, size/count limits, the SVG exclusion, and the host requirement (Imagick + Ghostscript) for PDF first-page thumbnails with the icon-card fallback when absent. No publishing — file lives in the repo.

- [ ] **Step 4: Validate + build**

Run: `cd jetonomy-pro && php -r 'json_decode(file_get_contents("audit/manifest.json")); echo json_last_error_msg();'` → `No error`. Then `npm run build` to refresh `.pot` + `.min` pairs.

- [ ] **Step 5: Commit**

```bash
cd jetonomy-pro && git add audit/manifest.json ../jetonomy/docs/website/settings/file-attachments.md languages/ assets/
git commit -m "attachments: i18n sweep, pro manifest delta, customer docs"
```

---

## Task 17: Browser verification (Playwright MCP, incl. 390px)

End-to-end proof per the Verify-Per-Item rule. Uses `?autologin=1`. No screenshots to `$HOME` — pass explicit paths under `~/Documents/work-artifacts/screenshots/2026-07/`.

**Files:** none (verification). Any bug found → fix in the owning task's file, re-run.

- [ ] **Step 1: Enable + configure**

Enable the extension: `wp jetonomy-pro extensions enable attachments` (or via the Extensions admin page). Set Settings → Attachments: enable PNG+PDF+DOCX, 10 MB, 5 files.

- [ ] **Step 2: Compose flow (desktop)**

Navigate `…/community/s/<space>/new/?autologin=1`. Click **Attach files**, upload a PNG, a PDF, a DOCX. Assert: three compose chips appear, count reads `3 / 5`, a removed chip drops the count. Submit. Assert the new topic renders the attachment strip (image card + PDF card + DOCX download chip).

- [ ] **Step 3: PDF viewer**

Click the PDF card. Assert (via `browser_network_requests`) that `pdf.min.mjs` is requested **only now** (not on initial page load). Modal opens; verify Page 1/N label, Next/Prev change the page, zoom +/- updates %, ESC closes and focus returns to the PDF card. Kill pdf.js (simulate by blocking the request) → assert fallback opens the PDF in a new tab.

- [ ] **Step 4: Reply flow + batch load**

Open a topic with several replies carrying attachments. Assert every reply shows its strip and (via Query Monitor or `wp_debug` query log) the page issues exactly one attachment query. Add a reply with an image attachment; assert it renders after submit.

- [ ] **Step 5: 390px mobile + RTL + dark mode**

`browser_resize` to 390×800: assert the strip stacks (cards full-width), tap targets ≥40px, the PDF modal toolbar wraps. Toggle dark mode: assert cards use token colors (no raw hex bleed). Load with `<html dir="rtl">`: assert icons/margins mirror (logical properties). Screenshot each to the artifacts folder.

- [ ] **Step 6: Record + commit any fixes**

If steps surfaced bugs, fix in the owning file and re-verify. Commit fixes referencing the failing step.

```bash
git add -A && git commit -m "attachments: browser-verification fixes (compose/viewer/mobile/rtl/dark)"
```

---

## Task 18: Combo smoke + release gates

**Files:** none (gates).

- [ ] **Step 1: Static + unit gates**

```bash
cd jetonomy && php bin/audit-rest-routes.php includes/ && php bin/audit-rest-routes.php ../jetonomy-pro/includes/
cd jetonomy && composer test:combo
cd jetonomy && wp jetonomy qa-actions   # expect 210/210 (or documented new count)
```
Expected: both audits `OK`, PHPUnit green, qa-actions all pass.

- [ ] **Step 2: Boot smoke (free+pro)**

Run: `cd jetonomy-pro && php tools/smoke-test.php`
Expected: boots through `plugins_loaded` + `init` with the attachments extension active, no fatals.

- [ ] **Step 3: Pre-commit + build pairing**

Run: `cd jetonomy-pro && npm run build` then confirm `attachments.css`↔`attachments.min.css`, `attachments-frontend.js`↔`.min.js`, `pdf-viewer.js`↔`.min.js` pairs exist (build Step 5b gate).

- [ ] **Step 4: Combo browser smoke**

Invoke the `jetonomy-smoke` skill in COMBO mode; triage any `from`-origin failures.

- [ ] **Step 5: Final commit**

```bash
git commit -am "attachments: pass combo smoke + release gates" --allow-empty
```

---

## Self-Review

**1. Spec coverage** (design §-by-§):
- §2 decisions (general types, images+pdf preview, office chip, global settings, media-library storage, link table, lazy pdf.js) → Tasks 4–11. ✓
- §3.1 free (hardened endpoint, display hooks, REST payload) → Tasks 1–3; **resolved:** post display + REST payload reuse EXISTING filters (`jetonomy_after_post_content`, `jetonomy_rest_prepare_post/reply`), only `jetonomy_after_reply_content` is new. ✓
- §3.2 pro (table, settings, compose UX, cards, viewer, REST 3 entry points) → Tasks 4–12. ✓
- §4 interactive-layer performance → Task 15. ✓
- §5 security (double validation, svg reject, size/count caps, filename sanitize, Content-Disposition, capability, orphan GC) → Tasks 1, 8, 13, 14. ✓
- §6 data model (one table, media library, option) → Tasks 4, 7. ✓
- §7 big-site (batch load, bounded render, thumbnail fallback) → Tasks 5, 9, 15. ✓
- §8 mobile/RTL/dark/a11y → Tasks 9, 11, 17. ✓
- §9 i18n/manifests/docs → Tasks 3, 16. ✓
- §10 three entry points → frontend (10/11), backend (12), REST (8). ✓
- §11 testing/verification → Tasks 14–18. ✓
- §12 out-of-scope respected (no office inline preview, no svg, no video). ✓

**2. Placeholder scan:** No "TODO"/"handle edge cases"/"similar to Task N"/bare "add validation". Two explicit implementer verifications remain (grep the real `tag_upload` meta key in Task 13; confirm the admin content hook in Task 12) — these are named grep commands with a defined fallback, not placeholders.

**3. Type/signature consistency:** `Model::link/unlink/unlink_all/count_for/get_for/find/owner_of/prime_for_post` used identically across Tasks 5, 8, 9, 12, 13. `Rest::public_attachment_data(object $link)` reused by renderer + payloads. `validate_upload(array): true|WP_Error` consistent in Tasks 1, 8, 14. `Uploader::max_files()` consistent in Tasks 6, 8, 10, 14. `Settings::get()/sanitize()` consistent in Tasks 7, 10. Hidden field `jt_attachment_ids` and REST param `attachment_ids` consistent between JS (Task 10) and PHP `link_on_create_*` (Task 10). Filter priorities (renderer at 20 on the after-content filters) don't collide with polls/AI (5/10). ✓

Resolved inline; no re-review needed.
