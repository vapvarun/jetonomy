<?php
/**
 * Host-specific link preview provider contract.
 *
 * Providers run before the generic OG parser. They pre-seed host-specific
 * fields (provider key, embed_html for video, richer author/title) using
 * WordPress's built-in oEmbed registry where available, then the service
 * falls through to the generic HTML+OG pipeline to fill any gaps.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Services\Links\Providers;

use Jetonomy\Services\Links\Preview_Data;

defined( 'ABSPATH' ) || exit;

interface Provider_Interface {

	/** Lowercased provider key matching the value returned in Preview_Data::$provider. */
	public function id(): string;

	/** Return true if this provider wants to handle the given URL. */
	public function supports( string $url, string $host ): bool;

	/**
	 * Hydrate as much of $out as the provider can determine without fetching
	 * HTML. Return the (possibly mutated) object — the service will then run
	 * the generic HTML+OG pipeline to fill the rest.
	 */
	public function hydrate( string $url, Preview_Data $out ): Preview_Data;
}
