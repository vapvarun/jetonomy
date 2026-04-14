<?php
/**
 * wp jetonomy tag — tag CRUD and post attachment via Taxonomy_Journey.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Taxonomy_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Jetonomy tags from the terminal.
 *
 * All subcommands delegate to {@see Taxonomy_Journey} for the actual work
 * so PHPUnit tests can assert on the same code path the CLI runs.
 */
final class Tag_Command extends Base_Command {

	/**
	 * Find a tag by name, creating it if missing.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Tag display name. Slug is derived automatically.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy tag create --name="announcement"
	 */
	public function create( $args, $assoc ): void {
		$name   = (string) ( $assoc['name'] ?? '' );
		$result = ( new Taxonomy_Journey() )->create_or_get_tag( $name );
		$this->render( $result, $assoc );
	}

	/**
	 * Delete a tag by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Tag ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy tag delete 7
	 */
	public function delete( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Taxonomy_Journey() )->delete_tag( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Fetch a tag by slug.
	 *
	 * ## OPTIONS
	 *
	 * --slug=<slug>
	 * : Tag slug to look up.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy tag get-by-slug --slug=announcement
	 *
	 * @subcommand get-by-slug
	 */
	public function get_by_slug( $args, $assoc ): void {
		$slug   = (string) ( $assoc['slug'] ?? '' );
		$result = ( new Taxonomy_Journey() )->get_tag_by_slug( $slug );
		$this->render( $result, $assoc );
	}

	/**
	 * Attach a tag to a post.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Post ID.
	 *
	 * --tag=<id>
	 * : Tag ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy tag attach --post=42 --tag=7
	 */
	public function attach( $args, $assoc ): void {
		$post_id = (int) ( $assoc['post'] ?? 0 );
		$tag_id  = (int) ( $assoc['tag'] ?? 0 );
		$result  = ( new Taxonomy_Journey() )->attach_tag_to_post( $post_id, $tag_id );
		$this->render( $result, $assoc );
	}

	/**
	 * Detach a tag from a post.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Post ID.
	 *
	 * --tag=<id>
	 * : Tag ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy tag detach --post=42 --tag=7
	 */
	public function detach( $args, $assoc ): void {
		$post_id = (int) ( $assoc['post'] ?? 0 );
		$tag_id  = (int) ( $assoc['tag'] ?? 0 );
		$result  = ( new Taxonomy_Journey() )->detach_tag_from_post( $post_id, $tag_id );
		$this->render( $result, $assoc );
	}

	/**
	 * List every tag attached to a post.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Post ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated column names to show.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy tag list-for-post --post=42
	 *
	 * @subcommand list-for-post
	 */
	public function list_for_post( $args, $assoc ): void {
		$post_id = (int) ( $assoc['post'] ?? 0 );
		$result  = ( new Taxonomy_Journey() )->list_tags_for_post( $post_id );
		$this->render_list( $result, $assoc );
	}

	/**
	 * List the most popular tags by post_count.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum number of tags to return. Default: 20.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated column names to show.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy tag popular
	 *     wp jetonomy tag popular --limit=5
	 */
	public function popular( $args, $assoc ): void {
		$limit  = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 20;
		$result = ( new Taxonomy_Journey() )->list_popular_tags( $limit );
		$this->render_list( $result, $assoc );
	}

	/**
	 * Search tags by name (partial match).
	 *
	 * ## OPTIONS
	 *
	 * --query=<text>
	 * : Non-empty search string.
	 *
	 * [--limit=<n>]
	 * : Maximum number of results. Default: 10.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated column names to show.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy tag search --query=announce
	 *     wp jetonomy tag search --query=bug --limit=5
	 */
	public function search( $args, $assoc ): void {
		$query  = (string) ( $assoc['query'] ?? '' );
		$limit  = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 10;
		$result = ( new Taxonomy_Journey() )->search_tags( $query, $limit );
		$this->render_list( $result, $assoc );
	}
}
