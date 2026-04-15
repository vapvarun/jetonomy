<?php
/**
 * wp jetonomy space — space CRUD and configuration via Space_Journey.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Space_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Jetonomy spaces from the terminal.
 *
 * All subcommands delegate to {@see Space_Journey} for the actual work so
 * PHPUnit tests can assert on the same code path the CLI runs.
 */
final class Space_Command extends Base_Command {

	/**
	 * Create a new space in a category.
	 *
	 * ## OPTIONS
	 *
	 * --title=<title>
	 * : Space title.
	 *
	 * --slug=<slug>
	 * : Space slug (must be unique).
	 *
	 * --category=<id>
	 * : Parent category ID.
	 *
	 * [--description=<text>]
	 * : Optional description.
	 *
	 * [--type=<type>]
	 * : Space type. Default: forum.
	 * ---
	 * default: forum
	 * options:
	 *   - forum
	 *   - qa
	 *   - ideas
	 *   - chat
	 * ---
	 *
	 * [--visibility=<vis>]
	 * : Visibility. Default: public.
	 * ---
	 * default: public
	 * options:
	 *   - public
	 *   - private
	 *   - hidden
	 * ---
	 *
	 * [--join-policy=<policy>]
	 * : Join policy. Default: open.
	 * ---
	 * default: open
	 * options:
	 *   - open
	 *   - approval
	 *   - invite
	 * ---
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
	 *     wp jetonomy space create --title="General" --slug=general --category=1
	 *     wp jetonomy space create --title="Q&A" --slug=qa --category=1 --type=qa --visibility=private --join-policy=approval
	 */
	public function create( $args, $assoc ): void {
		$result = ( new Space_Journey() )->create(
			[
				'title'       => (string) ( $assoc['title'] ?? '' ),
				'slug'        => (string) ( $assoc['slug'] ?? '' ),
				'category_id' => (int) ( $assoc['category'] ?? 0 ),
				'description' => (string) ( $assoc['description'] ?? '' ),
				'type'        => (string) ( $assoc['type'] ?? 'forum' ),
				'visibility'  => (string) ( $assoc['visibility'] ?? 'public' ),
				'join_policy' => (string) ( $assoc['join-policy'] ?? 'open' ),
			]
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Update mutable fields on a space.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Space ID.
	 *
	 * [--title=<title>]
	 * : New title.
	 *
	 * [--description=<text>]
	 * : New description.
	 *
	 * [--type=<type>]
	 * : New type.
	 * ---
	 * options:
	 *   - forum
	 *   - qa
	 *   - ideas
	 *   - chat
	 * ---
	 *
	 * [--visibility=<vis>]
	 * : New visibility.
	 * ---
	 * options:
	 *   - public
	 *   - private
	 *   - hidden
	 * ---
	 *
	 * [--join-policy=<policy>]
	 * : New join policy.
	 * ---
	 * options:
	 *   - open
	 *   - approval
	 *   - invite
	 * ---
	 *
	 * [--status=<status>]
	 * : New status (e.g. active, archived).
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
	 *     wp jetonomy space update 5 --title="General Discussion"
	 *     wp jetonomy space update 5 --visibility=private
	 */
	public function update( $args, $assoc ): void {
		$id      = (int) ( $args[0] ?? 0 );
		$changes = [];
		foreach ( [ 'title', 'description', 'type', 'visibility', 'status' ] as $key ) {
			if ( isset( $assoc[ $key ] ) ) {
				$changes[ $key ] = (string) $assoc[ $key ];
			}
		}
		if ( isset( $assoc['join-policy'] ) ) {
			$changes['join_policy'] = (string) $assoc['join-policy'];
		}
		$result = ( new Space_Journey() )->update( $id, $changes );
		$this->render( $result, $assoc );
	}

	/**
	 * Delete a space by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Space ID.
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
	 *     wp jetonomy space delete 5
	 */
	public function delete( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Space_Journey() )->delete( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Fetch a space by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Space ID.
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
	 *     wp jetonomy space get 5
	 *     wp jetonomy space get 5 --format=json
	 */
	public function get( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Space_Journey() )->get( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Change the join policy for a space.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Space ID.
	 *
	 * --policy=<policy>
	 * : New join policy.
	 * ---
	 * options:
	 *   - open
	 *   - approval
	 *   - invite
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
	 *     wp jetonomy space set-join-policy 5 --policy=approval
	 *
	 * @subcommand set-join-policy
	 */
	public function set_join_policy( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$policy = (string) ( $assoc['policy'] ?? '' );
		$result = ( new Space_Journey() )->set_join_policy( $id, $policy );
		$this->render( $result, $assoc );
	}

	/**
	 * Change the visibility for a space.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Space ID.
	 *
	 * --visibility=<vis>
	 * : New visibility.
	 * ---
	 * options:
	 *   - public
	 *   - private
	 *   - hidden
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
	 *     wp jetonomy space set-visibility 5 --visibility=private
	 *
	 * @subcommand set-visibility
	 */
	public function set_visibility( $args, $assoc ): void {
		$id         = (int) ( $args[0] ?? 0 );
		$visibility = (string) ( $assoc['visibility'] ?? '' );
		$result     = ( new Space_Journey() )->set_visibility( $id, $visibility );
		$this->render( $result, $assoc );
	}

	/**
	 * List spaces in a category.
	 *
	 * ## OPTIONS
	 *
	 * --category=<id>
	 * : Category ID to list spaces for.
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
	 *     wp jetonomy space list --category=1
	 *     wp jetonomy space list --category=1 --format=json
	 */
	public function list( $args, $assoc ): void {
		$category_id = (int) ( $assoc['category'] ?? 0 );
		$result      = ( new Space_Journey() )->list_by_category( $category_id );
		$this->render_list( $result, $assoc );
	}
}
