<?php
/**
 * route_url() and Router::add_rewrite_rules() describe the same URLs twice:
 * once as a segment map, once as regexes. This keeps them from drifting.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Router;
use function Jetonomy\route_url;
use function Jetonomy\base_url;

defined( 'ABSPATH' ) || exit;

/**
 * @covers ::Jetonomy\route_url
 */
class RouteUrlTest extends WP_UnitTestCase {

	/**
	 * Every route key with sample arguments.
	 *
	 * @return array<string,array{0:string,1:array<int,string|int>}>
	 */
	public function routes(): array {
		$keys = array(
			'connect-app'      => array(),
			'category'         => array( 'general' ),
			'space'            => array( 'help' ),
			'space-members'    => array( 'help' ),
			'space-roadmap'    => array( 'help' ),
			'space-moderation' => array( 'help' ),
			'new-post'         => array( 'help' ),
			'edit-space'       => array( 'help' ),
			'space-feed'       => array( 'help' ),
			'post'             => array( 'help', 'a-topic' ),
			'profile'          => array( 'jane' ),
			'edit-profile'     => array( 'me' ),
			'notifications'    => array(),
			'search'           => array(),
			'leaderboard'      => array(),
			'moderation'       => array(),
			'my-spaces'        => array(),
			'subscriptions'    => array(),
			'drafts'           => array(),
			'bookmarks'        => array(),
			'new-space'        => array(),
			'tag'              => array( 'api' ),
			'invite'           => array( 'abc123' ),
			'messages'         => array(),
			'conversation'     => array( 7 ),
		);
		$out  = array();
		foreach ( $keys as $key => $args ) {
			$out[ $key ] = array( $key, $args );
		}
		return $out;
	}

	/**
	 * @dataProvider routes
	 */
	public function test_every_route_url_is_served_by_its_own_rewrite_rule( string $route, array $args ): void {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();
		( new Router() )->add_rewrite_rules();

		$rules = array_filter(
			$wp_rewrite->extra_rules_top,
			static fn( $query ) => 1 === preg_match( '/[?&]jetonomy_route=' . preg_quote( $route, '/' ) . '(&|$)/', $query )
		);
		if ( ! $rules && in_array( $route, array( 'messages', 'conversation' ), true ) ) {
			$this->markTestSkipped( 'Messaging rules are only registered with Pro active.' );
		}

		$url  = route_url( $route, ...$args );
		$path = substr( $url, strlen( home_url( '/' ) ) );

		$this->assertStringStartsWith( base_url() . '/', $url );
		$this->assertStringEndsWith( '/', $url );
		$matched = array_filter( array_keys( $rules ), static fn( $regex ) => 1 === preg_match( '#' . $regex . '#', $path ) );
		$this->assertNotEmpty( $matched, "route_url( '{$route}' ) built '{$path}', which no {$route} rewrite rule matches." );
	}

	public function test_known_shapes(): void {
		$this->assertSame( base_url() . '/s/help/t/a-topic/', route_url( 'post', 'help', 'a-topic' ) );
		$this->assertSame( base_url() . '/mod/', route_url( 'moderation' ) );
	}

	public function test_unknown_route_returns_empty(): void {
		$this->setExpectedIncorrectUsage( 'Jetonomy\route_url' );
		$this->assertSame( '', route_url( 'nope' ) );
	}
}
