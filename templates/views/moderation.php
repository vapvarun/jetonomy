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
$per_page    = (int) apply_filters( 'jetonomy_moderation_per_page', 25 );
$paged       = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$total       = Moderation_Service::count_pending_flags( $user_id );
$total_pages = max( 1, (int) ceil( $total / $per_page ) );
if ( $paged > $total_pages ) {
	$paged = $total_pages;
}
$offset = ( $paged - 1 ) * $per_page;
$flags  = $total > 0
	? Moderation_Service::list_pending_flags( $user_id, null, $per_page, $offset )
	: [];

// Which panel: the flags overview (default) or the banned-members list. Both are
// gated by the same moderator check above. The Banned tab lets a moderator see
// who is restricted and lift it - the frontend home for unbanning, since
// moderators cannot reach wp-admin.
$jt_view = sanitize_key( wp_unslash( $_GET['view'] ?? 'flags' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( 'banned' !== $jt_view ) {
	$jt_view = 'flags';
}
// Active member restrictions - the SAME model the app's Banned-members screen
// and GET /moderation/ban read, so every surface stays in lockstep.
$jt_bans      = 'banned' === $jt_view
	? \Jetonomy\Models\Restriction::list_active( array( 'limit' => 100 ) )
	: array();
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
			<p class="jt-member-sub">
				<?php
				if ( $is_admin ) {
					/* translators: %d: total pending flag count across every space */
					echo esc_html( sprintf( _n( '%d pending flag across your community', '%d pending flags across your community', $total, 'jetonomy' ), $total ) );
				} else {
					/* translators: %d: total pending flag count across the spaces this moderator owns */
					echo esc_html( sprintf( _n( '%d pending flag across the spaces you moderate', '%d pending flags across the spaces you moderate', $total, 'jetonomy' ), $total ) );
				}
				?>
			</p>
		</div>
		<?php if ( $total > 0 ) : ?>
			<span class="jt-badge-danger jt-flag-count" data-count="<?php echo esc_attr( (string) $total ); ?>">
				<?php
				/* translators: %d: total pending flag count */
				echo esc_html( sprintf( _n( '%d pending', '%d pending', $total, 'jetonomy' ), $total ) );
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php // Tabs: Flags overview | Banned members. Reuses the profile tab styling. ?>
	<div class="jt-profile-tabs">
		<a href="<?php echo esc_url( $base . '/mod/' ); ?>" class="jt-profile-tab <?php echo 'flags' === $jt_view ? 'active' : ''; ?>">
			<?php esc_html_e( 'Flags', 'jetonomy' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'view', 'banned', $base . '/mod/' ) ); ?>" class="jt-profile-tab <?php echo 'banned' === $jt_view ? 'active' : ''; ?>">
			<?php esc_html_e( 'Banned members', 'jetonomy' ); ?>
		</a>
	</div>

	<?php if ( 'banned' === $jt_view ) : ?>
		<?php if ( empty( $jt_bans ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'message' => __( 'No members are currently banned or silenced.', 'jetonomy' ),
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
					$jt_ban_space   = $jt_ban->space_id ? \Jetonomy\Models\Space::find( (int) $jt_ban->space_id ) : null;
					?>
					<li class="jt-mod-flag-row">
						<div class="jt-mod-flag-row-head">
							<span class="jt-badge jt-badge-danger"><?php echo esc_html( $jt_ban_label ); ?></span>
							<a class="jt-mod-flag-space" href="<?php echo esc_url( $base . '/u/' . ( $jt_banned_user ? $jt_banned_user->user_login : '' ) . '/' ); ?>">
								<?php echo esc_html( $jt_banned_user ? $jt_banned_user->display_name : __( '[deleted]', 'jetonomy' ) ); ?>
							</a>
							<?php if ( $jt_ban_space ) : ?>
								<span class="jt-mod-flag-type"><?php echo esc_html( $jt_ban_space->title ); ?></span>
							<?php endif; ?>
							<span class="jt-mod-flag-age">
								<?php
								/* translators: 1: issuing moderator name, 2: human-readable time since the restriction */
								echo esc_html( sprintf( __( 'by %1$s · %2$s ago', 'jetonomy' ), $jt_issuer ? $jt_issuer->display_name : __( 'System', 'jetonomy' ), $jt_ban_age ) );
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
								data-user-name="<?php echo esc_attr( $jt_banned_user ? $jt_banned_user->display_name : '' ); ?>">
								<?php jetonomy_echo_icon( 'user-check', 14 ); ?>
								<?php esc_html_e( 'Lift', 'jetonomy' ); ?>
							</button>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

	<?php elseif ( empty( $flags ) ) : ?>
		<?php
		$jt_empty_message = $is_admin
			? __( 'No pending flags anywhere. Your community is clean.', 'jetonomy' )
			: __( 'No pending flags in the spaces you moderate.', 'jetonomy' );
		\Jetonomy\Template_Loader::partial( 'moderation/queue-empty', [ 'message' => $jt_empty_message ] );
		?>
	<?php else : ?>
		<ul class="jt-mod-flag-list">
			<?php
			foreach ( $flags as $flag ) :
				$is_reply = 'reply' === $flag->object_type;
				$obj      = $is_reply ? Reply::find( (int) $flag->object_id ) : Post::find( (int) $flag->object_id );
				if ( ! $obj ) {
					continue;
				}
				$space_id = $is_reply
					? (int) ( Post::find( (int) $obj->post_id )->space_id ?? 0 )
					: (int) ( $obj->space_id ?? 0 );
				$space    = $space_id ? Space::find( $space_id ) : null;
				if ( ! $space ) {
					continue;
				}
				$reporter      = get_userdata( (int) $flag->reporter_id );
				$reporter_name = $reporter ? $reporter->display_name : __( 'Unknown', 'jetonomy' );
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
							<?php echo $is_reply ? esc_html__( 'Reply', 'jetonomy' ) : esc_html__( 'Post', 'jetonomy' ); ?>
						</span>
						<a class="jt-mod-flag-space" href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>">
							<?php echo esc_html( $space->title ); ?>
						</a>
						<span class="jt-mod-flag-age">
							<?php
							/* translators: %s: human-readable time since flag was filed */
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
		if ( $total_pages > 1 ) :
			$jt_base_url = $base . '/mod/';
			$jt_prev_url = add_query_arg( 'paged', max( 1, $paged - 1 ), $jt_base_url );
			$jt_next_url = add_query_arg( 'paged', min( $total_pages, $paged + 1 ), $jt_base_url );
			?>
			<nav class="jt-pagination" aria-label="<?php esc_attr_e( 'Moderation queue pagination', 'jetonomy' ); ?>">
				<?php if ( $paged > 1 ) : ?>
					<a class="jt-pagination-link" href="<?php echo esc_url( $jt_prev_url ); ?>" rel="prev">
						<?php jetonomy_echo_icon( 'chevron-left', 14 ); ?>
						<?php esc_html_e( 'Previous', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
				<span class="jt-pagination-status">
					<?php
					/* translators: 1: current page, 2: total pages */
					echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'jetonomy' ), $paged, $total_pages ) );
					?>
				</span>
				<?php if ( $paged < $total_pages ) : ?>
					<a class="jt-pagination-link" href="<?php echo esc_url( $jt_next_url ); ?>" rel="next">
						<?php esc_html_e( 'Next', 'jetonomy' ); ?>
						<?php jetonomy_echo_icon( 'chevron-right', 14 ); ?>
					</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
