<?php
/**
 * Post card partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
$author      = get_userdata( $post->author_id );
$profile     = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
$space       = \Jetonomy\Models\Space::find( (int) $post->space_id );
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
<a href="<?php echo esc_url( $post_url ); ?>" class="jt-row <?php echo $post->is_sticky ? esc_attr( 'pinned' ) : ''; ?>"
	data-wp-interactive="jetonomy">
	<div class="jt-votes">
		<span class="jt-v-btn <?php echo 1 === $viewer_vote ? esc_attr( 'jt-voted' ) : ''; ?>" aria-hidden="true"><?php jetonomy_echo_icon( 'chevron-up', 14 ); ?></span>
		<span class="jt-v-num"><?php echo (int) $post->vote_score; ?></span>
		<span class="jt-v-btn <?php echo -1 === $viewer_vote ? esc_attr( 'jt-voted' ) : ''; ?>" aria-hidden="true"><?php jetonomy_echo_icon( 'chevron-down', 14 ); ?></span>
	</div>
	<div class="jt-row-main">
		<div class="jt-row-title">
			<?php if ( $post->is_sticky ) : ?>
				<span aria-hidden="true"><?php jetonomy_echo_icon( 'pin', 14 ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $post->is_private ) ) : ?>
				<span class="jt-badge-private"><?php jetonomy_echo_icon( 'lock', 12 ); ?> <?php esc_html_e( 'Private', 'jetonomy' ); ?></span>
			<?php endif; ?>
			<?php if ( $prefix_name ) : ?>
				<span class="jt-prefix" <?php echo $prefix_color ? 'style="--jt-pfx:' . esc_attr( $prefix_color ) . '"' : ''; ?>><?php echo esc_html( $prefix_name ); ?></span>
			<?php endif; ?>
			<?php echo esc_html( $post->title ); ?>
		</div>
		<div class="jt-row-sub">
			<?php echo esc_html( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) ); ?>
			<?php /* translators: %d: trust level number */ ?>
			<span class="jt-tl" data-jt-tl="<?php echo esc_attr( (string) $trust ); ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo (int) $trust; ?></span>
			<?php foreach ( $tags as $post_tag ) : ?>
				<span class="jt-tag"><?php echo esc_html( $post_tag->name ); ?></span>
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
</a>
