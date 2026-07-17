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
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
			'tone'      => 'warn',
		]
	);
	return;
}

// Visibility gate: the member roster of a private/hidden space is members-only.
// Mirror the main space view and the REST members endpoint, which both require
// read access before exposing any of a gated space's data. Runs BEFORE the
// roster queries so a non-member never triggers them.
if ( ! \Jetonomy\Permissions\Permission_Engine::can( get_current_user_id(), 'read', (int) $space->id ) ) {
	status_header( 403 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'    => 'lock',
			'message' => __( 'You need to be a member of this space to see its members.', 'jetonomy' ),
			'tone'    => 'forbidden',
		]
	);
	return;
}

// Pagination. 25/page is readable on desktop, fits mobile, keeps the
// COUNT(*) query trivial against the new space_role_joined index.
$jt_members_per_page = (int) apply_filters( 'jetonomy_space_members_per_page', 25 );
$jt_members_paged    = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
$viewer_is_priv = \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $viewer_id, (int) $space->id );

// Pending join requests, surfaced to space moderators/admins on the front-end
// (mirrors the wp-admin Join Requests tab). Capped to keep the panel and its
// COUNT cheap on approval-gated spaces with a large backlog; the wp-admin tab
// remains the full-history surface.
$jt_pending_requests = [];
$jt_pending_cap      = (int) apply_filters( 'jetonomy_space_pending_requests_shown', 50 );
if ( $viewer_is_priv ) {
	$jt_pending_all      = \Jetonomy\Models\JoinRequest::list_pending_for_space( (int) $space->id );
	$jt_pending_total    = count( $jt_pending_all );
	$jt_pending_requests = array_slice( $jt_pending_all, 0, $jt_pending_cap );
}

// Invite links, surfaced to space ADMINS on the front-end (mirrors the wp-admin
// Members tab). Space admin, not merely privileged: a token is a bearer
// credential into a space that may be hidden, so listing them is disclosing
// them. GET /spaces/{id}/invites applies the same rule server-side — this
// condition hides the panel, it does not secure it.
//
// This exists because 1.8.0 made `hidden` selectable on the front-end space
// forms, and a hidden space is forced onto the `invite` join policy
// (Space::save). Without this panel an owner could create a hidden space from
// the front-end and have no way to invite anyone into it without wp-admin.
$jt_invites = [];
if ( $viewer_is_sadm ) {
	$jt_invites = \Jetonomy\Models\InviteLink::list_by_space( (int) $space->id );
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

			<?php if ( $viewer_is_priv && ! empty( $jt_pending_requests ) ) : ?>
				<section class="jt-card jt-pending-requests" aria-label="<?php esc_attr_e( 'Pending join requests', 'jetonomy' ); ?>">
					<h2 class="jt-pending-requests-title">
						<?php esc_html_e( 'Pending join requests', 'jetonomy' ); ?>
						<span class="jt-badge-accent"><?php echo esc_html( number_format_i18n( (int) $jt_pending_total ) ); ?></span>
					</h2>
					<div class="jt-pending-list" data-jt-pending-list>
						<?php foreach ( $jt_pending_requests as $jt_req ) : ?>
							<?php
							$jt_req_uid  = (int) $jt_req->user_id;
							$jt_req_user = get_userdata( $jt_req_uid );
							$jt_req_prof = \Jetonomy\Models\UserProfile::find_by_user( $jt_req_uid );
							$jt_req_name = ( $jt_req_prof && ! empty( $jt_req_prof->display_name ) )
								? $jt_req_prof->display_name
								: ( $jt_req_user ? $jt_req_user->display_name : __( 'Unknown member', 'jetonomy' ) );
							?>
							<div class="jt-member-item jt-pending-item" data-jt-pending-row data-request-id="<?php echo absint( $jt_req->id ); ?>">
								<?php echo \Jetonomy\get_user_link( $jt_req_uid, 'jt-avatar-md', 36, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<div class="jt-flex-1">
									<a href="<?php echo esc_url( \Jetonomy\get_profile_url( $jt_req_uid ) ); ?>" class="jt-member-name"><?php echo esc_html( $jt_req_name ); ?></a>
									<?php if ( ! empty( $jt_req->message ) ) : ?>
										<div class="jt-pending-message"><?php echo esc_html( wp_trim_words( (string) $jt_req->message, 30 ) ); ?></div>
									<?php endif; ?>
								</div>
								<div class="jt-pending-actions">
									<button type="button" class="jt-btn jt-btn-sm jt-btn-primary"
										data-wp-on--click="actions.approveJoinRequest"
										data-space-id="<?php echo absint( $space->id ); ?>"
										data-request-id="<?php echo absint( $jt_req->id ); ?>">
										<?php esc_html_e( 'Approve', 'jetonomy' ); ?>
									</button>
									<button type="button" class="jt-btn jt-btn-sm jt-btn-ghost"
										data-wp-on--click="actions.denyJoinRequest"
										data-space-id="<?php echo absint( $space->id ); ?>"
										data-request-id="<?php echo absint( $jt_req->id ); ?>">
										<?php esc_html_e( 'Deny', 'jetonomy' ); ?>
									</button>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<?php if ( $jt_pending_total > count( $jt_pending_requests ) ) : ?>
						<p class="jt-form-help">
							<?php
							/* translators: 1: shown count, 2: total count */
							echo esc_html( sprintf( __( 'Showing %1$d of %2$d pending requests. Manage the rest from the admin Join Requests tab.', 'jetonomy' ), count( $jt_pending_requests ), (int) $jt_pending_total ) );
							?>
						</p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $viewer_is_sadm ) : ?>
				<section class="jt-card jt-invite-panel"
					aria-label="<?php esc_attr_e( 'Invite links', 'jetonomy' ); ?>"
					data-jt-invite-panel
					data-space-id="<?php echo absint( $space->id ); ?>"
					data-jt-uses-format="<?php echo esc_attr__( 'Uses: %1$s of %2$s', 'jetonomy' ); ?>"
					data-jt-uses-unlimited-format="<?php echo esc_attr__( 'Uses: %s', 'jetonomy' ); ?>"
					data-jt-no-expiry="<?php esc_attr_e( 'No expiry', 'jetonomy' ); ?>"
					data-jt-expires-format="<?php echo esc_attr__( 'Expires %s', 'jetonomy' ); ?>"
					data-jt-copy-label="<?php esc_attr_e( 'Copy', 'jetonomy' ); ?>"
					data-jt-copy-aria="<?php esc_attr_e( 'Copy invite link', 'jetonomy' ); ?>"
					data-jt-copied-label="<?php esc_attr_e( 'Copied', 'jetonomy' ); ?>"
					data-jt-copy-failed="<?php esc_attr_e( 'Press Ctrl+C to copy the selected link.', 'jetonomy' ); ?>"
					data-jt-revoke-label="<?php esc_attr_e( 'Revoke', 'jetonomy' ); ?>"
					data-jt-revoke-aria="<?php esc_attr_e( 'Revoke invite link', 'jetonomy' ); ?>"
					data-jt-revoke-title="<?php esc_attr_e( 'Revoke invite link', 'jetonomy' ); ?>"
					data-jt-revoke-body="<?php esc_attr_e( 'Revoke this invite link? Anyone still holding it will not be able to join, and the link cannot be restored.', 'jetonomy' ); ?>"
					data-jt-generate-failed="<?php esc_attr_e( 'Could not generate an invite link. Please try again.', 'jetonomy' ); ?>"
					data-jt-revoke-failed="<?php esc_attr_e( 'Could not revoke that link. Please try again.', 'jetonomy' ); ?>">

					<h2 class="jt-invite-panel-title"><?php esc_html_e( 'Invite links', 'jetonomy' ); ?></h2>

					<?php
					// The same honest note wp-admin carries. An invite link is not
					// coupled to the join policy — it admits its holder whatever the
					// policy is. Only "Invite only" makes a link the ONLY way in.
					if ( 'invite' !== ( $space->join_policy ?? 'open' ) ) :
						?>
						<p class="jt-form-help"><?php esc_html_e( "Invite links work with any join policy; set Join policy to 'Invite only' to require them.", 'jetonomy' ); ?></p>
					<?php endif; ?>

					<div class="jt-invite-form">
						<div class="jt-invite-field">
							<label class="jt-invite-label" for="jt-invite-max-uses"><?php esc_html_e( 'Max uses', 'jetonomy' ); ?></label>
							<input type="number" id="jt-invite-max-uses" class="jt-invite-input" data-jt-invite-max-uses min="0" step="1" value="0" inputmode="numeric">
						</div>
						<div class="jt-invite-field">
							<label class="jt-invite-label" for="jt-invite-expires"><?php esc_html_e( 'Expires', 'jetonomy' ); ?></label>
							<input type="date" id="jt-invite-expires" class="jt-invite-input" data-jt-invite-expires>
						</div>
						<button type="button" class="jt-btn jt-btn-fill jt-invite-generate"
							data-wp-on--click="actions.generateInvite">
							<?php esc_html_e( 'Generate invite link', 'jetonomy' ); ?>
						</button>
					</div>
					<p class="jt-form-help"><?php esc_html_e( 'Max uses 0 means unlimited. Leave Expires blank for no expiry.', 'jetonomy' ); ?></p>

					<p class="jt-invite-error" role="alert" data-jt-invite-error hidden></p>

					<ul class="jt-invite-list" data-jt-invite-list>
						<?php foreach ( $jt_invites as $jt_invite ) : ?>
							<?php
							$jt_invite_url = home_url(
								'/' . ( get_option( 'jetonomy_settings', [] )['base_slug'] ?? 'community' ) . '/invite/' . $jt_invite->token . '/'
							);
							$jt_invite_max = (int) $jt_invite->max_uses;
							$jt_invite_use = (int) $jt_invite->use_count;
							?>
							<li class="jt-invite-item" data-jt-invite-row data-invite-id="<?php echo absint( $jt_invite->id ); ?>">
								<div class="jt-invite-main">
									<code class="jt-invite-url"><?php echo esc_html( $jt_invite_url ); ?></code>
									<div class="jt-invite-meta">
										<span>
											<?php
											// "Uses: 1" rather than "1 use(s)" on purpose. A count
											// like this needs _n() to read correctly, and the JS that
											// renders a freshly generated row cannot reproduce _n()
											// (locales have up to six plural forms; mirroring that
											// client-side is a bug waiting to happen). A label +
											// number sidesteps plurals in every language, and matches
											// the "Uses" column the wp-admin table already uses.
											echo esc_html(
												$jt_invite_max > 0
													/* translators: 1: times used, 2: maximum uses */
													? sprintf( __( 'Uses: %1$s of %2$s', 'jetonomy' ), number_format_i18n( $jt_invite_use ), number_format_i18n( $jt_invite_max ) )
													/* translators: %s: times used */
													: sprintf( __( 'Uses: %s', 'jetonomy' ), number_format_i18n( $jt_invite_use ) )
											);
											?>
										</span>
										<span>
											<?php
											echo esc_html(
												$jt_invite->expires_at
													/* translators: %s: expiry date */
													? sprintf( __( 'Expires %s', 'jetonomy' ), date_i18n( get_option( 'date_format' ), strtotime( (string) $jt_invite->expires_at ) ) )
													: __( 'No expiry', 'jetonomy' )
											);
											?>
										</span>
										<?php if ( ! \Jetonomy\Models\InviteLink::is_valid( $jt_invite ) ) : ?>
											<span class="jt-invite-dead"><?php esc_html_e( 'No longer works', 'jetonomy' ); ?></span>
										<?php endif; ?>
									</div>
								</div>
								<div class="jt-invite-actions">
									<button type="button" class="jt-btn jt-btn-sm jt-btn-ghost"
										aria-label="<?php esc_attr_e( 'Copy invite link', 'jetonomy' ); ?>"
										data-jt-invite-url="<?php echo esc_url( $jt_invite_url ); ?>"
										data-wp-on--click="actions.copyInviteLink">
										<?php esc_html_e( 'Copy', 'jetonomy' ); ?>
									</button>
									<button type="button" class="jt-btn jt-btn-sm jt-btn-ghost jt-invite-revoke"
										aria-label="<?php esc_attr_e( 'Revoke invite link', 'jetonomy' ); ?>"
										data-wp-on--click="actions.revokeInvite">
										<?php esc_html_e( 'Revoke', 'jetonomy' ); ?>
									</button>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>

					<p class="jt-invite-empty" data-jt-invite-empty<?php echo empty( $jt_invites ) ? '' : ' hidden'; ?>>
						<?php
						/* translators: %s: space label, e.g. "space" */
						echo esc_html( sprintf( __( 'No invite links yet. Generate one to invite people straight into this %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) );
						?>
					</p>
				</section>
			<?php endif; ?>

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
							<?php
							// Trusted, fully-escaped plugin markup (incl. Lucide SVG avatar). Echo direct.
							echo \Jetonomy\get_user_link( (int) $member->user_id, 'jt-avatar-md', 36, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
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
									data-wp-on--change="actions.changeMemberRole"
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
									class="jt-btn jt-btn-sm jt-member-ban-btn" data-wp-on--click="actions.banMember"
									data-space-id="<?php echo absint( $space->id ); ?>"
									data-user-id="<?php echo absint( $member->user_id ); ?>"
									data-user-name="<?php echo esc_attr( $mu->display_name ); ?>">
									<?php echo esc_html( sprintf( __( 'Ban from %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
								</button>
							<?php endif; ?>
							<?php
							// Always show reputation, defaulting to 0 when the member
							// has no profile row yet — hiding it entirely read as
							// ambiguous (is it zero, or just missing?).
							?>
							<span class="jt-member-rep">
								<?php echo esc_html( $mp ? (int) $mp->reputation : 0 ); ?> <span class="jt-member-rep-label"><?php esc_html_e( 'rep', 'jetonomy' ); ?></span>
							</span>
						</div>
						<?php
						/**
						 * Fires after each member row in the space members list.
						 *
						 * Append a per-member badge, link, or action here. Fires
						 * after the row's closing element.
						 *
						 * @since 1.5.0
						 *
						 * @param object $member The space membership row (user_id, role, joined_at).
						 * @param object $space  The space being viewed.
						 */
						do_action( 'jetonomy_member_card_after', $member, $space );
						?>
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
