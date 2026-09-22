<?php
/**
 * Community canvas.
 *
 * Returned by Router::maybe_render_route() from the `template_include` filter,
 * which is how WordPress asks a plugin "which file renders this request". The
 * router used to render inline and `exit` during `template_redirect` instead;
 * that fires BEFORE template_include, so anything gating on the later hook - a
 * maintenance-mode, membership or coming-soon plugin - never ran at all
 * (Basecamp 10278698087). A file that WordPress loads normally, at the moment
 * it loads templates, leaves every one of those callbacks a turn to replace it.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

Template_Loader::render( Router::rendered_route_data() );
