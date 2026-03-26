Remove all Jetonomy branding and present your community as entirely your own product.

> **PRO** — This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

![Community frontend with custom logo and brand colors — no Jetonomy attribution](../images/pro-white-label-frontend.png)

## What You Will Learn

- How to enable White Label
- How to remove Jetonomy branding from the frontend and admin
- How to set a custom admin menu label and icon
- How to control branding for headless or REST API clients

## Why White Label Matters

You built your community. Your members know your brand — not the plugin powering it. White Label means your community looks like yours from every angle: the frontend pages, the admin sidebar, and the REST API responses. This is especially important for agencies delivering client projects and for SaaS products embedding community features under their own brand.

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

![White Label branding settings panel](../images/pro-white-label-settings.png)

> **Tip:** Use the Custom CSS injection field to apply brand-specific color overrides without editing any theme files. The CSS injects after Jetonomy's own stylesheet so your values always win.

## Admin Menu Customization

By default, the Jetonomy admin menu item is labeled "Jetonomy" with the Jetonomy logo icon.

In **Jetonomy → Settings → Branding → Admin Menu**:

- **Menu label** — Change to any string (e.g. "Community", "Forum", "My Community").
- **Menu icon** — Enter any [Dashicons](https://developer.wordpress.org/resource/dashicons/) class (e.g. `dashicons-groups`) or leave blank to use the default.

The label change applies to the top-level menu item and the browser window title on all Jetonomy admin pages.

![WordPress admin sidebar showing custom menu label and icon](../images/pro-white-label-admin-menu.png)

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

This is particularly useful for headless community builds where the REST API is consumed by a custom frontend — clients see your brand name, not Jetonomy's.

## Email Branding

White Label also affects transactional emails and digests. In **Settings → Branding → Email**:

- **From name** — Defaults to your site name. Change to any value.
- **Email footer** — Replaces the default Jetonomy email footer with your own text or HTML.
- **Logo in emails** — Upload a logo displayed at the top of notification emails.

## What's Next?

You have covered all 12 Pro features. Return to the full Pro feature overview to review what each extension covers.

[Pro Features Overview →](../pro-features-overview.md)
