<?php
/**
 * Shortcodes registration.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes for embedding Jetonomy content anywhere.
 *
 * [jetonomy_recent_posts]   — Recent forum posts.
 * [jetonomy_trending_posts] — Trending posts (time-decayed hot score).
 * [jetonomy_spaces]         — Space directory grid.
 * [jetonomy_leaderboard]    — Top members by reputation.
 * [jetonomy_user_profile]   — Single user profile card.
 * [jetonomy_space_members]  — Member list for a space.
 * [jetonomy_compose_topic]  — Inline topic composer (fixed space or member picker).
 * [jetonomy_widget]         — Render a registered Jetonomy widget anywhere.
 */
class Shortcodes {

	public static function register(): void {
		add_shortcode( 'jetonomy_recent_posts', array( __CLASS__, 'recent_posts' ) );
		add_shortcode( 'jetonomy_trending_posts', array( __CLASS__, 'trending_posts' ) );
		add_shortcode( 'jetonomy_spaces', array( __CLASS__, 'spaces' ) );
		add_shortcode( 'jetonomy_leaderboard', array( __CLASS__, 'leaderboard' ) );
		add_shortcode( 'jetonomy_user_profile', array( __CLASS__, 'user_profile' ) );
		add_shortcode( 'jetonomy_space_members', array( __CLASS__, 'space_members' ) );
		add_shortcode( 'jetonomy_compose_topic', array( __CLASS__, 'compose_topic' ) );
		add_shortcode( 'jetonomy_widget', array( __CLASS__, 'widget_embed' ) );
	}

	/**
	 * [jetonomy_widget id="jetonomy_recent_posts" title="..." count="5"]
	 *
	 * Render a registered Jetonomy widget inline. Lets a site owner embed
	 * "Active Spaces" / "User Stats" / etc. in a page or page-builder canvas
	 * without dropping into the Customizer. Anything beyond `id` becomes
	 * the widget instance settings, so per-widget options are accepted as
	 * shortcode attributes.
	 */
	public static function widget_embed( $atts ): string {
		// shortcode_atts would strip every key except those in defaults — but
		// each widget has its own instance keys (count, title, etc.), so we
		// take the raw user atts and only validate the dispatch key here.
		$user_atts = is_array( $atts ) ? $atts : array();
		$id_base   = isset( $user_atts['id'] ) ? (string) $user_atts['id'] : '';
		if ( '' === $id_base || 0 !== strpos( $id_base, 'jetonomy_' ) ) {
			return '';
		}

		// Class names match what `register_widget()` was called with — leading
		// backslash would mismatch the WP_Widget_Factory key.
		$class_map = array(
			'jetonomy_recent_posts'  => Widgets\Recent_Posts_Widget::class,
			'jetonomy_leaderboard'   => Widgets\Leaderboard_Widget::class,
			'jetonomy_active_spaces' => Widgets\Active_Spaces_Widget::class,
			'jetonomy_user_stats'    => Widgets\User_Stats_Widget::class,
		);
		if ( ! isset( $class_map[ $id_base ] ) || ! class_exists( $class_map[ $id_base ] ) ) {
			return '';
		}

		// Pass everything except `id` straight through as the widget instance.
		$instance = $user_atts;
		unset( $instance['id'] );

		self::enqueue_styles();

		ob_start();
		the_widget(
			$class_map[ $id_base ],
			$instance,
			array(
				'before_widget' => '<div class="jt-widget jt-widget--shortcode">',
				'after_widget'  => '</div>',
				'before_title'  => '<h4 class="jt-widget-title">',
				'after_title'   => '</h4>',
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Register + enqueue the shared blocks/shortcode stylesheet.
	 *
	 * Shortcodes can render in any context (page, page-builder canvas, widget),
	 * including pages that never run `wp_enqueue_scripts` for our handles. We
	 * register defensively in case we beat Blocks::register_block_assets(),
	 * then enqueue. WordPress dedupes by handle.
	 */
	private static function enqueue_styles(): void {
		if ( ! wp_style_is( 'jetonomy-blocks', 'registered' ) ) {
			wp_register_style( 'jetonomy-blocks', JETONOMY_URL . 'assets/css/blocks.css', array(), JETONOMY_VERSION );
		}
		wp_enqueue_style( 'jetonomy-blocks' );
	}

	/**
	 * [jetonomy_recent_posts count="5" space_id="" sort="latest"]
	 */
	public static function recent_posts( $atts ): string {
		$atts = shortcode_atts(
			array(
				'count'    => 5,
				'space_id' => 0,
				'sort'     => 'latest',
			),
			$atts,
			'jetonomy_recent_posts'
		);

		self::enqueue_styles();

		$limit = absint( $atts['count'] ) ?: 5;
		$base  = base_url();

		global $wpdb;
		$posts_tbl  = table( 'posts' );
		$spaces_tbl = table( 'spaces' );

		$where = "p.status = 'publish'";
		$args  = array();
		if ( ! empty( $atts['space_id'] ) ) {
			$where .= ' AND p.space_id = %d';
			$args[] = absint( $atts['space_id'] );
		}

		// Space-visibility + per-post is_private gate so the recent-posts
		// shortcode (and the forum-feed / trending blocks + recent-posts widget
		// that delegate to it) never leak private/hidden-space posts.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 'sp' );
		[ $priv_sql, $priv_params ]           = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );
		if ( '1=1' !== $space_vis_sql ) {
			$where .= ' AND ' . $space_vis_sql;
			$args   = array_merge( $args, $space_vis_params );
		}
		if ( '' !== $priv_sql ) {
			$where .= ' AND ' . $priv_sql;
			$args   = array_merge( $args, $priv_params );
		}

		$order = 'latest' === $atts['sort'] ? 'p.created_at DESC' : 'p.vote_score DESC';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query  = "SELECT p.*, sp.slug AS space_slug, sp.title AS space_title
		          FROM {$posts_tbl} p
		          LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
		          WHERE {$where}
		          ORDER BY {$order}
		          LIMIT %d";
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$posts = $wpdb->get_results( $wpdb->prepare( $query, ...$args ) ) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $posts ) ) {
			return '<div class="jt-shortcode-empty">' . esc_html__( 'No posts yet.', 'jetonomy' ) . '</div>';
		}

		$out = '<div class="jt-shortcode jt-shortcode-recent-posts">';
		foreach ( $posts as $post ) {
			$url    = $base . '/s/' . $post->space_slug . '/t/' . $post->slug . '/';
			$time   = human_time_diff( strtotime( $post->created_at ), time() );
			$author = get_userdata( (int) $post->author_id );
			$out   .= '<div class="jt-shortcode-post">';
			$out   .= '<a href="' . esc_url( $url ) . '" class="jt-shortcode-post-title">' . esc_html( $post->title ) . '</a>';
			$out   .= '<div class="jt-shortcode-post-meta">';
			$out   .= esc_html( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) );
			$out   .= ' · ' . esc_html( $post->space_title ?? '' );
			/* translators: %s: human-readable time difference */
			$out .= ' · ' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time ) );
			$out .= '</div>';
			$out .= '<div class="jt-shortcode-post-stats">';
			$out .= '<span>' . (int) $post->vote_score . ' ' . esc_html( _n( 'vote', 'votes', (int) $post->vote_score, 'jetonomy' ) ) . '</span>';
			$out .= '<span>' . (int) $post->reply_count . ' ' . esc_html( _n( 'reply', 'replies', (int) $post->reply_count, 'jetonomy' ) ) . '</span>';
			$out .= '</div></div>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * [jetonomy_trending_posts count="5" space_id="" window="7"]
	 *
	 * Trending = time-decayed hot score over the last N days (default 7).
	 * Recent engagement outranks lifetime score.
	 */
	public static function trending_posts( $atts ): string {
		$atts = shortcode_atts(
			array(
				'count'    => 5,
				'space_id' => 0,
				'window'   => 7,
			),
			$atts,
			'jetonomy_trending_posts'
		);

		self::enqueue_styles();

		$limit    = absint( $atts['count'] ) ?: 5;
		$window   = absint( $atts['window'] ) ?: 7;
		$space_id = absint( $atts['space_id'] );
		$base     = base_url();

		$posts = Models\Post::list_trending( $limit, $space_id ?: null, $window );

		if ( empty( $posts ) ) {
			return '<div class="jt-shortcode-empty">' . esc_html__( 'No trending discussions yet. Check back soon.', 'jetonomy' ) . '</div>';
		}

		$rank = 0;
		$out  = '<div class="jt-shortcode jt-shortcode-trending-posts">';
		foreach ( $posts as $post ) {
			++$rank;
			$url    = $base . '/s/' . $post->space_slug . '/t/' . $post->slug . '/';
			$author = get_userdata( (int) $post->author_id );
			$out   .= '<div class="jt-shortcode-post jt-shortcode-trending-post">';
			$out   .= '<span class="jt-shortcode-trending-rank" aria-hidden="true">' . (int) $rank . '</span>';
			$out   .= '<div class="jt-shortcode-trending-body">';
			$out   .= '<a href="' . esc_url( $url ) . '" class="jt-shortcode-post-title">' . esc_html( $post->title ) . '</a>';
			$out   .= '<div class="jt-shortcode-post-meta">';
			$out   .= esc_html( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) );
			if ( ! empty( $post->space_title ) ) {
				$out .= ' · ' . esc_html( $post->space_title );
			}
			$out .= '</div>';
			$out .= '<div class="jt-shortcode-post-stats">';
			$out .= '<span>' . (int) $post->vote_score . ' ' . esc_html( _n( 'vote', 'votes', (int) $post->vote_score, 'jetonomy' ) ) . '</span>';
			$out .= '<span>' . (int) $post->reply_count . ' ' . esc_html( _n( 'reply', 'replies', (int) $post->reply_count, 'jetonomy' ) ) . '</span>';
			$out .= '</div></div></div>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * [jetonomy_spaces count="6" category_id=""]
	 */
	public static function spaces( $atts ): string {
		$atts = shortcode_atts(
			array(
				'count'       => 6,
				'category_id' => 0,
			),
			$atts,
			'jetonomy_spaces'
		);

		self::enqueue_styles();

		$limit = absint( $atts['count'] ) ?: 6;
		$base  = base_url();

		global $wpdb;
		$spaces_tbl = table( 'spaces' );

		$where = "status = 'active' AND visibility = 'public'";
		$args  = array();
		if ( ! empty( $atts['category_id'] ) ) {
			$where .= ' AND category_id = %d';
			$args[] = absint( $atts['category_id'] );
		}
		$args[] = $limit;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$spaces = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$spaces_tbl} WHERE {$where} ORDER BY post_count DESC LIMIT %d",
				...$args
			)
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $spaces ) ) {
			return '<div class="jt-shortcode-empty">' . esc_html__( 'No spaces yet.', 'jetonomy' ) . '</div>';
		}

		$out = '<div class="jt-shortcode jt-shortcode-spaces">';
		foreach ( $spaces as $space ) {
			$url  = $base . '/s/' . $space->slug . '/';
			$out .= '<a href="' . esc_url( $url ) . '" class="jt-shortcode-space">';
			$out .= '<strong>' . esc_html( $space->title ) . '</strong>';
			if ( ! empty( $space->description ) ) {
				$out .= '<span class="jt-shortcode-space-desc">' . esc_html( wp_trim_words( $space->description, 12 ) ) . '</span>';
			}
			$out .= '<span class="jt-shortcode-space-stats">' . (int) $space->post_count . ' ' . esc_html( _n( 'post', 'posts', (int) $space->post_count, 'jetonomy' ) ) . '</span>';
			$out .= '</a>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * [jetonomy_leaderboard count="10"]
	 */
	public static function leaderboard( $atts ): string {
		$atts = shortcode_atts( array( 'count' => 10 ), $atts, 'jetonomy_leaderboard' );

		self::enqueue_styles();

		$limit = absint( $atts['count'] ) ?: 10;
		$base  = base_url();

		global $wpdb;
		$profiles_tbl = table( 'user_profiles' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$leaders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$profiles_tbl} ORDER BY reputation DESC LIMIT %d",
				$limit
			)
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $leaders ) ) {
			return '<div class="jt-shortcode-empty">' . esc_html__( 'No members yet.', 'jetonomy' ) . '</div>';
		}

		$out = '<div class="jt-shortcode jt-shortcode-leaderboard"><ol>';
		foreach ( $leaders as $leader ) {
			$user = get_userdata( (int) $leader->user_id );
			if ( ! $user ) {
				continue;
			}
			$url  = get_profile_url( (int) $leader->user_id );
			$out .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $user->display_name ) . '</a>';
			$out .= ' <span class="jt-shortcode-rep">' . (int) $leader->reputation . ' rep</span></li>';
		}
		$out .= '</ol></div>';

		return $out;
	}

	/**
	 * [jetonomy_user_profile user_id="1"]
	 */
	public static function user_profile( $atts ): string {
		$atts = shortcode_atts( array( 'user_id' => 0 ), $atts, 'jetonomy_user_profile' );

		$user_id = absint( $atts['user_id'] ) ?: get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

		self::enqueue_styles();

		$user    = get_userdata( $user_id );
		$profile = Models\UserProfile::find_by_user( $user_id );
		if ( ! $user ) {
			return '';
		}

		$trust = (int) ( $profile->trust_level ?? 0 );
		$url   = get_profile_url( $user_id );

		$out  = '<div class="jt-shortcode jt-shortcode-profile-card">';
		$out .= '<a href="' . esc_url( $url ) . '">';
		$out .= '<strong>' . esc_html( $user->display_name ) . '</strong>';
		$out .= '<span class="jt-tl" data-jt-tl="' . $trust . '">' . $trust . '</span>';
		$out .= '</a>';
		if ( ! empty( $profile->bio ) ) {
			$out .= '<p>' . esc_html( wp_trim_words( $profile->bio, 20 ) ) . '</p>';
		}
		$out .= '<div class="jt-shortcode-profile-stats">';
		$out .= '<span>' . (int) ( $profile->reputation ?? 0 ) . ' rep</span>';
		$out .= '<span>' . (int) ( $profile->post_count ?? 0 ) . ' ' . esc_html( _n( 'post', 'posts', (int) ( $profile->post_count ?? 0 ), 'jetonomy' ) ) . '</span>';
		$out .= '</div></div>';

		return $out;
	}

	/**
	 * [jetonomy_space_members space_id="1" count="10"]
	 */
	public static function space_members( $atts ): string {
		$atts = shortcode_atts(
			array(
				'space_id' => 0,
				'count'    => 10,
			),
			$atts,
			'jetonomy_space_members'
		);

		$space_id = absint( $atts['space_id'] );
		if ( ! $space_id ) {
			return '';
		}

		self::enqueue_styles();

		$limit = absint( $atts['count'] ) ?: 10;

		global $wpdb;
		$members_tbl  = table( 'space_members' );
		$profiles_tbl = table( 'user_profiles' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$members = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sm.user_id, sm.role, up.reputation, up.trust_level
			 FROM {$members_tbl} sm
			 LEFT JOIN {$profiles_tbl} up ON up.user_id = sm.user_id
			 WHERE sm.space_id = %d
			 ORDER BY up.reputation DESC
			 LIMIT %d",
				$space_id,
				$limit
			)
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $members ) ) {
			return '<div class="jt-shortcode-empty">' . esc_html__( 'No members yet.', 'jetonomy' ) . '</div>';
		}

		$out = '<div class="jt-shortcode jt-shortcode-members">';
		foreach ( $members as $m ) {
			$user = get_userdata( (int) $m->user_id );
			if ( ! $user ) {
				continue;
			}
			$url  = get_profile_url( (int) $m->user_id );
			$out .= '<div class="jt-shortcode-member">';
			$out .= '<a href="' . esc_url( $url ) . '">' . esc_html( $user->display_name ) . '</a>';
			$out .= ' <span class="jt-shortcode-rep">' . (int) $m->reputation . ' rep</span>';
			$out .= '</div>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * [jetonomy_compose_topic mode="picker|fixed" space_id="" types="topic,question,idea"]
	 *
	 * Inline topic composer usable on any WordPress page or page-builder canvas.
	 * In `picker` mode shows a <select> of spaces the current user is a member
	 * of and can post in. In `fixed` mode posts directly to the given space;
	 * invalid/missing space IDs degrade to picker so the block never silently
	 * breaks when a space is renumbered or deleted.
	 */
	public static function compose_topic( $atts ): string {
		$atts = shortcode_atts(
			array(
				'mode'     => 'picker',
				'space_id' => 0,
				'types'    => 'topic,question,idea',
			),
			$atts,
			'jetonomy_compose_topic'
		);

		$mode     = in_array( $atts['mode'], array( 'fixed', 'picker' ), true ) ? $atts['mode'] : 'picker';
		$space_id = absint( $atts['space_id'] );
		$types    = array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) $atts['types'] ) )
			)
		);
		if ( empty( $types ) ) {
			$types = array( 'topic', 'question', 'idea' );
		}

		$space    = null;
		$postable = array();

		if ( 'fixed' === $mode && $space_id ) {
			$space = \Jetonomy\Models\Space::find( $space_id );
			// If the fixed space is invalid, fall through to picker-mode data.
			if ( ! $space ) {
				$mode = 'picker';
			}
		}

		if ( 'picker' === $mode ) {
			$postable = self::postable_spaces_for_current_user();
		}

		self::enqueue_styles();
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( 'jetonomy-compose-topic' );
		}

		// composePost submits through window.jetonomyRest.restFetch, which is
		// only enqueued on community routes by Template_Loader::render(). The
		// embed can land on any WP page, so pull in the client (plus the
		// minimal jetonomyData payload) here too. Basecamp #9967059857.
		Template_Loader::enqueue_rest_client();

		// Seed the Interactivity API state with the REST base + nonce.
		// Template_Loader seeds the full state on community pages; here we
		// need the minimum needed for submit (apiBase, nonce, communityBase,
		// i18n.*) so the embed works on any WP page. wp_interactivity_state
		// merges keys across callers, so this is additive.
		if ( function_exists( 'wp_interactivity_state' ) ) {
			$settings = (array) get_option( 'jetonomy_settings', array() );
			wp_interactivity_state(
				'jetonomy',
				array(
					'apiBase'       => rest_url( 'jetonomy/v1' ),
					'_nonce'        => wp_create_nonce( 'wp_rest' ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'communityBase' => home_url( '/' . ( isset( $settings['base_slug'] ) ? (string) $settings['base_slug'] : 'community' ) ),
					'i18n'          => array(
						'chooseSpace'    => __( 'Choose a space first.', 'jetonomy' ),
						'titleRequired'  => __( 'Title is required.', 'jetonomy' ),
						'couldNotCreate' => __( 'Could not create the topic.', 'jetonomy' ),
						'networkError'   => __( 'Network error. Please try again.', 'jetonomy' ),
					),
				)
			);
		}

		ob_start();
		Template_Loader::partial(
			'compose-topic-embed',
			array(
				'mode'     => $mode,
				'space_id' => $space_id,
				'space'    => $space,
				'postable' => $postable,
				'types'    => $types,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Spaces the current user is a member of AND can create posts in.
	 *
	 * @return \stdClass[] Space rows (id, slug, title, visibility, etc.).
	 */
	private static function postable_spaces_for_current_user(): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		$memberships = \Jetonomy\Models\SpaceMember::list_user_spaces( $user_id );
		if ( empty( $memberships ) ) {
			return array();
		}

		$spaces = array();
		foreach ( $memberships as $m ) {
			$sid = (int) $m->space_id;
			if ( ! \Jetonomy\Permissions\Permission_Engine::can( $user_id, 'create_posts', $sid ) ) {
				continue;
			}
			$space = \Jetonomy\Models\Space::find( $sid );
			if ( $space ) {
				$spaces[] = $space;
			}
		}

		usort(
			$spaces,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a->title, (string) $b->title );
			}
		);

		return $spaces;
	}
}
