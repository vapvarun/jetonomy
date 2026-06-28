---
title: "Get the App"
category: "mobile-app"
order: 3
---

# Get the App

There are two ways to put the Jetonomy app in your members' hands: use the **open-source app** as-is, or **publish your own branded app**. Both use the same code and read your branding from WordPress.

## What You Will Learn

- How to try the app on a phone without publishing
- What it takes to publish your own branded app
- The accounts and tools involved (and the no-EAS alternatives)

## Try it first (no publishing)

The open-source app runs in **Expo Go** so you can see it before committing:

1. Install **Expo Go** (free) on a phone from the App Store / Play Store
2. On a computer with [Node.js](https://nodejs.org), run:
   ```bash
   git clone https://github.com/vapvarun/jetonomy-app.git
   cd jetonomy-app
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

**Then build and submit** (full walkthrough in the app repo):

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

## Full guides

The app is open source (GPL): **[github.com/vapvarun/jetonomy-app](https://github.com/vapvarun/jetonomy-app)**. Detailed, non-technical step-by-step guides - branding, signing in, trying the app, building, and troubleshooting - live in the **[wiki](https://github.com/vapvarun/jetonomy-app/wiki)**.

## Next steps

- [Brand Your App](brand-your-app) - set your logo, color, and name
- [Connect Members](connect-members) - how members sign in
