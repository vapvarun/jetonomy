# Access Control

The Access Control setting decides whether your community is open to the public or hidden behind sign-in. Pick the right mode in one place and Jetonomy enforces it across every page and the REST API.

## What You Will Learn

- The difference between Public and Private community modes
- Why sign-in, register, and lost password stay reachable in Private mode
- How REST API access changes between the two modes
- When to switch and what to expect

Go to **Jetonomy → Settings → General** and find the **Access Control** card. Under **Community Access**, choose **Public community** or **Private community**. It is one card on the General tab, not a separate sub-page - the same `guest_read` toggle introduced under [Guest Access on the General Settings page](01-general.md#guest-access-public--private).

## Public Mode (default)

This is the **Public community** option: "Anyone can read topics and replies. Visitors must log in to post, reply, or vote."

Anyone - including search engines and visitors who haven't signed in - can read posts, replies, and member profiles. Posting and voting still require sign-in.

This is the default for every community and is unchanged from prior versions. Existing communities continue working without any setting change after upgrading to 1.4.1.

Use Public mode when:

- You want search engine traffic to find your community
- You're running a customer support forum or open knowledge base
- New visitors should be able to browse before signing up

## Private Mode

This is the **Private community** option: "Only logged-in members can view any forum content. Everyone else is redirected to the login page."

Every community page requires sign-in. Guests visiting `/community/` or any space, post, tag, or profile URL are redirected to the WordPress login page. The REST API also rejects unauthenticated requests for community data.

Jetonomy has no sign-in, register, or lost-password pages of its own. Those are WordPress's own pages, which sit outside your community, so Private mode never blocks them. Whether guests can create an account depends on the **Anyone can register** setting under **Settings → General** in WordPress.

Use Private mode when:

- The community is for paying members only
- Discussions are confidential (internal team, private group, paid coaching)
- You don't want search engines to index any community content

## What Stays Public in Private Mode

The WordPress sign-in, registration, and forgot-password pages are not part of the community, so guests can always reach them to sign up and recover access.

Everything else - homepage, spaces, posts, replies, tags, member profiles, leaderboard, search - is gated.

## REST API Behaviour

Public mode: read endpoints return data to anyone. Write endpoints still require auth.

Private mode: every endpoint under `/wp-json/jetonomy/v1/` requires an authenticated request. Anonymous calls return `401 Unauthorized`. This is checked centrally - third-party clients calling the API see the same gate as the website does.

## Switching Modes

Switching is instant and applies to the next page load. You can change the mode at any time:

- Public → Private: existing public links become sign-in redirects. Search engines will eventually drop your indexed pages.
- Private → Public: pages become reachable again. Submit your sitemap to search engines if you want re-indexing.

There's no migration step and no downtime.
