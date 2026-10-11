<?php
/**
 * Community canvas.
 *
 * Returned by Router::maybe_render_route() from the `template_include` filter,
 * which is how WordPress asks a plugin "which file renders this request". The
 * router renders through this file at `template_include`, not inline during
 * `template_redirect` (which fires BEFORE it), so anything gating on the later
 * hook - a maintenance-mode, membership or coming-soon plugin - still runs.
 * A file that WordPress loads normally, at the moment
 * it loads templates, leaves every one of those callbacks a turn to replace it.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

Template_Loader::render( Router::rendered_route_data() );
