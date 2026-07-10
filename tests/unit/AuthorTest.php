<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Author;

class AuthorTest extends WP_UnitTestCase {

	private int $uid;

	public function set_up(): void {
		parent::set_up();
		$this->uid = self::factory()->user->create( array( 'display_name' => 'Jane Doe' ) );
	}

	public function test_masks_anonymous_row_for_non_reveal_viewer(): void {
		$row = (object) array( 'author_id' => $this->uid, 'is_anonymous' => 1 );
		$out = Author::for_display( $this->uid, $row );
		$this->assertSame( 0, $out['id'] );
		$this->assertSame( 'Anonymous', $out['name'] );
		$this->assertSame( '', $out['url'] );
		$this->assertSame( '', $out['avatar'] );
	}

	public function test_returns_real_identity_for_unflagged_row(): void {
		$row = (object) array( 'author_id' => $this->uid, 'is_anonymous' => 0 );
		$out = Author::for_display( $this->uid, $row );
		$this->assertSame( $this->uid, $out['id'] );
		$this->assertSame( 'Jane Doe', $out['name'] );
	}

	public function test_reveal_filter_unmasks_flagged_row(): void {
		$row = (object) array( 'author_id' => $this->uid, 'is_anonymous' => 1 );
		add_filter( 'jetonomy_author_can_reveal', '__return_true' );
		$out = Author::for_display( $this->uid, $row );
		remove_filter( 'jetonomy_author_can_reveal', '__return_true' );
		$this->assertSame( $this->uid, $out['id'] );
		$this->assertSame( 'Jane Doe', $out['name'] );
	}

	public function test_null_object_returns_real_identity(): void {
		$out = Author::for_display( $this->uid, null );
		$this->assertSame( $this->uid, $out['id'] );
		$this->assertSame( 'Jane Doe', $out['name'] );
	}
}
