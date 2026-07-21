# Users, Roles, and Trust Levels

The single most common question from new site owners: *"How does someone become a Jetonomy user?"* The answer is that **they already are one**. This page explains the three layers that decide what a person can do in your community, and which one to reach for.

## The one-sentence model

**Jetonomy members ARE your WordPress users.** There is no separate registration, no separate account table, no import step. Anyone with a WordPress account on your site is a community member the moment they visit; a community profile row is created for them automatically on first activity.

## Registration flow (public communities)

Registration is WordPress's own:

1. Enable **Settings → General → "Anyone can register"** in WordPress (not a Jetonomy setting).
2. A visitor registers through your normal WordPress signup (or any membership/social-login plugin you use — Jetonomy inherits whatever handles `wp_users`).
3. On their first visit to the community they get a Jetonomy profile automatically — starting at **Trust Level 0** with the WordPress role your site assigns new users (usually Subscriber).

Nothing else to configure. If your membership plugin gates registration, that gate IS the community's gate.

## The three layers

| Layer | Set by | Controls | Where to manage |
|---|---|---|---|
| **WordPress role** (Subscriber, Editor, Administrator…) | You / WordPress | wp-admin access and Jetonomy **capabilities** (`jetonomy_moderate`, `jetonomy_manage_settings`…) granted to roles at activation | WordPress **Users** screen |
| **Trust level** (0–5: New → Elder) | **Earned automatically** by participation (posts, replies, likes received, days visited) — or pinned manually | Community privileges that should be earned, not assigned: posting links/images, editing wikis, flag weight, rate limits | **Jetonomy → Users** (filter by level, override per user) |
| **Space role** (member, moderator, admin) | Space owners/admins | Powers *inside one space only*: moderating its topics, managing its members | Each space's **Members** screen |

The three are deliberately independent:

- A **Subscriber** (WP role) can be a **Trust Level 5 Elder** and a **space admin** — a star community member with zero wp-admin access.
- An **Administrator** always bypasses trust gates and holds every capability.
- Making someone a **space moderator** gives them power in that space only — it never grants wp-admin or site-wide moderation.

## Which layer do I use when?

| I want to… | Use |
|---|---|
| Let someone moderate the whole community from the frontend | Grant the `jetonomy_moderate` capability (a role manager plugin, or a custom role) |
| Let someone run one space | Make them that space's admin/moderator on its Members screen |
| Let regulars post links/images sooner (or later) | Adjust trust-level thresholds in **Jetonomy → Settings → Trust** |
| Promote one specific person past the gates | Pin their trust level on **Jetonomy → Users** |
| Give a client access to Jetonomy settings without full admin | Grant `jetonomy_manage_settings` to their role |

## Capabilities reference

Granted to WordPress roles at activation (Administrator gets all):

| Capability | What it unlocks |
|---|---|
| `jetonomy_moderate` | Site-wide moderation: flag queue, member restrictions, content actions |
| `jetonomy_manage_settings` | Jetonomy settings + the Spaces admin screen |
| `jetonomy_manage_spaces` | Space administration in wp-admin |
| `jetonomy_create_spaces` | Creating spaces from the frontend |

Assign them to custom roles with any role editor; every check falls back safely to `manage_options`, so Administrators are never locked out.
