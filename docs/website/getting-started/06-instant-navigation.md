---
title: "Instant Navigation"
category: "getting-started"
order: 6
---

# Instant Navigation

Moving around a Jetonomy community does not reload the page. Clicking from the home feed to a space, from a space to a member profile, or from search results to the leaderboard swaps the view in place: the surrounding layout stays put, the address bar updates to the new URL, and the browser back and forward buttons work exactly as expected. Posting a reply and casting a vote update in place too, without any page reload at all.

## What You Will See

As a member, instant navigation means the community feels responsive. Switching between areas is immediate - there is no white-flash load between pages, and the persistent navigation bar never disappears and reappears. Scroll position on the sidebar and the header persist across moves.

As a site owner, instant navigation is automatic. There is nothing to configure. It activates as soon as Jetonomy loads on the community page and works across all the views Jetonomy renders.

## Which Views Navigate in Place

The following views load via the in-place router:

- **Home feed** - the community landing page with category and space listing
- **Category pages** - a filtered view of spaces within a category
- **Space listing pages** - the topic list for any space
- **Space members** - the member roster for a space
- **Tag pages** - topics grouped by a community tag
- **User profiles** - any member's public profile page
- **Search results** - the community search view
- **Leaderboard** - the top contributors ranking
- **Notifications** (Pro) - the notification inbox
- **Messages and conversations** (Pro) - the direct messaging views

Two views always load with a full page navigation because they use rich editor scripts that require a clean initialization:

- **Single topic with replies** - the reply composer and code syntax highlighter boot on page load and do not re-initialize after an in-place swap
- **New topic form** - the topic composer has the same requirement

Every other link in the community navigates in place. External links, new-tab links, and links outside the community URL space are passed to the browser unchanged.

## What Happens When JavaScript Is Off

Instant navigation is a progressive enhancement. Every link in the community is a plain HTML anchor. If JavaScript is disabled or the router encounters an error on a particular navigation, the browser performs a normal full-page load to the destination. Members reach the correct page either way.

## How It Works

Instant navigation is built on the [WordPress Interactivity API client-side router](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/core-concepts/client-side-navigation/), the same routing engine used in the Block Editor. When a member clicks an internal community link, Jetonomy's global store intercepts the click, fetches the new view's HTML from the server, and swaps the main content region in place. The address bar is updated via the browser's History API, and focus is moved to the new content region for keyboard and screen-reader users.

After each navigation, Jetonomy fires a `jetonomy:navigated` custom DOM event so that other scripts - such as the load-more pagination and the icon picker - can re-initialize on the swapped content without needing a full page load.

Voting, replying, and other in-place mutations work through the Interactivity API store's reactive state rather than the router, so they update the visible counts and UI immediately without any navigation at all.
