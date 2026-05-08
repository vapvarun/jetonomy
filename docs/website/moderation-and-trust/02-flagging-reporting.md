Flagging lets any logged-in member report content that breaks your community rules. It is the first step in the moderation pipeline - members surface problems, and your moderators review and act.

![Admin moderation dashboard showing flagged content awaiting review](../images/admin-moderation.png)

## What You Will Learn

- How to flag a topic or reply
- What information a flag captures
- Who can flag content and what the restrictions are
- What happens to flagged content before a moderator reviews it
- How flags flow into the moderation queue

## How to Flag Content

Every topic and every reply has a **...** (more actions) menu. Open it and click **Report**. A small modal appears asking for a reason. Type a brief description of the problem - for example, "This contains spam links" or "This is abusive toward another member" - and click **Submit Report**.

The flag is saved immediately. You receive a confirmation message and the modal closes.

> **Tip:** A good flag reason helps moderators act faster. "Spam" alone works, but "Contains a link to a commercial site unrelated to this community" gives the moderator everything they need without having to investigate.

## Who Can Flag Content

Any logged-in member can flag a topic or reply, regardless of their trust level. There is one restriction: you cannot flag your own content.

The flag button is not visible to guests (logged-out visitors). If you want guests to be able to report content, you will need a custom solution - the built-in flagging system requires authentication.

There is no daily limit on flags per member. A member who finds multiple pieces of problematic content can flag all of them.

## What a Flag Captures

When a flag is submitted, Jetonomy records:

- The content being flagged (post or reply, with its ID)
- The member who submitted the flag
- The reason text they entered
- The timestamp

Moderators see all of this information when they review the flag in the moderation queue.

## What Happens to Flagged Content

Flagging alone does not change the visibility of a post or reply. The content stays live and readable by all members until a moderator reviews it and takes action. This is intentional - hiding content automatically on a single flag would allow abuse of the flagging system.

A moderator can then approve the flag (confirming the content breaks the rules) and take action, or dismiss the flag (marking it unfounded). See the [Moderation Queue](03-moderation-queue.md) guide for the full review workflow.

> **Note:** If the same piece of content receives multiple flags from different members, all flags are grouped under that content item in the moderation queue. Moderators see the total flag count and each individual reason.

## Preventing Flag Abuse

Jetonomy does not currently auto-penalize members who submit flags that moderators consistently dismiss. If you have a member who is abusing the flagging system, handle it by adjusting their trust level or banning them from **Jetonomy → Users** in the WordPress admin.

## What's Next?

See how flagged content and posts pending approval appear in the moderation queue, and learn how moderators take action.

[Moderation Queue →](03-moderation-queue.md)
