<?php
namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

class Adapter_Registry {

    private static array $membership = [];
    private static array $search     = [];
    private static array $realtime   = [];
    private static array $email      = [];

    public static function register_membership( string $id, Membership_Adapter $adapter ): void {
        self::$membership[ $id ] = $adapter;
    }

    public static function register_search( string $id, Search_Adapter $adapter ): void {
        self::$search[ $id ] = $adapter;
    }

    public static function register_realtime( string $id, Realtime_Adapter $adapter ): void {
        self::$realtime[ $id ] = $adapter;
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
            if ( $adapter->is_active() ) return $adapter;
        }
        return null;
    }

    public static function get_realtime(): ?Realtime_Adapter {
        foreach ( self::$realtime as $adapter ) {
            if ( $adapter->is_active() ) return $adapter;
        }
        return null;
    }

    public static function get_email(): ?Email_Adapter {
        foreach ( self::$email as $adapter ) {
            if ( $adapter->is_active() ) return $adapter;
        }
        return null;
    }

    public static function get_search(): ?Search_Adapter {
        foreach ( self::$search as $adapter ) {
            if ( $adapter->is_active() ) return $adapter;
        }
        return null;
    }

    public static function get_all_membership(): array {
        return self::$membership;
    }

    /**
     * Initialize default adapters.
     */
    public static function init_defaults(): void {
        self::register_membership( 'wp-roles', new WP_Roles_Adapter() );
        self::register_realtime( 'polling', new Polling_Adapter() );
    }
}
