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
?>
	<?php if ( ! empty( $space->cover_image ) ) : ?>
	<div class="jt-space-cover jt-space-cover--image" style="background-image:url('<?php echo esc_url( $space->cover_image ); ?>')">
<?php else : ?>
	<div class="jt-space-cover jt-space-cover--tonal">
<?php endif; ?>
	<div class="jt-space-cover__identity">
		<?php jetonomy_render_space_icon( $space->icon ?? '', 32, 'jt-space-emoji' ); ?>
		<h1 class="jt-space-cover__title" dir="auto"><?php echo esc_html( $space->title ); ?></h1>
	</div>
</div>
<?php
$_jt_can_edit_space = is_user_logged_in()
	&& \Jetonomy\Permissions\Permission_Engine::is_space_admin( $_jt_user_id, (int) $space->id );
?>
<div class="jt-space-head">
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
			do_action( 'jetonomy_space_display_fields', $space );
			?>
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
		<div class="jt-space-nums">
			<div class="jt-num">
				<div class="jt-num-val"><?php echo esc_html( (int) $space->post_count ); ?></div>
				<div class="jt-num-lbl"><?php echo esc_html( \Jetonomy\count_noun( (int) $space->post_count, 'topic' ) ); ?></div>
			</div>
			<div class="jt-num">
				<div class="jt-num-val"><?php echo esc_html( (int) $space->member_count ); ?></div>
				<div class="jt-num-lbl"><?php echo esc_html( \Jetonomy\count_noun( (int) $space->member_count, 'member' ) ); ?></div>
			</div>
		</div>
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
				// Member or open space: show Follow/Following toggle.
				$is_following_space = \Jetonomy\Models\Subscription::is_subscribed( get_current_user_id(), 'space', (int) $space->id );
				?>
				<button class="jt-btn jt-btn-sm <?php echo esc_attr( $is_following_space ? 'jt-btn-fill jt-following' : 'jt-btn-ghost' ); ?>"
					data-wp-interactive="jetonomy"
					data-wp-on--click="actions.followSpace"
					data-space-id="<?php echo absint( $space->id ); ?>"
					data-following="<?php echo esc_attr( $is_following_space ? '1' : '0' ); ?>">
					<?php echo $is_following_space ? esc_html__( 'Following', 'jetonomy' ) : esc_html__( 'Follow', 'jetonomy' ); ?>
				</button>
				<?php
				// Leave space: only for an actual ROSTER member (has a row to
				// remove) who is not the space author (the owner can't leave
				// their own space). Follow (a subscription) is separate and
				// stays. Rule-admitted-only viewers have no roster row, so
				// $_jt_is_member is false for them and no Leave is offered.
				if ( $_jt_is_member && (int) $space->author_id !== (int) $_jt_user_id ) :
					?>
					<button type="button" class="jt-btn jt-btn-sm jt-btn-ghost jt-leave-space-btn"
						data-wp-interactive="jetonomy"
						data-wp-on--click="actions.leaveSpace"
						data-space-id="<?php echo absint( $space->id ); ?>"
						data-user-id="<?php echo absint( $_jt_user_id ); ?>">
						<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
						<?php echo esc_html( sprintf( __( 'Leave %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>
					</button>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
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
