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
