---
title: "What's New in 2.0.5"
category: "whats-new"
order: 3
---

# What's New in 2.0.5

Safer updates. Jetonomy now checks each Jetonomy and Jetonomy Pro update package against its release signature before WordPress installs it.

> **Note:** Jetonomy and Jetonomy Pro are released together. Jetonomy Pro 2.0.5 requires Jetonomy 2.0.5, so update both at the same time.

## Safer updates

- **Signed updates.** Every Jetonomy and Jetonomy Pro release is signed when it is built. Before an update installs, Jetonomy checks that the package is the exact file that was released for that version. In this release the check only reports; updates install as before.
- **Nothing to set up.** The check runs on its own during normal dashboard, WP-CLI and automatic updates. Your licence key and settings are unchanged.

## Fixes

- **View details.** The "View details" link for a Jetonomy update no longer fails when the update server cannot be reached.
- **Licensing library.** The bundled licensing library is updated. When several plugins bundle it, the newest copy runs, and its screens load correctly on sites where the plugins folder is a symlink.

## What's new in Pro

- Jetonomy Pro updates are checked by Jetonomy 2.0.5 in the same way. No change to Pro features.
