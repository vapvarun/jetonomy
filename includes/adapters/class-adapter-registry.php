<?php
/**
 * Adapter registry.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

class Adapter_Registry {

	private static array $membership = [];
	private static array $search     = [];
	private static array $email      = [];
	private static array $ai         = [];

	/**
	 * Request-scoped cache for membership_level_owners().
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $level_owners = null;

	public static function register_membership( string $id, Membership_Adapter $adapter ): void {
		self::$membership[ $id ] = $adapter;
		self::$level_owners      = null;
	}

	/**
	 * Display names for the membership adapters, keyed by adapter id.
	 *
	 * Deliberately adapter-level, not entity-level: one adapter can expose
	 * several kinds of thing (Learnomy courses AND memberships, LearnDash
	 * courses AND groups), so an entity-specific name here would be wrong for
	 * half the ids it labels. The level's own label carries the specifics.
	 *
	 * @return array<string, string>
	 */
	public static function membership_labels(): array {
		/**
		 * Filter the display names shown for membership adapters.
		 *
		 * Lets a third-party adapter name itself in the access-rules UI.
		 *
		 * @param array<string, string> $labels Adapter id => display name.
		 */
		return apply_filters(
			'jetonomy_membership_adapter_labels',
			array(
				'wp-roles'      => __( 'WP Role', 'jetonomy' ),
				'memberpress'   => __( 'MemberPress', 'jetonomy' ),
				'pmpro'         => __( 'PMPro', 'jetonomy' ),
				'woocommerce'   => __( 'WooCommerce', 'jetonomy' ),
				'rcp'           => __( 'RCP', 'jetonomy' ),
				'learndash'     => __( 'LearnDash', 'jetonomy' ),
				'tutor'         => __( 'Tutor LMS', 'jetonomy' ),
				'lifterlms'     => __( 'LifterLMS', 'jetonomy' ),
				'sensei'        => __( 'Sensei', 'jetonomy' ),
				'masterstudy'   => __( 'MasterStudy', 'jetonomy' ),
				'learnomy'      => __( 'Learnomy', 'jetonomy' ),
				'suremembers'   => __( 'SureMembers', 'jetonomy' ),
				'buddynext-pro' => __( 'BuddyNext Pro Tier', 'jetonomy' ),
				'wpfusion'      => __( 'WP Fusion Tag', 'jetonomy' ),
			)
		);
	}

	/**
	 * Map every level id an active adapter exposes to that adapter's id.
	 *
	 * Asking the adapters which ids they own replaces the hardcoded prefix
	 * table this used to rely on. That table silently went stale every time an
	 * adapter shipped without someone remembering to add its prefix — WP Fusion
	 * and SureMembers both landed after it was written, so their rules rendered
	 * as raw `wpfusion_123` / `suremembers_5` on the Access Rules screen
	 * (Basecamp 10126146658). A registry lookup cannot drift that way.
	 *
	 * `wp-roles` is skipped: its ids are bare role slugs with no prefix, it is
	 * excluded from the membership picker, and a `membership` rule should never
	 * resolve to a WP role.
	 *
	 * @return array<string, string> Level id => adapter id.
	 */
	public static function membership_level_owners(): array {
		if ( null !== self::$level_owners ) {
			return self::$level_owners;
		}

		$owners = [];
		foreach ( self::$membership as $adapter_id => $adapter ) {
			if ( 'wp-roles' === $adapter_id || ! $adapter->is_active() ) {
				continue;
			}
			foreach ( $adapter->get_all_levels() as $level ) {
				$level_id = isset( $level['id'] ) ? (string) $level['id'] : '';
				if ( '' !== $level_id ) {
					$owners[ $level_id ] = $adapter_id;
				}
			}
		}

		self::$level_owners = $owners;

		return $owners;
	}

	/**
	 * Human-readable type + value for a membership rule value.
	 *
	 * Falls back to the raw level id when no active adapter claims it — an
	 * adapter whose plugin has been deactivated still has rules on disk, and
	 * showing the stored value beats showing nothing.
	 *
	 * @param string $level_id Stored `rule_value`, e.g. `wpfusion_102`.
	 * @return array{type: string, value: string}
	 */
	public static function describe_membership_level( string $level_id ): array {
		$fallback = [
			'type'  => __( 'Membership', 'jetonomy' ),
			'value' => $level_id,
		];

		if ( '' === $level_id ) {
			return $fallback;
		}

		$owners     = self::membership_level_owners();
		$adapter_id = $owners[ $level_id ] ?? '';
		if ( '' === $adapter_id ) {
			return $fallback;
		}

		$adapter = self::get_membership( $adapter_id );
		if ( ! $adapter ) {
			return $fallback;
		}

		$labels = self::membership_labels();
		$label  = $adapter->get_level_label( $level_id );

		return [
			'type'  => $labels[ $adapter_id ] ?? ucfirst( $adapter_id ),
			'value' => '' !== $label ? $label : $level_id,
		];
	}

	public static function register_search( string $id, Search_Adapter $adapter ): void {
		self::$search[ $id ] = $adapter;
	}

	public static function register_email( string $id, Email_Adapter $adapter ): void {
		self::$email[ $id ] = $adapter;
	}

	public static function get_membership( string $id = '' ): ?Membership_Adapter {
		if ( $id ) {
			return self::$membership[ $id ] ?? null;
		}
		// Return first active adapter
		foreach ( self::$membership as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}
		return null;
	}

	public static function get_email(): ?Email_Adapter {
		foreach ( self::$email as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}
		return null;
	}

	public static function get_search(): ?Search_Adapter {
		foreach ( self::$search as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}
		return null;
	}

	public static function get_all_membership(): array {
		return self::$membership;
	}

	public static function register_ai( string $id, AI_Adapter $adapter ): void {
		self::$ai[ $id ] = $adapter;
	}

	public static function get_ai( string $id = '' ): ?AI_Adapter {
		if ( $id ) {
			return self::$ai[ $id ] ?? null;
		}
		foreach ( self::$ai as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}
		return null;
	}

	public static function get_all_ai(): array {
		return self::$ai;
	}

	/**
	 * Initialize default adapters.
	 */
	public static function init_defaults(): void {
		self::register_membership( 'wp-roles', new WP_Roles_Adapter() );
		self::register_email( 'wp-mail', new WP_Mail_Adapter() );
	}
}
