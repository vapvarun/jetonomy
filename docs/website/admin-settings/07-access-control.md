The Access Control setting decides whether your community is open to the public or hidden behind sign-in. Pick the right mode in one place and Jetonomy enforces it across every page and the REST API.

## What You Will Learn

- The difference between Public and Private community modes
- Which pages stay reachable in Private mode (sign-in, register, lost password)
- How REST API access changes between the two modes
- When to switch and what to expect

Go to **Jetonomy → Settings → General → Access Control** to choose the mode.

## Public Mode (default)

Anyone - including search engines and visitors who haven't signed in - can read posts, replies, and member profiles. Posting and voting still require sign-in.

This is the default for every community and is unchanged from prior versions. Existing communities continue working without any setting change after upgrading to 1.4.1.

Use Public mode when:

- You want search engine traffic to find your community
- You're running a customer support forum or open knowledge base
- New visitors should be able to browse before signing up

## Private Mode

Every community page requires sign-in. Guests visiting `/community/` or any space, post, tag, or profile URL are redirected to the sign-in page. The REST API also rejects unauthenticated requests for community data.

The sign-in, register, and forgot-password pages stay reachable so guests can still create an account or recover access.

Use Private mode when:

- The community is for paying members only
- Discussions are confidential (internal team, private group, paid coaching)
- You don't want search engines to index any community content

## What Stays Public in Private Mode

These pages are intentionally exempt so guests can sign up and recover access:

- The sign-in page
- The registration page
- The forgot-password page
- Email verification and password-reset confirmation links

Everything else - homepage, spaces, posts, replies, tags, member profiles, leaderboard, search - is gated.

## REST API Behaviour

Public mode: read endpoints return data to anyone. Write endpoints still require auth.

Private mode: every endpoint under `/wp-json/jetonomy/v1/` requires an authenticated request. Anonymous calls return `401 Unauthorized`. This is checked centrally - third-party clients calling the API see the same gate as the website does.

## Switching Modes

Switching is instant and applies to the next page load. You can change the mode at any time:

- Public → Private: existing public links become sign-in redirects. Search engines will eventually drop your indexed pages.
- Private → Public: pages become reachable again. Submit your sitemap to search engines if you want re-indexing.

There's no migration step and no downtime.
