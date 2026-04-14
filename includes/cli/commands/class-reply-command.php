<?php
/**
 * wp jetonomy reply — reply CRUD via Content_Journey.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Content_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Jetonomy replies from the terminal.
 */
final class Reply_Command extends Base_Command {

	/**
	 * Create a new reply.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Parent post ID.
	 *
	 * --author=<id>
	 * : Author user ID.
	 *
	 * --content=<content>
	 * : Reply body (HTML allowed, sanitized via wp_kses_post).
	 *
	 * [--parent=<id>]
	 * : Parent reply ID for threaded replies.
	 *
	 * [--status=<status>]
	 * : Reply status.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - pending
	 * ---
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
	 *     wp jetonomy reply create --post=42 --author=3 --content="Great idea"
	 *     wp jetonomy reply create --post=42 --author=3 --content="Nested" --parent=17
	 */
	public function create( $args, $assoc ): void {
		$input = [
			'post_id'   => (int) ( $assoc['post'] ?? 0 ),
			'author_id' => (int) ( $assoc['author'] ?? 0 ),
			'content'   => (string) ( $assoc['content'] ?? '' ),
			'status'    => (string) ( $assoc['status'] ?? 'publish' ),
		];
		if ( ! empty( $assoc['parent'] ) ) {
			$input['parent_id'] = (int) $assoc['parent'];
		}
		$result = ( new Content_Journey() )->create_reply( $input );
		$this->render( $result, $assoc );
	}

	/**
	 * Delete a reply by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Reply ID.
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
	 *     wp jetonomy reply delete 17
	 */
	public function delete( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Content_Journey() )->delete_reply( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Mark a reply as the accepted answer for its parent post.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Parent post ID.
	 *
	 * --reply=<id>
	 * : Reply ID to mark as accepted.
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
	 *     wp jetonomy reply accept --post=42 --reply=17
	 */
	public function accept( $args, $assoc ): void {
		$post_id  = (int) ( $assoc['post'] ?? 0 );
		$reply_id = (int) ( $assoc['reply'] ?? 0 );
		$result   = ( new Content_Journey() )->accept_reply( $post_id, $reply_id );
		$this->render( $result, $assoc );
	}
}
