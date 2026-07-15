<?php
/**
 * Attachment rendering (free).
 *
 * Renders the files on a post or reply UNDERNEATH its content, rather than
 * leaving them inside the body. That is deliberate:
 *
 *   - The body is never rewritten to display an attachment. An importer that has
 *     to mutate content in order to show a file is one bad branch away from
 *     destroying the only reference to that file, which is exactly how the wpForo
 *     migration lost attachments.
 *   - The stored format is the same with Pro and without it, so a site that loses
 *     Pro keeps showing its files. Free shows images inline and every other file
 *     as a download link (name, size, type). Pro enriches individual cards — an
 *     inline PDF preview, for instance — through the `jetonomy_attachment_card`
 *     filter, without changing what is stored or how free falls back.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

use Jetonomy\Models\Attachment;

defined( 'ABSPATH' ) || exit;

class Attachments {

	public function register(): void {
		add_filter( 'jetonomy_after_post_content', array( $this, 'render_post' ), 20, 2 );
		add_filter( 'jetonomy_after_reply_content', array( $this, 'render_reply' ), 20, 2 );

		// The REST payload is FREE too. It used to be injected by Pro, which meant the
		// mobile app saw no attachments at all on a free site — the data was there and
		// simply never left the server. Pro enriches each item through
		// `jetonomy_rest_attachment_data`, so a Pro site's payload is unchanged.
		add_filter( 'jetonomy_rest_prepare_post', array( $this, 'inject_post_payload' ), 10, 2 );
		add_filter( 'jetonomy_rest_prepare_reply', array( $this, 'inject_reply_payload' ), 10, 2 );
	}

	/**
	 * @param array  $data Post payload.
	 * @param object $post Post row.
	 */
	public function inject_post_payload( array $data, $post ): array {
		$data['attachments'] = Attachment::payload_for( 'post', (int) ( $post->id ?? 0 ) );

		return $data;
	}

	/**
	 * @param array  $data  Reply payload.
	 * @param object $reply Reply row.
	 */
	public function inject_reply_payload( array $data, $reply ): array {
		// get_for() is cache-transparent, and the reply list is primed in one query
		// by prime_for_post(), so a page of N replies costs no extra queries.
		$data['attachments'] = Attachment::payload_for( 'reply', (int) ( $reply->id ?? 0 ) );

		return $data;
	}


	/**
	 * @param string $html Existing appended HTML.
	 * @param object $post Post row.
	 */
	public function render_post( string $html, $post ): string {
		if ( empty( $post->id ) ) {
			return $html;
		}

		// One query for every reply on this post, so the reply cards below cost
		// zero further attachment queries.
		Attachment::prime_for_post( (int) $post->id );

		return $html . $this->render( 'post', (int) $post->id );
	}

	/**
	 * @param string $html  Existing appended HTML.
	 * @param object $reply Reply row.
	 */
	public function render_reply( string $html, $reply ): string {
		if ( empty( $reply->id ) ) {
			return $html;
		}

		return $html . $this->render( 'reply', (int) $reply->id );
	}

	private function render( string $object_type, int $object_id ): string {
		$rows = Attachment::get_for( $object_type, $object_id );
		if ( empty( $rows ) ) {
			return '';
		}

		$cards = '';
		foreach ( $rows as $row ) {
			$attachment = Attachment::hydrate( $row );
			if ( null === $attachment ) {
				continue; // Media item is gone; show nothing rather than a broken card.
			}

			$card = $attachment['is_image']
				? $this->image_card( $attachment )
				: $this->file_card( $attachment );

			/**
			 * Filter one attachment's card HTML.
			 *
			 * This is the ONLY seam a richer renderer needs. Pro returns its own card
			 * (lightbox image, inline PDF viewer, typed chip) — so there is exactly one
			 * list renderer in the codebase, not one per plugin, and free's basic card
			 * is the fallback when Pro is absent.
			 *
			 * @param string $card        Card HTML.
			 * @param array  $attachment  Hydrated attachment.
			 * @param string $object_type 'post'|'reply'.
			 * @param int    $object_id   Object id.
			 */
			$cards .= (string) apply_filters( 'jetonomy_attachment_card', $card, $attachment, $object_type, $object_id );
		}

		if ( '' === $cards ) {
			return '';
		}

		/**
		 * Filter the attachment list's wrapper class, so a richer renderer can keep
		 * its own container styling.
		 *
		 * @param string $class Wrapper class.
		 */
		$class = (string) apply_filters( 'jetonomy_attachments_class', 'jt-attachments' );

		return sprintf(
			'<ul class="%1$s" aria-label="%2$s">%3$s</ul>',
			esc_attr( $class ),
			esc_attr__( 'Attachments', 'jetonomy' ),
			$cards
		);
	}

	private function image_card( array $a ): string {
		return sprintf(
			'<li class="jt-attachment jt-attachment--image">
				<a href="%1$s" class="jt-attachment__preview" target="_blank" rel="noopener">
					<img src="%2$s" alt="%3$s" loading="lazy" decoding="async" />
				</a>
			</li>',
			esc_url( $a['url'] ),
			esc_url( $a['thumb'] ?: $a['url'] ),
			esc_attr( $a['name'] )
		);
	}

	/**
	 * Anything that is not an image: name, size, type — enough to decide whether
	 * to click it before downloading it.
	 */
	private function file_card( array $a ): string {
		return sprintf(
			'<li class="jt-attachment jt-attachment--file">
				<a href="%1$s" class="jt-attachment__file" target="_blank" rel="noopener" download>
					<span class="jt-attachment__ext" aria-hidden="true">%2$s</span>
					<span class="jt-attachment__meta">
						<span class="jt-attachment__name">%3$s</span>
						<span class="jt-attachment__size">%4$s</span>
					</span>
				</a>
			</li>',
			esc_url( $a['url'] ),
			esc_html( $a['ext'] ?: 'FILE' ),
			esc_html( $a['name'] ),
			esc_html( $a['size'] ? size_format( $a['size'] ) : '' )
		);
	}
}
