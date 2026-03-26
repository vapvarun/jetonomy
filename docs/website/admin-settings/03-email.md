The Email settings tab controls which notification emails Jetonomy sends, what name and address they come from, and how to test your email configuration.

![Email settings with From name, From address, and notification type toggles](../images/admin-email.png)

## What You Will Learn

- Which notification types can be toggled on or off
- How to set your community's From name and From address
- How to send a test email to confirm delivery
- How to connect an SMTP plugin for reliable email delivery

Go to **Jetonomy → Settings → Email** to access these settings.

## How Jetonomy Sends Email

Jetonomy uses WordPress's built-in `wp_mail()` function for all outgoing notifications. This means it is immediately compatible with any SMTP plugin you already use — WP Mail SMTP, FluentSMTP, Postman, or any other. No extra configuration in Jetonomy is needed; just configure your SMTP plugin and Jetonomy benefits automatically.

> **Tip:** On production sites, always use an SMTP plugin with a transactional email service (Mailgun, Postmark, SendGrid, SES). The default PHP `mail()` delivery is unreliable and frequently lands in spam.

## From Name

**Setting:** `email_from_name`
**Default:** Your WordPress site name
**Location:** Email tab → Sender section

This is the display name that appears in the **From** field of every Jetonomy notification email. Use your community or product name — something members will recognize immediately in their inbox.

## From Address

**Setting:** `email_from_email`
**Default:** WordPress admin email
**Location:** Email tab → Sender section

This is the email address that appears in the **From** field. Use a dedicated address such as `community@yoursite.com` or `noreply@yoursite.com`.

> **Warning:** The From address must be a verified sender with your email service provider. Using an unverified address causes high bounce rates and spam scoring. If you use Gmail SMTP, the From address must match your Google account.

## Notification Toggles

**Setting:** `notification_defaults`
**Location:** Email tab → Notification Types section

Each notification type has an independent toggle for both **web** (in-app bell) and **email** delivery. The defaults shown here are the site-wide defaults. Individual members can override their own preferences from their notification settings page.

| Notification Type | Web Default | Email Default |
|---|---|---|
| Reply to your post | On | On |
| Reply to a reply you made | On | Off |
| @mention | On | On |
| Accepted answer (Q&A) | On | On |
| Vote on your post | On | Off |
| Badge earned | On | Off |
| New post in followed space | On | Off |

Turning off a type at the site level disables it globally — individual members cannot re-enable a type you have disabled here. Use this to prevent email overload from noisy notification types.

> **Note:** Vote and badge notifications default to web-only because they can occur frequently. Email for every vote would quickly train members to ignore your community emails entirely.

## Test Email

**Location:** Email tab → bottom of page → **Send Test Email** button

Click **Send Test Email** to send a test message to the WordPress admin email address. The test email confirms that `wp_mail()` is working and that your From name and address are applying correctly.

If the test email does not arrive within a few minutes, check:

1. Your SMTP plugin's log for send errors
2. Your spam folder
3. That the From address is verified with your email provider

## Email and Jetonomy Pro

Jetonomy Pro adds two additional email capabilities:

- **Email Digest** — daily and weekly summary emails that bundle multiple notifications into one. Members set their preference per notification type.
- **ESP Adapters** — native integrations for SendGrid, Mailgun, Amazon SES, and Postmark that bypass `wp_mail()` for higher throughput and detailed delivery analytics.

Both are managed via **Jetonomy → Extensions** after installing Jetonomy Pro.

## What's Next?

Control the visual appearance of your community — accent color, font inheritance, layout density, and custom CSS.

[Appearance Settings →](04-appearance.md)
