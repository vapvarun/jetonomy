---
title: "Welcome Banner"
category: "getting-started"
order: 7
---

# Welcome Banner

A first-time visitor who lands on your community home page sees a welcome banner before anything else. It introduces the community with a short heading and a sentence of context, shows live activity numbers, and gives them two clear choices: create a free account or log in. Logged-in members never see it.

## What the Banner Contains

The banner has three parts:

**Heading** - A short introductory line. The default reads "Welcome to [community title]", where the title comes from your community name in settings.

**Subheading** - One sentence of context. If you have set a community tagline under **Jetonomy → Settings → General**, it appears here. If you have not set a tagline, the default reads: "Ask questions, share what you build, and join the discussion. Create a free account to post, vote, and follow the spaces you care about."

**Community pulse** - Three live numbers drawn from your community data: total member count, total post count, and posts published this week. The "this week" figure only appears when it is greater than zero, so a brand-new community with no recent activity shows only the member and post counts. The pulse numbers are cached for one hour so the query does not run on every page view.

**Call to action** - Two buttons below the pulse. "Create free account" links to the WordPress registration page. "Log in" links to the login page with a redirect back to the community home after sign-in.

## Who Sees the Banner

Only signed-out visitors see the banner. Logged-in members go straight to the space and category listing without an intro screen. If registration is disabled on your site, the "Create free account" button links to a WordPress registration page that will itself tell visitors registration is closed - the banner does not check this itself.

## Customise

The heading and subheading are both filterable. Add the following to your theme's `functions.php` or a site-specific plugin to override them:

```php
// Override the welcome heading.
add_filter( 'jetonomy_home_welcome_heading', function( $heading ) {
    return 'Join the conversation';
} );

// Override the welcome subheading.
add_filter( 'jetonomy_home_welcome_subheading', function( $sub ) {
    return 'Ask questions, share ideas, and connect with others.';
} );
```

Both filters receive the current string as their only argument and expect a plain-text string in return. HTML is escaped on output, so do not pass markup.

The banner's visual style follows the same `--jt-*` design tokens as the rest of the community, so it automatically picks up your theme's brand color, typography, and border radius with no extra CSS.
