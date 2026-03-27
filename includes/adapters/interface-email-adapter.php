<?php
namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

interface Email_Adapter {
	public function is_active(): bool;
	public function send( string $to, string $subject, string $html, string $plain, array $extra_headers = [] ): bool;
	public function send_batch( array $messages ): array;
	public function register_hooks(): void;
}
