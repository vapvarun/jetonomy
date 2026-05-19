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
				wp_safe_redirect( wp_login_url( home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) ) ) ) );
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
			wp_safe_redirect( wp_login_url( home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) ) ) ) );
			exit;
		}

		// ── Auth redirect for protected routes (BEFORE any output) ──
		$auth_required_routes = array( 'notifications', 'messages', 'conversation', 'edit-profile', 'new-post', 'my-spaces', 'new-space', 'edit-space', 'moderation', 'space-moderation', 'drafts', 'bookmarks' );
		if ( in_array( $data['route'], $auth_required_routes, true ) && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) ) ) ) );
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

		// Enqueue styles
		wp_enqueue_style(
			'jetonomy',
			JETONOMY_URL . 'assets/css/jetonomy.css',
			array(),
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

		// Accent color override.
		if ( ! empty( $settings['accent_color'] ) && '#0073aa' !== $settings['accent_color'] ) {
			$accent = sanitize_hex_color( $settings['accent_color'] );
			if ( $accent ) {
				$dynamic_css .= ':root,.jt-app{--jt-accent:' . $accent . ';}';
			}
		}

		// Inherit fonts: when enabled, don't override theme fonts.
		if ( ! empty( $settings['inherit_fonts'] ) ) {
			$dynamic_css .= ':root,.jt-app{--jt-font:inherit;--jt-font-heading:inherit;}';
		}

		// Inherit colors: when enabled, use WP theme preset colors only.
		if ( ! empty( $settings['inherit_colors'] ) ) {
			$dynamic_css .= ':root,.jt-app{--jt-accent:var(--wp--preset--color--primary,#3B82F6);--jt-text:var(--wp--preset--color--contrast,#1a1a1a);--jt-bg:var(--wp--preset--color--base,#ffffff);}';
		}

		// Layout density.
		if ( ! empty( $settings['layout_density'] ) && 'compact' === $settings['layout_density'] ) {
			$dynamic_css .= '.jt-app{font-size:0.875rem;line-height:1.5;}.jt-row{padding:8px 12px;}.jt-reply-body{padding:12px 14px;}.jt-post-body{padding:16px;}';
		}

		// Custom CSS from settings.
		if ( ! empty( $settings['custom_css'] ) ) {
			$dynamic_css .= wp_strip_all_tags( $settings['custom_css'] );
		}

		wp_add_inline_style( 'jetonomy', $dynamic_css );

		// Set up Interactivity API state
		wp_interactivity_state(
			'jetonomy',
			array(
				'apiBase'       => rest_url( 'jetonomy/v1' ),
				'_nonce'        => wp_create_nonce( 'wp_rest' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'communityBase' => home_url( '/' . ( $settings['base_slug'] ?? 'community' ) ),
				'currentPostId' => 0,
				'postScores'    => new \stdClass(),
				'replyScores'   => new \stdClass(),
				'currentSort'   => sanitize_text_field( $_GET['sort'] ?? 'latest' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'isLoggedIn'        => is_user_logged_in(),
			'loginUrl'          => wp_login_url( home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) ),
			'unreadCount'       => 0,
			'isSubmitting'      => false,
			'submitLabel'       => __( 'Post Topic', 'jetonomy' ),
			'submitError'       => '',
			'msgComposeOpen'    => false,
			'i18n'              => array(
				'voteRecorded'       => __( 'Vote recorded', 'jetonomy' ),
				'statusUpdated'      => __( 'Roadmap status updated', 'jetonomy' ),
				'accepted'           => __( 'Accepted', 'jetonomy' ),
				'reply'              => __( 'Reply', 'jetonomy' ),
				'cancel'             => __( 'Cancel', 'jetonomy' ),
				'save'               => __( 'Save', 'jetonomy' ),
				'saving'             => __( 'Saving...', 'jetonomy' ),
				'failedSave'         => __( 'Failed to save.', 'jetonomy' ),
				'networkError'       => __( 'Network error. Please try again.', 'jetonomy' ),
				'follow'             => __( 'Follow', 'jetonomy' ),
				'following'          => __( 'Following', 'jetonomy' ),
				'followingSpace'     => __( 'Following space', 'jetonomy' ),
				'unfollowedSpace'    => __( 'Unfollowed space', 'jetonomy' ),
				'copyLink'           => __( 'Copy link', 'jetonomy' ),
				'bookmark'           => __( 'Bookmark', 'jetonomy' ),
				'removeBookmark'     => __( 'Remove bookmark', 'jetonomy' ),
				'bookmarked'         => __( 'Bookmarked', 'jetonomy' ),
				'bookmarkRemoved'    => __( 'Bookmark removed', 'jetonomy' ),
				'reportPrompt'       => __( 'Why are you reporting this post?', 'jetonomy' ),
				'reportedThankYou'   => __( 'Reported. Thank you.', 'jetonomy' ),
				'failedReport'       => __( 'Failed to submit report.', 'jetonomy' ),
				'postPinned'         => __( 'Post pinned', 'jetonomy' ),
				'postUnpinned'       => __( 'Post unpinned', 'jetonomy' ),
				'failedPin'          => __( 'Failed to toggle pin.', 'jetonomy' ),
				'confirmDeletePost'  => __( 'Are you sure you want to delete this topic?', 'jetonomy' ),
				'confirmDeleteReply' => __( 'Are you sure you want to delete this reply?', 'jetonomy' ),
				'failedDelete'       => __( 'Failed to delete.', 'jetonomy' ),
				'moveTopicTitle'     => __( 'Move topic to another space', 'jetonomy' ),
				'topicMoved'         => __( 'Topic moved successfully.', 'jetonomy' ),
				'moveFailed'         => __( 'Failed to move topic.', 'jetonomy' ),
				'mergeTopicTitle'    => __( 'Merge into another topic', 'jetonomy' ),
				'confirmMerge'       => __( 'Merge this topic into the selected one? All replies will be moved and this topic will be deleted.', 'jetonomy' ),
				'topicMerged'        => __( 'Topics merged successfully.', 'jetonomy' ),
				'mergeFailed'        => __( 'Failed to merge topics.', 'jetonomy' ),
				'splitReplyTitle'    => __( 'Enter a title for the new topic:', 'jetonomy' ),
				'replySplit'         => __( 'Reply split into new topic.', 'jetonomy' ),
				'splitFailed'        => __( 'Failed to split reply.', 'jetonomy' ),
				'replyingTo'         => __( 'Replying to', 'jetonomy' ),
				'cancelReply'        => __( 'Cancel reply', 'jetonomy' ),
				'posting'            => __( 'Posting...', 'jetonomy' ),
				'postTopic'          => __( 'Post Topic', 'jetonomy' ),
				'newReply'           => __( '%d new reply. Click to refresh.', 'jetonomy' ),
				'newReplies'         => __( '%d new replies. Click to refresh.', 'jetonomy' ),
				'linkCopied'         => __( 'Link copied', 'jetonomy' ),
				'linkCopyFailed'     => __( 'Could not copy the link. Copy it from the address bar.', 'jetonomy' ),
				'titleRequired'      => __( 'Please enter a title for your topic.', 'jetonomy' ),
				'bodyRequired'       => __( 'Please add some details before posting.', 'jetonomy' ),
				'loginRequired'      => __( 'Please sign in to use this.', 'jetonomy' ),
				// Pro Private Messaging composer (consumed by jetonomy-pro/assets/js/pro-view.js).
				'messageSending'     => __( 'Sending...', 'jetonomy' ),
				'messageSend'        => __( 'Send', 'jetonomy' ),
				'messageSendFailed'  => __( 'Failed to send. Please try again.', 'jetonomy' ),
				'noMessageMatches'   => __( 'No matches. You can only message people who share at least one space with you.', 'jetonomy' ),
				// Pro Private Messaging — conversation actions (kebab menu, WS3-C).
				'muteFailed'         => __( 'Could not update mute setting.', 'jetonomy' ),
				'archiveFailed'      => __( 'Could not archive the conversation.', 'jetonomy' ),
				'leaveFailed'        => __( 'Could not leave the conversation.', 'jetonomy' ),
				'blockFailed'        => __( 'Could not update block setting.', 'jetonomy' ),
				'confirmLeave'       => __( 'Leave this conversation? You will not receive new messages.', 'jetonomy' ),
				'confirmBlock'       => __( 'Block this user? They will no longer be able to message you here.', 'jetonomy' ),
				// WS4-C: moderation flag toasts + profile-save failure (consumed by view.js via state.i18n).
				'contentRemoved'     => __( 'Content removed', 'jetonomy' ),
				'flagDismissed'      => __( 'Flag dismissed', 'jetonomy' ),
				'failed'             => __( 'Failed', 'jetonomy' ),
				'failedSaveProfile'  => __( 'Failed to save profile.', 'jetonomy' ),
				'schedule'           => __( 'Schedule', 'jetonomy' ),
				'editPost'           => __( 'Edit post', 'jetonomy' ),
				'editReply'          => __( 'Edit reply', 'jetonomy' ),
				// WS4-C: Pro Polls strings consumed via state.i18n in pro-view.js.
				'failedSubmitVote'   => __( 'Failed to submit vote. Please try again.', 'jetonomy' ),
				'voteSingular'       => __( '1 vote', 'jetonomy' ),
				/* translators: %d: vote count. */
				'voteFormat'         => __( '%d votes', 'jetonomy' ),
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

		wp_enqueue_script_module(
			'jetonomy-view',
			JETONOMY_URL . 'assets/js/view.js',
			array( '@wordpress/interactivity' ),
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
					'createSpaceFailed'      => esc_html__( 'Could not create the space. Please try again.', 'jetonomy' ),
					'saveFailed'             => esc_html__( 'Could not save changes.', 'jetonomy' ),
					'prefixLabel'            => esc_html__( 'Label', 'jetonomy' ),
					'removePrefix'           => esc_html__( 'Remove prefix', 'jetonomy' ),
					// Composer + Join-Space gate strings (consumed by composer.js).
					'quoteSelected'          => esc_html__( 'Quote', 'jetonomy' ),
					'joining'                => esc_html__( 'Joining...', 'jetonomy' ),
					'joinSpace'              => esc_html__( 'Join Space', 'jetonomy' ),
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
					'loadingSpaces'          => esc_html__( 'Loading spaces…', 'jetonomy' ),
					'selectSpacePlaceholder' => esc_html__( 'Select a space…', 'jetonomy' ),
					'noOtherSpaces'          => esc_html__( 'No other spaces available', 'jetonomy' ),
					'failedLoadSpaces'       => esc_html__( 'Failed to load spaces', 'jetonomy' ),
					'searchTopicPlaceholder' => esc_html__( 'Search for a topic...', 'jetonomy' ),
					'noTopicsFound'          => esc_html__( 'No topics found', 'jetonomy' ),
					'searchFailed'           => esc_html__( 'Search failed', 'jetonomy' ),
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
					'banConfirmFormat'       => __( 'Ban %s from this space? They will lose access to its posts and replies until you lift the ban.', 'jetonomy' ),
					'banMemberTitle'         => esc_html__( 'Ban member', 'jetonomy' ),
					'banLabel'               => esc_html__( 'Ban', 'jetonomy' ),
					'banFailed'              => esc_html__( 'Ban failed. Please try again.', 'jetonomy' ),
				),
			)
		);

		// Unified REST fetch client (1.4.3). Exposes window.jetonomyRest.restFetch
		// for every mutation/read; centralises nonce handling + auto-retry on
		// rest_cookie_invalid_nonce. Depends on `jetonomy-data` for restBase
		// + restNonce. See includes/api/class-rest-auth.php for the matching
		// server-side permission helper.
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

		// Pagination "Load More" auto-scroll handler. Self-discovers all
		// .jt-pagination containers on the page; safe to always enqueue.
		wp_enqueue_script(
			'jetonomy-pagination',
			JETONOMY_URL . 'assets/js/pagination-frontend.js',
			array( 'jetonomy-data' ),
			JETONOMY_VERSION,
			true
		);

		// Per-route page scripts.
		if ( 'new-space' === $data['route'] ) {
			wp_enqueue_script(
				'jetonomy-new-space',
				JETONOMY_URL . 'assets/js/new-space.js',
				array( 'jetonomy-data' ),
				JETONOMY_VERSION,
				true
			);
		} elseif ( 'space-edit' === $data['route'] ) {
			wp_enqueue_script(
				'jetonomy-space-edit',
				JETONOMY_URL . 'assets/js/space-edit.js',
				array( 'jetonomy-data' ),
				JETONOMY_VERSION,
				true
			);
		} elseif ( 'notifications' === $data['route'] ) {
			wp_enqueue_script(
				'jetonomy-notifications-page',
				JETONOMY_URL . 'assets/js/notifications-page.js',
				array( 'jetonomy-data' ),
				JETONOMY_VERSION,
				true
			);
		}

		// Enqueue composer enhancement script (depends on the shared modal
		// toolkit for the link-insert prompt).
		wp_enqueue_script(
			'jetonomy-composer',
			JETONOMY_URL . 'assets/js/composer.js',
			array( 'jetonomy-modals' ),
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

		// Enqueue role-dropdown handler on the space-members route (only
		// rendered for space admins, but the JS binds via delegation and is
		// a no-op when no select is present — safe to always enqueue here).
		if ( 'space-members' === $data['route'] ) {
			$sm_file    = JETONOMY_DIR . 'assets/js/space-members.js';
			$sm_mtime   = file_exists( $sm_file ) ? (string) filemtime( $sm_file ) : '';
			$sm_version = '' !== $sm_mtime ? JETONOMY_VERSION . '+' . $sm_mtime : JETONOMY_VERSION;
			wp_enqueue_script(
				'jetonomy-space-members',
				JETONOMY_URL . 'assets/js/space-members.js',
				array( 'jetonomy-data', 'jetonomy-modals' ),
				$sm_version,
				true
			);
		}

		// Enqueue moderation queue resolver on moderation routes.
		if ( in_array( $data['route'], array( 'moderation', 'space-moderation' ), true ) ) {
			$mod_file    = JETONOMY_DIR . 'assets/js/moderation.js';
			$mod_mtime   = file_exists( $mod_file ) ? (string) filemtime( $mod_file ) : '';
			$mod_version = '' !== $mod_mtime ? JETONOMY_VERSION . '+' . $mod_mtime : JETONOMY_VERSION;
			wp_enqueue_script(
				'jetonomy-moderation',
				JETONOMY_URL . 'assets/js/moderation.js',
				array( 'jetonomy-data' ),
				$mod_version,
				true
			);
		}

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

		echo '<div id="jetonomy-app" class="jt-app" data-wp-interactive="jetonomy">';

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

		// Load the main template
		include $template_path;

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

	private static function set_seo_meta( array $data ): void {
		add_filter(
			'document_title_parts',
			function ( $parts ) use ( $data ) {
				// WP appends ' – {site_name}' via the default separator + tagline,
				// so we only set the route-specific title fragment here. Don't
				// concatenate the site name yourself — it doubles up.
				$slug_pretty = ucfirst( str_replace( '-', ' ', (string) $data['slug'] ) );
				switch ( $data['route'] ) {
					case 'home':
						$parts['title'] = __( 'Community', 'jetonomy' );
						break;
					case 'space':
						$parts['title'] = $slug_pretty . ' — ' . __( 'Community', 'jetonomy' );
						break;
					case 'space-members':
						$parts['title'] = $slug_pretty . ' — ' . __( 'Members', 'jetonomy' );
						break;
					case 'space-roadmap':
						$parts['title'] = $slug_pretty . ' — ' . __( 'Roadmap', 'jetonomy' );
						break;
					case 'space-moderation':
						$parts['title'] = $slug_pretty . ' — ' . __( 'Moderation', 'jetonomy' );
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
						$parts['title'] = $slug_pretty . ' — ' . __( 'Community', 'jetonomy' );
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
						$parts['title'] = __( 'My Spaces', 'jetonomy' );
						break;
					case 'new-space':
						$parts['title'] = __( 'Create a space', 'jetonomy' );
						break;
					case 'edit-space':
						$parts['title'] = __( 'Edit space', 'jetonomy' );
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

				// seo-pro currently emits only for the `post` route — its
				// get_current_context() bails when `jetonomy_space_slug` is
				// empty, which is true on the bare `/s/:slug/` space route
				// (the router only sets `jetonomy_space_slug` on the post
				// rewrite). When that gap is closed in Pro, add `space`
				// here so free skips the duplicate emit.
				$seo_pro_handles = array( 'post' );
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
						$title     = ucfirst( str_replace( '-', ' ', (string) $data['slug'] ) );
						$desc      = sprintf( __( 'Spaces in the %1$s category on %2$s.', 'jetonomy' ), $title, $site_name );
						$url       = $base . '/category/' . rawurlencode( (string) $data['slug'] ) . '/';
						$image_alt = $title;
						break;
					case 'space':
					case 'space-members':
					case 'space-roadmap':
					case 'space-moderation':
						$space = \Jetonomy\Models\Space::find_by_slug( (string) $data['slug'] );
						if ( $space ) {
							$is_private = ! empty( $space->visibility ) && 'public' !== $space->visibility;
							$title      = $space->title;
							$desc       = wp_strip_all_tags( $space->description ?? '' );
							$image      = $space->cover_image ?? '';
							$image_alt  = $space->title;

							switch ( $data['route'] ) {
								case 'space-members':
									$title = $space->title . ' — ' . __( 'Members', 'jetonomy' );
									$desc  = sprintf( __( 'Members of the %1$s space on %2$s.', 'jetonomy' ), $space->title, $site_name );
									$url   = $base . '/s/' . $space->slug . '/members/';
									break;
								case 'space-roadmap':
									$title = $space->title . ' — ' . __( 'Roadmap', 'jetonomy' );
									$desc  = sprintf( __( 'Roadmap for the %1$s space on %2$s.', 'jetonomy' ), $space->title, $site_name );
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
						if ( $post && ! \Jetonomy\Permissions\Permission_Engine::can_read_post( get_current_user_id(), $post ) ) {
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
						$title     = __( 'My Spaces', 'jetonomy' );
						$desc      = sprintf( __( 'Spaces you run and spaces you are part of on %s.', 'jetonomy' ), $site_name );
						$url       = $base . '/my-spaces/';
						$image_alt = $site_name;
						$noindex   = true; // Logged-in personal view.
						break;
					case 'new-space':
						$title     = __( 'Create a space', 'jetonomy' );
						$desc      = sprintf( __( 'Start a new community space on %s.', 'jetonomy' ), $site_name );
						$url       = $base . '/new-space/';
						$image_alt = $site_name;
						$noindex   = true; // Composer page.
						break;
					case 'edit-space':
						$title     = __( 'Edit space', 'jetonomy' );
						$desc      = __( 'Edit your community space settings.', 'jetonomy' );
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
				if ( $slug && ! \Jetonomy\Models\Space::find_by_slug( $slug ) ) {
					status_header( 404 );
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
			extract( $args, EXTR_SKIP );
			include $path;
		}
	}
}
