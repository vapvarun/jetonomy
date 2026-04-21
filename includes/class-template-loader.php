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
		$settings            = get_option( 'jetonomy_settings', array() );
		$is_public_community = ! isset( $settings['guest_read'] ) || ! empty( $settings['guest_read'] );
		if ( ! $is_public_community && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) ) ) ) );
			exit;
		}

		// ── Auth redirect for protected routes (BEFORE any output) ──
		$auth_required_routes = array( 'notifications', 'messages', 'conversation', 'edit-profile', 'new-post' );
		if ( in_array( $data['route'], $auth_required_routes, true ) && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) ) ) ) );
			exit;
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
			'home'          => 'views/home.php',
			'category'      => 'views/category.php',
			'space'         => 'views/space.php',
			'space-members' => 'views/space-members.php',
			'space-roadmap' => 'views/space-roadmap.php',
			'post'          => 'views/single-post.php',
			'profile'       => 'views/user-profile.php',
			'notifications' => 'views/notifications.php',
			'search'        => 'views/search.php',
			'leaderboard'   => 'views/leaderboard.php',
			'moderation'    => 'views/moderation.php',
			'tag'           => 'views/tag.php',
			'new-post'      => 'views/new-post.php',
			'edit-profile'  => 'views/edit-profile.php',
			'invite'        => 'views/invite.php',
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
			'unreadCount'       => 0,
			'isSubmitting'      => false,
			'submitLabel'       => __( 'Post Topic', 'jetonomy' ),
			'submitError'       => '',
			'msgComposeOpen'    => false,
			'i18n'              => array(
				'voteRecorded'       => __( 'Vote recorded', 'jetonomy' ),
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
				'titleRequired'      => __( 'Please enter a title for your topic.', 'jetonomy' ),
				'bodyRequired'       => __( 'Please add some details before posting.', 'jetonomy' ),
			),
			)
		);

		// Enqueue Interactivity API module
		wp_enqueue_script_module(
			'jetonomy-view',
			JETONOMY_URL . 'assets/js/view.js',
			array( '@wordpress/interactivity' ),
			JETONOMY_VERSION
		);

		// Shared global for non-Interactivity JS on community pages (link preview
		// cards, similar-topics typeahead). Keeps the REST nonce + base URL in
		// one place so the same contract works for the future native app.
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
			)
		);

		// Enqueue composer enhancement script
		wp_enqueue_script(
			'jetonomy-composer',
			JETONOMY_URL . 'assets/js/composer.js',
			array(),
			JETONOMY_VERSION,
			true
		);

		// Localize upload & API data for composer.js (image upload + instant search).
		wp_localize_script(
			'jetonomy-composer',
			'jetonomyUpload',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'jetonomy_upload' ),
				'apiBase'       => rest_url( 'jetonomy/v1' ),
				'communityBase' => \Jetonomy\base_url(),
			)
		);

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
				switch ( $data['route'] ) {
					case 'home':
						$parts['title'] = __( 'Community', 'jetonomy' );
						break;
					case 'space':
						$parts['title'] = ucfirst( str_replace( '-', ' ', $data['slug'] ) ) . ' — ' . __( 'Community', 'jetonomy' );
						break;
					case 'post':
						$parts['title'] = ucfirst( str_replace( '-', ' ', $data['slug'] ) );
						break;
				}
				return $parts;
			}
		);

		// Meta description + OG tags
		add_action(
			'wp_head',
			function () use ( $data ) {
				// When the Pro seo-pro extension is active, it emits richer
				// OG/Twitter/canonical tags at priority 0 and owns that output
				// completely. We must not duplicate those tags — but we still
				// emit the oEmbed discovery <link> since that lives only here.
				$seo_pro_active = in_array(
					'seo-pro',
					(array) get_option( 'jetonomy_pro_extensions', array() ),
					true
				);

				$desc         = '';
				$title        = '';
				$url          = '';
				$image        = '';
				$og_type      = 'website';
				$twitter_card = 'summary';
				$article_meta = array(); // author / published_time / section — only for post route.
				$oembed_url   = '';

				switch ( $data['route'] ) {
					case 'home':
						$title = get_bloginfo( 'name' ) . ' Community';
						$desc  = __( 'Join our community discussions, Q&A, and more.', 'jetonomy' );
						$url   = \Jetonomy\base_url() . '/';
						break;
					case 'space':
						$space = \Jetonomy\Models\Space::find_by_slug( $data['slug'] );
						if ( $space ) {
							$title = $space->title;
							$desc  = wp_strip_all_tags( $space->description ?? '' );
							$url   = \Jetonomy\base_url() . '/s/' . $space->slug . '/';
							$image = $space->cover_image ?? '';
						}
						break;
					case 'post':
						$post = \Jetonomy\Models\Post::find_by_slug( $data['slug'] );
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
							$url          = \Jetonomy\base_url() . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/';
							$og_type      = 'article';
							$twitter_card = 'summary_large_image';

							// First inline image → OG/Twitter image.
							if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->content, $m ) ) {
								$image = esc_url_raw( $m[1] );
							}

							// article:* meta — author, published time, section.
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

							// oEmbed JSON discovery link — consumers hit our custom endpoint.
							$oembed_url = add_query_arg(
								array(
									'url'    => rawurlencode( $url ),
									'format' => 'json',
								),
								rest_url( 'jetonomy/v1/oembed' )
							);
						}
						break;
					case 'profile':
						$user = get_user_by( 'login', $data['slug'] );
						if ( $user ) {
							$title = $user->display_name;
							$desc  = __( 'Community member profile', 'jetonomy' );
							$url   = \Jetonomy\base_url() . '/u/' . $data['slug'] . '/';
						}
						break;
				}

				if ( ! $seo_pro_active ) {
					if ( $desc ) {
						echo '<meta name="description" content="' . esc_attr( mb_substr( $desc, 0, 160 ) ) . '">' . "\n";
					}
					if ( $title ) {
						echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
						echo '<meta property="og:description" content="' . esc_attr( mb_substr( $desc, 0, 200 ) ) . '">' . "\n";
						echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
						echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '">' . "\n";
						echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
						if ( $image ) {
							echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
						}
						foreach ( $article_meta as $prop => $value ) {
							if ( '' !== $value ) {
								echo '<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $value ) . '">' . "\n";
							}
						}
						echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . '">' . "\n";
						echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
						echo '<meta name="twitter:description" content="' . esc_attr( mb_substr( $desc, 0, 200 ) ) . '">' . "\n";
						if ( $image ) {
							echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
						}
					}

					if ( $url ) {
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
