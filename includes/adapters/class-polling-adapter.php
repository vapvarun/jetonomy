<?php
namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

class Polling_Adapter implements Realtime_Adapter {

    public function is_active(): bool {
        return true;
    }

    public function publish( string $channel, string $event, array $data ): void {
        // Polling doesn't push — clients pull via /updates endpoint
        // Store the event in a transient for the polling endpoint to pick up
        $key = 'jetonomy_event_' . md5( $channel . $event . microtime() );
        set_transient( $key, [
            'channel' => $channel,
            'event'   => $event,
            'data'    => $data,
            'time'    => current_time( 'mysql', true ),
        ], 120 ); // Keep for 2 minutes
    }

    public function get_client_config(): array {
        return [
            'type'     => 'polling',
            'interval' => 10000, // 10 seconds in ms
            'endpoint' => rest_url( 'jetonomy/v1/updates' ),
        ];
    }
}
