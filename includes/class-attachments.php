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
	}

	/**
	 * Should free draw the attachments?
	 *
	 * Pro ships a richer strip (lightbox, inline PDF preview, type badges) and turns
	 * this off so exactly ONE renderer runs — otherwise every attachment would appear
	 * twice. The DATA is identical either way, which is the whole point: a site that
	 * loses Pro keeps showing its files, just more plainly.
	 */
	private function enabled(): bool {
		/**
		 * Filter whether free renders the attachment list.
		 *
		 * @param bool $enabled Default true. Pro returns false and renders its own.
		 */
		return (bool) apply_filters( 'jetonomy_render_attachments', true );
	}

	/**
	 * @param string $html Existing appended HTML.
	 * @param object $post Post row.
	 */
	public function render_post( string $html, $post ): string {
		if ( empty( $post->id ) || ! $this->enabled() ) {
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
		if ( empty( $reply->id ) || ! $this->enabled() ) {
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
			 * Filter a single attachment card.
			 *
			 * Pro uses this to upgrade a card in place (e.g. an inline PDF preview)
			 * without changing the stored data or free's fallback.
			 *
			 * @param string $card       Card HTML.
			 * @param array  $attachment Hydrated attachment.
			 * @param string $object_type 'post'|'reply'.
			 * @param int    $object_id   Object id.
			 */
			$cards .= (string) apply_filters( 'jetonomy_attachment_card', $card, $attachment, $object_type, $object_id );
		}

		if ( '' === $cards ) {
			return '';
		}

		return sprintf(
			'<ul class="jt-attachments" aria-label="%s">%s</ul>',
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
