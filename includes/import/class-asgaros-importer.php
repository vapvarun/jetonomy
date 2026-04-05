<?php
/**
 * Asgaros Forum importer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post as JtPost;
use Jetonomy\Models\Reply as JtReply;
use Jetonomy\Models\UserProfile;
use function Jetonomy\now;

class Asgaros_Importer extends Importer {

	public function get_source_name(): string {
		return 'Asgaros Forum';
	}

	public function is_source_available(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'forum_forums' )
		);
	}

	public function get_source_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;
		return [
			'forums' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}forum_forums" ),
			'topics' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}forum_topics" ),
			'posts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}forum_posts" ),
		];
	}

	public function get_total_count(): int {
		global $wpdb;
		$p      = $wpdb->prefix;
		$forums = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}forum_forums" );
		$topics = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}forum_topics" );
		$posts  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}forum_posts" );
		return $forums + $topics + $posts;
	}

	public function run_batch( string $phase, int $offset, int $batch_size ): array {
		// TODO: implement batched import for Asgaros.
		// For now, fall through to run() via a single-shot batch.
		if ( 'forums' === $phase && 0 === $offset ) {
			$this->run();
			return [
				'phase'     => 'complete',
				'offset'    => 0,
				'done'      => true,
				'processed' => $this->imported,
			];
		}
		return [
			'phase'     => 'complete',
			'offset'    => 0,
			'done'      => true,
			'processed' => 0,
		];
	}

	public function run( array $options = [] ): array {
		$cat_id = Category::create(
			[
				'name' => __( 'Imported from Asgaros', 'jetonomy' ),
				'slug' => 'imported-asgaros',
			]
		);

		$this->import_forums( $cat_id );
		$this->import_topics();
		$this->import_replies();
		$this->create_profiles();
		$this->recount();

		return $this->results();
	}

	private function import_forums( int $cat_id ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$forums = $wpdb->get_results(
			"SELECT * FROM {$p}forum_forums ORDER BY sort ASC, id ASC"
		);

		$ordered = $this->sort_by_dependency( $forums );

		foreach ( $ordered as $forum ) {
			$parent_space_id = 0;
			if ( (int) $forum->parent_forum > 0 ) {
				$mapped = $this->get_mapped_id( 'forum', (int) $forum->parent_forum );
				if ( $mapped ) {
					$parent_space_id = $mapped;
				}
			}

			$space_id = Space::create(
				[
					'category_id' => $cat_id,
					'parent_id'   => $parent_space_id,
					'author_id'   => 1,
					'type'        => 'forum',
					'title'       => $forum->name,
					'slug'        => sanitize_title( $forum->name ) ?: 'forum-' . $forum->id,
					'description' => wp_strip_all_tags( $forum->description ?? '' ),
					'visibility'  => 'public',
					'join_policy' => 'open',
					'sort_order'  => (int) ( $forum->sort ?? 0 ),
				]
			);

			if ( $space_id ) {
				$this->map_id( 'forum', (int) $forum->id, $space_id );
				++$this->imported;
			} else {
				$this->log_error( 'forum', $forum->id, 'Failed to create space' );
				++$this->skipped;
			}
		}
	}

	/**
	 * Sort forums so parents always appear before their children.
	 *
	 * @param object[] $forums
	 * @return object[]
	 */
	private function sort_by_dependency( array $forums ): array {
		$indexed  = [];
		$children = [];

		foreach ( $forums as $forum ) {
			$indexed[ $forum->id ] = $forum;
			if ( 0 === (int) $forum->parent_forum ) {
				$children[0][] = $forum->id;
			} else {
				$children[ $forum->parent_forum ][] = $forum->id;
			}
		}

		$ordered = [];
		$queue   = $children[0] ?? [];

		while ( ! empty( $queue ) ) {
			$id = array_shift( $queue );
			if ( isset( $indexed[ $id ] ) ) {
				$ordered[] = $indexed[ $id ];
				if ( ! empty( $children[ $id ] ) ) {
					array_splice( $queue, 0, 0, $children[ $id ] );
				}
			}
		}

		foreach ( $forums as $forum ) {
			if ( ! in_array( $forum, $ordered, true ) ) {
				$ordered[] = $forum;
			}
		}

		return $ordered;
	}

	private function import_topics(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$topics = $wpdb->get_results(
			"SELECT * FROM {$p}forum_topics ORDER BY id ASC"
		);

		if ( empty( $topics ) ) {
			return;
		}

		$topic_ids      = wp_list_pluck( $topics, 'id' );
		$placeholders   = implode( ',', array_fill( 0, count( $topic_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$first_posts_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fp.* FROM {$p}forum_posts fp
				 INNER JOIN (
				     SELECT parent_id, MIN(id) AS first_id
				     FROM {$p}forum_posts
				     WHERE parent_id IN ({$placeholders})
				     GROUP BY parent_id
				 ) f ON fp.id = f.first_id",
				...$topic_ids
			)
		);
		$first_posts_map = [];
		foreach ( $first_posts_raw as $fp ) {
			$first_posts_map[ (int) $fp->parent_id ] = $fp;
		}

		foreach ( $topics as $topic ) {
			$space_id = $this->get_mapped_id( 'forum', (int) $topic->parent_id );
			if ( ! $space_id ) {
				$this->log_error( 'topic', $topic->id, "Parent forum {$topic->parent_id} not imported" );
				++$this->skipped;
				continue;
			}

			$first_post = $first_posts_map[ (int) $topic->id ] ?? null;
			$content    = $first_post ? $first_post->text : '';

			$status = ( isset( $topic->approved ) && 1 === (int) $topic->approved ) ? 'publish' : 'pending';

			$post_id = JtPost::create(
				[
					'space_id'      => $space_id,
					'author_id'     => (int) ( $topic->author_id ?? 1 ),
					'type'          => 'topic',
					'title'         => $topic->name,
					'slug'          => sanitize_title( $topic->name ) ?: 'topic-' . $topic->id,
					'content'       => wp_kses_post( $content ),
					'content_plain' => wp_strip_all_tags( $content ),
					'status'        => $status,
					'is_sticky'     => (int) ( $topic->sticky ?? 0 ),
					'is_closed'     => (int) ( $topic->closed ?? 0 ),
					'created_at'    => $first_post->date ?? now(),
				]
			);

			if ( is_wp_error( $post_id ) ) {
				$this->log_error( 'topic', $topic->id, $post_id->get_error_message() );
				++$this->skipped;
				continue;
			}

			if ( $post_id ) {
				$this->map_id( 'topic', (int) $topic->id, $post_id );
				if ( $first_post ) {
					$this->map_id( 'asgaros_post_skip', (int) $first_post->id, 0 );
				}
				++$this->imported;
			} else {
				$this->log_error( 'topic', $topic->id, 'Failed to create post' );
				++$this->skipped;
			}
		}
	}

	private function import_replies(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$posts = $wpdb->get_results(
			"SELECT p.* FROM {$p}forum_posts p
			 INNER JOIN (
			     SELECT parent_id AS topic_id, MIN(id) AS first_id
			     FROM {$p}forum_posts
			     GROUP BY parent_id
			 ) f ON p.parent_id = f.topic_id
			 WHERE p.id != f.first_id
			 ORDER BY p.id ASC"
		);

		foreach ( $posts as $asgaros_post ) {
			$post_id = $this->get_mapped_id( 'topic', (int) $asgaros_post->parent_id );
			if ( ! $post_id ) {
				++$this->skipped;
				continue;
			}

			$reply_id = JtReply::create(
				[
					'post_id'       => $post_id,
					'parent_id'     => null,
					'author_id'     => (int) ( $asgaros_post->author_id ?? 1 ),
					'content'       => wp_kses_post( $asgaros_post->text ),
					'content_plain' => wp_strip_all_tags( $asgaros_post->text ),
					'status'        => 'publish',
					'created_at'    => $asgaros_post->date ?? now(),
				]
			);

			if ( is_wp_error( $reply_id ) ) {
				$this->log_error( 'reply', $asgaros_post->id, $reply_id->get_error_message() );
				++$this->skipped;
				continue;
			}

			if ( $reply_id ) {
				$this->map_id( 'asgaros_reply', (int) $asgaros_post->id, $reply_id );
				++$this->imported;
			} else {
				$this->log_error( 'reply', $asgaros_post->id, 'Failed to create reply' );
				++$this->skipped;
			}
		}
	}

	private function create_profiles(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$ids = $wpdb->get_col(
			"SELECT DISTINCT author_id FROM {$p}forum_topics WHERE author_id > 0
			 UNION
			 SELECT DISTINCT author_id FROM {$p}forum_posts WHERE author_id > 0"
		);

		foreach ( $ids as $uid ) {
			$this->ensure_profile( (int) $uid );
		}
	}

	private function recount(): void {
		global $wpdb;
		$pt = \Jetonomy\table( 'posts' );
		$rt = \Jetonomy\table( 'replies' );
		$st = \Jetonomy\table( 'spaces' );

		$wpdb->query( "UPDATE {$pt} p SET p.reply_count    = (SELECT COUNT(*)   FROM {$rt} r WHERE r.post_id  = p.id AND r.status = 'publish')" );
		$wpdb->query( "UPDATE {$st} s SET s.post_count     = (SELECT COUNT(*)   FROM {$pt} p WHERE p.space_id = s.id AND p.status = 'publish')" );
		$wpdb->query( "UPDATE {$pt} p SET p.last_reply_at  = (SELECT MAX(r.created_at) FROM {$rt} r WHERE r.post_id = p.id AND r.status = 'publish')" );
	}
}
