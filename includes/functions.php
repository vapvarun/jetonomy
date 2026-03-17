<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

function table( string $name ): string {
    global $wpdb;
    return $wpdb->prefix . 'jt_' . $name;
}

function now(): string {
    return current_time( 'mysql', true );
}
