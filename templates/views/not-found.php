<?php
/**
 * Community page that does not exist (an unknown route, or a feature that is
 * switched off, such as Messages when Pro's messaging is dormant).
 *
 * Renders inside the community shell so the member keeps the header and a way
 * back, instead of an empty page under a 404 status.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

\Jetonomy\Template_Loader::partial(
	'empty-state',
	array(
		'icon'        => 'help-circle',
		'icon_size'   => 48,
		'message'     => __( 'This page is not available.', 'jetonomy' ),
		'description' => __( 'The link may be out of date, or this feature is turned off on this community.', 'jetonomy' ),
		// The page state adds the breadcrumb home, "Browse spaces" and a search box.
	)
);
