<?php
namespace Jetonomy\Tests\Integration\Import;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use Jetonomy\DB\Schema;
use Jetonomy\Import\Import_Manager;
use Jetonomy\Permissions\Capabilities;

/**
 * REGRESSION (Basecamp 10415690431): the Import screen showed three different
 * "already imported" numbers for one source (2,313 / 2,314 / 2,312). The pre-run
 * note, the completion result, the Last imported line and Past imports, and
 * `wp jetonomy import`, must all count jt_import_map rows.
 */
class ImportCountsAgreeTest extends WP_Ajax_UnitTestCase {

	private int $user;
	private int $forum;

	public function set_up(): void {
		parent::set_up();
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Schema::create_tables();
		delete_option( 'jetonomy_import_history' );

		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account = new \WP_User( $admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		wp_set_current_user( $admin );
		new \Jetonomy\Admin\Ajax\Import_Handler();

		$this->user  = self::factory()->user->create();
		$this->forum = self::factory()->post->create( array( 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'General' ) );
		$this->topic_with_reply( 'First' );
		$this->topic_with_reply( 'Second' );
	}

	private function topic_with_reply( string $title ): void {
		$topic = self::factory()->post->create( array( 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => $this->forum, 'post_author' => $this->user, 'post_title' => $title ) );
		self::factory()->post->create( array( 'post_type' => 'reply', 'post_status' => 'publish', 'post_parent' => $topic, 'post_author' => $this->user, 'post_content' => 'Reply to ' . $title ) );
	}

	/** Drive the Import screen's batches to the end; returns the last response. */
	private function run_import(): array {
		$phase  = 'forums';
		$offset = 0;
		$first  = true;
		do {
			$_POST = array(
				'nonce'      => wp_create_nonce( 'jetonomy_admin' ),
				'source'     => 'bbpress',
				'phase'      => $phase,
				'offset'     => $offset,
				'batch_size' => 2,
				'new_run'    => $first ? 1 : 0,
			);
			try {
				$this->_handleAjax( 'jetonomy_import_batch' );
			} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the JSON response is asserted.
			}
			$res                  = json_decode( $this->_last_response, true );
			$this->_last_response = '';
			$data                 = (array) ( $res['data'] ?? array() );
			$this->assertTrue( $res['success'] ?? false, 'import batch failed: ' . wp_json_encode( $res ) );
			$phase  = (string) $data['phase'];
			$offset = (int) $data['offset'];
			$first  = false;
		} while ( empty( $data['done'] ) );
		return $data;
	}

	/** The N in "This source has been imported before (N items)". */
	private function note_count(): int {
		Import_Manager::init();
		foreach ( Import_Manager::get_importers()['bbpress']->get_import_notes() as $note ) {
			if ( preg_match( '/imported before \(([\d,]+) items?\)/', $note, $m ) ) {
				return (int) str_replace( ',', '', $m[1] );
			}
		}
		return 0;
	}

	private function history(): array {
		return (array) ( get_option( 'jetonomy_import_history' )['bbpress'] ?? array() );
	}

	public function test_note_result_and_history_count_the_same_rows(): void {
		$this->run_import();
		$first = $this->history();
		$this->assertSame( 0, (int) $first['already'], 'a first import reported items as already imported' );
		$this->assertSame( (int) $first['imported'], $this->note_count(), 'Last imported and the pre-run note disagree after the first import' );

		// One new source topic, then the re-run the owner does to pick it up.
		$this->topic_with_reply( 'Third' );
		$before = $this->note_count();
		$done   = $this->run_import();
		$second = $this->history();

		$this->assertSame( $before, (int) $second['already'], '"imported before (N)" and "N already imported" disagree' );
		$this->assertSame( 2, (int) $second['imported'], 'the new topic and its reply' );
		$this->assertStringContainsString( number_format_i18n( $before ) . ' items were already imported', (string) $done['summary'] );
		$this->assertSame( $before + 2, $this->note_count(), 'the next note is not already + imported' );
	}

	public function test_cli_counts_the_same_rows_as_the_screen(): void {
		$this->run_import();
		$this->topic_with_reply( 'Fourth' );
		$before = $this->note_count();

		Import_Manager::init();
		$result = Import_Manager::run( 'bbpress' );

		$this->assertSame( $before, (int) $result['already'] );
		$this->assertSame( 2, (int) $result['imported'] );
	}
}
