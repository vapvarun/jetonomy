Remove all Jetonomy branding and present your community as entirely your own product.

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

> **As of 1.4.1, every White Label setting actually applies on every surface.** Header logo, footer text, email accent colour, email logos, the sidebar sign-in card, and admin footer text all rebrand on every send / render. Earlier versions defined the filters but nothing was hooking into them, so changes to the Branding settings had no visible effect on customer sites. Free 1.4.1 ships the matching `Jetonomy\header_logo()` and `Jetonomy\footer_text()` helpers Pro hooks into.

<!-- TODO screenshot needed: Community frontend with custom logo and brand colors - no Jetonomy attribution (was ../images/pro-white-label-frontend.png) -->
## What You Will Learn

- How to enable White Label
- How to remove Jetonomy branding from the frontend and admin
- How to set a custom admin menu label and icon
- How to control branding for headless or REST API clients

## Why White Label Matters

You built your community. Your members know your brand - not the plugin powering it. White Label means your community looks like yours from every angle: the frontend pages, the admin sidebar, and the REST API responses. This is especially important for agencies delivering client projects and for SaaS products embedding community features under their own brand.

## Enabling White Label

1. Go to **Jetonomy → Extensions** in your WordPress admin.
2. Find **White Label** and click **Enable**.
3. A **Branding** tab appears under **Jetonomy → Settings**.

## Removing Frontend Branding

Go to **Jetonomy → Settings → Branding**.

| Setting | Default | What it controls |
|---------|---------|-----------------|
| **"Powered by Jetonomy" footer** | Shown | Remove the attribution link from the community footer |
| **Jetonomy logo in community nav** | Shown | Replace with your own logo or hide entirely |
| **HTML `data-plugin` attribute** | `jetonomy` | Change or remove the attribute on the `.jt-app` wrapper |
| **Custom CSS injection** | Empty | Add CSS that loads on every community page |

Upload your own logo (SVG or PNG, max 400×100 px) to replace the Jetonomy logo in the community navigation bar. Leave the logo field blank to show no logo at all.

![White Label branding settings panel](../images/pro-white-label.png)
> **Tip:** Use the Custom CSS injection field to apply brand-specific color overrides without editing any theme files. The CSS injects after Jetonomy's own stylesheet so your values always win.

## Admin Menu Customization

By default, the Jetonomy admin menu item is labeled "Jetonomy" with the Jetonomy logo icon.

In **Jetonomy → Settings → Branding → Admin Menu**:

- **Menu label** - Change to any string (e.g. "Community", "Forum", "My Community").
- **Menu icon** - Enter any [Dashicons](https://developer.wordpress.org/resource/dashicons/) class (e.g. `dashicons-groups`) or leave blank to use the default.

The label change applies to the top-level menu item and the browser window title on all Jetonomy admin pages.

<!-- TODO screenshot needed: WordPress admin sidebar showing custom menu label and icon (was ../images/pro-white-label-admin-menu.png) -->
## REST API Branding

By default, Jetonomy's REST API responses include a `powered_by` key in the root namespace response:

```json
GET /wp-json/jetonomy/v1/

{
  "name": "Jetonomy API",
  "powered_by": "Jetonomy"
}
```

With White Label enabled, you can override both the `name` and `powered_by` values in **Settings → Branding → REST API Label**. Set them to your product name, or leave them blank to omit those fields entirely from the response.

This is particularly useful for headless community builds where the REST API is consumed by a custom frontend - clients see your brand name, not Jetonomy's.

## Email Branding

White Label also affects transactional emails and digests. In **Settings → Branding → Email**:

- **From name** - Defaults to your site name. Change to any value.
- **Email footer** - Replaces the default Jetonomy email footer with your own text or HTML.
- **Logo in emails** - Upload a logo displayed at the top of notification emails.

## Branding Settings Reference

White Label stores its configuration in the `jetonomy_pro_white_label` option. The settings worth calling out:

| Setting | What it does |
|---------|--------------|
| `header_logo_url` | The logo actually **displayed** in the community header. This is the image members see at the top of every community page. |
| `logo_url` | The **fallback / Open Graph** logo. Used for social share cards (OG image) and anywhere a logo is needed but no header logo is set. Keep this set even if you customize the header separately. |
| `accent_color` | A global accent colour override. Recolours Pro-rebranded surfaces - including email accents - in one place so your brand colour is consistent across the community and its emails. |
| `custom_css` | Freeform CSS injected on every community page. It loads **after** Jetonomy's own stylesheet, so your rules always win - use it for brand colour and spacing tweaks without touching theme files. |
| `sidebar_auth_card_html` | Custom HTML for the community sidebar's sign-in / call-to-action card. When set, it **replaces** Jetonomy's default auth card with your own markup; leave it empty to keep the default card. |
| `footer_text` | Replaces the "Powered by Jetonomy" footer text. |
| `email_logo_url` | Logo shown at the top of notification and digest emails. |

> **Tip:** `header_logo_url` and `logo_url` are deliberately separate. Set `header_logo_url` for the on-page logo and `logo_url` for the share-card / fallback image - they are often different sizes and aspect ratios.

## REST API

White Label exposes its settings under `jetonomy/v1`:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/settings/white-label` | Read the current white-label settings |
| `PATCH` | `/settings/white-label` | Save white-label settings |

Both routes require `manage_options`. See the [REST API reference](../developer-guide/01-rest-api.md) for full payloads.

## What's Next?

You have covered all 12 Pro features. Return to the beginning of the Pro section to explore Emoji Reactions and other extensions.

[Emoji Reactions →](01-reactions.md)
