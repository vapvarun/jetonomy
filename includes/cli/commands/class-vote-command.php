<?php
/**
 * wp jetonomy vote — cast votes on posts and replies.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Content_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Cast or remove votes on posts and replies.
 *
 * Wraps {@see Content_Journey::vote()} which delegates to Vote::cast(). The
 * three-state behavior (insert, undo, switch) is handled entirely in the
 * model — calling `cast` twice with the same value undoes the vote.
 */
final class Vote_Command extends Base_Command {

	/**
	 * Cast (or undo, or switch) a vote.
	 *
	 * The `--voter` flag (not `--user`) is used because `--user` is a
	 * reserved WP-CLI global that gets stripped before reaching commands.
	 *
	 * ## OPTIONS
	 *
	 * --voter=<id>
	 * : Voting user ID.
	 *
	 * --type=<type>
	 * : Target object type.
	 * ---
	 * options:
	 *   - post
	 *   - reply
	 * ---
	 *
	 * --id=<id>
	 * : Target post or reply ID.
	 *
	 * --value=<value>
	 * : Vote direction. +1 for upvote, -1 for downvote.
	 * ---
	 * options:
	 *   - 1
	 *   - -1
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
	 *     wp jetonomy vote cast --voter=3 --type=post --id=42 --value=1
	 *     wp jetonomy vote cast --voter=3 --type=reply --id=17 --value=-1
	 */
	public function cast( $args, $assoc ): void {
		$result = ( new Content_Journey() )->vote(
			(int) ( $assoc['voter'] ?? 0 ),
			(string) ( $assoc['type'] ?? '' ),
			(int) ( $assoc['id'] ?? 0 ),
			(int) ( $assoc['value'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}
}
