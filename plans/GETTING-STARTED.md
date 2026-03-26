# Getting Started with Jetonomy

Welcome! You're about to have a real community running on your WordPress site. This guide walks you through everything — from installation to your first active members — in about five minutes.

---

## Step 1: Install and Activate

1. Go to **Plugins → Add New Plugin** in your WordPress admin.
2. Search for **Jetonomy** and click **Install Now**, then **Activate**.
3. Or upload the plugin zip manually: **Plugins → Add New Plugin → Upload Plugin**.

Once activated, you'll see a blue notice at the top of your dashboard:

> **Your community is almost ready.** Run the setup wizard to get started.

Click that notice (or go to **Jetonomy → Dashboard**) to open the setup wizard.

---

## Step 2: Run the Setup Wizard

The setup wizard asks you three questions and gets out of your way.

**Question 1: What's your community URL slug?**

By default your community lives at `yoursite.com/community/`. You can change `community` to anything you like — `forum`, `hub`, `members`, whatever fits your site.

**Question 2: Custom setup or demo data?**

You have two paths here:

**Path A — Custom Setup (recommended for production sites)**
- Give your first space a name and pick its type (Forum, Q&A, Ideas, or Social).
- Choose a join policy: open (anyone can join), request-to-join, or invite-only.
- Click **Create My Community** and you're done.

**Path B — Load Demo Data (great for exploring features)**
- Jetonomy creates several realistic spaces with sample posts, replies, and users.
- Explore all four community types and see how voting, trust levels, and Q&A work before committing your real content.
- When you're ready to go live, click **Remove Demo Data** in the setup wizard to wipe everything clean in one click.

**Question 3: Email notifications**

Choose whether members get email notifications for replies and mentions. You can change this anytime in Settings.

Click **Finish Setup** and you're done. Your community is live.

---

## Step 3: Create Your First Post

Head to your community at `yoursite.com/community/`. You'll see your space listed on the home page.

1. Click into your space.
2. Click the **New Post** button in the top right.
3. Give your post a title and write some content.
4. Add tags if you like (type a tag name and press Enter).
5. Click **Post Topic**.

That's it. Your first post is live and indexed for search.

**A few things to know about the editor:**
- Paste an image directly from your clipboard and it uploads automatically.
- Paste a YouTube or Twitter URL on its own line and it embeds automatically.
- Type `@` followed by a username to mention someone — they'll get a notification.
- The emoji picker is the smiley face icon in the toolbar.

---

## Step 4: Invite Members

You want people in your community. Here are three ways to bring them in.

**Option A — Share the URL**
If your space is set to "open join policy," anyone can visit `yoursite.com/community/s/your-space/` and join by clicking **Join Space**.

**Option B — Generate an invite link**
1. Go to your space and click **Members** in the space navigation.
2. Click **Generate Invite Link**.
3. Set an expiry date (or leave it open).
4. Copy the link and share it anywhere — email, Slack, social media.

When someone visits the invite link, they're added to the space immediately after logging in or registering.

**Option C — Existing WordPress users**
Anyone who already has a WordPress account on your site can join spaces directly. Their display name, avatar, and email are pulled from their existing account.

---

## Step 5: Configure Settings

Go to **Jetonomy → Settings** to tune the plugin to your needs. There are five tabs:

**General**
Set your community base slug, default language, and whether guests can read content without logging in.

**Spaces**
Choose the default join policy for new spaces and configure what space owners can change.

**Trust Levels**
Configure the post count, days active, and reputation thresholds that unlock Levels 1, 2, and 3 automatically. Levels 4 and 5 are always granted manually.

**Notifications**
Configure default notification preferences for new members and set which events send email.

**Advanced**
Flush permalink rules, configure caching behavior, and manage the REST API nonce lifetime.

---

## Step 6: Set Up Moderation

Your community is only as good as its moderation. Jetonomy gives you a few tools to keep things healthy from day one.

**Akismet** — If you have Akismet active (you probably do), it's already checking every post and reply for spam automatically.

**Trust Levels** — New users start at Level 0 with basic access. They can't flood your community immediately. As they participate honestly, they earn more abilities.

**The Moderation Queue** — When members flag a post or reply, it lands in your moderation queue. You can access it at **Jetonomy → Moderation** in wp-admin, or at `yoursite.com/community/mod/` if you're logged in as an admin.

**Banning** — If someone is causing problems, go to **Jetonomy → Users**, find the user, and click **Ban** or **Silence**. Banned users can't log in. Silenced users can log in and read, but can't post.

---

## Congratulations — Your Community Is Live!

You've gone from zero to a fully functional community platform. Here's what to explore next:

**Add more spaces** — Go to **Jetonomy → Spaces** and click **Add Space**. Try different community types (Q&A, Ideas) to see which fits your audience.

**Customize the look** — Create a `jetonomy/` folder in your active theme directory. Drop in any template file from `wp-content/plugins/jetonomy/templates/` to override it. The plugin inherits your theme's fonts and colors automatically via `theme.json`.

**Import an existing community** — If you're migrating from bbPress, wpForo, or Asgaros, go to **Jetonomy → Import**. Select your source and click **Start Import**. Imports run in batches — you can close the browser and come back.

**Set up membership gating** — If you have MemberPress or Paid Memberships Pro installed, go to any space's settings and you'll see a **Membership Access** tab where you can restrict the space to specific plans.

**Explore Pro features** — Private messaging, polls, emoji reactions, real-time updates, analytics, and Slack/Discord integration are all available in [Jetonomy Pro](https://jetonomy.com/pro).

**Get help** — [Documentation](https://jetonomy.com/docs) | [Support forums](https://jetonomy.com/support) | [hello@jetonomy.com](mailto:hello@jetonomy.com)
