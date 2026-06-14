<?php
/**
 * Community Media admin view — paginated grid of member uploads.
 *
 * @package Jetonomy\Admin
 *
 * @var array  $jt_result  Result set from Media_Library::query().
 * @var string $jt_member  Member search term.
 * @var int    $jt_space   Space filter id (0 = all).
 * @var string $jt_sort    'recent' | 'oldest'.
 * @var array  $jt_spaces  Spaces for the filter dropdown.
 */

defined( 'ABSPATH' ) || exit;

$jt_items = $jt_result['items'] ?? array();
$jt_total = (int) ( $jt_result['total'] ?? 0 );
$jt_pages = (int) ( $jt_result['total_pages'] ?? 1 );
$jt_page  = (int) ( $jt_result['page'] ?? 1 );

// Space id => name map for labelling cards + the filter dropdown.
$jt_space_names = array();
foreach ( (array) $jt_spaces as $jt_s ) {
	$jt_space_names[ (int) $jt_s->id ] = (string) $jt_s->title;
}
?>
<div class="wrap jt-cmedia">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Community Media', 'jetonomy' ); ?></h1>
	<hr class="wp-header-end">

	<p class="jt-cmedia__intro"><?php esc_html_e( 'Media uploaded by members across your community. These are hidden from the main Media Library by default so member uploads do not drown your own assets.', 'jetonomy' ); ?></p>

	<form method="get" class="jt-cmedia__filters">
		<input type="hidden" name="page" value="jetonomy-community-media">

		<label class="screen-reader-text" for="jt_member"><?php esc_html_e( 'Filter by member', 'jetonomy' ); ?></label>
		<input type="search" id="jt_member" name="jt_member" value="<?php echo esc_attr( $jt_member ); ?>" placeholder="<?php esc_attr_e( 'Member username or email', 'jetonomy' ); ?>">

		<label class="screen-reader-text" for="jt_space"><?php esc_html_e( 'Filter by space', 'jetonomy' ); ?></label>
		<select id="jt_space" name="jt_space">
			<option value="0"><?php esc_html_e( 'All spaces', 'jetonomy' ); ?></option>
			<?php foreach ( $jt_space_names as $jt_sid => $jt_sname ) : ?>
				<option value="<?php echo esc_attr( (string) $jt_sid ); ?>" <?php selected( $jt_space, $jt_sid ); ?>><?php echo esc_html( $jt_sname ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="jt_sort"><?php esc_html_e( 'Sort order', 'jetonomy' ); ?></label>
		<select id="jt_sort" name="jt_sort">
			<option value="recent" <?php selected( $jt_sort, 'recent' ); ?>><?php esc_html_e( 'Newest first', 'jetonomy' ); ?></option>
			<option value="oldest" <?php selected( $jt_sort, 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'jetonomy' ); ?></option>
		</select>

		<?php submit_button( __( 'Filter', 'jetonomy' ), 'secondary', '', false ); ?>
	</form>

	<p class="jt-cmedia__count">
		<?php
		printf(
			/* translators: %s: number of uploads. */
			esc_html( _n( '%s upload', '%s uploads', $jt_total, 'jetonomy' ) ),
			esc_html( number_format_i18n( $jt_total ) )
		);
		?>
	</p>

	<?php if ( empty( $jt_items ) ) : ?>
		<div class="jt-cmedia__empty">
			<p class="jt-cmedia__empty-title"><?php esc_html_e( 'No community uploads found', 'jetonomy' ); ?></p>
			<p><?php esc_html_e( 'When members upload images in posts or replies, they appear here. Try clearing the filters above.', 'jetonomy' ); ?></p>
		</div>
	<?php else : ?>
		<div class="jt-cmedia__grid">
			<?php
			foreach ( $jt_items as $jt_att ) :
				$jt_id     = (int) $jt_att->ID;
				$jt_sid    = (int) get_post_meta( $jt_id, '_jetonomy_space_id', true );
				$jt_author = get_the_author_meta( 'display_name', (int) $jt_att->post_author );
				$jt_edit   = get_edit_post_link( $jt_id );
				?>
				<div class="jt-cmedia__card">
					<div class="jt-cmedia__thumb">
						<?php
						// wp_get_attachment_image returns safe markup; shows a media-type icon for non-images.
						echo wp_get_attachment_image( $jt_id, 'medium', true, array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
					<div class="jt-cmedia__body">
						<p class="jt-cmedia__name"><?php echo esc_html( get_the_title( $jt_id ) ? get_the_title( $jt_id ) : __( '(no title)', 'jetonomy' ) ); ?></p>
						<p class="jt-cmedia__sub">
							<?php echo esc_html( $jt_author ? $jt_author : __( 'Unknown', 'jetonomy' ) ); ?>
							<?php if ( $jt_sid && isset( $jt_space_names[ $jt_sid ] ) ) : ?>
								<span aria-hidden="true">&middot;</span> <?php echo esc_html( $jt_space_names[ $jt_sid ] ); ?>
							<?php endif; ?>
						</p>
						<p class="jt-cmedia__sub jt-cmedia__date"><?php echo esc_html( get_the_date( '', $jt_id ) ); ?></p>
						<?php if ( $jt_edit ) : ?>
							<a href="<?php echo esc_url( $jt_edit ); ?>" class="jt-cmedia__link"><?php esc_html_e( 'View / edit', 'jetonomy' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $jt_pages > 1 ) : ?>
			<div class="jt-cmedia__pager tablenav-pages">
				<?php
				echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links returns safe markup.
					array(
						'base'      => esc_url_raw( add_query_arg( 'paged', '%#%' ) ),
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $jt_pages,
						'current'   => $jt_page,
					)
				);
				?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
