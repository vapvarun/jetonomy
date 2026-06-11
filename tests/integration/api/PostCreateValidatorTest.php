<?php
/**
 * Integration coverage for POST /jetonomy/v1/spaces/{space_id}/posts.
 *
 * 1.4.3 WS1 changes the title contract:
 *   - Feed spaces accept (and store) an empty title — no synthetic
 *     derivation, the body is the post.
 *   - Every other space type still rejects an empty title with 400.
 *   - Feed-space slugs fall back to a content excerpt so URLs stay
 *     meaningful even with no title.
 *
 * Also locks in regression coverage for Basecamp #9886339472 — the
 * `is_private` flag must round-trip from any compose surface (free
 * page composer, embed) into the stored row.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\API\Posts_Controller;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;

class PostCreateValidatorTest extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	private WP_REST_Server $server;

	private int $category_id;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		( new Posts_Controller() )->register_routes();

		$this->category_id = Category::create(
			array(
				'name' => 'Compose Test',
				'slug' => 'compose-test-' . uniqid(),
			)
		);

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		UserProfile::find_or_create( $this->user_id );
		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function make_space( string $type ): int {
		$space_id = Space::create(
			array(
				'title'       => ucfirst( $type ) . ' space',
				'slug'        => 'compose-' . $type . '-' . uniqid(),
				'category_id' => $this->category_id,
				'visibility'  => 'public',
				'type'        => $type,
			)
		);
		SpaceMember::add( $space_id, $this->user_id, 'member' );
		return $space_id;
	}

	private function dispatch_create( int $space_id, array $body ): \WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/jetonomy/v1/spaces/' . $space_id . '/posts' );
		$req->set_body_params( $body );
		return $this->server->dispatch( $req );
	}

	/* ──────────────────────────────────────────────────────────────────── */

	public function test_feed_space_accepts_empty_title(): void {
		$space_id = $this->make_space( 'feed' );

		$res = $this->dispatch_create(
			$space_id,
			array(
				'title'   => '',
				'content' => 'Just shipped 1.4.3 — the compose pipeline is unified.',
			)
		);

		$this->assertSame( 201, $res->get_status(), 'Feed space should accept empty title and return 201.' );

		$data = (array) $res->get_data();
		$id   = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->assertGreaterThan( 0, $id, 'Response payload must include the new post id.' );

		$row = Post::find( $id );
		$this->assertIsObject( $row, 'Row must exist in storage.' );
		// Contract since 72ad312 (2026-05-20): feed posts with no title get a
		// server-derived headline from the body (<=60 chars) so breadcrumbs,
		// notifications, search results, and OG previews have one. The earlier
		// WS1 empty-title-verbatim contract was deliberately reversed.
		$this->assertSame(
			'Just shipped 1.4.3 — the compose pipeline is unified.',
			(string) $row->title,
			'Short feed body becomes the derived title verbatim.'
		);
	}

	public function test_feed_space_derives_title_from_long_body(): void {
		$space_id = $this->make_space( 'feed' );

		$res = $this->dispatch_create(
			$space_id,
			array(
				'title'   => '',
				// Long body — derived headline must be capped at 60 chars.
				'content' => 'This is a long status update with multiple sentences. It would have been turned into a synthetic title by the old code path. We must not do that anymore.',
			)
		);

		$this->assertSame( 201, $res->get_status() );
		$id  = (int) $res->get_data()['id'];
		$row = Post::find( $id );

		// Contract since 72ad312 (2026-05-20): long feed bodies derive a
		// 60-char headline. Assert the truncation contract precisely.
		$expected = trim( (string) mb_substr( 'This is a long status update with multiple sentences. It would have been turned into a synthetic title by the old code path. We must not do that anymore.', 0, 60 ) );
		$this->assertSame( $expected, (string) $row->title, 'Derived title must be the first 60 chars of the stripped body, trimmed.' );
		$this->assertLessThanOrEqual( 60, mb_strlen( (string) $row->title ) );
	}

	public function test_non_feed_space_rejects_empty_title(): void {
		$space_id = $this->make_space( 'forum' );

		$res = $this->dispatch_create(
			$space_id,
			array(
				'title'   => '',
				'content' => 'Body only — should be rejected on a non-feed space.',
			)
		);

		$this->assertSame( 400, $res->get_status(), 'Non-feed spaces must still require a title.' );
	}

	public function test_feed_post_slug_falls_back_to_content_excerpt(): void {
		$space_id = $this->make_space( 'feed' );

		$res = $this->dispatch_create(
			$space_id,
			array(
				'title'   => '',
				'content' => 'Hello world from the unified composer pipeline.',
			)
		);

		$this->assertSame( 201, $res->get_status() );
		$row = Post::find( (int) $res->get_data()['id'] );

		$this->assertNotEmpty( $row->slug, 'Slug column is NOT NULL; must always be populated.' );
		// Slug should be derived from the first ~40 chars of plain content,
		// sanitised. It must not be empty and must not be the
		// random-stub form unless content slugification truly failed.
		$this->assertNotSame( '', $row->slug );
		$this->assertMatchesRegularExpression(
			'/^hello-world|^post-/',
			(string) $row->slug,
			'Slug should either reflect the content excerpt or be the randomised fallback.'
		);
	}

	public function test_feed_post_image_only_slug_falls_back_to_stub(): void {
		// Content that strips to nothing should hit the randomised stub
		// branch so the INSERT never violates the NOT NULL slug constraint.
		$space_id = $this->make_space( 'feed' );

		$res = $this->dispatch_create(
			$space_id,
			array(
				'title'   => '',
				// Non-empty content (passes the "content required" check)
				// but wp_strip_all_tags reduces it to whitespace.
				'content' => '<span>   </span>',
			)
		);

		// content_plain after strip is empty — depending on the wp_kses_post
		// pass this might 400 with "content required" OR 201. Both states
		// are acceptable for this regression — what we care about is that
		// IF it succeeds, the slug is non-empty.
		if ( 201 === $res->get_status() ) {
			$row = Post::find( (int) $res->get_data()['id'] );
			$this->assertNotEmpty( $row->slug );
		} else {
			$this->assertSame( 400, $res->get_status() );
		}
	}

	/**
	 * Regression coverage for Basecamp #9886339472 — the inline compose-
	 * topic embed was dropping the `is_private` flag, so posts the user
	 * marked private leaked publicly. The shared composePost generator
	 * now collects the flag from both surfaces; the REST controller
	 * stores it verbatim.
	 */
	public function test_embed_payload_propagates_is_private(): void {
		$space_id = $this->make_space( 'forum' );

		$res = $this->dispatch_create(
			$space_id,
			array(
				'title'      => 'Private from the embed',
				'content'    => 'Members only — this must not leak.',
				'is_private' => true,
			)
		);

		$this->assertSame( 201, $res->get_status() );
		$row = Post::find( (int) $res->get_data()['id'] );

		$this->assertSame(
			1,
			(int) $row->is_private,
			'is_private flag must round-trip from the embed payload into storage.'
		);
	}
}
