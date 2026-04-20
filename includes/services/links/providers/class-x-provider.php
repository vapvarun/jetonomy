<?php
/**
 * x.com → twitter.com URL rewrite provider.
 *
 * WordPress's oEmbed registry only matches `twitter.com` URLs; `x.com` URLs
 * (the default rebrand) fall through to the generic OG parser and miss the
 * rich tweet card. We rewrite x.com URLs to their twitter.com equivalents
 * before delegating to the oEmbed provider so the result is identical
 * regardless of which hostname the user pasted.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Services\Links\Providers;

use Jetonomy\Services\Links\Preview_Data;

defined( 'ABSPATH' ) || exit;

final class X_Provider implements Provider_Interface {

	private OEmbed_Provider $oembed;

	public function __construct( ?OEmbed_Provider $oembed = null ) {
		$this->oembed = $oembed ?? new OEmbed_Provider();
	}

	public function id(): string {
		return 'twitter';
	}

	public function supports( string $url, string $host ): bool {
		return 'x.com' === $host || str_ends_with( $host, '.x.com' );
	}

	public function hydrate( string $url, Preview_Data $out ): Preview_Data {
		$rewritten = preg_replace( '#^(https?://)(www\.)?x\.com/#i', '$1twitter.com/', $url );
		if ( ! is_string( $rewritten ) || $rewritten === $url ) {
			return $out;
		}

		// Keep `original_url` as what the user typed; `url` becomes the
		// canonical twitter.com version so downstream clients can deep-link
		// consistently.
		$out->original_url = $url;
		$out->url          = $rewritten;
		$out->provider     = 'twitter';
		return $this->oembed->hydrate( $rewritten, $out );
	}
}
