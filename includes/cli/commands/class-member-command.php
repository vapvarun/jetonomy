<?php
/**
 * wp jetonomy member — space membership and join requests via Member_Journey.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Member_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Jetonomy space memberships from the terminal.
 *
 * All subcommands delegate to {@see Member_Journey} for the actual work so
 * PHPUnit tests can assert on the same code path the CLI runs. The `--by`
 * flag (not `--user`) is used for the acting user because `--user` is a
 * reserved WP-CLI global that gets stripped before reaching commands.
 */
final class Member_Command extends Base_Command {

	/**
	 * Add a user to a space with an optional role.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Space ID the user joins.
	 *
	 * --by=<user_id>
	 * : User ID being added.
	 *
	 * [--role=<role>]
	 * : Role to assign. Default: member.
	 * ---
	 * default: member
	 * options:
	 *   - member
	 *   - moderator
	 *   - admin
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
	 *     wp jetonomy member join --space=15 --by=4
	 *     wp jetonomy member join --space=15 --by=4 --role=moderator
	 */
	public function join( $args, $assoc ): void {
		$result = ( new Member_Journey() )->join(
			(int) ( $assoc['space'] ?? 0 ),
			(int) ( $assoc['by'] ?? 0 ),
			(string) ( $assoc['role'] ?? 'member' )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Remove a user from a space.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Space ID.
	 *
	 * --by=<user_id>
	 * : User ID being removed.
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
	 *     wp jetonomy member leave --space=15 --by=4
	 */
	public function leave( $args, $assoc ): void {
		$result = ( new Member_Journey() )->leave(
			(int) ( $assoc['space'] ?? 0 ),
			(int) ( $assoc['by'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Upsert a member's role in a space.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Space ID.
	 *
	 * --by=<user_id>
	 * : User ID whose role changes.
	 *
	 * --role=<role>
	 * : New role.
	 * ---
	 * options:
	 *   - member
	 *   - moderator
	 *   - admin
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
	 *     wp jetonomy member set-role --space=15 --by=4 --role=moderator
	 *
	 * @subcommand set-role
	 */
	public function set_role( $args, $assoc ): void {
		$result = ( new Member_Journey() )->set_role(
			(int) ( $assoc['space'] ?? 0 ),
			(int) ( $assoc['by'] ?? 0 ),
			(string) ( $assoc['role'] ?? '' )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Check whether a user is a member of a space.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Space ID.
	 *
	 * --by=<user_id>
	 * : User ID to check.
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
	 *     wp jetonomy member is-member --space=15 --by=4
	 *
	 * @subcommand is-member
	 */
	public function is_member( $args, $assoc ): void {
		$result = ( new Member_Journey() )->is_member(
			(int) ( $assoc['space'] ?? 0 ),
			(int) ( $assoc['by'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Get the user's current role in a space.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Space ID.
	 *
	 * --by=<user_id>
	 * : User ID to look up.
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
	 *     wp jetonomy member get-role --space=15 --by=4
	 *
	 * @subcommand get-role
	 */
	public function get_role( $args, $assoc ): void {
		$result = ( new Member_Journey() )->get_role(
			(int) ( $assoc['space'] ?? 0 ),
			(int) ( $assoc['by'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * List every member of a space.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Space ID.
	 *
	 * [--fields=<fields>]
	 * : Limit output to these columns.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy member list-members --space=15
	 *     wp jetonomy member list-members --space=15 --format=json
	 *
	 * @subcommand list-members
	 */
	public function list_members( $args, $assoc ): void {
		$result = ( new Member_Journey() )->list_space_members(
			(int) ( $assoc['space'] ?? 0 )
		);
		$this->render_list( $result, $assoc );
	}

	/**
	 * List every space a user has joined.
	 *
	 * ## OPTIONS
	 *
	 * --by=<user_id>
	 * : User ID to query.
	 *
	 * [--fields=<fields>]
	 * : Limit output to these columns.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy member list-user-spaces --by=4
	 *
	 * @subcommand list-user-spaces
	 */
	public function list_user_spaces( $args, $assoc ): void {
		$result = ( new Member_Journey() )->list_user_spaces(
			(int) ( $assoc['by'] ?? 0 )
		);
		$this->render_list( $result, $assoc );
	}

	/**
	 * Submit a join request for an approval-gated space.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Target space ID.
	 *
	 * --by=<user_id>
	 * : Requesting user ID.
	 *
	 * [--message=<text>]
	 * : Optional message to include with the request.
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
	 *     wp jetonomy member request-join --space=20 --by=4 --message="Please let me in"
	 *
	 * @subcommand request-join
	 */
	public function request_join( $args, $assoc ): void {
		$result = ( new Member_Journey() )->submit_join_request(
			(int) ( $assoc['space'] ?? 0 ),
			(int) ( $assoc['by'] ?? 0 ),
			(string) ( $assoc['message'] ?? '' )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Approve a pending join request.
	 *
	 * ## OPTIONS
	 *
	 * <request_id>
	 * : Join request row ID.
	 *
	 * --reviewer=<user_id>
	 * : Reviewing user ID (moderator or admin).
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
	 *     wp jetonomy member approve-request 12 --reviewer=1
	 *
	 * @subcommand approve-request
	 */
	public function approve_request( $args, $assoc ): void {
		$request_id = (int) ( $args[0] ?? 0 );
		$result     = ( new Member_Journey() )->approve_join_request(
			$request_id,
			(int) ( $assoc['reviewer'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Deny a pending join request.
	 *
	 * ## OPTIONS
	 *
	 * <request_id>
	 * : Join request row ID.
	 *
	 * --reviewer=<user_id>
	 * : Reviewing user ID (moderator or admin).
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
	 *     wp jetonomy member deny-request 12 --reviewer=1
	 *
	 * @subcommand deny-request
	 */
	public function deny_request( $args, $assoc ): void {
		$request_id = (int) ( $args[0] ?? 0 );
		$result     = ( new Member_Journey() )->deny_join_request(
			$request_id,
			(int) ( $assoc['reviewer'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * List all pending join requests for a space.
	 *
	 * ## OPTIONS
	 *
	 * --space=<id>
	 * : Space ID.
	 *
	 * [--fields=<fields>]
	 * : Limit output to these columns.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy member list-requests --space=20
	 *
	 * @subcommand list-requests
	 */
	public function list_requests( $args, $assoc ): void {
		$result = ( new Member_Journey() )->list_pending_requests(
			(int) ( $assoc['space'] ?? 0 )
		);
		$this->render_list( $result, $assoc );
	}
}
