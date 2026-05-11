---
title: "Create a Space from the Front-End"
category: "spaces-and-categories"
order: 7
---

Since Jetonomy 1.4.0, members with the right role can create a new space without ever opening wp-admin. The front-end Create Space page lives at `/community/new-space/` and gives community owners a way to delegate space creation to trusted regulars, team leads, or paying members without handing out WordPress admin access.

## What You Will Learn

- Where the Create Space page lives and who can reach it
- Which roles are allowed to create spaces, and how to change that
- Every field available on the form, including the visual icon picker
- What happens the moment the form is submitted
- When to use this page versus the wp-admin equivalent

## Where The Page Lives

The page is always available at `/community/new-space/`. It is part of the standard `/community/` rewrite group, so it inherits the same theme, header, and footer as the rest of your community pages.

There is no separate menu item by default. Most communities expose the page in two places:

- A "Create space" button on `/community/` for signed-in users with permission
- A "Start a space" link in the header avatar menu

Both links are conditional. Members who do not have permission never see the link and never see the page either, even if they type the URL directly.

## Who Can Create Spaces

Open **Jetonomy → Settings → Front-end space creation**. The setting is a list of WordPress roles. Tick the roles you want to allow.

The default is Administrator only, which matches the pre-1.4.0 behaviour. Most communities widen this to Editor, Author, or a custom role such as "Community Builder" after they have run for a few weeks and identified trusted members.

A few important notes:

- The permission is role-based, not per-user. If you want to grant one specific member the ability to create spaces, add them to a role that has it.
- Granting the right to create a space is not the same as granting the right to moderate every other space. A member who can create one space only moderates the spaces they created, not the whole community.
- Network admins on multisite have the permission everywhere by default.

## The Form Fields

The front-end form mirrors the wp-admin space editor field-for-field. There is no "lite" version of space creation.

| Field | What it controls |
|---|---|
| Title | The display name shown in listings and the space header. Required. |
| Slug | The URL segment under `/community/s/`. Generated from the title; you can edit it. Must be unique. |
| Description | One or two sentences shown on the space card and the space header. |
| Icon | A visual icon shown next to the title everywhere the space appears. |
| Category | Which top-level community category the space belongs to. Optional but recommended for navigation. |
| Type | Forum, Q&A, Ideas, Show & Tell, or Social Feed. Cannot be changed after creation. |
| Visibility | Public, Private, or Hidden. |
| Join policy | Open, Approval Required, or Invite Only. |
| Posts per page | 10, 25, or 50. Defaults to the community-wide value. |

The form does its own validation in the browser before submission, then again on the server. Submitting with an empty title or a slug that already exists returns an inline error rather than a generic failure.

## The Visual Icon Picker

The icon field is not a free-text field. Jetonomy ships with a Lucide icon picker so every space gets a consistent, professionally-drawn icon.

The picker shows 16 default icons up front, covering the most common community space themes: messages, lightbulb, sparkles, code, life-buoy, megaphone, palette, briefcase, gamepad, book, heart, globe, target, rocket, trophy, and users.

Click "Show more" to reveal another 8 icons for less common topics. If none of those fit, the search field at the top filters the entire Lucide catalogue by name, so typing "music" surfaces the music note icon, "camera" surfaces the camera icon, and so on.

The picker stores only the icon name, not an SVG, so the icon stays crisp at any size and automatically picks up the active theme's color tokens.

## What Happens On Submit

Submitting the form does five things in one transaction:

1. Creates the space row in `wp_jt_spaces`
2. Adds the submitting user as the space admin (`role = admin`)
3. Adds the space to the chosen category, if any
4. Flushes the relevant rewrite caches so the space URL resolves immediately
5. Redirects the user to the new space at `/community/s/<slug>/`

There is no approval queue. The space is live the moment the form is submitted. If your community needs an approval step before new spaces appear, file a ticket and we will surface the existing `jetonomy_can_create_space` filter as a setting.

## Validation Hints

- Title is required and cannot be a duplicate of an existing space title within the same category.
- Slug is required, must be lowercase, and must be unique across the whole community. Jetonomy auto-generates a safe slug from the title; you only need to edit it if you want a specific URL.
- Description is optional but space cards look better with one.
- Category, Type, Visibility, and Join policy default to the community-wide defaults set in **Jetonomy → Settings**.
- Posts per page defaults to the community-wide value and is read from the same setting.

## Permission Gotchas

A few rules that surprise people on first use:

- **Creating is not moderating.** A role granted "create spaces" is automatically space admin only for the spaces it creates. It cannot moderate other spaces it did not create.
- **Visibility is per-space, not per-role.** A role allowed to create spaces can create a Hidden space. If you want to restrict that, use the `jetonomy_can_create_space_with_visibility` filter (developer docs).
- **Deactivating a member who created a space does not delete the space.** The space remains; ownership transfers to the next admin in the space, or to the site administrator if there is no other admin.
- **Slug collisions are checked across the whole site.** A member trying to create a space with a slug another space already uses will see an inline error, even if they cannot see the other space.

## Front-End Form vs wp-admin

Both paths produce identical spaces. Pick whichever is faster for the situation.

| Situation | Use front-end | Use wp-admin |
|---|---|---|
| You're a regular member with permission | Yes | Not available |
| You're an admin and already in the community | Yes, faster | Either |
| You're an admin setting up the community for the first time | Either | Either, bulk import easier |
| You want to create 10+ spaces in one session | Either | wp-admin has bulk tools |
| You want to seed a space with demo content | wp-admin | wp-admin only |
| You want to change advanced options (access rules, custom roles) | wp-admin | wp-admin only |

The front-end form covers everything a member or space owner needs. The wp-admin editor adds bulk tools and a few advanced toggles that only site administrators ever touch.

## Developer Hooks

Two hooks come up most often when customising this page:

- `jetonomy_use_frontend_space_edit` (filter) - returns true to route both the Create and Edit space flows through the front-end UI. Default true.
- `jetonomy_can_create_space` (filter) - boolean override per user. Useful if you need a per-user gate (e.g. paid membership) on top of the role-based default.

See the Developer Reference for the full signatures and examples.

## What's Next?

Once a member has created a space, they often want to tweak it. The Edit Space page lets them adjust the icon, description, join policy, and more without leaving the front-end.

[Edit a Space from the Front-End →](08-front-end-edit-space.md)
