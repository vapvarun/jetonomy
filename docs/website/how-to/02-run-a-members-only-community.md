---
title: "Run a Members-Only Community"
category: "how-to"
order: 2
---

# Run a Members-Only Community

Use this guide when your discussions should not be readable by the public. You can lock the whole community behind sign-in, or lock only some spaces. When you finish, you will know exactly what a visitor sees at each level.

## What you will set up

- Either a fully private community, or private and hidden spaces inside a public one
- A way for the right people to get in
- A check from a logged-out browser window

## Before you start

- You can open **Jetonomy → Settings** as an administrator.
- You have a private (incognito) browser window to act as a guest.
- You know how new people will join: open sign-up, approval, invite links, or a paid plan.

## Step 1: Choose how much to lock

| You want | Use |
|---|---|
| Nothing readable until sign-in | **Private community** (Step 2) |
| Most spaces public, a few locked | A space **Visibility** of **Private** or **Hidden** (Step 3) |
| A whole section out of sight | A category **Visibility** of **Hidden** (Step 4) |

You can combine these.

## Step 2: Make the whole community private

1. Go to **Jetonomy → Settings → General**.
2. Find the **Access Control** card.
3. Under **Community Access**, choose **Private community**. Its description reads: "Only logged-in members can view any forum content. Everyone else is redirected to the login page."
4. Click **Save Settings**.

The choice applies on the next page load. The default is **Public community**.

Guests who open any community address are sent to the WordPress sign-in page and brought back after they log in. The WordPress sign-in, registration and password-reset pages are not part of the community, so they stay open. See [Access Control](../admin-settings/07-access-control.md) for the full detail.

> **Tip:** Switching to private makes existing public links into sign-in redirects. Search engines will slowly drop your pages. That is the point, but do it before you promote the community.

## Step 3: Lock individual spaces

1. Go to **Jetonomy → Spaces** and click **Edit** under the space.
2. On the **General** tab, set **Visibility**:
   - **Public**: anyone can find and read it.
   - **Private**: anyone signed in can find it, but only members can read it.
   - **Hidden**: only members can find it. Everyone else gets "Space not found."
3. Set **Join Policy**: **Open**, **Requires Approval** or **Invite Only**.
4. Click **Update Space**.

Pick the join policy with care. A **Private** space with an **Open** policy is readable by any signed-in person who clicks **Join Space**. For a real lock, use **Requires Approval** or **Invite Only**.

**Hidden** always means **Invite Only**. If you pick Hidden, Jetonomy switches the join policy for you and tells you so.

## Step 4: Hide a whole category (optional)

1. Go to **Jetonomy → Categories** and click **Edit** under the category.
2. Set **Visibility** to **Hidden**, then click **Update Category**.

A hidden category and every space inside it are out of sight for people who are not members of those spaces. **Private** on a category means signed-in members only.

## Step 5: Let the right people in

- **Open registration:** turn on **Anyone can register** under WordPress **Settings → General**. To make new members confirm their address first, tick **Email verification** in **Jetonomy → Settings → General**.
- **Approval or invite links:** see [Invite Your First Members](05-invite-your-first-members.md).
- **Paid plan:** see [Restrict a Space to Paying Members](03-restrict-a-space-to-paying-members.md).

## Check that it works

Open a private browser window where you are not signed in.

| Setup | What the guest sees |
|---|---|
| **Private community** | The WordPress login page |
| Public community, **Private** space | The space is missing from the lists. A direct link shows "This space is private. Please log in to request access." with a **Log In** button |
| Public community, **Hidden** space | "Space not found." |

Then sign in as a test member who is not in the space:

- **Requires Approval**: "This space requires approval to join. Submit a request below."
- **Invite Only**: "This space is invite-only. You need an invitation to join."
- **Open** (but Private): "This space is private. Join to access its topics and discussions."

## Common questions

**What is the difference between a private community and a private space?**
Private community is one switch that blocks every page for guests. A private space blocks only that space, while the rest stays public.

**Can guests still register?**
Yes. Registration is a WordPress page, so it works as long as **Anyone can register** is on. If you keep it off, create accounts yourself or use invite links.

**Are posts in private spaces safe from search and widgets?**
Posts in private or hidden spaces are left out of search, tag pages, trending lists and recent-post widgets for non-members.

**Will plan holders still get in?**
Yes. An access rule lets people in even when the space is private. See [Restrict a Space to Paying Members](03-restrict-a-space-to-paying-members.md).

## Related guides

- [Membership & Join Policies](../spaces-and-categories/03-membership-policies.md)
- [Categories](../spaces-and-categories/00-categories.md)
- [General Settings](../admin-settings/01-general.md)
- [In-Page Sign-In](../getting-started/04-in-page-auth.md)
- [Access Control](../admin-settings/07-access-control.md)
