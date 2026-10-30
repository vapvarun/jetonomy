<?php
/**
 * Space header: cover, description, counts, the viewer's actions (Join,
 * Follow, Leave, Edit) and the archived/locked banner.
 *
 * Shared by every space tab (Discussions, Members, Roadmap) so members always
 * see where they are and can act. The Discussions view passes the viewer
 * state it has already worked out; other tabs pass just the space.
 *
 * @package Jetonomy
 *
 * @var object $space Space row.
 */

defined( 'ABSPATH' ) || exit;

$_jt_user_id     = $_jt_user_id ?? get_current_user_id();
$_jt_is_member   = $_jt_is_member ?? ( $_jt_user_id && \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $_jt_user_id ) );
$_jt_rule_admits = $_jt_rule_admits ?? ( $_jt_user_id && \Jetonomy\Models\AccessRule::grants_access( $_jt_user_id, (int) $space->id ) );
$_jt_is_admin    = $_jt_is_admin ?? current_user_can( 'manage_options' );
// Hidden spaces are invite-only whatever the stored policy says.
$_jt_join_policy = $_jt_join_policy ?? \Jetonomy\Models\Space::effective_join_policy( $space );
$space_status    = $space->status ?? 'active';
$is_restricted   = in_array( $space_status, array( 'archived', 'locked' ), true );
$_jt_has_cover   = ! empty( $space->cover_image );
?>
<?php
// A banner only when the space has a cover photo. Without one the header is a
// compact card with a slim accent strip, so the first topic is not pushed a
// screen down by an empty gradient.
if ( $_jt_has_cover ) :
	?>
	<div class="jt-space-cover" style="background-image:url('<?php echo esc_url( $space->cover_image ); ?>')" aria-hidden="true"></div>
<?php endif; ?>
<?php
$_jt_can_edit_space = is_user_logged_in()
	&& \Jetonomy\Permissions\Permission_Engine::is_space_admin( $_jt_user_id, (int) $space->id );
?>
<div class="jt-space-head<?php echo $_jt_has_cover ? ' jt-space-head--covered' : ''; ?>">
		<div class="jt-space-head__identity">
			<?php jetonomy_render_space_icon( $space->icon ?? '', 24, 'jt-space-emoji', (string) ( $space->type ?? '' ) ); ?>
			<div class="jt-space-head__titles">
				<h1 class="jt-space-head__title" dir="auto"><?php echo esc_html( $space->title ); ?></h1>
				<div class="jt-space-nums">
					<span class="jt-num"><span class="jt-num-val"><?php echo esc_html( number_format_i18n( (int) $space->post_count ) ); ?></span> <span class="jt-num-lbl"><?php echo esc_html( \Jetonomy\count_noun( (int) $space->post_count, 'topic' ) ); ?></span></span>
					<span class="jt-num"><span class="jt-num-val"><?php echo esc_html( number_format_i18n( (int) $space->member_count ) ); ?></span> <span class="jt-num-lbl"><?php echo esc_html( \Jetonomy\count_noun( (int) $space->member_count, 'member' ) ); ?></span></span>
				</div>
			</div>
		</div>
		<div class="jt-space-head__actions">
		<?php
		// An archived or locked space takes no new members (SpaceMember::join_blocked()),
		// so a non-member there gets Follow, never a Join button that would fail.
		if ( is_user_logged_in() ) :
			if ( 'invite' === $_jt_join_policy && ! $_jt_is_member && ! $_jt_is_admin && ! $_jt_rule_admits && ! $is_restricted ) :
				// Invite-only: show a disabled badge instead of Follow/Join.
				?>
				<span class="jt-btn jt-btn-sm jt-btn-ghost jt-btn--status">
					<?php esc_html_e( 'Invite Only', 'jetonomy' ); ?>
				</span>
				<?php
			elseif ( 'approval' === $_jt_join_policy && ! $_jt_is_member && ! $_jt_is_admin && ! $_jt_rule_admits && ! $is_restricted && \Jetonomy\Models\SpaceMember::may_join_spaces( $_jt_user_id ) ) :
				// Approval required: check for existing pending request. Pending
				// gets the same Withdraw the private-space gate offers; the
				// "awaiting approval" message renders under the header.
				$_jt_pending = \Jetonomy\Models\JoinRequest::find_pending( (int) $space->id, $_jt_user_id );
				if ( $_jt_pending ) :
					?>
					<button type="button" class="jt-btn jt-btn-sm jt-btn-ghost jt-withdraw-request-btn" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Withdraw request', 'jetonomy' ); ?></button>
					<?php
				else :
					$_jt_join_nonce = wp_create_nonce( 'wp_rest' );
					?>
					<button class="jt-btn jt-btn-sm jt-btn-fill jt-join-request-btn"
						data-space-id="<?php echo absint( $space->id ); ?>"
						data-nonce="<?php echo esc_attr( $_jt_join_nonce ); ?>">
						<?php esc_html_e( 'Request to join', 'jetonomy' ); ?>
					</button>
				<?php endif; ?>
				<?php
			elseif ( 'open' === $_jt_join_policy && ! $_jt_is_member && ! $_jt_is_admin && ! $_jt_rule_admits && ! $is_restricted && \Jetonomy\Models\SpaceMember::may_join_spaces( $_jt_user_id ) ) :
				// Open space, non-member: show Join Space button (instant membership).
				$_jt_join_nonce = wp_create_nonce( 'wp_rest' );
				?>
				<button class="jt-btn jt-btn-sm jt-btn-fill jt-join-btn"
					data-space-id="<?php echo absint( $space->id ); ?>"
					data-nonce="<?php echo esc_attr( $_jt_join_nonce ); ?>">
					<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ ?>
					<?php echo esc_html( sprintf( __( 'Join %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>
				</button>
				<?php
			else :
				// Membership and following are two different things: membership
				// is one "Joined" control whose menu holds Leave (with a confirm
				// naming the space), and following is a separate bell toggle that
				// only decides whether new activity notifies you.
				$is_following_space = \Jetonomy\Models\Subscription::is_subscribed( get_current_user_id(), 'space', (int) $space->id );
				// Leave: only for an actual ROSTER member (has a row to remove)
				// who is not the space author (the owner can't leave their own
				// space). Rule-admitted-only viewers have no roster row, so
				// $_jt_is_member is false for them and no Leave is offered.
				if ( $_jt_is_member && (int) $space->author_id !== (int) $_jt_user_id ) :
					?>
					<div class="jt-more-menu jt-membership-menu" data-wp-interactive="jetonomy">
						<button type="button" class="jt-btn jt-btn-sm jt-btn-ghost jt-more-trigger jt-membership-trigger"
							aria-haspopup="menu" aria-expanded="false"
							data-wp-on--click="actions.toggleMoreMenu">
							<?php jetonomy_echo_icon( 'check-circle', 14 ); ?>
							<span><?php esc_html_e( 'Joined', 'jetonomy' ); ?></span>
							<?php jetonomy_echo_icon( 'chevron-down', 14 ); ?>
						</button>
						<div class="jt-more-dropdown" role="menu" hidden>
							<button type="button" role="menuitem" class="jt-more-item jt-more-item--danger jt-leave-space-btn"
								data-wp-on--click="actions.leaveSpace"
								data-space-id="<?php echo absint( $space->id ); ?>"
								data-space-title="<?php echo esc_attr( $space->title ); ?>"
								data-user-id="<?php echo absint( $_jt_user_id ); ?>">
								<?php jetonomy_echo_icon( 'x-circle', 14 ); ?>
								<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
								<?php echo esc_html( sprintf( __( 'Leave %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>
				<button type="button" class="jt-btn jt-btn-sm jt-follow-space-btn <?php echo esc_attr( $is_following_space ? 'jt-btn-fill jt-following' : 'jt-btn-ghost' ); ?>"
					data-wp-interactive="jetonomy"
					data-wp-on--click="actions.followSpace"
					data-space-id="<?php echo absint( $space->id ); ?>"
					data-following="<?php echo esc_attr( $is_following_space ? '1' : '0' ); ?>"
					aria-pressed="<?php echo $is_following_space ? 'true' : 'false'; ?>"
					title="<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ echo esc_attr( sprintf( __( 'Get notified about new activity in this %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>">
					<?php jetonomy_echo_icon( 'bell', 14 ); ?>
					<span class="jt-follow-label"><?php echo $is_following_space ? esc_html__( 'Following', 'jetonomy' ) : esc_html__( 'Follow', 'jetonomy' ); ?></span>
				</button>
			<?php endif; ?>
		<?php endif; ?>
		</div>
		<div class="jt-space-head__meta">
			<?php if ( ! empty( $space->description ) ) : ?>
				<p class="jt-space-desc" dir="auto"><?php echo esc_html( $space->description ); ?></p>
			<?php endif; ?>
			<?php
			/**
			 * Fires in the space header to display custom field values
			 * (context = space). Pro custom-fields renders the saved
			 * values here. Mirrors jetonomy_profile_display_fields.
			 *
			 * @param object $space The space being viewed.
			 */
			?>
			<div class="jt-space-fields"><?php do_action( 'jetonomy_space_display_fields', $space ); ?></div>
			<?php
			// Mirror of the gate: someone a membership let in sees which one, so
			// they know why they are here and what keeps them in. Not shown to
			// people who joined or were invited, whose access does not depend on it.
			$_jt_admitted_by = $_jt_rule_admits && ! $_jt_is_admin
				&& in_array( \Jetonomy\Models\SpaceMember::find_source( (int) $space->id, (int) $_jt_user_id ), array( null, 'tier' ), true )
				? \Jetonomy\Models\AccessRule::admitting_membership( (int) $_jt_user_id, (int) $space->id )
				: null;
			?>
			<?php if ( $_jt_admitted_by ) : ?>
				<p class="jt-space-access-note">
					<?php jetonomy_echo_icon( 'user-check', 16 ); ?>
					<span>
						<?php
						$_jt_level_html = '' !== $_jt_admitted_by['url']
							? '<a href="' . esc_url( $_jt_admitted_by['url'] ) . '">' . esc_html( $_jt_admitted_by['label'] ) . '</a>'
							: '<strong>' . esc_html( $_jt_admitted_by['label'] ) . '</strong>';
						/* translators: %s: the membership level that admits the viewer, e.g. "Writing Basics (Course)", linked to it. */
						printf( esc_html__( 'You have access through %s.', 'jetonomy' ), $_jt_level_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
						?>
					</span>
				</p>
			<?php endif; ?>
			<?php if ( $_jt_can_edit_space ) : ?>
				<?php
				/*
				 * Inline Edit Space CTA for users who can administer THIS space.
				 * The admin-bar entry alone is not enough - WordPress hides the
				 * admin bar at mobile widths under most themes, leaving phone-only
				 * space admins with no path to /edit/. This inline button always
				 * shows, gated by the same Permission_Engine::is_space_admin check
				 * as the admin-bar entry and the front-end edit template.
				 */
				?>
				<p class="jt-space-edit-cta">
					<a class="jt-btn jt-btn-sm jt-btn-ghost"
						href="<?php echo esc_url( \Jetonomy\route_url( 'edit-space', $space->slug ) ); ?>">
						<?php jetonomy_echo_icon( 'pencil', 14 ); ?>
						<?php /* translators: %s: the label of the item being edited (the configured noun). */ ?>
						<?php echo esc_html( sprintf( __( 'Edit %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
					</a>
				</p>
			<?php endif; ?>
			<?php
			// The sidebar About card (and its queue link) is suppressed on the
			// space page, and the global nav link vanishes when the nav is
			// hidden, so space moderators get their queue here.
			$_jt_mod_uid = get_current_user_id();
			if ( $_jt_mod_uid && \Jetonomy\Moderation\Moderation_Permissions::can_view_space_queue( $_jt_mod_uid, (int) $space->id ) ) :
				$_jt_flag_count  = \Jetonomy\Moderation\Moderation_Service::count_pending_flags( $_jt_mod_uid, (int) $space->id );
				$_jt_held_count  = \Jetonomy\Moderation\Moderation_Service::count_pending_approvals( $_jt_mod_uid, 'post', (int) $space->id )
					+ \Jetonomy\Moderation\Moderation_Service::count_pending_approvals( $_jt_mod_uid, 'reply', (int) $space->id );
				$_jt_queue_count = $_jt_flag_count + $_jt_held_count;
				// Open the tab the count is about: the queue opens on Flags, so
				// held content alone used to land on "0 pending flags".
				$_jt_queue_url = \Jetonomy\route_url( 'space-moderation', $space->slug );
				if ( 0 === $_jt_flag_count && $_jt_held_count > 0 ) {
					$_jt_queue_url = add_query_arg( 'view', 'approvals', $_jt_queue_url );
				}
				?>
				<p class="jt-space-edit-cta">
					<a class="jt-btn jt-btn-sm jt-btn-ghost"
						href="<?php echo esc_url( $_jt_queue_url ); ?>">
						<?php jetonomy_echo_icon( 'shield', 14 ); ?>
						<?php esc_html_e( 'Moderation queue', 'jetonomy' ); ?>
						<?php if ( $_jt_queue_count > 0 ) : ?>
							<span class="jt-count-pill"><?php echo esc_html( number_format_i18n( $_jt_queue_count ) ); ?></span>
						<?php endif; ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
	</div>

<?php if ( ! empty( $_jt_pending ) ) : ?>
	<div class="jt-status-banner jt-status-banner--pending" role="status" dir="auto">
		<?php
		/* translators: %s: space title. */
		echo esc_html( sprintf( __( 'Your request to join %s is awaiting approval.', 'jetonomy' ), $space->title ) );
		?>
	</div>
<?php endif; ?>

<?php if ( $is_restricted ) : ?>
	<div class="jt-status-banner jt-status-banner--<?php echo esc_attr( $space_status ); ?>">
		<?php if ( 'archived' === $space_status ) : ?>
			<?php
			// Right after a hand-over, name the new owner (read from the
			// space, so the query flag only decides whether to say it).
			$jt_owner_name = \Jetonomy\user_display_name( (int) $space->author_id );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
			if ( ! empty( $_GET['jt_handed_over'] ) && '' !== $jt_owner_name ) :
				?>
				<strong role="status">
					<?php
					/* translators: %s: display name of the new owner. */
					echo esc_html( sprintf( __( 'Archived and handed over to %s.', 'jetonomy' ), $jt_owner_name ) );
					?>
				</strong>
			<?php endif; ?>
			<?php echo esc_html( sprintf( /* translators: 1: singular space label; 2: plural topic label; 3: plural reply label. */ __( 'This %1$s is archived. New %2$s and %3$s are no longer accepted.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?>
		<?php else : ?>
			<?php /* translators: 1: singular space label (e.g. space, group); 2: plural reply label. */ ?>
			<?php echo esc_html( sprintf( /* translators: 1: singular space label; 2: plural topic label; 3: plural reply label. */ __( 'This %1$s is locked. New %2$s and %3$s are not allowed.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?>
		<?php endif; ?>
	</div>
	<?php endif; ?>
