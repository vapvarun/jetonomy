<?php
/**
 * wp jetonomy mod — flag review and user restriction workflow.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Moderation_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Moderator workflow commands from the terminal.
 *
 * All subcommands delegate to {@see Moderation_Journey} for the actual work
 * so PHPUnit tests and CLI invocations run the same code path. Each
 * subcommand is a thin adapter: read args, call the journey, render.
 *
 * Flag name convention: descriptive role-named flags like `--target`,
 * `--issuer`, `--resolver`. `--user` is avoided because WP-CLI reserves it
 * as a global and strips it before the command is dispatched.
 */
final class Mod_Command extends Base_Command {

	/**
	 * List flags by status (defaults to pending).
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Flag status to filter by. Default: pending.
	 * ---
	 * default: pending
	 * options:
	 *   - pending
	 *   - valid
	 *   - dismissed
	 * ---
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
	 *     wp jetonomy mod flags
	 *     wp jetonomy mod flags --status=valid
	 *     wp jetonomy mod flags --status=dismissed --format=json
	 */
	public function flags( $args, $assoc ): void {
		$status = (string) ( $assoc['status'] ?? 'pending' );
		$result = ( new Moderation_Journey() )->list_flags_by_status( $status );
		$this->render_list( $result, $assoc );
	}

	/**
	 * Resolve a pending flag as valid or dismissed.
	 *
	 * ## OPTIONS
	 *
	 * <flag_id>
	 * : Flag row ID to resolve.
	 *
	 * --resolver=<user_id>
	 * : User ID of the moderator recording the decision.
	 *
	 * --decision=<decision>
	 * : Resolution decision.
	 * ---
	 * options:
	 *   - valid
	 *   - dismissed
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
	 *     wp jetonomy mod resolve 42 --resolver=1 --decision=valid
	 *     wp jetonomy mod resolve 17 --resolver=1 --decision=dismissed
	 */
	public function resolve( $args, $assoc ): void {
		$flag_id  = (int) ( $args[0] ?? 0 );
		$resolver = (int) ( $assoc['resolver'] ?? 0 );
		$decision = (string) ( $assoc['decision'] ?? '' );
		$result   = ( new Moderation_Journey() )->resolve_flag( $flag_id, $resolver, $decision );
		$this->render( $result, $assoc );
	}

	/**
	 * Issue a ban, silence, or IP restriction against a user.
	 *
	 * ## OPTIONS
	 *
	 * --target=<user_id>
	 * : Target user ID to restrict.
	 *
	 * --issuer=<user_id>
	 * : Moderator/admin user ID issuing the restriction.
	 *
	 * [--type=<type>]
	 * : Restriction type. Default: global_ban.
	 * ---
	 * default: global_ban
	 * options:
	 *   - global_ban
	 *   - space_ban
	 *   - silence
	 *   - ip_ban
	 * ---
	 *
	 * [--space=<id>]
	 * : Space ID (required when type=space_ban).
	 *
	 * [--reason=<text>]
	 * : Optional human-readable reason.
	 *
	 * [--expires=<datetime>]
	 * : Optional MySQL datetime when the restriction expires. Omit for permanent.
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
	 *     wp jetonomy mod ban --target=5 --issuer=1 --reason="spam"
	 *     wp jetonomy mod ban --target=5 --issuer=1 --type=space_ban --space=3
	 *     wp jetonomy mod ban --target=5 --issuer=1 --type=silence --expires="2026-05-01 00:00:00"
	 */
	public function ban( $args, $assoc ): void {
		$target   = (int) ( $assoc['target'] ?? 0 );
		$issuer   = (int) ( $assoc['issuer'] ?? 0 );
		$type     = (string) ( $assoc['type'] ?? 'global_ban' );
		$space_id = isset( $assoc['space'] ) ? (int) $assoc['space'] : null;
		$reason   = isset( $assoc['reason'] ) ? (string) $assoc['reason'] : null;
		$expires  = isset( $assoc['expires'] ) ? (string) $assoc['expires'] : null;
		$result   = ( new Moderation_Journey() )->ban_user( $target, $issuer, $type, $space_id, $reason, $expires );
		$this->render( $result, $assoc );
	}

	/**
	 * Lift a restriction by its row ID.
	 *
	 * ## OPTIONS
	 *
	 * <restriction_id>
	 * : Restriction row ID.
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
	 *     wp jetonomy mod unban 12
	 */
	public function unban( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Moderation_Journey() )->unban( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Check whether a user is currently banned (global or per-space).
	 *
	 * ## OPTIONS
	 *
	 * --target=<user_id>
	 * : Target user ID to check.
	 *
	 * [--space=<id>]
	 * : Space ID for a scoped check. Omit for a global ban check.
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
	 *     wp jetonomy mod is-banned --target=5
	 *     wp jetonomy mod is-banned --target=5 --space=3
	 */
	public function is_banned( $args, $assoc ): void {
		$target   = (int) ( $assoc['target'] ?? 0 );
		$space_id = isset( $assoc['space'] ) ? (int) $assoc['space'] : null;
		$result   = ( new Moderation_Journey() )->is_banned( $target, $space_id );
		$this->render( $result, $assoc );
	}
}
