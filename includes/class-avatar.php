<?php
/**
 * Local avatar resolution + management.
 *
 * Jetonomy profiles have always stored an `avatar_url` column on
 * jt_user_profiles (writable via PATCH /users/me, readable via GET
 * /users/me), but nothing consumed it at render time — every surface fell
 * through to Gravatar (#9966775705). This class is the single consumer:
 * one `pre_get_avatar_data` filter so get_avatar() / get_avatar_url()
 * callers everywhere (templates, REST payloads, wp-admin, Pro messaging)
 * pick the local avatar automatically, with Gravatar as the fallback when
 * no local avatar is set.
 *
 * Upload flow is native and REST-first: the edit-profile form posts the
 * image through the existing POST /media endpoint (uploads gate +
 * auto-alt), then saves the returned URL via PATCH /users/me. Site owners
 * get an admin entry point on the wp-admin user-edit screen to view or
 * remove a member's custom avatar.
 *
 * @package Jetonomy
 * @since 1.5.0
 */

namespace Jetonomy;

use Jetonomy\Models\UserProfile;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and manages locally-uploaded avatars.
 */
class Avatar {

	/**
	 * Per-request cache of resolved avatar URLs, keyed by user ID.
	 * Empty string = looked up, no custom avatar (negative cache) — a
	 * topic listing calls the avatar filter dozens of times per user.
	 *
	 * @var array<int, string>
	 */
	private static array $cache = array();

	/**
	 * User-meta flag caching whether a member has a real hosted Gravatar.
	 * '1' = yes, '0' = no, absent = not yet checked. Keyed off the user's
	 * email, so it is invalidated when the email changes.
	 */
	private const META_HAS_GRAVATAR = 'jetonomy_has_gravatar';

	/**
	 * Async action that populates META_HAS_GRAVATAR out of the render path.
	 */
	private const CHECK_HOOK = 'jetonomy_gravatar_check';

	/**
	 * Per-request guard so one page render enqueues at most one background
	 * Gravatar check per user, no matter how many times the avatar renders.
	 *
	 * @var array<int, true>
	 */
	private static array $check_enqueued = array();

	/**
	 * Hook everything up. Called once from Jetonomy bootstrap.
	 */
	public static function init(): void {
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );

		// wp-admin entry point: site owners can see / remove a member's
		// custom avatar from the user-edit screen.
		add_action( 'show_user_profile', array( __CLASS__, 'render_admin_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_admin_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_admin_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_field' ) );

		// Background Gravatar-existence check (populates META_HAS_GRAVATAR) and
		// its invalidation. Runs off the render path via Action Scheduler, with
		// a WP-Cron single-event fallback.
		add_action( self::CHECK_HOOK, array( __CLASS__, 'run_gravatar_check' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'invalidate_gravatar' ) );
	}

	/**
	 * Prefer the profile's local avatar over Gravatar.
	 *
	 * Runs on `pre_get_avatar_data` so both get_avatar_url() and the
	 * get_avatar() HTML path are covered in one place. Respects
	 * `force_default` (used by the edit-profile "remove" preview and by
	 * anyone explicitly requesting the default avatar).
	 *
	 * @param array $args        Avatar data args.
	 * @param mixed $id_or_email User ID, email, or object WP passes around.
	 * @return array
	 */
	public static function filter_avatar_data( $args, $id_or_email ): array {
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$user_id = self::resolve_user_id( $id_or_email );
		if ( $user_id <= 0 ) {
			return $args;
		}

		$url = self::custom_avatar_url( $user_id );
		if ( '' !== $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * The user's locally-stored avatar URL, or '' when none is set.
	 *
	 * @param int $user_id WP user ID.
	 * @return string
	 */
	public static function custom_avatar_url( int $user_id ): string {
		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}

		$profile = UserProfile::find_by_user( $user_id );
		$url     = $profile && ! empty( $profile->avatar_url ) ? (string) $profile->avatar_url : '';

		self::$cache[ $user_id ] = $url;

		return $url;
	}

	/**
	 * Clear the per-request cache for a user (after their avatar changes).
	 *
	 * @param int $user_id WP user ID.
	 */
	public static function flush_cache( int $user_id ): void {
		unset( self::$cache[ $user_id ] );
	}

	/**
	 * Resolve the avatar URL to display for a user, or '' when the caller
	 * should render an initials placeholder instead.
	 *
	 * Chain: a locally-uploaded avatar (Jetonomy profile, BuddyPress, or any
	 * other provider that hooks the avatar filters) is used as-is; a plain
	 * Gravatar URL is only used when the member actually has a hosted Gravatar
	 * (cached check). Otherwise '' is returned so the reader sees initials
	 * rather than Gravatar's generic mystery-person, which is indistinguishable
	 * from a broken image.
	 *
	 * The Gravatar existence check never runs inline — it is enqueued as a
	 * background job and the member shows initials until it resolves, so a
	 * 400-reply topic never fires 400 HTTP calls during render.
	 *
	 * @param int $user_id WP user ID.
	 * @param int $size    Requested pixel size (passed to get_avatar_url()).
	 * @return string Avatar URL, or '' to signal an initials fallback.
	 */
	public static function display_url( int $user_id, int $size = 96 ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$url = (string) get_avatar_url( $user_id, array( 'size' => $size ) );
		if ( '' === $url ) {
			return '';
		}

		// A non-Gravatar URL means a real uploaded avatar resolved via the
		// avatar filters (Jetonomy local, BuddyPress, WP Fusion, …). Use it.
		if ( false === strpos( $url, 'gravatar.com/avatar/' ) ) {
			return $url;
		}

		// Gravatar URL: only trust it when the member has a real hosted Gravatar.
		$has = self::has_gravatar( $user_id );
		if ( true === $has ) {
			return $url;
		}
		if ( null === $has ) {
			self::maybe_check_gravatar( $user_id ); // Warm the cache in the background.
		}

		return '';
	}

	/**
	 * Whether the member has a real hosted Gravatar.
	 *
	 * @param int $user_id WP user ID.
	 * @return bool|null true/false when known, null when not yet checked.
	 */
	public static function has_gravatar( int $user_id ): ?bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$flag = get_user_meta( $user_id, self::META_HAS_GRAVATAR, true );
		if ( '1' === $flag ) {
			return true;
		}
		if ( '0' === $flag ) {
			return false;
		}
		return null;
	}

	/**
	 * Enqueue a one-off background Gravatar-existence check for a user,
	 * deduplicated per request and (for an hour) across requests so a busy
	 * topic doesn't schedule the same check repeatedly.
	 *
	 * @param int $user_id WP user ID.
	 */
	public static function maybe_check_gravatar( int $user_id ): void {
		if ( $user_id <= 0 || isset( self::$check_enqueued[ $user_id ] ) ) {
			return;
		}
		self::$check_enqueued[ $user_id ] = true;

		$lock = 'jt_grav_chk_' . $user_id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, HOUR_IN_SECONDS );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::CHECK_HOOK, array( $user_id ), 'jetonomy' );
		} else {
			wp_schedule_single_event( time() + 30, self::CHECK_HOOK, array( $user_id ) );
		}
	}

	/**
	 * Background worker: HEAD-request Gravatar with `d=404` and cache whether a
	 * real avatar exists (200) or not (404). Runs via Action Scheduler / WP-Cron.
	 *
	 * @param int $user_id WP user ID.
	 */
	public static function run_gravatar_check( $user_id ): void {
		$user_id = (int) $user_id;
		delete_transient( 'jt_grav_chk_' . $user_id );

		$user = get_userdata( $user_id );
		if ( ! $user || '' === (string) $user->user_email ) {
			update_user_meta( $user_id, self::META_HAS_GRAVATAR, '0' );
			return;
		}

		$hash = md5( strtolower( trim( (string) $user->user_email ) ) );
		$resp = wp_remote_head(
			'https://www.gravatar.com/avatar/' . $hash . '?d=404',
			array(
				'timeout'     => 3,
				'redirection' => 0,
			)
		);

		$exists = ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp );
		update_user_meta( $user_id, self::META_HAS_GRAVATAR, $exists ? '1' : '0' );
	}

	/**
	 * Re-check on the next render when a user's email changes (Gravatar keys
	 * off the email address).
	 *
	 * @param int      $user_id       Updated user ID.
	 * @param \WP_User $old_user_data User object before the update.
	 */
	public static function on_profile_update( $user_id, $old_user_data ): void {
		$new = get_userdata( (int) $user_id );
		if ( $new && $new->user_email !== $old_user_data->user_email ) {
			self::invalidate_gravatar( (int) $user_id );
		}
	}

	/**
	 * Drop the cached Gravatar flag so the next render re-checks.
	 *
	 * @param int $user_id WP user ID.
	 */
	public static function invalidate_gravatar( $user_id ): void {
		$user_id = (int) $user_id;
		delete_user_meta( $user_id, self::META_HAS_GRAVATAR );
		delete_transient( 'jt_grav_chk_' . $user_id );
	}

	/**
	 * Resolve a WP user ID from the many forms WordPress passes to avatar
	 * filters (int, email, WP_User, WP_Comment, WP_Post, …).
	 *
	 * @param mixed $id_or_email User identifier.
	 * @return int 0 if unresolvable.
	 */
	public static function resolve_user_id( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( is_object( $id_or_email ) ) {
			if ( isset( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			if ( isset( $id_or_email->ID ) ) {
				return (int) $id_or_email->ID;
			}
			if ( isset( $id_or_email->user_email ) ) {
				$u = get_user_by( 'email', $id_or_email->user_email );
				return $u ? (int) $u->ID : 0;
			}
			if ( isset( $id_or_email->comment_author_email ) ) {
				$u = get_user_by( 'email', $id_or_email->comment_author_email );
				return $u ? (int) $u->ID : 0;
			}
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u = get_user_by( 'email', $id_or_email );
			return $u ? (int) $u->ID : 0;
		}
		return 0;
	}

	/**
	 * wp-admin user-edit screen: show the custom avatar with a remove
	 * checkbox. Core's update-user nonce covers the save.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public static function render_admin_field( $user ): void {
		$url = self::custom_avatar_url( (int) $user->ID );
		if ( '' === $url ) {
			return;
		}
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Community avatar', 'jetonomy' ); ?></th>
				<td>
					<img src="<?php echo esc_url( $url ); ?>" width="64" height="64" style="border-radius:50%;display:block;margin-bottom:8px;" alt="">
					<label>
						<input type="checkbox" name="jetonomy_remove_avatar" value="1">
						<?php esc_html_e( 'Remove this custom avatar (falls back to Gravatar)', 'jetonomy' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Handle the admin remove checkbox.
	 *
	 * @param int $user_id User being saved.
	 */
	public static function save_admin_field( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core's update-user nonce is verified before these hooks fire.
		if ( empty( $_POST['jetonomy_remove_avatar'] ) ) {
			return;
		}
		UserProfile::update_profile( (int) $user_id, array( 'avatar_url' => '' ) );
		self::flush_cache( (int) $user_id );
	}
}
