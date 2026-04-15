<?php
/**
 * wp jetonomy flag — file content flags.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Content_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * File a flag against a post or reply.
 *
 * Resolution (approve/dismiss) is handled by the moderation journey in C4,
 * not here — this command only files new reports. Keeping the two surfaces
 * separate mirrors the real permission split: any logged-in user can file,
 * only moderators can resolve.
 */
final class Flag_Command extends Base_Command {

	/**
	 * File a new flag.
	 *
	 * ## OPTIONS
	 *
	 * --type=<type>
	 * : Target object type.
	 * ---
	 * options:
	 *   - post
	 *   - reply
	 *   - user
	 * ---
	 *
	 * --id=<id>
	 * : Target post, reply, or user ID.
	 *
	 * --reporter=<id>
	 * : Reporting user ID.
	 *
	 * --reason=<reason>
	 * : Reason enum value.
	 * ---
	 * options:
	 *   - spam
	 *   - offensive
	 *   - off_topic
	 *   - harassment
	 *   - other
	 * ---
	 *
	 * [--description=<description>]
	 * : Optional free-text description from the reporter.
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
	 *     wp jetonomy flag create --type=post --id=42 --reporter=3 --reason=spam
	 *     wp jetonomy flag create --type=reply --id=17 --reporter=3 --reason=harassment --description="..."
	 */
	public function create( $args, $assoc ): void {
		$result = ( new Content_Journey() )->flag(
			[
				'object_type' => (string) ( $assoc['type'] ?? '' ),
				'object_id'   => (int) ( $assoc['id'] ?? 0 ),
				'reporter_id' => (int) ( $assoc['reporter'] ?? 0 ),
				'reason'      => (string) ( $assoc['reason'] ?? '' ),
				'description' => (string) ( $assoc['description'] ?? '' ),
			]
		);
		$this->render( $result, $assoc );
	}
}
