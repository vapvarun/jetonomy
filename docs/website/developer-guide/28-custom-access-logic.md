# Custom Access Logic with WP Fusion and Code Snippets

Jetonomy Pro's WP Fusion integration grants space access from CRM tags. The built-in
Access Rules are deliberately simple: each selected tag is one rule, and a user who
matches any rule gets in. When you need a compound condition - "must hold this tag
AND at least one of these" - you express it in a short code snippet instead of a
rule builder. This page shows the two supported patterns, using APIs the plugin
ships for exactly this purpose.

Both patterns survive plugin updates (they live in your child theme's
`functions.php` or a small must-use plugin, never in Jetonomy's files), and both
react automatically when tags change in your CRM - including a lapsed member
renewing, whose access comes back the moment the tag does.

## When to use which pattern

| Your space | Pattern |
|---|---|
| Private or hidden - people must be members to see it | Pattern 1: sync membership on tag change |
| Public with gated participation - access granted by rule, no join needed | Pattern 2: capability rule + `user_has_cap` |

## Pattern 1: sync membership on tag change

Private and hidden spaces require actual membership, so the right seam is the
moment tags change. WP Fusion fires `wpf_tags_modified` with the user's full tag
set after every change - the same hook Jetonomy Pro's own adapter listens to - and
Jetonomy's `SpaceMember` model is the public API for joining and removing members.

The example implements a real customer scenario: a "Chapter Leaders" space that
requires an active membership tag AND any one of three leader tags.

```php
add_action( 'wpf_tags_modified', function ( $user_id, $user_tags ) {
	$space_id = 123; // Your space's ID (visible in the wp-admin Spaces list).

	$has = fn( $tag ) => wp_fusion()->user->has_tag( $tag, $user_id );

	$eligible = $has( 'WP Sync: Active Member' ) && (
		$has( 'WP Sync: Is Chapter Leader' ) ||
		$has( 'WP Sync: Is Associate Chapter Leader' ) ||
		$has( 'WP Sync: Forums: Manual Add: Chapter Leaders' )
	);

	if ( $eligible && ! \Jetonomy\Models\SpaceMember::is_member( $space_id, $user_id ) ) {
		\Jetonomy\Models\SpaceMember::add( $space_id, $user_id, 'member' );
	} elseif ( ! $eligible && \Jetonomy\Models\SpaceMember::is_member( $space_id, $user_id ) ) {
		\Jetonomy\Models\SpaceMember::remove( $space_id, $user_id );
	}
}, 20, 2 );
```

How it behaves:

- **Grant and revoke are both automatic.** Losing the `Active Member` tag removes
  the user from the space; getting it back (a renewal) re-adds them. No manual
  cleanup, no scheduled sweep.
- **Any boolean shape works.** The `$eligible` expression is plain PHP - nest AND,
  OR, and NOT however your CRM model requires. `has_tag()` accepts a tag name or
  its numeric ID.
- **Priority 20** runs after Jetonomy Pro's own adapter (priority 10), so your
  compound rule has the last word if you also use the built-in per-tag sync.
- Managing several spaces? Repeat the pattern with a small map of
  `space_id => condition callback` instead of copying the block.

When you use this pattern for a space, leave that space's built-in WP Fusion tag
list empty - otherwise the plugin's own any-tag sync and your snippet will fight
over membership.

`SpaceMember` methods you may need (all in
`includes/models/class-space-member.php`):

```php
\Jetonomy\Models\SpaceMember::add( int $space_id, int $user_id, string $role = 'member' );
\Jetonomy\Models\SpaceMember::remove( int $space_id, int $user_id );
\Jetonomy\Models\SpaceMember::is_member( int $space_id, int $user_id ): bool;
\Jetonomy\Models\SpaceMember::set_role( int $space_id, int $user_id, string $role );
```

## Pattern 2: a capability rule backed by `user_has_cap`

For spaces where access is granted by rule rather than by membership, the Access
Rules tab already ships a **Capability** rule type, evaluated with WordPress's own
`user_can()`. Point it at a capability that doesn't exist in any role, then grant
that capability dynamically from your tag condition:

```php
add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	if ( ! in_array( 'access_chapter_leaders_space', $caps, true ) ) {
		return $allcaps; // Not our capability - stay out of the way.
	}
	if ( ! function_exists( 'wp_fusion' ) || ! $user->ID ) {
		return $allcaps;
	}

	$has = fn( $tag ) => wp_fusion()->user->has_tag( $tag, $user->ID );

	if ( $has( 'WP Sync: Active Member' ) && (
		$has( 'WP Sync: Is Chapter Leader' ) ||
		$has( 'WP Sync: Is Associate Chapter Leader' ) ||
		$has( 'WP Sync: Forums: Manual Add: Chapter Leaders' )
	) ) {
		$allcaps['access_chapter_leaders_space'] = true;
	}

	return $allcaps;
}, 10, 4 );
```

Then open the space in wp-admin, add an Access Rule of type **Capability** with
the value `access_chapter_leaders_space`, and choose what it grants (read,
participate, or full). The condition is evaluated live on every check - nothing is
stored, so there is nothing to get stale.

Note that access rules add grants on top of a space's visibility; they do not make
a private space visible to non-members. That is why private spaces use Pattern 1.

## Beyond WP Fusion

Neither pattern is WP Fusion-specific. Replace the `has_tag()` calls with any
condition you can compute in PHP - a WooCommerce Memberships check, a LearnDash
course completion, an external API lookup - and the same two seams apply. For the
full picture of how rules, visibility, and roles interact, see the
[Visibility and Access Matrix](./08-visibility-and-access-matrix.md); for the
membership adapter interface Pro implements on top of these seams, see
[Adapter System](./05-adapters.md).
