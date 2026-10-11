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
	/**
	 * Explain a misconfigured shortcode to whoever can fix it.
	 *
	 * A blank region would hide a mistyped attribute from the owner, so these paths
	 * explain it (genuinely empty data renders `jt-shortcode-empty`). Only users who
	 * can edit see this: visitors keep the silent output, so editor guidance never
	 * surfaces on a public page.
	 *
	 * @param string $message What the author needs to change.
	 * @return string
	 */
	/**
	 * Resolve a space reference (numeric id or slug) to a row, or null.
	 *
	 * Shared so every shortcode answers the same question. A numeric ref is looked up
	 * like a slug, so a non-existent id yields null (a notice) rather than an EMPTY
	 * state that looks like a real empty space.
	 *
	 * @param string $ref Numeric id or slug. '' and '0' mean "not supplied".
	 * @return object|null
	 */
	private static function resolve_space_ref( string $ref ): ?object {
		$ref = trim( $ref );
		if ( '' === $ref || '0' === $ref ) {
			return null;
		}

		return ctype_digit( $ref )
			? Models\Space::find( absint( $ref ) )
			: Models\Space::find_by_slug( $ref );
	}

	/**
	 * The notice shown when a supplied reference does not name anything.
	 *
	 * @param string $tag   Shortcode tag, so the editor knows which one to edit.
	 * @param string $thing What the reference was meant to name ('space', 'category').
	 * @param string $ref   What the author actually wrote.
	 */
	private static function missing_ref_notice( string $tag, string $thing, string $ref ): string {
		return self::config_notice(
			sprintf(
				/* translators: 1: shortcode tag, 2: the kind of thing referenced, 3: the value written. */
				__( '%1$s: no %2$s matching "%3$s".', 'jetonomy' ),
				$tag,
				$thing,
				$ref
			)
		);
	}

	private static function config_notice( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return '<div class="jt-shortcode-empty jt-shortcode-notice">' . esc_html( $message ) . '</div>';
	}

	public static function widget_embed( $atts ): string {
		// shortcode_atts would strip every key except those in defaults — but
		// each widget has its own instance keys (count, title, etc.), so we
		// take the raw user atts and only validate the dispatch key here.
		$user_atts = is_array( $atts ) ? $atts : array();
		$id_base   = isset( $user_atts['id'] ) ? (string) $user_atts['id'] : '';
		if ( '' === $id_base ) {
			return self::config_notice(
				__( 'jetonomy_widget needs an id attribute, e.g. [jetonomy_widget id="jetonomy_recent_posts"].', 'jetonomy' )
			);
		}

		// Class names match what `register_widget()` was called with — leading
		// backslash would mismatch the WP_Widget_Factory key.
		$class_map = array(
			'jetonomy_recent_posts'  => Widgets\Recent_Posts_Widget::class,
			'jetonomy_leaderboard'   => Widgets\Leaderboard_Widget::class,
			'jetonomy_active_spaces' => Widgets\Active_Spaces_Widget::class,
			'jetonomy_user_stats'    => Widgets\User_Stats_Widget::class,
		);
		// The prefix check belongs here, not on the empty-id branch: an id that is
		// present but wrong ("bogus_widget") is an unknown id, and the editor needs
		// the valid-ids list, not "needs an id attribute" for one they did supply.
		if ( 0 !== strpos( $id_base, 'jetonomy_' ) || ! isset( $class_map[ $id_base ] ) || ! class_exists( $class_map[ $id_base ] ) ) {
			return self::config_notice(
				sprintf(
					/* translators: 1: the id that was passed, 2: comma-separated list of valid ids. */
					__( 'jetonomy_widget: unknown id "%1$s". Valid ids are %2$s.', 'jetonomy' ),
					$id_base,
					implode( ', ', array_keys( $class_map ) )
				)
			);
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
			// blocks.css declares no tokens of its own, so this fallback has to
			// carry the same dependency Blocks::register_block_assets() does or
			// the shortcode renders with every --jt-* unresolved.
			wp_register_style( 'jetonomy-blocks', JETONOMY_URL . 'assets/css/blocks.css', array( jetonomy_register_token_style() ), JETONOMY_VERSION );
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

		// A supplied space reference must name something. Without this the query
		// matches nothing and the owner sees "No posts yet." for a space that
		// does not exist - identical to a genuinely empty one.
		$space_ref = trim( (string) $atts['space_id'] );
		$space_id  = 0;
		if ( '' !== $space_ref && '0' !== $space_ref ) {
			$space = self::resolve_space_ref( $space_ref );
			if ( ! $space ) {
				return self::missing_ref_notice( 'jetonomy_recent_posts', 'space', $space_ref );
			}
			// Filter on the RESOLVED id. absint() on a slug yields 0, which
			// silently queried space 0 and returned nothing.
			$space_id = absint( $space->id );
		}

		// Viewer-gated (space access, private topics, blocked authors) so the
		// shortcode, and the forum-feed / trending blocks and recent-posts
		// widget that delegate to it, never leak private or hidden-space posts.
		$posts = Models\Post::list_recent_visible( $limit, $space_id, (string) $atts['sort'] );

		if ( empty( $posts ) ) {
			return '<div class="jt-shortcode-empty">' . esc_html( sprintf( /* translators: %s: the plural label of the item (the configured noun). */ __( 'No %s yet.', 'jetonomy' ), jetonomy_label( 'topic', true, true ) ) ) . '</div>';
		}

		$out = '<div class="jt-shortcode jt-shortcode-recent-posts">';
		foreach ( $posts as $post ) {
			$url    = route_url( 'post', $post->space_slug, $post->slug );
			$time   = human_time_diff( strtotime( $post->created_at ), time() );
			$author = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
			$out   .= '<div class="jt-shortcode-post">';
			$out   .= '<a href="' . esc_url( $url ) . '" class="jt-shortcode-post-title">' . esc_html( $post->title ) . '</a>';
			$out   .= '<div class="jt-shortcode-post-meta">';
			$out   .= esc_html( $author['name'] );
			$out   .= ' · ' . esc_html( $post->space_title ?? '' );
			/* translators: %s: human-readable time difference. */
			$out .= ' · ' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time ) );
			$out .= '</div>';
			$out .= '<div class="jt-shortcode-post-stats">';
			/* translators: %s: number of votes. */
			$out .= '<span>' . esc_html( sprintf( _n( '%s vote', '%s votes', (int) $post->vote_score, 'jetonomy' ), number_format_i18n( (int) $post->vote_score ) ) ) . '</span>';
			$out .= '<span>' . esc_html( count_label( (int) $post->reply_count, 'reply' ) ) . '</span>';
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

		$limit = absint( $atts['count'] ) ?: 5;

		// See jetonomy_recent_posts: a reference that names nothing must say so
		// rather than render the empty state.
		$space_ref = trim( (string) $atts['space_id'] );
		$space_id  = 0;
		if ( '' !== $space_ref && '0' !== $space_ref ) {
			$space = self::resolve_space_ref( $space_ref );
			if ( ! $space ) {
				return self::missing_ref_notice( 'jetonomy_trending_posts', 'space', $space_ref );
			}
			$space_id = absint( $space->id );
		}
		$window = absint( $atts['window'] ) ?: 7;

		$posts = Models\Post::list_trending( $limit, $space_id ?: null, $window );

		if ( empty( $posts ) ) {
			return '<div class="jt-shortcode-empty">' . esc_html__( 'No trending discussions yet. Check back soon.', 'jetonomy' ) . '</div>';
		}

		$rank = 0;
		$out  = '<div class="jt-shortcode jt-shortcode-trending-posts">';
		foreach ( $posts as $post ) {
			++$rank;
			$url    = route_url( 'post', $post->space_slug, $post->slug );
			$author = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
			$out   .= '<div class="jt-shortcode-post jt-shortcode-trending-post">';
			$out   .= '<span class="jt-shortcode-trending-rank" aria-hidden="true">' . (int) $rank . '</span>';
			$out   .= '<div class="jt-shortcode-trending-body">';
			$out   .= '<a href="' . esc_url( $url ) . '" class="jt-shortcode-post-title">' . esc_html( $post->title ) . '</a>';
			$out   .= '<div class="jt-shortcode-post-meta">';
			$out   .= esc_html( $author['name'] );
			if ( ! empty( $post->space_title ) ) {
				$out .= ' · ' . esc_html( $post->space_title );
			}
			$out .= '</div>';
			$out .= '<div class="jt-shortcode-post-stats">';
			/* translators: %s: number of votes. */
			$out .= '<span>' . esc_html( sprintf( _n( '%s vote', '%s votes', (int) $post->vote_score, 'jetonomy' ), number_format_i18n( (int) $post->vote_score ) ) ) . '</span>';
			$out .= '<span>' . esc_html( count_label( (int) $post->reply_count, 'reply' ) ) . '</span>';
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

		// Same contract for the category reference.
		$category_ref = trim( (string) $atts['category_id'] );
		$category_id  = 0;
		if ( '' !== $category_ref && '0' !== $category_ref ) {
			// Both branches resolve through the viewer's visibility, so a
			// shortcode cannot surface a category (or the spaces inside it)
			// that the community listing withholds from the same viewer.
			$category = ctype_digit( $category_ref )
				? Models\Category::find_visible( absint( $category_ref ) )
				: Models\Category::find_by_slug( $category_ref );
			if ( ! $category ) {
				return self::missing_ref_notice( 'jetonomy_spaces', 'category', $category_ref );
			}
			$category_id = absint( $category->id );
		}

		$spaces = Models\Space::list_public_by_post_count( $limit, $category_id );

		if ( empty( $spaces ) ) {
			return '<div class="jt-shortcode-empty">' . esc_html__( 'No spaces yet.', 'jetonomy' ) . '</div>';
		}

		$out = '<div class="jt-shortcode jt-shortcode-spaces">';
		foreach ( $spaces as $space ) {
			$url  = route_url( 'space', $space->slug );
			$out .= '<a href="' . esc_url( $url ) . '" class="jt-shortcode-space">';
			$out .= '<strong>' . esc_html( $space->title ) . '</strong>';
			if ( ! empty( $space->description ) ) {
				$out .= '<span class="jt-shortcode-space-desc">' . esc_html( wp_trim_words( $space->description, 12 ) ) . '</span>';
			}
			$out .= '<span class="jt-shortcode-space-stats">' . esc_html( \Jetonomy\count_label( (int) $space->post_count, 'topic' ) ) . '</span>';
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

		// The Leaderboard page's own model query (same eligible population:
		// real members with reputation > 0, same tie order). Also backs the
		// leaderboard block and the classic widget, which render this shortcode.
		$leaders = Models\UserProfile::list_for_leaderboard( 'all', $limit, 0 );

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
			$out .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( user_display_name( $user ) ) . '</a>';
			$out .= ' <span class="jt-shortcode-rep">' . esc_html( sprintf( /* translators: %s: reputation points. */ _n( '%s rep', '%s rep', (int) $leader->reputation, 'jetonomy' ), number_format_i18n( (int) $leader->reputation ) ) ) . '</span></li>';
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
			return self::config_notice(
				__( 'jetonomy_user_profile needs a user_id attribute when nobody is logged in, e.g. [jetonomy_user_profile user_id="1"].', 'jetonomy' )
			);
		}

		self::enqueue_styles();

		$user    = get_userdata( $user_id );
		$profile = Models\UserProfile::find_by_user( $user_id );
		if ( ! $user ) {
			return self::config_notice(
				sprintf(
					/* translators: %d: the user ID that was passed. */
					__( 'jetonomy_user_profile: no user with ID %d.', 'jetonomy' ),
					$user_id
				)
			);
		}

		$trust = (int) ( $profile->trust_level ?? 0 );
		$url   = get_profile_url( $user_id );

		$out  = '<div class="jt-shortcode jt-shortcode-profile-card">';
		$out .= '<a href="' . esc_url( $url ) . '">';
		$out .= '<strong>' . esc_html( user_display_name( $user ) ) . '</strong>';
		$out .= '<span class="jt-tl" data-jt-tl="' . $trust . '">' . $trust . '</span>';
		$out .= '</a>';
		if ( ! empty( $profile->bio ) ) {
			$out .= '<p>' . esc_html( wp_trim_words( $profile->bio, 20 ) ) . '</p>';
		}
		$out .= '<div class="jt-shortcode-profile-stats">';
		/* translators: %s: reputation points. */
		$out .= '<span>' . esc_html( sprintf( _n( '%s point', '%s points', (int) ( $profile->reputation ?? 0 ), 'jetonomy' ), number_format_i18n( (int) ( $profile->reputation ?? 0 ) ) ) ) . '</span>';
		$out .= '<span>' . esc_html( \Jetonomy\count_label( (int) ( $profile->post_count ?? 0 ), 'topic' ) ) . '</span>';
		$out .= '</div></div>';

		return $out;
	}

	/**
	 * [jetonomy_space_members space_id="1" count="10"]
	 */
	public static function space_members( $atts ): string {
		$atts = shortcode_atts(
			array(
				'space_id' => '',
				'count'    => 10,
			),
			$atts,
			'jetonomy_space_members'
		);

		// Accept a slug as well as a numeric id. Nothing in the admin UI ever
		// shows a space's numeric ID, so requiring one asked the site owner for
		// a value they had no way to look up; the slug is what Jetonomy > Spaces
		// and every community URL already display.
		//
		// BOTH branches resolve to a real row. The first version only verified
		// the slug: a numeric ref was cast and trusted, so space_id="99999" kept
		// a truthy id, the members query matched nothing, and the owner was shown
		// "No members yet." for a space that does not exist - the exact confusion
		// this was meant to end, surviving in the one branch nobody probed.
		$space_ref = trim( (string) $atts['space_id'] );
		$space     = null;

		if ( '' !== $space_ref ) {
			$space = ctype_digit( $space_ref )
				? Models\Space::find( absint( $space_ref ) )
				: Models\Space::find_by_slug( $space_ref );

			if ( ! $space ) {
				return self::config_notice(
					sprintf(
						/* translators: %s: the space slug or id that was passed. */
						__( 'jetonomy_space_members: no space matching "%s".', 'jetonomy' ),
						$space_ref
					)
				);
			}
		}

		$space_id = $space ? absint( $space->id ) : 0;
		if ( ! $space_id ) {
			return self::config_notice(
				__( 'jetonomy_space_members needs a space_id attribute - the space slug or its numeric id, e.g. [jetonomy_space_members space_id="announcements"].', 'jetonomy' )
			);
		}

		self::enqueue_styles();

		$limit = absint( $atts['count'] ) ?: 10;

		// Deliberately NOT block-filtered — a member roster/ranking, not a
		// content feed. Per-viewer filtering would re-rank the list and leak
		// "you blocked someone" via a missing row.
		$members = Models\SpaceMember::list_top_by_reputation( $space_id, $limit );

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
			$out .= '<a href="' . esc_url( $url ) . '">' . esc_html( user_display_name( $user ) ) . '</a>';
			$out .= ' <span class="jt-shortcode-rep">' . esc_html( sprintf( /* translators: %s: reputation points. */ _n( '%s rep', '%s rep', (int) $m->reputation, 'jetonomy' ), number_format_i18n( (int) $m->reputation ) ) ) . '</span>';
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

		// A supplied space that does not exist is an authoring mistake in EVERY mode, so
		// the owner gets a notice instead of a working compose form aimed at nothing
		// (fixed mode would otherwise degrade silently to a picker).
		$space_ref = trim( (string) $atts['space_id'] );
		if ( '' !== $space_ref && '0' !== $space_ref ) {
			$space = self::resolve_space_ref( $space_ref );
			if ( ! $space ) {
				return self::missing_ref_notice( 'jetonomy_compose_topic', 'space', $space_ref );
			}
			$space_id = absint( $space->id );
		}

		if ( 'picker' !== $mode && ! $space ) {
			$mode = 'picker';
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
		// minimal jetonomyData payload) here too.
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
					// The same strings as community pages, so the composer never shows English fallbacks here.
					'i18n'          => array_merge(
						Template_Loader::app_i18n(),
						array(
							'chooseSpace'   => __( 'Choose a space first.', 'jetonomy' ),
							'titleRequired' => __( 'Title is required.', 'jetonomy' ),
						)
					),
					'localeData'    => Template_Loader::app_locale_data(),
					/* translators: %s: the label of the item (the configured noun); "Post" is the verb. */
					'publishLabel'  => sprintf( __( 'Post %s', 'jetonomy' ), jetonomy_label( 'topic' ) ),
				)
			);
			wp_enqueue_script( 'wp-i18n' );
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
