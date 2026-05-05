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
		<header class="jt-page-head">
			<h1 class="jt-page-title">
				<?php esc_html_e( 'My Spaces', 'jetonomy' ); ?>
			</h1>
			<p class="jt-page-subtitle">
				<?php esc_html_e( 'Spaces you run, plus the ones you are part of.', 'jetonomy' ); ?>
			</p>
		</header>

		<?php if ( empty( $privileged_spaces ) && empty( $member_spaces ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'      => 'users',
					'message'   => __( 'You are not in any spaces yet.', 'jetonomy' ),
					'cta_label' => __( 'Browse spaces', 'jetonomy' ),
					'cta_url'   => $base . '/',
				]
			);
			?>
		<?php endif; ?>

		<?php
		// One H1 ("My Spaces") + the per-card ADMIN / MOD pill is enough
		// to tell the viewer which spaces they run vs are just in. The
		// section eyebrows that used to live here read as a third
		// heading layer saying the same thing — dropped.
		?>

		<?php if ( ! empty( $privileged_spaces ) ) : ?>
			<section class="jt-my-spaces-section">
				<ul class="jt-space-list">
					<?php foreach ( $privileged_spaces as $sp ) : ?>
						<?php
						$role  = \Jetonomy\Models\SpaceMember::role_label( (int) $sp->id, $user_id );
						$label = ( 'admin' === $role ) ? __( 'Admin', 'jetonomy' ) : __( 'Mod', 'jetonomy' );
						?>
						<li class="jt-space-card jt-space-card--privileged">
							<a class="jt-space-card-link" href="<?php echo esc_url( $base . '/s/' . $sp->slug . '/' ); ?>">
								<div class="jt-space-card-head">
									<?php jetonomy_render_space_icon( $sp->icon ?? '', 24, 'jt-space-card-icon', $sp->type ?? '' ); ?>
									<div class="jt-space-card-titlewrap">
										<h3 class="jt-space-card-title"><?php echo esc_html( $sp->title ); ?></h3>
										<?php if ( null !== $role ) : ?>
											<span class="jt-role-pill jt-role-pill--<?php echo esc_attr( $role ); ?>">
												<?php echo esc_html( $label ); ?>
											</span>
										<?php endif; ?>
									</div>
								</div>
								<?php if ( ! empty( $sp->description ) ) : ?>
									<p class="jt-space-card-desc">
										<?php echo esc_html( wp_html_excerpt( $sp->description, 120, '…' ) ); ?>
									</p>
								<?php endif; ?>
								<p class="jt-space-card-meta">
									<?php
									/* translators: %d: post count */
									echo esc_html( sprintf( _n( '%d post', '%d posts', (int) $sp->post_count, 'jetonomy' ), (int) $sp->post_count ) );
									?>
									·
									<?php
									/* translators: %d: member count */
									echo esc_html( sprintf( _n( '%d member', '%d members', (int) $sp->member_count, 'jetonomy' ), (int) $sp->member_count ) );
									?>
								</p>
							</a>
							<div class="jt-space-card-actions">
								<?php if ( 'admin' === $role ) : ?>
									<a class="jt-space-card-action" href="<?php echo esc_url( \Jetonomy\get_space_edit_url( $sp ) ); ?>">
										<?php jetonomy_echo_icon( 'edit', 14 ); ?>
										<span><?php esc_html_e( 'Edit', 'jetonomy' ); ?></span>
									</a>
								<?php endif; ?>
								<a class="jt-space-card-action" href="<?php echo esc_url( $base . '/s/' . $sp->slug . '/mod/' ); ?>">
									<?php jetonomy_echo_icon( 'shield', 14 ); ?>
									<span><?php esc_html_e( 'Mod queue', 'jetonomy' ); ?></span>
								</a>
								<a class="jt-space-card-action" href="<?php echo esc_url( $base . '/s/' . $sp->slug . '/members/' ); ?>">
									<?php jetonomy_echo_icon( 'users', 14 ); ?>
									<span><?php esc_html_e( 'Members', 'jetonomy' ); ?></span>
								</a>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $member_spaces ) ) : ?>
			<section class="jt-my-spaces-section">
				<ul class="jt-space-list">
					<?php foreach ( $member_spaces as $sp ) : ?>
						<li class="jt-space-card">
							<a class="jt-space-card-link" href="<?php echo esc_url( $base . '/s/' . $sp->slug . '/' ); ?>">
								<div class="jt-space-card-head">
									<?php jetonomy_render_space_icon( $sp->icon ?? '', 24, 'jt-space-card-icon', $sp->type ?? '' ); ?>
									<div class="jt-space-card-titlewrap">
										<h3 class="jt-space-card-title"><?php echo esc_html( $sp->title ); ?></h3>
									</div>
								</div>
								<?php if ( ! empty( $sp->description ) ) : ?>
									<p class="jt-space-card-desc">
										<?php echo esc_html( wp_html_excerpt( $sp->description, 120, '…' ) ); ?>
									</p>
								<?php endif; ?>
								<p class="jt-space-card-meta">
									<?php
									/* translators: %d: post count */
									echo esc_html( sprintf( _n( '%d post', '%d posts', (int) $sp->post_count, 'jetonomy' ), (int) $sp->post_count ) );
									?>
									·
									<?php
									/* translators: %d: member count */
									echo esc_html( sprintf( _n( '%d member', '%d members', (int) $sp->member_count, 'jetonomy' ), (int) $sp->member_count ) );
									?>
								</p>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) ); ?>
</div>
