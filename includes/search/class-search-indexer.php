<?php
/**
 * Keeps a plugin search adapter's index in step with the community.
 *
 * Search_Adapter::index() and delete() were part of the contract from the
 * start but nothing ever called them, so an external adapter (Elasticsearch,
 * Meilisearch) would have answered from an index that was never filled. Since
 * 2.0.1 REST /search, the search page and the app use a plugin adapter when
 * one is registered (Basecamp 10368526736), so this class feeds it.
 *
 * The built-in MySQL search reads the live tables, so nothing runs while it is
 * the adapter in use.
 *
 * @package Jetonomy
 * @since   2.0.1
 */

namespace Jetonomy\Search;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Adapters\Search_Adapter;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;

/**
 * Forwards topic and reply changes to the active search adapter.
 */
class Search_Indexer {

	/**
	 * Hook the post/reply lifecycle.
	 */
	public static function init(): void {
		// Create fires once a topic or reply is published, including at approval.
		add_action( 'jetonomy_after_create_post', static fn( $id ) => self::sync( 'post', (int) $id ) );
		add_action( 'jetonomy_after_create_reply', static fn( $id ) => self::sync( 'reply', (int) $id ) );
		add_action( 'jetonomy_post_updated', static fn( $id ) => self::sync( 'post', (int) $id ) );
		add_action( 'jetonomy_reply_updated', static fn( $id ) => self::sync( 'reply', (int) $id ) );
		// Approve, trash, close, spam: the row's status changed, re-send it.
		add_action(
			'jetonomy_content_moderated',
			static function ( $action, $type, $id ): void {
				if ( 'post' === $type || 'reply' === $type ) {
					self::sync( (string) $type, (int) $id );
				}
			},
			10,
			3
		);
		add_action( 'jetonomy_after_delete_post', static fn( $id ) => self::remove( 'post', (int) $id ) );
		add_action( 'jetonomy_after_delete_reply', static fn( $id ) => self::remove( 'reply', (int) $id ) );
	}

	/**
	 * The adapter to feed, or null while the built-in search is in use.
	 *
	 * @return Search_Adapter|null
	 */
	private static function adapter(): ?Search_Adapter {
		$adapter = Adapter_Registry::get_search();
		// Exact class: an adapter that extends the built-in one still has its own index.
		return ( $adapter && Fulltext_Search::class !== get_class( $adapter ) ) ? $adapter : null;
	}

	/**
	 * Send the current row. Unpublished rows are removed instead, so a held,
	 * trashed or spam topic never becomes searchable through the adapter.
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Object id.
	 */
	private static function sync( string $type, int $id ): void {
		$adapter = self::adapter();
		if ( ! $adapter || $id <= 0 ) {
			return;
		}
		$row = 'post' === $type ? Post::find( $id ) : Reply::find( $id );
		if ( ! $row || 'publish' !== ( $row->status ?? '' ) ) {
			$adapter->delete( $type, $id );
			return;
		}
		$adapter->index( $type, $id, (array) $row );
	}

	/**
	 * Drop a deleted row from the index.
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Object id.
	 */
	private static function remove( string $type, int $id ): void {
		$adapter = self::adapter();
		if ( $adapter && $id > 0 ) {
			$adapter->delete( $type, $id );
		}
	}
}
