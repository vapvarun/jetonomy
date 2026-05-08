The General settings tab is the first place to go after installation. It controls your community URL, pagination defaults, and who can read or participate.

## What You Will Learn

- How to change your community's base URL slug
- What the default space type setting controls
- How to configure pagination for posts and replies
- How guest access and login requirements work

![General settings](../images/admin-general.png)

Go to **Jetonomy → Settings** to access these options. All changes take effect on save.

## Community Base URL

**Setting:** `base_slug`
**Default:** `community`
**Location:** General tab → Community URL section

This is the URL prefix for all Jetonomy pages on your site. With the default value, your community home is at `yoursite.com/community/`. Spaces live at `yoursite.com/community/s/space-name/`, and so on.

You can change this to any URL-safe string, for example `forum`, `hub`, or `discuss`. Jetonomy automatically flushes rewrite rules when you change the base URL and save settings.

> **Warning:** Changing the base URL after your community has content will break all existing links. If you must change it on a live site, set up 301 redirects from the old slug to the new one.

## Default Space Type

**Setting:** `default_space_type`
**Default:** `forum`
**Options:** Forum, Q&A, Ideas, Show & Tell

When you create a new space in the admin, this setting pre-fills the **Type** dropdown. It is a convenience setting only - you can always change the type on any individual space. It does not affect existing spaces.

Choose the type that best matches the primary purpose of your community:

- **Forum** - open discussion, replies sorted by date
- **Q&A** - questions and answers, accepted answers float to top
- **Ideas** - feature requests and votes, status workflow
- **Show & Tell** - short-form cards, optional title, chronological feed

## Posts Per Page

**Setting:** `posts_per_page`
**Default:** `20`
**Location:** General tab → Pagination section

Controls how many posts appear per page in space listings and search results. A lower number is faster on large communities; a higher number reduces clicks for users who prefer scrolling. This value also controls how many additional posts load each time a member clicks **Load More** on a space listing.

> **Tip:** For communities with 10,000+ posts per space, keep this at 20 or lower. Higher values increase page load time and database query time proportionally.

## Replies Per Page

**Setting:** `replies_per_page`
**Default:** `20`
**Location:** General tab → Pagination section

Controls how many replies load per page inside a single post view. This value also controls how many additional replies load each time a member clicks **Load More** in a thread. Pagination starts at the oldest replies and works forward. Members can jump to the last page to see the most recent replies.

## Guest Access

**Setting:** `guest_read`
**Default:** `true` (on)
**Location:** General tab → Access section

When enabled, logged-out visitors can read all public spaces and posts without signing in. They see a prompt to log in when they try to vote, reply, or follow a space.

Turn this off if your community is members-only and you do not want any content visible to search engines or unregistered visitors.

## Require Login to Participate

**Setting:** `require_login`
**Default:** `true` (on)
**Location:** General tab → Access section

When enabled, any action that writes data (posting, replying, voting, following) requires the user to be logged in. Guests are redirected to the WordPress login page.

This is always recommended on. Disable it only if you are running a very specific open-participation setup where anonymous contributions make sense.

> **Note:** Even with guest access enabled, anonymous posting is not supported. "Guest access" means read-only browsing for logged-out visitors.

## Settings Save Confirmations

After you click **Save Changes**, a confirmation banner appears at the top of the settings page. The banner stays visible until you dismiss it - it does not disappear automatically. This ensures you always have a clear signal that your changes were saved.

## What's Next?

Configure trust level thresholds and rate limits to control who can do what in your community.

[Permission Settings →](02-permissions.md)
