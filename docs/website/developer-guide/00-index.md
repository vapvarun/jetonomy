The Developer Guide is the technical reference for extending, embedding, and integrating Jetonomy. Every page here is written for developers - if you are configuring the community from wp-admin, start with the Getting Started and Admin Settings sections instead.

Use this page as a map: each guide is grouped by what you are trying to do, so you can jump straight to the right reference no matter where you landed.

## Extend

Hook into Jetonomy's data and behaviour, or swap out a back-end service.

- [Hooks Reference](./02-hooks-reference.md) - every `jetonomy_*` action and filter, with the arguments each one passes.
- [Adapter System](./05-adapters.md) - the membership, search, real-time, and email adapter interfaces you implement to plug in your own service.
- [Template Overrides](./03-template-overrides.md) - copy any community template into your theme's `jetonomy/` directory to change its layout.

## Embed

Surface community content on any page, post, sidebar, or page-builder canvas.

- [Shortcodes, Widgets, and Blocks](./04-shortcodes-widgets-blocks.md) - the eight shortcodes, four classic widgets, and eight Gutenberg blocks, with attributes and block/shortcode parity notes.

## Integrate

Read and write community data from another application, agent, or platform.

- [REST API Reference](./01-rest-api.md) - the full `jetonomy/v1` endpoint listing with methods, payloads, responses, and permission contracts.
- [Abilities API](./11-abilities-api.md) - expose the community to AI agents and automation tools through the WordPress Abilities API.
- [FluentCommunity Integration](./06-fluent-community-integration.md) - developer reference for the FluentCommunity coexistence layer.
- [BuddyPress Integration](./07-buddypress-integration.md) - developer reference for the BuddyPress Groups coexistence layer, including how to disable leave-sync.

## Front-end toolkit

JavaScript and access-control building blocks for custom front-end code.

- [Modal Toolkit](./09-modal-toolkit.md) - the `jetonomyConfirm` / `jetonomyAlert` / `jetonomyPrompt` globals that replace native browser dialogs.
- [Visibility and Access Matrix](./08-visibility-and-access-matrix.md) - the `Jetonomy\Visibility` helper that enforces the public/private community toggle, plus the access-matrix regression runner.

## Operations

Drive and test the community from the command line.

- [WP-CLI Commands](./10-wp-cli.md) - the full `wp jetonomy` and `wp jetonomy-pro` command surface, plus the `qa-actions` smoke suite.
