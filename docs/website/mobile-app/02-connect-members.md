---
title: "Connect Members"
category: "mobile-app"
order: 2
---

# Connect Members

There is **no separate "connect your site" step**. Everyone - you and your members - signs in to the app the same way, and WordPress decides who can do what. This page explains the sign-in flow and how roles work.

## What You Will Learn

- How the site address is provided (typed vs baked in)
- How members create a secure Application Password
- How admin and member roles are handled automatically

## The site address

- **Open-source app:** each person types your community's web address (for example, `https://yourcommunity.com`) once.
- **Your own branded app:** the address is baked in, so members never type it - they just open your app and sign in.

## How everyone signs in - Application Passwords

The app authenticates with **WordPress Application Passwords**, a secure feature built into WordPress core. It is *not* the member's normal password, and it can be revoked at any time without changing their real password.

Each person creates their own:

1. Log in to your site's wp-admin
2. Go to **Users -> Profile** and scroll to **Application Passwords**
3. Enter a name like `My Phone` and click **Add New Application Password**
4. WordPress shows a code like `abcd efgh ijkl mnop` - copy it
5. In the app, enter the **site address** (if asked), the **username**, and paste that **code**

> Application Passwords require your site to be served over HTTPS in production. Some security plugins disable them - if sign-in fails, confirm Application Passwords are enabled.

## Admins vs members - automatic

The app reads each person's role from WordPress, so there is nothing extra to set up:

- **Admins and moderators** also get a **Manage** area in the app (moderation queue, flags, announcements, analytics).
- **Members** see the community (feed, topics, spaces, messages).

Being an admin in WordPress *is* the connection - no extra "register the app" action is needed.

## Revoking access

Anyone can revoke a device from the same **Users -> Profile -> Application Passwords** screen, or sign out / remove the community inside the app. Revoking does not affect their account or their real password.

## Next steps

- [Get the App](get-the-app) - use the open-source app or publish your own
- [Brand Your App](brand-your-app) - set your logo, color, and name
