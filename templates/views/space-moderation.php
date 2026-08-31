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

if ( ! $space || \Jetonomy\Models\Space::concealed_from_viewer( $space, get_current_user_id() ) ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			/* translators: %s: the singular label of the item (the configured noun). */
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
			/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
			'message' => sprintf( __( 'You do not have permission to moderate this %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
			'tone'    => 'forbidden',
		]
	);
	return;
}

$base     = \Jetonomy\base_url();
$category = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$space_id = (int) $space->id;

// Two panels, two sources. A flag is a member REPORTING published content; an
// approval-hold is this space refusing to publish at all, via require_approval
// (Base_Controller::should_hold_for_approval() writes status = 'pending' and
// creates no flag row). Held content therefore never appeared in a flag-only
// queue - it was approvable from wp-admin and nowhere else, which is no use to
// a space moderator who has no business in the WordPress dashboard.
$jt_view = sanitize_key( wp_unslash( $_GET['view'] ?? 'flags' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $jt_view, [ 'flags', 'approvals' ], true ) ) {
	$jt_view = 'flags';
}

$jt_kind = sanitize_key( wp_unslash( $_GET['kind'] ?? 'post' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $jt_kind, [ 'post', 'reply' ], true ) ) {
	$jt_kind = 'post';
}

// Pagination. The flags list previously took the model's unbounded default, so
// a space past a few hundred flags rendered every one of them into a single
// response - the rows past the fold were unreachable in practice and the page
// grew without limit. Same PER_PAGE contract as the cross-space dashboard.
$per_page     = (int) apply_filters( 'jetonomy_moderation_per_page', 25 );
$jt_raw_paged = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$jt_flag_total = Moderation_Service::count_pending_flags( $user_id, $space_id );
$jt_flag_pages = max( 1, (int) ceil( $jt_flag_total / $per_page ) );
$jt_flag_paged = min( $jt_raw_paged, $jt_flag_pages );
$flags         = 'flags' === $jt_view && $jt_flag_total > 0
	? Moderation_Service::list_pending_flags( $user_id, $space_id, $per_page, ( $jt_flag_paged - 1 ) * $per_page )
	: [];

// Both held counts run on every render so the tab badge stays honest while the
// moderator is reading the flags panel. Two COUNT(*)s on status_created.
$jt_held_posts   = Moderation_Service::count_pending_approvals( $user_id, 'post', $space_id );
$jt_held_replies = Moderation_Service::count_pending_approvals( $user_id, 'reply', $space_id );
$jt_held_total   = $jt_held_posts + $jt_held_replies;

$jt_held_count = 'reply' === $jt_kind ? $jt_held_replies : $jt_held_posts;
$jt_held_pages = max( 1, (int) ceil( $jt_held_count / $per_page ) );
$jt_held_paged = min( $jt_raw_paged, $jt_held_pages );
$jt_held       = 'approvals' === $jt_view && $jt_held_count > 0
	? Moderation_Service::list_pending_approvals( $user_id, $jt_kind, $space_id, $per_page, ( $jt_held_paged - 1 ) * $per_page )
	: [];

// Batch-resolve what the cards need. Every held item is in THIS space, so only
// the reply parents and the author cache need priming.
$jt_held_parents = [];
if ( $jt_held ) {
	if ( 'reply' === $jt_kind ) {
		$jt_held_parents = \Jetonomy\Models\Post::list_by_ids( array_map( static fn( $r ) => (int) $r->post_id, $jt_held ) );
	}
	$jt_held_authors = array_values( array_unique( array_map( static fn( $r ) => (int) $r->author_id, $jt_held ) ) );
	if ( $jt_held_authors ) {
		// Primes WP's user cache so each card's get_userdata() is served from memory.
		get_users(
			[
				'include'     => $jt_held_authors,
				'fields'      => 'all_with_meta',
				'number'      => count( $jt_held_authors ),
				'count_total' => false,
			]
		);
	}
}

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
						<?php // Shared space sub-page header — see space-members.php. ?>
						<h1 class="jt-page-title jt-page-title-sm">
							<?php echo esc_html( $space->title ); ?>
						</h1>
						<p class="jt-page-subtitle">
							<?php
							// The subtitle follows the open panel - reporting the flag
							// total over a screen of held submissions reads as a bug.
							if ( 'approvals' === $jt_view ) {
								/* translators: %d: number of submissions held for approval. */
								echo esc_html( sprintf( _n( '%d submission awaiting approval', '%d submissions awaiting approval', $jt_held_total, 'jetonomy' ), $jt_held_total ) );
							} else {
								/* translators: %d: number of pending flags. */
								echo esc_html( sprintf( _n( '%d pending flag', '%d pending flags', $jt_flag_total, 'jetonomy' ), $jt_flag_total ) );
							}
							?>
						</p>
					</div>
				</div>

				<?php if ( 'approvals' !== $jt_view && $jt_flag_total > 0 ) : ?>
					<span class="jt-badge-danger jt-flag-count" data-count="<?php echo esc_attr( (string) $jt_flag_total ); ?>">
						<?php
						/* translators: %d: number of pending flags. */
						echo esc_html( sprintf( _n( '%d pending', '%d pending', $jt_flag_total, 'jetonomy' ), $jt_flag_total ) );
						?>
					</span>
				<?php elseif ( 'approvals' === $jt_view && $jt_held_total > 0 ) : ?>
					<span class="jt-badge-danger jt-held-count" data-count="<?php echo esc_attr( (string) $jt_held_total ); ?>">
						<?php
						/* translators: %d: number of submissions held for approval. */
						echo esc_html( sprintf( _n( '%d held', '%d held', $jt_held_total, 'jetonomy' ), $jt_held_total ) );
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php $jt_queue_url = $base . '/s/' . $space->slug . '/mod/'; ?>
			<nav class="jt-profile-tabs" aria-label="<?php esc_attr_e( 'Moderation sections', 'jetonomy' ); ?>">
				<a href="<?php echo esc_url( $jt_queue_url ); ?>" class="jt-profile-tab <?php echo 'flags' === $jt_view ? 'active' : ''; ?>" <?php echo 'flags' === $jt_view ? 'aria-current="page"' : ''; ?>>
					<?php esc_html_e( 'Flags', 'jetonomy' ); ?>
					<?php if ( $jt_flag_total > 0 ) : ?>
						<span class="jt-tab-count"><?php echo esc_html( number_format_i18n( $jt_flag_total ) ); ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'approvals', $jt_queue_url ) ); ?>" class="jt-profile-tab <?php echo 'approvals' === $jt_view ? 'active' : ''; ?>" <?php echo 'approvals' === $jt_view ? 'aria-current="page"' : ''; ?>>
					<?php esc_html_e( 'Awaiting approval', 'jetonomy' ); ?>
					<?php if ( $jt_held_total > 0 ) : ?>
						<span class="jt-tab-count"><?php echo esc_html( number_format_i18n( $jt_held_total ) ); ?></span>
					<?php endif; ?>
				</a>
			</nav>

			<?php if ( 'approvals' === $jt_view ) : ?>
				<nav class="jt-subtabs" aria-label="<?php esc_attr_e( 'Held content type', 'jetonomy' ); ?>">
					<?php
					$jt_kind_tabs = [
						'post'  => [ __( 'Posts', 'jetonomy' ), $jt_held_posts ],
						'reply' => [ \Jetonomy\jetonomy_label( 'reply', true ), $jt_held_replies ],
					];
					foreach ( $jt_kind_tabs as $jt_kind_key => $jt_kind_meta ) :
						$jt_kind_url = add_query_arg(
							[
								'view' => 'approvals',
								'kind' => $jt_kind_key,
							],
							$jt_queue_url
						);
						?>
						<a href="<?php echo esc_url( $jt_kind_url ); ?>"
							class="jt-subtab <?php echo $jt_kind === $jt_kind_key ? 'active' : ''; ?>"
							<?php echo $jt_kind === $jt_kind_key ? 'aria-current="page"' : ''; ?>>
							<?php echo esc_html( $jt_kind_meta[0] ); ?>
							<span class="jt-tab-count"><?php echo esc_html( number_format_i18n( $jt_kind_meta[1] ) ); ?></span>
						</a>
					<?php endforeach; ?>
				</nav>

				<?php if ( empty( $jt_held ) ) : ?>
					<?php
					$jt_held_empty = 'reply' === $jt_kind
						? sprintf( /* translators: %s: plural reply label. */ __( 'No %s are waiting for approval.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ) )
						: __( 'No posts are waiting for approval.', 'jetonomy' );
					\Jetonomy\Template_Loader::partial( 'moderation/queue-empty', [ 'message' => $jt_held_empty ] );
					?>
				<?php else : ?>
					<div class="jt-card jt-card-flush" data-jt-mod-queue="approvals" data-space-id="<?php echo absint( $space->id ); ?>">
						<?php
						foreach ( $jt_held as $jt_item ) :
							$jt_parent = 'reply' === $jt_kind
								? ( $jt_held_parents[ (int) $jt_item->post_id ] ?? null )
								: null;
							// A held reply whose parent post was deleted underneath it
							// has no thread to approve into; the delete cascade clears it.
							if ( 'reply' === $jt_kind && ! $jt_parent ) {
								continue;
							}
							\Jetonomy\Template_Loader::partial(
								'moderation/approval-card',
								[
									'item'        => $jt_item,
									'kind'        => $jt_kind,
									'space'       => $space,
									'parent_post' => $jt_parent,
									'base'        => $base,
								]
							);
						endforeach;
						?>
					</div>

					<?php
					if ( $jt_held_pages > 1 ) :
						$jt_held_base = add_query_arg(
							[
								'view' => 'approvals',
								'kind' => $jt_kind,
							],
							$jt_queue_url
						);
						\Jetonomy\Template_Loader::partial(
							'pagination-nav',
							[
								'paged' => $jt_held_paged,
								'pages' => $jt_held_pages,
								'base'  => $jt_held_base,
								'label' => __( 'Awaiting approval pagination', 'jetonomy' ),
							]
						);
					endif;
					?>
				<?php endif; ?>

			<?php elseif ( empty( $flags ) ) : ?>
				<?php \Jetonomy\Template_Loader::partial( 'moderation/queue-empty' ); ?>
			<?php else : ?>
				<?php // One batched resolve for the page; the card falls back per-row without it. ?>
				<?php $jt_flag_ctx = Moderation_Service::flag_context( $flags, $base ); ?>
				<div class="jt-card jt-card-flush" data-jt-mod-queue="space" data-space-id="<?php echo absint( $space->id ); ?>">
					<?php foreach ( $flags as $flag ) : ?>
						<?php
						\Jetonomy\Template_Loader::partial(
							'moderation/flag-card',
							[
								'flag'             => $flag,
								'resolve_endpoint' => $resolve_endpoint,
								'base'             => $base,
								'context'          => $jt_flag_ctx[ $flag->object_type . ':' . (int) $flag->object_id ] ?? null,
							]
						);
						?>
					<?php endforeach; ?>
				</div>

				<?php
				if ( $jt_flag_pages > 1 ) :
					\Jetonomy\Template_Loader::partial(
						'pagination-nav',
						[
							'paged' => $jt_flag_paged,
							'pages' => $jt_flag_pages,
							'base'  => $jt_queue_url,
							'label' => __( 'Moderation queue pagination', 'jetonomy' ),
						]
					);
				endif;
				?>
			<?php endif; ?>
		</div>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
</div>
