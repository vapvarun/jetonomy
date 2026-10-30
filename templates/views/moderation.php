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
		array(
			'message' => __( 'You do not have permission to view this page.', 'jetonomy' ),
			'tone'    => 'forbidden',
		)
	);
	return;
}

// Pagination: ?paged=N from the URL, clamped to [1, total_pages].
// PER_PAGE picked at 25 — readable on one screen on desktop, fits
// mobile after row stacking, and keeps the COUNT query irrelevant
// to total once a queue grows past a thousand flags.
/**
 * Filters the number of flags per page on the moderation queue.
 *
 * @since 1.4.4
 *
 * @param int $per_page Flags per page. Default 25.
 * @return int Flags per page.
 */
$per_page = (int) apply_filters( 'jetonomy_moderation_per_page', 25 );
// Keep the raw ?paged= separate: $paged below is clamped to the FLAGS page
// count, and the Banned panel has its own, different total. Clamping the banned
// list with the flags ceiling pinned it to page 1 whenever there were fewer
// flags than restrictions - the rows past 25 stayed unreachable.
$jt_raw_paged = \Jetonomy\list_page(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged        = $jt_raw_paged;
$total        = Moderation_Service::count_pending_flags( $user_id );
$total_pages  = max( 1, (int) ceil( $total / $per_page ) );
if ( $paged > $total_pages ) {
	$paged = $total_pages;
}
$offset = ( $paged - 1 ) * $per_page;
$flags  = $total > 0
	? Moderation_Service::list_pending_flags( $user_id, null, $per_page, $offset )
	: array();

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
if ( ! in_array( $jt_view, array( 'flags', 'approvals', 'trash', 'banned' ), true ) ) {
	$jt_view = 'flags';
}
if ( 'banned' === $jt_view && ! $jt_can_manage_bans ) {
	$jt_view = 'flags';
}

// ── Awaiting approval ────────────────────────────────────────────────────
// The queue's second source. A flag is a member REPORTING published content;
// an approval-hold is the space refusing to publish at all
// (Moderation_Service::screen_new_content() writes status = 'pending' and
// creates no flag row). Nothing lands in jt_flags for a held submission, so this
// queue lists them separately; otherwise the page would say "No pending flags
// anywhere" while they pile up, approvable only from wp-admin, which a
// frontend-first community cannot ask a space moderator to open.
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
if ( ! in_array( $jt_kind, array( 'post', 'reply' ), true ) ) {
	$jt_kind = 'post';
}
// The Trash panel reuses the approvals list, card and pagination - same
// shape as the space queue. Its counts run only while it is open.
$jt_is_trash = 'trash' === $jt_view;
// Awaiting approval lists pending items by default; ?status=spam shows what the
// spam check or a moderator marked as spam, which no other frontend list reaches.
$jt_held_status  = 'approvals' === $jt_view && 'spam' === sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ) ? 'spam' : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$jt_spam_view    = 'spam' === $jt_held_status;
$jt_list_posts   = $jt_is_trash ? Moderation_Service::count_trashed( $user_id, 'post' ) : ( $jt_spam_view ? Moderation_Service::count_pending_approvals( $user_id, 'post', null, 'spam' ) : $jt_held_posts );
$jt_list_replies = $jt_is_trash ? Moderation_Service::count_trashed( $user_id, 'reply' ) : ( $jt_spam_view ? Moderation_Service::count_pending_approvals( $user_id, 'reply', null, 'spam' ) : $jt_held_replies );
// With no tab chosen, open on the one that holds items: Topics empty while
// Replies is not read as "nothing here".
if ( ! isset( $_GET['kind'] ) && 0 === $jt_list_posts && $jt_list_replies > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$jt_kind = 'reply';
}

$jt_held_count = 'reply' === $jt_kind ? $jt_list_replies : $jt_list_posts;
$jt_held_pages = max( 1, (int) ceil( $jt_held_count / $per_page ) );
$jt_held_paged = min( $jt_raw_paged, $jt_held_pages );
$jt_held       = array();
if ( in_array( $jt_view, array( 'approvals', 'trash' ), true ) && $jt_held_count > 0 ) {
	$jt_held = $jt_is_trash
		? Moderation_Service::list_trashed( $user_id, $jt_kind, null, $per_page, ( $jt_held_paged - 1 ) * $per_page )
		: Moderation_Service::list_pending_approvals( $user_id, $jt_kind, null, $per_page, ( $jt_held_paged - 1 ) * $per_page, $jt_held_status );
}

// Batch-resolve everything the cards need: parent posts (replies only), spaces,
// and the WP user cache. Done here rather than inside approval-card.php so a
// full page of 25 costs three queries instead of seventy-five.
$jt_held_parents = array();
$jt_held_spaces  = array();
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
			array(
				'include'     => $jt_held_authors,
				'fields'      => 'all_with_meta',
				'number'      => count( $jt_held_authors ),
				'count_total' => false,
			)
		);
	}
}

// Active member restrictions - the SAME model the app's Banned-members screen
// and GET /moderation/ban read, so every surface stays in lockstep.
// Paginated: a flat limit=100 would silently truncate on any site with more than 100
// active restrictions - the 101st ban would be unliftable from the frontend because it
// would never render.
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
	/* translators: %s: singular space label. */
	'space_ban'  => sprintf( __( '%s ban', 'jetonomy' ), \Jetonomy\space_label() ),
	'silence'    => __( 'Silenced', 'jetonomy' ),
	'ip_ban'     => __( 'IP address ban', 'jetonomy' ),
);

// Reason → human label map. Source of truth for the badge text;
// matches the enum at class-moderation-controller.php:106.
$jt_reason_labels = array(
	'spam'       => __( 'Spam', 'jetonomy' ),
	'offensive'  => __( 'Offensive', 'jetonomy' ),
	'off_topic'  => __( 'Off-topic', 'jetonomy' ), // jt-terms-ok: report reason idiom, not the noun.
	'harassment' => __( 'Harassment', 'jetonomy' ),
	'other'      => __( 'Other', 'jetonomy' ),
);

$crumbs = array(
	array(
		'label' => __( 'Moderation', 'jetonomy' ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::breadcrumb( $crumbs ); ?>

<?php
/*
 * Same two-column shell as every other community route.
 *
 * This view was the only one rendering a bare content column, so the moderation
 * screen dropped the sidebar entirely - the profile widget and the rest of the
 * community navigation simply vanished, and a moderator had no way back out
 * except the browser. The sidebar takes no arguments here, exactly as on the
 * home route; it is not space-scoped.
 */
?>
<div class="jt-two-col">
	<main>
		<?php \Jetonomy\Template_Loader::breadcrumb_in_main(); ?>

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
				if ( $jt_is_trash ) {
					$jt_trash_total = $jt_list_posts + $jt_list_replies;
					/* translators: %d: number of trashed posts and replies. */
					echo esc_html( sprintf( _n( '%d item in trash', '%d items in trash', $jt_trash_total, 'jetonomy' ), $jt_trash_total ) );
				} elseif ( 'approvals' === $jt_view ) {
					/* translators: %d: number of submissions held for approval. */
					echo esc_html( sprintf( _n( '%d submission awaiting approval', '%d submissions awaiting approval', $jt_held_total, 'jetonomy' ), $jt_held_total ) );
				} elseif ( $is_admin ) {
					/* translators: %d: total pending flag count across every space */
					echo esc_html( sprintf( _n( '%d pending flag across your community', '%d pending flags across your community', $total, 'jetonomy' ), $total ) );
				} else {
					/* translators: 1: total pending flag count; 2: plural space label. */
					echo esc_html( sprintf( _n( '%1$d pending flag across the %2$s you moderate', '%1$d pending flags across the %2$s you moderate', $total, 'jetonomy' ), $total, \Jetonomy\space_label( true, true ) ) );
				}
				?>
			</p>
		</div>
		<?php if ( 'flags' === $jt_view && $total > 0 ) : ?>
			<span class="jt-badge-danger jt-flag-count">
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
		<a href="<?php echo esc_url( \Jetonomy\route_url( 'moderation' ) ); ?>" class="jt-profile-tab <?php echo 'flags' === $jt_view ? 'active' : ''; ?>" <?php echo 'flags' === $jt_view ? 'aria-current="page"' : ''; ?>>
			<?php esc_html_e( 'Flags', 'jetonomy' ); ?>
			<?php if ( $total > 0 ) : ?>
				<span class="jt-tab-count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'view', 'approvals', \Jetonomy\route_url( 'moderation' ) ) ); ?>" class="jt-profile-tab <?php echo 'approvals' === $jt_view ? 'active' : ''; ?>" <?php echo 'approvals' === $jt_view ? 'aria-current="page"' : ''; ?>>
			<?php esc_html_e( 'Awaiting approval', 'jetonomy' ); ?>
			<?php if ( $jt_held_total > 0 ) : ?>
				<span class="jt-tab-count"><?php echo esc_html( number_format_i18n( $jt_held_total ) ); ?></span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'view', 'trash', \Jetonomy\route_url( 'moderation' ) ) ); ?>" class="jt-profile-tab <?php echo $jt_is_trash ? 'active' : ''; ?>" <?php echo $jt_is_trash ? 'aria-current="page"' : ''; ?>>
			<?php esc_html_e( 'Trash', 'jetonomy' ); ?>
		</a>
		<?php if ( $jt_can_manage_bans ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'banned', \Jetonomy\route_url( 'moderation' ) ) ); ?>" class="jt-profile-tab <?php echo 'banned' === $jt_view ? 'active' : ''; ?>" <?php echo 'banned' === $jt_view ? 'aria-current="page"' : ''; ?>>
				<?php printf( /* translators: %s: plural member label. */ esc_html__( 'Banned %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?>
			</a>
		<?php endif; ?>
	</nav>

	<?php if ( 'banned' === $jt_view ) : ?>
		<?php if ( empty( $jt_bans ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				array(
					'message' => sprintf( /* translators: %s: plural member label. */ __( 'No %s are currently banned or silenced.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ),
					'variant' => 'compact',
				)
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
					$jt_is_ip_ban   = 'ip_ban' === $jt_ban->type;
					?>
					<li class="jt-mod-flag-row">
						<div class="jt-mod-flag-row-head">
							<span class="jt-badge jt-badge-danger"><?php echo esc_html( $jt_ban_label ); ?></span>
							<?php if ( $jt_is_ip_ban ) : // An IP ban blocks an address, not the member it was issued from. ?>
								<code class="jt-mod-flag-space" id="<?php echo esc_attr( 'jt-ban-' . absint( $jt_ban->id ) . '-who' ); ?>"><?php echo esc_html( (string) $jt_ban->reason ); ?></code>
							<?php else : ?>
							<a class="jt-mod-flag-space" id="<?php echo esc_attr( 'jt-ban-' . absint( $jt_ban->id ) . '-who' ); ?>" href="<?php echo esc_url( $jt_banned_user ? \Jetonomy\get_profile_url( (int) $jt_banned_user->ID ) : '' ); ?>">
								<?php echo esc_html( $jt_banned_user ? \Jetonomy\user_display_name( $jt_banned_user ) : __( '[deleted]', 'jetonomy' ) ); ?>
							</a>
							<?php endif; ?>
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
						<?php if ( ! empty( $jt_ban->reason ) && ! $jt_is_ip_ban ) : ?>
							<div class="jt-mod-flag-excerpt"><?php echo esc_html( (string) $jt_ban->reason ); ?></div>
						<?php endif; ?>
						<div class="jt-mod-flag-foot">
							<?php if ( null === Moderation_Permissions::lift_error( $user_id, $jt_ban ) ) : // Lifting needs the authority that issued it. ?>
							<button type="button"
								class="jt-btn jt-btn-ghost jt-btn-sm jt-flex-shrink-0"
								data-wp-on--click="actions.liftRestriction"
								aria-describedby="<?php echo esc_attr( 'jt-ban-' . absint( $jt_ban->id ) . '-who' ); ?>"
								data-restriction-id="<?php echo absint( $jt_ban->id ); ?>"
								data-user-name="<?php echo esc_attr( $jt_is_ip_ban ? (string) $jt_ban->reason : ( $jt_banned_user ? \Jetonomy\user_display_name( $jt_banned_user ) : '' ) ); ?>">
								<?php jetonomy_echo_icon( 'user-check', 14 ); ?>
								<?php esc_html_e( 'Lift restriction', 'jetonomy' ); ?>
							</button>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			// Same pagination contract as the flags queue below, so a site with
			// more than one page of restrictions can reach every one of them.
			\Jetonomy\Template_Loader::partial(
				'pagination-nav',
				array(
					'paged' => $jt_ban_paged,
					'pages' => $jt_ban_pages,
					'base'  => add_query_arg( 'view', 'banned', \Jetonomy\route_url( 'moderation' ) ),
					'label' => sprintf( /* translators: %s: plural member label. */ __( 'Banned %s pagination', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ),
				)
			);
			?>
		<?php endif; ?>

	<?php elseif ( 'approvals' === $jt_view || $jt_is_trash ) : ?>
		<?php // Sub-tabs: posts and replies are counted and paginated separately. ?>
		<nav class="jt-subtabs" aria-label="<?php esc_attr_e( 'Content type', 'jetonomy' ); ?>">
			<?php
			$jt_kind_tabs = array(
				'post'  => array( \Jetonomy\jetonomy_label( 'topic', true ), $jt_list_posts ),
				'reply' => array( \Jetonomy\jetonomy_label( 'reply', true ), $jt_list_replies ),
			);
			foreach ( $jt_kind_tabs as $jt_kind_key => $jt_kind_meta ) :
				$jt_kind_url = add_query_arg(
					array(
						'view'   => $jt_view,
						'kind'   => $jt_kind_key,
						'status' => $jt_spam_view ? 'spam' : false,
					),
					\Jetonomy\route_url( 'moderation' )
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
		<?php if ( 'approvals' === $jt_view ) : ?>
		<form method="get" action="<?php echo esc_url( \Jetonomy\route_url( 'moderation' ) ); ?>" class="jt-held-filter">
			<input type="hidden" name="view" value="approvals">
			<input type="hidden" name="kind" value="<?php echo esc_attr( $jt_kind ); ?>">
			<label for="jt-held-status"><?php esc_html_e( 'Show', 'jetonomy' ); ?></label>
			<select id="jt-held-status" name="status" class="jt-select">
				<option value="pending" <?php selected( $jt_held_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'jetonomy' ); ?></option>
				<option value="spam" <?php selected( $jt_held_status, 'spam' ); ?>><?php esc_html_e( 'Spam', 'jetonomy' ); ?></option>
			</select>
			<button type="submit" class="jt-btn jt-btn-ghost jt-btn-sm"><?php esc_html_e( 'Apply', 'jetonomy' ); ?></button>
		</form>
		<?php endif; ?>

		<?php if ( empty( $jt_held ) ) : ?>
			<?php
			if ( $jt_is_trash ) {
				$jt_held_empty = 'reply' === $jt_kind
					? sprintf( /* translators: %s: plural label of the items in this tab (topics or replies). */ __( 'No %s in the trash.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ) )
					: sprintf( /* translators: %s: plural label of the items in this tab (topics or replies). */ __( 'No %s in the trash.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) );
			} elseif ( $jt_spam_view ) {
				$jt_held_empty = 'reply' === $jt_kind
					? sprintf( /* translators: %s: plural label of the items in this tab (topics or replies). */ __( 'No %s are marked as spam.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ) )
					: sprintf( /* translators: %s: plural label of the items in this tab (topics or replies). */ __( 'No %s are marked as spam.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) );
			} else {
				$jt_held_empty = 'reply' === $jt_kind
					? sprintf( /* translators: %s: plural label of the items in this tab (topics or replies). */ __( 'No %s are waiting for approval.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ) )
					: sprintf( /* translators: %s: plural label of the items in this tab (topics or replies). */ __( 'No %s are waiting for approval.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) );
			}
			\Jetonomy\Template_Loader::partial( 'moderation/queue-empty', array( 'message' => $jt_held_empty ) );
			?>
		<?php else : ?>
			<div class="jt-card jt-card-flush" data-jt-mod-queue="approvals"<?php echo $jt_is_trash ? ' data-empty-text="' . esc_attr__( 'The trash is empty.', 'jetonomy' ) . '"' : ''; ?>>
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
						array(
							'item'        => $jt_item,
							'kind'        => $jt_kind,
							'space'       => $jt_item_space,
							'parent_post' => $jt_parent,
							'base'        => $base,
							'mode'        => $jt_view,
						)
					);
				endforeach;
				?>
			</div>

			<?php
			\Jetonomy\Template_Loader::partial(
				'pagination-nav',
				array(
					'paged' => $jt_held_paged,
					'pages' => $jt_held_pages,
					'base'  => add_query_arg(
						array(
							'view'   => $jt_view,
							'kind'   => $jt_kind,
							'status' => $jt_spam_view ? 'spam' : false,
						),
						\Jetonomy\route_url( 'moderation' )
					),
					'label' => $jt_is_trash ? __( 'Trash pagination', 'jetonomy' ) : __( 'Awaiting approval pagination', 'jetonomy' ),
				)
			);
			?>
		<?php endif; ?>

	<?php elseif ( empty( $flags ) ) : ?>
		<?php
		$jt_empty_message = $is_admin
			? __( 'No pending flags anywhere. Your community is clean.', 'jetonomy' )
			/* translators: %s: plural space label. */
			: sprintf( __( 'No pending flags in the %s you moderate.', 'jetonomy' ), \Jetonomy\space_label( true, true ) );
		\Jetonomy\Template_Loader::partial( 'moderation/queue-empty', array( 'message' => $jt_empty_message ) );
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
				// A reported member has no space queue to review in, so the card
				// and its actions live here (only site moderators list them).
				if ( 'user' === $flag->object_type ) :
					?>
					<li class="jt-mod-flag-row jt-mod-flag-row--card">
						<?php
						\Jetonomy\Template_Loader::partial(
							'moderation/flag-card',
							array(
								'flag'             => $flag,
								'resolve_endpoint' => esc_url_raw( rest_url( 'jetonomy/v1/moderation/flags/' ) ),
								'base'             => $base,
								'context'          => $jt_ctx,
							)
						);
						?>
					</li>
					<?php
					continue;
				endif;
				$obj           = $jt_ctx->object;
				$space         = $jt_ctx->space;
				$reporter      = get_userdata( (int) $flag->reporter_id );
				$reporter_name = $reporter ? \Jetonomy\user_display_name( $reporter ) : __( 'Unknown', 'jetonomy' );
				$jt_reports    = (int) ( $jt_ctx->reports['count'] ?? 1 );
				if ( $jt_reports > 1 ) {
					/* translators: 1: newest reporter's display name; 2: number of other members who reported the same item. */
					$reporter_name = sprintf( _n( '%1$s and %2$s other', '%1$s and %2$s others', $jt_reports - 1, 'jetonomy' ), $reporter_name, number_format_i18n( $jt_reports - 1 ) );
				}
				$age           = human_time_diff( strtotime( $flag->created_at ), time() );
				$content_plain = (string) ( $obj->content_plain ?? wp_strip_all_tags( (string) ( $obj->content ?? '' ) ) );
				$excerpt       = trim( mb_substr( $content_plain, 0, 140 ) );
				if ( mb_strlen( $content_plain ) > 140 ) {
					$excerpt .= '…';
				}
				$reason_key   = (string) ( $flag->reason ?? 'other' );
				$reason_label = implode(
					', ',
					array_map(
						static fn( $key, $n ) => ( $jt_reason_labels[ $key ] ?? $jt_reason_labels['other'] ) . ( $n > 1 ? ' (' . number_format_i18n( (int) $n ) . ')' : '' ),
						array_keys( (array) ( $jt_ctx->reports['reasons'] ?? array( $reason_key => 1 ) ) ),
						(array) ( $jt_ctx->reports['reasons'] ?? array( $reason_key => 1 ) )
					)
				);
				$queue_url    = \Jetonomy\route_url( 'space-moderation', $space->slug );
				?>
				<li class="jt-mod-flag-row">
					<div class="jt-mod-flag-row-head">
						<span class="jt-mod-flag-reason jt-mod-flag-reason--<?php echo esc_attr( $reason_key ); ?>">
							<?php echo esc_html( $reason_label ); ?>
						</span>
						<span class="jt-mod-flag-type">
							<?php echo $is_reply ? esc_html( \Jetonomy\jetonomy_label( 'reply' ) ) : esc_html( \Jetonomy\jetonomy_label( 'topic' ) ); ?>
						</span>
						<a class="jt-mod-flag-space" href="<?php echo esc_url( \Jetonomy\route_url( 'space', $space->slug ) ); ?>">
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
						<div class="jt-mod-flag-title" dir="auto"><?php echo esc_html( (string) $obj->title ); ?></div>
					<?php endif; ?>
					<div class="jt-mod-flag-excerpt" dir="auto">
						<?php echo esc_html( $excerpt ); ?>
					</div>
					<?php if ( ! empty( $jt_ctx->removed ) ) : ?>
						<p class="jt-mod-flag-removed">
							<?php
							/* translators: %s: singular topic or reply label, lowercase. */
							echo esc_html( sprintf( __( 'This %s was already removed. Dismiss the report to clear it.', 'jetonomy' ), \Jetonomy\jetonomy_label( $is_reply ? 'reply' : 'topic', false, true ) ) );
							?>
						</p>
					<?php endif; ?>
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
			array(
				'paged' => $paged,
				'pages' => $total_pages,
				'base'  => \Jetonomy\route_url( 'moderation' ),
				'label' => __( 'Moderation queue pagination', 'jetonomy' ),
			)
		);
		?>
	<?php endif; ?>
</div>

	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
</div>
