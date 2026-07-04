<?php
/**
 * Space-scoped moderation queue.
 *
 * Renders pending flags for the current space, visible to space
 * moderators, space admins, WP admins, and jetonomy_moderate-cap holders.
 *
 * Backing REST:  /jetonomy/v1/spaces/{id}/moderation/flags
 * Backing REST:  /jetonomy/v1/spaces/{id}/moderation/flags/{flag_id}/resolve
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Moderation\Moderation_Permissions;
use Jetonomy\Moderation\Moderation_Service;

$space_slug = (string) ( $data['slug'] ?? '' );
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
			'tone'      => 'warn',
		]
	);
	return;
}

$user_id = get_current_user_id();
if ( ! Moderation_Permissions::can_view_space_queue( $user_id, (int) $space->id ) ) {
	status_header( 403 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'message' => __( 'You do not have permission to moderate this space.', 'jetonomy' ),
			'tone'    => 'forbidden',
		]
	);
	return;
}

$flags    = Moderation_Service::list_pending_flags( $user_id, (int) $space->id );
$base     = \Jetonomy\base_url();
$category = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;

$crumbs = [];
if ( $category ) {
	$crumbs[] = [
		'label' => $category->name,
		'url'   => '',
	];
}
$crumbs[] = [
	'label' => $space->title,
	'url'   => $base . '/s/' . $space->slug . '/',
];
$crumbs[] = [
	'label' => __( 'Moderation', 'jetonomy' ),
	'url'   => '',
];

$resolve_endpoint = esc_url_raw( rest_url( 'jetonomy/v1/spaces/' . (int) $space->id . '/moderation/flags/' ) );
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
	<main>
		<div class="jt-mod-wrap jt-mod-queue">
			<div class="jt-flex jt-items-center jt-justify-between jt-mb-20">
				<div class="jt-cat-page-row">
					<?php jetonomy_render_space_icon( $space->icon ?? '', 24, 'jt-space-card-emoji', $space->type ?? '' ); ?>
					<div>
						<h1 class="jt-page-title jt-page-title-sm">
							<?php echo esc_html( $space->title ); ?>
							&nbsp;&middot;&nbsp;
							<?php esc_html_e( 'Moderation', 'jetonomy' ); ?>
						</h1>
						<p class="jt-member-sub">
							<?php
							$count = count( $flags );
							/* translators: %d: number of pending flags */
							echo esc_html( sprintf( _n( '%d pending flag', '%d pending flags', $count, 'jetonomy' ), $count ) );
							?>
						</p>
					</div>
				</div>

				<?php if ( ! empty( $flags ) ) : ?>
					<span class="jt-badge-danger jt-flag-count" data-count="<?php echo esc_attr( (string) count( $flags ) ); ?>">
						<?php
						/* translators: %d: number of pending flags */
						echo esc_html( sprintf( _n( '%d pending', '%d pending', count( $flags ), 'jetonomy' ), count( $flags ) ) );
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( empty( $flags ) ) : ?>
				<?php \Jetonomy\Template_Loader::partial( 'moderation/queue-empty' ); ?>
			<?php else : ?>
				<div class="jt-card jt-card-flush" data-jt-mod-queue="space" data-space-id="<?php echo absint( $space->id ); ?>">
					<?php foreach ( $flags as $flag ) : ?>
						<?php
						\Jetonomy\Template_Loader::partial(
							'moderation/flag-card',
							[
								'flag'             => $flag,
								'resolve_endpoint' => $resolve_endpoint,
								'base'             => $base,
							]
						);
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
</div>
