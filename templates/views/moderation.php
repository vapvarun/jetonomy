<?php
/**
 * Cross-space moderation dashboard.
 *
 * Two audiences land here:
 *   1. WP admins + jetonomy_moderate cap holders — see every space with
 *      pending flags. Their query is unscoped.
 *   2. Space-level mods who moderate two or more spaces — see only the
 *      queues they actually own. (Single-space mods are redirected by
 *      Template_Loader::render straight to /s/:slug/mod/, so they
 *      never reach this template.)
 *
 * Page model: pending flags are listed directly so a moderator
 * sees content excerpt + reporter + age + reason WITHOUT having
 * to open a per-space queue first. Bulk actions and detailed
 * resolution still live at /s/:slug/mod/ — the row's space link
 * carries the moderator there pre-scoped to that space's queue.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Moderation\Moderation_Permissions;
use Jetonomy\Moderation\Moderation_Service;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;

$user_id  = get_current_user_id();
$base     = \Jetonomy\base_url();
$is_admin = Moderation_Permissions::can_view_admin_dashboard( $user_id );

// Anyone without admin dashboard access OR any moderated space gets the
// standard 403 empty state. Template_Loader has already redirected
// single-space mods, so reaching here without view rights is a stale
// link / drive-by visit.
if ( ! $is_admin && ! Moderation_Permissions::can_view_any_queue( $user_id ) ) {
	status_header( 403 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'message' => __( 'You do not have permission to view this page.', 'jetonomy' ),
			'tone'    => 'forbidden',
		]
	);
	return;
}

// Pagination: ?paged=N from the URL, clamped to [1, total_pages].
// PER_PAGE picked at 25 — readable on one screen on desktop, fits
// mobile after row stacking, and keeps the COUNT query irrelevant
// to total once a queue grows past a thousand flags.
$per_page = (int) apply_filters( 'jetonomy_moderation_per_page', 25 );
// Keep the raw ?paged= separate: $paged below is clamped to the FLAGS page
// count, and the Banned panel has its own, different total. Clamping the banned
// list with the flags ceiling pinned it to page 1 whenever there were fewer
// flags than restrictions - the rows past 25 stayed unreachable.
$jt_raw_paged = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged        = $jt_raw_paged;
$total        = Moderation_Service::count_pending_flags( $user_id );
$total_pages  = max( 1, (int) ceil( $total / $per_page ) );
if ( $paged > $total_pages ) {
	$paged = $total_pages;
}
$offset = ( $paged - 1 ) * $per_page;
$flags  = $total > 0
	? Moderation_Service::list_pending_flags( $user_id, null, $per_page, $offset )
	: [];

// The Banned tab needs a STRICTER cap than the page itself. The page admits
// anyone with can_view_any_queue() - which includes a space moderator who holds
// no site-wide cap - but the list it renders is global (every restriction on the
// site, not just their spaces) and every Lift button posts to
// DELETE /moderation/ban/{id}, which requires jetonomy_moderate. So a space mod
// was shown other spaces' restrictions and a Lift that could only ever 403.
// Mirror the REST cap exactly: no tab, no panel, no buttons that cannot work.
$jt_can_manage_bans = user_can( $user_id, 'jetonomy_moderate' );

// Which panel: the flags overview (default) or the banned-members list. The
// flags list is already scoped per-viewer by Moderation_Service; the banned
// list is not, hence the extra gate above.
$jt_view = sanitize_key( wp_unslash( $_GET['view'] ?? 'flags' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $jt_view, [ 'flags', 'approvals', 'banned' ], true ) ) {
	$jt_view = 'flags';
}
if ( 'banned' === $jt_view && ! $jt_can_manage_bans ) {
	$jt_view = 'flags';
}

// ── Awaiting approval ────────────────────────────────────────────────────
// The queue's second source. A flag is a member REPORTING published content;
// an approval-hold is the space refusing to publish at all
// (Base_Controller::should_hold_for_approval() writes status = 'pending' and
// creates no flag row). Because nothing lands in jt_flags, this page used to
// say "No pending flags anywhere" while held submissions piled up invisibly -
// approvable only from wp-admin, which a frontend-first community cannot ask
// a space moderator to open.
//
// Both counts run on every render, not just when the tab is open, because the
// badge has to be honest while the moderator is looking at the flags panel.
// They are two COUNT(*)s served by status_created (status, created_at), so the
// cost does not grow with the queue.
$jt_held_posts   = Moderation_Service::count_pending_approvals( $user_id, 'post' );
$jt_held_replies = Moderation_Service::count_pending_approvals( $user_id, 'reply' );
$jt_held_total   = $jt_held_posts + $jt_held_replies;

// Posts and replies are separate sub-tabs rather than one merged list: merging
// needs a UNION across two tables to paginate honestly, and wp-admin already
// splits them, so this keeps one query per model and one mental model on both
// surfaces.
$jt_kind = sanitize_key( wp_unslash( $_GET['kind'] ?? 'post' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $jt_kind, [ 'post', 'reply' ], true ) ) {
	$jt_kind = 'post';
}
$jt_held_count = 'reply' === $jt_kind ? $jt_held_replies : $jt_held_posts;
$jt_held_pages = max( 1, (int) ceil( $jt_held_count / $per_page ) );
$jt_held_paged = min( $jt_raw_paged, $jt_held_pages );
$jt_held       = 'approvals' === $jt_view && $jt_held_count > 0
	? Moderation_Service::list_pending_approvals( $user_id, $jt_kind, null, $per_page, ( $jt_held_paged - 1 ) * $per_page )
	: [];

// Batch-resolve everything the cards need: parent posts (replies only), spaces,
// and the WP user cache. Done here rather than inside approval-card.php so a
// full page of 25 costs three queries instead of seventy-five.
$jt_held_parents = [];
$jt_held_spaces  = [];
if ( $jt_held ) {
	if ( 'reply' === $jt_kind ) {
		$jt_held_parents = Post::list_by_ids( array_map( static fn( $r ) => (int) $r->post_id, $jt_held ) );
		$jt_space_ids    = array_map( static fn( $pp ) => (int) $pp->space_id, $jt_held_parents );
	} else {
		$jt_space_ids = array_map( static fn( $r ) => (int) $r->space_id, $jt_held );
	}
	foreach ( Space::list_by_ids( array_values( array_unique( $jt_space_ids ) ) ) as $jt_held_space ) {
		$jt_held_spaces[ (int) $jt_held_space->id ] = $jt_held_space;
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

// Active member restrictions - the SAME model the app's Banned-members screen
// and GET /moderation/ban read, so every surface stays in lockstep.
// Paginated: this used to take a flat limit=100, which silently truncated on any
// site with more than 100 active restrictions - the 101st ban was unliftable
// from the frontend because it never rendered.
$jt_ban_total = 'banned' === $jt_view ? \Jetonomy\Models\Restriction::count_active() : 0;
$jt_ban_pages = max( 1, (int) ceil( $jt_ban_total / $per_page ) );
$jt_ban_paged = min( $jt_raw_paged, $jt_ban_pages );
$jt_bans      = 'banned' === $jt_view && $jt_ban_total > 0
	? \Jetonomy\Models\Restriction::list_active(
		array(
			'limit'  => $per_page,
			'offset' => ( $jt_ban_paged - 1 ) * $per_page,
		)
	)
	: array();

// Batch-load the banned members in one query. The per-row get_userdata() below
// was an N+1: a full page of restrictions issued a query per row.
if ( $jt_bans ) {
	// Same batching as the reporter cache below: one query for every space
	// named by this page of restrictions, instead of a Space::find() per row.
	$jt_ban_spaces    = array();
	$jt_ban_space_ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) $r->space_id, $jt_bans ) ) ) );
	foreach ( \Jetonomy\Models\Space::list_by_ids( $jt_ban_space_ids ) as $jt_ban_space_row ) {
		$jt_ban_spaces[ (int) $jt_ban_space_row->id ] = $jt_ban_space_row;
	}

	$jt_ban_user_ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r->user_id, $jt_bans ) ) );
	if ( $jt_ban_user_ids ) {
		// Primes WP's user cache so get_userdata() below is served from memory.
		get_users(
			array(
				'include'     => $jt_ban_user_ids,
				'fields'      => 'all_with_meta',
				'number'      => count( $jt_ban_user_ids ),
				'count_total' => false,
			)
		);
	}
}
$jt_ban_types = array(
	'global_ban' => __( 'Banned', 'jetonomy' ),
	'space_ban'  => __( 'Space ban', 'jetonomy' ),
	'silence'    => __( 'Silenced', 'jetonomy' ),
);

// Reason → human label map. Source of truth for the badge text;
// matches the enum at class-moderation-controller.php:106.
$jt_reason_labels = [
	'spam'       => __( 'Spam', 'jetonomy' ),
	'offensive'  => __( 'Offensive', 'jetonomy' ),
	'off_topic'  => __( 'Off-topic', 'jetonomy' ),
	'harassment' => __( 'Harassment', 'jetonomy' ),
	'other'      => __( 'Other', 'jetonomy' ),
];

$crumbs = [
	[
		'label' => __( 'Moderation', 'jetonomy' ),
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-mod-wrap jt-mod-dashboard">
	<div class="jt-mod-dashboard-head">
		<div>
			<h1 class="jt-page-title">
				<?php esc_html_e( 'Moderation Overview', 'jetonomy' ); ?>
			</h1>
			<p class="jt-page-subtitle">
				<?php
				// The subtitle follows the open panel. Reporting the flag total
				// while the moderator is reading the approvals list would say
				// "0 pending flags" over a screen full of held submissions.
				if ( 'approvals' === $jt_view ) {
					/* translators: %d: number of submissions held for approval. */
					echo esc_html( sprintf( _n( '%d submission awaiting approval', '%d submissions awaiting approval', $jt_held_total, 'jetonomy' ), $jt_held_total ) );
				} elseif ( $is_admin ) {
					/* translators: %d: total pending flag count across every space */
					echo esc_html( sprintf( _n( '%d pending flag across your community', '%d pending flags across your community', $total, 'jetonomy' ), $total ) );
				} else {
					/* translators: %d: total pending flag count across the spaces this moderator owns */
					echo esc_html( sprintf( _n( '%d pending flag across the spaces you moderate', '%d pending flags across the spaces you moderate', $total, 'jetonomy' ), $total ) );
				}
				?>
			</p>
		</div>
		<?php if ( 'approvals' !== $jt_view && $total > 0 ) : ?>
			<span class="jt-badge-danger jt-flag-count" data-count="<?php echo esc_attr( (string) $total ); ?>">
				<?php
				/* translators: %d: number of pending flags. */
				echo esc_html( sprintf( _n( '%d pending', '%d pending', $total, 'jetonomy' ), $total ) );
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

	<?php // Tabs: Flags | Awaiting approval | Banned members. Reuses the profile tab styling. ?>
	<?php // Flags and Awaiting approval are always both reachable, so the strip always renders; Banned stays capability-gated. ?>
	<nav class="jt-profile-tabs" aria-label="<?php esc_attr_e( 'Moderation sections', 'jetonomy' ); ?>">
		<a href="<?php echo esc_url( $base . '/mod/' ); ?>" class="jt-profile-tab <?php echo 'flags' === $jt_view ? 'active' : ''; ?>" <?php echo 'flags' === $jt_view ? 'aria-current="page"' : ''; ?>>
			<?php esc_html_e( 'Flags', 'jetonomy' ); ?>
			<?php if ( $total > 0 ) : ?>
				<span class="jt-tab-count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'view', 'approvals', $base . '/mod/' ) ); ?>" class="jt-profile-tab <?php echo 'approvals' === $jt_view ? 'active' : ''; ?>" <?php echo 'approvals' === $jt_view ? 'aria-current="page"' : ''; ?>>
			<?php esc_html_e( 'Awaiting approval', 'jetonomy' ); ?>
			<?php if ( $jt_held_total > 0 ) : ?>
				<span class="jt-tab-count"><?php echo esc_html( number_format_i18n( $jt_held_total ) ); ?></span>
			<?php endif; ?>
		</a>
		<?php if ( $jt_can_manage_bans ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'banned', $base . '/mod/' ) ); ?>" class="jt-profile-tab <?php echo 'banned' === $jt_view ? 'active' : ''; ?>" <?php echo 'banned' === $jt_view ? 'aria-current="page"' : ''; ?>>
				<?php printf( /* translators: %s: plural member label. */ esc_html__( 'Banned %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?>
			</a>
		<?php endif; ?>
	</nav>

	<?php if ( 'banned' === $jt_view ) : ?>
		<?php if ( empty( $jt_bans ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'message' => sprintf( /* translators: %s: plural member label. */ __( 'No %s are currently banned or silenced.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ),
					'variant' => 'compact',
				]
			);
			?>
		<?php else : ?>
			<ul class="jt-mod-flag-list">
				<?php
				foreach ( $jt_bans as $jt_ban ) :
					$jt_banned_user = get_userdata( (int) $jt_ban->user_id );
					$jt_issuer      = (int) $jt_ban->issued_by ? get_userdata( (int) $jt_ban->issued_by ) : null;
					$jt_ban_label   = $jt_ban_types[ $jt_ban->type ] ?? $jt_ban_types['global_ban'];
					$jt_ban_age     = human_time_diff( strtotime( $jt_ban->created_at ), time() );
					$jt_ban_space   = $jt_ban->space_id ? ( $jt_ban_spaces[ (int) $jt_ban->space_id ] ?? null ) : null;
					?>
					<li class="jt-mod-flag-row">
						<div class="jt-mod-flag-row-head">
							<span class="jt-badge jt-badge-danger"><?php echo esc_html( $jt_ban_label ); ?></span>
							<a class="jt-mod-flag-space" href="<?php echo esc_url( $jt_banned_user ? \Jetonomy\get_profile_url( (int) $jt_banned_user->ID ) : '' ); ?>">
								<?php echo esc_html( $jt_banned_user ? \Jetonomy\user_display_name( $jt_banned_user ) : __( '[deleted]', 'jetonomy' ) ); ?>
							</a>
							<?php if ( $jt_ban_space ) : ?>
								<span class="jt-mod-flag-type"><?php echo esc_html( $jt_ban_space->title ); ?></span>
							<?php endif; ?>
							<span class="jt-mod-flag-age">
								<?php
								/* translators: 1: a person's display name; 2: human-readable time elapsed. */
								echo esc_html( sprintf( __( 'by %1$s · %2$s ago', 'jetonomy' ), $jt_issuer ? \Jetonomy\user_display_name( $jt_issuer ) : __( 'System', 'jetonomy' ), $jt_ban_age ) );
								?>
							</span>
						</div>
						<?php if ( ! empty( $jt_ban->reason ) ) : ?>
							<div class="jt-mod-flag-excerpt"><?php echo esc_html( (string) $jt_ban->reason ); ?></div>
						<?php endif; ?>
						<div class="jt-mod-flag-foot">
							<button type="button"
								class="jt-btn jt-btn-ghost jt-btn-sm jt-flex-shrink-0"
								data-wp-on--click="actions.liftRestriction"
								data-restriction-id="<?php echo absint( $jt_ban->id ); ?>"
								data-user-name="<?php echo esc_attr( $jt_banned_user ? \Jetonomy\user_display_name( $jt_banned_user ) : '' ); ?>">
								<?php jetonomy_echo_icon( 'user-check', 14 ); ?>
								<?php esc_html_e( 'Lift', 'jetonomy' ); ?>
							</button>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			// Same pagination contract as the flags queue below, so a site with
			// more than one page of restrictions can reach every one of them.
			\Jetonomy\Template_Loader::partial(
				'pagination-nav',
				[
					'paged' => $jt_ban_paged,
					'pages' => $jt_ban_pages,
					'base'  => add_query_arg( 'view', 'banned', $base . '/mod/' ),
					'label' => sprintf( /* translators: %s: plural member label. */ __( 'Banned %s pagination', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ),
				]
			);
			?>
		<?php endif; ?>

	<?php elseif ( 'approvals' === $jt_view ) : ?>
		<?php // Sub-tabs: posts and replies are counted and paginated separately. ?>
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
					$base . '/mod/'
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
			<div class="jt-card jt-card-flush" data-jt-mod-queue="approvals">
				<?php
				foreach ( $jt_held as $jt_item ) :
					$jt_parent        = 'reply' === $jt_kind
						? ( $jt_held_parents[ (int) $jt_item->post_id ] ?? null )
						: null;
					$jt_item_space_id = 'reply' === $jt_kind
						? (int) ( $jt_parent->space_id ?? 0 )
						: (int) ( $jt_item->space_id ?? 0 );
					$jt_item_space    = $jt_held_spaces[ $jt_item_space_id ] ?? null;
					// A held item whose space or parent post was deleted underneath
					// it has nowhere to link and no space route to act through.
					// Skipping is right: the row is unactionable, and the delete
					// cascade will clear it.
					if ( ! $jt_item_space || ( 'reply' === $jt_kind && ! $jt_parent ) ) {
						continue;
					}
					\Jetonomy\Template_Loader::partial(
						'moderation/approval-card',
						[
							'item'        => $jt_item,
							'kind'        => $jt_kind,
							'space'       => $jt_item_space,
							'parent_post' => $jt_parent,
							'base'        => $base,
						]
					);
				endforeach;
				?>
			</div>

			<?php
			\Jetonomy\Template_Loader::partial(
				'pagination-nav',
				[
					'paged' => $jt_held_paged,
					'pages' => $jt_held_pages,
					'base'  => add_query_arg(
						[
							'view' => 'approvals',
							'kind' => $jt_kind,
						],
						$base . '/mod/'
					),
					'label' => __( 'Awaiting approval pagination', 'jetonomy' ),
				]
			);
			?>
		<?php endif; ?>

	<?php elseif ( empty( $flags ) ) : ?>
		<?php
		$jt_empty_message = $is_admin
			? __( 'No pending flags anywhere. Your community is clean.', 'jetonomy' )
			: __( 'No pending flags in the spaces you moderate.', 'jetonomy' );
		\Jetonomy\Template_Loader::partial( 'moderation/queue-empty', [ 'message' => $jt_empty_message ] );
		?>
	<?php else : ?>
		<?php
		// One batched resolve for the whole page instead of four queries a row.
		$jt_flag_ctx = Moderation_Service::flag_context( $flags, $base );
		?>
		<ul class="jt-mod-flag-list">
			<?php
			foreach ( $flags as $flag ) :
				$is_reply = 'reply' === $flag->object_type;
				$jt_ctx   = $jt_flag_ctx[ $flag->object_type . ':' . (int) $flag->object_id ] ?? null;
				// Absent means the flagged object or its space has been deleted
				// out from under the flag - nothing to show and nothing to act on.
				if ( ! $jt_ctx ) {
					continue;
				}
				$obj           = $jt_ctx->object;
				$space         = $jt_ctx->space;
				$reporter      = get_userdata( (int) $flag->reporter_id );
				$reporter_name = $reporter ? \Jetonomy\user_display_name( $reporter ) : __( 'Unknown', 'jetonomy' );
				$age           = human_time_diff( strtotime( $flag->created_at ), time() );
				$content_plain = (string) ( $obj->content_plain ?? wp_strip_all_tags( (string) ( $obj->content ?? '' ) ) );
				$excerpt       = trim( mb_substr( $content_plain, 0, 140 ) );
				if ( mb_strlen( $content_plain ) > 140 ) {
					$excerpt .= '…';
				}
				$reason_key   = (string) ( $flag->reason ?? 'other' );
				$reason_label = $jt_reason_labels[ $reason_key ] ?? $jt_reason_labels['other'];
				$queue_url    = $base . '/s/' . $space->slug . '/mod/';
				?>
				<li class="jt-mod-flag-row">
					<div class="jt-mod-flag-row-head">
						<span class="jt-mod-flag-reason jt-mod-flag-reason--<?php echo esc_attr( $reason_key ); ?>">
							<?php echo esc_html( $reason_label ); ?>
						</span>
						<span class="jt-mod-flag-type">
							<?php echo $is_reply ? esc_html( \Jetonomy\jetonomy_label( 'reply' ) ) : esc_html__( 'Post', 'jetonomy' ); ?>
						</span>
						<a class="jt-mod-flag-space" href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>">
							<?php echo esc_html( $space->title ); ?>
						</a>
						<span class="jt-mod-flag-age">
							<?php
							/* translators: %s: human-readable time difference. */
							echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $age ) );
							?>
						</span>
					</div>
					<?php if ( ! $is_reply && ! empty( $obj->title ) ) : ?>
						<div class="jt-mod-flag-title"><?php echo esc_html( (string) $obj->title ); ?></div>
					<?php endif; ?>
					<div class="jt-mod-flag-excerpt">
						<?php echo esc_html( $excerpt ); ?>
					</div>
					<div class="jt-mod-flag-foot">
						<span class="jt-mod-flag-reporter">
							<?php
							/* translators: %s: reporter's display name */
							echo esc_html( sprintf( __( 'Reported by %s', 'jetonomy' ), $reporter_name ) );
							?>
						</span>
						<?php if ( ! empty( $flag->note ) ) : ?>
							<span class="jt-mod-flag-note" title="<?php echo esc_attr( (string) $flag->note ); ?>">
								<?php jetonomy_echo_icon( 'message-circle', 14 ); ?>
								<?php esc_html_e( 'Note', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<a class="jt-mod-flag-action" href="<?php echo esc_url( $queue_url ); ?>">
							<?php esc_html_e( 'Review in queue', 'jetonomy' ); ?>
							<?php jetonomy_echo_icon( 'arrow-right', 14 ); ?>
						</a>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php
		\Jetonomy\Template_Loader::partial(
			'pagination-nav',
			[
				'paged' => $paged,
				'pages' => $total_pages,
				'base'  => $base . '/mod/',
				'label' => __( 'Moderation queue pagination', 'jetonomy' ),
			]
		);
		?>
	<?php endif; ?>
</div>
