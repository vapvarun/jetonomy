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
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- jetonomy_icon() returns trusted SVG
	echo '<div class="jt-empty"><div class="jt-empty-icon">' . jetonomy_icon( 'search', 48 ) . '</div><div class="jt-empty-text">' . esc_html__( 'Space not found.', 'jetonomy' ) . '</div></div>';
	return;
}

$members        = \Jetonomy\Models\SpaceMember::list_by_space( (int) $space->id );
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
				<?php if ( ! empty( $space->icon ) ) : ?>
					<span class="jt-space-card-emoji"><?php echo esc_html( $space->icon ); ?></span>
				<?php endif; ?>
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
				<div class="jt-empty">
					<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'empty-members', 80 ); ?></div>
					<div class="jt-empty-text"><?php esc_html_e( 'No members yet.', 'jetonomy' ); ?></div>
				</div>
			<?php else : ?>
				<div class="jt-card jt-card-flush">
					<?php foreach ( $members as $member ) : ?>
						<?php
						$mu = get_userdata( (int) $member->user_id );
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
								<span class="jt-tl" data-jt-tl="<?php echo esc_attr( (string) $trust ); ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo esc_html( (int) $trust ); ?></span>
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
							<?php endif; ?>
							<?php if ( $mp ) : ?>
								<span class="jt-member-rep">
									<?php echo esc_html( (int) $mp->reputation ); ?> <span class="jt-member-rep-label"><?php esc_html_e( 'rep', 'jetonomy' ); ?></span>
								</span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>
