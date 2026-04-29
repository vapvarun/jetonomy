# A9 — Frontend `?tab=drafts` and `?tab=bookmarks` views

**Branch:** `1.4.1`
**Plugin:** jetonomy (free)
**Risk:** low — REST endpoints already exist
**Estimated time:** 2 days

## Problem

REST endpoints exist for drafts (`GET /posts/drafts`) and bookmarks (`GET /bookmarks`). Frontend has no tabs to render them — users can't see "my drafts" or "my bookmarks" without crafting REST calls themselves.

## Implementation

### New route slugs in template-loader

`includes/class-template-loader.php` already maps tab slugs (e.g., `feed`, `spaces`, `notifications`) to template files. Add two more:

```php
'drafts'    => 'views/drafts.php',
'bookmarks' => 'views/bookmarks.php',
```

Both routes are auth-required (already in the `auth_required_routes` array — verify).

### New templates

```
templates/views/drafts.php       (new)
templates/views/bookmarks.php    (new)
```

Pattern (copy from an existing list-view template like `feed.php`):

```php
<?php defined( 'ABSPATH' ) || exit; ?>
<div class="jt-shell">
    <?php do_action( 'jetonomy_before_content', 'drafts', null ); ?>
    
    <h1><?php esc_html_e( 'My drafts', 'jetonomy' ); ?></h1>
    
    <?php
    $drafts = ( new \Jetonomy\Models\Post )->list_drafts_for( get_current_user_id() );
    if ( empty( $drafts ) ) :
        ?><p class="jt-empty"><?php esc_html_e( 'No drafts yet. Start a new post and save it as a draft to see it here.', 'jetonomy' ); ?></p><?php
    else :
        foreach ( $drafts as $draft ) :
            include __DIR__ . '/../partials/post-card.php';
        endforeach;
    endif;
    ?>
    
    <?php do_action( 'jetonomy_after_content', 'drafts', null ); ?>
</div>
```

(Bookmarks template same pattern, calling `Bookmarks::list_for( get_current_user_id() )`.)

Reuse `templates/partials/post-card.php` so the card style matches existing feeds.

### Empty state design

When the user has no drafts/bookmarks:
- Friendly message ("No drafts yet" / "You haven't bookmarked anything yet")
- CTA back to feed ("Browse the community" link)

### Anonymous handling

Already covered: `template-loader.php` line 39's `auth_required_routes` array redirects anonymous users to login when they hit `?tab=drafts` or `?tab=bookmarks`. Just verify both new slugs are in the list.

## Safety checks

1. **PRE:**
   - Auth as `test_subscriber` with no drafts/bookmarks: `?tab=drafts` and `?tab=bookmarks` currently 404 or render the default feed (this is the bug)
   - Screenshot of existing tabs (`feed`, `notifications`) for visual regression diff
2. **POST:**
   - Auth user with drafts: `?tab=drafts` shows them, count matches `GET /posts/drafts` REST response
   - Auth user without drafts: empty-state UI shows
   - Anonymous: redirected to login (or shows login prompt)
   - Same trio for `?tab=bookmarks`
   - Existing tabs (`feed`, `spaces`, `notifications`, `messages`, etc.) still work — no visual regression
   - Click a draft card → opens edit composer with content prefilled
   - Click a bookmark → opens the post
3. **Smoke** 210/210
4. **Runner:** `--diff-baseline` no drift — these are template-only changes, REST unchanged

## Commits

```
1. feat(frontend): drafts tab template + route map (A9.1)
2. feat(frontend): bookmarks tab template + route map (A9.2)
```

## Done criteria

- [ ] Two new templates render correctly for authed user
- [ ] Empty-state UI works for both
- [ ] Anonymous redirect works
- [ ] Existing tabs unaffected
- [ ] CHECKLIST marks A9 done
- [ ] Push to origin/1.4.1

## Forbidden

- ❌ Don't add new REST endpoints (existing `/posts/drafts` + `/bookmarks` are sufficient)
- ❌ Don't break existing tab routing — keep additions purely additive
