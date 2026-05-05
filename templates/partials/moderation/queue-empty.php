<?php
/**
 * Empty-state partial for a moderation queue.
 *
 * Thin wrapper around the canonical `partials/empty-state.php` so that
 * existing call sites (`templates/views/moderation.php`,
 * `templates/views/space-moderation.php`) keep working without churn.
 * The check-circle icon is moderation-specific; everything else flows
 * through the shared partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$message = isset( $message ) ? (string) $message : __( 'No pending flags. The community is clean!', 'jetonomy' );

\Jetonomy\Template_Loader::partial(
	'empty-state',
	[
		'icon'      => 'check-circle',
		'icon_size' => 48,
		'message'   => $message,
	]
);
