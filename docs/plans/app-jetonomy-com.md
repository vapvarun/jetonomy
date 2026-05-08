# app.jetonomy.com - Laravel App Builder Platform

## Overview

Central platform for Jetonomy customers to configure and build their branded mobile apps. Separate from the WordPress plugin ecosystem - fast, secure, Laravel-based.

## Tech Stack

- **Framework:** Laravel 11
- **Frontend:** Tailwind CSS + Livewire
- **Database:** MySQL
- **Storage:** S3 (branding assets, build artifacts)
- **Build pipeline:** GitHub Actions + Expo EAS
- **Hosting:** DigitalOcean Droplet
- **Repo:** Private GitHub repo

## Database Schema

### customers
```
id, license_key, site_url, email, plan (starter/growth/agency/lifetime),
status (active/expired), edd_customer_id, created_at, updated_at
```

### app_configs
```
id, customer_id, app_name, accent_color, icon_path, splash_path,
login_bg_path, dark_mode_default, extra_config (JSON), updated_at
```

### builds
```
id, customer_id, platform (android/ios), status (pending/building/ready/failed),
version, download_url, error_log, triggered_at, completed_at
```

## Auth Flow

1. Customer enters license key in the dashboard
2. Laravel validates against EDD API: `GET store.wbcomdesigns.com/edd-api/v2/check-license?key=XXX`
3. If valid → create/update customer record, start session
4. Dashboard shows app config + build history

## Customer Dashboard

```
app.jetonomy.com/dashboard
├── App Settings
│   ├── App Name: [text]
│   ├── App Icon: [upload 1024x1024]
│   ├── Splash Screen: [upload]
│   ├── Accent Color: [color picker]
│   ├── Login Background: [upload or color]
│   └── Dark Mode Default: [toggle]
├── Builds
│   ├── Android: Ready - [Download APK] - Built Mar 30, 2026
│   ├── iOS: Ready - [Download IPA] - Built Mar 30, 2026
│   └── [Request New Build]
├── Build History
│   └── Table of past builds with status, date, download
└── Site Info
    ├── Site URL: courseacademy.com
    ├── License: Pro Growth (active)
    └── Jetonomy Version: 1.2.0
```

## API Endpoints

### From Pro Plugin → Laravel

```
POST /api/app/register
  Body: { license_key, site_url, app_name, accent_color, icon (base64) }
  → Creates/updates customer + config

GET /api/app/status?license_key=XXX
  → Returns: { has_config, latest_build: { android_url, ios_url, status } }

POST /api/app/build
  Body: { license_key, platform: "android" | "ios" | "both" }
  → Triggers build, returns build_id

GET /api/app/build/{id}
  → Returns build status + download URL when ready
```

### From GitHub Actions → Laravel (webhook)

```
POST /api/webhooks/build-complete
  Body: { build_id, status, download_url, platform }
  → Updates build record
```

## Build Pipeline

1. Laravel receives build request
2. Validates license is active
3. Generates `app.json` from stored config (name, icon, colors)
4. Triggers GitHub Actions workflow dispatch:
   ```
   gh workflow dispatch build-app.yml \
     --field customer_id=123 \
     --field config_url=https://app.jetonomy.com/api/build-config/123
   ```
5. GitHub Actions:
   - Clones React Native repo
   - Downloads config + branding assets from Laravel API
   - Injects into app.json
   - Runs `eas build --platform android` (or ios)
   - Uploads artifact to S3
   - Calls webhook: `POST /api/webhooks/build-complete`
6. Laravel updates build status → customer sees download link

## Pro Plugin Integration

In Jetonomy Pro → Settings → App tab:

```php
// Simple: just link to the dashboard
<a href="https://app.jetonomy.com/dashboard?license_key=XXX" target="_blank">
  Open App Builder →
</a>
```

Or embedded experience:
- Plugin sends branding to Laravel API
- Shows build status inline via AJAX polling
- Download links appear in wp-admin

## OTA Updates (Post-Launch)

Expo supports Over-The-Air JS updates - push bug fixes and features without app store resubmission. Customer's app auto-updates on next launch.

Only native changes (new SDK, new permissions) require a full rebuild.

## Security

- License key validated on every API call
- S3 presigned URLs for downloads (expire after 24h)
- Rate limiting on build requests (max 2/day per customer)
- HTTPS only
- No customer credentials stored - auth via license key + EDD validation

## Pages

| Route | What |
|---|---|
| `/` | Landing page - "Build your branded community app" |
| `/login` | License key entry |
| `/dashboard` | App config + builds |
| `/dashboard/builds` | Build history |
| `/docs` | App submission guide for customers |

## Pricing (handled by EDD, not Laravel)

| Tier | App Access |
|---|---|
| Free Jetonomy | Pay $99-199/year separately |
| Pro Starter | Free - included |
| Pro Growth | Free - included |
| Pro Agency | Free - included |
| Pro Lifetime | Free - included |

## Development Phases

### Phase 1 - MVP (2-3 weeks)
- Laravel project setup + deploy to droplet
- Customer auth via EDD license validation
- App config form (name, icon, colors)
- GitHub Actions build workflow for Android
- Build status + download link

### Phase 2 - iOS + Polish (1-2 weeks)
- iOS build support
- Build history
- OTA update pipeline
- Customer documentation for app store submission

### Phase 3 - wp-admin Integration (1 week)
- Pro plugin Settings → App tab
- Inline config + build status via API
- One-click "Open App Builder" with auto-login via license key

## Prerequisites

- Jetonomy v1.2.0 shipped (Abilities API 100%)
- React Native / Expo app codebase built
- DigitalOcean droplet provisioned
- Private GitHub repo for Laravel + RN app
- S3 bucket for build artifacts
- EDD API accessible for license validation
