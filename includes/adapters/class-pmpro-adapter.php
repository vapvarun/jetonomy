<?php
namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\SpaceMember;

class PMPro_Adapter implements Membership_Adapter {

    public function is_active(): bool {
        return defined( 'PMPRO_VERSION' ) && function_exists( 'pmpro_getMembershipLevelForUser' );
    }

    public function get_user_levels( int $user_id ): array {
        if ( ! $this->is_active() ) return [];

        // PMPro 3.0+ supports multiple levels
        if ( function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
            $levels = pmpro_getMembershipLevelsForUser( $user_id );
            return array_map( function ( $level ) {
                return 'pmpro_' . $level->id;
            }, $levels ?: [] );
        }

        // PMPro 2.x — single level
        $level = pmpro_getMembershipLevelForUser( $user_id );
        return $level ? [ 'pmpro_' . $level->id ] : [];
    }

    public function user_has_level( int $user_id, string $level_id ): bool {
        return in_array( $level_id, $this->get_user_levels( $user_id ), true );
    }

    public function get_all_levels(): array {
        if ( ! $this->is_active() ) return [];

        if ( ! function_exists( 'pmpro_getAllLevels' ) ) return [];

        $all = pmpro_getAllLevels( false, true );
        $levels = [];
        foreach ( $all as $level ) {
            $levels[] = [
                'id'    => 'pmpro_' . $level->id,
                'label' => $level->name,
            ];
        }
        return $levels;
    }

    public function get_level_label( string $level_id ): string {
        if ( ! $this->is_active() ) return $level_id;

        $id = (int) str_replace( 'pmpro_', '', $level_id );
        if ( function_exists( 'pmpro_getLevel' ) ) {
            $level = pmpro_getLevel( $id );
            return $level ? $level->name : $level_id;
        }
        return $level_id;
    }

    public function register_hooks(): void {
        if ( ! $this->is_active() ) return;

        // Level changed (added, changed, or removed)
        add_action( 'pmpro_after_change_membership_level', [ $this, 'on_level_change' ], 10, 3 );
    }

    /**
     * Handle PMPro level change.
     *
     * @param int $level_id New level ID (0 = cancelled)
     * @param int $user_id
     * @param int $cancel_level Old level being cancelled
     */
    public function on_level_change( int $level_id, int $user_id, $cancel_level = 0 ): void {
        // If new level assigned, activate
        if ( $level_id > 0 ) {
            $this->sync_spaces_for_level( $user_id, 'pmpro_' . $level_id, true );
            do_action( 'jetonomy_membership_activated', $user_id, 'pmpro_' . $level_id, 'pmpro' );
        }

        // If old level cancelled, deactivate
        if ( $cancel_level > 0 ) {
            $this->sync_spaces_for_level( $user_id, 'pmpro_' . $cancel_level, false );
            do_action( 'jetonomy_membership_deactivated', $user_id, 'pmpro_' . $cancel_level, 'pmpro' );
        }
    }

    private function sync_spaces_for_level( int $user_id, string $level_id, bool $activate ): void {
        global $wpdb;

        $table = \Jetonomy\table( 'access_rules' );
        $rules = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE rule_type = 'membership' AND rule_value = %s",
                $level_id
            )
        );

        foreach ( $rules as $rule ) {
            if ( $activate ) {
                SpaceMember::add( (int) $rule->space_id, $user_id, $rule->space_role ?? 'member' );
            } else {
                $current = SpaceMember::get_role( (int) $rule->space_id, $user_id );
                if ( $current && 'viewer' !== $current ) {
                    SpaceMember::add( (int) $rule->space_id, $user_id, 'viewer' );
                }
            }
        }
    }
}
