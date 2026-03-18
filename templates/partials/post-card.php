<?php
defined( 'ABSPATH' ) || exit;
$author  = get_userdata( $post->author_id );
$profile = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
$space   = \Jetonomy\Models\Space::find( (int) $post->space_id );
$initials = $author ? strtoupper( substr( $author->display_name, 0, 2 ) ) : '??';
$trust    = $profile ? (int) $profile->trust_level : 0;
$base     = home_url( '/community' );
$post_url = $base . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/';
$time_ago = human_time_diff( strtotime( $post->created_at ), current_time( 'timestamp', true ) );
$tags     = \Jetonomy\Models\Tag::list_for_post( (int) $post->id );
?>
<div class="jt-row <?php echo $post->is_sticky ? 'pinned' : ''; ?>"
	data-wp-interactive="jetonomy"
	onclick="window.location='<?php echo esc_url( $post_url ); ?>'">
	<div class="jt-votes">
		<button class="jt-v-btn"
			data-wp-on--click="actions.voteUp"
			data-post-id="<?php echo (int) $post->id; ?>"
			aria-label="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>">&#9650;</button>
		<span class="jt-v-num"
			data-wp-text="state.postScores.<?php echo (int) $post->id; ?>"><?php echo (int) $post->vote_score; ?></span>
		<button class="jt-v-btn"
			data-wp-on--click="actions.voteDown"
			data-post-id="<?php echo (int) $post->id; ?>"
			aria-label="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>">&#9660;</button>
	</div>
	<div class="jt-row-main">
		<div class="jt-row-title">
			<?php if ( $post->is_sticky ) : ?>
				<span aria-hidden="true">&#128204;</span>
			<?php endif; ?>
			<?php echo esc_html( $post->title ); ?>
		</div>
		<div class="jt-row-sub">
			<?php echo esc_html( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) ); ?>
			<span class="jt-tl" data-jt-tl="<?php echo $trust; ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo $trust; ?></span>
			<?php foreach ( $tags as $tag ) : ?>
				<a href="<?php echo esc_url( home_url( '/community/tag/' . $tag->slug . '/' ) ); ?>"
					class="jt-tag"
					onclick="event.stopPropagation();"><?php echo esc_html( $tag->name ); ?></a>
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
