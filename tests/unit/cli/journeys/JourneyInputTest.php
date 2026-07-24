<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journeys\Content_Journey;
use Jetonomy\CLI\Journeys\Journey_Input;
use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\CLI\Journeys\Taxonomy_Journey;
use Jetonomy\Models\Category;
use Jetonomy\DB\Schema;

/**
 * The create journeys must REJECT payload keys they do not understand.
 *
 * They build their payload key by key, so anything unrecognised used to be
 * dropped in silence while the write still reported success. That is exactly
 * how the importer's backdating shipped looking finished: buddynext-importer
 * sent `created_at` for months, three journeys never read it, nothing ever
 * errored, and every migrated space/topic/reply carried the migration run time
 * instead of its real date (BuddyNext card 10124307318).
 *
 * These tests pin the loud failure so the same class of bug cannot return.
 */
class JourneyInputTest extends WP_UnitTestCase {

	private int $category_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$suffix            = uniqid( 'ji_', true );
		$this->category_id = (int) Category::create(
			[
				'name' => 'Input Guard Category',
				'slug' => 'input-guard-' . $suffix,
			]
		);
	}

	public function test_unknown_keys_lists_only_the_unrecognised_ones(): void {
		$unknown = Journey_Input::unknown_keys(
			[
				'title'      => 'a',
				'creaetd_at' => 'b',
				'colour'     => 'c',
			],
			[ 'title', 'created_at' ]
		);

		$this->assertSame( [ 'creaetd_at', 'colour' ], $unknown );
	}

	public function test_unknown_keys_is_empty_for_a_clean_payload(): void {
		$this->assertSame(
			[],
			Journey_Input::unknown_keys( [ 'title' => 'a', 'created_at' => 'b' ], [ 'title', 'created_at' ] )
		);
	}

	public function test_error_names_the_offending_and_the_accepted_keys(): void {
		$message = Journey_Input::error( [ 'title' => 'a', 'nope' => 1 ], [ 'title', 'slug' ] );

		$this->assertStringContainsString( 'nope', $message );
		$this->assertStringContainsString( 'title, slug', $message );
	}

	public function test_space_create_rejects_a_mistyped_created_at(): void {
		$result = ( new Space_Journey() )->create(
			[
				'title'       => 'Typo space',
				'slug'        => 'typo-space-' . uniqid(),
				'category_id' => $this->category_id,
				// The exact failure mode this guard exists for.
				'creaetd_at'  => '2016-01-01 00:00:00',
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'creaetd_at', implode( ' ', $result->errors ) );
	}

	public function test_space_create_still_accepts_the_real_backdate_seam(): void {
		$result = ( new Space_Journey() )->create(
			[
				'title'       => 'Backdated space',
				'slug'        => 'backdated-space-' . uniqid(),
				'category_id' => $this->category_id,
				'created_at'  => '2016-01-01 00:00:00',
			]
		);

		$this->assertTrue( $result->is_success(), implode( ' ', $result->errors ) );
	}

	public function test_content_create_post_rejects_an_unknown_key(): void {
		$result = ( new Content_Journey() )->create_post(
			[
				'space_id'  => 1,
				'author_id' => 1,
				'title'     => 'x',
				'content'   => 'y',
				'nonsense'  => true,
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'nonsense', implode( ' ', $result->errors ) );
	}

	public function test_taxonomy_create_category_rejects_an_unknown_key(): void {
		$result = ( new Taxonomy_Journey() )->create_category(
			[
				'name'   => 'x',
				'slug'   => 'x-' . uniqid(),
				'parent' => 3, // The accepted key is parent_id.
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'parent', implode( ' ', $result->errors ) );
	}

	public function test_the_filter_restores_the_lenient_behaviour(): void {
		add_filter( 'jetonomy_journey_strict_input', '__return_false' );

		$result = ( new Space_Journey() )->create(
			[
				'title'       => 'Lenient space',
				'slug'        => 'lenient-space-' . uniqid(),
				'category_id' => $this->category_id,
				'creaetd_at'  => 'ignored',
			]
		);

		remove_filter( 'jetonomy_journey_strict_input', '__return_false' );

		$this->assertTrue( $result->is_success(), implode( ' ', $result->errors ) );
	}
}
