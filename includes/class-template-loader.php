<?php
/**
 * Template loader.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Template_Loader {

	public static function render( array $data ): void {
		// ── /u/me/ redirect to actual user profile ──
		if ( 'profile' === $data['route'] && 'me' === $data['slug'] ) {
			if ( is_user_logged_in() ) {
				$settings  = get_option( 'jetonomy_settings', array() );
				$base_slug = $settings['base_slug'] ?? 'community';
				wp_safe_redirect( home_url( '/' . $base_slug . '/u/' . wp_get_current_user()->user_login . '/' ) );
			} else {
				wp_safe_redirect( wp_login_url( current_url() ) );
			}
			exit;
		}

		// ── Global access control from settings ──
		// Public community (guest_read on, or unset — defaults public): anyone can read, writes
		// still require login via the REST permission layer. Private community (guest_read off):
		// redirect anonymous visitors to the login page.
		// Visibility helper centralizes the guest_read check; the same predicate
		// gates the REST API in private mode (see Jetonomy\Visibility::rest_check).
		$settings = get_option( 'jetonomy_settings', array() );
		if ( ! \Jetonomy\Visibility::can_view_community() ) {
			wp_safe_redirect( wp_login_url( current_url() ) );
			exit;
		}

		// ── Auth redirect for protected routes (BEFORE any output) ──
		$auth_required_routes = array( 'notifications', 'messages', 'conversation', 'edit-profile', 'new-post', 'my-spaces', 'subscriptions', 'new-space', 'edit-space', 'moderation', 'space-moderation', 'drafts', 'bookmarks' );
		if ( in_array( $data['route'], $auth_required_routes, true ) && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( current_url() ) );
			exit;
		}

		// ── /mod/ route: route space-level mods to the right surface ──
		// A user who moderates exactly one space is sent straight to that
		// space's queue (one less click for the common case). A user who
		// moderates two or more spaces falls through to the aggregate
		// dashboard so they can see every queue they own with pending
		// counts at a glance — previously they got auto-redirected to
		// their first space and had no UI affordance to reach the others.
		if ( 'moderation' === $data['route'] && is_user_logged_in() ) {
			$mod_user_id = get_current_user_id();
			if (
				! \Jetonomy\Moderation\Moderation_Permissions::can_view_admin_dashboard( $mod_user_id )
				&& \Jetonomy\Moderation\Moderation_Permissions::can_view_any_queue( $mod_user_id )
			) {
				$mod_space_ids = \Jetonomy\Models\SpaceMember::moderated_space_ids( $mod_user_id );
				if ( count( $mod_space_ids ) === 1 ) {
					$mod_first = \Jetonomy\Models\Space::find( (int) $mod_space_ids[0] );
					if ( $mod_first ) {
						$mod_settings  = get_option( 'jetonomy_settings', array() );
						$mod_base_slug = $mod_settings['base_slug'] ?? 'community';
						wp_safe_redirect( home_url( '/' . $mod_base_slug . '/s/' . $mod_first->slug . '/mod/' ) );
						exit;
					}
				}
				// 0 spaces (somehow) or 2+ spaces: fall through and let
				// templates/views/moderation.php render the aggregate
				// dashboard scoped to the user's moderated spaces.
			}
		}

		// ── /new/ route membership guard ──
		// REST POST /posts returns 403 for non-members on invite/approval spaces, but
		// without this guard the user still reaches the composer, fills it, submits, and
		// sees a silent failure. Redirect to the space page where the invite-only /
		// request-to-join empty state surfaces the correct next action.
		if ( 'new-post' === $data['route'] && ! empty( $data['slug'] ) && ! current_user_can( 'manage_options' ) ) {
			$jt_space = \Jetonomy\Models\Space::find_by_slug( (string) $data['slug'] );
			if ( $jt_space ) {
				$jt_join_policy = $jt_space->join_policy ?? 'open';
				$jt_is_member   = \Jetonomy\Models\SpaceMember::is_member( (int) $jt_space->id, get_current_user_id() );
				if ( ! $jt_is_member && in_array( $jt_join_policy, array( 'invite', 'approval' ), true ) ) {
					$jt_settings  = get_option( 'jetonomy_settings', array() );
					$jt_base_slug = $jt_settings['base_slug'] ?? 'community';
					wp_safe_redirect( home_url( '/' . $jt_base_slug . '/s/' . $jt_space->slug . '/' ) );
					exit;
				}
			}
		}

		// Update last_seen_at for online status tracking.
		$current_user_id = get_current_user_id();
		if ( $current_user_id ) {
			\Jetonomy\Models\UserProfile::update_last_seen( $current_user_id );
		}

		// Allow theme overrides: theme/jetonomy/views/home.php
		$theme_dir  = get_stylesheet_directory() . '/jetonomy/';
		$plugin_dir = JETONOMY_DIR . 'templates/';

		// Map routes to template files
		$template_map = array(
			'home'             => 'views/home.php',
			'category'         => 'views/category.php',
			'space'            => 'views/space.php',
			'space-members'    => 'views/space-members.php',
			'space-roadmap'    => 'views/space-roadmap.php',
			'space-moderation' => 'views/space-moderation.php',
			'post'             => 'views/single-post.php',
			'profile'          => 'views/user-profile.php',
			'notifications'    => 'views/notifications.php',
			'search'           => 'views/search.php',
			'leaderboard'      => 'views/leaderboard.php',
			'moderation'       => 'views/moderation.php',
			'tag'              => 'views/tag.php',
			'new-post'         => 'views/new-post.php',
			'edit-profile'     => 'views/edit-profile.php',
			'invite'           => 'views/invite.php',
			'my-spaces'        => 'views/my-spaces.php',
			'subscriptions'    => 'views/subscriptions.php',
			'new-space'        => 'views/new-space.php',
			'edit-space'       => 'views/space-edit.php',
			'drafts'           => 'views/drafts.php',
			'bookmarks'        => 'views/bookmarks.php',
		);

		/**
		 * Filter the template map so Pro (or other plugins) can register
		 * additional routes or override existing template paths.
		 *
		 * Values may be relative (resolved against plugin_dir/theme_dir)
		 * or absolute paths (starting with /).
		 *
		 * @param array $template_map Route => template file map.
		 */
		$template_map = apply_filters( 'jetonomy_template_map', $template_map );

		$route         = $data['route'];
		$template_file = $template_map[ $route ] ?? null;

		if ( ! $template_file ) {
			status_header( 404 );
			return;
		}

		// If the template path is absolute (from Pro), use it directly.
		// Otherwise, check theme override first, then plugin directory.
		if ( str_starts_with( $template_file, '/' ) || str_starts_with( $template_file, ABSPATH ) ) {
			$template_path = $template_file;
		} else {
			$template_path = file_exists( $theme_dir . $template_file )
				? $theme_dir . $template_file
				: $plugin_dir . $template_file;
		}

		if ( ! file_exists( $template_path ) ) {
			status_header( 404 );
			return;
		}

		// Enqueue styles. The --jt-* token layer is its own handle: blocks render
		// on pages this stylesheet never loads on, so the tokens cannot live in
		// it (see the header of jetonomy-tokens.css). Registering is idempotent —
		// Blocks::register_block_assets() registers the same handle and WordPress
		// dedupes — but jetonomy.css consumes tokens it does not declare, so the
		// dependency below is what guarantees they are parsed first.
		wp_register_style(
			'jetonomy-tokens',
			JETONOMY_URL . 'assets/css/jetonomy-tokens.css',
			array(),
			JETONOMY_VERSION
		);
		wp_enqueue_style(
			'jetonomy',
			JETONOMY_URL . 'assets/css/jetonomy.css',
			array( 'jetonomy-tokens' ),
			JETONOMY_VERSION
		);

		// RTL stylesheet for right-to-left languages.
		if ( is_rtl() ) {
			wp_enqueue_style( 'jetonomy-rtl', JETONOMY_URL . 'assets/css/jetonomy-rtl.css', array( 'jetonomy' ), JETONOMY_VERSION );
		}

		// ── Inject dynamic CSS from settings (accent color, custom CSS, etc.) ──
		$dynamic_css = '';

		// Detect the theme's container width and set --jt-container-width.
		// 1. Check Jetonomy setting (user override)
		// 2. Read from theme.json via wp_get_global_settings()
		// 3. Read WP's $content_width global (classic themes set this in functions.php)
		// 4. Fallback to 1200px
		$container_width = '';
		if ( ! empty( $settings['container_width'] ) ) {
			$container_width = $settings['container_width'];
		}
		if ( ! $container_width ) {
			$global_settings = wp_get_global_settings( array( 'layout' ) );
			// wideSize is the wider container (for layouts with sidebars).
			$container_width = $global_settings['wideSize'] ?? '';
			// If no wideSize, try contentSize.
			if ( ! $container_width ) {
				$container_width = $global_settings['contentSize'] ?? '';
			}
		}
		if ( ! $container_width ) {
			global $content_width;
			if ( ! empty( $content_width ) ) {
				$container_width = $content_width . 'px';
			}
		}
		if ( ! $container_width ) {
			$container_width = '1200px';
		}
		$dynamic_css .= '.jt-container{--jt-container-width:' . esc_attr( $container_width ) . ';}';

		// Color palette overrides (accent + text/background/subtle/border).
		$dynamic_css .= self::palette_css( $settings );

		// Theme adoption is unconditional and lives in jetonomy.css — see the
		// --jt-accent / --jt-font chains there. Two settings used to gate it here
		// and both are gone:
		//
		// `inherit_fonts` emitted `--jt-font:inherit`. Measured across 11 themes it
		// changed nothing: the chain already ends in `inherit`, so the token
		// resolves to empty either way and .jt-app inherits the theme's font from
		// body regardless. It was a checkbox with no effect, over a font setting
		// that does not exist.
		//
		// `inherit_colors` did have an effect, and it was the wrong one: it made
		// palette_tokens() return nothing, so an owner who picked an accent had it
		// silently discarded — the checkbox defaults to checked, which left the
		// colour picker directly beneath it dead on arrival. The picker already
		// encodes the same intent honestly (the #0073aa default means "not set,
		// adopt the theme"), so the toggle was redundant as well as harmful.
		// It also re-declared the whole accent chain from a stale copy, which had
		// already drifted from the real one in jetonomy.css.

		// Layout density. Comfortable is the baseline (theme defaults, no override);
		// compact tightens spacing/type, spacious loosens it. Rules are keyed to the
		// data-jt-density attribute set on the .jt-app wrapper below, matching the
		// documented mechanism (docs/website/admin-settings/04-appearance.md). All
		// values reference --jt-* tokens so density inherits theme + dark-mode scaling.
		$density = $settings['layout_density'] ?? 'comfortable';
		if ( 'compact' === $density ) {
			$dynamic_css .= '.jt-app[data-jt-density="compact"]{font-size:var(--jt-text-sm);line-height:var(--jt-leading-normal);}'
				. '.jt-app[data-jt-density="compact"] .jt-row{padding:var(--jt-space-2) var(--jt-space-3);}'
				. '.jt-app[data-jt-density="compact"] .jt-reply-body{padding:var(--jt-space-3) var(--jt-space-4);}'
				. '.jt-app[data-jt-density="compact"] .jt-post-body{padding:var(--jt-space-4);}';
		} elseif ( 'spacious' === $density ) {
			$dynamic_css .= '.jt-app[data-jt-density="spacious"]{font-size:var(--jt-text-lg);line-height:1.8;}'
				. '.jt-app[data-jt-density="spacious"] .jt-row{padding:var(--jt-space-5) var(--jt-space-6);}'
				. '.jt-app[data-jt-density="spacious"] .jt-reply-body{padding:var(--jt-space-6) var(--jt-space-8);}'
				. '.jt-app[data-jt-density="spacious"] .jt-post-body{padding:var(--jt-space-8);}';
		}

		// Custom CSS from settings.
		if ( ! empty( $settings['custom_css'] ) ) {
			$dynamic_css .= wp_strip_all_tags( $settings['custom_css'] );
		}

		/**
		 * Filters the Jetonomy dynamic inline CSS before it is attached.
		 *
		 * The string already contains the container width, palette tokens,
		 * font-inherit rules, the host-theme colour-adoption chain, density
		 * rules and the admin Custom CSS. Append your own `--jt-*` token
		 * overrides or scoped rules here from a plugin without a child theme.
		 *
		 * @since 1.5.0
		 *
		 * @param string $dynamic_css The assembled inline CSS.
		 * @param array  $settings    The jetonomy_settings option array.
		 */
		$dynamic_css = (string) apply_filters( 'jetonomy_dynamic_css', $dynamic_css, $settings );

		wp_add_inline_style( 'jetonomy', $dynamic_css );

		// Set up Interactivity API state
		wp_interactivity_state(
			'jetonomy',
			array(
				'apiBase'        => rest_url( 'jetonomy/v1' ),
				'_nonce'         => wp_create_nonce( 'wp_rest' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'communityBase'  => home_url( '/' . ( $settings['base_slug'] ?? 'community' ) ),
				'currentPostId'  => 0,
				'postScores'     => new \stdClass(),
				'replyScores'    => new \stdClass(),
				'isLoggedIn'     => is_user_logged_in(),
				'loginUrl'       => wp_login_url( current_url() ),
				'isSubmitting'   => false,
				'submitLabel'    => __( 'Post Topic', 'jetonomy' ),
				'submitError'    => '',
				'msgComposeOpen' => false,
				'i18n'           => array(
					'voteRecorded'          => __( 'Vote recorded', 'jetonomy' ),
					'statusUpdated'         => __( 'Roadmap status updated', 'jetonomy' ),
					'accepted'              => __( 'Accepted', 'jetonomy' ),
					'reply'                 => __( 'Reply', 'jetonomy' ),
					'cancel'                => __( 'Cancel', 'jetonomy' ),
					'save'                  => __( 'Save', 'jetonomy' ),
					'saving'                => __( 'Saving...', 'jetonomy' ),
					'failedSave'            => __( 'Failed to save.', 'jetonomy' ),
					'networkError'          => __( 'Network error. Please try again.', 'jetonomy' ),
					'follow'                => __( 'Follow', 'jetonomy' ),
					'following'             => __( 'Following', 'jetonomy' ),
					'followingSpace'        => sprintf( __( 'Following %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					'unfollowedSpace'       => sprintf( __( 'Unfollowed %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					'copyLink'              => __( 'Copy link', 'jetonomy' ),
					'bookmark'              => __( 'Bookmark', 'jetonomy' ),
					'removeBookmark'        => __( 'Remove bookmark', 'jetonomy' ),
					'bookmarked'            => __( 'Bookmarked', 'jetonomy' ),
					'bookmarkRemoved'       => __( 'Bookmark removed', 'jetonomy' ),
					'reportPrompt'          => __( 'Why are you reporting this post?', 'jetonomy' ),
					'reportedThankYou'      => __( 'Reported. Thank you.', 'jetonomy' ),
					'failedReport'          => __( 'Failed to submit report.', 'jetonomy' ),
					'postPinned'            => __( 'Post pinned', 'jetonomy' ),
					'postUnpinned'          => __( 'Post unpinned', 'jetonomy' ),
					'failedPin'             => __( 'Failed to toggle pin.', 'jetonomy' ),
					'confirmDeletePost'     => __( 'Are you sure you want to delete this topic?', 'jetonomy' ),
					'confirmDeleteReply'    => __( 'Are you sure you want to delete this reply?', 'jetonomy' ),
					'failedDelete'          => __( 'Failed to delete.', 'jetonomy' ),
					'moveTopicTitle'        => sprintf( __( 'Move topic to another %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					'topicMoved'            => __( 'Topic moved successfully.', 'jetonomy' ),
					'moveFailed'            => __( 'Failed to move topic.', 'jetonomy' ),
					'mergeTopicTitle'       => __( 'Merge into another topic', 'jetonomy' ),
					'confirmMerge'          => __( 'Merge this topic into the selected one? All replies will be moved and this topic will be deleted.', 'jetonomy' ),
					'topicMerged'           => __( 'Topics merged successfully.', 'jetonomy' ),
					'mergeFailed'           => __( 'Failed to merge topics.', 'jetonomy' ),
					'splitReplyTitle'       => __( 'Enter a title for the new topic:', 'jetonomy' ),
					'replySplit'            => __( 'Reply split into new topic.', 'jetonomy' ),
					'splitFailed'           => __( 'Failed to split reply.', 'jetonomy' ),
					'replyingTo'            => __( 'Replying to', 'jetonomy' ),
					'cancelReply'           => __( 'Cancel reply', 'jetonomy' ),
					'posting'               => __( 'Posting...', 'jetonomy' ),
					'postTopic'             => __( 'Post Topic', 'jetonomy' ),
					'newReply'              => __( '%d new reply. Click to refresh.', 'jetonomy' ),
					'newReplies'            => __( '%d new replies. Click to refresh.', 'jetonomy' ),
					'linkCopied'            => __( 'Link copied', 'jetonomy' ),
					'linkCopyFailed'        => __( 'Could not copy the link. Copy it from the address bar.', 'jetonomy' ),
					'titleRequired'         => __( 'Please enter a title for your topic.', 'jetonomy' ),
					'bodyRequired'          => __( 'Please add some details before posting.', 'jetonomy' ),
					'loginRequired'         => __( 'Please sign in to use this.', 'jetonomy' ),
					// Pro Private Messaging composer (consumed by jetonomy-pro/assets/js/pro-view.js).
					'messageSending'        => __( 'Sending...', 'jetonomy' ),
					'messageSend'           => __( 'Send', 'jetonomy' ),
					'messageSendFailed'     => __( 'Failed to send. Please try again.', 'jetonomy' ),
					'noMessageMatches'      => sprintf( __( 'No matches. You can only message people who share at least one %s with you.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					'recipientRequired'     => __( 'Please enter a recipient.', 'jetonomy' ),
					'userNotFound'          => __( 'User not found: ', 'jetonomy' ),
					// Pro Private Messaging — conversation actions (kebab menu, WS3-C).
					'muteFailed'            => __( 'Could not update mute setting.', 'jetonomy' ),
					'archiveFailed'         => __( 'Could not archive the conversation.', 'jetonomy' ),
					'leaveFailed'           => __( 'Could not leave the conversation.', 'jetonomy' ),
					'blockFailed'           => __( 'Could not update block setting.', 'jetonomy' ),
					'confirmLeave'          => __( 'Leave this conversation? You will not receive new messages.', 'jetonomy' ),
					'confirmBlock'          => __( 'Block this user? They will no longer be able to message you here.', 'jetonomy' ),
					// WS4-C: moderation flag toasts + profile-save failure (consumed by view.js via state.i18n).
					'contentRemoved'        => __( 'Content removed', 'jetonomy' ),
					'flagDismissed'         => __( 'Flag dismissed', 'jetonomy' ),
					'failed'                => __( 'Failed', 'jetonomy' ),
					'failedSaveProfile'     => __( 'Failed to save profile.', 'jetonomy' ),
					'schedule'              => __( 'Schedule', 'jetonomy' ),
					'editPost'              => __( 'Edit post', 'jetonomy' ),
					'editReply'             => __( 'Edit reply', 'jetonomy' ),
					'unaccepted'            => __( 'Marked as unanswered', 'jetonomy' ),
					// 1.4.4 i18n sweep — keys view.js reads via state.i18n that were
					// missing here, so they always rendered the English fallback even
					// on a translated locale.
					'voteFailed'            => __( 'Vote failed.', 'jetonomy' ),
					'chooseSpace'           => sprintf( __( 'Choose a %s first.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					'draftSaved'            => __( 'Draft saved. You can find it in your profile under Drafts.', 'jetonomy' ),
					'saveDraft'             => __( 'Save Draft', 'jetonomy' ),
					'scheduleDateRequired'  => __( 'Please choose a publish date and time.', 'jetonomy' ),
					'failedTogglePrivate'   => __( 'Failed to change visibility.', 'jetonomy' ),
					'madePrivate'           => __( 'Topic is now private', 'jetonomy' ),
					'madePublic'            => __( 'Topic is now public', 'jetonomy' ),
					'pendingNotice'         => __( 'Your post is awaiting moderation and will appear once approved.', 'jetonomy' ),
					'reportPlaceholder'     => __( 'Describe the issue...', 'jetonomy' ),
					'reportReplyPrompt'     => __( 'Why are you reporting this reply?', 'jetonomy' ),
					'reportUserPrompt'      => __( 'Why are you reporting this user?', 'jetonomy' ),
					'reportUserPlaceholder' => __( 'Describe the issue...', 'jetonomy' ),
					'topicClosed'           => __( 'Topic closed', 'jetonomy' ),
					'topicReopened'         => __( 'Topic reopened', 'jetonomy' ),
					'failedClose'           => __( 'Failed to update topic.', 'jetonomy' ),
					// WS4-C: Pro Polls strings consumed via state.i18n in pro-view.js.
					'failedSubmitVote'      => __( 'Failed to submit vote. Please try again.', 'jetonomy' ),
					'voteSingular'          => __( '1 vote', 'jetonomy' ),
					/* translators: %d: vote count. */
					'voteFormat'            => __( '%d votes', 'jetonomy' ),
					// 1.6.1 i18n sweep - keys view.js reads via state.i18n that were
					// never injected, so they rendered the English fallback on every locale.
					'alreadyReported'       => __( 'You already reported this.', 'jetonomy' ),
					'draftPublished'        => __( 'Published.', 'jetonomy' ),
					'genericError'          => __( 'Could not publish.', 'jetonomy' ),
					// Threaded-reply toggle label (single-post.php); the count is
					// substituted client-side in the state.threadToggleLabel getter.
					'hideReplies'           => __( 'Hide replies', 'jetonomy' ),
					'showRepliesOne'        => __( 'Show 1 reply', 'jetonomy' ),
					/* translators: %d: number of replies. */
					'showRepliesMany'       => __( 'Show %d replies', 'jetonomy' ),
				),
			)
		);

		// Enqueue Interactivity API module. Asset version uses filemtime()
		// (with the plugin version as a fallback) so any in-place hotfix
		// shipped under the same plugin version still busts browser + CDN
		// caches — a site stuck on a cached view.js?ver=x.y.z would
		// otherwise never pick up an x.y.z hotfix.
		$view_file    = JETONOMY_DIR . 'assets/js/view.js';
		$view_mtime   = file_exists( $view_file ) ? (string) filemtime( $view_file ) : '';
		$view_version = '' !== $view_mtime ? JETONOMY_VERSION . '+' . $view_mtime : JETONOMY_VERSION;

		// WS3-A primitives (1.4.3): shared optimistic-action helper and smart
		// dropdown positioner. Registered as classic scripts so window globals
		// (jetonomyOptimistic, jetonomySmartDropdown) exist before any
		// downstream consumer evaluates. Depend on jetonomy-data so they share
		// its enqueue context; the script-module below loads after them in
		// document order. Callsite migration lands in WS3-B.
		wp_enqueue_script( 'jetonomy-optimistic', JETONOMY_URL . 'assets/js/lib/optimistic.min.js', array( 'jetonomy-data' ), JETONOMY_VERSION, true );
		wp_enqueue_script( 'jetonomy-smart-dropdown', JETONOMY_URL . 'assets/js/lib/smart-dropdown.min.js', array( 'jetonomy-data' ), JETONOMY_VERSION, true );

		// Shared Pro custom-field collector (window.jetonomyCollectCustomFields).
		// Single implementation consumed by the post composer + inline editor
		// (view.js module) and the create/edit space forms (classic scripts), so
		// the jt_cf -> custom_fields mapping lives in exactly one place. Loaded as
		// a classic script so it is defined before any consumer runs.
		wp_enqueue_script( 'jetonomy-custom-fields', JETONOMY_URL . 'assets/js/lib/custom-fields.min.js', array( 'jetonomy-data' ), JETONOMY_VERSION, true );

		wp_enqueue_script_module(
			'jetonomy-view',
			JETONOMY_URL . 'assets/js/view.js',
			array(
				array( 'id' => '@wordpress/interactivity' ),
				// iAPI client-side router, dynamically imported by the `navigate`
				// action so it only loads the first time a client nav fires.
				array(
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				),
			),
			$view_version
		);

		// Pagination hydrator: re-wires data-wp-on--click directives on reply
		// cards appended by the classic Load More fetch. Must be a script
		// module so it can pull the live IA store ref via the @wordpress/
		// interactivity import. See pagination-hydrator.js header for the
		// fallback strategy.
		$ph_file    = JETONOMY_DIR . 'assets/js/pagination-hydrator.js';
		$ph_mtime   = file_exists( $ph_file ) ? (string) filemtime( $ph_file ) : '';
		$ph_version = '' !== $ph_mtime ? JETONOMY_VERSION . '+' . $ph_mtime : JETONOMY_VERSION;
		wp_enqueue_script_module(
			'jetonomy-pagination-hydrator',
			JETONOMY_URL . 'assets/js/pagination-hydrator.js',
			array( '@wordpress/interactivity', 'jetonomy-view' ),
			$ph_version
		);

		// Shared global for non-Interactivity JS on community pages (link preview
		// cards, similar-topics typeahead). Keeps the REST nonce + base URL in
		// one place so the same contract works for the future native app.
		// Shared modal toolkit (1.4.0) — registers window.jetonomyConfirm /
		// jetonomyAlert / jetonomyPrompt globally. Every JS callsite that
		// previously used window.confirm / alert / prompt depends on this
		// handle. Loaded BEFORE composer / space-members / etc.
		if ( ! wp_script_is( 'jetonomy-modals', 'registered' ) ) {
			wp_register_script(
				'jetonomy-modals',
				JETONOMY_URL . 'assets/js/jetonomy-modals.js',
				array(),
				JETONOMY_VERSION,
				true
			);
			// Default button labels for jetonomyConfirm / jetonomyAlert /
			// jetonomyPrompt when no override is passed. Localized so the
			// toolkit works in every language Jetonomy itself supports —
			// previously these were hard-coded English in the JS bundle.
			wp_localize_script(
				'jetonomy-modals',
				'jetonomyModalsI18n',
				array(
					'cancel'  => __( 'Cancel', 'jetonomy' ),
					'confirm' => __( 'Confirm', 'jetonomy' ),
					'submit'  => __( 'Submit', 'jetonomy' ),
					'ok'      => __( 'OK', 'jetonomy' ),
				)
			);
		}
		wp_enqueue_script( 'jetonomy-modals' );

		// The dummy `jetonomy-data` handle exists solely to give wp_localize_script
		// a target — actual behaviour lives in view.js.
		if ( ! wp_script_is( 'jetonomy-data', 'registered' ) ) {
			wp_register_script( 'jetonomy-data', '', array(), JETONOMY_VERSION, false );
		}
		wp_enqueue_script( 'jetonomy-data' );
		wp_localize_script(
			'jetonomy-data',
			'jetonomyData',
			array(
				'restBase'      => esc_url_raw( rest_url( 'jetonomy/v1' ) ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'communityBase' => \Jetonomy\base_url(),
				'i18n'          => array(
					'queueClean'             => esc_html__( 'Queue cleared.', 'jetonomy' ),
					'resolveFailed'          => esc_html__( 'Could not resolve flag. Please try again.', 'jetonomy' ),
					'roleUpdateFailed'       => esc_html__( 'Could not update role. Please try again.', 'jetonomy' ),
					'loading'                => esc_html__( 'Loading...', 'jetonomy' ),
					'loadMore'               => esc_html__( 'Load More', 'jetonomy' ),
					'iconShowFewer'          => esc_html__( 'Show fewer icons', 'jetonomy' ),
					'iconShowMore'           => esc_html__( 'Show more icons', 'jetonomy' ),
					'uploading'              => esc_html__( 'Uploading...', 'jetonomy' ),
					'uploaded'               => esc_html__( 'Uploaded.', 'jetonomy' ),
					'uploadFailed'           => esc_html__( 'Upload failed.', 'jetonomy' ),
					'networkError'           => esc_html__( 'Network error.', 'jetonomy' ),
					'networkErrorRetry'      => esc_html__( 'Network error. Please try again.', 'jetonomy' ),
					'createSpaceFailed'      => esc_html( sprintf( __( 'Could not create the %s. Please try again.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ),
					'saveFailed'             => esc_html__( 'Could not save changes.', 'jetonomy' ),
					'prefixLabel'            => esc_html__( 'Label', 'jetonomy' ),
					'removePrefix'           => esc_html__( 'Remove prefix', 'jetonomy' ),
					// Composer + Join-Space gate strings (consumed by composer.js).
					'quoteSelected'          => esc_html__( 'Quote', 'jetonomy' ),
					'joining'                => esc_html__( 'Joining...', 'jetonomy' ),
					'joinSpace'              => esc_html( sprintf( __( 'Join %s', 'jetonomy' ), \Jetonomy\space_label() ) ),
					'requesting'             => esc_html__( 'Requesting...', 'jetonomy' ),
					'awaitingApproval'       => esc_html__( 'Awaiting Approval', 'jetonomy' ),
					'requestToJoin'          => esc_html__( 'Request to Join', 'jetonomy' ),
					'submitting'             => esc_html__( 'Submitting...', 'jetonomy' ),
					'requestSent'            => esc_html__( 'Request Sent', 'jetonomy' ),
					'requestSubmitted'       => esc_html__( 'Request submitted. Awaiting approval.', 'jetonomy' ),
					'requestFailed'          => esc_html__( 'Could not submit request.', 'jetonomy' ),
					'noMentionMatches'       => esc_html__( 'No matches', 'jetonomy' ),
					'memberBanned'           => esc_html__( 'Banned', 'jetonomy' ),
					// Modal helpers in view.js (jetonomyConfirm / jetonomyPrompt /
					// jetonomySpacePicker / jetonomyPostPicker). These live OUTSIDE the
					// Interactivity store so they read window.jetonomyData.i18n, not
					// state.i18n. Hard-coded English used to ship in the JS bundle.
					'modalCancel'            => esc_html__( 'Cancel', 'jetonomy' ),
					'modalConfirm'           => esc_html__( 'Confirm', 'jetonomy' ),
					'modalSubmit'            => esc_html__( 'Submit', 'jetonomy' ),
					'modalMove'              => esc_html__( 'Move', 'jetonomy' ),
					'modalMerge'             => esc_html__( 'Merge', 'jetonomy' ),
					'loadingSpaces'          => esc_html( sprintf( __( 'Loading %s…', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ),
					'selectSpacePlaceholder' => esc_html( sprintf( __( 'Select a %s…', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ),
					'noOtherSpaces'          => esc_html( sprintf( __( 'No other %s available', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ),
					'failedLoadSpaces'       => esc_html( sprintf( __( 'Failed to load %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ),
					'searchTopicPlaceholder' => esc_html__( 'Search for a topic...', 'jetonomy' ),
					'noTopicsFound'          => esc_html__( 'No topics found', 'jetonomy' ),
					'searchFailed'           => esc_html__( 'Search failed', 'jetonomy' ),
					// Merge-picker copy. `mergeFromLabel` heads the "From <topic>"
					// banner. The hint is shown until the visitor types enough
					// to trigger a search; the reply placeholders use %d so the
					// translator picks singular/plural per their locale.
					'mergeFromLabel'         => esc_html__( 'From', 'jetonomy' ),
					'pickerHintTwoChars'     => esc_html__( 'Type at least 2 characters to search.', 'jetonomy' ),
					/* translators: %d: number of replies on a topic. */
					'pickerReplySingular'    => esc_html__( '%d reply', 'jetonomy' ),
					/* translators: %d: number of replies on a topic. */
					'pickerReplyPlural'      => esc_html__( '%d replies', 'jetonomy' ),
					'roleLabels'             => array(
						'member'    => esc_html__( 'Member', 'jetonomy' ),
						'moderator' => esc_html__( 'Moderator', 'jetonomy' ),
						'admin'     => esc_html__( 'Admin', 'jetonomy' ),
					),
					// WS4-C: composer mobile-nav close + link prompt.
					'closeMenu'              => esc_html__( 'Close menu', 'jetonomy' ),
					'linkPromptUrl'          => esc_html__( 'Enter URL:', 'jetonomy' ),
					'linkPromptPlaceholder'  => esc_html__( 'https://example.com', 'jetonomy' ),
					// WS4-C: moderation flag actions in view.js.
					'contentRemoved'         => esc_html__( 'Content removed', 'jetonomy' ),
					'flagDismissed'          => esc_html__( 'Flag dismissed', 'jetonomy' ),
					'failed'                 => esc_html__( 'Failed', 'jetonomy' ),
					'failedSaveProfile'      => esc_html__( 'Failed to save profile.', 'jetonomy' ),
					// WS4-C: space-members ban dialog (translator placeholders).
					/* translators: %s: member display name. */
					'banConfirmFormat'       => sprintf( __( 'Ban %1$s from this %2$s? They will lose access to its posts and replies until you lift the ban.', 'jetonomy' ), '%s', \Jetonomy\space_label( false, true ) ),
					'banMemberTitle'         => esc_html__( 'Ban member', 'jetonomy' ),
					'banLabel'               => esc_html__( 'Ban', 'jetonomy' ),
					'banFailed'              => esc_html__( 'Ban failed. Please try again.', 'jetonomy' ),
					// Frontend member moderation from a profile (site ban / silence / lift).
					'banSiteConfirmFormat'   => esc_html__( 'Ban %s from the whole community? They can no longer post, reply, or vote anywhere until you lift the ban.', 'jetonomy' ),
					'banSiteTitle'           => esc_html__( 'Ban member', 'jetonomy' ),
					'silenceConfirmFormat'   => esc_html__( 'Silence %s? They stay a member but cannot post, reply, or file reports until you lift it.', 'jetonomy' ),
					'silenceTitle'           => esc_html__( 'Silence member', 'jetonomy' ),
					'silenceLabel'           => esc_html__( 'Silence', 'jetonomy' ),
					'liftConfirmFormat'      => esc_html__( 'Lift the restriction on %s? They regain full access right away.', 'jetonomy' ),
					'liftTitle'              => esc_html__( 'Lift restriction', 'jetonomy' ),
					'liftLabel'              => esc_html__( 'Lift', 'jetonomy' ),
					// 1.6.1 i18n sweep - role-change + deny-join confirm dialogs (view.js reads
					// window.jetonomyData.i18n outside the IA store; these were never injected).
					/* translators: %name%, %from%, %to% are replaced client-side. */
					'confirmRoleChange'      => esc_html__( 'Change %name% from %from% to %to%?', 'jetonomy' ),
					'confirmRoleChangeTitle' => esc_html__( 'Change role', 'jetonomy' ),
					'confirmLabel'           => esc_html__( 'Change role', 'jetonomy' ),
					'cancelLabel'            => esc_html__( 'Cancel', 'jetonomy' ),
					'denyJoinBody'           => esc_html__( 'Deny this join request? The member can request again later.', 'jetonomy' ),
					'denyJoinTitle'          => esc_html__( 'Deny request', 'jetonomy' ),
					'denyLabel'              => esc_html__( 'Deny', 'jetonomy' ),
				),
			)
		);

		// Unified REST fetch client (1.4.3). Exposes window.jetonomyRest.restFetch
		// for every mutation/read; centralises nonce handling + auto-retry on
		// rest_cookie_invalid_nonce. Depends on `jetonomy-data` for restBase
		// + restNonce. See includes/api/class-rest-auth.php for the matching
		// server-side permission helper.
		self::enqueue_rest_client();

		// Pagination "Load More" auto-scroll handler. Self-discovers all
		// .jt-pagination containers on the page; safe to always enqueue.
		wp_enqueue_script(
			'jetonomy-pagination',
			JETONOMY_URL . 'assets/js/pagination-frontend.js',
			array( 'jetonomy-data' ),
			JETONOMY_VERSION,
			true
		);

		// Icon picker wiring. Self-discovers every [data-jt-icon-picker] on
		// the page so any template (frontend new-space, space-edit, or a
		// future surface) gets search + show-more behaviour for free.
		wp_enqueue_script(
			'jetonomy-icon-picker',
			JETONOMY_URL . 'assets/js/jetonomy-icon-picker.js',
			array( 'jetonomy-data' ),
			JETONOMY_VERSION,
			true
		);

		// Per-route page scripts: NONE remain. Every former per-route surface
		// (new-space, edit-space, space-members, notifications, moderation) is now
		// a declarative action in the global jetonomy store, so the iAPI router
		// re-hydrates them on client navigation. The only non-global asset left is
		// vendor Prism on the post route (still full-loaded by the route guard).

		// Enqueue composer enhancement script (depends on the shared modal
		// toolkit for the link-insert prompt).
		wp_enqueue_script(
			'jetonomy-composer',
			JETONOMY_URL . 'assets/js/composer.js',
			array( 'jetonomy-modals', 'jetonomy-rest' ),
			JETONOMY_VERSION,
			true
		);

		// Localize REST data for composer.js (image upload + instant search).
		// 1.4.0 A.1 commit 3: legacy `ajaxUrl` + `nonce` keys removed; the
		// wp_ajax_jetonomy_upload_image handler they fed has been deleted.
		wp_localize_script(
			'jetonomy-composer',
			'jetonomyUpload',
			array(
				'apiBase'       => rest_url( 'jetonomy/v1' ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'communityBase' => \Jetonomy\base_url(),
			)
		);

		// Space-members role change + ban are now declarative actions in the
		// global jetonomy store (actions.changeMemberRole / actions.banMember),
		// so no per-route script is enqueued — the router re-hydrates the
		// directives on client navigation. (Was assets/js/space-members.js.)

		// Moderation queue resolve is now a declarative action in the global
		// jetonomy store (actions.resolveFlag, used by moderation/flag-card.php),
		// so the router re-hydrates it on client navigation — no per-route script.
		// (Was assets/js/moderation.js.)

		// Enqueue Prism.js for code syntax highlighting on post pages (only if files exist).
		$prism_dir = JETONOMY_DIR . 'assets/vendor/prismjs/';
		if ( in_array( $data['route'], array( 'post', 'new-post' ), true ) && file_exists( $prism_dir . 'prism.min.js' ) ) {
			wp_enqueue_style( 'prismjs', JETONOMY_URL . 'assets/vendor/prismjs/prism.min.css', array(), '1.29.0' );
			wp_enqueue_script( 'prismjs', JETONOMY_URL . 'assets/vendor/prismjs/prism.min.js', array(), '1.29.0', true );
			if ( file_exists( $prism_dir . 'prism-autoloader.min.js' ) ) {
				wp_enqueue_script( 'prismjs-autoloader', JETONOMY_URL . 'assets/vendor/prismjs/prism-autoloader.min.js', array( 'prismjs' ), '1.29.0', true );
			}
		}

		// Pre-flight 404 detection: check before get_header() sends HTTP headers.
		self::maybe_set_404( $data );

		// Track post view + set deduplication cookie before any output.
		self::maybe_track_post_view( $data );

		// Set up SEO
		self::set_seo_meta( $data );

		// Signal to the active theme that this is a Jetonomy community page.
		// Themes check for 'jt-page' in body_class to skip container/sidebar wrappers
		// and render Jetonomy's layout at full viewport width.
		add_filter(
			'body_class',
			static function ( array $classes ): array {
				$classes[] = 'jt-page';
				return $classes;
			}
		);

		// Use WP's get_header/get_footer for theme integration.
		// Block themes (FSE) have no header.php — use block_header_area() to avoid
		// the "Theme without header.php is deprecated" notice introduced in WP 3.0.
		if ( wp_is_block_theme() ) {
			?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
			<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
			<?php
			wp_body_open();
			block_header_area();
			?>
			<?php
		} else {
			get_header();
		}

		// data-wp-on--click on the app wrapper delegates every internal link
		// click to actions.navigate (Phase 2 client-side routing). The action
		// route-guards which targets are safe to swap vs. full-load, and always
		// preserves the real <a href> as the fallback.
		echo '<div id="jetonomy-app" class="jt-app" data-jt-density="' . esc_attr( $density ) . '" data-wp-interactive="jetonomy" data-wp-on--click="actions.navigate">';

		/**
		 * Fires inside the Jetonomy app wrapper, before the header partial and
		 * content container. Bridge plugins use this hook to inject a unified
		 * community nav (e.g. BuddyNext subnav) in place of the default
		 * Jetonomy community nav.
		 *
		 * @param array $data Route data array: ['route' => string, 'slug' => string].
		 */
		do_action( 'jetonomy_before_content', $data );

		// Open content container (jt-container avoids collisions with theme/framework .container classes).
		echo '<div class="jt-container">';

		// Load the Jetonomy header partial inside the container so nav aligns with content.
		$header_path = file_exists( $theme_dir . 'partials/header.php' )
			? $theme_dir . 'partials/header.php'
			: $plugin_dir . 'partials/header.php';
		if ( file_exists( $header_path ) ) {
			include $header_path;
		}

		// Load the main template, wrapped in an iAPI router region so client-side
		// navigation swaps only the view content while the header/nav above stays
		// put. The region element carries BOTH data-wp-interactive and
		// data-wp-router-region (the router only recognises a region when both are
		// present). The grid lives on the view's inner .jt-two-col, so this plain
		// wrapper does not affect layout. Region id must match across every route.
		echo '<div data-wp-interactive="jetonomy" data-wp-router-region="jetonomy/main">';
		include $template_path;
		echo '</div>'; // [data-wp-router-region]

		echo '</div>'; // .jt-container

		/**
		 * Fires after the main content container closes, before the app wrapper
		 * closes. Bridge plugins use this to close their own shell wrapper
		 * (e.g. BuddyNext hub shell + community sidebar).
		 *
		 * @param array $data Route data array.
		 */
		do_action( 'jetonomy_after_content', $data );

		echo '</div>'; // #jetonomy-app

		if ( wp_is_block_theme() ) {
			block_footer_area();
			wp_footer();
			?>
			</body></html>
			<?php
		} else {
			get_footer();
		}
	}

	/**
	 * Build the root token overrides for the admin-chosen color palette
	 * (Settings → Appearance → Color Palette).
	 *
	 * Emits one `:root,.jt-app{...}` block containing only the colors the
	 * site owner actually set — an empty field means "keep the default", so
	 * a site with no palette saved gets an empty string and zero behaviour
	 * change. Derived tokens (text-secondary/tertiary, bg-hover, hover
	 * accents) recompute automatically because they are color-mix()
	 * expressions over these root tokens. Dark mode is unaffected: the
	 * `.jt-dark .jt-app` token block outranks `:root,.jt-app` by
	 * specificity, so the palette only restyles light mode.
	 *
	 * The legacy accent default `#0073aa` is treated as "unset" — it was the
	 * pre-palette field default and was never emitted as an override.
	 *
	 * @since 1.5.0
	 *
	 * @param array $settings The jetonomy_settings option array.
	 * @return string CSS block, or '' when no palette color is set.
	 */
	public static function palette_css( array $settings ): string {
		$vars = '';
		foreach ( self::palette_tokens( $settings ) as $token => $hex ) {
			$vars .= $token . ':' . $hex . ';';
		}

		// `:root` only, never `:root,.jt-app`. The dark tokens are reassigned on
		// the <body> class and reach .jt-app by inheritance; a declaration made
		// directly ON .jt-app beats an inherited one no matter its specificity,
		// so listing .jt-app here would pin every app surface light in dark mode
		// the moment an owner sets a palette colour. :root is an ancestor of
		// .jt-app anyway, so the extra selector never bought any reach.
		return '' !== $vars ? ':root{' . $vars . '}' : '';
	}

	/**
	 * Resolve the admin-chosen palette as a `--jt-*` token => hex map.
	 *
	 * Shared by palette_css() (frontend emission) and by
	 * Theme_Integration::output_color_bridge(), which subtracts these
	 * tokens from its automatic theme bridge so an explicit owner choice
	 * always outranks inherited theme colors.
	 *
	 * Empty when no palette field is set — which is the normal case, and is
	 * what makes theme adoption the default: emit nothing, and the chains in
	 * jetonomy.css resolve to the active theme's own tokens. The accent field
	 * treats its #0073aa default as "not set" for the same reason, so an owner
	 * who never touches it keeps matching their theme.
	 *
	 * @since 1.5.0
	 *
	 * @param array $settings The jetonomy_settings option array.
	 * @return array<string,string> Token name => sanitized hex color.
	 */
	/**
	 * Black or white, whichever reads better on the given accent hex.
	 *
	 * The runtime `oklch()` contrast guard in jetonomy-tokens.css derives
	 * --jt-accent-fg for a THEME-token accent the server never sees. When the
	 * owner PICKS an accent, the server knows the exact colour, so it computes
	 * the readable foreground here and emits it as a concrete hex — which works
	 * in every browser, including those without relative-colour oklch (Safari
	 * < 16.4 / Chrome < 111), where the guard would otherwise fall back to plain
	 * white and turn a light accent's button text invisible.
	 *
	 * @param string $hex A #rrggbb / #rgb accent colour.
	 * @return string '#ffffff' or '#000000'.
	 */
	private static function accent_fg( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return '#ffffff';
		}
		$lin = static function ( float $c ): float {
			$c /= 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		$lum = 0.2126 * $lin( (float) hexdec( substr( $hex, 0, 2 ) ) )
			+ 0.7152 * $lin( (float) hexdec( substr( $hex, 2, 2 ) ) )
			+ 0.0722 * $lin( (float) hexdec( substr( $hex, 4, 2 ) ) );
		// Contrast of white vs black against this background; pick the higher.
		$white_ratio = 1.05 / ( $lum + 0.05 );
		$black_ratio = ( $lum + 0.05 ) / 0.05;
		return $white_ratio >= $black_ratio ? '#ffffff' : '#000000';
	}

	public static function palette_tokens( array $settings ): array {
		$map = array(
			'accent_color'    => '--jt-accent',
			'text_color'      => '--jt-text',
			'bg_color'        => '--jt-bg',
			'bg_subtle_color' => '--jt-bg-subtle',
			'border_color'    => '--jt-border',
		);

		$tokens = array();
		foreach ( $map as $key => $token ) {
			if ( empty( $settings[ $key ] ) ) {
				continue;
			}
			if ( 'accent_color' === $key && '#0073aa' === $settings[ $key ] ) {
				continue;
			}
			$hex = sanitize_hex_color( (string) $settings[ $key ] );
			if ( $hex ) {
				$tokens[ $token ] = $hex;
			}
		}

		// A picked accent gets a server-computed readable foreground, so every
		// accent-backed surface (buttons, the follow pill, level tags, banners)
		// stays legible on a light accent in ALL browsers — not only those the
		// oklch guard covers.
		if ( isset( $tokens['--jt-accent'] ) ) {
			$fg                             = self::accent_fg( $tokens['--jt-accent'] );
			$tokens['--jt-accent-fg']       = $fg;
			$tokens['--jt-accent-hover-fg'] = $fg;
		}

		return $tokens;
	}

	/**
	 * Enqueue the unified REST fetch client (window.jetonomyRest) plus the
	 * minimal `jetonomyData` payload it reads (restBase + restNonce).
	 *
	 * Idempotent and additive. On community routes render() has already
	 * registered + localized the full jetonomyData payload, so the minimal
	 * localize only fires on embed surfaces (compose-topic shortcode/block
	 * on a regular WP page) where no other Jetonomy script has run. Without
	 * this, view.js actions that go through restFetch fail before the
	 * request is made (Basecamp #9967059857).
	 *
	 * @since 1.5.0
	 */
	public static function enqueue_rest_client(): void {
		if ( ! wp_script_is( 'jetonomy-data', 'registered' ) ) {
			wp_register_script( 'jetonomy-data', '', array(), JETONOMY_VERSION, false );
			wp_localize_script(
				'jetonomy-data',
				'jetonomyData',
				array(
					'restBase'  => esc_url_raw( rest_url( 'jetonomy/v1' ) ),
					'restNonce' => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
		wp_enqueue_script( 'jetonomy-data' );

		$jr_file    = JETONOMY_DIR . 'assets/js/jetonomy-rest.js';
		$jr_mtime   = file_exists( $jr_file ) ? (string) filemtime( $jr_file ) : '';
		$jr_version = '' !== $jr_mtime ? JETONOMY_VERSION . '+' . $jr_mtime : JETONOMY_VERSION;
		wp_enqueue_script(
			'jetonomy-rest',
			JETONOMY_URL . 'assets/js/jetonomy-rest.js',
			array( 'jetonomy-data' ),
			$jr_version,
			true
		);
	}

	/**
	 * Resolve the owner's SEO title pattern for a content route.
	 *
	 * Ported from Schema_Markup::filter_title(), which is now deleted — it was a
	 * second document_title_parts producer and this one always overwrote it.
	 *
	 * Two behaviours are deliberately preserved from it. `{site_name}` is stripped
	 * out of the pattern (with any adjacent separator) because WordPress appends
	 * the site name itself via the title separator; leaving the placeholder in
	 * would render it twice. And an unresolvable object returns '' rather than a
	 * half-substituted string, so the caller falls back to the generic label —
	 * that path is live, since 404s render through here too.
	 *
	 * One behaviour is deliberately fixed: this reads \Jetonomy\seo_settings(),
	 * not get_option() raw. filter_title() gated on `! empty( $settings[...] )`
	 * against the raw option, so on any install where the owner had not explicitly
	 * saved a pattern the documented defaults never applied and the whole feature
	 * silently no-opped.
	 *
	 * THE TITLE IS ITS OWN FETCH. This resolves the post independently of
	 * set_seo_meta()'s $post — which is exactly how the <title> ended up being
	 * the one thing in the <head> with no permission gate on it at all, while
	 * og:*, meta description and article:* beside it had been gated since 1.4.0.
	 * Verified at runtime on 1.8.0: a logged-out crawler on an is_private topic
	 * got a body reading "Post not found" and a <title> reading the private
	 * topic's real title, and a viewer who had blocked the author got their
	 * post title in their browser tab beside a "you blocked this user" body.
	 *
	 * @param string              $route 'post' | 'space'.
	 * @param string              $slug  Object slug from the route.
	 * @param array<string,mixed> $seo   Resolved seo_settings().
	 * @return string|null Title fragment; '' when the object cannot be resolved
	 *                     (caller falls back to the slug label — that is just the
	 *                     URL echoed back, so it reveals nothing); null when the
	 *                     object EXISTS but this viewer may not be shown its text,
	 *                     which the caller must render as a neutral label. The two
	 *                     are distinct on purpose: the slug is derived from the
	 *                     title, so falling back to it for a gated post would
	 *                     re-leak in hyphens the words the gate just withheld.
	 */
	private static function seo_title_from_pattern( string $route, string $slug, array $seo ): ?string {
		$key     = 'post' === $route ? 'seo_post_title' : 'seo_space_title';
		$pattern = (string) ( $seo[ $key ] ?? '' );
		if ( '' === $pattern || '' === $slug ) {
			return '';
		}

		// WP appends ' – {site_name}' itself; drop the placeholder and any
		// separator hugging it so the site name is not printed twice.
		$pattern = (string) preg_replace( '/\s*[\|\-–—]?\s*\{site_name\}\s*[\|\-–—]?\s*/u', ' ', $pattern );
		$pattern = trim( (string) preg_replace( '/\s+/u', ' ', $pattern ) );
		if ( '' === $pattern ) {
			return '';
		}

		if ( 'post' === $route ) {
			$post = \Jetonomy\Models\Post::find_by_slug( $slug );
			if ( ! $post ) {
				return '';
			}
			// The gate the rest of the <head> already had. Same predicate as the
			// og:* / description branch in set_seo_meta() so the tab title and the
			// tags beneath it can never disagree about the same post again.
			if ( ! \Jetonomy\Permissions\Permission_Engine::can_render_post_text( get_current_user_id(), $post ) ) {
				return null;
			}
			$space = \Jetonomy\Models\Space::find( (int) $post->space_id );
			return str_replace(
				[ '{post_title}', '{space_name}' ],
				[ (string) $post->title, (string) ( $space->title ?? '' ) ],
				$pattern
			);
		}

		$space = \Jetonomy\Models\Space::find_by_slug( $slug );
		if ( ! $space ) {
			return '';
		}
		// Concealed space: the tab title must not name it. NULL (not '') for the
		// same reason as the gated-post branch above — the slug fallback would
		// re-leak in hyphens the words this gate withholds.
		if ( \Jetonomy\Models\Space::concealed_from_viewer( $space, get_current_user_id() ) ) {
			return null;
		}
		return str_replace( '{space_name}', (string) $space->title, $pattern );
	}

	/**
	 * The display name for a virtual route's SEO title/OG, resolved from the
	 * object rather than the slug.
	 *
	 * The slug loses punctuation and capitalization ("Help & Support" ->
	 * "help-support"), so prettifying it back gives "Help support" in the
	 * <title> and OG tags while the on-page <h1> shows the real name. Resolve
	 * the space/category object and use its stored name; fall back to the
	 * prettified slug only when the object is missing (404s render here too).
	 *
	 * @param string $route    Virtual route key.
	 * @param string $slug     Route slug.
	 * @param string $fallback Prettified-slug fallback.
	 * @return string
	 */
	private static function seo_display_name( string $route, string $slug, string $fallback ): string {
		if ( in_array( $route, array( 'space', 'space-members', 'space-roadmap', 'space-moderation' ), true ) ) {
			$sp = \Jetonomy\Models\Space::find_by_slug( $slug );
			// Concealed spaces must not leak their title into <title> — the
			// viewer gets the prettified slug they themselves typed, nothing
			// more (pairs with the 404 in maybe_set_404).
			if ( $sp && ! empty( $sp->title ) && ! \Jetonomy\Models\Space::concealed_from_viewer( $sp, get_current_user_id() ) ) {
				return (string) $sp->title;
			}
		} elseif ( 'category' === $route ) {
			$ct = \Jetonomy\Models\Category::find_by_slug( $slug );
			if ( $ct && ! empty( $ct->name ) ) {
				return (string) $ct->name;
			}
		}
		return $fallback;
	}

	private static function set_seo_meta( array $data ): void {
		// On a mapped front page the community is rendered over a real WP page.
		// That page has its own object, its own permalink, and its own SEO fields
		// in whatever SEO plugin the owner runs — so it owns the title, canonical
		// and OG, and we emit nothing. Jetonomy supplies SEO only where no other
		// party can: its virtual routes, which have no WP object to attach to.
		if ( ! empty( $data['mapped'] ) ) {
			return;
		}

		add_filter(
			'document_title_parts',
			function ( $parts ) use ( $data ) {
				// WP appends ' – {site_name}' via the default separator + tagline,
				// so we only set the route-specific title fragment here. Don't
				// concatenate the site name yourself — it doubles up.
				$slug_pretty = ucfirst( str_replace( '-', ' ', (string) $data['slug'] ) );

				// Owner-configurable title patterns (Settings → SEO). These are the
				// documented contract for the two routes that represent real content,
				// and until now they did nothing at all: Schema_Markup::filter_title
				// implemented them but read the option raw, so its own defaults never
				// applied, and this closure ran later and overwrote its result anyway.
				// Two producers of document_title_parts, and the wrong one won — which
				// is why every topic's title was its SLUG rather than its title.
				// One producer now; filter_title is gone.
				$seo = \Jetonomy\seo_settings();
				if ( 'post' === $data['route'] || 'space' === $data['route'] ) {
					$patterned = self::seo_title_from_pattern( $data['route'], (string) $data['slug'], $seo );
					// null = the post exists but this viewer may not be shown its
					// text (private/pending/trashed, or they blocked the author).
					// Neutral label rather than the slug fallback below, which is
					// built FROM the title and would hand back the same words
					// (1.8.0).
					if ( null === $patterned ) {
						$parts['title'] = __( 'Community', 'jetonomy' );
						return $parts;
					}
					if ( '' !== $patterned ) {
						$parts['title'] = $patterned;
						return $parts;
					}
					// Fall through to the generic labels below when the object is
					// missing (404s render through this same path).
				}

				switch ( $data['route'] ) {
					case 'home':
						$parts['title'] = __( 'Community', 'jetonomy' );
						break;
					case 'space':
						$parts['title'] = self::seo_display_name( 'space', (string) $data['slug'], $slug_pretty ) . ' — ' . __( 'Community', 'jetonomy' );
						break;
					case 'space-members':
						$parts['title'] = self::seo_display_name( 'space-members', (string) $data['slug'], $slug_pretty ) . ' — ' . __( 'Members', 'jetonomy' );
						break;
					case 'space-roadmap':
						$parts['title'] = self::seo_display_name( 'space-roadmap', (string) $data['slug'], $slug_pretty ) . ' — ' . __( 'Roadmap', 'jetonomy' );
						break;
					case 'space-moderation':
						$parts['title'] = self::seo_display_name( 'space-moderation', (string) $data['slug'], $slug_pretty ) . ' — ' . __( 'Moderation', 'jetonomy' );
						break;
					case 'post':
						$parts['title'] = $slug_pretty;
						break;
					case 'profile':
						$parts['title'] = '@' . (string) $data['slug'];
						break;
					case 'tag':
						$parts['title'] = '#' . (string) $data['slug'];
						break;
					case 'category':
						$parts['title'] = self::seo_display_name( 'category', (string) $data['slug'], $slug_pretty ) . ' — ' . __( 'Community', 'jetonomy' );
						break;
					case 'leaderboard':
						$parts['title'] = __( 'Top members', 'jetonomy' );
						break;
					case 'search':
						$parts['title'] = __( 'Search', 'jetonomy' );
						break;
					case 'moderation':
						$parts['title'] = __( 'Moderation Queue', 'jetonomy' );
						break;
					case 'new-post':
						$parts['title'] = __( 'Start a discussion', 'jetonomy' );
						break;
					case 'notifications':
						$parts['title'] = __( 'Notifications', 'jetonomy' );
						break;
					case 'edit-profile':
						$parts['title'] = __( 'Edit profile', 'jetonomy' );
						break;
					case 'invite':
						$parts['title'] = __( 'You are invited', 'jetonomy' );
						break;
					case 'my-spaces':
						$parts['title'] = sprintf( __( 'My %s', 'jetonomy' ), \Jetonomy\space_label( true ) );
						break;
					case 'subscriptions':
						$parts['title'] = __( 'My Subscriptions', 'jetonomy' );
						break;
					case 'new-space':
						$parts['title'] = sprintf( __( 'Create a %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
						break;
					case 'edit-space':
						$parts['title'] = sprintf( __( 'Edit %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
						break;
					case 'drafts':
						$parts['title'] = __( 'My drafts', 'jetonomy' );
						break;
					case 'bookmarks':
						$parts['title'] = __( 'My bookmarks', 'jetonomy' );
						break;
				}
				return $parts;
			}
		);

		// Meta description + canonical + OG/Twitter + robots — emitted on EVERY
		// public route. Phase D contract (1.4.0): the free plugin alone is
		// SEO-complete. The Pro `seo-pro` extension layers per-space overrides
		// at priority 0, but we no longer hand it the entire emission — it
		// only owns the routes it explicitly handles (currently `space` +
		// `post`). All other routes are owned by free, so a community on the
		// free plugin doesn't go invisible to crawlers on home / profile /
		// leaderboard / search / tag / etc. (incident: 2026-04-28 SEO audit).
		add_action(
			'wp_head',
			function () use ( $data ) {
				// Two checks gate the "Pro owns this route" branch:
				// (1) the seo-pro extension is enabled in the option array,
				// AND (2) the Pro plugin is actually loaded — without #2 we
				// can hit a state where the option still says "enabled" but
				// Pro itself was deactivated or removed, and free silently
				// skips emit while no one fills in for it. Result: the post
				// route ships zero meta. Belt-and-suspenders here is cheap.
				$seo_pro_active = defined( 'JETONOMY_PRO_VERSION' ) && in_array(
					'seo-pro',
					(array) get_option( 'jetonomy_pro_extensions', array() ),
					true
				);

				// Routes seo-pro emits its own OG/meta for. Its
				// get_current_context() resolves the post-detail route plus the
				// whole space family (it remaps the space slug for the bare
				// listing, members, roadmap, moderation, edit and new-post
				// surfaces). Free must defer on every one of those or the page
				// gets a duplicate og:title/og:url set — the space-page dupe
				// reported in 1.5.0. Any route NOT in this list (home, category,
				// tag, search, leaderboard, …) has no Pro emitter, so free stays
				// the single source there.
				$seo_pro_handles = array( 'post', 'space', 'space-members', 'space-roadmap', 'space-moderation', 'edit-space', 'new-post' );
				$skip_baseline   = $seo_pro_active && in_array( $data['route'], $seo_pro_handles, true );

				$site_name    = get_bloginfo( 'name' );
				$base         = \Jetonomy\base_url();
				$desc         = '';
				$title        = '';
				$url          = '';
				$image        = '';
				$image_alt    = '';
				$og_type      = 'website';
				$twitter_card = 'summary';
				$article_meta = array(); // author / published_time / section — only for post route.
				$oembed_url   = '';
				$noindex      = false; // Thin / private / auth-required surface.

				switch ( $data['route'] ) {
					case 'home':
						$title     = $site_name . ' ' . __( 'Community', 'jetonomy' );
						$desc      = __( 'Join our community discussions, Q&A, and more.', 'jetonomy' );
						$url       = $base . '/';
						$image_alt = $site_name;
						break;
					case 'category':
						$title     = self::seo_display_name( 'category', (string) $data['slug'], ucfirst( str_replace( '-', ' ', (string) $data['slug'] ) ) );
						$desc      = sprintf( __( '%1$s in the %2$s category on %3$s.', 'jetonomy' ), \Jetonomy\space_label( true ), $title, $site_name );
						$url       = $base . '/category/' . rawurlencode( (string) $data['slug'] ) . '/';
						$image_alt = $title;
						break;
					case 'space':
					case 'space-members':
					case 'space-roadmap':
					case 'space-moderation':
						$space = \Jetonomy\Models\Space::find_by_slug( (string) $data['slug'] );
						// A concealed space's <head> must look exactly like a
						// missing space's: no title/desc/og/image (pairs with the
						// 404 in maybe_set_404 — Basecamp 10105630168).
						if ( $space && \Jetonomy\Models\Space::concealed_from_viewer( $space, get_current_user_id() ) ) {
							$space = null;
						}
						if ( $space ) {
							$is_private = ! empty( $space->visibility ) && 'public' !== $space->visibility;

							// Feed auto-discovery for readers/browsers (1.5.0).
							if ( 'space' === $data['route'] ) {
								\Jetonomy\Feed::discovery_link( $space );
							}
							$title     = $space->title;
							$desc      = wp_strip_all_tags( $space->description ?? '' );
							$image     = $space->cover_image ?? '';
							$image_alt = $space->title;

							switch ( $data['route'] ) {
								case 'space-members':
									$title = $space->title . ' — ' . __( 'Members', 'jetonomy' );
									$desc  = sprintf( __( 'Members of the %1$s %2$s on %3$s.', 'jetonomy' ), $space->title, \Jetonomy\space_label( false, true ), $site_name );
									$url   = $base . '/s/' . $space->slug . '/members/';
									break;
								case 'space-roadmap':
									$title = $space->title . ' — ' . __( 'Roadmap', 'jetonomy' );
									$desc  = sprintf( __( 'Roadmap for the %1$s %2$s on %3$s.', 'jetonomy' ), $space->title, \Jetonomy\space_label( false, true ), $site_name );
									$url   = $base . '/s/' . $space->slug . '/roadmap/';
									break;
								case 'space-moderation':
									$title   = $space->title . ' — ' . __( 'Moderation', 'jetonomy' );
									$desc    = sprintf( __( 'Moderation queue for %s.', 'jetonomy' ), $space->title );
									$url     = $base . '/s/' . $space->slug . '/mod/';
									$noindex = true; // Mod tools never indexed.
									break;
								default:
									$url = $base . '/s/' . $space->slug . '/';
							}

							if ( $is_private ) {
								$noindex = true; // Private space — no public crawl.
							}
						}
						break;
					case 'post':
						$post = \Jetonomy\Models\Post::find_by_slug( (string) $data['slug'] );
						// Same permission gate as single-post.php — without this, the meta
						// description, OG tags, article:* metadata, and oEmbed discovery URL
						// for a private topic leak into every non-author response's <head>
						// (Basecamp 9803998504).
						//
						// can_render_post_text() (not can_read_post()) because the <head> is
						// also where a BLOCKED author's words were still arriving: the body
						// read "Content hidden — you blocked this user" while <title>,
						// og:title and — worst — meta description (the whole post body) sat
						// in the same response. Nulling $post falls through to the generic
						// community title, which is what a viewer who blocked the author
						// should get. A guest/crawler blocks nobody, so their <head> is
						// byte-identical to before (1.8.0).
						if ( $post && ! \Jetonomy\Permissions\Permission_Engine::can_render_post_text( get_current_user_id(), $post ) ) {
							$post = null;
						}
						if ( $post ) {
							$space        = \Jetonomy\Models\Space::find( (int) $post->space_id );
							$title        = $post->title;
							$desc         = ! empty( $post->content_plain )
								? wp_strip_all_tags( (string) $post->content_plain )
								: wp_strip_all_tags( (string) $post->content );
							$desc         = trim( preg_replace( '/\s+/', ' ', $desc ) );
							$url          = $base . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/';
							$og_type      = 'article';
							$twitter_card = 'summary_large_image';
							$image_alt    = $post->title;

							if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->content, $m ) ) {
								$image = esc_url_raw( $m[1] );
							}

							$author_name = '';
							if ( ! empty( $post->author_id ) ) {
								$profile = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
								if ( $profile && ! empty( $profile->display_name ) ) {
									$author_name = $profile->display_name;
								} else {
									$author      = get_userdata( (int) $post->author_id );
									$author_name = $author ? $author->display_name : '';
								}
							}
							$article_meta['article:author']         = $author_name;
							$article_meta['article:published_time'] = ! empty( $post->created_at )
								? gmdate( 'c', strtotime( (string) $post->created_at ) )
								: '';
							$article_meta['article:modified_time']  = ! empty( $post->updated_at )
								? gmdate( 'c', strtotime( (string) $post->updated_at ) )
								: '';
							$article_meta['article:section']        = $space->title ?? '';

							$oembed_url = add_query_arg(
								array(
									'url'    => rawurlencode( $url ),
									'format' => 'json',
								),
								rest_url( 'jetonomy/v1/oembed' )
							);

							if ( $space && ! empty( $space->visibility ) && 'public' !== $space->visibility ) {
								$noindex = true;
							}
						}
						break;
					case 'profile':
						$user = get_user_by( 'login', (string) $data['slug'] );
						if ( $user ) {
							$profile   = \Jetonomy\Models\UserProfile::find_by_user( (int) $user->ID );
							$bio       = $profile && ! empty( $profile->bio ) ? wp_strip_all_tags( (string) $profile->bio ) : '';
							$title     = $user->display_name;
							$desc      = $bio !== '' ? $bio : sprintf( __( 'Community profile for @%1$s on %2$s.', 'jetonomy' ), $user->user_login, $site_name );
							$url       = $base . '/u/' . rawurlencode( $user->user_login ) . '/';
							$image     = (string) get_avatar_url( $user->ID, array( 'size' => 256 ) );
							$image_alt = $user->display_name;
						}
						break;
					case 'tag':
						$title     = '#' . (string) $data['slug'];
						$desc      = sprintf( __( 'Discussions tagged %1$s on %2$s.', 'jetonomy' ), $title, $site_name );
						$url       = $base . '/tag/' . rawurlencode( (string) $data['slug'] ) . '/';
						$image_alt = $title;
						break;
					case 'leaderboard':
						$title     = __( 'Top members', 'jetonomy' );
						$desc      = sprintf( __( 'Top contributors and most-helpful members on %s.', 'jetonomy' ), $site_name );
						$url       = $base . '/leaderboard/';
						$image_alt = $site_name;
						break;
					case 'search':
						$title     = __( 'Search the community', 'jetonomy' );
						$desc      = sprintf( __( 'Search discussions, replies, members, and tags on %s.', 'jetonomy' ), $site_name );
						$url       = $base . '/search/';
						$image_alt = $site_name;
						$noindex   = true; // Search results — duplicate / thin.
						break;
					case 'moderation':
						$title     = __( 'Moderation Queue', 'jetonomy' );
						$desc      = sprintf( __( 'Moderation queue for %s.', 'jetonomy' ), $site_name );
						$url       = $base . '/mod/';
						$image_alt = $site_name;
						$noindex   = true; // Admin tooling.
						break;
					case 'new-post':
						$slug      = (string) $data['slug'];
						$title     = '' !== $slug
							? sprintf( __( 'Start a discussion in %s', 'jetonomy' ), ucfirst( str_replace( '-', ' ', $slug ) ) )
							: __( 'Start a discussion', 'jetonomy' );
						$desc      = sprintf( __( 'Compose a new discussion on %s.', 'jetonomy' ), $site_name );
						$url       = $base . ( '' !== $slug ? '/s/' . rawurlencode( $slug ) . '/new/' : '/new/' );
						$image_alt = $site_name;
						$noindex   = true; // Composer page.
						break;
					case 'notifications':
						$title     = __( 'Notifications', 'jetonomy' );
						$desc      = __( 'Your community notifications.', 'jetonomy' );
						$url       = $base . '/notifications/';
						$image_alt = $site_name;
						$noindex   = true; // Personal logged-in view.
						break;
					case 'edit-profile':
						$title     = __( 'Edit profile', 'jetonomy' );
						$desc      = __( 'Edit your community profile.', 'jetonomy' );
						$url       = $base . '/u/me/edit/';
						$image_alt = $site_name;
						$noindex   = true; // Logged-in form.
						break;
					case 'invite':
						$title     = __( 'You are invited', 'jetonomy' );
						$desc      = sprintf( __( 'Accept your community invite to %s.', 'jetonomy' ), $site_name );
						$url       = $base . '/invite/' . rawurlencode( (string) $data['slug'] ) . '/';
						$image_alt = $site_name;
						$noindex   = true; // One-shot landing.
						break;
					case 'my-spaces':
						$title     = sprintf( __( 'My %s', 'jetonomy' ), \Jetonomy\space_label( true ) );
						$desc      = sprintf( __( '%1$s you run and %2$s you are part of on %3$s.', 'jetonomy' ), \Jetonomy\space_label( true ), \Jetonomy\space_label( true, true ), $site_name );
						$url       = $base . '/my-spaces/';
						$image_alt = $site_name;
						$noindex   = true; // Logged-in personal view.
						break;
					case 'subscriptions':
						$title     = __( 'My Subscriptions', 'jetonomy' );
						/* translators: 1: plural space label (e.g. Spaces), 2: site name */
						$desc      = sprintf( __( 'Topics and %1$s you follow on %2$s.', 'jetonomy' ), \Jetonomy\space_label( true, true ), $site_name );
						$url       = $base . '/subscriptions/';
						$image_alt = $site_name;
						$noindex   = true; // Logged-in personal view.
						break;
					case 'new-space':
						$title     = sprintf( __( 'Create a %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
						$desc      = sprintf( __( 'Start a new community %1$s on %2$s.', 'jetonomy' ), \Jetonomy\space_label( false, true ), $site_name );
						$url       = $base . '/new-space/';
						$image_alt = $site_name;
						$noindex   = true; // Composer page.
						break;
					case 'edit-space':
						$title     = sprintf( __( 'Edit %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
						$desc      = sprintf( __( 'Edit your community %s settings.', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
						$url       = $base . '/s/' . rawurlencode( (string) $data['slug'] ) . '/edit/';
						$image_alt = $site_name;
						$noindex   = true; // Logged-in editor view.
						break;
					case 'drafts':
						$title     = __( 'My drafts', 'jetonomy' );
						$desc      = __( 'Your saved drafts on the community.', 'jetonomy' );
						$url       = $base . '/drafts/';
						$image_alt = $site_name;
						$noindex   = true; // Personal logged-in view.
						break;
					case 'bookmarks':
						$title     = __( 'My bookmarks', 'jetonomy' );
						$desc      = __( 'Posts you have bookmarked on the community.', 'jetonomy' );
						$url       = $base . '/bookmarks/';
						$image_alt = $site_name;
						$noindex   = true; // Personal logged-in view.
						break;
				}

				$jt_seo_settings = get_option( 'jetonomy_settings', array() );

				/**
				 * og:image fallback chain — when the route-specific image is
				 * empty, fall back through admin-configured default → site
				 * logo → site icon so social shares always carry a card
				 * image instead of letting the platform render a blank tile.
				 */
				if ( '' === $image ) {
					$default_og = isset( $jt_seo_settings['seo_default_og_image'] ) ? (string) $jt_seo_settings['seo_default_og_image'] : '';
					if ( '' !== $default_og ) {
						$image = $default_og;
					}
				}
				if ( '' === $image ) {
					$logo_id = (int) get_theme_mod( 'custom_logo' );
					if ( $logo_id > 0 ) {
						$image = (string) wp_get_attachment_image_url( $logo_id, 'full' );
					}
					if ( '' === $image ) {
						$icon_url = (string) get_site_icon_url( 512 );
						if ( '' !== $icon_url ) {
							$image = $icon_url;
						}
					}
				}
				if ( '' === $image_alt && '' !== $title ) {
					$image_alt = $title;
				}

				/**
				 * Filter the SEO meta payload — lets Pro extensions or themes
				 * mutate any value before emission, e.g. swap in a per-space
				 * og:image, force a noindex on a paginated tail, or rewrite
				 * the canonical for sort/filter param normalisation.
				 *
				 * @param array $payload {
				 *     @type string $title         OG/Twitter title.
				 *     @type string $desc          Meta description (≤160 char clip applied at emit time).
				 *     @type string $url           Canonical / og:url.
				 *     @type string $image         og:image URL.
				 *     @type string $image_alt     og:image:alt text.
				 *     @type string $og_type       og:type (default 'website').
				 *     @type string $twitter_card  twitter:card (default 'summary').
				 *     @type bool   $noindex       Whether to emit robots noindex.
				 *     @type array  $article_meta  article:* meta keyed by property.
				 * }
				 * @param array $data Route data (route, slug, etc.).
				 */
				$payload      = apply_filters(
					'jetonomy_seo_meta',
					compact( 'title', 'desc', 'url', 'image', 'image_alt', 'og_type', 'twitter_card', 'noindex', 'article_meta' ),
					$data
				);
				$title        = (string) ( $payload['title'] ?? '' );
				$desc         = (string) ( $payload['desc'] ?? '' );
				$url          = (string) ( $payload['url'] ?? '' );
				$image        = (string) ( $payload['image'] ?? '' );
				$image_alt    = (string) ( $payload['image_alt'] ?? '' );
				$og_type      = (string) ( $payload['og_type'] ?? 'website' );
				$twitter_card = (string) ( $payload['twitter_card'] ?? 'summary' );
				$noindex      = (bool) ( $payload['noindex'] ?? false );
				$article_meta = (array) ( $payload['article_meta'] ?? array() );

				// Always emit robots noindex when the route asks for it —
				// independent of seo-pro, because seo-pro doesn't currently
				// emit a follow-aware noindex on these surfaces.
				if ( $noindex ) {
					echo '<meta name="robots" content="noindex, follow">' . "\n";
				}

				if ( ! $skip_baseline ) {
					if ( '' !== $desc ) {
						echo '<meta name="description" content="' . esc_attr( mb_substr( $desc, 0, 160 ) ) . '">' . "\n";
					}
					if ( '' !== $title ) {
						echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
						echo '<meta property="og:description" content="' . esc_attr( mb_substr( $desc, 0, 200 ) ) . '">' . "\n";
						echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
						echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '">' . "\n";
						echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
						if ( '' !== $image ) {
							echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
							echo '<meta property="og:image:alt" content="' . esc_attr( $image_alt ) . '">' . "\n";
						}
						foreach ( $article_meta as $prop => $value ) {
							if ( '' !== (string) $value ) {
								echo '<meta property="' . esc_attr( (string) $prop ) . '" content="' . esc_attr( (string) $value ) . '">' . "\n";
							}
						}
						echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . '">' . "\n";
						$jt_twitter_handle = isset( $jt_seo_settings['seo_twitter_handle'] ) ? trim( (string) $jt_seo_settings['seo_twitter_handle'] ) : '';
						if ( '' !== $jt_twitter_handle ) {
							if ( '@' !== substr( $jt_twitter_handle, 0, 1 ) ) {
								$jt_twitter_handle = '@' . $jt_twitter_handle;
							}
							echo '<meta name="twitter:site" content="' . esc_attr( $jt_twitter_handle ) . '">' . "\n";
						}
						echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
						echo '<meta name="twitter:description" content="' . esc_attr( mb_substr( $desc, 0, 200 ) ) . '">' . "\n";
						if ( '' !== $image ) {
							echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
							echo '<meta name="twitter:image:alt" content="' . esc_attr( $image_alt ) . '">' . "\n";
						}
					}
					if ( '' !== $url ) {
						echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
					}
				}

				if ( $oembed_url ) {
					echo '<link rel="alternate" type="application/json+oembed" href="' . esc_url( $oembed_url ) . '" title="' . esc_attr( $title ) . '">' . "\n";
				}
			},
			1
		);
	}

	/**
	 * Track a post view with 24-hour cookie deduplication.
	 *
	 * Must run before get_header() so that setcookie() fires before any output.
	 */
	private static function maybe_track_post_view( array $data ): void {
		if ( 'post' !== $data['route'] || empty( $data['slug'] ) ) {
			return;
		}

		$post = \Jetonomy\Models\Post::find_by_slug( $data['slug'] );
		if ( ! $post ) {
			return;
		}

		$cookie = 'jt_viewed_' . (int) $post->id;
        // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		if ( empty( $_COOKIE[ $cookie ] ) ) {
			\Jetonomy\Models\Post::increment_view_count( (int) $post->id );
			setcookie( $cookie, '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		// 1.4.0 C.5 — wire ReadStatus::mark_read so opening a thread clears
		// its "new replies" pill on the space view. Records the latest reply
		// id (or 0 if no replies yet) for the current user.
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$latest_reply_id = (int) ( $post->last_reply_id ?? 0 );
			if ( 0 === $latest_reply_id && (int) $post->reply_count > 0 ) {
				$latest_reply_id = (int) \Jetonomy\Models\Reply::latest_id_for_post( (int) $post->id );
			}
			\Jetonomy\Models\ReadStatus::mark_read( $user_id, (int) $post->id, $latest_reply_id );
		}
	}

	/**
	 * Pre-flight 404 check — runs BEFORE get_header() sends HTTP headers.
	 *
	 * This ensures that non-existent spaces, posts, users, and tags return
	 * a proper 404 HTTP status code rather than 200.
	 */
	private static function maybe_set_404( array $data ): void {
		$slug = $data['slug'] ?? '';

		switch ( $data['route'] ) {
			case 'space':
			case 'space-members':
			case 'space-roadmap':
			case 'space-moderation':
				if ( $slug ) {
					$space = \Jetonomy\Models\Space::find_by_slug( $slug );
					// A hidden space answers 404 to non-members — its existence
					// is concealed, same as the view templates render (Basecamp
					// 10105630168). Missing and concealed are indistinguishable.
					if ( ! $space || \Jetonomy\Models\Space::concealed_from_viewer( $space, get_current_user_id() ) ) {
						status_header( 404 );
					}
				}
				break;

			case 'post':
				if ( $slug ) {
					$post = \Jetonomy\Models\Post::find_by_slug( $slug );
					if ( ! $post ) {
						status_header( 404 );
					} elseif ( 'publish' !== $post->status ) {
						// Allow moderators and the post author to view non-published posts.
						$user_id   = get_current_user_id();
						$is_author = $user_id && (int) $post->author_id === $user_id;
						$can_mod   = current_user_can( 'jetonomy_moderate' );
						if ( ! $is_author && ! $can_mod ) {
							status_header( 404 );
						}
					}
				}
				break;

			case 'profile':
				if ( $slug && ! get_user_by( 'login', $slug ) ) {
					status_header( 404 );
				}
				break;

			case 'tag':
				if ( $slug && ! \Jetonomy\Models\Tag::find_by_slug( $slug ) ) {
					status_header( 404 );
				}
				break;

			case 'category':
				if ( $slug && ! \Jetonomy\Models\Category::find_by_slug( $slug ) ) {
					status_header( 404 );
				}
				break;
		}
	}

	/**
	 * Helper to load a partial template.
	 */
	public static function partial( string $name, array $args = array() ): void {
		$theme_path  = get_stylesheet_directory() . '/jetonomy/partials/' . $name . '.php';
		$plugin_path = JETONOMY_DIR . 'templates/partials/' . $name . '.php';

		$path = file_exists( $theme_path ) ? $theme_path : $plugin_path;

		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template rendering: expose $args as locals to the included template.
			extract( $args, EXTR_SKIP );
			include $path;
		}
	}
}
