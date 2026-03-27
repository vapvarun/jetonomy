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
$initials = $author ? strtoupper( substr( $author->display_name, 0, 2 ) ) : '??';
$trust    = $profile ? (int) $profile->trust_level : 0;
$base     = \Jetonomy\base_url();
$post_url = $base . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/';
$time_ago = human_time_diff( strtotime( $post->created_at ), current_time( 'timestamp', true ) );
$tags     = \Jetonomy\Models\Tag::list_for_post( (int) $post->id );
?>
<a href="<?php echo esc_url( $post_url ); ?>" class="jt-row <?php echo $post->is_sticky ? esc_attr( 'pinned' ) : ''; ?>"
	data-wp-interactive="jetonomy">
	<div class="jt-votes">
		<span class="jt-v-btn" aria-hidden="true"><?php jetonomy_echo_icon( 'chevron-up', 14 ); ?></span>
		<span class="jt-v-num"><?php echo (int) $post->vote_score; ?></span>
		<span class="jt-v-btn" aria-hidden="true"><?php jetonomy_echo_icon( 'chevron-down', 14 ); ?></span>
	</div>
	<div class="jt-row-main">
		<div class="jt-row-title">
			<?php if ( $post->is_sticky ) : ?>
				<span aria-hidden="true"><?php jetonomy_echo_icon( 'pin', 14 ); ?></span>
			<?php endif; ?>
			<?php echo esc_html( $post->title ); ?>
		</div>
		<div class="jt-row-sub">
			<?php echo esc_html( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) ); ?>
			<span class="jt-tl" data-jt-tl="<?php echo esc_attr( (string) $trust ); ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo (int) $trust; ?></span>
			<?php foreach ( $tags as $tag ) : ?>
				<span class="jt-tag"><?php echo esc_html( $tag->name ); ?></span>
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
