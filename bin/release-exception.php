<?php
/**
 * The owner's written exception to the walk-freshness gates, for ONE release.
 *
 * docs/qa/release-exception.json names a version, who approved it, why, and
 * which journeys were walked instead. It applies only while the plugin header
 * Version equals that version, so the next bump restores the strict gate on
 * its own. It waives walk freshness only: a journey's structure (checks,
 * roles, refusals, escape cards) is never waived.
 *
 * @package Jetonomy
 * @return array{version:string,approved:string,by:string,reason:string,walked:string[]}|null
 */
function jetonomy_release_exception( string $root ): ?array {
	$file = $root . '/docs/qa/release-exception.json';
	if ( ! is_file( $file ) || ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $root . '/jetonomy.php' ), $m ) ) {
		return null;
	}
	$ex = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $ex ) || ( $ex['version'] ?? '' ) !== $m[1] || empty( $ex['approved'] ) || empty( $ex['by'] ) || empty( $ex['reason'] ) ) {
		return null;
	}
	return $ex;
}
