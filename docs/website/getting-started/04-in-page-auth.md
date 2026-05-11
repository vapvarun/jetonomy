---
title: "In-Page Authentication"
category: "getting-started"
order: 4
---

Before Jetonomy 1.4.0, anything that required a logged-in member bounced visitors to `wp-login.php`. That meant signing in to upvote a post or reply to a thread sent visitors to a generic WordPress login screen, then back to the community after a redirect. From 1.4.0 onward, Jetonomy handles Login, Register, and Forgot Password in-page through its own `/auth/*` REST endpoints, with forms that match your theme and the rest of the community UI.

![Login modal opening over a topic page when a guest clicks to reply](../images/auth-login-modal.png)

## What You Will Learn

- Where the in-page auth forms appear and how they behave
- Why the new flow matters for member experience
- How captcha protection now covers signup, not just posting
- Who still uses `wp-login.php` and why
- How auth behaves in Private community mode
- How to customize the auth surface with theme tokens

## Where the Forms Appear

In-page auth shows up in two ways depending on what the visitor is doing.

### Modals on Interaction

When a signed-out visitor tries to do something that requires an account, Jetonomy opens a modal directly over the page they're on. The modal carries Login, Register, and Lost Password tabs.

Common triggers:

- Clicking the upvote arrow on a post or reply
- Starting a reply
- Clicking "Follow" on a space or member
- Trying to subscribe to a tag
- Clicking "Bookmark"

After successful sign-in, the modal closes and the action the visitor was trying to take happens automatically. They never lose their place.

### Dedicated Pages

Three full-page routes are always available:

| Route | Purpose |
|---|---|
| `/community/login/` | Direct link for sign-in |
| `/community/register/` | Direct link for new accounts |
| `/community/lost-password/` | Direct link for password reset |

These pages are useful for sharing in marketing emails, embedding in your nav, or sending in support replies. They use the same forms and the same `/auth/*` endpoints as the modal, so behaviour is consistent.

## Why This Matters

The old `wp-login.php` flow worked, but it had three real problems:

1. **Visual jarring.** The WordPress login screen does not look like your community. Visitors went from your themed pages to a generic blue-and-white form and back. The break in visual continuity made the community feel less polished.
2. **Lost context.** Visitors who clicked "Reply" had to sign in, then find their way back to the thread. WordPress's redirect handling did not always land them on the right page, especially with theme-specific URLs.
3. **Slow perceived load.** Two full page navigations for what should be a quick "sign me in and let me reply" step.

In-page auth fixes all three. The forms render where the visitor is, look like the rest of the community, and pick up the visitor's intended action when they finish signing in.

## Captcha Now Protects Signup

Site owners can configure reCAPTCHA or Cloudflare Turnstile keys under **Jetonomy → Settings → Anti-spam**. Before 1.4.0, those keys only protected post and reply submission. Bots could still register accounts freely.

From 1.4.0, the same captcha keys also protect:

- New account registration
- Password reset requests
- Login attempts after repeated failures from the same IP

Nothing needs to change in your settings. If you already had captcha configured, it now extends to signup automatically. If you don't have captcha configured, the auth forms still work; they just have less spam protection.

### Captcha Providers Supported

| Provider | Where to get keys |
|---|---|
| Google reCAPTCHA v3 | https://www.google.com/recaptcha/admin |
| Cloudflare Turnstile | https://dash.cloudflare.com/?to=/:account/turnstile |

Choose whichever fits your stack. Turnstile is recommended if you're privacy-conscious or already on Cloudflare; it runs without challenging visitors in most cases.

## Who Still Uses wp-login.php

In-page auth covers your community. The standard WordPress site-wide login is unchanged.

Administrators (anyone with the `manage_options` capability) can still sign in at `wp-login.php` and reach `wp-admin/`. That's intentional. Site owners need a reliable way to get into the admin area even if community pages have an issue, and security plugins, two-factor plugins, and SSO integrations all hook into `wp-login.php`.

In practice:

- Members never need to visit `wp-login.php`
- Admins can use either `wp-login.php` (for admin access) or `/community/login/` (to log in as a member)
- Any plugin you have that customises `wp-login.php` (login restrictions, two-factor, branding) still works for admin login

## Private Community Mode

If you've set your community to Private (under **Jetonomy → Settings → Privacy**), signed-out visitors can only reach three pages:

- `/community/login/`
- `/community/register/`
- `/community/lost-password/`

Every other community URL redirects to `/community/login/` with a `redirect_to` parameter, so the visitor lands on the page they were trying to reach as soon as they sign in.

Registration can be disabled separately if you only want to allow invited members. In that case the Register tab is hidden, and `/community/register/` redirects to `/community/login/`.

## Customization

The auth surface uses the same `--jt-*` design tokens as the rest of Jetonomy. That means your theme's brand color, fonts, border radius, and spacing are picked up automatically. No custom CSS required for a polished match.

### Light Auth Surface in Dark Mode

There's one intentional exception: the Login Block and the modal auth forms stay in light mode even when the rest of your community is in dark mode. This is a deliberate UX choice. Sign-in forms in dark mode are statistically harder to read and easier to mistype, especially on mobile. Keeping auth surfaces light maintains form readability where it matters most: at the point of conversion.

If you want to override this and run a dark auth surface (for a fully dark community theme), you can do it via CSS:

```css
.jt-auth-modal,
.jt-login-block {
  --jt-bg: #1a1a1a;
  --jt-text: #f5f5f5;
}
```

### Customising Form Labels

All auth form labels are translatable through the standard WordPress translation pipeline. They use the `jetonomy` text domain. If you're running a translated site, the forms pick up your translations on the next load.

### Replacing the Forms Entirely

For deep customisation (e.g. adding a "Sign in with Google" button via your SSO plugin), the auth templates are theme-overridable:

```
your-theme/jetonomy/auth/login-form.php
your-theme/jetonomy/auth/register-form.php
your-theme/jetonomy/auth/lost-password-form.php
```

Copy from `wp-content/plugins/jetonomy/templates/auth/` to start.

## What's Next?

Learn how Jetonomy's in-product modal toolkit replaces native browser dialogs across the community.

[Modals and Confirmations](../discussions/09-modals-confirmations.md)
