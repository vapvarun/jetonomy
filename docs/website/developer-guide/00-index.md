The Developer Guide is the technical reference for extending, embedding, and integrating Jetonomy. Every page here is written for developers - if you are configuring the community from wp-admin, start with the Getting Started and Admin Settings sections instead.

Use this page as a map: each guide is grouped by what you are trying to do, so you can jump straight to the right reference no matter where you landed.

## Extend

Hook into Jetonomy's data and behaviour, or swap out a back-end service.

- [Hooks Reference](./02-hooks-reference.md) - every `jetonomy_*` action and filter, with the arguments each one passes.
- [Hooks Index (generated)](./02a-hooks-index.md) - the complete machine-generated hook table, regenerated from the manifest at every release.
- [Attachment Hooks](./24-attachment-hooks.md) - the 1.8.0 attachment filters (`jetonomy_attachment_card`, `jetonomy_attachments_class`, `jetonomy_rest_attachment_data`) and the `attachments` array now carried on post/reply REST payloads.
- [Build a Pro Extension](./25-build-a-pro-extension.md) - the extension SDK (Pro 1.8.1+): `jetonomy_pro_register_extensions`, the base-class contract, lifecycle guarantees, and compatibility rules.
- [Adapter System](./05-adapters.md) - the membership, search, real-time, and email adapter interfaces you implement to plug in your own service.
- [Template Overrides](./03-template-overrides.md) - copy any community template into your theme's `jetonomy/` directory to change its layout.
- [Admin Extensions](./20-admin-extensions.md) - add settings tabs, space-edit tabs, moderation tabs, dashboard widgets, and menu white-label overrides to the Jetonomy wp-admin UI.

## Build a companion plugin (recipes)

Step-by-step guides for the most common customization tasks.

- [Add a Profile Tab](./12-add-a-profile-tab.md) - register a new tab on the Jetonomy member profile using the `jetonomy_profile_tabs` filter.
- [Add a Space Tab](./13-add-a-space-tab.md) - register a new tab on the space frontend nav using the `jetonomy_space_tabs` filter.
- [Add a Nav Item](./14-add-a-nav-item.md) - add a link to the community header nav bar.
- [Customize Cards](./15-customize-cards.md) - inject content into post cards, reply cards, and the single-post view using template hooks.
- [Theming with Tokens](./16-theming-and-tokens.md) - use the `--jt-*` CSS custom properties to style or rebrand the community without overriding templates.
- [Extend the Frontend](./17-extend-the-frontend.md) - hook into the WP Interactivity API store, listen to the `jetonomy:navigated` event, and call `restFetch` from your own scripts.
- [Extend the REST API](./18-extend-the-rest-api.md) - add fields to existing responses with `jetonomy_rest_prepare_*` filters, and register new `jetonomy/v1` routes.
- [Customize Emails](./19-customize-emails.md) - override notification email subjects, bodies, and templates using the `jetonomy_email_*` filter set.

## Embed

Surface community content on any page, post, sidebar, or page-builder canvas.

- [Shortcodes, Widgets, and Blocks](./04-shortcodes-widgets-blocks.md) - the eight shortcodes, four classic widgets, and eight Gutenberg blocks, with attributes and block/shortcode parity notes.

## Get found

Make the community discoverable to search engines and feed readers.

- [SEO and Discoverability](./22-seo-and-discoverability.md) - the structured data, Open Graph and Twitter cards, canonical URLs, XML sitemap, and per-space RSS feeds Jetonomy emits, plus the `jetonomy_seo_meta` filter and the Pro SEO controls.

## Integrate

Read and write community data from another application, agent, or platform.

- [REST API Reference](./01-rest-api.md) - the full `jetonomy/v1` endpoint listing with methods, payloads, responses, and permission contracts.
- [OpenAPI Spec + Full REST Reference](./api/) - the complete machine-readable `openapi.json` (load into Swagger UI / Redoc) plus a human-readable companion covering every free and Pro route, generated from 1.8.0 source.
- [Attachment Model](./23-attachment-model.md) - `Jetonomy\Models\Attachment` (link, get_for, get_for_many, prime_for_post, hydrate, payload_for) and the `jt_attachments` table.
- [Import Framework](./25-import-framework.md) - the `Jetonomy\Import\Importer` base contract (media migration, error surfacing, the per-batch time budget) and how to add a source importer.
- [Abilities API](./11-abilities-api.md) - expose the community to AI agents and automation tools through the WordPress Abilities API.
- [FluentCommunity Integration](./06-fluent-community-integration.md) - developer reference for the FluentCommunity coexistence layer.
- [BuddyPress Integration](./07-buddypress-integration.md) - developer reference for the BuddyPress Groups coexistence layer, including how to disable leave-sync.
- [Coming from BuddyPress / BuddyBoss](./21-coming-from-buddypress-buddyboss.md) - concept map, API equivalents, and an honest account of what has no Jetonomy equivalent yet.

## Front-end toolkit

JavaScript and access-control building blocks for custom front-end code.

- [Modal Toolkit](./09-modal-toolkit.md) - the `jetonomyConfirm` / `jetonomyAlert` / `jetonomyPrompt` globals that replace native browser dialogs.
- [Visibility and Access Matrix](./08-visibility-and-access-matrix.md) - the `Jetonomy\Visibility` helper that enforces the public/private community toggle, plus the access-matrix regression runner.

## Operations

Drive and test the community from the command line.

- [WP-CLI Commands](./10-wp-cli.md) - the full `wp jetonomy` and `wp jetonomy-pro` command surface, plus the `qa-actions` smoke suite.
- [Caching](./26-caching.md) - the `Jetonomy\Cache` wrapper and the bust-after-write invalidation rule (`Space::bust_cache()` as the reference pattern).
- [Media Provenance](./27-media-provenance.md) - `Jetonomy\Media_Library` origin tagging (`tag_upload`, `is_ours`, `META_ORIGIN`) and why only files Jetonomy created are garbage-collected.
