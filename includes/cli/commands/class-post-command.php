<?php
/**
 * wp jetonomy post — post CRUD via Content_Journey.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Content_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Jetonomy posts from the terminal.
 *
 * All subcommands delegate to {@see Content_Journey} for the actual work so
 * PHPUnit tests can assert on the same code path headless tests run.
 */
final class Post_Command extends Base_Command {

	/**
	 * Create a new post in a space.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Space ID the post belongs to.
	 *
	 * --author=<id>
	 * : Author user ID.
	 *
	 * --title=<title>
	 * : Post title.
	 *
	 * --content=<content>
	 * : Post body (HTML allowed, will be sanitized via wp_kses_post).
	 *
	 * [--status=<status>]
	 * : Post status. Default: publish.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 *   - pending
	 * ---
	 *
	 * [--slug=<slug>]
	 * : Optional slug. Generated from the title if omitted.
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
	 *     wp jetonomy post create --space=5 --author=3 --title="Hello" --content="First post"
	 *     wp jetonomy post create --space=5 --author=3 --title="Draft" --content="..." --status=draft
	 */
	public function create( $args, $assoc ): void {
		$result = ( new Content_Journey() )->create_post(
			[
				'space_id'  => (int) ( $assoc['space'] ?? 0 ),
				'author_id' => (int) ( $assoc['author'] ?? 0 ),
				'title'     => (string) ( $assoc['title'] ?? '' ),
				'content'   => (string) ( $assoc['content'] ?? '' ),
				'status'    => (string) ( $assoc['status'] ?? 'publish' ),
				'slug'      => (string) ( $assoc['slug'] ?? '' ),
			]
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Update mutable fields on a post.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * [--title=<title>]
	 * : New title.
	 *
	 * [--content=<content>]
	 * : New body.
	 *
	 * [--status=<status>]
	 * : New status.
	 *
	 * [--slug=<slug>]
	 * : New slug.
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
	 *     wp jetonomy post update 42 --title="New title"
	 *     wp jetonomy post update 42 --status=draft
	 */
	public function update( $args, $assoc ): void {
		$id      = (int) ( $args[0] ?? 0 );
		$changes = array_intersect_key( $assoc, array_flip( [ 'title', 'content', 'status', 'slug' ] ) );
		$result  = ( new Content_Journey() )->update_post( $id, $changes );
		$this->render( $result, $assoc );
	}

	/**
	 * Delete a post by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
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
	 *     wp jetonomy post delete 42
	 */
	public function delete( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Content_Journey() )->delete_post( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Fetch a post by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
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
	 *     wp jetonomy post get 42
	 *     wp jetonomy post get 42 --format=json
	 */
	public function get( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Content_Journey() )->get_post( $id );
		$this->render( $result, $assoc );
	}
}
