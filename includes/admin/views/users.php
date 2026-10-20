<?php
/**
 * Admin users management view.
 *
 * Variables seeded by Admin::render_users() before include.
 *
 * @var int        $paged
 * @var int        $total
 * @var int        $total_pages
 * @var string     $search
 * @var int|string $filter_trust
 * @var array      $restrictions user_id => active restriction {id, user_id, type, space_id} (Restriction::active_rows_for_users()).
 * @var array      $space_titles space id => title, for the spaces named by a space ban.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

// Read from Trust_Levels, never a local copy, so the labels match the promotion
// email and the Permissions tab.
$trust_labels = array();
for ( $jt_tl = 0; $jt_tl <= 5; $jt_tl++ ) {
	$trust_labels[ $jt_tl ] = \Jetonomy\Trust\Trust_Levels::label( $jt_tl );
}

// Each row names the member's strongest active restriction; without it a banned
// member's row looked the same as anyone else's.
$restriction_labels = array(
	'global_ban' => __( 'Banned', 'jetonomy' ),
	/* translators: %s: space label, lowercase (e.g. "space"). */
	'space_ban'  => sprintf( __( 'Banned from a %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', false, true ) ),
	'silence'    => __( 'Silenced', 'jetonomy' ),
);
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Users', 'jetonomy' ); ?></h1>

	<?php // Role-clarity explainer: the #1 new-owner question is "how does someone become a Jetonomy user?". Collapsible so it costs experienced owners one line. ?>
	<details class="jt-roles-explainer">
		<summary><?php esc_html_e( 'How users, roles, and trust levels fit together', 'jetonomy' ); ?></summary>
		<div class="jt-roles-explainer__body">
			<p class="jt-roles-explainer__lead"><?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Community %1$s ARE your WordPress users, with no separate registration. Anyone who can log in is counted among them; their community profile is created automatically the first time they visit a community page while logged in. Three independent layers decide what each person can do:', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
			<div class="jt-roles-explainer__grid">
				<div class="jt-roles-explainer__col">
					<h3><?php esc_html_e( 'WordPress role', 'jetonomy' ); ?></h3>
					<p><?php /* translators: %1$s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'Subscriber, Editor, Administrator… Controls wp-admin access and which %1$s capabilities (moderate, manage settings) a person holds. Assign roles on the WordPress Users screen; edit which capabilities each role carries under %1$s → Settings → Permissions.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() ) ); ?></p>
				</div>
				<div class="jt-roles-explainer__col">
					<h3><?php esc_html_e( 'Trust level (0-5)', 'jetonomy' ); ?></h3>
					<p><?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Earned automatically through participation: posting links, images, and editing privileges unlock as %s prove themselves. Override per user below; tune thresholds under Settings → Trust.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
				</div>
				<div class="jt-roles-explainer__col">
					<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
					<h3><?php echo esc_html( sprintf( __( '%s role', 'jetonomy' ), \Jetonomy\space_label( false ) ) ); ?></h3>
					<?php /* jt-terms-ok: Member / moderator / admin are space role names. */ /* translators: 1: singular space label, 2: singular space label, 3: member label, plural (e.g. "Members"). */ ?>
					<p><?php echo esc_html( sprintf( __( 'Member, moderator, or admin of ONE %1$s, granted on that %2$s’s %3$s screen. Never implies wp-admin access.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\space_label( false, true ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></p>
				</div>
			</div>
			<p class="jt-roles-explainer__foot">
				<?php
				printf(
					/* translators: 1: name of the highest trust level, 2: link to the full guide, 3: space label, lowercase (e.g. "space"). */
					esc_html__( 'A Subscriber can be a Level 5 %1$s and admin of any %3$s: community standing and wp-admin access are separate on purpose. %2$s', 'jetonomy' ),
					// Read from $trust_labels for the same reason the rest of this screen does: the
					// sentence must never hardcode a level name such as "Elder" that can be renamed.
					esc_html( (string) $trust_labels[5] ), // Owner-editable since 2.0.2, so escaped like any stored text.
					// Directory, not the deep link to 08-users-roles-and-trust.md.
					// `blob/HEAD` resolves against the DEFAULT branch, and that
					// file lands there only when the release branch merges - so
					// the in-product link would 404 for anyone who clicked it before
					// release.
					// The directory exists on main today and gains the
					// guide at merge, so the link is correct in both states.
					'<a href="https://github.com/vapvarun/jetonomy/tree/HEAD/docs/website/getting-started" target="_blank" rel="noopener">' . esc_html__( 'Browse the setup guides', 'jetonomy' ) . '</a>',
					esc_html( \Jetonomy\space_label( false, true ) )
				);
				?>
			</p>
		</div>
	</details>

	<!-- Search & Filters -->
	<div class="tablenav top">
		<div class="alignleft actions">
			<form method="get" action="" class="jetonomy-user-filters">
				<input type="hidden" name="page" value="jetonomy-users">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search users...', 'jetonomy' ); ?>" class="regular-text">
				<select name="trust_level">
					<option value=""><?php esc_html_e( 'All Trust Levels', 'jetonomy' ); ?></option>
					<?php for ( $i = 0; $i <= 5; $i++ ) : ?>
						<option value="<?php echo absint( $i ); ?>" <?php selected( $filter_trust, (string) $i ); ?>><?php echo esc_html( $i . ' - ' . $trust_labels[ $i ] ); ?></option>
					<?php endfor; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'jetonomy' ); ?></button>
			</form>
		</div>
		<div class="alignright">
			<span class="displaying-num">
				<?php
				/* translators: %s: number of users */
				printf( esc_html( _n( '%s user', '%s users', $total, 'jetonomy' ) ), esc_html( number_format_i18n( $total ) ) );
				?>
			</span>
		</div>
	</div>

	<?php
	jetonomy_admin_table(
		array(
			'rows'      => $users ?? array(),
			'row_attrs' => fn( $u ) => array( 'data-user-id' => absint( $u->user_id ) ),
			'columns'   => array(
				'username'     => array(
					'label'   => __( 'Username', 'jetonomy' ),
					'primary' => true,
				),
				'display-name' => array( 'label' => __( 'Display Name', 'jetonomy' ) ),
				// Widths snap to the shared xs/s/m/l scale rather than inline px; the scale
				// drops to auto below 782px, which a hardcoded width does not.
				'trust'        => array(
					'label' => __( 'Trust Level', 'jetonomy' ),
					'width' => 'm',
				),
				'reputation'   => array(
					'label' => __( 'Reputation', 'jetonomy' ),
					'width' => 's',
				),
				'posts'        => array(
					'label' => \Jetonomy\jetonomy_label( 'topic', true ),
					'width' => 'xs',
				),
				'replies'      => array(
					'label' => \Jetonomy\jetonomy_label( 'reply', true ),
					'width' => 's',
				),
				'joined'       => array(
					'label' => __( 'Joined', 'jetonomy' ),
					'width' => 'm',
				),
				'last-seen'    => array(
					'label' => __( 'Last Seen', 'jetonomy' ),
					'width' => 'm',
				),
			),
			'empty'     => array(
				'icon'  => 'admin-users',
				'title' => __( 'No users match these filters', 'jetonomy' ),
				/* translators: %s: member label, plural, lowercase (e.g. "members"). */
				'body'  => sprintf( __( 'Try clearing a filter or broadening your search to see more %s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ),
			),
			'cell'      => function ( $u, $col ) use ( $trust_labels, $restrictions, $restriction_labels, $space_titles ) {
				switch ( $col ) {
					case 'username':
						echo get_avatar( $u->user_id, 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
						echo '<strong>' . esc_html( $u->user_login ) . '</strong>';
						$jt_restriction = $restrictions[ (int) $u->user_id ] ?? null;
						if ( $jt_restriction && isset( $restriction_labels[ $jt_restriction->type ] ) ) {
							$jt_label = $restriction_labels[ $jt_restriction->type ];
							if ( 'space_ban' === $jt_restriction->type && isset( $space_titles[ $jt_restriction->space_id ] ) ) {
								/* translators: %s: space name. */
								$jt_label = sprintf( __( 'Banned from %s', 'jetonomy' ), $space_titles[ $jt_restriction->space_id ] );
							}
							echo ' <span class="jetonomy-badge jetonomy-badge--ban jetonomy-restriction-label">' . esc_html( $jt_label ) . '</span>';
						}
						// Restrict controls follow the issue rule: never yourself, an admin, or (unless admin) another moderator.
						$jt_may_restrict = current_user_can( 'jetonomy_moderate' ) && true === \Jetonomy\Models\Restriction::actor_may_restrict( (int) $u->user_id, get_current_user_id() );
						?>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( get_edit_user_link( $u->user_id ) ); ?>"><?php esc_html_e( 'View Profile', 'jetonomy' ); ?></a> | </span>
							<span class="trust"><a href="#" class="jetonomy-change-trust-trigger" data-user-id="<?php echo absint( $u->user_id ); ?>" data-current="<?php echo absint( $u->trust_level ); ?>"><?php esc_html_e( 'Change Trust Level', 'jetonomy' ); ?></a><?php echo $jt_may_restrict ? ' | ' : ''; ?></span>
							<?php if ( $jt_may_restrict ) : ?>
								<?php if ( $jt_restriction ) : ?>
									<?php if ( null === \Jetonomy\Moderation\Moderation_Permissions::lift_error( get_current_user_id(), $jt_restriction ) ) : // Lifting needs the authority that issued it. ?>
							<span class="ban"><a href="#" class="jetonomy-unban-user" data-restriction-id="<?php echo absint( $jt_restriction->id ); ?>"><?php esc_html_e( 'Lift restriction', 'jetonomy' ); ?></a> | </span>
								<?php endif; ?>
							<?php else : ?>
							<span class="ban"><a href="#" class="jetonomy-ban-trigger" data-user-id="<?php echo absint( $u->user_id ); ?>" data-username="<?php echo esc_attr( $u->user_login ); ?>"><?php esc_html_e( 'Ban', 'jetonomy' ); ?></a> | </span>
							<span class="silence"><a href="#" class="jetonomy-silence-trigger" data-user-id="<?php echo absint( $u->user_id ); ?>"><?php esc_html_e( 'Silence', 'jetonomy' ); ?></a><?php echo current_user_can( 'manage_options' ) ? ' | ' : ''; ?></span>
								<?php if ( current_user_can( 'manage_options' ) ) : // IP bans block everyone on the address: administrators only (Restriction::validate_ip_ban). ?>
							<span class="ban-ip"><a href="#" class="jetonomy-ban-ip-trigger" data-user-id="<?php echo absint( $u->user_id ); ?>"><?php esc_html_e( 'Ban IP address', 'jetonomy' ); ?></a></span>
								<?php endif; ?>
							<?php endif; ?>
							<?php endif; ?>
						</div>
						<?php
						break;
					case 'display-name':
						echo esc_html( $u->wp_display_name ?: $u->display_name );
						break;
					case 'trust':
						?>
						<span class="jetonomy-trust-badge jetonomy-trust-badge--<?php echo absint( $u->trust_level ); ?>" data-user-id="<?php echo absint( $u->user_id ); ?>">
							<?php echo esc_html( ( $trust_labels[ (int) $u->trust_level ] ?? __( 'Unknown', 'jetonomy' ) ) . ' (' . $u->trust_level . ')' ); ?>
						</span>
						<?php
						break;
					case 'reputation':
						echo esc_html( number_format_i18n( $u->reputation ) );
						break;
					case 'posts':
						echo absint( $u->post_count );
						break;
					case 'replies':
						echo absint( $u->reply_count );
						break;
					case 'joined':
						echo esc_html( $u->user_registered ? human_time_diff( strtotime( $u->user_registered ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : '—' );
						break;
					case 'last-seen':
						echo esc_html( $u->last_seen_at ? human_time_diff( strtotime( $u->last_seen_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : __( 'Never', 'jetonomy' ) );
						break;
				}
			},
		)
	);
	?>

	<!-- Pagination -->
	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				$pagination = paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $total_pages,
						'type'    => 'array',
					)
				);
				if ( $pagination ) {
					echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $pagination ) ) . '</span>';
				}
				?>
			</div>
		</div>
	<?php endif; ?>

	<!-- Trust Level Change Inline Dropdown -->
	<div id="jetonomy-trust-dropdown" class="jetonomy-dropdown" style="display:none;">
		<select id="trust-level-select">
			<?php for ( $i = 0; $i <= 5; $i++ ) : ?>
				<option value="<?php echo absint( $i ); ?>"><?php echo esc_html( $i . ' - ' . $trust_labels[ $i ] ); ?></option>
			<?php endfor; ?>
		</select>
		<button type="button" class="button button-small button-primary" id="jetonomy-save-trust"><?php esc_html_e( 'Save', 'jetonomy' ); ?></button>
		<button type="button" class="button button-small jetonomy-dropdown-cancel"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></button>
		<input type="hidden" id="trust-user-id" value="">
	</div>

	<!-- Ban User Modal -->
	<div class="jetonomy-modal" id="jetonomy-ban-modal" style="display:none;">
		<div class="jetonomy-modal__overlay"></div>
		<div class="jetonomy-modal__content">
			<?php // Title and confirm button are re-labelled by admin-common.js to match the chosen type. ?>
			<h2 id="ban-modal-title"><?php esc_html_e( 'Ban User', 'jetonomy' ); ?></h2>
			<input type="hidden" id="ban-user-id" value="">
			<p id="ban-user-label"></p>
			<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table in the ban modal, not a data list; wp-admin stacks label/field rows below 782px -->
				<tr>
					<th scope="row"><label for="ban-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label></th>
					<td>
						<select id="ban-type">
							<option value="global_ban"><?php esc_html_e( 'Global Ban', 'jetonomy' ); ?></option>
							<option value="silence"><?php esc_html_e( 'Silence', 'jetonomy' ); ?></option>
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
								<option value="ip_ban"><?php esc_html_e( 'IP address ban', 'jetonomy' ); ?></option>
							<?php endif; ?>
						</select>
					</td>
				</tr>
				<tr id="ban-ip-row" hidden>
					<th scope="row"><label for="ban-ip"><?php esc_html_e( 'IP address', 'jetonomy' ); ?></label></th>
					<td>
						<input type="text" id="ban-ip" class="regular-text" inputmode="decimal" autocomplete="off" aria-describedby="ban-ip-help">
						<?php /* translators: 1: product name shown in wp-admin (default "Jetonomy"). 2: member label, plural, lowercase (e.g. "members"). */ ?>
						<p class="description" id="ban-ip-help"><?php echo esc_html( sprintf( __( '%1$s does not record the IP addresses of %2$s. Copy the address from your server logs or security plugin. Everyone using it, signed in or not, loses access to the community.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name(), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
					</td>
				</tr>
				<tr id="ban-reason-row">
					<th scope="row"><label for="ban-reason"><?php esc_html_e( 'Reason', 'jetonomy' ); ?></label></th>
					<td><input type="text" id="ban-reason" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ban-duration"><?php esc_html_e( 'Duration', 'jetonomy' ); ?></label></th>
					<td>
						<select id="ban-duration">
							<option value="permanent"><?php esc_html_e( 'Permanent', 'jetonomy' ); ?></option>
							<option value="1d"><?php esc_html_e( '1 Day', 'jetonomy' ); ?></option>
							<option value="7d"><?php esc_html_e( '7 Days', 'jetonomy' ); ?></option>
							<option value="30d"><?php esc_html_e( '30 Days', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<p class="jetonomy-modal__actions">
				<button type="button" class="button button-primary" id="jetonomy-confirm-ban"><?php esc_html_e( 'Ban User', 'jetonomy' ); ?></button>
				<button type="button" class="button jetonomy-modal-close"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></button>
				<span class="spinner"></span>
			</p>
		</div>
	</div>
</div>
<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
<div class="jt-pro-upsell">
	<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
	<h4><?php esc_html_e( 'Custom Badges & Advanced Moderation', 'jetonomy' ); ?></h4>
	<p><?php esc_html_e( 'Auto-award badges based on activity, create custom fields for profiles, and set up keyword-based auto-moderation rules.', 'jetonomy' ); ?></p>
	<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
	&nbsp;
	<a href="https://store.wbcomdesigns.com/jetonomy/docs/" class="button button-link" target="_blank"><?php esc_html_e( 'View Docs', 'jetonomy' ); ?></a>
</div>
<?php endif; ?>
