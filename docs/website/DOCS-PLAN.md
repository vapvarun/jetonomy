# Jetonomy Documentation Plan

**Total: 13 categories, 42 docs**
**Image format:** `![Alt text](../images/{filename}.png)` — relative paths for GitHub rendering
**Screenshots:** Taken from model site at `http://forums.local/` (Jetonomy Demo + BuddyX theme)
**PRO badge:** Docs covering Pro features start with `> **PRO** — This feature requires Jetonomy Pro.`

---

## Screenshot List (take before writing)

Each doc needs 1-3 screenshots. Total ~60 screenshots.

### Getting Started (3 docs, 6 images)
| Doc | Screenshots needed |
|-----|--------------------|
| installation.md | `install-upload.png` (WP plugin upload), `install-activated.png` (plugin list) |
| setup-wizard.md | `wizard-step1.png` (basics), `wizard-step2.png` (first space), `wizard-step3.png` (done) |
| first-community.md | `community-home.png` (full community home page) |

### Spaces & Categories (4 docs, 8 images)
| Doc | Screenshots needed |
|-----|--------------------|
| creating-spaces.md | `admin-spaces-list.png`, `admin-space-edit.png` |
| space-types.md | `space-forum.png` (forum listing), `space-qa.png` (Q&A with accepted), `space-ideas.png` (ideas with status) |
| membership-policies.md | `space-private.png` (join request), `space-members.png` (member list) |
| space-settings.md | `admin-space-settings.png` |

### Discussions & Topics (6 docs, 12 images)
| Doc | Screenshots needed |
|-----|--------------------|
| creating-topics.md | `new-post-form.png`, `post-created.png` |
| replies-threading.md | `reply-thread.png` (threaded replies), `reply-accepted.png` (Q&A accepted) |
| voting.md | `voting-topic.png` (vote arrows + score), `voting-popular.png` (popular sort) |
| bookmarks-following.md | `bookmark-icon.png`, `space-follow.png` |
| drafts-scheduling.md | `draft-button.png` (split button), `schedule-picker.png` (datetime), `profile-drafts.png` |
| topic-management.md | `move-modal.png`, `merge-modal.png`, `split-modal.png` |

### Search & Discovery (2 docs, 4 images)
| Doc | Screenshots needed |
|-----|--------------------|
| search-filters.md | `search-results.png`, `search-filters.png` (filter bar expanded) |
| tags.md | `tags-sidebar.png`, `tag-page.png` |

### Moderation & Trust (4 docs, 8 images)
| Doc | Screenshots needed |
|-----|--------------------|
| trust-levels.md | `trust-badges.png` (TL badges on replies), `admin-trust-settings.png` |
| flagging-reporting.md | `flag-button.png`, `flag-modal.png` |
| moderation-queue.md | `admin-moderation.png` (queue), `mod-actions.png` (approve/spam/trash) |
| anti-spam.md | `admin-antispam.png` (settings tab), `captcha-invisible.png` |

### Notifications & Email (2 docs, 3 images)
| Doc | Screenshots needed |
|-----|--------------------|
| notifications.md | `notif-dropdown.png`, `notif-page.png` |
| email-settings.md | `admin-email.png` |

### User Profiles & Leaderboard (3 docs, 4 images)
| Doc | Screenshots needed |
|-----|--------------------|
| profiles.md | `profile-page.png`, `profile-edit.png` |
| leaderboard.md | `leaderboard.png` |
| online-status.md | `online-dot.png` (green dot on avatar) |

### Pro Features (12 docs, 18 images) — ALL labeled PRO
| Doc | Screenshots needed |
|-----|--------------------|
| reactions.md | `reactions-picker.png`, `reactions-summary.png` |
| private-messaging.md | `messages-list.png`, `message-thread.png` |
| polls.md | `poll-create.png`, `poll-results.png` |
| custom-fields.md | `admin-fields.png`, `profile-fields.png` |
| custom-badges.md | `admin-badges.png`, `badge-earned.png` |
| analytics.md | `admin-analytics.png` |
| advanced-moderation.md | `admin-mod-rules.png` |
| email-digest.md | `digest-settings.png` |
| webhooks.md | `admin-webhooks.png` |
| web-push.md | `push-settings.png` |
| reply-by-email.md | `reply-email-settings.png` |
| white-label.md | `admin-branding.png` |

### Integrations (7 docs, 7 images)
| Doc | Screenshots needed |
|-----|--------------------|
| memberpress.md | `integration-memberpress.png` |
| pmpro.md | `integration-pmpro.png` |
| woocommerce.md | `integration-woo.png` (PRO) |
| learndash.md | `integration-learndash.png` (PRO) |
| rcp.md | `integration-rcp.png` (PRO) |
| buddynext.md | `integration-buddynext.png` |
| theme-compatibility.md | `theme-buddyx.png` |

### Admin Settings (6 docs, 6 images)
| Doc | Screenshots needed |
|-----|--------------------|
| general.md | `admin-general.png` |
| permissions.md | `admin-permissions.png` |
| email.md | `admin-email-tab.png` |
| appearance.md | `admin-appearance.png` |
| seo.md | `admin-seo.png` |
| anti-spam.md | `admin-antispam-tab.png` |

### Migration (2 docs, 3 images)
| Doc | Screenshots needed |
|-----|--------------------|
| bbpress-import.md | `import-bbpress.png`, `import-progress.png` |
| wpforo-import.md | `import-wpforo.png` |

### Why Jetonomy (3 docs, 0 images — text comparison)
| Doc | Screenshots needed |
|-----|--------------------|
| vs-bbpress.md | Feature comparison table |
| vs-wpforo.md | Feature comparison table |
| scalability.md | Architecture diagram (optional) |

### Developer Guide (5 docs, 0 images — code-focused)
| Doc | Screenshots needed |
|-----|--------------------|
| rest-api.md | Full endpoint reference (48 free + 39 pro routes) |
| hooks-reference.md | All 53 free + 20 pro hooks with params |
| template-overrides.md | Override directory structure |
| shortcodes-widgets-blocks.md | 5 shortcodes, 4 widgets, 3 blocks |
| adapters.md | Adapter interfaces + how to extend |

---

## Doc Template

```markdown
One-line description of what this page covers.

In this guide, you will learn:
- Bullet point 1
- Bullet point 2
- Bullet point 3

## Section Title

Short paragraph (2-3 sentences max).

![Screenshot description](../images/screenshot-name.png)

> **Tip:** Helpful note or best practice.

## Another Section

Content here.

## What's Next?

- [Next logical doc title](../next-category/next-doc.md)
- [Related doc](../related-category/related-doc.md)
```

## Pro Badge Pattern

```markdown
> **PRO** — This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

Rest of content...
```

---

## Feature Coverage Checklist

### Free Features
- [ ] Spaces (4 types: forum, Q&A, ideas, feed)
- [ ] Categories (grouping spaces)
- [ ] Topics (create, edit, delete)
- [ ] Replies (threaded, 3 levels)
- [ ] Voting (upvote/downvote, reputation)
- [ ] Bookmarks
- [ ] Following/Subscriptions
- [ ] Draft posts
- [ ] Scheduled posts
- [ ] Full-text search with filters (date, author, tag, sort)
- [ ] Tags
- [ ] Trust levels (0-5, auto-promotion)
- [ ] Flagging/Reporting
- [ ] Moderation queue
- [ ] Topic move between spaces
- [ ] Topic merge
- [ ] Reply split to new topic
- [ ] Pin/Close topics
- [ ] Anti-spam (reCAPTCHA v3 / Turnstile)
- [ ] Notifications (in-app + email)
- [ ] User profiles (posts, replies, votes tabs)
- [ ] Online status (green dot)
- [ ] Leaderboard
- [ ] Notification preferences
- [ ] Akismet integration
- [ ] bbPress importer
- [ ] wpForo importer
- [ ] MemberPress adapter
- [ ] Paid Memberships Pro adapter
- [ ] Shortcodes (5)
- [ ] Widgets (4)
- [ ] Gutenberg blocks (3)
- [ ] REST API (48 endpoints)
- [ ] 53 action/filter hooks
- [ ] Template overrides
- [ ] Theme.json design token bridge
- [ ] SEO (sitemap, schema markup, meta tags)
- [ ] Privacy (data export/erasure)
- [ ] WP-CLI commands

### Pro Features (each needs PRO label)
- [ ] Emoji Reactions (Fluent 3D emoji, single-reaction per user)
- [ ] Private Messaging (conversations, real-time)
- [ ] Polls (create, vote, multi-option)
- [ ] Custom Profile Fields (text, textarea, select, checkbox, URL)
- [ ] Custom Badges (auto-award on conditions)
- [ ] Analytics Dashboard (overview, top spaces, contributors, engagement, export)
- [ ] Advanced Moderation (auto-rules, banned words, rate limit rules)
- [ ] Email Digest (daily/weekly summary)
- [ ] Webhooks (outbound, event-driven)
- [ ] Web Push Notifications (browser push via VAPID)
- [ ] Reply by Email (inbound email → reply)
- [ ] White Label / Branding (remove Jetonomy branding)
- [ ] SEO Pro (per-space SEO settings)
- [ ] WooCommerce Membership adapter
- [ ] Restrict Content Pro adapter
- [ ] LearnDash adapter

---

## Execution Order

1. **Take all screenshots** from model site (60+ images)
2. **Write Getting Started** (3 docs) — first impression
3. **Write Spaces + Discussions** (10 docs) — core features
4. **Write Search + Moderation + Notifications + Profiles** (11 docs)
5. **Write Pro Features** (12 docs) — upsell content
6. **Write Integrations + Admin + Migration** (15 docs)
7. **Write Why Jetonomy** (3 docs) — comparison/marketing
8. **Write Developer Guide** (5 docs) — separate audience
9. **Review all docs** for consistency
10. **Verify on GitHub** — images render, links work
