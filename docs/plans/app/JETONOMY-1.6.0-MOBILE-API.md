# Jetonomy 1.6.0 — Mobile API (lean)

**Status:** SHIPPED in 1.6.0 (free b46c84f / pro 5ca3e99) - verified 2026-07-04. Endpoints /app/config, /feed, /push/register-device and viewer-context enrichment all present in code.
**Consumers:** [`vapvarun/jetonomy-app`](https://github.com/vapvarun/jetonomy-app) (React Native / Expo)

**Guiding principle:** Jetonomy is a WordPress plugin — **use WP core for everything core
provides; never duplicate it.** Authentication, site discovery, media and users are core's
job. This milestone adds only the two things that are genuinely Jetonomy-specific.

> **Auth is NOT in this milestone.** The app authenticates with **WP core Application
> Passwords** via `wp-admin/authorize-application.php` (tap-to-approve → app receives the
> credential on the `success_url` redirect → `Authorization: Basic`). No JWT, no
> `/auth/token`, no token store. Building one would duplicate core. See
> `jetonomy-app/docs/2026-06-REALITY-CHECK.md` §2.

---

## 1. `GET /app/config` — thin white-label + feature block (free)

Only what the core `/wp-json/` root index **can't** express. Do **not** restate site
name/description/icon/auth — the app reads those from `GET /wp-json/`.

```
GET jetonomy/v1/app/config           (public read; pre-login theming)
200:
{
  "accent_color": "#3B82F6",
  "logo_url": "https://.../logo.png",
  "login_bg_url": "https://.../bg.png",
  "dark_mode_default": false,
  "pro_active": true,
  "features": {
    "messaging": true, "reactions": true, "polls": true,
    "badges": true, "custom_fields": true, "web_push": true, "native_push": true
  }
}
```

- `accent_color` / `logo_url` / `login_bg_url`: source from Pro white-label
  (`/settings/white-label`) when active, else Jetonomy defaults.
- `features.*`: convenience mirror of route registration (each Pro extension reports its
  own active flag, filterable) so the app reads one block instead of parsing the index.

## 2. Native push — Expo token store + sender (pro, web-push extension)

New transport **alongside** the browser `/push/subscribe` — do NOT overload the web
`PushSubscription` route.

```
POST   jetonomy/v1/push/register-device   { expo_push_token, platform: "ios"|"android", device_name? }
DELETE jetonomy/v1/push/register-device   { expo_push_token }
```

- Store: new table or a `transport` column on the web-push subscription store
  (`user_id, expo_push_token, platform, device_name, created_at`).
- On every Jetonomy notification (reply, mention, message), the notifier fans out to
  registered Expo tokens via the **Expo Push API** in addition to web-push.
- Payload carries a deep-link target: `post:{id}` | `reply:{id}` | `conversation:{id}`.

---

## Auth hardening (no new endpoint — verify existing guards)

Because credentials are minted by core Application Passwords (not a Jetonomy endpoint),
Jetonomy's ban / email-verification gates are **not** applied at credential-creation time.
They must be enforced in the **REST permission callbacks** on every authenticated route.

- [ ] Contract test: a banned user holding a valid Application Password is still blocked
      (403) on write routes (`POST /posts`, `/replies`, `/conversations`, votes).
- [ ] Contract test: a pending-verification user is blocked where the web flow blocks them.

## Out of scope for 1.6.0

- wp-admin → `app.jetonomy.com` build integration (`/app/register`, `/app/build`,
  `/app/status` + a Pro "App Builder" client). The build server works standalone via
  license-key login. Phase 5+.

## Definition of done

- [ ] `GET /app/config` with white-label sourcing + per-extension feature flags
- [ ] `POST/DELETE /push/register-device` + Expo Push fan-out in the notifier + deep-link payloads
- [ ] Ban / verification enforced in REST permission callbacks (contract tests above)
- [ ] Manifests refreshed (free + pro), contract-audit baseline updated, runbook D-rows for new routes
- [ ] `jetonomy-app` wires Application Passwords auth, `/app/config`, and native push
