When FluentCommunity is active alongside Jetonomy, the two plugins coexist as one product. Members navigate between the social feed and the forum without noticing two separate systems, and admins pair spaces in one click.

![Jetonomy admin settings showing the FluentCommunity integration tab](../images/admin-settings.png)

## What You Will Learn

- How the integration auto-detects FluentCommunity
- How to pair FluentCommunity spaces with Jetonomy spaces
- How member sync, activity broadcast, and the comment-to-reply bridge work
- How the cross-profile links and unified avatars behave
- What stays separate (private content, moderation, notifications)

## Auto-Detection

Jetonomy checks for FluentCommunity on every load via `class_exists( 'FluentCommunity\\App\\App' )`. When detected, the integration layer activates automatically. There are no toggles to flip and no bootstrap code to add.

The integration is read-only toward FluentCommunity's database: every write goes through FluentCommunity's own helpers (`addToSpace`) and the Feed model, never via direct SQL. Deactivate FluentCommunity at any time and both plugins continue to work independently.

## Pairing Spaces

Open **Jetonomy Settings > FluentCommunity**. You will see:

- A status line confirming FluentCommunity is detected
- A tab-label field (default "Discussions")
- A pairings table where each row maps one FluentCommunity space to one Jetonomy space

Click **Add pair**, pick the FluentCommunity space on the left and the Jetonomy space on the right, and save. The pairing is stored in a single WordPress option (`jetonomy_fc_space_pairs`). Unpair a row by setting the Jetonomy column to "Not paired".

Once a pair is saved, you get five connected surfaces automatically:

1. A **Discussions** tab appears on the FluentCommunity space header, linking to the paired Jetonomy space.
2. An **Also on {your community}** card appears in the Jetonomy space sidebar, linking back to the FluentCommunity feed.
3. The FluentCommunity profile gets a **Discussions** block showing the member's five most recent topics started plus the five topics they follow on Jetonomy.
4. The Jetonomy profile gets a **View on {your community}** cross-link to the member's FluentCommunity profile.
5. Member avatars unify across both sides: the FluentCommunity avatar is used everywhere on the site, including Jetonomy pages.

The tab, card, and button labels pick up the **Site Title** configured in FluentCommunity so the wording matches your community brand. The Discussions label is configurable from the settings page.

## Member Sync

When a member joins a paired FluentCommunity space, they are automatically added to the paired Jetonomy space. The reverse also holds: joining the Jetonomy space enrols the member in the FluentCommunity space.

Sync is **add-only by design**:

- Joins propagate in both directions.
- Leaves do not propagate. Removing yourself from one side never yanks you out of the other.
- Role changes do not propagate. Each plugin manages its own role structure.

Member sync is enabled by default and can be toggled off from the settings page. There is also a **Sync existing members now** button that performs a one-click backfill: every member already in one side of a pair is enrolled into the other side. Backfill is safe to re-run and capped at 5,000 members per space per run.

## Activity Broadcast

When a new topic is created in a paired Jetonomy space, an announcement feed post appears in the paired FluentCommunity space with the topic title, excerpt, and a discreet "Shared from the forum" attribution link.

Properties:

- **One-way only.** Broadcast runs from Jetonomy to FluentCommunity. FluentCommunity feed posts never silently create forum topics.
- **Private topics are never broadcast.** If a topic is marked private in Jetonomy, nothing is posted to FluentCommunity. The FC feed audience can be broader than the private-topic scope, so private content stays where it was authored.
- **Paragraph breaks preserved.** The excerpt keeps its formatting through the HTML-to-plain conversion.
- **No duplicated titles.** The feed post uses the topic title as its heading once, with the excerpt as body and a footer link back to the Jetonomy topic.

Broadcast is enabled by default and can be toggled off from the settings page.

## Comment-to-Reply Bridge

When a member comments on one of the broadcast feed posts in FluentCommunity, the comment is mirrored back as a reply on the original Jetonomy topic, preserving author attribution.

Only comments on broadcast feed posts round-trip. Native FluentCommunity feed posts (ones Jetonomy did not broadcast) are left alone. Edits and deletes on FluentCommunity do not propagate: the forum thread remains the durable record of record.

## Cross-Profile Navigation

Navigation between the two profile systems works in both directions:

- FluentCommunity profile: the Discussions block ends with a **View all on forum** link pointing to the Jetonomy profile.
- Jetonomy profile: a **View on {your community}** button points to the FluentCommunity profile.

The FluentCommunity site title is read from the `fluent_community_settings` option's `site_title` key, so the button text always matches what your FC admin has branded the community with. If you have not set one, the button falls back to your WordPress site name, then to "Community".

## Identity Keying

Everything joins on `user_id`, never on username. This matters when a user has different usernames on the two sides (for example WP user `admin` with FluentCommunity xprofile username `admin2`). `user_id` is the primary key in both `wp_fcom_xprofile` and `wp_jt_user_profiles`, so the integration stays correct no matter how usernames diverge.

## Stability Guarantees

- **No core changes** to FluentCommunity or Jetonomy. Everything lives in one integration file in the Jetonomy plugin.
- **No writes to FluentCommunity tables** outside FC's public helpers. Remove the integration and FluentCommunity is untouched.
- **Stale pair handling.** If either side's space ID does not resolve (deleted space, renamed slug), the tab and card silently disappear. No admin cleanup needed.
- **Graceful degradation.** If a FluentCommunity hook is ever renamed or removed in a future FC release, only the corresponding surface stops rendering. The rest of the integration keeps working.
- **One option row.** The pairing map is stored in a single WordPress option. Uninstall deletes one row.

## What Is Not Integrated (Yet)

Kept out of the first release on purpose:

- **Leave sync.** Destructive semantics: pulling someone off one side when they leave the other is too likely to cause surprise removals. Add-only stays the default.
- **Privacy mirroring.** Paired spaces do not share visibility settings. Exposing or hiding content across two plugins silently is a product decision, not a hook decision.
- **Profile field sync.** Bios, display names, and custom fields are not synced two-way.
- **Notification inbox merge.** Each plugin keeps its own notification stream.
- **Unified search.** FC and Jetonomy have different search surfaces; querying both simultaneously is not wired.
- **Shared moderation queue.** Different content models, different flag rules.

Open a request if one of these is the feature you need. We ship what customers actually use.

## Developer Reference

The entire integration lives in one file: `includes/integrations/class-fluent-community.php`. It loads only when `FluentCommunity\App\App` exists.

### Options

| Option | Type | Default | Purpose |
|--------|------|---------|---------|
| `jetonomy_fc_space_pairs` | array `{fc_id: jt_id}` | `[]` | Space pairing map. One row stores every pair. |
| `jetonomy_fc_tab_label` | string | `Discussions` | Label used on the FC tab, Jetonomy sidebar card, and FC profile Discussions block. |
| `jetonomy_fc_sync_members` | `'1'` / `'0'` | `'1'` | Toggle for bidirectional member sync. |
| `jetonomy_fc_broadcast` | `'1'` / `'0'` | `'1'` | Toggle for topic broadcast to the paired FC feed. |

On uninstall, both options are removed by Jetonomy's standard `jetonomy_*` sweep.

### FluentCommunity hooks consumed

| Hook | Surface |
|------|---------|
| `get_avatar_url` (core WP) | Unifies the avatar across both sides |
| `fluent_community/space_header_links` | Adds the Discussions tab on the FC space header |
| `fluent_community/activity/after_contents_user` | Adds the Discussions block on the FC profile |
| `fluent_community/space/joined` | Triggers member sync FC to Jetonomy |
| `fluent_community/comment_added` | Triggers the comment-to-reply bridge |

### Jetonomy hooks consumed

| Hook | Surface |
|------|---------|
| `jetonomy_admin_settings_tabs` + `jetonomy_admin_settings_tab_content` | Registers the FluentCommunity settings tab |
| `jetonomy_sidebar_after_about` | Renders the "Also on {community}" sidebar card |
| `jetonomy_profile_after_stats` | Renders the cross-link to the member's FC profile |
| `jetonomy_user_joined_space` | Triggers member sync Jetonomy to FC |
| `jetonomy_after_create_post` | Triggers the broadcast to the paired FC feed |

### Identity helpers

- `fc_username_for_user( int $user_id ): ?string`: maps a WP user ID to the FC xprofile username.
- `fc_site_title(): string`: returns the FC-configured community name (falls back to WP site name, then `Community`).

### Loop protection

Member sync and broadcast use a static `$syncing` flag so a join on one side never triggers a boomerang join back. Broadcast feed rows are tagged with meta so only those feeds round-trip their comments as replies; native FC feeds are left alone.
