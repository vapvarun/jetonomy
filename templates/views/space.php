<?php
/**
 * Space view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$space_slug = $data['slug'] ?? '';
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space || \Jetonomy\Models\Space::concealed_from_viewer( $space, get_current_user_id() ) ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		array(
			'icon'      => 'empty-search',
			'icon_size' => 48,
			/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
			'tone'      => 'warn',
		)
	);
	return;
}

// The policy the viewer actually meets (hidden = invite, private = never
// open), from the same rule the join endpoint enforces.
$_jt_join_policy = \Jetonomy\Models\Space::effective_join_policy( $space );
$_jt_user_id     = get_current_user_id();
$_jt_is_member   = $_jt_user_id && \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $_jt_user_id );
// Somebody an access rule admits (a paying subscriber, say) is not on the
// roster, so every gate below has to count them as being through the door.
// Without this a member who had just bought the plan that includes this space
// was still shown "requires approval to join" and asked to petition a human
// for what they had already paid for.
$_jt_rule_admits = $_jt_user_id && \Jetonomy\Models\AccessRule::grants_access( $_jt_user_id, (int) $space->id );
$_jt_is_admin    = current_user_can( 'manage_options' );
$_jt_is_hidden   = 'hidden' === ( $space->visibility ?? '' );

// Gate: block access for private/hidden spaces (full block) or public spaces with
// restricted join policies (buttons handled below; content stays readable).
//
// The four gate variants below render their unique content (login link, join
// form, pending-approval state) inside the shared empty-state partial so the
// spacing, icon, and tone treatment are uniform with every other empty path.
// Only the join-form variant adds raw HTML below the partial because the form
// itself is too bespoke to fit the generic CTA argument.
// Membership levels this space asks for that the viewer does not hold. Drives
// the "included with VIP" gate below; empty for guests (they get the login
// prompt first, since they may already own the plan) and for anyone whose
// plan is active.
$_jt_unmet_membership = $_jt_user_id
	? \Jetonomy\Models\AccessRule::unmet_membership_requirements( $_jt_user_id, (int) $space->id )
	: array();

// A member banned from this space cannot read its topics (Permission_Engine
// refuses every action there), so the space shows the ban, not a list of
// topics that would each answer 404.
if ( $_jt_user_id && ! $_jt_is_admin && \Jetonomy\Models\Restriction::is_space_banned( $_jt_user_id, (int) $space->id ) ) {
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		array(
			'icon'      => 'lock',
			'message'   => \Jetonomy\Permissions\Permission_Engine::restriction_error( $_jt_user_id, 'read', (int) $space->id )->get_error_message(),
			/* translators: %s: plural space label the site owner configured (e.g. spaces). */
			'cta_label' => sprintf( __( 'Browse other %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
			'cta_url'   => \Jetonomy\base_url() . '/',
			'tone'      => 'forbidden',
		)
	);
	return;
}

if ( in_array( $space->visibility, array( 'private', 'hidden' ), true ) && ! $_jt_is_member && ! $_jt_is_admin && ! $_jt_rule_admits ) {
	// The gate names what is behind it: the space's own description, briefly.
	$_jt_gate_about = wp_trim_words( wp_strip_all_tags( (string) ( $space->description ?? '' ) ), 30 );
	if ( ! $_jt_user_id ) {
		// Guest - prompt to log in. Only private spaces get here: a hidden
		// space answers 404 to guests before this template runs
		// (Space::concealed_from_viewer), so its existence is never confirmed.
		// With guest teasers off the name and description are withheld too.
		if ( \Jetonomy\Models\Space::name_withheld_from_viewer( $space, 0 ) ) {
			$_jt_gate_about = '';
			/* translators: %s: the singular space label, lowercase (e.g. space, group). */
			$gate_message = sprintf( __( 'This %s is private. Log in to see it.', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
		} else {
			$gate_message = 'invite' === $_jt_join_policy
				/* translators: %s: space title. */
				? sprintf( __( '%s is private and invite-only. Log in if you have an invitation.', 'jetonomy' ), $space->title )
				/* translators: %s: space title. */
				: sprintf( __( '%s is private. Log in to request access.', 'jetonomy' ), $space->title );
		}
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			array(
				'icon'            => 'lock',
				'message'         => $gate_message,
				'description'     => $_jt_gate_about,
				'cta_label'       => __( 'Log In', 'jetonomy' ),
				'cta_url'         => wp_login_url( \Jetonomy\current_url() ),
				'secondary_label' => get_option( 'users_can_register' ) ? __( 'Register', 'jetonomy' ) : '',
				'secondary_url'   => get_option( 'users_can_register' ) ? wp_registration_url() : '',
				'tone'            => 'forbidden',
			)
		);
		return;
	} elseif ( ! empty( $_jt_unmet_membership ) ) {
		// A membership rule is the reason this person is out, so say so and
		// give them the way in. Ranked above the invite / approval copy on
		// purpose: telling a would-be subscriber "you need an invitation"
		// when the space is actually sold as part of a plan sends them to ask
		// a human for something they could have bought in one click.
		// A trashed or unpublished course is no way in: name only what a visitor
		// can still get, unless nothing is left (then the admin hint below applies).
		$_jt_live = array_values( array_filter( $_jt_unmet_membership, static fn( $r ) => ! empty( $r['available'] ) ) );
		if ( $_jt_live ) {
			$_jt_unmet_membership = $_jt_live;
		}
		$_jt_tier_names = wp_list_pluck( $_jt_unmet_membership, 'label' );
		// Every way in that can still be bought or joined gets its own button
		// (the partial has room for two), not only the first course.
		$_jt_ways = array_values( array_filter( $_jt_unmet_membership, static fn( $r ) => '' !== $r['url'] ) );
		$_jt_one  = $_jt_unmet_membership[0];
		if ( count( $_jt_unmet_membership ) > 1 ) {
			$_jt_how = $_jt_ways
				? __( 'Any one of them gives you access, from the moment you have it until it ends.', 'jetonomy' )
				: __( 'Ask a site administrator how to get one of them.', 'jetonomy' );
		} elseif ( '' === $_jt_one['url'] ) {
			$_jt_how = '' !== $_jt_one['kind']
				/* translators: %s: what the visitor needs, lowercase (e.g. course, group). */
				? sprintf( __( 'Ask a site administrator to add you to this %s.', 'jetonomy' ), mb_strtolower( $_jt_one['kind'] ) )
				: __( 'Ask a site administrator how to get access to this plan.', 'jetonomy' );
		} elseif ( ! empty( $_jt_one['purchase'] ) ) {
			$_jt_how = '' !== $_jt_one['kind']
				/* translators: %s: what the visitor buys, lowercase (e.g. subscription, membership plan). */
				? sprintf( __( 'Your access starts as soon as your %s is active, and ends if it lapses.', 'jetonomy' ), mb_strtolower( $_jt_one['kind'] ) )
				: __( 'Your access starts as soon as the plan is active, and ends if it lapses.', 'jetonomy' );
		} else {
			/* translators: %s: what the visitor joins, lowercase (e.g. course, group). */
			$_jt_how = sprintf( __( 'You get access as soon as you join this %s, and lose it if you leave it.', 'jetonomy' ), mb_strtolower( $_jt_one['kind'] ) );
		}

		\Jetonomy\Template_Loader::partial(
			'empty-state',
			array(
				'icon'            => 'lock',
				'message'         => sprintf(
					/* translators: 1: the singular space label (e.g. space, group), 2: comma-separated plan names. */
					__( 'This %1$s is included with %2$s.', 'jetonomy' ),
					\Jetonomy\space_label( false, true ),
					implode( ', ', $_jt_tier_names )
				),
				'description'     => $_jt_how,
				/* translators: %s: plan, course or group name. */
				'cta_label'       => isset( $_jt_ways[0] ) ? sprintf( __( 'Get %s', 'jetonomy' ), $_jt_ways[0]['name'] ) : '',
				'cta_url'         => $_jt_ways[0]['url'] ?? '',
				/* translators: %s: plan, course or group name. */
				'secondary_label' => isset( $_jt_ways[1] ) ? sprintf( __( 'Get %s', 'jetonomy' ), $_jt_ways[1]['name'] ) : '',
				'secondary_url'   => $_jt_ways[1]['url'] ?? '',
				'tone'            => 'forbidden',
			)
		);
		return;
	} elseif ( 'invite' === $_jt_join_policy ) {
		// Invite-only — cannot self-join. Hidden spaces always land here
		// (forced above) so the message wording works for both.
		// A hidden space is never named; a private one says which space it is.
		$invite_message = $_jt_is_hidden
			/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
			? sprintf( __( 'This %s is hidden and invite-only. You need an invitation to join.', 'jetonomy' ), \Jetonomy\space_label( false, true ) )
			/* translators: %s: space title. */
			: sprintf( __( '%s is invite-only. You need an invitation to join.', 'jetonomy' ), $space->title );
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			array(
				'icon'        => 'lock',
				'message'     => $invite_message,
				'description' => $_jt_is_hidden ? '' : $_jt_gate_about,
				'tone'        => 'forbidden',
			)
		);
		return;
	} elseif ( 'approval' === $_jt_join_policy ) {
		// Approval required — check for existing pending request first.
		$_jt_gate_pending = \Jetonomy\Models\JoinRequest::find_pending( (int) $space->id, $_jt_user_id );
		if ( $_jt_gate_pending ) {
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				array(
					'icon'        => 'check-circle',
					/* translators: %s: space title. */
					'message'     => sprintf( __( 'Your request to join %s is awaiting approval.', 'jetonomy' ), $space->title ),
					'description' => $_jt_gate_about,
					'slot'        => static function () use ( $space ) {
						?>
						<button type="button" class="jt-btn jt-btn-ghost jt-withdraw-request-btn" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Withdraw request', 'jetonomy' ); ?></button>
						<?php
					},
				)
			);
			return;
		}
		$join_nonce = wp_create_nonce( 'wp_rest' );
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			array(
				'icon'        => 'lock',
				/* translators: %s: space title. */
				'message'     => sprintf( __( '%s is private. Request to join and an admin will review it.', 'jetonomy' ), $space->title ),
				'description' => $_jt_gate_about,
				'tone'        => 'forbidden',
				// The form sits inside the gate card, not floating below it.
				'slot'        => static function () use ( $space, $join_nonce ) {
					?>
					<form class="jt-join-request-form jt-space-gate-form" data-space-id="<?php echo absint( $space->id ); ?>" data-nonce="<?php echo esc_attr( $join_nonce ); ?>">
						<textarea class="jt-input" name="message" rows="3" aria-label="<?php esc_attr_e( 'Message to the admins (optional)', 'jetonomy' ); ?>" placeholder="<?php esc_attr_e( 'Optional: why do you want to join?', 'jetonomy' ); ?>"></textarea>
						<button type="submit" class="jt-btn jt-btn-fill"><?php esc_html_e( 'Request to join', 'jetonomy' ); ?></button>
					</form>
					<?php
				},
			)
		);
		return;
	}
}

$space_status  = $space->status ?? 'active';
$is_restricted = in_array( $space_status, array( 'archived', 'locked' ), true );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'latest';
if ( ! in_array( $sort, array( 'latest', 'popular', 'unanswered' ), true ) ) {
	$sort = 'latest';
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
// Resolve per-page through the model, never inline: the space -> global -> 20 chain
// with `??` only falls through on null, so a stored `posts_per_page: ""` (what the
// front-end edit form sends for "use the default", view.js) would evaluate to (int) '' = 0
// and clamp to a limit of 1. One resolver, one answer, every surface.
$limit  = \Jetonomy\Models\Space::get_posts_per_page( (int) $space->id );
$offset = ( $paged - 1 ) * $limit;

// Use the visibility-aware listing so private topics (is_private = 1) are
// filtered out for non-author, non-privileged viewers — before 1.3.6 this
// called list_by_space() which returned them to everyone with space access,
// including subscribers who were never supposed to see them.
$_jt_is_priv = $_jt_user_id
	&& ( $_jt_is_admin || \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $_jt_user_id, (int) $space->id ) );
// Card projection for topic-card spaces: those cards render
// title + trimmed excerpt only, so drop `content` LONGTEXT and truncate
// content_plain. FEED spaces render the full body inline (feed-card.php)
// and stay on the SELECT * method — trimming it was the safety review's
// confirmed break.
$_jt_list_fn = ( 'feed' === ( $space->type ?? '' ) ) ? 'list_by_space_visible' : 'list_cards_by_space_visible';
$posts       = \Jetonomy\Models\Post::$_jt_list_fn(
	(int) $space->id,
	(int) $_jt_user_id,
	(bool) $_jt_is_priv,
	$sort,
	$limit,
	$offset
);
// "Load More" must reflect whether more posts actually exist, not whether this
// page happened to fill up. count($posts) >= $limit showed the button on a space
// with EXACTLY $limit posts and then loaded an empty page. Compare the
// real total (same visibility population as the listing) against what's shown.
$_jt_total    = \Jetonomy\Models\Post::count_by_space_visible( (int) $space->id, (int) $_jt_user_id, (bool) $_jt_is_priv, $sort );
$_jt_has_more = ( $paged * $limit ) < $_jt_total;
$space_url    = \Jetonomy\route_url( 'space', $space->slug );

// find_visible(): a member admitted to a space inside a hidden category must
// not see that category named or linked in the breadcrumb (it would 404).
$category = $space->category_id ? \Jetonomy\Models\Category::find_visible( (int) $space->category_id ) : null;

// Sub-spaces of this space. An import maps sub-forums to child spaces, and
// until now nothing rendered that: the parent listed no children and the child
// appeared as an unrelated space beside its parent, so a migrated
// "Support > Billing" lost the relationship entirely.
$jt_sub_spaces = \Jetonomy\Models\Space::list_children( (int) $space->id, $_jt_user_id );

// The PARENT belongs in a child's breadcrumb. Home > Category > Child skipped
// the middle of the hierarchy the owner migrated.
$jt_parent = ! empty( $space->parent_id )
	? \Jetonomy\Models\Space::find( (int) $space->parent_id )
	: null;
if ( $jt_parent && \Jetonomy\Models\Space::concealed_from_viewer( $jt_parent, $_jt_user_id ) ) {
	// A parent the viewer may not see must not be named in the trail.
	$jt_parent = null;
}

// Home > Parent category > Category > ... Each category crumb links to its
// page; nothing on a space page led back to its category.
$crumbs = array();
if ( $category ) {
	$jt_parent_cat = (int) $category->parent_id > 0 ? \Jetonomy\Models\Category::find_visible( (int) $category->parent_id ) : null;
	if ( $jt_parent_cat ) {
		$crumbs[] = array(
			'label' => $jt_parent_cat->name,
			'url'   => \Jetonomy\route_url( 'category', $jt_parent_cat->slug ),
		);
	}
	$crumbs[] = array(
		'label' => $category->name,
		'url'   => \Jetonomy\route_url( 'category', $category->slug ),
	);
}
if ( $jt_parent ) {
	$crumbs[] = array(
		'label' => $jt_parent->title,
		'url'   => \Jetonomy\route_url( 'space', $jt_parent->slug ),
	);
}
$crumbs[] = array(
	'label' => $space->title,
	'url'   => '',
);
?>
<?php \Jetonomy\Template_Loader::breadcrumb( $crumbs ); ?>

<div class="jt-two-col">
		<main>
			<?php \Jetonomy\Template_Loader::breadcrumb_in_main(); ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'space-header',
				array(
					'space'           => $space,
					'_jt_user_id'     => $_jt_user_id,
					'_jt_is_member'   => $_jt_is_member,
					'_jt_rule_admits' => $_jt_rule_admits,
					'_jt_is_admin'    => $_jt_is_admin,
					'_jt_join_policy' => $_jt_join_policy,
				)
			);
			?>

			<?php
			// Space sub-navigation. Ideas spaces keep their Ideas + Roadmap tabs;
			// every space type also exposes a Members tab for logged-in users so
			?>

			<?php if ( ! empty( $jt_sub_spaces ) ) : ?>
				<?php /* translators: %s: a plural label the site owner configured (e.g. spaces, categories). */ ?>
				<nav class="jt-subspaces" aria-label="<?php echo esc_attr( sprintf( __( 'Sub-%s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?>">
					<h2 class="jt-subspaces__title">
						<?php
						/* translators: %s: a plural label the site owner configured (e.g. spaces, categories). */
						printf( esc_html__( 'Sub-%s', 'jetonomy' ), esc_html( \Jetonomy\space_label( true, true ) ) );
						?>
					</h2>
					<ul class="jt-subspaces__list">
						<?php foreach ( $jt_sub_spaces as $jt_sub ) : ?>
							<li class="jt-subspaces__item">
								<a class="jt-subspaces__link" href="<?php echo esc_url( \Jetonomy\route_url( 'space', $jt_sub->slug ) ); ?>">
									<?php jetonomy_render_space_icon( $jt_sub->icon ?? '', 20, 'jt-space-emoji' ); ?>
									<span class="jt-subspaces__name"><?php echo esc_html( $jt_sub->title ); ?></span>
									<span class="jt-subspaces__count">
										<?php
										echo esc_html( \Jetonomy\count_label( (int) $jt_sub->post_count, 'topic' ) );
										?>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<?php
			\Jetonomy\Template_Loader::partial(
				'space-tabs',
				array(
					'space'     => $space,
					'space_url' => $space_url,
					'active'    => 'primary',
				)
			);
			?>

			<div class="jt-bar">
				<div class="jt-pills">
					<?php
					$sort_options = array(
						'latest'     => __( 'Latest', 'jetonomy' ),
						'popular'    => __( 'Popular', 'jetonomy' ),
						'unanswered' => __( 'Unanswered', 'jetonomy' ),
					);
					foreach ( $sort_options as $key => $label ) :
						$pill_url = add_query_arg( 'sort', $key, $space_url );
						?>
						<a href="<?php echo esc_url( $pill_url ); ?>"
							class="jt-pill <?php echo $sort === $key ? esc_attr( 'on' ) : ''; ?>"
							<?php echo $sort === $key ? 'aria-current="true"' : ''; ?>>
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
				<?php if ( $is_restricted ) : ?>
					<?php /* No new-post button for archived/locked spaces. */ ?>
					<?php
					/*
					 * Ask whether this viewer may POST, not whether they were
					 * let in. $_jt_rule_admits is binary - it says "admitted",
					 * not "admitted to do what" - so a Read-grant rule put a
					 * New Topic button in front of people the server then
					 * refused, and the refusal produced no message at all.
					 *
					 * Permission_Engine::can() already folds in membership,
					 * the rule's grant level, the public+open shortcut, bans,
					 * silences and the per-space who_can_post setting, so this
					 * is the one question worth asking and the conditions it
					 * replaces were an incomplete re-derivation of it.
					 */
					?>
				<?php elseif ( \Jetonomy\Permissions\Permission_Engine::can( get_current_user_id(), 'create_posts', (int) $space->id ) ) : ?>
					<a href="<?php echo esc_url( \Jetonomy\route_url( 'new-post', $space->slug ) ); ?>" class="jt-btn jt-btn-fill">
						<?php
						// Shared label, so the button and the composer heading it
						// leads to never disagree.
						/* translators: %s: the compose action for this space type, e.g. "Ask a Question". */
						echo esc_html( sprintf( __( '+ %s', 'jetonomy' ), \Jetonomy\compose_label( (string) $space->type ) ) );
						?>
					</a>
				<?php elseif ( ! is_user_logged_in() ) : ?>
					<?php
					// Logged-out members still come here to contribute — the
					// primary action shouldn't read as a faint secondary control.
					// Use the filled button so "Log in to post" has the same
					// visual weight as the New Post action it stands in for.
					?>
					<a href="<?php echo esc_url( wp_login_url( $space_url ) ); ?>" class="jt-btn jt-btn-fill">
						<?php esc_html_e( 'Log in to post', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php
			// A silenced or banned member gets no New Topic button; say why
			// instead of leaving them to guess.
			$_jt_restriction = $is_restricted ? null : \Jetonomy\Permissions\Permission_Engine::restriction_error( get_current_user_id(), 'create_posts', (int) $space->id );
			if ( $_jt_restriction ) :
				?>
				<p class="jt-notice jt-notice-warning"><?php echo esc_html( $_jt_restriction->get_error_message() ); ?></p>
			<?php endif; ?>

			<?php if ( empty( $posts ) ) : ?>
				<?php
				// Empty copy + CTA speak the space's own language: a Q&A space
				// invites a question, a feed invites an update, an ideas space
				// invites an idea — "No posts yet" reads as generic and dead.
				$_jt_space_type = (string) ( $space->type ?? 'forum' );
				// Mirror the space-header New Topic gate (Permission_Engine::can,
				// which folds in membership, admin, trust AND the access rule's
				// grant LEVEL) instead of the binary $_jt_rule_admits - a Read-only
				// rule-admit is admitted but cannot post, and the server refuses
				// it, so it must not get a New Post CTA either.
				$_jt_can_post = ! $is_restricted && is_user_logged_in() && \Jetonomy\Permissions\Permission_Engine::can( get_current_user_id(), 'create_posts', (int) $space->id );
				if ( $is_restricted ) {
					// No invitation to post where posting is closed.
					/* translators: %s: plural topic label. */
					$_jt_no_posts_msg = sprintf( __( 'No %s were posted here.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) );
				} elseif ( 'unanswered' === $sort ) {
					$_jt_no_posts_msg = ( 'qa' === $_jt_space_type )
						? __( 'Every question has an accepted answer.', 'jetonomy' )
						: sprintf( /* translators: 1: plural topic label; 2: plural reply label. */ __( 'No %1$s without %2$s yet.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) );
				} elseif ( ! $_jt_can_post ) {
					// Not a member yet, a guest, or read-only: same noun, no invitation it cannot act on.
					$_jt_no_posts_by_type = array(
						'qa'    => __( 'No questions yet.', 'jetonomy' ),
						'feed'  => __( 'Nothing posted yet.', 'jetonomy' ),
						'ideas' => __( 'No ideas yet.', 'jetonomy' ),
					);
					/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
					$_jt_no_posts_msg = $_jt_no_posts_by_type[ $_jt_space_type ] ?? sprintf( __( 'No %s yet.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) );
				} else {
					switch ( $_jt_space_type ) {
						case 'qa':
							$_jt_no_posts_msg = __( 'No questions yet. Ask the first one and get answers from the community.', 'jetonomy' );
							break;
						case 'feed':
							$_jt_no_posts_msg = __( 'Nothing posted yet. Share the first update.', 'jetonomy' );
							break;
						case 'ideas':
							$_jt_no_posts_msg = __( 'No ideas yet. Suggest the first one and let the community vote.', 'jetonomy' );
							break;
						default:
							/* translators: %s: plural topic label. */
							$_jt_no_posts_msg = sprintf( __( 'No %s yet. Be the first to start a discussion!', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) );
					}
				}
				$_jt_cta_by_type = array(
					'qa'    => __( 'Ask a question', 'jetonomy' ),
					'feed'  => __( 'Share an update', 'jetonomy' ),
					'ideas' => __( 'Suggest an idea', 'jetonomy' ),
				);
				$_jt_post_cta    = $_jt_cta_by_type[ $_jt_space_type ] ?? \Jetonomy\compose_label( $_jt_space_type );
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					array(
						'icon'      => 'empty-posts',
						'message'   => $_jt_no_posts_msg,
						'cta_label' => $_jt_can_post ? $_jt_post_cta : '',
						'cta_url'   => $_jt_can_post ? ( \Jetonomy\route_url( 'new-post', $space->slug ) ) : '',
					)
				);
				?>
			<?php else : ?>
				<?php
				// One batch load for every card on the page (roles, profiles,
				// tags, the viewer's votes and last-read ids).
				$jt_read_map = \Jetonomy\prime_post_cards( $posts );
				$jt_viewer   = get_current_user_id();
				?>
				<?php
				// Feed spaces render the post body inline as a social-feed
				// card; every other space type uses the topic-card list with
				// title + excerpt + click-through.
				$jt_card_partial = ( 'feed' === ( $space->type ?? '' ) ) ? 'feed-card' : 'post-card';
				$jt_topics_class = ( 'feed' === ( $space->type ?? '' ) ) ? 'jt-topics jt-feed' : 'jt-topics';
				?>
				<div class="<?php echo esc_attr( $jt_topics_class ); ?>">
					<?php foreach ( $posts as $post ) : ?>
						<?php
						// Boolean signal: does the viewer have unread replies on
						// this thread? An ID-arithmetic count would be misleading
						// because reply IDs aren't contiguous per post — only the
						// "newer than last_read" comparison is reliable.
						$jt_has_unread = false;
						if ( $jt_viewer > 0 ) {
							$jt_last_reply = (int) ( $post->last_reply_id ?? 0 );
							$jt_last_read  = $jt_read_map[ (int) $post->id ] ?? 0;
							$jt_has_unread = $jt_last_reply > $jt_last_read && (int) $post->author_id !== $jt_viewer;
						}
						?>
						<?php
						\Jetonomy\Template_Loader::partial(
							$jt_card_partial,
							array(
								'post'       => $post,
								'has_unread' => $jt_has_unread,
							)
						);
						?>
					<?php endforeach; ?>
				</div>

				<?php \Jetonomy\Template_Loader::partial( 'pagination', array( 'has_more' => $_jt_has_more ) ); ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => $space ) ); ?>
	</div>
