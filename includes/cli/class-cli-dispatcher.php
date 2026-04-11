<?php
/**
 * Central registrar for the journey-based CLI module.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every journey-backed `wp jetonomy <topic>` subcommand with WP-CLI.
 *
 * Called once from jetonomy.php after the existing `wp jetonomy` root command
 * is registered. Each command class wraps one or more journey methods and is
 * responsible for formatting {@see Journey_Result} into terminal output.
 * Command classes must not contain business logic — they are thin formatters
 * only. All behavior lives in journey classes and is fully unit-testable.
 *
 * Adding a new command: append its slug → class-string mapping to {@see COMMANDS}
 * and make sure the class is reachable through the autoloader.
 */
final class CLI_Dispatcher {

	/**
	 * Map of `wp jetonomy <slug>` → fully qualified command class.
	 *
	 * Populated incrementally per journey commit. Entries with missing classes
	 * are silently skipped so the dispatcher never breaks site load mid-rollout.
	 *
	 * @var array<string,class-string>
	 */
	private const COMMANDS = [
		'post'         => Commands\Post_Command::class,
		'reply'        => Commands\Reply_Command::class,
		'vote'         => Commands\Vote_Command::class,
		'flag'         => Commands\Flag_Command::class,
		'space'        => Commands\Space_Command::class,
		'member'       => Commands\Member_Command::class,
		'mod'          => Commands\Mod_Command::class,
		'notification' => Commands\Notification_Command::class,
		'config'       => Commands\Config_Command::class,
		'category'     => Commands\Category_Command::class,
		'tag'          => Commands\Tag_Command::class,
	];

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		foreach ( self::COMMANDS as $slug => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			\WP_CLI::add_command( 'jetonomy ' . $slug, $class );
		}
	}
}
