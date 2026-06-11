<?php
/**
 * Tests the AI Batch_Reviewer: watermark baselining (no historical sweep),
 * staff/trusted exemption (never sent to the model), batched verdict
 * application through the system actor, and zero-API no-op when nothing
 * new exists.
 *
 * The AI_Client is stubbed so no real provider is contacted; the stub
 * records every chat() call so batching behaviour is assertable.
 *
 * Skipped automatically when Jetonomy Pro is not active.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;

class AiBatchReviewerTest extends WP_UnitTestCase {

	private int $member_id;
	private int $admin_id;
	private int $space_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active — AI batch reviewer tests skipped.' );
		}

		require_once JETONOMY_PRO_DIR . 'includes/extensions/ai/class-usage-tracker.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/ai/class-ai-client.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/ai/class-moderator.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/ai/class-batch-reviewer.php';

		Schema::create_tables();
		delete_option( \Jetonomy_Pro\Extensions\Ai\Batch_Reviewer::STATE_OPTION );

		$this->member_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$cat            = Category::create( [ 'name' => 'AI Test Cat', 'slug' => 'ai-test-cat-' . wp_rand() ] );
		$this->space_id = (int) Space::create(
			[
				'category_id' => (int) $cat,
				'title'       => 'AI Test Space',
				'slug'        => 'ai-test-space-' . wp_rand(),
				'type'        => 'forum',
				'visibility'  => 'public',
			]
		);
		$this->post_id = (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->member_id,
				'title'     => 'Seed topic',
				'slug'      => 'seed-topic-' . wp_rand(),
				'content'   => '<p>Seed</p>',
				'status'    => 'publish',
			]
		);
	}

	/**
	 * Build a reviewer whose client returns the given verdicts and records calls.
	 *
	 * @param array $verdict_items items array the stub returns for every chat call.
	 * @param array $calls         By-ref recorder of chat() invocations.
	 */
	private function make_reviewer( array $verdict_items, array &$calls ) {
		$tracker = new \Jetonomy_Pro\Extensions\Ai\Usage_Tracker( [] );
		$client  = new class( $verdict_items, $calls, $tracker ) extends \Jetonomy_Pro\Extensions\Ai\AI_Client {
			private array $items;
			private $recorder;
			public function __construct( array $items, array &$calls, $tracker ) {
				parent::__construct( [], $tracker );
				$this->items    = $items;
				$this->recorder = &$calls;
			}
			public function is_available(): bool {
				return true;
			}
			public function chat( string $feature, array $messages, array $options = [], array $context = [] ): array {
				$this->recorder[] = $messages;
				return [ 'content' => wp_json_encode( [ 'items' => $this->items ] ) ];
			}
		};

		return new \Jetonomy_Pro\Extensions\Ai\Batch_Reviewer(
			$client,
			[
				'spam_detection'     => [ 'enabled' => true, 'threshold' => 0.8, 'hold_threshold' => 0.5 ],
				'content_moderation' => [ 'enabled' => true ],
			]
		);
	}

	public function test_first_run_baselines_watermark_without_calling_model(): void {
		$calls    = [];
		$reviewer = $this->make_reviewer( [], $calls );

		$reviewer->run();

		$state = get_option( \Jetonomy_Pro\Extensions\Ai\Batch_Reviewer::STATE_OPTION );
		$this->assertIsArray( $state );
		$this->assertGreaterThanOrEqual( $this->post_id, (int) $state['post'] );
		$this->assertCount( 0, $calls, 'Baseline run must not contact the model.' );
	}

	public function test_no_new_content_means_zero_model_calls(): void {
		$calls    = [];
		$reviewer = $this->make_reviewer( [], $calls );
		$reviewer->run(); // baseline
		$reviewer->run(); // nothing new

		$this->assertCount( 0, $calls );
	}

	public function test_member_reply_is_reviewed_and_spam_verdict_applied(): void {
		$calls    = [];
		$reviewer = $this->make_reviewer( [], $calls );
		$reviewer->run(); // baseline

		$reply_id = (int) Reply::create(
			[
				'post_id'   => $this->post_id,
				'author_id' => $this->member_id,
				'content'   => '<p>Buy cheap pills now!!!</p>',
				'status'    => 'publish',
			]
		);

		$calls2   = [];
		$reviewer = $this->make_reviewer(
			[
				[
					'key'        => 'reply:' . $reply_id,
					'spam'       => true,
					'action'     => null,
					'confidence' => 0.95,
					'reason'     => 'pharma spam',
				],
			],
			$calls2
		);
		$reviewer->run();

		$this->assertCount( 1, $calls2, 'One new item = exactly one batched model call.' );
		$reply = Reply::find( $reply_id );
		$this->assertSame( 'spam', $reply->status, 'High-confidence spam verdict marks the reply spam.' );
	}

	public function test_multiple_items_share_one_model_call(): void {
		$calls    = [];
		$reviewer = $this->make_reviewer( [], $calls );
		$reviewer->run(); // baseline

		$ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = (int) Reply::create(
				[
					'post_id'   => $this->post_id,
					'author_id' => $this->member_id,
					'content'   => '<p>Reply number ' . $i . '</p>',
					'status'    => 'publish',
				]
			);
		}

		$calls2   = [];
		$reviewer = $this->make_reviewer( [], $calls2 );
		$reviewer->run();

		$this->assertCount( 1, $calls2, '5 new replies must be classified in a single batched call, not 5.' );
		// All ids appear in the prompt.
		$prompt = $calls2[0][1]['content'];
		foreach ( $ids as $id ) {
			$this->assertStringContainsString( 'reply:' . $id, $prompt );
		}
	}

	public function test_admin_content_is_never_sent_to_model(): void {
		$calls    = [];
		$reviewer = $this->make_reviewer( [], $calls );
		$reviewer->run(); // baseline

		Reply::create(
			[
				'post_id'   => $this->post_id,
				'author_id' => $this->admin_id,
				'content'   => '<p>Admin housekeeping note.</p>',
				'status'    => 'publish',
			]
		);

		$calls2   = [];
		$reviewer = $this->make_reviewer( [], $calls2 );
		$reviewer->run();

		$this->assertCount( 0, $calls2, 'Staff content must be exempt from AI review.' );
	}

	public function test_trusted_member_is_exempt(): void {
		$calls    = [];
		$reviewer = $this->make_reviewer( [], $calls );
		$reviewer->run(); // baseline

		UserProfile::find_or_create( $this->member_id );
		UserProfile::update_profile( $this->member_id, [ 'trust_level' => 3 ] );

		Reply::create(
			[
				'post_id'   => $this->post_id,
				'author_id' => $this->member_id,
				'content'   => '<p>Trusted member reply.</p>',
				'status'    => 'publish',
			]
		);

		$calls2   = [];
		$reviewer = $this->make_reviewer( [], $calls2 );
		$reviewer->run();

		$this->assertCount( 0, $calls2, 'Trust level 3+ must be exempt from AI review.' );
	}

	public function test_flag_verdict_creates_flag_and_keeps_content_live(): void {
		$calls    = [];
		$reviewer = $this->make_reviewer( [], $calls );
		$reviewer->run(); // baseline

		$reply_id = (int) Reply::create(
			[
				'post_id'   => $this->post_id,
				'author_id' => $this->member_id,
				'content'   => '<p>Borderline content.</p>',
				'status'    => 'publish',
			]
		);

		$calls2   = [];
		$reviewer = $this->make_reviewer(
			[
				[
					'key'        => 'reply:' . $reply_id,
					'spam'       => false,
					'action'     => 'flag',
					'confidence' => 0.7,
					'reason'     => 'borderline',
				],
			],
			$calls2
		);
		$reviewer->run();

		$reply = Reply::find( $reply_id );
		$this->assertSame( 'publish', $reply->status, 'Flagged content stays live.' );

		global $wpdb;
		$flags = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COUNT(*) FROM ' . \Jetonomy\table( 'flags' ) . ' WHERE object_type = %s AND object_id = %d',
				'reply',
				$reply_id
			)
		);
		$this->assertSame( 1, $flags, 'A flag row surfaces the item in the moderation queue.' );
	}
}
