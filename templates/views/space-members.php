<?php
defined( 'ABSPATH' ) || exit;

$space_slug = $data['slug'] ?? '';
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space ) {
	status_header( 404 );
	echo '<div class="jt-container"><div class="jt-empty"><div class="jt-empty-icon">&#128483;</div><div class="jt-empty-text">' . esc_html__( 'Space not found.', 'jetonomy' ) . '</div></div></div>';
	return;
}

$members  = \Jetonomy\Models\SpaceMember::list_by_space( (int) $space->id );
$category = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$base     = home_url( '/community' );

$crumbs = [];
if ( $category ) {
	$crumbs[] = [ 'label' => $category->name, 'url' => '' ];
}
$crumbs[] = [ 'label' => $space->title, 'url' => $base . '/s/' . $space->slug . '/' ];
$crumbs[] = [ 'label' => __( 'Members', 'jetonomy' ), 'url' => '' ];

$role_labels = [
	'moderator' => __( 'Moderator', 'jetonomy' ),
	'member'    => __( 'Member', 'jetonomy' ),
];
?>
<div class="jt-container">

	<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

	<div class="jt-two-col">
		<main>
			<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
				<?php if ( ! empty( $space->emoji ) ) : ?>
					<span style="font-size:24px;"><?php echo esc_html( $space->emoji ); ?></span>
				<?php endif; ?>
				<div>
					<h1 style="font-family:var(--jt-font-heading);font-size:20px;font-weight:700;margin:0;">
						<?php echo esc_html( $space->title ); ?> &mdash; <?php esc_html_e( 'Members', 'jetonomy' ); ?>
					</h1>
					<p style="color:var(--jt-text-tertiary);font-size:13px;margin-top:2px;">
						<?php
						/* translators: %d: member count */
						echo esc_html( sprintf( _n( '%d member', '%d members', (int) $space->member_count, 'jetonomy' ), (int) $space->member_count ) );
						?>
					</p>
				</div>
			</div>

			<?php if ( empty( $members ) ) : ?>
				<div class="jt-empty">
					<div class="jt-empty-icon">&#128100;</div>
					<div class="jt-empty-text"><?php esc_html_e( 'No members yet.', 'jetonomy' ); ?></div>
				</div>
			<?php else : ?>
				<div class="jt-card" style="padding:0;overflow:hidden;">
					<?php foreach ( $members as $member ) : ?>
						<?php
						$mu = get_userdata( (int) $member->user_id );
						if ( ! $mu ) {
							continue;
						}
						$mp       = \Jetonomy\Models\UserProfile::find_by_user( (int) $member->user_id );
						$trust    = $mp ? (int) $mp->trust_level : 0;
						$initials = strtoupper( substr( $mu->display_name, 0, 2 ) );
						$joined   = date_i18n( get_option( 'date_format' ), strtotime( $member->joined_at ) );
						$role_label = $role_labels[ $member->role ] ?? $member->role;
						?>
						<div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--jt-border);">
							<a href="<?php echo esc_url( $base . '/u/' . $mu->user_login . '/' ); ?>">
								<span class="jt-avatar jt-avatar-md"><?php echo esc_html( $initials ); ?></span>
							</a>
							<div style="flex:1;min-width:0;">
								<a href="<?php echo esc_url( $base . '/u/' . $mu->user_login . '/' ); ?>"
									style="font-weight:600;font-size:14px;color:var(--jt-text);">
									<?php echo esc_html( $mu->display_name ); ?>
								</a>
								<span class="jt-tl" style="background:var(--jt-tl<?php echo $trust; ?>);margin-left:6px;" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo $trust; ?></span>
								<div style="font-size:12px;color:var(--jt-text-tertiary);margin-top:2px;">
									<?php
									/* translators: %s: joined date */
									echo esc_html( sprintf( __( 'Joined %s', 'jetonomy' ), $joined ) );
									?>
								</div>
							</div>
							<?php if ( 'moderator' === $member->role ) : ?>
								<span style="font-size:11px;font-weight:600;background:var(--jt-accent-muted);color:var(--jt-accent);padding:2px 8px;border-radius:var(--jt-radius-full);">
									<?php echo esc_html( $role_label ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $mp ) : ?>
								<span style="font-family:var(--jt-font-mono);font-size:12px;font-weight:600;color:var(--jt-accent);">
									<?php echo (int) $mp->reputation; ?> <span style="font-weight:400;color:var(--jt-text-tertiary);"><?php esc_html_e( 'rep', 'jetonomy' ); ?></span>
								</span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>

</div>
