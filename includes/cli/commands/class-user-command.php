<?php
/**
 * wp jetonomy user — user provisioning, trust, profile, reputation, bans.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\User_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Jetonomy users from the terminal.
 *
 * Every subcommand delegates to {@see User_Journey} for the actual work so
 * PHPUnit tests can assert on the same code path the CLI runs through.
 */
final class User_Command extends Base_Command {

	/**
	 * Create a WP user with an initial Jetonomy trust level.
	 *
	 * ## OPTIONS
	 *
	 * --login=<login>
	 * : WordPress user_login.
	 *
	 * --email=<email>
	 * : WordPress user_email.
	 *
	 * [--password=<pw>]
	 * : Password. Auto-generated (20 chars) if omitted.
	 *
	 * [--role=<role>]
	 * : WP role to assign. Default: subscriber.
	 * ---
	 * default: subscriber
	 * ---
	 *
	 * [--trust-level=<0-5>]
	 * : Initial Jetonomy trust level. Default: 0.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--display-name=<name>]
	 * : Optional display name; defaults to the login.
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
	 *     wp jetonomy user create --login=alice --email=alice@example.com
	 *     wp jetonomy user create --login=mod1 --email=m1@ex.com --trust-level=4 --role=editor
	 */
	public function create( $args, $assoc ): void {
		$result = ( new User_Journey() )->create_with_trust_level(
			[
				'login'        => (string) ( $assoc['login'] ?? '' ),
				'email'        => (string) ( $assoc['email'] ?? '' ),
				'password'     => (string) ( $assoc['password'] ?? '' ),
				'role'         => (string) ( $assoc['role'] ?? 'subscriber' ),
				'trust_level'  => (int) ( $assoc['trust-level'] ?? 0 ),
				'display_name' => (string) ( $assoc['display-name'] ?? '' ),
			]
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Manually set a user's trust level.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : Target user ID.
	 *
	 * --level=<0-5>
	 * : Trust level (0–5).
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
	 *     wp jetonomy user set-trust 42 --level=3
	 *
	 * @subcommand set-trust
	 */
	public function set_trust( $args, $assoc ): void {
		$result = ( new User_Journey() )->set_trust_level(
			(int) ( $args[0] ?? 0 ),
			(int) ( $assoc['level'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Get the user's current trust level + requirements for the next tier.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : Target user ID.
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
	 *     wp jetonomy user get-trust 42
	 *
	 * @subcommand get-trust
	 */
	public function get_trust( $args, $assoc ): void {
		$result = ( new User_Journey() )->get_trust_level( (int) ( $args[0] ?? 0 ) );
		$this->render( $result, $assoc );
	}

	/**
	 * Patch whitelisted profile fields on a user.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : Target user ID.
	 *
	 * [--display-name=<name>]
	 * : New display name.
	 *
	 * [--bio=<bio>]
	 * : New bio.
	 *
	 * [--avatar-url=<url>]
	 * : New avatar URL.
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
	 *     wp jetonomy user update-profile 42 --bio="Hello world"
	 *     wp jetonomy user update-profile 42 --display-name="Alice" --avatar-url="https://example.com/a.png"
	 *
	 * @subcommand update-profile
	 */
	public function update_profile( $args, $assoc ): void {
		$changes = [];
		if ( isset( $assoc['display-name'] ) ) {
			$changes['display_name'] = (string) $assoc['display-name'];
		}
		if ( isset( $assoc['bio'] ) ) {
			$changes['bio'] = (string) $assoc['bio'];
		}
		if ( isset( $assoc['avatar-url'] ) ) {
			$changes['avatar_url'] = (string) $assoc['avatar-url'];
		}

		$result = ( new User_Journey() )->update_profile( (int) ( $args[0] ?? 0 ), $changes );
		$this->render( $result, $assoc );
	}

	/**
	 * Add or subtract reputation on a user.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : Target user ID.
	 *
	 * --delta=<int>
	 * : Reputation delta (use negative to subtract).
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
	 *     wp jetonomy user adjust-reputation 42 --delta=25
	 *     wp jetonomy user adjust-reputation 42 --delta=-10
	 *
	 * @subcommand adjust-reputation
	 */
	public function adjust_reputation( $args, $assoc ): void {
		$result = ( new User_Journey() )->adjust_reputation(
			(int) ( $args[0] ?? 0 ),
			(int) ( $assoc['delta'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Fetch the full profile row for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : Target user ID.
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
	 *     wp jetonomy user get-profile 42
	 *
	 * @subcommand get-profile
	 */
	public function get_profile( $args, $assoc ): void {
		$result = ( new User_Journey() )->get_profile( (int) ( $args[0] ?? 0 ) );
		$this->render( $result, $assoc );
	}

	/**
	 * List currently-online users (active within the last 5 minutes).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max rows to return. Default: 20.
	 * ---
	 * default: 20
	 * ---
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
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to display.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy user list-online
	 *     wp jetonomy user list-online --limit=50
	 *
	 * @subcommand list-online
	 */
	public function list_online( $args, $assoc ): void {
		$result = ( new User_Journey() )->list_online_users( (int) ( $assoc['limit'] ?? 20 ) );
		$this->render_list( $result, $assoc );
	}

	/**
	 * Ban a user globally. Delegates to Moderation_Journey::ban_user().
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : Target user ID.
	 *
	 * --issuer=<id>
	 * : Issuing moderator/admin user ID.
	 *
	 * [--reason=<text>]
	 * : Optional human-readable reason.
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
	 *     wp jetonomy user ban 42 --issuer=1 --reason="spam"
	 */
	public function ban( $args, $assoc ): void {
		$result = ( new User_Journey() )->ban_user(
			(int) ( $args[0] ?? 0 ),
			(int) ( $assoc['issuer'] ?? 0 ),
			isset( $assoc['reason'] ) ? (string) $assoc['reason'] : null
		);
		$this->render( $result, $assoc );
	}
}
