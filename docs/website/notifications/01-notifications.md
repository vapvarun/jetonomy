Notifications keep your community members in the loop without requiring them to check back manually. Every relevant activity — replies, mentions, votes — surfaces instantly in the notification bell so members always know when something needs their attention.

## What You Will Learn

- How the notification bell works and where it appears
- Every notification type and when each one fires
- How to mark notifications as read
- How to view the full notifications history
- Where members set their personal notification preferences

## The Notification Bell

The notification bell icon appears in the community navigation bar on every page. When you have unread notifications, a red badge shows the count. The count updates automatically — you do not need to refresh the page.

Click the bell to open a dropdown showing your most recent notifications. The dropdown lazy-loads its content when you click, keeping page load times fast.

Each notification in the dropdown shows:

- The notification type (icon)
- A summary of what happened (for example, "Sarah replied to your topic")
- The time it occurred
- A direct link to the relevant content

Clicking a notification in the dropdown marks it as read and navigates you to the relevant topic, reply, or profile.

## Notification Types

Jetonomy fires a notification for each of the following events:

| Event | Who receives it |
|-------|-----------------|
| Someone replies to your topic | Topic author |
| Someone replies to your reply (threaded) | Reply author |
| Someone mentions you with @username | Mentioned member |
| Your reply is accepted as an answer (Q&A) | Reply author |
| A new topic is posted in a space you follow | All followers of that space |
| Your topic or reply receives an upvote | Content author |
| Your topic or reply receives a downvote | Content author |

Upvote and downvote notifications can be turned off per-member if members prefer not to see them. See the Preferences section below.

## Marking Notifications as Read

**Mark one as read:** Click any notification in the dropdown — navigating to it marks it read automatically.

**Mark all as read:** Click the **Mark all as read** link at the top of the dropdown. All notifications are cleared in a single action. The badge disappears immediately.

Unread notifications are highlighted with a subtle background tint in the dropdown so you can spot them at a glance.

## The Full Notifications Page

The dropdown shows your most recent notifications — roughly the last 10 to 20 items depending on screen size. To see your full notification history, click **See all notifications** at the bottom of the dropdown or navigate directly to `/community/notifications/`.

The full page lists every notification you have received, paginated in groups of 25. You can filter by read / unread status. Notifications older than 90 days are automatically cleaned up from the database by a background cron job.

## Per-User Notification Preferences

Each member can control which notification types they receive. Go to **Profile → Edit Profile → Notifications** (at `/community/u/your-username/edit/`).

Options are:

- In-app notifications on/off per type
- Email notifications on/off per type (see [Email Notifications](02-email-settings.md) for the full email guide)

Members cannot disable notifications for direct mentions — the @mention notification is always delivered to ensure important communications reach their target.

> **Note:** Administrators can set the default notification preferences that new members start with. Go to **Jetonomy → Settings → Email** to configure the defaults applied at signup.

## What's Next?

Learn how to configure email notification delivery, set community-wide defaults, and understand which notification types support email.

[Email Notifications →](02-email-settings.md)
