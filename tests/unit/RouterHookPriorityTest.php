<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Router;

/**
 * The router renders LAST on template_redirect, and asserts its 200 FIRST.
 *
 * handle_request() renders and exits, so whatever priority it holds is the
 * point past which no other template_redirect callback runs at all. At the
 * default 10 that silently swallowed every gate registered after it: a
 * maintenance-mode plugin hooking at 11+ never saw the request and Jetonomy
 * pages stayed public while the rest of the site was closed (Basecamp
 * 10278698087). The same hole applied to any access gate - on the reference
 * install WP Fusion's content restriction sits at 13 and 15 - and to the
 * header emitters core registers at 11.
 *
 * Moving the render late is only half of it. WP_Query::handle_404() can flag a
 * paginated route as a 404, and every 404 handler in the window before us
 * would then act on the stale flag: BuddyX Pro's buddyx_404_redirect() (at 10)
 * would 301 a paginated Jetonomy URL to the theme's custom 404 page. So the
 * not-404 assertion has to LEAD and the render has to TRAIL.
 *
 * These are ordering assertions rather than behaviour assertions on purpose.
 * The behaviour needs a full request (and was verified that way: a maintenance
 * gate at priorities 5 through 100 now blocks, where 11+ previously did not).
 * What a unit test can pin cheaply is the invariant that makes it work, so a
 * later edit cannot quietly restore the default priority.
 */
class RouterHookPriorityTest extends WP_UnitTestCase {

	private Router $router;

	public function set_up(): void {
		parent::set_up();
		// Constructing registers the hooks; the global Router instance has
		// already done so at boot, so assert against the live hook table.
		$this->router = new Router();
	}

	public function test_render_runs_after_any_plausible_gate(): void {
		$priority = has_action( 'template_redirect', array( $this->router, 'handle_request' ) );

		$this->assertNotFalse( $priority, 'handle_request must be registered on template_redirect.' );
		$this->assertGreaterThan(
			15,
			$priority,
			'The render must run after access gates. WP Fusion sits at 13 and 15; a maintenance '
			. 'plugin commonly sits at 11 or 20. Anything at or below those is swallowed, because '
			. 'handle_request exits.'
		);
	}

	public function test_not_404_assertion_runs_before_any_404_handler(): void {
		$priority = has_action( 'template_redirect', array( $this->router, 'assert_route_state' ) );

		$this->assertNotFalse( $priority, 'assert_route_state must be registered on template_redirect.' );
		$this->assertSame(
			0,
			$priority,
			'The not-404 assertion must lead, or a theme 404 redirect at priority 10 acts on a stale flag.'
		);
	}

	/**
	 * The pair is the invariant: assert first, render last, with room between
	 * for everyone else. Asserting the relationship - rather than two magic
	 * numbers - is what survives a deliberate future change to either value.
	 */
	public function test_assertion_leads_and_render_trails(): void {
		$assert = has_action( 'template_redirect', array( $this->router, 'assert_route_state' ) );
		$render = has_action( 'template_redirect', array( $this->router, 'handle_request' ) );

		$this->assertLessThan(
			$render,
			$assert,
			'assert_route_state must run before handle_request, not after it.'
		);
	}
}
