---
title: "Get the App"
category: "mobile-app"
order: 3
---

# Get the App

The Jetonomy app is published under **your** developer accounts, as **your** app - your icon, your name - reading its branding from your WordPress site.

## What You Will Learn

- How to get the app source
- What it takes to publish your own branded app
- The accounts and tools involved (and the no-EAS alternatives)

![The Communities screen, where a member can switch between several Jetonomy communities or add another](../images/mobile-app/07-communities.png)

## Getting the app source

The app source is **not publicly downloadable**. To get a copy - whether you
want to build it yourself or have it built for you - contact Wbcom and ask for
mobile app access.

Once you have the source, it runs in **Expo Go**, so you can see it on a real
phone before publishing anything:

1. Install **Expo Go** (free) on a phone from the App Store / Play Store
2. On a computer with [Node.js](https://nodejs.org), from the app folder run:
   ```bash
   npm install
   npx expo start
   ```
3. Scan the QR code with Expo Go (Android) or the Camera app (iPhone)
4. Sign in with your site address + an Application Password

## Publish your own branded app

This puts **your** app - your icon and name - in the App Store and Google Play under **your own** developer accounts.

**You provide (one time):**

- A free [Expo](https://expo.dev) account
- An **Apple Developer** account ($99/year) for the App Store
- A **Google Play** account ($25 one time) for the Play Store

**Then build and submit:**

```bash
npm i -g eas-cli
eas login
eas init
eas build --profile production --platform all
eas submit
```

> Not technical? You only need to create the three accounts. A developer - or the Wbcom team as a one-time setup - can do the rest and hand you the live app.

### Prefer not to use Expo's cloud (EAS)?

The app is built with Expo, but EAS (Expo's paid cloud build service) is optional. You can build for free with `eas build --local` on your own Mac, or run `npx expo prebuild` and build the native projects in Xcode and Android Studio, or use Fastlane / Codemagic / GitHub Actions. A Mac with Xcode is required for iOS, and the Apple and Google accounts are required to publish (Apple's rules, not Expo's).

## Getting help

Branding and member sign-in are covered here in these docs - see
[Brand Your App](01-brand-your-app.md) and
[Connect Members](02-connect-members.md). For anything specific to building and
submitting your app, contact Wbcom; the same request that gets you the source
gets you the build walkthrough for your setup.

## Next steps

- [Brand Your App](01-brand-your-app.md) - set your logo, color, and name
- [Connect Members](02-connect-members.md) - how members sign in
