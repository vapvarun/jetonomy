<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

/**
 * Refusal parity on the write routes (owner policy): a topic, reply or space
 * the viewer may not know exists answers exactly like a missing id, and only
 * a viewer who can see it gets the route's 403 for lacking the role.
 *
 * The route-authority gate proves the 404 half for every route; this file
 * holds the controls it cannot: readers still get 403, a listed private space
 * still says 403, and a ban is still named.
 */
class RefusalParityTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $author;
	private int $member;
	private int $outsider;
	private int $private_space;
	private int $hidden_space;
	private int $topic;
	private int $reply;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$admin          = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$cat                 = (int) Category::create( array( 'name' => 'Parity', 'slug' => 'parity-' . uniqid() ) );
		$this->private_space = $this->space( $cat, 'private', $admin );
		$this->hidden_space  = $this->space( $cat, 'hidden', $admin );
		foreach ( array( $this->private_space, $this->hidden_space ) as $space ) {
			SpaceMember::add( $space, $this->author, 'member' );
			SpaceMember::add( $space, $this->member, 'member' );
		}

		$this->topic = (int) Post::create(
			array(
				'space_id'  => $this->private_space,
				'author_id' => $this->author,
				'title'     => 'Lounge topic',
				'slug'      => 'lounge-topic-' . uniqid(),
				'content'   => 'Members only.',
				'status'    => 'publish',
			)
		);
		$this->reply = (int) Reply::create(
			array(
				'post_id'   => $this->topic,
				'author_id' => $this->author,
				'content'   => 'A members-only reply.',
				'status'    => 'publish',
			)
		);
		\Jetonomy\Cache::flush();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function space( int $cat, string $visibility, int $owner ): int {
		return (int) Space::create(
			array(
				'title'       => ucfirst( $visibility ) . ' space',
				'slug'        => $visibility . '-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => $visibility,
				'type'        => 'forum',
			),
			$owner
		);
	}

	/**
	 * @return array{0:int, 1:string, 2:string} Status, error code, message.
	 */
	private function call( int $user_id, string $method, string $path, array $body = array() ): array {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, '/jetonomy/v1' . $path );
		$request->set_body_params( $body );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		return array(
			$response->get_status(),
			(string) ( $data['code'] ?? '' ),
			(string) ( $data['message'] ?? '' ),
		);
	}

	public function test_a_private_topic_answers_an_outsider_exactly_like_a_missing_one(): void {
		$missing = 987654321;
		$routes  = array(
			array( 'PATCH', '/posts/%d', array( 'title' => 'Edited title here' ) ),
			array( 'DELETE', '/posts/%d', array() ),
			array( 'POST', '/posts/%d/close', array() ),
			array( 'POST', '/posts/%d/pin', array() ),
			array( 'POST', '/posts/%d/idea-status', array( 'idea_status' => 'planned' ) ),
			array( 'POST', '/posts/%d/replies', array( 'content' => 'Let me in.' ) ),
		);
		foreach ( $routes as [ $method, $path, $body ] ) {
			$this->assertSame(
				$this->call( $this->outsider, $method, sprintf( $path, $missing ), $body ),
				$this->call( $this->outsider, $method, sprintf( $path, $this->topic ), $body ),
				"{$method} {$path}: a private-space topic must answer an outsider like a missing id."
			);
		}

		[ $status, $code ] = $this->call( $this->outsider, 'PATCH', '/replies/' . $this->reply, array( 'content' => 'Edited reply.' ) );
		$this->assertSame( array( 404, 'jetonomy_not_found' ), array( $status, $code ) );
	}

	/** @covers-existing Guard: the new 404 rule must not swallow the 403 a reader without the role already got. */
	public function test_a_member_who_can_read_the_topic_still_gets_403_for_the_role(): void {
		// Control: the 404 above is concealment, not a guard that refuses everyone.
		[ $status, $code ] = $this->call( $this->member, 'PATCH', '/posts/' . $this->topic, array( 'title' => 'Not my topic to edit' ) );
		$this->assertSame( array( 403, 'jetonomy_forbidden' ), array( $status, $code ) );

		[ $status ] = $this->call( $this->member, 'PATCH', '/replies/' . $this->reply, array( 'content' => 'Not my reply.' ) );
		$this->assertSame( 403, $status );

		// The type check still runs for a reader: this is not an Ideas space.
		[ $status, $code ] = $this->call( $this->member, 'POST', '/posts/' . $this->topic . '/idea-status', array( 'idea_status' => 'planned' ) );
		$this->assertSame( array( 400, 'jetonomy_not_ideas_space' ), array( $status, $code ) );

		// And the author still edits.
		[ $status ] = $this->call( $this->author, 'PATCH', '/posts/' . $this->topic, array( 'title' => 'Lounge topic, edited' ) );
		$this->assertSame( 200, $status );
	}

	public function test_a_hidden_space_is_404_and_a_listed_private_one_403(): void {
		foreach ( array( array( 'PATCH', '' ), array( 'DELETE', '' ), array( 'POST', '/invite' ), array( 'GET', '/invites' ) ) as [ $method, $suffix ] ) {
			[ $status ] = $this->call( $this->outsider, $method, "/spaces/{$this->hidden_space}{$suffix}" );
			$this->assertSame( 404, $status, "{$method} hidden{$suffix}" );

			[ $status ] = $this->call( $this->outsider, $method, "/spaces/{$this->private_space}{$suffix}" );
			$this->assertSame( 403, $status, "{$method} listed private{$suffix}" );
		}

		[ $status, $code ] = $this->call( $this->outsider, 'POST', "/spaces/{$this->hidden_space}/members" );
		$this->assertSame( array( 404, 'jetonomy_not_found' ), array( $status, $code ) );

		[ $status, $code ] = $this->call( $this->outsider, 'POST', "/spaces/{$this->hidden_space}/posts", array( 'title' => 'Knock knock', 'content' => 'Anyone home?' ) );
		$this->assertSame( array( 404, 'jetonomy_not_found' ), array( $status, $code ) );
	}

	/** @covers-existing Guard: the new 404 rule must not hide the named ban message banned members already got. */
	public function test_a_banned_member_is_told_why_for_every_id_alike(): void {
		Restriction::ban( $this->author, 'global_ban', 1 );
		\Jetonomy\Cache::flush();

		$own     = $this->call( $this->author, 'PATCH', '/posts/' . $this->topic, array( 'title' => 'Still mine' ) );
		$missing = $this->call( $this->author, 'PATCH', '/posts/987654321', array( 'title' => 'Still mine' ) );

		$this->assertSame( 'jetonomy_user_banned', $own[1], 'A ban is named, not hidden behind a 404.' );
		$this->assertSame( $own, $missing, 'The ban answers every id alike, so it cannot test which exist.' );
	}
}
