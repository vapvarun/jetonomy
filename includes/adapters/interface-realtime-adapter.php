<?php
/**
 * Real-time adapter interface.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

interface Realtime_Adapter {
	public function is_active(): bool;
	public function publish( string $channel, string $event, array $data ): void;
	public function get_client_config(): array;
}
