# Shared "Integrations / Stack" Tab — Cross-Promote + 1-Click Free Install

**Target version:** 1.5.x (after 1.5.0)
**Status:** IMPLEMENTED 2026-06-14 — built **self-contained** (mirrors Learnomy's `includes/integrations/` pattern), NOT the external shared-lib design sketched below. Ships `Jetonomy\Integrations\Companion_Registry` + `Companion_Installer` + an Integrations-tab card grid offering WB Gamification, WPMediaVerse, and Learnomy via the EDD free-install flow (`activate_license` -> `get_version` -> `Plugin_Upgrader` -> activate), `install_plugins` cap + nonce, store allowlisted to wbcomdesigns.com. No store-side catalog endpoint needed (registry is a local declarative array, filterable via `jetonomy_companions`). The shared-lib/remote-catalog notes below are retained only as a possible future consolidation.
**Card:** "Add shared Wbcom 'Integrations' tab (cross-promote + 1-click free install of the Academy Stack incl. Learnomy)".

## Goal

From Jetonomy's own settings, let a site owner discover and **one-click install + activate** the related free Wbcom plugins (Learnomy LMS, MediaShield, WB Gamification, WPMediaVerse, …) — reciprocal cross-promotion across the stack. Pro counterparts link out to the store (license-gated). Pure promotion: never a gate, always optional.

## What already exists (verified) vs what to build

| Piece | Status | Location |
|---|---|---|
| EDD SL SDK (free auto-license + updater) | EXISTS | `jetonomy/libs/edd-sl-sdk/` — free key `wbcomfreec7e2…` wired in `jetonomy.php:62-129` |
| Settings tab hooks | EXISTS | `jetonomy_admin_settings_tabs` + `jetonomy_admin_settings_tab_content` (Pro already renders a membership-integrations card via these) |
| Tab nav + card UI pattern | EXISTS | `includes/admin/views/settings.php` tab loop; `jt-settings-card` structure |
| One-click plugin installer | **BUILD** | No `Plugin_Upgrader` usage anywhere in jetonomy/jetonomy-pro |
| Shared installer library | **BUILD** | No `wbcom/academy-stack-integrations` lib exists; build once, reuse across the stack |
| Remote plugin catalog | **BUILD** | JSON endpoint on store.wbcomdesigns.com (slug, label, icon, why, free item_id+key, pro item_id) |

## Architecture decisions

1. **Lives in FREE Jetonomy** (not Pro). Cross-promotion must reach free users, and the EDD SDK + free keys already live in free. The existing Pro *membership-integrations* card stays where it is — this is a separate "Stack" section/sub-tab.
2. **Build the installer as a shared bundled library** (`wbcom/academy-stack-integrations`), vendored the same way every Wbcom plugin bundles the identical EDD SL SDK. Do NOT hand-roll a per-plugin plugin list. The lib renders the cards + runs the install flow; each host plugin just bundles it and mounts the tab.
3. **Single remote catalog** so add/retire/key-rotate happens in one place: the lib fetches one JSON from store.wbcomdesigns.com, caches it (transient, e.g. 12h) with a bundled static fallback so the tab works offline.
4. **No arbitrary installs.** The installer only ever installs slugs present in the signed catalog; the download URL is resolved through EDD `get_version` with the catalog's free `item_id` + free key; the store host is allowlisted to `wbcomdesigns.com`.

## Components

### A. Remote catalog (store side — dependency)
`GET https://store.wbcomdesigns.com/wp-json/wbcom/v1/stack-catalog` → JSON array:
```json
[{ "slug":"learnomy", "file":"learnomy/learnomy.php", "label":"Learnomy LMS",
   "icon":"https://…", "why":"Sell courses to your community",
   "free_item_id":1660333, "free_key":"wbcomfree…", "pro_item_id":1660334,
   "pro_url":"https://wbcomdesigns.com/downloads/learnomy/" }]
```
Cached locally; static fallback shipped in the lib. (Catalog endpoint is a store-side task — file a wbcomdesigns.com card.)

### B. Shared installer library (`libs/academy-stack/`)
- `Catalog` — fetch + cache + fallback + filter `wbcom_stack_catalog`.
- `Installer` — `require_once ABSPATH 'wp-admin/includes/{plugin,file,class-wp-upgrader}.php'`; resolve free download URL via the bundled EDD SDK's `get_version` (free item_id + free key); `Plugin_Upgrader->install()`; `activate_plugin()`.
- `Tab_Renderer` — renders the cards, greys out the current plugin, shows per-card state.
- One REST route `POST jetonomy/v1/stack/install {slug}` (frontend-REST-only rule), `permission_callback` = `current_user_can('install_plugins')` via `REST_Auth::auth_mutation` with an `install_plugins` cap check; nonce enforced.

### C. UI (host plugin: Jetonomy)
- Mount a "Stack" (or "Add-ons") sub-tab under the existing Integrations tab, or a top-level settings tab, via `jetonomy_admin_settings_tabs` + `_tab_content`.
- Card per catalog entry: icon, label, "why" line, and a state-driven button:
  - **Active** (plugin active) → greyed "Installed".
  - **Installed, inactive** → "Activate".
  - **Not installed** → "Install free" (runs the REST install flow with a spinner).
  - **Pro available** → secondary "Get Pro" link to `pro_url` (allowlisted host).
  - **Self** → greyed out.
- States: not-installed / installing / installed / active / error — all handled inline.

## Security (non-negotiable)
- `install_plugins` capability + nonce on the install route.
- Only catalog slugs are installable; download URL comes from EDD `get_version`, never from client input.
- Store/Pro URLs allowlisted to `wbcomdesigns.com`.
- Fail closed on catalog-fetch failure (show the static fallback, never a broken install).

## Big-site / UX
- Catalog cached (transient) + invalidation via a "refresh" action; static fallback bundled.
- Install is idempotent (already-installed → activate, never re-download).
- Multi-actor: re-check plugin state at render and again server-side before install.

## Phasing
- **Phase 1:** build the shared lib once + the store catalog endpoint; adopt in Jetonomy (bundle lib + mount tab). This card is Jetonomy's adoption.
- **Phase 2:** other stack plugins (Learnomy, MediaShield, WB Gamification, WPMediaVerse) bundle the same lib + mount the tab — reciprocal promotion.

## Test plan
- Standalone: tab renders with the static catalog when the store is unreachable; no PHP notices.
- Install flow: from a clean site, "Install free" on Learnomy → downloads via EDD free key, installs, activates; button → "Installed". Re-click is a no-op.
- Security: install route rejects without `install_plugins` cap / bad nonce / slug not in catalog.
- State matrix: self greyed; active greyed; inactive shows Activate; pro link goes only to wbcomdesigns.com.
- Mobile 390px + RTL + dark mode on the cards.

## Dependencies / blockers
1. **Store-side catalog endpoint** on store.wbcomdesigns.com (file a card there) with free item_id+key + pro item_id per stack plugin.
2. Confirm each stack plugin's free EDD `item_id` + free distribution key (Learnomy/MediaShield/Jetonomy share `wbcomfreec7e2…`; WB Gamification uses `wbcomfree6e2a…`).
3. Decide the shared-lib home (vendored copy per plugin, like the EDD SDK) and its composer/package name.
