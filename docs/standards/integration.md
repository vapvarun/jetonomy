# The Integration Standard (portfolio-wide)

**Applies to every plugin that shows another of our plugins' data**: BuddyNext over Jetonomy,
WPMediaVerse, WB Gamification, Career Board, Learnomy, Eventonomy, Listora, Member Blog, and any
future pair. The companion to `free-pro-seam-standard.md`: that one governs a base and its
extension (vertical); this one governs two products that meet (horizontal).

Each plugin keeps a synced copy at `docs/standards/integration.md`. Do not re-explain per plugin.
Change this file first, then re-sync the copies.

---

## The one-line thesis

**Every rule, sentence and permission lives in exactly one plugin: the one that owns the data.
The other plugin renders it and calls it. Anything written twice will drift, and the drift is
the bug.**

---

## 1. Ownership: who writes what

| | The data plugin (Jetonomy, MediaVerse, ...) | The application layer (BuddyNext) |
|---|---|---|
| Rules (who may see, act, message) | Owns them, exposes them as a function or filter | Calls them. Never re-implements |
| Words (notification text, labels) | Writes and translates them, plain text | Displays them |
| Links | Builds them | Uses them |
| Email for its features | Sends it | Never sends it |
| Screens, bell, push, feed cards | Stays out when the application layer is active | Owns them |

When BuddyNext is the screen for a feature (MediaVerse follows, comments, favorites, DMs), the
data plugin is told to stand down for that feature (for example `mvs_should_send_notification`).
One feature, one owner, decided and written down. Never both.

## 2. No duplicate code: the rules

1. **Manifest first.** Before adding a function, hook, route or helper, read `audit/manifest.json`
   and the plugin's CLAUDE.md in BOTH plugins. If it exists, call it.
2. **Missing door = add it to the owner.** If the application layer needs a rule the data plugin
   has no public door for, add the door to the data plugin (PR on its dev branch). Never copy the
   logic across, not even "for now".
3. **One helper per plugin family.** Shared logic lives in the free plugin; Pro calls it. A second
   copy in Pro is a defect (see `free-pro-seam-standard.md`).
4. **No parallel paths.** When a new path replaces an old one, the old one is deleted in the same
   release. A switch that keeps both alive ("stand down when adopted") is allowed only while the
   two sides ship in different releases, and it carries a comment naming the release that removes
   it. We have no legacy install base (BuddyNext is new), so the default is lockstep: both sides
   in one release, no switch.
5. **Protocol, not code, crosses the boundary.** Plugins agree on hook names, payload shapes and
   filter signatures (below). They never include each other's classes for logic, never write each
   other's tables, and guard every cross-plugin call with `function_exists` / `class_exists`.
6. **No dead code.** A guard that makes a branch unreachable removes the branch.

## 3. Bridge rules (application layer)

1. **State-driven sync.** One sync per partner object that reads the partner row's CURRENT state
   on every transition (create, approve, restore, trash, edit, purge).
2. **Withdraw, don't delete.** Reversible removal hides the card; only a permanent purge deletes;
   restore brings back the same card.
3. **One notification per action**, from the source event, never as a side effect of a mirror.
   Mirror writes never notify.
4. **Namespaced object types** for partner rows in shared tables (`jetonomy_post`, `mvs_media`),
   never a bare `post` holding a partner id.
5. **Partner visibility.** The partner answers who may see its rows and cards (see 4.3).
6. **Plain text** from partners into plain-text surfaces. No partner HTML.
7. **Background work in the job.** Deferred work (card creation, carry-over, email) runs inside
   the Action Scheduler job, not the member's request.

## 4. Notification contract

One inbox, like Facebook. The data plugin decides when to notify and writes the words; BuddyNext
shows it (bell, grouping, push, a settings switch per type); the data plugin emails.

### 4.1 Payload, as the LAST argument of the plugin's existing hook

| Plugin | Hook (unchanged) | Prefix | Source slug |
|---|---|---|---|
| Jetonomy | `jetonomy_notification_created` | `jetonomy` | `jetonomy` |
| WP Career Board | `wcb_notification_created` | `wcb` | `career_board` |
| Learnomy | `learnomy_send_notification` | `learnomy` | `learnomy` |
| Eventonomy | `evnm_notification_dispatch` | `evnm` | `eventonomy` |
| WPMediaVerse | `mvs_notification_created` | `mvs` | `mediaverse` |
| WB Gamification | `wb_gam_notification_created` (payload only) | `wb_gam` | `wb_gamification` |

A new plugin joins through BuddyNext's `buddynext_notification_sources` filter.

```php
array(
	'recipient_id'    => 42,                   // required
	'type'            => 'reply_to_post',      // required, the plugin's own slug
	'actor_id'        => 7,                    // 0 for system notices
	'object_type'     => 'post',               // the plugin's own object kind
	'object_id'       => 1153,
	'message'         => 'Aisha replied to "Welcome thread".',     // required, translated plain text
	'message_grouped' => '{actor} and {others} replied to "Welcome thread".', // optional
	'url'             => 'https://example.com/...',               // required
	'group_key'       => 'reply_to_post_1153', // per object + subtype, never per actor
	'context'         => array( 'type' => 'space', 'id' => 12, 'label' => 'Design Critique' ),
	'notification_id' => 991,                  // the plugin's own row id
)
```

`{actor}` = latest actor's name; `{others}` = BuddyNext's translated "1 other" / "3 others".
Never fire for the actor themself or during an import. Build the payload in ONE helper.

### 4.2 Declare types: the one switch

`{prefix}_community_notification_types` (slug => label, description, default_on). Declaring is
what makes BuddyNext read the payload. Ship the payload and the declaration together.

### 4.3 Visibility

`{prefix}_community_notification_visible( array $visible, int $viewer_id, array $targets )`,
return key => bool. Batched per bell page. Reuse the plugin's existing permission checks.

### 4.4 Removal

`{prefix}_community_notification_removed( $object_type, $object_id )` on PERMANENT delete only.
Trash and unpublish are visibility.

## 5. Checklist for any integration PR

- [ ] Manifest read in both plugins; nothing re-implemented that exists.
- [ ] Every rule lives in the data owner; the other side calls it.
- [ ] One helper per concern; Pro calls Free's.
- [ ] Old path deleted in the same release (or a dated removal comment if releases differ).
- [ ] Notification payload on every member-facing notification site; types declared; visibility
      reuses existing checks; removal on permanent delete; own email unchanged.
- [ ] Existing listeners of a changed hook still receive the arguments they registered for.
- [ ] Hooks documented in the plugin's hooks reference; `docs/standards/integration.md` synced.
- [ ] Verified live with both plugins active: one bell row, one email, correct link, per role.
