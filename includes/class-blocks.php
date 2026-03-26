<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Register Gutenberg blocks (server-side rendered).
 *
 * Each block renders via a shortcode callback — keeps logic DRY.
 * Block settings (count, space_id, etc.) map to shortcode attributes.
 */
class Blocks {

	public static function register(): void {
		add_action( 'init', [ __CLASS__, 'register_blocks' ] );
	}

	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type( 'jetonomy/forum-feed', [
			'api_version'     => 3,
			'attributes'      => [
				'count'   => [ 'type' => 'number', 'default' => 5 ],
				'spaceId' => [ 'type' => 'number', 'default' => 0 ],
				'sort'    => [ 'type' => 'string', 'default' => 'latest' ],
			],
			'render_callback' => [ __CLASS__, 'render_forum_feed' ],
			'category'        => 'widgets',
			'title'           => __( 'Forum Feed', 'jetonomy' ),
			'description'     => __( 'Display recent forum discussions.', 'jetonomy' ),
			'icon'            => 'format-chat',
			'keywords'        => [ 'forum', 'posts', 'discussions', 'jetonomy' ],
		] );

		register_block_type( 'jetonomy/space-list', [
			'api_version'     => 3,
			'attributes'      => [
				'count'      => [ 'type' => 'number', 'default' => 6 ],
				'categoryId' => [ 'type' => 'number', 'default' => 0 ],
			],
			'render_callback' => [ __CLASS__, 'render_space_list' ],
			'category'        => 'widgets',
			'title'           => __( 'Space List', 'jetonomy' ),
			'description'     => __( 'Display forum spaces as a grid.', 'jetonomy' ),
			'icon'            => 'groups',
			'keywords'        => [ 'spaces', 'categories', 'forum', 'jetonomy' ],
		] );

		register_block_type( 'jetonomy/leaderboard', [
			'api_version'     => 3,
			'attributes'      => [
				'count' => [ 'type' => 'number', 'default' => 10 ],
			],
			'render_callback' => [ __CLASS__, 'render_leaderboard' ],
			'category'        => 'widgets',
			'title'           => __( 'Leaderboard', 'jetonomy' ),
			'description'     => __( 'Display top community members by reputation.', 'jetonomy' ),
			'icon'            => 'awards',
			'keywords'        => [ 'leaderboard', 'ranking', 'reputation', 'jetonomy' ],
		] );
	}

	public static function render_forum_feed( array $attributes ): string {
		$atts = 'count="' . absint( $attributes['count'] ) . '"';
		if ( ! empty( $attributes['spaceId'] ) ) {
			$atts .= ' space_id="' . absint( $attributes['spaceId'] ) . '"';
		}
		$atts .= ' sort="' . esc_attr( $attributes['sort'] ?? 'latest' ) . '"';

		return '<div class="wp-block-jetonomy-forum-feed">' . do_shortcode( '[jetonomy_recent_posts ' . $atts . ']' ) . '</div>';
	}

	public static function render_space_list( array $attributes ): string {
		$atts = 'count="' . absint( $attributes['count'] ) . '"';
		if ( ! empty( $attributes['categoryId'] ) ) {
			$atts .= ' category_id="' . absint( $attributes['categoryId'] ) . '"';
		}

		return '<div class="wp-block-jetonomy-space-list">' . do_shortcode( '[jetonomy_spaces ' . $atts . ']' ) . '</div>';
	}

	public static function render_leaderboard( array $attributes ): string {
		return '<div class="wp-block-jetonomy-leaderboard">' . do_shortcode( '[jetonomy_leaderboard count="' . absint( $attributes['count'] ) . '"]' ) . '</div>';
	}
}
