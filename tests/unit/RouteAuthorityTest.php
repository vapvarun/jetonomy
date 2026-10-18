<?php
/**
 * Every Free REST route declares who may reach it, and the code is held to it.
 *
 * The declarations live in audit/route-authority.json; the checks are in
 * Support/RouteAuthorityChecks.php, shared with Pro's RouteAuthorityProTest.
 * Nothing else in the pipeline asks the authorisation question: WPCS,
 * PHPStan, the contract audit and a green browser smoke all pass a route that
 * hands a private topic to a stranger.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Tests\Unit\Support\Route_Authority_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * @covers-existing Release gate G2: pins the authority every route already
 * has. Proven by mutation instead of by the base run - break a permission
 * callback and this class goes red (mutations listed in the commit body).
 */
class RouteAuthorityTest extends WP_UnitTestCase {

	use Route_Authority_Checks;

	protected function owner(): string {
		return 'free';
	}

	protected function manifest_file(): string {
		return JETONOMY_DIR . 'audit/route-authority.json';
	}
}
