<?php
/**
 * The one writer for the jetonomy_settings option.
 *
 * The admin form, POST /jetonomy/v1/admin/settings and `wp jetonomy config set`
 * all clean values through sanitize(), so a value the form refuses is refused
 * everywhere. The role capability mapping is not here: it is role
 * administration, lives in its own option and stays on the admin form.
 *
 * @package Jetonomy
 * @since   2.1.0
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Settings schema, sanitizer and writer.
 *
 * @since 2.1.0
 */
class Settings {

	public const OPTION = 'jetonomy_settings';

	/** Most keys one REST or CLI write may carry. */
	public const MAX_KEYS = 50;

	/** What a secret reads as over REST. Sending it back keeps the stored secret. */
	public const MASK = '********';

	/** How long a write waits for another one to finish before answering 503. */
	private const LOCK_WAIT_MS = 5000;

	/**
	 * Notices the last sanitize() raised (a refused address, for example).
	 *
	 * @var array<int, array{code: string, message: string}>
	 */
	private static array $notices = array();

	/**
	 * Who is writing: 'rest' or 'cli' while write() runs, '' for the admin form.
	 *
	 * @var string
	 */
	private static string $source = '';

	/**
	 * While write() runs: the touched sections as they were, cleaned. announce()
	 * compares against this instead of the raw stored option.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $baseline = null;

	/**
	 * The sanitizer runs a section only when that section's marker key is in
	 * the input, the way each settings tab posts its own fields. write() fills
	 * a touched section from the stored values so a one-key change never resets
	 * its neighbours.
	 *
	 * @return array<string, string> Section => marker key.
	 */
	private static function sections(): array {
		return array(
			'general'     => 'base_slug',
			'trust'       => 'trust_thresholds',
			'member_name' => 'member_name_display',
			'rate_limits' => 'rate_limits',
			'reputation'  => 'reputation_points',
			'email'       => 'email_from_name',
			'appearance'  => 'accent_color',
			'antispam'    => 'captcha_provider',
			'seo'         => 'seo_post_title',
		);
	}

	/**
	 * Every key a REST or CLI write may set, with its JSON schema and section.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		$s = static fn( string $section, string $type, array $extra = array() ): array => array_merge(
			array(
				'type'    => $type,
				'section' => $section,
			),
			$extra
		);

		$schema = array(
			'base_slug'                     => $s( 'general', 'string' ),
			'community_title'               => $s( 'general', 'string' ),
			'terms_url'                     => $s( 'general', 'string' ),
			'privacy_url'                   => $s( 'general', 'string' ),
			'support_url'                   => $s( 'general', 'string' ),
			'posts_per_page'                => $s( 'general', 'integer' ),
			'activity_log_retention_days'   => $s( 'general', 'integer' ),
			'replies_per_page'              => $s( 'general', 'integer' ),
			'default_space_type'            => $s( 'general', 'string', array( 'enum' => \Jetonomy\Models\Space::valid_types() ) ),
			'guest_read'                    => $s( 'general', 'boolean' ),
			'front_page'                    => $s( 'general', 'boolean' ),
			'guest_private_teasers'         => $s( 'general', 'boolean' ),
			'frontend_space_creation_roles' => $s( 'general', 'array', array( 'items' => array( 'type' => 'string' ) ) ),
			'require_email_verification'    => $s( 'general', 'boolean' ),
			'allow_space_admin_purge'       => $s( 'general', 'boolean' ),
			'remove_data_on_uninstall'      => $s( 'general', 'boolean' ),
			'trust_thresholds'              => $s( 'trust', 'object' ),
			'trust_level_names'             => $s( 'trust', 'array', array( 'items' => array( 'type' => 'string' ) ) ),
			'member_name_display'           => $s( 'member_name', 'string', array( 'enum' => array( 'display_name', 'handle', 'both' ) ) ),
			'rate_limits'                   => $s( 'rate_limits', 'object' ),
			'reputation_points'             => $s( 'reputation', 'object' ),
			'email_from_name'               => $s( 'email', 'string' ),
			'email_from_email'              => $s( 'email', 'string' ),
			'email_logo_url'                => $s( 'email', 'string' ),
			'email_footer_text'             => $s( 'email', 'string' ),
			'verification_reminder_hours'   => $s( 'email', 'integer' ),
			'notification_defaults'         => $s( 'email', 'object' ),
			'accent_color'                  => $s( 'appearance', 'string', array( 'pattern' => '^#([0-9a-fA-F]{3}){1,2}$' ) ),
			'logo_url'                      => $s( 'appearance', 'string' ),
			'text_color'                    => $s( 'appearance', 'string', array( 'pattern' => '^(#([0-9a-fA-F]{3}){1,2})?$' ) ),
			'bg_color'                      => $s( 'appearance', 'string', array( 'pattern' => '^(#([0-9a-fA-F]{3}){1,2})?$' ) ),
			'bg_subtle_color'               => $s( 'appearance', 'string', array( 'pattern' => '^(#([0-9a-fA-F]{3}){1,2})?$' ) ),
			'border_color'                  => $s( 'appearance', 'string', array( 'pattern' => '^(#([0-9a-fA-F]{3}){1,2})?$' ) ),
			'layout_density'                => $s( 'appearance', 'string' ),
			'custom_css'                    => $s( 'appearance', 'string' ),
			'container_width'               => $s( 'appearance', 'string', array( 'enum' => array( 'theme', 'full', 'custom' ) ) ),
			'container_width_custom'        => $s( 'appearance', 'integer' ),
			'padding_preset'                => $s( 'appearance', 'string', array( 'enum' => array( 'theme', 'none', 'comfortable' ) ) ),
			'captcha_provider'              => $s( 'antispam', 'string', array( 'enum' => array( 'none', 'recaptcha_v3', 'turnstile' ) ) ),
			'captcha_site_key'              => $s( 'antispam', 'string' ),
			'captcha_secret_key'            => $s( 'antispam', 'string', array( 'secret' => true ) ),
			'captcha_score_threshold'       => $s( 'antispam', 'number' ),
			'seo_post_title'                => $s( 'seo', 'string' ),
			'seo_space_title'               => $s( 'seo', 'string' ),
			'seo_schema'                    => $s( 'seo', 'boolean' ),
			'seo_sitemap'                   => $s( 'seo', 'boolean' ),
			'seo_noindex_profiles'          => $s( 'seo', 'boolean' ),
			'seo_noindex_search'            => $s( 'seo', 'boolean' ),
			'seo_twitter_handle'            => $s( 'seo', 'string' ),
			'seo_default_og_image'          => $s( 'seo', 'string' ),
			'fb_app_id'                     => $s( 'seo', 'string' ),
			'fb_app_secret'                 => $s( 'seo', 'string', array( 'secret' => true ) ),
		);
		foreach ( array_keys( label_defaults() ) as $noun ) {
			$schema[ $noun . '_label_singular' ] = $s( 'general', 'string' );
			$schema[ $noun . '_label_plural' ]   = $s( 'general', 'string' );
		}

		/**
		 * Filters the settings a REST or CLI write may set.
		 *
		 * Add a key here only together with the sanitizer branch that cleans it;
		 * a key with no branch is accepted and then silently not saved.
		 *
		 * @since 2.1.0
		 *
		 * @param array<string, array<string, mixed>> $schema Key => JSON schema plus `section` and optional `secret`.
		 */
		return (array) apply_filters( 'jetonomy_settings_schema', $schema );
	}

	/**
	 * The stored settings for the keys in the schema, secrets masked.
	 *
	 * @since 2.1.0
	 *
	 * @param string[]|null $keys Only these keys; null for every key.
	 * @return array<string, mixed>
	 */
	public static function read( ?array $keys = null ): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( self::schema() as $key => $def ) {
			if ( null !== $keys && ! in_array( $key, $keys, true ) ) {
				continue;
			}
			$value = $stored[ $key ] ?? null;
			if ( ! empty( $def['secret'] ) ) {
				$value = ( null === $value || '' === $value ) ? '' : self::MASK;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Validate, clean and save a partial change. The REST route and the CLI use this.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $patch  Key => new value.
	 * @param string               $source 'rest' or 'cli', passed to jetonomy_settings_updated.
	 * @return array{settings: array<string, mixed>, notices: array<int, array{code: string, message: string}>}|\WP_Error
	 */
	public static function write( array $patch, string $source ) {
		if ( ! $patch ) {
			return new \WP_Error( 'jetonomy_settings_empty', __( 'Send at least one setting.', 'jetonomy' ), array( 'status' => 400 ) );
		}
		if ( count( $patch ) > self::MAX_KEYS ) {
			/* translators: %d: the most settings one request may change. */
			return new \WP_Error( 'jetonomy_settings_too_many', sprintf( __( 'Change at most %d settings in one request.', 'jetonomy' ), self::MAX_KEYS ), array( 'status' => 400 ) );
		}

		$schema = self::schema();
		$sent   = array_keys( $patch );
		foreach ( $patch as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				return new \WP_Error(
					'jetonomy_unknown_setting',
					/* translators: %s: setting key. */
					sprintf( __( '%s is not a setting that can be changed here.', 'jetonomy' ), (string) $key ),
					array(
						'status' => 400,
						'key'    => (string) $key,
					)
				);
			}
			// A client that read the masked secret and sent it back is not changing it.
			if ( ! empty( $schema[ $key ]['secret'] ) && self::MASK === $value ) {
				unset( $patch[ $key ] );
				continue;
			}
			$valid = rest_validate_value_from_schema( $value, array_diff_key( $schema[ $key ], array_flip( array( 'section', 'secret' ) ) ), (string) $key );
			if ( is_wp_error( $valid ) ) {
				return new \WP_Error(
					'rest_invalid_param',
					$valid->get_error_message(),
					array(
						'status' => 400,
						'key'    => (string) $key,
					)
				);
			}
		}

		// One writer at a time: two requests that each read the option and save
		// their change would otherwise drop each other's keys.
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		$waited = 0;
		while ( ! \WP_Upgrader::create_lock( self::OPTION, 10 ) ) {
			if ( $waited >= self::LOCK_WAIT_MS ) {
				return new \WP_Error( 'jetonomy_settings_busy', __( 'Another settings change is being saved. Try again in a moment.', 'jetonomy' ), array( 'status' => 503 ) );
			}
			usleep( 100000 );
			$waited += 100;
		}
		try {
			return self::write_locked( $patch, $source, $schema, $sent );
		} finally {
			\WP_Upgrader::release_lock( self::OPTION );
		}
	}

	/**
	 * The read-modify-write of write(), run while holding the settings lock.
	 *
	 * @param array<string, mixed>                $patch  Validated key => new value.
	 * @param string                              $source 'rest' or 'cli'.
	 * @param array<string, array<string, mixed>> $schema Settings::schema().
	 * @param string[]                            $sent   Keys the caller sent.
	 * @return array{settings: array<string, mixed>, notices: array<int, array{code: string, message: string}>}
	 */
	private static function write_locked( array $patch, string $source, array $schema, array $sent ): array {
		// Read what the last writer saved, not this request's cached copy.
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$sections = self::sections();
		$touched  = array_unique( array_map( static fn( string $k ): string => (string) $schema[ $k ]['section'], array_keys( $patch ) ) );
		$current  = self::effective( $stored );
		$input    = array();
		foreach ( $touched as $section ) {
			foreach ( $schema as $key => $def ) {
				if ( $section === $def['section'] && array_key_exists( $key, $current ) ) {
					$input[ $key ] = $current[ $key ];
				}
			}
			$marker = $sections[ $section ] ?? '';
			if ( '' !== $marker && ! isset( $input[ $marker ] ) ) {
				$input[ $marker ] = self::marker_default( $marker );
			}
		}

		// What the touched sections hold today once cleaned, so the announcement
		// names only keys whose value really changed (a first write also stores
		// the section's defaults, which is not a change).
		self::$notices  = array();
		self::$baseline = self::sanitize( $input );
		self::$notices  = array();
		$clean          = self::sanitize( array_replace( $input, $patch ) );
		$notices        = self::$notices;

		self::$source = $source;
		update_option( self::OPTION, $clean );
		self::$source   = '';
		self::$baseline = null;

		return array(
			'settings' => self::read( $sent ),
			'notices'  => $notices,
		);
	}

	/**
	 * The stored settings plus the defaults the settings screen shows for keys
	 * never saved, so a partial write starts from what the owner sees. Only keys
	 * whose "absent" reading differs from the sanitizer's are listed: checkboxes
	 * that default on (absent input means off to the sanitizer).
	 *
	 * @param array<string, mixed> $stored Stored settings.
	 * @return array<string, mixed>
	 */
	private static function effective( array $stored ): array {
		foreach ( array( 'seo_schema', 'seo_sitemap', 'seo_noindex_profiles', 'seo_noindex_search' ) as $key ) {
			$stored[ $key ] = $stored[ $key ] ?? true;
		}
		$notif = is_array( $stored['notification_defaults'] ?? null ) ? $stored['notification_defaults'] : array();
		foreach ( array_keys( array_filter( \Jetonomy\Admin\Admin::email_types(), static fn( array $t ): bool => $t['toggle'] ) ) as $type ) {
			if ( ! is_array( $notif[ $type ] ?? null ) ) {
				// Same reading as the Notification Defaults table: web on, email per the notifier.
				$notif[ $type ] = array(
					'web'   => true,
					'email' => \Jetonomy\Notifications\Notifier::default_email( $type, $notif ),
				);
			}
		}
		$stored['notification_defaults'] = $notif;
		return $stored;
	}

	/**
	 * The value a section marker takes when the site never saved that section,
	 * so filling it in does not change what the sanitizer stores.
	 *
	 * @param string $marker Marker key.
	 * @return mixed
	 */
	private static function marker_default( string $marker ) {
		switch ( $marker ) {
			case 'base_slug':
				return base_slug();
			case 'accent_color':
				return '#0073aa';
			case 'captcha_provider':
				return 'none';
			case 'member_name_display':
				return 'display_name';
			case 'seo_post_title':
				return '{post_title} - {space_name} | {site_name}';
			case 'email_from_name':
				return '';
			default:
				return array();
		}
	}

	/**
	 * Fire jetonomy_settings_updated after any write of the option.
	 *
	 * Hooked on update_option_jetonomy_settings and add_option_jetonomy_settings,
	 * so the admin form, REST, CLI and direct update_option() calls all announce.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $old_value Previous value (the option name on add_option).
	 * @param mixed $value     New value.
	 */
	public static function announce( $old_value, $value ): void {
		$old     = self::$baseline ?? ( is_array( $old_value ) ? $old_value : array() );
		$new     = is_array( $value ) ? $value : array();
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				$changed[] = (string) $key;
			}
		}
		if ( ! $changed ) {
			return;
		}

		/**
		 * Fires after jetonomy_settings changed.
		 *
		 * @since 2.1.0
		 *
		 * @param string[] $changed Keys whose stored value changed.
		 * @param string   $source  'form' (wp-admin or any direct write), 'rest' or 'cli'.
		 */
		do_action( 'jetonomy_settings_updated', $changed, '' !== self::$source ? self::$source : 'form' );
	}

	/**
	 * Record a notice, and show it on the settings screen when there is one.
	 *
	 * @param string $code    Notice code.
	 * @param string $message Message for the owner.
	 */
	private static function notice( string $code, string $message ): void {
		self::$notices[] = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message );
		}
	}

	/**
	 * One address setting from the posted form: cleaned, or refused by name with
	 * the stored value kept, so the save never shows "saved" over a broken value.
	 *
	 * @param array  $input Posted settings.
	 * @param array  $clean Settings being built (holds the stored values).
	 * @param string $key   Setting key.
	 * @param string $label What the owner sees the field called.
	 * @return string
	 */
	private static function url_setting( array $input, array $clean, string $key, string $label ): string {
		$url = \Jetonomy\absolute_url( (string) ( $input[ $key ] ?? '' ) );
		if ( null !== $url ) {
			return $url;
		}
		self::notice(
			'jetonomy_invalid_' . $key,
			/* translators: %s: settings field label. */
			sprintf( __( '%s was not changed: it must be a full address starting with http:// or https://. The previous value was kept.', 'jetonomy' ), $label )
		);
		return (string) ( $clean[ $key ] ?? '' );
	}

	/**
	 * Clean posted settings, section by section, merged over the stored ones.
	 *
	 * Moved from Admin::sanitize_settings() in 2.1.0, unchanged apart from the
	 * class it lives in. Admin keeps the role capability branch and calls this.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $input Posted settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		// Merge with existing settings so saving one tab doesn't wipe another.
		$existing = get_option( self::OPTION, array() );
		$clean    = is_array( $existing ) ? $existing : array();

		// ── General tab ──
		// Only process if base_slug is present (General tab was submitted).
		if ( isset( $input['base_slug'] ) ) {
			// The base the site is running on now; old links point at it.
			$old_base = \Jetonomy\base_slug();
			// A cleared field keeps that base: an empty one would unmount the community.
			$new_slug = sanitize_title( $input['base_slug'] ) ?: $old_base;
			if ( $new_slug !== $old_base ) {
				// Delete the versioned flush key so Router re-registers rules on next load.
				delete_option( 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION );

				// Store the old slug so Router can 301-redirect old URLs.
				update_option( 'jetonomy_old_base_slug', $old_base, false );
			}
			// Moving back onto the old slug retires the redirect.
			if ( get_option( 'jetonomy_old_base_slug', '' ) === $new_slug ) {
				delete_option( 'jetonomy_old_base_slug' );
			}
			$clean['base_slug']       = $new_slug;
			$clean['community_title'] = sanitize_text_field( $input['community_title'] ?? __( 'Community', 'jetonomy' ) );
			// Mobile app EULA screen (Apple Guideline 1.2) reads these via /app/config.
			$clean['terms_url']   = self::url_setting( $input, $clean, 'terms_url', __( 'Terms of Service URL', 'jetonomy' ) );
			$clean['privacy_url'] = self::url_setting( $input, $clean, 'privacy_url', __( 'Privacy Policy URL', 'jetonomy' ) );
			$clean['support_url'] = self::url_setting( $input, $clean, 'support_url', __( 'Support URL', 'jetonomy' ) );
			// Product-noun label overrides (singular / plural). Empty = keep the
			// default. One loop over every renamable noun so the save side cannot
			// drift from the Terminology settings block that renders them.
			foreach ( array_keys( \Jetonomy\label_defaults() ) as $jt_noun ) {
				$clean[ $jt_noun . '_label_singular' ] = sanitize_text_field( $input[ $jt_noun . '_label_singular' ] ?? '' );
				$clean[ $jt_noun . '_label_plural' ]   = sanitize_text_field( $input[ $jt_noun . '_label_plural' ] ?? '' );
			}
			// Clamp to the UI max (100) so a crafted POST can't store a huge
			// value that flows straight into a SQL LIMIT on a big-site query.
			$clean['posts_per_page'] = min( 100, max( 1, absint( $input['posts_per_page'] ?? 20 ) ) );
			// Activity-log retention: 1 day to ~10 years. Consumed by the daily
			// jetonomy_prune_activity cron (class-cron.php).
			$clean['activity_log_retention_days'] = min( 3650, max( 1, absint( $input['activity_log_retention_days'] ?? 90 ) ) );
			$clean['replies_per_page']            = min( 100, max( 1, absint( $input['replies_per_page'] ?? 30 ) ) );
			$raw_space_type                       = sanitize_key( (string) ( $input['default_space_type'] ?? 'forum' ) );
			$clean['default_space_type']          = in_array( $raw_space_type, \Jetonomy\Models\Space::valid_types(), true ) ? $raw_space_type : 'forum';
			// Community access mode — radio stores "1" (public) or "0" (private).
			$clean['guest_read'] = isset( $input['guest_read'] ) ? (bool) (int) $input['guest_read'] : true;
			// Community as homepage — unchecked checkboxes don't submit, so
			// absence inside a General-tab save means OFF (default).
			$clean['front_page'] = ! empty( $input['front_page'] );
			// Private spaces as locked teasers for logged-out visitors (default off).
			$clean['guest_private_teasers'] = ! empty( $input['guest_private_teasers'] );
			if ( $clean['guest_private_teasers'] !== ! empty( $existing['guest_private_teasers'] ) ) {
				\Jetonomy\Models\Space::bump_tree_generation(); // Cached guest listings must re-read.
			}

			// Front-end space creation role allowlist (G6). Validate each
			// posted role against the live wp_roles() registry so a stale or
			// malformed input can't smuggle in an arbitrary string. Empty
			// array (no checkboxes ticked) keeps the gate admin-only.
			$raw_roles                              = is_array( $input['frontend_space_creation_roles'] ?? null ) ? $input['frontend_space_creation_roles'] : array();
			$known_keys                             = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : array();
			$clean['frontend_space_creation_roles'] = array_values(
				array_intersect( array_map( 'sanitize_key', $raw_roles ), $known_keys )
			);

			// Email verification gate for new signups. When ON, the Login
			// block's register flow holds the new account in pending state
			// until the visitor clicks the confirmation link in the email.
			// The reject_pending_verification_login authenticate filter only
			// gates accounts that ALREADY have the pending meta — flipping
			// this setting on does NOT retroactively lock existing users.
			$clean['require_email_verification'] = ! empty( $input['require_email_verification'] );

			// Both of these shipped in 1.9.3 as checkboxes on this tab and were
			// never added here, so sanitize_settings() dropped them on every
			// save. $clean starts as $existing, so an unwritten key silently
			// keeps its old value - the box appeared to tick, the page reloaded
			// showing it unticked, and the feature behind it stayed off. That is
			// what made "let space admins delete a space" impossible to turn on:
			// the AJAX guard reads allow_space_admin_purge, which could never
			// become true.
			//
			// An unchecked checkbox submits nothing, so absence inside a
			// General-tab save means OFF - the same rule front_page above uses.
			$clean['allow_space_admin_purge'] = ! empty( $input['allow_space_admin_purge'] );
			// Arming data removal on delete is for whoever can delete plugins; a
			// delegated settings manager keeps the stored value.
			if ( current_user_can( 'manage_options' ) ) {
				$clean['remove_data_on_uninstall'] = ! empty( $input['remove_data_on_uninstall'] );
			}
			// Rendered only while Pro is inactive, so only a form that carried the row may change it.
			if ( ! empty( $input['pro_suggestions_field'] ) ) {
				$clean['hide_pro_suggestions'] = empty( $input['show_pro_suggestions'] );
			}
		}

		// ── Permissions tab ──
		// Only process if trust_thresholds is present (Permissions tab was submitted).
		if ( isset( $input['trust_thresholds'] ) ) {
			$raw_thresholds = is_array( $input['trust_thresholds'] ) ? $input['trust_thresholds'] : array();
			$tl_defaults    = \Jetonomy\Trust\Trust_Levels::defaults();
			foreach ( array( 1, 2, 3 ) as $level ) {
				$td                                  = $tl_defaults[ $level ];
				$lv                                  = is_array( $raw_thresholds[ $level ] ?? null ) ? $raw_thresholds[ $level ] : array();
				$clean['trust_thresholds'][ $level ] = array(
					'posts'            => absint( $lv['posts'] ?? $td['posts'] ),
					'days_active'      => absint( $lv['days_active'] ?? $td['days_active'] ),
					'reputation'       => absint( $lv['reputation'] ?? $td['reputation'] ),
					'replies_received' => absint( $lv['replies_received'] ?? $td['replies_received'] ),
				);
			}
			// Display names per level; blank keeps the built-in name.
			$raw_names                  = is_array( $input['trust_level_names'] ?? null ) ? $input['trust_level_names'] : array();
			$clean['trust_level_names'] = array();
			// No wp_unslash(): options.php has already unslashed the posted value.
			foreach ( range( 0, 5 ) as $level ) {
				$clean['trust_level_names'][ $level ] = sanitize_text_field( (string) ( $raw_names[ $level ] ?? '' ) );
			}
		}

		// How members are identified on bylines, member lists and REST.
		// Allowlisted rather than trusted: this string is read on every name
		// render, so an unrecognised value must fall back rather than reach
		// user_display_name().
		if ( isset( $input['member_name_display'] ) ) {
			$mode                         = sanitize_key( wp_unslash( $input['member_name_display'] ) );
			$clean['member_name_display'] = in_array( $mode, array( 'display_name', 'handle', 'both' ), true )
				? $mode
				: 'display_name';
		}

		// Only process if rate_limits is present (Permissions tab was submitted).
		if ( isset( $input['rate_limits'] ) ) {
			$raw_limits           = is_array( $input['rate_limits'] ) ? $input['rate_limits'] : array();
			$rl_defaults          = \Jetonomy\Permissions\Rate_Limiter::defaults();
			$clean['rate_limits'] = array(
				'posts'   => absint( $raw_limits['posts'] ?? $rl_defaults['posts'] ),
				'replies' => absint( $raw_limits['replies'] ?? $rl_defaults['replies'] ),
				'votes'   => absint( $raw_limits['votes'] ?? $rl_defaults['votes'] ),
			);
		}

		// Reputation point overrides — only persist the keys we recognise so
		// a stale POST body can't smuggle in arbitrary action names. Values
		// are signed ints (negative penalties allowed).
		if ( isset( $input['reputation_points'] ) ) {
			$raw_rep   = is_array( $input['reputation_points'] ) ? $input['reputation_points'] : array();
			$defaults  = \Jetonomy\Trust\Reputation::action_points_defaults();
			$clean_rep = array();
			foreach ( $defaults as $action_key => $default_val ) {
				if ( ! array_key_exists( $action_key, $raw_rep ) ) {
					continue;
				}

				// An empty field means "use the default", not zero. Storing 0 for a
				// cleared box silently turned off the points for that action.
				if ( '' === trim( (string) $raw_rep[ $action_key ] ) ) {
					continue;
				}

				// Store only what DIFFERS from the default. The form posts every
				// action, so persisting them all made the first save a full set of
				// overrides - after which the jetonomy_reputation_points_map filter
				// could never apply again, because Reputation::points_for() reads a
				// stored override ahead of the filtered map. An owner who never
				// touched this tab had their integration's ladder silently frozen
				// by visiting Permissions once.
				if ( (int) $raw_rep[ $action_key ] === (int) $default_val ) {
					continue;
				}

				$clean_rep[ $action_key ] = (int) $raw_rep[ $action_key ];
			}
			$clean['reputation_points'] = $clean_rep;
		}

		// ── Email tab ──
		// Only process if email_from_name is present (Email tab was submitted).
		if ( isset( $input['email_from_name'] ) ) {
			$clean['email_from_name']   = sanitize_text_field( $input['email_from_name'] );
			$clean['email_from_email']  = sanitize_email( $input['email_from_email'] ?? '' );
			$clean['email_logo_url']    = self::url_setting( $input, $clean, 'email_logo_url', __( 'Email Logo', 'jetonomy' ) );
			$clean['email_footer_text'] = sanitize_text_field( $input['email_footer_text'] ?? '' );

			// Verification-reminder cadence (hours). 0 = disabled; clamp to a
			// week so a typo can't schedule an absurd delay. Consumed by the
			// verification-reminder cron (Verification_Reminder).
			$clean['verification_reminder_hours'] = min( 168, max( 0, absint( $input['verification_reminder_hours'] ?? 24 ) ) );

			// Notification defaults — checkbox values absent when unchecked, so default false if not present.
			// Same single list as the Notification Defaults table. Pro's `message`
			// is only in it (rendered, and so submitted) while messaging is live.
			$notif_types = array_keys( array_filter( \Jetonomy\Admin\Admin::email_types(), static fn( array $t ): bool => $t['toggle'] ) );
			$raw_notif   = is_array( $input['notification_defaults'] ?? null ) ? $input['notification_defaults'] : array();
			foreach ( $notif_types as $nt ) {
				$nt_data                               = is_array( $raw_notif[ $nt ] ?? null ) ? $raw_notif[ $nt ] : array();
				$clean['notification_defaults'][ $nt ] = array(
					'web'   => ! empty( $nt_data['web'] ),
					'email' => ! empty( $nt_data['email'] ),
				);
			}
		}

		// ── Appearance tab ──
		// Only process if accent_color is present (Appearance tab was submitted).
		if ( isset( $input['accent_color'] ) ) {
			$clean['accent_color'] = sanitize_hex_color( $input['accent_color'] );
			$clean['logo_url']     = self::url_setting( $input, $clean, 'logo_url', __( 'Logo URL', 'jetonomy' ) );
			// Color palette — empty string means "no override, keep the default".
			foreach ( array( 'text_color', 'bg_color', 'bg_subtle_color', 'border_color' ) as $palette_key ) {
				$clean[ $palette_key ] = sanitize_hex_color( (string) ( $input[ $palette_key ] ?? '' ) ) ?: '';
			}
			$clean['layout_density'] = sanitize_text_field( $input['layout_density'] ?? 'comfortable' );
			$clean['custom_css']     = wp_strip_all_tags( $input['custom_css'] ?? '' );

			$raw_width                       = sanitize_key( (string) ( $input['container_width'] ?? 'theme' ) );
			$clean['container_width']        = in_array( $raw_width, array( 'theme', 'full', 'custom' ), true ) ? $raw_width : 'theme';
			$clean['container_width_custom'] = max( 600, min( 2400, absint( $input['container_width_custom'] ?? 1280 ) ) );

			$raw_padding             = sanitize_key( (string) ( $input['padding_preset'] ?? 'theme' ) );
			$clean['padding_preset'] = in_array( $raw_padding, array( 'theme', 'none', 'comfortable' ), true ) ? $raw_padding : 'theme';
		}

		// ── Anti-Spam tab ──
		// Only process if captcha_provider is present (Anti-Spam tab was submitted).
		if ( isset( $input['captcha_provider'] ) ) {
			$allowed_providers                = array( 'none', 'recaptcha_v3', 'turnstile' );
			$raw_provider                     = sanitize_text_field( $input['captcha_provider'] );
			$clean['captcha_provider']        = in_array( $raw_provider, $allowed_providers, true ) ? $raw_provider : 'none';
			$clean['captcha_site_key']        = sanitize_text_field( $input['captcha_site_key'] ?? '' );
			$clean['captcha_secret_key']      = sanitize_text_field( $input['captcha_secret_key'] ?? '' );
			$raw_threshold                    = (float) ( $input['captcha_score_threshold'] ?? 0.5 );
			$clean['captcha_score_threshold'] = max( 0.1, min( 0.9, $raw_threshold ) );
		}

		// ── SEO tab ──
		// Only process if seo_post_title is present (SEO tab was submitted).
		if ( isset( $input['seo_post_title'] ) ) {
			$clean['seo_post_title']       = sanitize_text_field( $input['seo_post_title'] );
			$clean['seo_space_title']      = sanitize_text_field( $input['seo_space_title'] ?? '{space_name} | {site_name}' );
			$clean['seo_schema']           = ! empty( $input['seo_schema'] );
			$clean['seo_sitemap']          = ! empty( $input['seo_sitemap'] );
			$clean['seo_noindex_profiles'] = ! empty( $input['seo_noindex_profiles'] );
			$clean['seo_noindex_search']   = ! empty( $input['seo_noindex_search'] );

			// Twitter / X site handle (D.6) — emitted as `twitter:site` on
			// every public route. Strip leading @ if the admin types it; the
			// emitter prepends it back so the value stored is a plain handle.
			$raw_twitter                 = trim( (string) ( $input['seo_twitter_handle'] ?? '' ) );
			$clean['seo_twitter_handle'] = preg_replace( '/[^A-Za-z0-9_]/', '', ltrim( $raw_twitter, '@' ) );

			// Default share image URL (D.6) — falls back into the og:image
			// chain (route image → admin default → custom logo → site icon).
			$clean['seo_default_og_image'] = self::url_setting( $input, $clean, 'seo_default_og_image', __( 'Default share image', 'jetonomy' ) );

			// Social embeds — Meta developer app credentials for Instagram/Facebook oEmbed.
			// App IDs are numeric; secrets are 32-char hex strings. Strip whitespace only.
			$clean['fb_app_id']     = preg_replace( '/\D/', '', (string) ( $input['fb_app_id'] ?? '' ) );
			$clean['fb_app_secret'] = trim( sanitize_text_field( $input['fb_app_secret'] ?? '' ) );
		}

		return $clean;
	}
}
