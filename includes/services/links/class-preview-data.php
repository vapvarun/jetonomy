<?php
/**
 * Link preview data — normalized shape returned by the preview service.
 *
 * Exposed over REST at GET /jetonomy/v1/link-preview?url=… and meant to drive
 * both the in-community web card and native mobile clients.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Services\Links;

defined( 'ABSPATH' ) || exit;

final class Preview_Data {

	public string $url          = '';
	public string $original_url = '';
	public string $title        = '';
	public string $description  = '';
	public string $image        = '';
	public string $image_alt    = '';
	public string $site_name    = '';
	public string $domain       = '';
	public string $favicon      = '';
	public string $type         = 'website';
	public string $provider     = '';
	public string $locale       = '';
	public string $published_at = '';
	public string $author       = '';
	public string $embed_html   = '';

	public static function empty_for( string $url ): self {
		$out               = new self();
		$out->url          = $url;
		$out->original_url = $url;
		$out->domain       = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' );
		return $out;
	}

	/**
	 * Serialize for REST / cache / transport. Keys are stable across versions —
	 * mobile clients and extensions rely on this shape.
	 */
	public function to_array(): array {
		return array(
			'url'          => $this->url,
			'original_url' => $this->original_url,
			'title'        => $this->title,
			'description'  => $this->description,
			'image'        => $this->image,
			'image_alt'    => $this->image_alt,
			'site_name'    => $this->site_name,
			'domain'       => $this->domain,
			'favicon'      => $this->favicon,
			'type'         => $this->type,
			'provider'     => $this->provider,
			'locale'       => $this->locale,
			'published_at' => $this->published_at,
			'author'       => $this->author,
			'embed_html'   => $this->embed_html,
		);
	}

	public static function from_array( array $data ): self {
		$out = new self();
		foreach ( get_object_vars( $out ) as $key => $_ ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$out->$key = $data[ $key ];
			}
		}
		return $out;
	}
}
