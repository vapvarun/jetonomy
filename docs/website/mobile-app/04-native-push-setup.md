---
title: "Native Push Setup"
category: "mobile-app"
order: 4
---

# Native Push Setup

Native push sends notifications to a member's phone even when the Jetonomy app is closed - a new reply, a mention, or a direct message shows up on the lock screen. It is delivered by the **Web Push** extension in Jetonomy Pro, which handles both browser push and native (Expo) push from the same settings.

> **Requires Jetonomy Pro with the Web Push extension enabled, plus Jetonomy 1.6.0 or newer for the mobile app.** Native push also requires your site to be served over HTTPS.

## What You Will Learn

- What native push needs before it can work
- Where to turn it on and set the notification title and icon
- How members' devices get registered (nothing manual for you)

## Before you start

Native push relies on three things being in place:

1. **Jetonomy Pro is installed and licensed.**
2. **The Web Push extension is enabled** under **Jetonomy -> Extensions**.
3. **Your site uses HTTPS.** Push services reject insecure origins.

When the Web Push extension is active, the app's config endpoint (`GET /wp-json/jetonomy/v1/app/config`) reports `features.native_push: true`, and the app starts registering devices for push.

## Turn on push and set the message

1. Go to **Jetonomy -> Settings** and open the **Web Push** tab
2. Tick **Enable Web Push**
3. **VAPID Public Key** - this is generated automatically the first time the extension runs and shown here read-only. You do not need to create or paste a key. (If it reports it could not be generated, your server needs OpenSSL with EC support.)
4. **Default Notification Title** - the title shown on push notifications. Defaults to your site name; change it to your community's name if you prefer.
5. **Notification Icon URL** - the small icon shown with the notification. Recommended: a 192x192 PNG. Leave it blank to fall back to the site favicon.
6. Click **Save Web Push Settings**

These same settings drive both browser push and native app push - there is no separate native-push screen to configure.

## How members' devices get registered

You do not register devices by hand. When a member signs in on the Jetonomy app, the app sends its device push token to your site automatically:

```
POST /wp-json/jetonomy/v1/push/register-device
```

with the device's Expo push token and platform (`ios` or `android`). When a member signs out or uninstalls, the token is removed with a matching `DELETE`. From then on, notifications the member would see in-app are also delivered to their device.

## In-app notifications vs native push

- **In-app notifications** always work - the bell and the notifications screen inside the app.
- **Native push** is the extra step that reaches the member when the app is closed. It only fires once the Web Push extension is enabled and saved, over HTTPS.

## Next steps

- [Brand Your App](brand-your-app) - set your logo, color, and name
- [Connect Members](connect-members) - how members sign in
- [Get the App](get-the-app) - use the open-source app or publish your own
