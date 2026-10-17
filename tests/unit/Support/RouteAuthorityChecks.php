<?php
/**
 * The route-authority gate, shared by Free (tests/unit/RouteAuthorityTest.php)
 * and Pro (tests/pro/RouteAuthorityProTest.php).
 *
 * Each plugin keeps its own manifest (audit/route-authority.json in its own
 * repo) and owns the routes whose HANDLER lives in its own files - Pro
 * registers most of its routes inside jetonomy/v1, so the namespace cannot
 * say whose a route is. See bin/route-authority-scan.php.
 *
 * What it enforces (wp-plugin-release Gate G2):
 *   1. a registered route missing from the manifest fails;
 *   2. a manifest entry with no live route fails, and a conditional entry
 *      must name the toggle that mounts it;
 *   3. the declared permission_callback is the live one (factory + captured
 *      capabilities), "public" means a signed-out caller passes the gate and
 *      nothing else does, and every public route is dispatched signed out and
 *      must answer exactly what its manifest entry says was observed;
 *   4. every route with a probe is dispatched against PRIVATE fixtures (a
 *      topic and reply in a private space, a hidden space, a hidden category)
 *      as a stranger and as a signed-in outsider, and must not return them.
 *
 * Probes fail loudly when they cannot build a request or get a 400 from the
 * argument schema: a request that never reached the permission check proves
 * nothing about it.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit\Support;

use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use WP_REST_Request;

trait Route_Authority_Checks {

	/**
	 * Marker in every private fixture; a body containing it is a leak.
	 *
	 * @var string
	 */
	private static string $secret = 'G2SECRET';

	/** @var array<string, array> Manifest entries keyed "METHODS /route". */
	private array $manifest = array();

	/** @var array<string, array> Live routes owned by this plugin. */
	private array $live = array();

	/** @var array<string, int|string> Fixture ids and values. */
	private array $fx = array();

	/** free|pro: which plugin's routes this test owns. */
	abstract protected function owner(): string;

	/** Absolute path of this plugin's manifest. */
	abstract protected function manifest_file(): string;

	/** Mount everything the manifest declares (Pro: boot every extension). */
	protected function mount(): void {}

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_ROUTE_AUTHORITY_LIB' ) ) {
			define( 'JETONOMY_ROUTE_AUTHORITY_LIB', true );
		}
		require_once JETONOMY_DIR . 'bin/route-authority-scan.php';

		$this->mount();

		// A fresh server, so routes mounted above are registered.
		$GLOBALS['wp_rest_server'] = null;
		$this->live                = jetonomy_route_authority_live( $this->owner() );

		$decoded        = json_decode( (string) file_get_contents( $this->manifest_file() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local manifest file.
		$this->manifest = (array) ( $decoded['routes'] ?? array() );

		// Guest reading on (the default) is the mode "public" is declared for.
		$settings               = (array) get_option( 'jetonomy_settings', array() );
		$settings['guest_read'] = 1;
		update_option( 'jetonomy_settings', $settings );
		$this->assertSame( 'public', \Jetonomy\Visibility::get_mode(), 'Guest reading did not switch on, so every public result below would mean nothing.' );

		// Probes must not reach the network (link previews, oEmbed). Armed last
		// and checked, so a late filter cannot quietly let a request through.
		add_filter( 'pre_http_request', array( self::class, 'block_http' ), PHP_INT_MAX );
		$this->assertSame( 'g2_http_blocked', wp_remote_get( 'https://example.com/' )->get_error_code(), 'The outbound HTTP block is not armed.' );

		$this->seed();
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Refuse every outbound request made while probing.
	 *
	 * @return \WP_Error
	 */
	public static function block_http(): \WP_Error {
		return new \WP_Error( 'g2_http_blocked', 'Outbound HTTP is blocked in the route-authority probe.' );
	}

	/**
	 * One public and one private copy of everything a route can be pointed at.
	 */
	private function seed(): void {
		$secret = self::$secret;

		$author   = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'g2author',
			)
		);
		$outsider = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$cat        = Category::create( array( 'name' => 'G2 open' ) );
		$hidden_cat = Category::create(
			array(
				'name'       => $secret . ' category',
				'visibility' => 'hidden',
			)
		);

		$space         = Space::create( $this->space_row( 'G2 open space', 'public', $cat ) );
		$private_space = Space::create( $this->space_row( 'G2 lounge', 'private', $cat ) );
		$hidden_space  = Space::create( $this->space_row( $secret . ' hidden space', 'hidden', $cat ) );
		foreach ( array( $space, $private_space, $hidden_space ) as $space_id ) {
			SpaceMember::add( $space_id, $author, 'member' );
		}
		Space::create( $this->space_row( $secret . ' space in a hidden category', 'public', $hidden_cat ) );

		$topic         = Post::create( $this->post_row( $space, $author, 'G2 open topic' ) );
		$private_topic = Post::create( $this->post_row( $private_space, $author, $secret . ' topic' ) );
		Post::create( $this->post_row( $hidden_space, $author, $secret . ' hidden topic' ) );

		$reply         = (int) Reply::create( $this->reply_row( $topic, $author, 'G2 open reply' ) );
		$private_reply = (int) Reply::create( $this->reply_row( $private_topic, $author, $secret . ' reply' ) );

		$private_space_row = Space::find( $private_space );
		$private_topic_row = Post::find( $private_topic );

		$this->fx = array(
			'author'           => $author,
			'author_login'     => get_userdata( $author )->user_login,
			'outsider'         => $outsider,
			'admin'            => $admin,
			'category'         => $cat,
			'hidden_category'  => $hidden_cat,
			'space'            => $space,
			'private_space'    => $private_space,
			'hidden_space'     => $hidden_space,
			'post'             => $topic,
			'private_post'     => $private_topic,
			'reply'            => $reply,
			'private_reply'    => $private_reply,
			'private_post_url' => \Jetonomy\route_url( 'post', $private_space_row->slug, $private_topic_row->slug ),
			'since'            => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			'secret'           => $secret,
			'missing'          => 987654321,
		);
	}

	private function space_row( string $title, string $visibility, int $category ): array {
		return array(
			'title'       => $title,
			'slug'        => sanitize_title( $title ) . '-' . wp_generate_password( 6, false, false ),
			'category_id' => $category,
			'visibility'  => $visibility,
		);
	}

	private function post_row( int $space, int $author, string $title ): array {
		return array(
			'space_id'  => $space,
			'author_id' => $author,
			'title'     => $title,
			'slug'      => sanitize_title( $title ) . '-' . wp_generate_password( 6, false, false ),
			'content'   => $title . ' body',
			'status'    => 'publish',
		);
	}

	private function reply_row( int $post, int $author, string $content ): array {
		return array(
			'post_id'   => $post,
			'author_id' => $author,
			'content'   => $content,
			'status'    => 'publish',
		);
	}

	/**
	 * Fixture id a route's object param points at.
	 *
	 * @param string $kind    space|post|reply|category|user, or '' to infer from the path.
	 * @param bool   $private The private variant(s) instead of the public one.
	 * @return int[]
	 */
	private function fixture_ids( string $kind, bool $private ): array {
		$map = array(
			'space'    => $private ? array( $this->fx['hidden_space'], $this->fx['private_space'] ) : array( $this->fx['space'] ),
			'post'     => array( $private ? $this->fx['private_post'] : $this->fx['post'] ),
			'reply'    => array( $private ? $this->fx['private_reply'] : $this->fx['reply'] ),
			'category' => array( $private ? $this->fx['hidden_category'] : $this->fx['category'] ),
			'user'     => array( $this->fx['author'] ),
		);
		return $map[ $kind ] ?? array( $this->fx['missing'] );
	}

	/**
	 * The fixture kind a route's first path segment names.
	 */
	private static function kind_of( string $route ): string {
		$segment = explode( '/', ltrim( $route, '/' ) )[2] ?? '';
		$kinds   = array(
			'spaces'     => 'space',
			'posts'      => 'post',
			'replies'    => 'reply',
			'users'      => 'user',
			'categories' => 'category',
		);
		return $kinds[ $segment ] ?? '';
	}

	/**
	 * Fill a route pattern into a concrete path.
	 *
	 * @param string $route Route regex as registered.
	 * @param int    $id    Value for the route's object id.
	 * @return array{0:string, 1:array<string,string>} Path and its URL params.
	 */
	private function build_path( string $route, int $id ): array {
		$params = array();
		$path   = (string) preg_replace_callback(
			'/\(\?P<([a-z_]+)>([^)]*)\)/',
			function ( array $m ) use ( $id, &$params ): string {
				[ , $name, $pattern ] = $m;
				if ( preg_match( '/^[a-z]+(\|[a-z]+)+$/', $pattern ) ) {
					$value = explode( '|', $pattern )[0];
				} elseif ( 'login' === $name ) {
					$value = (string) $this->fx['author_login'];
				} elseif ( in_array( $name, array( 'id', 'post_id', 'space_id', 'user_id', 'obj_id' ), true ) && ! isset( $params['__first'] ) ) {
					$value             = (string) $id;
					$params['__first'] = '1';
				} elseif ( 'obj_id' === $name ) {
					$value = (string) $this->fx['post'];
				} elseif ( str_ends_with( $name, 'id' ) ) {
					$value = (string) $this->fx['missing'];
				} else {
					$value = 'g2token';
				}
				$params[ $name ] = $value;
				return $value;
			},
			$route
		);
		unset( $params['__first'] );

		$this->assertMatchesRegularExpression( '@^' . $route . '$@i', $path, "Could not build a path for {$route}, so its probe would prove nothing." );
		return array( $path, $params );
	}

	/**
	 * Replace {placeholders} with fixture values, recursively.
	 *
	 * @param mixed $value Manifest probe_params / probe_body.
	 * @return mixed
	 */
	private function fill( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'fill' ), $value );
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( preg_match( '/^\{([a-z_]+)\}$/', $value, $m ) && isset( $this->fx[ $m[1] ] ) ) {
			return $this->fx[ $m[1] ];
		}
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			fn( $m ) => (string) ( $this->fx[ $m[1] ] ?? $m[0] ),
			$value
		);
	}

	/**
	 * Run a route's permission_callback for a user, against a public fixture.
	 *
	 * @return mixed What the callback returned.
	 */
	private function gate( string $key, int $user_id ) {
		$live              = $this->live[ $key ];
		[ $path, $params ] = $this->build_path( $live['route'], $this->fixture_ids( self::kind_of( $live['route'] ), false )[0] );

		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $live['methods'][0], $path );
		$request->set_url_params( $params );
		return call_user_func( $live['callback'], $request );
	}

	/**
	 * Dispatch a route as a user.
	 *
	 * @return array{0:int, 1:string, 2:string} Status, error code ('' when none), JSON body.
	 */
	private function dispatch( string $key, int $user_id, int $id ): array {
		$live  = $this->live[ $key ];
		$entry = $this->manifest[ $key ] ?? array();

		[ $path ] = $this->build_path( $live['route'], $id );

		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $live['methods'][0], $path );
		$request->set_query_params( (array) $this->fill( $entry['probe_params'] ?? array() ) );
		if ( isset( $entry['probe_body'] ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $this->fill( $entry['probe_body'] ) ) );
		}

		$response = rest_do_request( $request );
		$data     = $response->get_data();
		return array(
			$response->get_status(),
			is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '',
			(string) wp_json_encode( $data ),
		);
	}

	/**
	 * The status a refused probe must answer with.
	 *
	 * Owner policy: content the viewer may not know exists answers like missing
	 * (404). Only a publicly listed private space may say 403 instead.
	 *
	 * @param int   $id     Fixture the probe pointed at.
	 * @param int[] $refuse The entry's declared refusal statuses.
	 * @return int[]
	 */
	private function refusal_for( int $id, array $refuse, array $entry ): array {
		if ( (int) $this->fx['private_space'] === $id ) {
			return $refuse;
		}
		// A recorded, reported parity gap: refused with no data, but with a status
		// (the entry's refuse list) that confirms the object exists.
		return empty( $entry['known_gap'] ) ? array( 404 ) : $refuse;
	}

	/**
	 * Every string the probe itself sent, so an echoed query is not read as a leak.
	 *
	 * @param array $entry Manifest entry.
	 * @return string[]
	 */
	private function sent_values( array $entry ): array {
		$values = array();
		$sent   = (array) $this->fill( array( $entry['probe_params'] ?? array(), $entry['probe_body'] ?? array() ) );
		array_walk_recursive(
			$sent,
			static function ( $value ) use ( &$values ): void {
				if ( is_string( $value ) && '' !== $value ) {
					$values[] = trim( (string) wp_json_encode( $value ), '"' );
				}
			}
		);
		return $values;
	}

	public function test_the_registry_and_the_manifest_are_not_empty(): void {
		// Tripwire: an empty walk makes every other test here pass on nothing.
		$this->assertNotEmpty( $this->live, 'No live ' . $this->owner() . ' routes were found, so nothing below tested anything.' );
		$this->assertNotEmpty( $this->manifest, 'The manifest is empty or unreadable: ' . $this->manifest_file() );
	}

	public function test_every_live_route_declares_its_audience(): void {
		$this->assertSame(
			array(),
			array_keys( array_diff_key( $this->live, $this->manifest ) ),
			"These routes are registered but not declared in the route-authority manifest.\n"
			. 'Add each one with its audience (public|member|owner|admin|guarded) and the reason. A route nobody declared is a route nobody reviewed.'
		);
	}

	public function test_no_route_in_our_namespaces_escapes_both_manifests(): void {
		// Ownership is the handler's file. A route whose handler lives in neither
		// plugin (a core function, a third party) would be owned by nobody and
		// checked by nobody, so it fails here instead of passing silently.
		$this->assertSame(
			array(),
			array_keys( jetonomy_route_authority_live( 'other' ) ),
			'These jetonomy/v1 or jetonomy-pro/v1 routes have a handler outside both plugins, so neither manifest can declare them. Give each a handler in the plugin that registers it.'
		);
	}

	public function test_the_manifest_declares_no_route_that_is_gone(): void {
		$problems = array_map(
			static fn( $key ) => $key . ' - declared but not registered. Remove it, or mount what registers it.',
			array_keys( array_diff_key( $this->manifest, $this->live ) )
		);

		foreach ( $this->manifest as $key => $entry ) {
			if ( ! empty( $entry['conditional'] ) && '' === trim( (string) ( $entry['conditional_on'] ?? '' ) ) ) {
				$problems[] = $key . ' - conditional, but names no toggle in conditional_on.';
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	public function test_declared_authority_is_the_one_in_the_source(): void {
		$audiences = array( 'public', 'member', 'owner', 'admin', 'guarded' );
		$problems  = array();

		foreach ( array_intersect_key( $this->manifest, $this->live ) as $key => $entry ) {
			if ( ! in_array( $entry['audience'] ?? '', $audiences, true ) ) {
				$problems[] = $key . ' - audience must be one of ' . implode( '|', $audiences );
			}
			$reason = trim( (string) ( $entry['reason'] ?? '' ) );
			if ( '' === $reason || 0 === stripos( $reason, 'TODO' ) ) {
				$problems[] = $key . ' - no reason. Say who reaches it and what decides that.';
			}
			if ( ( $entry['permission_callback'] ?? '' ) !== $this->live[ $key ]['permission_callback'] ) {
				$problems[] = $key . " - permission_callback changed.\n    declared: " . ( $entry['permission_callback'] ?? '(none)' ) . "\n    live:     " . $this->live[ $key ]['permission_callback'];
			}
		}

		$this->assertSame( array(), $problems, "The manifest no longer describes the code. Re-review each route, then update its entry:\n" . implode( "\n", $problems ) );
	}

	public function test_public_means_a_stranger_passes_the_gate_and_nothing_else_does(): void {
		$problems = array();

		foreach ( array_intersect_key( $this->manifest, $this->live ) as $key => $entry ) {
			$public = 'public' === ( $entry['audience'] ?? '' );
			$passes = true === $this->gate( $key, 0 );

			if ( $this->live[ $key ]['always_true'] && ! $public ) {
				$problems[] = $key . ' - permission_callback always passes, so it must be declared public.';
			}
			if ( $public && ! $passes ) {
				$problems[] = $key . ' - declared public, but a signed-out caller is refused by the gate.';
			}
			if ( ! $public && $passes ) {
				$problems[] = $key . ' - declared ' . ( $entry['audience'] ?? '?' ) . ', but a signed-out caller passes the gate.';
			}
			if ( $public && ! isset( $entry['observed_anon'] ) && empty( $entry['dispatch_exits'] ) ) {
				$problems[] = $key . ' - public with no observed_anon: dispatch it signed out and write down what a stranger gets.';
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	public function test_a_private_community_closes_every_guest_read_route(): void {
		update_option( 'jetonomy_settings', array( 'guest_read' => 0 ) );
		$this->assertSame( 'private', \Jetonomy\Visibility::get_mode() );

		$checked = 0;
		$open    = array();
		foreach ( $this->live as $key => $live ) {
			if ( 'Visibility::rest_check' !== $live['permission_callback'] ) {
				continue;
			}
			++$checked;
			if ( true === $this->gate( $key, 0 ) ) {
				$open[] = $key;
			}
		}

		if ( 'free' === $this->owner() ) {
			$this->assertGreaterThan( 0, $checked, 'No guest-read route was checked.' );
		}
		$this->assertSame( array(), $open, "With guest reading off, a signed-out caller still passes:\n" . implode( "\n", $open ) );
	}

	public function test_staff_routes_refuse_a_member_and_admit_an_administrator(): void {
		$problems = array();
		$checked  = 0;

		foreach ( array_intersect_key( $this->manifest, $this->live ) as $key => $entry ) {
			if ( 'admin' !== ( $entry['audience'] ?? '' ) ) {
				continue;
			}
			++$checked;
			if ( true === $this->gate( $key, (int) $this->fx['outsider'] ) ) {
				$problems[] = $key . ' - declared admin, but a subscriber passes the gate.';
			}
			// Positive control: the request reaches the check at all.
			if ( true !== $this->gate( $key, (int) $this->fx['admin'] ) ) {
				$problems[] = $key . ' - declared admin, but an administrator is refused, so the member check above proves nothing.';
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No admin route was checked.' );
		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	public function test_public_routes_answer_a_stranger_as_observed(): void {
		$problems = array();

		foreach ( array_intersect_key( $this->manifest, $this->live ) as $key => $entry ) {
			if ( 'public' !== ( $entry['audience'] ?? '' ) || ! empty( $entry['dispatch_exits'] ) ) {
				continue;
			}
			$kind              = (string) ( $entry['probe'] ?? '' );
			[ $status, $code ] = $this->dispatch( $key, 0, $this->fixture_ids( '' !== $kind && 'none' !== $kind ? $kind : self::kind_of( $this->live[ $key ]['route'] ), false )[0] );

			if ( in_array( $code, array( 'rest_missing_callback_param', 'rest_invalid_param' ), true ) ) {
				$problems[] = $key . " - {$status} {$code}: the probe never reached the route. Give it schema-valid probe_params / probe_body.";
			} elseif ( (int) ( $entry['observed_anon'] ?? 0 ) !== $status ) {
				$problems[] = $key . ' - a stranger now gets ' . $status . ( $code ? " {$code}" : '' ) . ', the manifest says ' . ( $entry['observed_anon'] ?? '?' ) . '. Re-read the route and update the reason with what was observed.';
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	public function test_private_fixtures_never_reach_a_caller_who_cannot_read_them(): void {
		$problems = array();
		$probed   = 0;

		foreach ( array_intersect_key( $this->manifest, $this->live ) as $key => $entry ) {
			$kind = (string) ( $entry['probe'] ?? '' );
			if ( '' === $kind || 'none' === $kind ) {
				continue;
			}

			$writes = (bool) array_diff( $this->live[ $key ]['methods'], array( 'GET', 'HEAD' ) );
			$actors = array( 'outsider' => (int) $this->fx['outsider'] );
			if ( 'public' === ( $entry['audience'] ?? '' ) ) {
				$actors = array( 'stranger' => 0 ) + $actors;
			}
			$refuse = isset( $entry['refuse'] ) ? array_map( 'intval', (array) $entry['refuse'] ) : null;

			foreach ( $this->fixture_ids( $kind, true ) as $id ) {
				foreach ( $actors as $who => $user_id ) {
					++$probed;
					[ $status, $code, $body ] = $this->dispatch( $key, $user_id, $id );
					$label                    = "{$key} [{$who}, {$kind} #{$id}] {$status}" . ( $code ? " {$code}" : '' );
					$body                     = str_ireplace( $this->sent_values( $entry ), '', $body );
					$allowed                  = null === $refuse || 'user' === $kind ? null : $this->refusal_for( $id, $refuse, $entry );

					if ( in_array( $code, array( 'rest_missing_callback_param', 'rest_invalid_param' ), true ) ) {
						$problems[] = $label . ' - the probe never reached the route. Give it schema-valid probe_params / probe_body.';
					} elseif ( false !== stripos( $body, self::$secret ) ) {
						$problems[] = $label . ' - the private fixture is in the response.';
					} elseif ( null !== $allowed && ! in_array( $status, $allowed, true ) ) {
						$problems[] = $label . ' - expected ' . implode( '/', $allowed ) . '.';
					} elseif ( $writes && $status < 300 && null === $allowed ) {
						$problems[] = $label . ' - a write on a private object succeeded.';
					}
				}
			}
		}

		$this->assertGreaterThan( 0, $probed, 'No route was probed, so this proved nothing.' );
		$this->assertSame( array(), $problems, "A caller reached something they may not read:\n" . implode( "\n", $problems ) );
	}
}
