How Jetonomy compares to Asgaros Forum - moving up from a lightweight forum to a full discussion platform.

![Jetonomy community home page with modern spaces, voting, and trust-level badges](../images/community-home.png)

## What You Will Learn

- Key differences between Jetonomy and Asgaros Forum
- Where Jetonomy excels for growing communities
- What Asgaros Forum still does well

> **Try a live Jetonomy community before you commit** - Wbcom runs its own public support community on Jetonomy at [community.wbcomdesigns.com](https://community.wbcomdesigns.com/). Browse spaces, read real support threads, and get a feel for the voting, trust-level badges, reply threading, and moderation flow on a production site.

## Feature Comparison

| Feature | Asgaros Forum | Jetonomy |
|---------|---------------|----------|
| Data storage | Custom tables | Custom tables (20 tables) |
| Forum formats | Forums + sub-forums | 4 space types (forum, Q&A, ideas, feed) |
| Threaded replies | Flat | 3-level threading |
| Voting | Not built-in | Built-in upvote/downvote with reputation |
| Q&A with accepted answers | Not available | Built-in (per-space type) |
| Idea boards | Not available | Built-in with status workflow |
| Trust levels | Not available | 6-level auto-promotion system (0-5) |
| Search | Built-in search | FULLTEXT index with advanced filters |
| Real-time interactions | Page reload required | Real-time updates with no page reload |
| Moderation | Approve / unapprove topics | Flag system + queue + auto-rules (Pro) |
| Anti-spam | reCAPTCHA / honeypot | Akismet + reCAPTCHA v3 + Turnstile (invisible) |
| Membership gating | Not built-in | Adapter system (MemberPress, PMPro free; WooCommerce, LMS Pro) |
| REST API | Limited | 68+ endpoints (127+ with Pro) |
| Private messaging | Not built-in | Built-in (Pro) |
| Polls | Not built-in | Built-in (Pro) |
| Analytics | Basic stats | Dashboard with export (Pro) |
| Migration | N/A | Built-in Asgaros importer |

## Where Jetonomy Excels

### More Than a Forum

Asgaros Forum is a focused, traditional forum: categories, forums, sub-forums, topics, and replies. Jetonomy gives you that same forum format plus three more - Stack Overflow style Q&A with accepted answers, idea boards with a Planned / In Progress / Shipped / Declined workflow, and a social activity feed - all in the same community with one set of members.

### Self-Moderating Community

Asgaros relies on you (or assigned moderators) to approve content and watch for problems. Jetonomy's trust level system automatically promotes members as they contribute. New users start restricted; active, helpful members gradually earn the ability to edit, moderate, and manage - without any manual role changes. You set the thresholds once and the community moderates itself.

![A trust-level badge shown next to a member's name on a reply](../images/why-jetonomy/trust-level-badge.png)

### Voting and Reputation

Asgaros has no built-in voting. Jetonomy gives every topic and reply upvote/downvote controls, ranks replies by score, and turns those votes into a reputation score that drives the leaderboard and trust-level promotions.

### Performance at Scale

Both plugins use their own database tables instead of WordPress posts, so both avoid the bloat that slows down CPT-based forums. Jetonomy goes further with cursor-based pagination, smart reply loading on long threads, and denormalized counters, so a space with tens of thousands of topics loads as fast as a small one. See the [Scalability](03-scalability.md) page for the details.

## Where Asgaros Forum Still Works

Asgaros Forum is free, lightweight, and genuinely simple to set up. If you want a basic, traditional forum with no membership gating, no Q&A or idea boards, and a small-to-medium community, Asgaros is a solid, no-cost choice maintained by a dedicated developer.

If you are already running Asgaros and want the additional formats, voting, trust levels, and scalability, Jetonomy includes a built-in Asgaros importer that brings over your forums, sub-forums, topics, and replies.

## What's Next?

- [Importing from Asgaros Forum](../migration/03-asgaros-import.md) - step-by-step migration guide
- [Scalability](03-scalability.md) - how Jetonomy handles large communities
