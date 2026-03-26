After you activate Jetonomy, a three-step wizard walks you through the only decisions you need to make before your community goes live. The whole process takes about two minutes.

## What You Will Learn

- How to set your community URL slug
- How to choose between creating your first space manually or loading demo data
- What the wizard does — and what you can always change later

## Opening the Wizard

Click the blue notice at the top of your WordPress dashboard, or go to **Jetonomy → Dashboard** and click **Launch Setup Wizard**.

The wizard runs in a full-screen overlay. You can close it at any time — your progress is saved, and you can return to finish it later.

## Step 1: Community URL

Choose the slug where your community will live on your site.

The default is `community`, which gives you `yoursite.com/community/`. You can change this to anything that fits your site — `forum`, `hub`, `members`, `discuss`, or your brand name.

| Example slug | Resulting URL |
|---|---|
| `community` | `yoursite.com/community/` |
| `forum` | `yoursite.com/forum/` |
| `hub` | `yoursite.com/hub/` |

**Default space type:** Also on this screen, choose the default type for new spaces you create. Your options are:

- **Forum** — open-ended threaded discussion
- **Q&A** — questions with votable answers; the best answer can be marked accepted

You can create spaces of any type regardless of what you choose here. This setting just controls the default when you click "Add Space" later.

> **Tip:** You can change your community URL slug later in **Jetonomy → Settings → General**. Jetonomy automatically flushes permalink rules when you save.

## Step 2: First Space

This step gets real content into your community so it is ready to share the moment you finish. You have two paths — choose the one that fits where you are right now.

### Path A: Create Your First Space

Choose this if you are setting up a production site and want to start with your own content.

1. Enter a name for your first space (e.g., "General Discussion").
2. Choose its type: **Forum**, **Q&A**, or **Ideas**.
3. Set a join policy: **Open** (anyone can join), **Request to join** (members need approval), or **Invite only**.
4. Click **Create Space**.

Your space is created and visible immediately after you finish the wizard.

### Path B: Load Sample Data

Choose this if you want to explore Jetonomy's features before committing to a structure.

Jetonomy seeds your site with:

- **2 categories** — "Product Support" and "Community"
- **5 spaces** — one of each type (Forum, Q&A, Ideas) plus two extras with varied content
- **Demo users** with realistic avatars, trust level badges, and posting history
- **Sample posts and replies** — enough content to see voting, accepted answers, tags, and notifications working in context

This lets you experience the full community interface as a regular member would see it, without writing any content yourself.

When you are ready to go live, click **Remove Demo Data** on the Jetonomy dashboard. Every demo post, reply, space, category, and user record is deleted in a single operation. Your real content — if you have added any alongside the demo data — is preserved.

> **Note:** Demo data is tracked internally via a `jetonomy_demo_data` record. Removal is precise and does not affect any content you created yourself.

## Step 3: Done

The final screen confirms your community is live and gives you two quick links:

- **Visit your community** — opens `yoursite.com/community/` in a new tab so you can see the frontend immediately.
- **Go to admin dashboard** — takes you to **Jetonomy → Dashboard** where you can manage spaces, moderate content, and configure settings.

Everything you configured in the wizard can be changed later:

| Setting | Where to change it |
|---|---|
| Community URL slug | Jetonomy → Settings → General |
| Default space type | Jetonomy → Settings → Spaces |
| Space name, type, join policy | Jetonomy → Spaces → Edit |
| Email notifications | Jetonomy → Settings → Notifications |

## What's Next?

Your community is live. Now learn what to do in the first hour — create categories, set up your real spaces, and invite your first members.

[Your First Community →](03-first-community.md)
