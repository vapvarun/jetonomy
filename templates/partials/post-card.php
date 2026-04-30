<?php
/**
 * Post card partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
$author  = get_userdata( $post->author_id );
$profile = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
$space   = \Jetonomy\Models\Space::find( (int) $post->space_id );
// 1.4.0 C.5: caller passes `has_unread` from the bulk read-status map.
// Boolean signal — pill text reads "New" regardless of how many.
$has_unread  = isset( $has_unread ) ? (bool) $has_unread : false;
$initials    = $author ? strtoupper( substr( $author->display_name, 0, 2 ) ) : '??';
$trust       = $profile ? (int) $profile->trust_level : 0;
$base        = \Jetonomy\base_url();
$post_url    = $base . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/';
$time_ago    = human_time_diff( strtotime( $post->created_at ), time() );
$tags        = \Jetonomy\Models\Tag::list_for_post( (int) $post->id );
$viewer_id   = get_current_user_id();
$viewer_vote = $viewer_id ? \Jetonomy\Models\Vote::get_user_vote( $viewer_id, 'post', (int) $post->id ) : null;

// Resolve prefix color from space settings.
$prefix_name  = $post->prefix ?? null;
$prefix_color = null;
if ( $prefix_name && $space ) {
	$space_settings_pf = \Jetonomy\Models\Space::get_settings( (int) $space->id );
	$prefix_list       = $space_settings_pf['prefixes'] ?? array();
	foreach ( $prefix_list as $pfx ) {
		if ( ( $pfx['name'] ?? '' ) === $prefix_name ) {
			$prefix_color = $pfx['color'] ?? null;
			break;
		}
	}
}
?>
<div class="jt-row <?php echo $post->is_sticky ? esc_attr( 'pinned' ) : ''; ?>"
	data-wp-interactive="jetonomy">
	<div class="jt-votes">
		<span class="jt-v-btn <?php echo 1 === $viewer_vote ? esc_attr( 'jt-voted' ) : ''; ?>" aria-hidden="true"><?php jetonomy_echo_icon( 'chevron-up', 14 ); ?></span>
		<span class="jt-v-num"><?php echo (int) $post->vote_score; ?></span>
		<span class="jt-v-btn <?php echo -1 === $viewer_vote ? esc_attr( 'jt-voted' ) : ''; ?>" aria-hidden="true"><?php jetonomy_echo_icon( 'chevron-down', 14 ); ?></span>
	</div>
	<div class="jt-row-main">
		<?php
		// 1.4.0 C.4 fix: row is no longer one big <a>. The title link is the
		// stretched anchor (CSS positions ::before to cover the whole row),
		// so child <a> elements (tags, mentions) remain clickable without
		// nested-anchor invalid markup. The whole row stays clickable.
		?>
		<a class="jt-row-title jt-row-title-link" href="<?php echo esc_url( $post_url ); ?>">
			<?php if ( $post->is_sticky ) : ?>
				<span aria-hidden="true"><?php jetonomy_echo_icon( 'pin', 14 ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $post->is_private ) ) : ?>
				<span class="jt-badge-private"><?php jetonomy_echo_icon( 'lock', 12 ); ?> <?php esc_html_e( 'Private', 'jetonomy' ); ?></span>
			<?php endif; ?>
			<?php if ( $prefix_name ) : ?>
				<span class="jt-prefix"
				<?php
				if ( $prefix_color ) :
					?>
					style="--jt-pfx:<?php echo esc_attr( $prefix_color ); ?>"<?php endif; ?>><?php echo esc_html( $prefix_name ); ?></span>
			<?php endif; ?>
			<?php echo esc_html( $post->title ); ?>
			<?php if ( $has_unread ) : ?>
				<span class="jt-unread-pill" aria-label="<?php esc_attr_e( 'You have unread replies', 'jetonomy' ); ?>">
					<?php esc_html_e( 'New', 'jetonomy' ); ?>
				</span>
			<?php endif; ?>
		</a>
		<div class="jt-row-sub">
			<?php echo esc_html( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) ); ?>
			<?php
			// 1.4.0 G3: render role pill (Admin / Mod) when this user holds a
			// privileged role IN THIS POST'S SPACE. Reads the warmed cache
			// populated by the parent view — see space.php / single-post.php.
			$jt_role = \Jetonomy\get_space_role_label( (int) $post->author_id, (int) $post->space_id );
			if ( null !== $jt_role ) :
				$jt_role_label = ( 'admin' === $jt_role )
					? __( 'Admin', 'jetonomy' )
					: __( 'Mod', 'jetonomy' );
				?>
				<span class="jt-role-pill jt-role-pill--<?php echo esc_attr( $jt_role ); ?>">
					<?php echo esc_html( $jt_role_label ); ?>
				</span>
			<?php endif; ?>
			<?php
			// 1.4.1 byline cleanup: trust-level number removed. Trust progress
			// stays on the user profile + hover-card surfaces.
			?>
			<?php foreach ( $tags as $post_tag ) : ?>
				<?php
				// 1.4.0 C.4 fix: tags are now anchors to /community/tag/:slug/
				// instead of inert <span> elements. CSS selectors stay
				// `.jt-tag` so existing styles still apply (the `<a>` carries
				// the same class).
				$jt_tag_url = \Jetonomy\base_url() . '/tag/' . rawurlencode( (string) $post_tag->slug ) . '/';
				?>
				<a class="jt-tag" href="<?php echo esc_url( $jt_tag_url ); ?>"><?php echo esc_html( $post_tag->name ); ?></a>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="jt-row-stat">
		<div class="jt-row-stat-n"><?php echo (int) $post->reply_count; ?></div>
		<div class="jt-row-stat-l"><?php esc_html_e( 'replies', 'jetonomy' ); ?></div>
	</div>
	<div class="jt-row-stat">
		<div class="jt-row-time">
			<?php
			/* translators: %s: human-readable time difference */
			echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
			?>
		</div>
	</div>
</div>
