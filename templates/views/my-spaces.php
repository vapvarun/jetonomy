<?php
/**
 * My spaces view (G7).
 *
 * Stacked sections: "Spaces I run" (admin / moderator) above "Spaces I'm
 * in" (member only). Empty sections hide so the page never shows a hollow
 * heading. Auth-required — Template_Loader::render() redirects guests to
 * login before this template ever runs.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$user_id = get_current_user_id();
$base    = \Jetonomy\base_url();

// One indexed query per role bucket. Hydrating Space rows happens once
// per id; nothing N+1 here even on a user who runs 50 spaces.
$privileged_ids = \Jetonomy\Models\SpaceMember::spaces_for_user( $user_id, 'privileged' );
$member_ids     = array_values(
	array_diff(
		\Jetonomy\Models\SpaceMember::spaces_for_user( $user_id, 'member' ),
		$privileged_ids
	)
);

$privileged_spaces = array_filter(
	array_map(
		static fn( $id ) => \Jetonomy\Models\Space::find( (int) $id ),
		$privileged_ids
	)
);
$member_spaces     = array_filter(
	array_map(
		static fn( $id ) => \Jetonomy\Models\Space::find( (int) $id ),
		$member_ids
	)
);

// Warm role-label cache for the privileged list so each card row gets
// its admin / mod pill in O(1).
if ( ! empty( $privileged_spaces ) ) {
	foreach ( $privileged_spaces as $sp ) {
		\Jetonomy\Models\SpaceMember::warm_role_cache( (int) $sp->id, array( $user_id ) );
	}
}

$crumbs = array(
	array(
		'label' => __( 'My Spaces', 'jetonomy' ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<h1 class="jt-page-title jt-mb-20">
			<?php esc_html_e( 'My Spaces', 'jetonomy' ); ?>
		</h1>

		<?php if ( empty( $privileged_spaces ) && empty( $member_spaces ) ) : ?>
			<div class="jt-empty">
				<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'users', 80 ); ?></div>
				<div class="jt-empty-text">
					<?php esc_html_e( 'You are not in any spaces yet.', 'jetonomy' ); ?>
				</div>
				<p class="jt-empty-cta">
					<a class="jt-btn jt-btn-fill" href="<?php echo esc_url( $base . '/' ); ?>">
						<?php esc_html_e( 'Browse spaces', 'jetonomy' ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $privileged_spaces ) ) : ?>
			<section class="jt-my-spaces-section">
				<h2 class="jt-section-title">
					<?php esc_html_e( 'Spaces I run', 'jetonomy' ); ?>
				</h2>
				<ul class="jt-space-list">
					<?php foreach ( $privileged_spaces as $sp ) : ?>
						<?php
						$role  = \Jetonomy\Models\SpaceMember::role_label( (int) $sp->id, $user_id );
						$label = ( 'admin' === $role )
							? __( 'Admin', 'jetonomy' )
							: __( 'Mod', 'jetonomy' );
						?>
						<li class="jt-space-card">
							<a class="jt-space-card-link" href="<?php echo esc_url( $base . '/s/' . $sp->slug . '/' ); ?>">
								<?php if ( ! empty( $sp->icon ) ) : ?>
									<?php if ( 0 === strpos( $sp->icon, 'dashicons-' ) ) : ?>
										<span class="jt-space-card-icon dashicons <?php echo esc_attr( $sp->icon ); ?>"></span>
									<?php else : ?>
										<span class="jt-space-card-icon"><?php echo esc_html( $sp->icon ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
								<span class="jt-space-card-body">
									<span class="jt-space-card-title">
										<?php echo esc_html( $sp->title ); ?>
										<?php if ( null !== $role ) : ?>
											<span class="jt-role-pill jt-role-pill--<?php echo esc_attr( $role ); ?>">
												<?php echo esc_html( $label ); ?>
											</span>
										<?php endif; ?>
									</span>
									<?php if ( ! empty( $sp->description ) ) : ?>
										<span class="jt-space-card-desc">
											<?php echo esc_html( wp_html_excerpt( $sp->description, 120, '…' ) ); ?>
										</span>
									<?php endif; ?>
									<span class="jt-space-card-stats">
										<?php
										/* translators: %d: post count */
										echo esc_html( sprintf( _n( '%d post', '%d posts', (int) $sp->post_count, 'jetonomy' ), (int) $sp->post_count ) );
										?>
										·
										<?php
										/* translators: %d: member count */
										echo esc_html( sprintf( _n( '%d member', '%d members', (int) $sp->member_count, 'jetonomy' ), (int) $sp->member_count ) );
										?>
									</span>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $member_spaces ) ) : ?>
			<section class="jt-my-spaces-section">
				<h2 class="jt-section-title">
					<?php esc_html_e( "Spaces I'm in", 'jetonomy' ); ?>
				</h2>
				<ul class="jt-space-list">
					<?php foreach ( $member_spaces as $sp ) : ?>
						<li class="jt-space-card">
							<a class="jt-space-card-link" href="<?php echo esc_url( $base . '/s/' . $sp->slug . '/' ); ?>">
								<?php if ( ! empty( $sp->icon ) ) : ?>
									<?php if ( 0 === strpos( $sp->icon, 'dashicons-' ) ) : ?>
										<span class="jt-space-card-icon dashicons <?php echo esc_attr( $sp->icon ); ?>"></span>
									<?php else : ?>
										<span class="jt-space-card-icon"><?php echo esc_html( $sp->icon ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
								<span class="jt-space-card-body">
									<span class="jt-space-card-title">
										<?php echo esc_html( $sp->title ); ?>
									</span>
									<?php if ( ! empty( $sp->description ) ) : ?>
										<span class="jt-space-card-desc">
											<?php echo esc_html( wp_html_excerpt( $sp->description, 120, '…' ) ); ?>
										</span>
									<?php endif; ?>
									<span class="jt-space-card-stats">
										<?php
										/* translators: %d: post count */
										echo esc_html( sprintf( _n( '%d post', '%d posts', (int) $sp->post_count, 'jetonomy' ), (int) $sp->post_count ) );
										?>
										·
										<?php
										/* translators: %d: member count */
										echo esc_html( sprintf( _n( '%d member', '%d members', (int) $sp->member_count, 'jetonomy' ), (int) $sp->member_count ) );
										?>
									</span>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) ); ?>
</div>
