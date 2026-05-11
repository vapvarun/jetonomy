---
title: "My Spaces"
category: "user-profiles"
order: 4
---

Since Jetonomy 1.4.0, every signed-in member has a personal page at `/community/my-spaces/` that lists every space they run and every space they're a member of, in one place. It is the fastest way to jump back into a space you are active in without scrolling the home page.

![My Spaces page showing two sections - Spaces you run and Spaces you're in - with role badges and unread counts](../images/my-spaces.png)

## What You Will Learn

- Where the My Spaces page lives and how to reach it
- The two sections shown on the page and what they mean
- What each row tells you at a glance
- Quick actions available per row
- What members see when they're brand new and have not joined any space yet
- The privacy semantics: who can see this page

## Where The Page Lives

The page is always at `/community/my-spaces/`. It is signed-in only. Visiting the URL while signed out redirects to the login page and returns to My Spaces after a successful sign-in.

There are three built-in ways to reach the page:

- The **My Spaces** link in the header avatar menu (added automatically in Jetonomy 1.4.0+)
- The **My Spaces** tab on `/community/u/<your-login>/` (your own profile page)
- The mobile drawer menu under "Community"

If your theme overrides the header or profile templates, the link may not appear automatically. The page itself still works at the URL.

## The Two Sections

The page is split into two sections, stacked top to bottom.

### Spaces You Run

The first section lists every space where you are a space admin or space moderator. These are the spaces you have authority over.

For each space, the row shows:

- The space icon and title
- A role badge ("Admin" or "Moderator")
- The current unread count
- The last activity timestamp ("Active 2 hours ago")
- Quick action buttons: **Visit**, **Edit**, **Members**

If you run no spaces, this section is replaced with a short empty state: "You don't run any spaces yet. If your community allows it, you can start one." If front-end space creation is enabled for your role, the empty state includes a **Create a space** button that goes to `/community/new-space/`.

### Spaces You're In

The second section lists every space where you are a regular member. These are the spaces you have joined but do not moderate.

For each space, the row shows:

- The space icon and title
- The space type (Forum, Q&A, Ideas, Show & Tell, Social Feed)
- The current unread count
- The last activity timestamp
- Quick action buttons: **Visit**, **Leave**

If you have not joined any spaces yet, this section shows a friendly empty state with a **Browse the community** button that goes to the community home.

## What Each Row Tells You

The row layout is designed to answer two questions at a glance: "Is there anything new to read here?" and "What can I do here right now?"

| Element | What it means |
|---|---|
| Icon | The Lucide icon picked by the space owner |
| Title | The space name, linked to the space home |
| Role badge | "Admin", "Moderator", or no badge for regular members |
| Unread count | New posts and replies you have not read yet |
| Last activity | When the most recent post or reply landed in the space |
| Type label | Forum, Q&A, Ideas, Show & Tell, or Social Feed |

The unread count is per-space, computed from your last-read timestamp. Catching up on a space marks it read and the count goes to zero until new content arrives.

## Quick Actions

Each row carries one to three buttons depending on your role in the space.

- **Visit** is always present. It opens the space home.
- **Edit** appears only on rows in the "Spaces you run" section. It opens the front-end Edit Space page covered in the previous article.
- **Members** appears only on rows in the "Spaces you run" section. It opens the members tab where you can promote, demote, or remove members.
- **Leave** appears only on rows in the "Spaces you're in" section. Clicking it asks for confirmation and then removes you from the space.

A space admin who is the only admin cannot leave their own space. The **Leave** button is hidden in that case and a tooltip explains that ownership must be transferred first.

## Empty States

Brand-new members often land on My Spaces before they have joined anything. The page handles four empty-state combinations:

| You run | You're in | What the page shows |
|---|---|---|
| Nothing | Nothing | A single full-page empty state inviting you to browse the community |
| Nothing | One or more | Section 1 collapsed with a short hint, section 2 normal |
| One or more | Nothing | Section 1 normal, section 2 collapsed with a "Find spaces to join" link |
| One or more | One or more | Both sections normal |

The empty states are intentionally friendly and short. The goal is to point new members at the next action, not to make them feel like they are missing out.

## Privacy

The My Spaces page is personal to the signed-in user.

- It requires sign-in. Signed-out visitors are bounced to login.
- It is excluded from search engine indexing via the `noindex, nofollow` meta tag.
- It is not visible to anyone else. There is no public URL that shows another user's space list.
- Membership in a Hidden space is shown on this page but is still not visible on the user's public profile.

If you want to see which spaces another user is in, you have to look at their public profile, which only shows public memberships.

## Performance

The page paginates server-side at 25 spaces per section. Most members never trigger pagination because they belong to far fewer than 25 spaces. Communities with very active staff may see paginated results in the "Spaces you run" section.

Unread counts are read from the same per-user read-status table used everywhere else in Jetonomy. Loading My Spaces does not trigger a separate count query per space; the page issues one batched query and renders.

## What's Next?

The My Spaces page is one entry into your community life. The full profile page covers everything else: activity feed, badges, trust level, and account settings.

[Your Profile Page →](01-profiles.md)
