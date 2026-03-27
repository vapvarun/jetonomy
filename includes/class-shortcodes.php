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
 * [jetonomy_recent_posts]  — Recent forum posts.
 * [jetonomy_spaces]        — Space directory grid.
 * [jetonomy_leaderboard]   — Top members by reputation.
 * [jetonomy_user_profile]  — Single user profile card.
 * [jetonomy_space_members] — Member list for a space.
 */
class Shortcodes {

	public static function register(): void {
		add_shortcode( 'jetonomy_recent_posts', array( __CLASS__, 'recent_posts' ) );
		add_shortcode( 'jetonomy_spaces', array( __CLASS__, 'spaces' ) );
		add_shortcode( 'jetonomy_leaderboard', array( __CLASS__, 'leaderboard' ) );
		add_shortcode( 'jetonomy_user_profile', array( __CLASS__, 'user_profile' ) );
		add_shortcode( 'jetonomy_space_members', array( __CLASS__, 'space_members' ) );
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
}
