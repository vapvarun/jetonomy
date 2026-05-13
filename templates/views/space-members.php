<?php
/**
 * Space members view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$space_slug = $data['slug'] ?? '';
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Space not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

// Pagination. 25/page is readable on desktop, fits mobile, keeps the
// COUNT(*) query trivial against the new space_role_joined index.
$jt_members_per_page = (int) apply_filters( 'jetonomy_space_members_per_page', 25 );
$jt_members_paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$jt_members_total    = \Jetonomy\Models\SpaceMember::count_by_space( (int) $space->id );
$jt_members_pages    = max( 1, (int) ceil( $jt_members_total / $jt_members_per_page ) );
if ( $jt_members_paged > $jt_members_pages ) {
	$jt_members_paged = $jt_members_pages;
}
$jt_members_offset = ( $jt_members_paged - 1 ) * $jt_members_per_page;
$members           = $jt_members_total > 0
	? \Jetonomy\Models\SpaceMember::list_by_space( (int) $space->id, $jt_members_per_page, $jt_members_offset )
	: [];

// Batch-fetch WP_User + UserProfile for the page's members up front
// so the render loop reads from in-memory maps instead of firing two
// extra queries per row (N+1). At 25/page that's 50 fewer queries.
$jt_member_user_ids    = array_map( static fn( $m ) => (int) $m->user_id, $members );
$jt_member_users       = ! empty( $jt_member_user_ids )
	? get_users(
		array(
			'include' => $jt_member_user_ids,
			'orderby' => 'include',
		)
	)
	: array();
$jt_member_users_by_id = array();
foreach ( $jt_member_users as $jt_u ) {
	$jt_member_users_by_id[ (int) $jt_u->ID ] = $jt_u;
}
$category       = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$base           = \Jetonomy\base_url();
$viewer_id      = get_current_user_id();
$viewer_is_sadm = \Jetonomy\Permissions\Permission_Engine::is_space_admin( $viewer_id, (int) $space->id );

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
	'label' => __( 'Members', 'jetonomy' ),
	'url'   => '',
];

$role_labels = [
	'viewer'    => __( 'Viewer', 'jetonomy' ),
	'member'    => __( 'Member', 'jetonomy' ),
	'moderator' => __( 'Moderator', 'jetonomy' ),
	'admin'     => __( 'Admin', 'jetonomy' ),
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<div class="jt-cat-page-row">
				<?php jetonomy_render_space_icon( $space->icon ?? '', 24, 'jt-space-card-emoji', $space->type ?? '' ); ?>
				<div>
					<h1 class="jt-page-title jt-page-title-sm">
						<?php echo esc_html( $space->title ); ?> &mdash; <?php esc_html_e( 'Members', 'jetonomy' ); ?>
					</h1>
					<p class="jt-member-sub">
						<?php
						/* translators: %d: member count */
						echo esc_html( sprintf( _n( '%d member', '%d members', (int) $space->member_count, 'jetonomy' ), (int) $space->member_count ) );
						?>
					</p>
				</div>
			</div>

			<?php if ( empty( $members ) ) : ?>
				<?php
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					[
						'icon'    => 'empty-members',
						'message' => __( 'No members yet.', 'jetonomy' ),
					]
				);
				?>
			<?php else : ?>
				<div class="jt-card jt-card-flush">
					<?php foreach ( $members as $member ) : ?>
						<?php
						$mu = $jt_member_users_by_id[ (int) $member->user_id ] ?? null;
						if ( ! $mu ) {
							continue;
						}
						$mp         = \Jetonomy\Models\UserProfile::find_by_user( (int) $member->user_id );
						$trust      = $mp ? (int) $mp->trust_level : 0;
						$initials   = strtoupper( substr( $mu->display_name, 0, 2 ) );
						$joined     = date_i18n( get_option( 'date_format' ), strtotime( $member->joined_at ) );
						$role_label = $role_labels[ $member->role ] ?? $member->role;
						?>
						<div class="jt-member-item">
							<?php echo wp_kses_post( \Jetonomy\get_user_link( (int) $member->user_id, 'jt-avatar-md', 36, false ) ); ?>
							<div class="jt-flex-1">
								<a href="<?php echo esc_url( \Jetonomy\get_profile_url( (int) $member->user_id ) ); ?>"
									class="jt-member-name">
									<?php echo esc_html( $mu->display_name ); ?>
								</a>
								<?php
								// 1.4.1 byline cleanup: trust-level number removed
								// from inline bylines. Trust progress lives on the
								// user profile + hover-card surfaces.
								?>
								<div class="jt-member-joined">
									<?php
									/* translators: %s: joined date */
									echo esc_html( sprintf( __( 'Joined %s', 'jetonomy' ), $joined ) );
									?>
								</div>
							</div>
							<?php if ( in_array( $member->role, array( 'moderator', 'admin' ), true ) ) : ?>
								<span class="jt-badge-accent jt-member-badge">
									<?php echo esc_html( $role_label ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $viewer_is_sadm && (int) $member->user_id !== $viewer_id ) : ?>
								<label class="screen-reader-text" for="jt-member-role-<?php echo absint( $member->user_id ); ?>">
									<?php
									/* translators: %s: member display name */
									echo esc_html( sprintf( __( 'Change role for %s', 'jetonomy' ), $mu->display_name ) );
									?>
								</label>
								<select
									id="jt-member-role-<?php echo absint( $member->user_id ); ?>"
									class="jt-member-role-select"
									data-space-id="<?php echo absint( $space->id ); ?>"
									data-user-id="<?php echo absint( $member->user_id ); ?>"
									data-prev-role="<?php echo esc_attr( (string) $member->role ); ?>">
									<?php foreach ( array( 'member', 'moderator', 'admin' ) as $role_option ) : ?>
										<option value="<?php echo esc_attr( $role_option ); ?>" <?php selected( $member->role, $role_option ); ?>>
											<?php echo esc_html( $role_labels[ $role_option ] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php
								// 1.4.0 C.3 — front-end "Ban" affordance for space admins.
								// Issues a SPACE-scope ban via POST /moderation/ban (with
								// space_id), so the cap-vs-role gap that blocked space mods
								// is closed. Site-wide bans / silences stay cap-only.
								?>
								<button type="button"
									class="jt-btn jt-btn-sm jt-member-ban-btn"
									data-space-id="<?php echo absint( $space->id ); ?>"
									data-user-id="<?php echo absint( $member->user_id ); ?>"
									data-user-name="<?php echo esc_attr( $mu->display_name ); ?>">
									<?php esc_html_e( 'Ban from space', 'jetonomy' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( $mp ) : ?>
								<span class="jt-member-rep">
									<?php echo esc_html( (int) $mp->reputation ); ?> <span class="jt-member-rep-label"><?php esc_html_e( 'rep', 'jetonomy' ); ?></span>
								</span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<?php
				if ( $jt_members_pages > 1 ) :
					$jt_members_base_url = $base . '/s/' . $space->slug . '/members/';
					$jt_members_prev_url = add_query_arg( 'paged', max( 1, $jt_members_paged - 1 ), $jt_members_base_url );
					$jt_members_next_url = add_query_arg( 'paged', min( $jt_members_pages, $jt_members_paged + 1 ), $jt_members_base_url );
					?>
					<nav class="jt-pagination" aria-label="<?php esc_attr_e( 'Members pagination', 'jetonomy' ); ?>">
						<?php if ( $jt_members_paged > 1 ) : ?>
							<a class="jt-pagination-link" href="<?php echo esc_url( $jt_members_prev_url ); ?>" rel="prev">
								<?php jetonomy_echo_icon( 'chevron-left', 14 ); ?>
								<?php esc_html_e( 'Previous', 'jetonomy' ); ?>
							</a>
						<?php endif; ?>
						<span class="jt-pagination-status">
							<?php
							/* translators: 1: current page, 2: total pages */
							echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'jetonomy' ), $jt_members_paged, $jt_members_pages ) );
							?>
						</span>
						<?php if ( $jt_members_paged < $jt_members_pages ) : ?>
							<a class="jt-pagination-link" href="<?php echo esc_url( $jt_members_next_url ); ?>" rel="next">
								<?php esc_html_e( 'Next', 'jetonomy' ); ?>
								<?php jetonomy_echo_icon( 'chevron-right', 14 ); ?>
							</a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>
