Spam is the fastest way to kill a community's quality. Jetonomy has multiple layers of protection that work silently in the background — your real members never know they are there.

![Anti-spam settings with CAPTCHA provider selection and API key fields](../images/admin-antispam.png)

## What You Will Learn

- How to enable reCAPTCHA v3 or Cloudflare Turnstile
- Where to enter your API keys in the admin settings
- How trusted members are automatically exempted from CAPTCHA
- How Akismet integration catches spam that passes CAPTCHA
- How rate limiting protects against new-member spam floods

## Choosing a CAPTCHA Provider

Go to **Jetonomy → Settings → Anti-Spam** to configure your CAPTCHA provider.

Jetonomy supports two providers:

| Provider | Type | User friction |
|----------|------|---------------|
| Google reCAPTCHA v3 | Score-based, invisible | None — runs in background |
| Cloudflare Turnstile | Challenge-based, invisible | None — usually auto-passes |

Both are invisible to real users. reCAPTCHA v3 assigns a bot-likelihood score and blocks low-scoring submissions. Cloudflare Turnstile analyzes browser signals and shows a challenge only when it cannot verify the user automatically.

Choose Cloudflare Turnstile if your community has members who are privacy-conscious or who block Google services. Both providers have free tiers sufficient for most communities.

## Setting Up reCAPTCHA v3

1. Go to [google.com/recaptcha](https://www.google.com/recaptcha/) and create a v3 site.
2. Add your domain to the allowed domains list.
3. Copy the **Site Key** and **Secret Key**.
4. In **Jetonomy → Settings → Anti-Spam**, select **reCAPTCHA v3**, paste both keys, and click **Save**.

## Setting Up Cloudflare Turnstile

1. Log in to your Cloudflare dashboard and go to **Turnstile**.
2. Add a site and set the widget mode to **Invisible**.
3. Copy the **Site Key** and **Secret Key**.
4. In **Jetonomy → Settings → Anti-Spam**, select **Cloudflare Turnstile**, paste both keys, and click **Save**.

> **Note:** After saving, Jetonomy automatically loads the CAPTCHA script on the post and reply forms. You do not need to add any code to your theme.

## Trusted Members Skip CAPTCHA

Members at Trust Level 2 and above never see or trigger a CAPTCHA check. Jetonomy bypasses the verification entirely for them — the API call is never made.

This means your most active, trusted members post without any friction while new accounts get verified. As members earn reputation and cross the TL2 threshold, they transition out of CAPTCHA checks automatically.

## Akismet Integration

If the Akismet plugin is active on your site, Jetonomy sends every new post and reply through Akismet's spam detection API as a second layer of protection. Content that passes CAPTCHA but that Akismet marks as spam is held in the moderation queue rather than published.

See the [Moderation Queue](03-moderation-queue.md) guide for how to review Akismet-held content.

Akismet and CAPTCHA work independently. You can run both at the same time for maximum protection, or use either one alone.

## Rate Limiting for New Members

Trust Level 0 members (brand-new accounts) are subject to posting rate limits regardless of CAPTCHA:

| Content type | Default limit |
|--------------|---------------|
| Topics per day | 3 |
| Replies per day | 10 |

Limits reset after 24 hours. Members at Trust Level 1 and above are exempt from all rate limits.

You can adjust the default thresholds at **Jetonomy → Settings → Permissions**. Setting a limit to 0 disables it for that trust level.

> **Tip:** Rate limiting is your best defense against coordinated spam from many new accounts. Even if a bot farm passes CAPTCHA, each account can only post 3 topics before hitting the daily limit.

## What's Next?

Learn how in-app notifications keep your members engaged and informed about replies, mentions, and votes.

[Notifications →](../notifications/01-notifications.md)
