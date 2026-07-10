<?php
namespace Jetonomy\Tests\Pro\Extensions;

/**
 * Shared fixtures for the attachments extension test suite: a real uploaded
 * image attachment, and N replies on a fresh post id, each carrying one
 * linked attachment — used to exercise Model::prime_for_post() batch loading.
 */
trait AttachmentFixtures {

	protected static function img(): int {
		return self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/test-image.png'
		);
	}

	/**
	 * @return array{0:int,1:int[]} [post_id, reply_ids]
	 */
	protected static function seed_replies_with_attachments( int $n ): array {
		global $wpdb;
		$post_id = 6000 + wp_rand( 1, 9999 );
		$ids     = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'jt_replies',
				array(
					'post_id'    => $post_id,
					'author_id'  => 1,
					'content'    => 'x',
					'created_at' => current_time( 'mysql', true ),
				)
			);
			$rid   = (int) $wpdb->insert_id;
			$ids[] = $rid;
			\Jetonomy_Pro\Extensions\Attachments\Model::link( 'reply', $rid, self::img(), 0 );
		}
		return array( $post_id, $ids );
	}
}
