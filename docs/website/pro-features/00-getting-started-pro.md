Activate your license, switch on the extensions you need, and understand which capabilities gate each Pro feature.

> **PRO** - This section covers [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- How to activate your Jetonomy Pro license
- How to enable and disable individual Pro extensions
- Which license tier unlocks which extensions
- Which WordPress capabilities gate each Pro feature

## Activate Your License

Jetonomy Pro is a peer plugin to free Jetonomy. Install and activate free Jetonomy first, then install Jetonomy Pro - Pro shows an admin notice and stays dormant if the free plugin is not active.

1. Go to **Jetonomy → License** in your WordPress admin (the `jetonomy-license` page).
2. Paste the license key from your purchase receipt.
3. Click **Activate**. Jetonomy validates the key against the store and shows your tier and expiry.

Your license drives automatic updates and tier checks. If a license is missing or expired, extensions still boot - but features that require a higher tier than your license carries are blocked at the gate.

> **Note:** Free and Pro always ship the same `x.y.z` version. Keep both updated together so the contracts between them stay in sync.

## Enable Extensions

Jetonomy Pro ships fifteen extensions. None of them do anything until you switch them on.

1. Go to **Jetonomy → Extensions** (the `jetonomy-extensions` page).
2. Each extension shows a card with its name, description, and a toggle.
3. Click **Enable** on the extensions you want. The toggle persists the choice to the `jetonomy_pro_extensions` option (an array of extension IDs).
4. Enabling an extension runs any one-time setup it needs (for example, creating its database tables) and registers its hooks, REST routes, and admin screens immediately.

Disable an extension at any time from the same page. Disabling stops the feature and unregisters its hooks; it does not delete the data the extension already stored.

## License Tiers

| Tier | Who it suits |
|------|--------------|
| **Starter** | A single community getting started with Pro features |
| **Growth** | A growing community that needs the full extension set across more sites |
| **Agency** | Builders running Pro across many client sites |
| **Lifetime** | One-time purchase with ongoing updates |

All fifteen extensions are available on the paid tiers. Higher tiers raise the activation limits (number of sites) and support level rather than locking individual features behind a paywall. The exact site limits per tier are listed on your account page and on the [pricing page](https://jetonomy.com/pro/).

## Capabilities

Pro registers five capabilities that gate its features. Each maps to the WordPress roles shown below by default; you can reassign them with any role-editor plugin if your community uses custom roles.

| Capability | Default roles | What it gates |
|------------|---------------|---------------|
| `jetonomy_manage_settings` | Administrator | Access to the Pro settings pages (License, Extensions, integration and extension settings tabs) |
| `jetonomy_manage_spaces` | Administrator | Per-space Pro admin controls - SEO Pro metadata, custom-field tabs, and creating site-wide announcements |
| `jetonomy_moderate` | Editor, Administrator | The actions Advanced Moderation rules can take on matched content |
| `jetonomy_vote` | Subscriber, Contributor, Author, Editor, Administrator | Casting Reactions and voting in Polls |
| `jetonomy_view_analytics` | Administrator | Viewing the community Analytics dashboard |

> **Note:** Most Pro REST write routes are additionally gated by `manage_options` for admin operations, and by trust level for member operations (for example, Private Messaging and Poll creation require Trust Level 1 or higher). See the per-feature pages and the [REST API reference](../developer-guide/01-rest-api.md) for the exact permission on each route.

## What's Next?

Start with the engagement extensions members notice first.

[Emoji Reactions →](01-reactions.md)
