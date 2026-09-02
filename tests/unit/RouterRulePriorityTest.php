<?php
/**
 * Verifies `prioritize_rules()` puts every Jetonomy route ahead of
 * third-party rewrite rules, and survives the non-array values the
 * `option_rewrite_rules` filter hands it before any rules exist.
 *
 * Background: `add_rewrite_rule( …, 'top' )` only prepends against the rules
 * that exist when it runs, so an SEO plugin registering a broad
 * `-sitemap.xml` catch-all later took precedence and 404'd
 * /community-sitemap.xml on every site running one.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Router;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy\Router::prioritize_rules
 */
class RouterRulePriorityTest extends WP_UnitTestCase {

	private Router $router;

	public function set_up(): void {
		parent::set_up();
		$this->router = new Router();
	}

	public function test_jetonomy_rules_move_ahead_of_third_party_rules(): void {
		$rules = array(
			'sitemap_index\.xml$'                 => 'index.php?sitemap=1',
			'([^/]+?)-sitemap([0-9]+)?\.xml$'     => 'index.php?sitemap=$matches[1]',
			'^community-sitemap\.xml$'            => 'index.php?jetonomy_route=sitemap',
			'^community/?$'                       => 'index.php?jetonomy_route=home',
		);

		$ordered = array_keys( $this->router->prioritize_rules( $rules ) );

		$this->assertSame(
			array( '^community-sitemap\.xml$', '^community/?$' ),
			array_slice( $ordered, 0, 2 ),
			'Jetonomy rules must sort ahead of third-party rules.'
		);
	}

	public function test_relative_order_within_our_own_rules_is_preserved(): void {
		$rules = array(
			'^community/s/([^/]+)/t/([^/]+)/?$' => 'index.php?jetonomy_route=post',
			'^community/s/([^/]+)/?$'           => 'index.php?jetonomy_route=space',
			'other$'                            => 'index.php?other=1',
		);

		$ordered = array_keys( $this->router->prioritize_rules( $rules ) );

		// The more specific post rule was registered first and must stay first,
		// otherwise the looser space rule would swallow every topic URL.
		$this->assertSame( '^community/s/([^/]+)/t/([^/]+)/?$', $ordered[0] );
		$this->assertSame( '^community/s/([^/]+)/?$', $ordered[1] );
	}

	public function test_third_party_rules_keep_their_own_relative_order(): void {
		$rules = array(
			'first$'                   => 'index.php?a=1',
			'^community/?$'            => 'index.php?jetonomy_route=home',
			'second$'                  => 'index.php?b=1',
		);

		$ordered = array_keys( $this->router->prioritize_rules( $rules ) );

		$this->assertSame( array( '^community/?$', 'first$', 'second$' ), $ordered );
	}

	/**
	 * `option_rewrite_rules` fires on every read of the option, including
	 * before any rules exist, when the stored value is an empty string rather
	 * than an array. Declaring an `array` return type here turned an ordinary
	 * empty-option read into a TypeError inside flush_rewrite_rules().
	 *
	 * @dataProvider non_array_values
	 * @param mixed $value Whatever the option currently holds.
	 */
	public function test_non_array_values_pass_through_untouched( $value ): void {
		$this->assertSame( $value, $this->router->prioritize_rules( $value ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public function non_array_values(): array {
		return array(
			'empty string' => array( '' ),
			'false'        => array( false ),
			'null'         => array( null ),
			'serialized'   => array( 'a:0:{}' ),
		);
	}
}
