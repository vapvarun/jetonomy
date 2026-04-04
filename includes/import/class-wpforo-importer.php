<?php
/**
 * wpForo importer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post as JtPost;
use Jetonomy\Models\Reply as JtReply;
use Jetonomy\Models\Vote;
use Jetonomy\Models\UserProfile;
use function Jetonomy\now;

class WPForo_Importer extends Importer {

	public function get_source_name(): string {
		return 'wpForo';
	}

	public function is_source_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wpforo_forums';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function get_source_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;
		return [
			'forums' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_forums" ),
			'topics' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_topics" ),
			'posts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts" ),
		];
	}

	public function get_total_count(): int {
		global $wpdb;
		$p      = $wpdb->prefix;
		$forums = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_forums" );
		$topics = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_topics" );
		$posts  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts" );
		return $forums + $topics + $posts;
	}

	public function run_batch( string $phase, int $offset, int $batch_size ): array {
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
		global $wpdb;

		$boards_table = $wpdb->prefix . 'wpforo_boards';
		$boards       = $wpdb->get_results( "SELECT boardid, title FROM {$boards_table} WHERE status = 1 ORDER BY boardid ASC" );

		if ( empty( $boards ) ) {
			$boards = [ (object) [ 'boardid' => 0, 'title' => 'Forums' ] ];
		}

		foreach ( $boards as $board ) {
			$board_id = (int) $board->boardid;
			$prefix   = $wpdb->prefix . 'wpforo' . ( $board_id ? $board_id . '_' : '_' );

			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . 'forums' ) ) ) {
				continue;
			}

			$cat_name = count( $boards ) > 1
				/* translators: %s: wpForo board name */
				? sprintf( __( 'Imported from wpForo -- %s', 'jetonomy' ), $board->title )
				: __( 'Imported from wpForo', 'jetonomy' );

			$cat_slug = count( $boards ) > 1
				? 'imported-wpforo-' . sanitize_title( $board->title )
				: 'imported-wpforo';

			$cat_id = Category::create(
				[
					'name' => $cat_name,
					'slug' => $cat_slug,
				]
			);

			$this->import_forums( $cat_id, $prefix );
			$this->import_topics( $prefix );
			$this->import_replies( $prefix );
			$this->import_likes( $prefix );
			$this->create_profiles( $prefix );
		}

		$this->recount();

		return $this->results();
	}

	private function import_forums( int $cat_id, string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		$forums = $wpdb->get_results( "SELECT * FROM {$p}forums ORDER BY `order` ASC" );

		foreach ( $forums as $forum ) {
			$space_id = Space::create(
				[
					'category_id' => $cat_id,
					'author_id'   => 1,
					'type'        => 'forum',
					'title'       => $forum->title,
					'slug'        => $forum->slug ?: sanitize_title( $forum->title ),
					'description' => wp_strip_all_tags( $forum->description ?? '' ),
					'visibility'  => 'public',
					'join_policy' => 'open',
					'sort_order'  => (int) $forum->order,
				]
			);

			if ( $space_id ) {
				$this->map_id( 'forum', $forum->forumid, $space_id );
				++$this->imported;
			} else {
				++$this->skipped;
			}
		}
	}

	private function import_topics( string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		$topics = $wpdb->get_results( "SELECT * FROM {$p}topics ORDER BY topicid ASC" );

		if ( empty( $topics ) ) {
			return;
		}

		$topic_ids    = wp_list_pluck( $topics, 'topicid' );
		$placeholders = implode( ',', array_fill( 0, count( $topic_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$first_posts_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fp.* FROM {$p}posts fp
				 INNER JOIN (
				     SELECT topicid, MIN(postid) AS first_postid
				     FROM {$p}posts
				     WHERE topicid IN ({$placeholders})
				     GROUP BY topicid
				 ) f ON fp.postid = f.first_postid",
				...$topic_ids
			)
		);
		$first_posts_map = [];
		foreach ( $first_posts_raw as $fp ) {
			$first_posts_map[ (int) $fp->topicid ] = $fp;
		}

		foreach ( $topics as $topic ) {
			$space_id = $this->get_mapped_id( 'forum', $topic->forumid );
			if ( ! $space_id ) {
				++$this->skipped;
				continue;
			}

			$is_sticky = 0;
			if ( isset( $topic->type ) && 1 === (int) $topic->type ) {
				$is_sticky = 1;
			}

			$first_post = $first_posts_map[ (int) $topic->topicid ] ?? null;
			$content    = $first_post ? $first_post->body : '';

			$post_id = JtPost::create(
				[
					'space_id'      => $space_id,
					'author_id'     => (int) $topic->userid,
					'type'          => 'topic',
					'title'         => $topic->title,
					'slug'          => $topic->slug ?: sanitize_title( $topic->title ),
					'content'       => wp_kses_post( $content ),
					'content_plain' => wp_strip_all_tags( $content ),
					'status'        => ( 0 === (int) ( $topic->status ?? 0 ) ) ? 'publish' : 'pending',
					'is_sticky'     => $is_sticky,
					'is_closed'     => (int) ( $topic->closed ?? 0 ),
					'created_at'    => $topic->created ?? now(),
				]
			);

			if ( $post_id ) {
				$this->map_id( 'topic', $topic->topicid, $post_id );
				if ( $first_post ) {
					$this->map_id( 'wpforo_post', $first_post->postid, 0 );
				}
				++$this->imported;
			} else {
				++$this->skipped;
			}
		}
	}

	private function import_replies( string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		$posts = $wpdb->get_results(
			"SELECT p.* FROM {$p}posts p
			 INNER JOIN (
			     SELECT topicid, MIN(postid) as first_postid FROM {$p}posts GROUP BY topicid
			 ) fp ON p.topicid = fp.topicid
			 WHERE p.postid != fp.first_postid
			 ORDER BY p.postid ASC"
		);

		foreach ( $posts as $wf_post ) {
			$post_id = $this->get_mapped_id( 'topic', $wf_post->topicid );
			if ( ! $post_id ) {
				++$this->skipped;
				continue;
			}

			$parent_id = null;
			if ( ! empty( $wf_post->parentid ) && (int) $wf_post->parentid > 0 ) {
				$parent_id = $this->get_mapped_id( 'wpforo_reply', $wf_post->parentid );
			}

			$reply_id = JtReply::create(
				[
					'post_id'       => $post_id,
					'parent_id'     => $parent_id,
					'author_id'     => (int) $wf_post->userid,
					'content'       => wp_kses_post( $wf_post->body ),
					'content_plain' => wp_strip_all_tags( $wf_post->body ),
					'status'        => 'publish',
					'created_at'    => $wf_post->created ?? now(),
				]
			);

			if ( $reply_id ) {
				$this->map_id( 'wpforo_reply', $wf_post->postid, $reply_id );
				++$this->imported;
			} else {
				++$this->skipped;
			}
		}
	}

	private function import_likes( string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		$likes_table = $p . 'likes';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $likes_table ) ) ) {
			return;
		}

		$likes = $wpdb->get_results( "SELECT * FROM {$likes_table}" );

		foreach ( $likes as $like ) {
			$reply_id = $this->get_mapped_id( 'wpforo_reply', $like->postid );
			if ( ! $reply_id ) {
				continue;
			}

			Vote::cast( (int) $like->userid, 'reply', $reply_id, 1 );
			++$this->imported;
		}
	}

	private function create_profiles( string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		$profiles_table = $p . 'profiles';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles_table ) ) ) {
			return;
		}

		$profiles = $wpdb->get_results( "SELECT * FROM {$profiles_table}" );

		foreach ( $profiles as $prof ) {
			$this->ensure_profile( (int) $prof->userid );
		}
	}

	private function recount(): void {
		global $wpdb;
		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );
		$spaces_t  = \Jetonomy\table( 'spaces' );

		$wpdb->query( "UPDATE {$posts_t} p SET p.reply_count = (SELECT COUNT(*) FROM {$replies_t} r WHERE r.post_id = p.id AND r.status = 'publish')" );
		$wpdb->query( "UPDATE {$spaces_t} s SET s.post_count = (SELECT COUNT(*) FROM {$posts_t} p WHERE p.space_id = s.id AND p.status = 'publish')" );
		$wpdb->query( "UPDATE {$posts_t} p SET p.last_reply_at = (SELECT MAX(r.created_at) FROM {$replies_t} r WHERE r.post_id = p.id AND r.status = 'publish')" );
	}
}
