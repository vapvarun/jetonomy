<?php
/**
 * "Managed by" sidebar card — shown on every space page so a visitor knows
 * who runs the space without clicking around (1.4.0 G1).
 *
 * Expected variables:
 *
 *   @var object[] $members  — array of objects with user_id, role,
 *                             display_name, avatar_url
 *   @var string   $base     — community base URL (for profile links)
 *   @var bool     $bn_active — whether the BuddyNext sidebar shell is active
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$members   = isset( $members ) ? (array) $members : [];
$base      = isset( $base ) ? (string) $base : \Jetonomy\base_url();
$bn_active = isset( $bn_active ) ? (bool) $bn_active : false;

$role_labels = [
	'admin'     => __( 'Admin', 'jetonomy' ),
	'moderator' => __( 'Mod', 'jetonomy' ),
];
?>

<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
		<?php if ( ! $bn_active ) : ?>
			<h4><?php esc_html_e( 'Managed by', 'jetonomy' ); ?></h4>
		<?php else : ?>
			<?php esc_html_e( 'Managed by', 'jetonomy' ); ?>
		<?php endif; ?>
	</div>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
		<?php if ( empty( $members ) ) : ?>
			<p class="jt-managed-by-empty">
				<?php esc_html_e( 'No moderators yet.', 'jetonomy' ); ?>
			</p>
		<?php else : ?>
			<ul class="jt-managed-by-list">
				<?php foreach ( $members as $member ) : ?>
					<?php
					$user = get_userdata( (int) $member->user_id );
					// Resolve from the WP_User already fetched above, not from the
					// $member row: the row is a space_members join, and its own
					// display_name column is not the canonical one.
					$user_name = $user ? \Jetonomy\user_display_name( $user ) : '';
					$role_key  = (string) ( $member->role ?? '' );
					$role_text = $role_labels[ $role_key ] ?? ucfirst( $role_key );
					$profile   = $user ? \Jetonomy\get_profile_url( (int) $user->ID ) : '';
					?>
					<li class="jt-managed-by-row">
						<?php if ( $profile ) : ?>
							<a href="<?php echo esc_url( $profile ); ?>" class="jt-managed-by-link">
								<?php if ( ! empty( $member->avatar_url ) ) : ?>
									<img class="jt-managed-by-avatar"
										src="<?php echo esc_url( $member->avatar_url ); ?>"
										alt="<?php echo esc_attr( $user_name ); ?>"
										width="32"
										height="32"
										loading="lazy" />
								<?php endif; ?>
								<span class="jt-managed-by-name">
									<?php echo esc_html( $user_name ); ?>
								</span>
							</a>
						<?php else : ?>
							<span class="jt-managed-by-name">
								<?php echo esc_html( $user_name ); ?>
							</span>
						<?php endif; ?>
						<span class="jt-managed-by-role jt-managed-by-role--<?php echo esc_attr( $role_key ); ?>">
							<?php echo esc_html( $role_text ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>
