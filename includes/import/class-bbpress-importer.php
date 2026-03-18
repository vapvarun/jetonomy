<?php
namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post as JtPost;
use Jetonomy\Models\Reply as JtReply;
use Jetonomy\Models\UserProfile;
use function Jetonomy\now;

class BBPress_Importer extends Importer {

	public function get_source_name(): string {
		return 'bbPress';
	}

	public function is_source_available(): bool {
		global $wpdb;
		// Check if bbPress post types exist
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('forum', 'topic', 'reply')"
		);
		return $count > 0;
	}

	public function get_source_stats(): array {
		global $wpdb;
		return [
			'forums'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'publish'" ),
			'topics'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'publish'" ),
			'replies' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status = 'publish'" ),
		];
	}

	public function run( array $options = [] ): array {
		// 1. Create a default category for imported forums
		if ( ! $this->dry_run ) {
			$cat_id = Category::create( [
				'name'        => __( 'Imported from bbPress', 'jetonomy' ),
				'slug'        => 'imported-bbpress',
				'description' => __( 'Forums imported from bbPress', 'jetonomy' ),
			] );
		} else {
			$cat_id = 0; // Simulate
		}

		// 2. Import forums as spaces
		$this->import_forums( $cat_id );

		// 3. Import topics as posts
		$this->import_topics();

		// 4. Import replies
		$this->import_replies();

		// 5. Create user profiles for all authors (skip in dry-run)
		if ( ! $this->dry_run ) {
			$this->create_profiles();
		}

		// 6. Recount all denormalized fields (skip in dry-run)
		if ( ! $this->dry_run ) {
			$this->recount();
		}

		return $this->results();
	}

	private function import_forums( int $cat_id ): void {
		global $wpdb;

		$forums = $wpdb->get_results(
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'publish' ORDER BY menu_order ASC, ID ASC"
		);

		foreach ( $forums as $forum ) {
			if ( ! $this->dry_run ) {
				$space_id = Space::create( [
					'category_id' => $cat_id,
					'author_id'   => (int) $forum->post_author ?: 1,
					'type'        => 'forum',
					'title'       => $forum->post_title,
					'slug'        => $forum->post_name ?: sanitize_title( $forum->post_title ),
					'description' => wp_strip_all_tags( $forum->post_content ),
					'visibility'  => 'public',
					'join_policy' => 'open',
				] );
			} else {
				$space_id = 0; // Simulate
			}

			if ( $space_id || $this->dry_run ) {
				$this->map_id( 'forum', $forum->ID, $space_id );
				$this->imported++;
			} else {
				$this->log_error( 'forum', $forum->ID, 'Failed to create space' );
				$this->skipped++;
			}
		}
	}

	private function import_topics(): void {
		global $wpdb;

		$topics = $wpdb->get_results(
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'publish' ORDER BY ID ASC"
		);

		foreach ( $topics as $topic ) {
			$forum_id = (int) $topic->post_parent;
			$space_id = $this->get_mapped_id( 'forum', $forum_id );

			if ( ! $space_id ) {
				$this->log_error( 'topic', $topic->ID, "Parent forum {$forum_id} not imported" );
				$this->skipped++;
				continue;
			}

			$is_sticky = (int) get_post_meta( $topic->ID, '_bbp_topic_sticky', true );

			if ( ! $this->dry_run ) {
				$post_id = JtPost::create( [
					'space_id'      => $space_id,
					'author_id'     => (int) $topic->post_author,
					'type'          => 'topic',
					'title'         => $topic->post_title,
					'slug'          => $topic->post_name ?: sanitize_title( $topic->post_title ),
					'content'       => wp_kses_post( $topic->post_content ),
					'content_plain' => wp_strip_all_tags( $topic->post_content ),
					'status'        => 'publish',
					'is_sticky'     => $is_sticky ? 1 : 0,
					'created_at'    => $topic->post_date_gmt ?: now(),
				] );
			} else {
				$post_id = 0; // Simulate
			}

			if ( $post_id || $this->dry_run ) {
				$this->map_id( 'topic', $topic->ID, $post_id );
				$this->imported++;
			} else {
				$this->log_error( 'topic', $topic->ID, 'Failed to create post' );
				$this->skipped++;
			}
		}
	}

	private function import_replies(): void {
		global $wpdb;

		$replies = $wpdb->get_results(
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status = 'publish' ORDER BY ID ASC"
		);

		foreach ( $replies as $reply ) {
			// bbPress reply's post_parent is the topic ID
			$topic_id = (int) $reply->post_parent;
			$post_id  = $this->get_mapped_id( 'topic', $topic_id );

			if ( ! $post_id ) {
				// Try grandparent (nested reply)
				$this->skipped++;
				continue;
			}

			if ( ! $this->dry_run ) {
				$reply_id = JtReply::create( [
					'post_id'       => $post_id,
					'author_id'     => (int) $reply->post_author,
					'content'       => wp_kses_post( $reply->post_content ),
					'content_plain' => wp_strip_all_tags( $reply->post_content ),
					'status'        => 'publish',
					'created_at'    => $reply->post_date_gmt ?: now(),
				] );
			} else {
				$reply_id = 0; // Simulate
			}

			if ( $reply_id || $this->dry_run ) {
				$this->map_id( 'reply', $reply->ID, $reply_id );
				$this->imported++;
			} else {
				$this->skipped++;
			}
		}
	}

	private function create_profiles(): void {
		global $wpdb;

		$author_ids = $wpdb->get_col(
			"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ('topic', 'reply') AND post_status = 'publish' AND post_author > 0"
		);

		foreach ( $author_ids as $uid ) {
			$this->ensure_profile( (int) $uid );
		}
	}

	private function recount(): void {
		global $wpdb;
		$posts_table   = \Jetonomy\table( 'posts' );
		$replies_table = \Jetonomy\table( 'replies' );
		$spaces_table  = \Jetonomy\table( 'spaces' );

		// Recount reply counts on posts
		$wpdb->query( "UPDATE {$posts_table} p SET p.reply_count = (SELECT COUNT(*) FROM {$replies_table} r WHERE r.post_id = p.id AND r.status = 'publish')" );

		// Recount post counts on spaces
		$wpdb->query( "UPDATE {$spaces_table} s SET s.post_count = (SELECT COUNT(*) FROM {$posts_table} p WHERE p.space_id = s.id AND p.status = 'publish')" );

		// Update last_reply_at on posts
		$wpdb->query( "UPDATE {$posts_table} p SET p.last_reply_at = (SELECT MAX(r.created_at) FROM {$replies_table} r WHERE r.post_id = p.id AND r.status = 'publish')" );
	}
}
