<?php
/**
 * My Subscriptions view — every space + topic the viewer follows, with
 * one-click unfollow. Closes the three-entry-points gap: the subscriptions
 * store had REST + per-object toggles but no manage surface (Basecamp
 * 9891710479).
 *
 * Auth is enforced by Template_Loader ($auth_required_routes).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$jt_user_id  = get_current_user_id();
$jt_per_page = 50;
$jt_page     = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1;
$jt_offset   = ( $jt_page - 1 ) * $jt_per_page;
$jt_total    = \Jetonomy\Models\Subscription::count_for_user( $jt_user_id );
$jt_rows     = \Jetonomy\Models\Subscription::list_for_user( $jt_user_id, $jt_per_page, $jt_offset );
$jt_base     = \Jetonomy\base_url();

// Resolve titles/slugs via the shared model resolver (same source REST uses).
$jt_items = \Jetonomy\Models\Subscription::attach_targets(
	array_map(
		static fn( $r ) => array(
			'id'          => (int) $r->id,
			'object_type' => (string) $r->object_type,
			'object_id'   => (int) $r->object_id,
			'via'         => (string) ( $r->notify_via ?? 'both' ),
		),
		$jt_rows
	)
);

$jt_spaces_subs = array_values( array_filter( $jt_items, static fn( $i ) => 'space' === $i['object_type'] ) );
$jt_topic_subs  = array_values( array_filter( $jt_items, static fn( $i ) => 'post' === $i['object_type'] ) );

$jt_via_labels = array(
	'web'   => __( 'Web', 'jetonomy' ),
	'email' => __( 'Email', 'jetonomy' ),
	'both'  => __( 'Web + Email', 'jetonomy' ),
);

/**
 * Render one subscription group as a list.
 *
 * @param array  $items      Resolved subscription items.
 * @param string $type       'space'|'post'.
 * @param string $base       Community base URL.
 * @param array  $via_labels Channel labels.
 */
$jt_render_group = static function ( array $items, string $type, string $base, array $via_labels ): void {
	?>
	<ul class="jt-subs-list">
		<?php foreach ( $items as $item ) : ?>
			<li class="jt-subs-row">
				<div class="jt-subs-main">
					<?php if ( $item['exists'] && '' !== $item['title'] ) : ?>
						<a class="jt-subs-title" href="<?php echo esc_url( 'space' === $type ? $base . '/s/' . $item['slug'] . '/' : $base . '/s/' . ( $item['space_slug'] ?? '' ) . '/t/' . $item['slug'] . '/' ); ?>">
							<?php echo esc_html( $item['title'] ); ?>
						</a>
					<?php else : ?>
						<span class="jt-subs-title jt-subs-title--gone"><?php esc_html_e( 'No longer available', 'jetonomy' ); ?></span>
					<?php endif; ?>
					<span class="jt-subs-via"><?php echo esc_html( $via_labels[ $item['via'] ] ?? $via_labels['both'] ); ?></span>
				</div>
				<button class="jt-btn jt-btn-ghost jt-btn-sm jt-flex-shrink-0"
					data-wp-on--click="actions.unsubscribeRow"
					data-subscription-id="<?php echo absint( $item['id'] ); ?>"
					data-confirm="<?php echo esc_attr( sprintf( __( 'Unfollow “%s”?', 'jetonomy' ), $item['title'] ?: __( 'this item', 'jetonomy' ) ) ); ?>"
					title="<?php esc_attr_e( 'Stop receiving notifications for this', 'jetonomy' ); ?>">
					<?php jetonomy_echo_icon( 'bell-off', 14 ); ?>
					<?php esc_html_e( 'Unfollow', 'jetonomy' ); ?>
				</button>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
};
?>

<div class="jt-app" data-wp-interactive="jetonomy">
	<div class="jt-container">
		<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'trail' => array( __( 'My Subscriptions', 'jetonomy' ) ) ) ); ?>
		<main>
			<div class="jt-flex jt-items-center jt-justify-between jt-mb-20">
				<h1 class="jt-page-title"><?php esc_html_e( 'My Subscriptions', 'jetonomy' ); ?></h1>
			</div>

			<?php if ( empty( $jt_items ) ) : ?>
				<?php
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					array(
						'icon'      => 'bell',
						'icon_size' => 48,
						'message'   => __( 'You are not following anything yet. Follow a topic or a space and it will show up here.', 'jetonomy' ),
					)
				);
				?>
			<?php else : ?>
				<?php if ( ! empty( $jt_spaces_subs ) ) : ?>
					<h2 class="jt-subs-heading"><?php echo esc_html( sprintf( __( '%s you follow', 'jetonomy' ), \Jetonomy\space_label( true ) ) ); ?></h2>
					<?php $jt_render_group( $jt_spaces_subs, 'space', $jt_base, $jt_via_labels ); ?>
				<?php endif; ?>

				<?php if ( ! empty( $jt_topic_subs ) ) : ?>
					<h2 class="jt-subs-heading"><?php esc_html_e( 'Topics you follow', 'jetonomy' ); ?></h2>
					<?php $jt_render_group( $jt_topic_subs, 'post', $jt_base, $jt_via_labels ); ?>
				<?php endif; ?>

				<?php if ( $jt_total > $jt_per_page ) : ?>
					<nav class="jt-pagination" aria-label="<?php esc_attr_e( 'Subscriptions pagination', 'jetonomy' ); ?>">
						<?php if ( $jt_page > 1 ) : ?>
							<a class="jt-btn jt-btn-ghost jt-btn-sm" href="<?php echo esc_url( add_query_arg( 'pg', $jt_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'jetonomy' ); ?></a>
						<?php endif; ?>
						<?php if ( $jt_offset + $jt_per_page < $jt_total ) : ?>
							<a class="jt-btn jt-btn-ghost jt-btn-sm" href="<?php echo esc_url( add_query_arg( 'pg', $jt_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'jetonomy' ); ?></a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</main>
	</div>
</div>
