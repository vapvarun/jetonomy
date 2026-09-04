<?php
/**
 * SSRF guard for outbound link-preview fetches.
 *
 * WordPress's wp_http_validate_url() / wp_safe_remote_get() block RFC1918 (10/8, 172.16/12,
 * 192.168/16) and loopback, but NOT 169.254.0.0/16 (cloud metadata endpoint),
 * 100.64.0.0/10 (CGNAT), or IPv6 loopback / ULA / link-local. The link-preview
 * endpoint is reachable unauthenticated in public mode, so a crafted or
 * redirected URL could reach internal services. This guard resolves the host to
 * every address the fetch can actually reach and rejects the request if any of
 * them is private or reserved. The fetcher applies it to every redirect hop,
 * not just the first, and pins the fetch to IPv4 so that "every address we
 * checked" and "every address cURL may connect to" are the same set — see
 * {@see resolve_host()} for why the check is IPv4-only.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Services\Links;

defined( 'ABSPATH' ) || exit;

final class Url_Guard {

	/**
	 * Validate that a URL is safe to fetch server-side.
	 *
	 * @param string $url Absolute http(s) URL.
	 * @return true|\WP_Error True when safe, WP_Error (status 400) otherwise.
	 */
	public static function check_remote_url( string $url ) {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return self::error();
		}

		$host = (string) ( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return self::error();
		}

		$ips = self::resolve_host( $host );
		if ( empty( $ips ) ) {
			// Unresolvable host — fail closed.
			return self::error();
		}

		foreach ( $ips as $ip ) {
			if ( ! self::ip_is_public( $ip ) ) {
				return self::error();
			}
		}

		return true;
	}

	/**
	 * Whether an IP is a globally-routable (public) address.
	 *
	 * @param string $ip IPv4 or IPv6 literal.
	 * @return bool
	 */
	public static function ip_is_public( string $ip ): bool {
		// Rejects private (10/8, 172.16/12, 192.168/16, fc00::/7) AND reserved
		// (0/8, 127/8, 169.254/16, 192.0.2/24, ::1, fe80::/10, ::ffff:0:0/96, …).
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		// 100.64.0.0/10 (RFC 6598 shared address space) is not covered by
		// FILTER_FLAG_NO_RES_RANGE on every PHP build — reject it explicitly.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			if ( false !== $long && ( $long & 0xFFC00000 ) === ( ip2long( '100.64.0.0' ) & 0xFFC00000 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve a host to every IPv4 address it maps to.
	 *
	 * Uses the SYSTEM resolver (getaddrinfo), never dns_get_record().
	 * dns_get_record() speaks raw DNS to the servers in /etc/resolv.conf,
	 * bypassing the OS resolver and its cache, and takes NO timeout argument.
	 * When those servers are unreachable from the PHP process — a VPN,
	 * split-horizon DNS, a container, or macOS, where the real resolver config
	 * lives in mDNSResponder and resolv.conf is a stub — it blocks for the full
	 * libresolv retry budget with no way to bound it. Measured under php-fpm on
	 * a working machine: 30s for DNS_A and another 30s for DNS_AAAA, against
	 * 0.02s for gethostbynamel() on the same host in the same process.
	 *
	 * That is not a slow response, it is a wedged one. This guard runs on
	 * /link-preview, which is reachable unauthenticated in public mode, and
	 * HTML_Fetcher re-runs it on every redirect hop — so a handful of requests
	 * pinned every worker in the pool until the web server killed them.
	 * Whatever the resolver situation on a given host, an unbounded blocking
	 * call does not belong on that path.
	 *
	 * IPv4 only, and that is deliberate: gethostbynamel() is the only portable
	 * builtin that returns EVERY address through the system resolver, and
	 * socket_addrinfo_lookup() needs ext-sockets plus AF_UNSPEC (PHP 8.3+).
	 * The AAAA blind spot that would otherwise open is closed at the other end
	 * instead — HTML_Fetcher pins the fetch to IPv4, so the addresses vetted
	 * here are the only ones the request can reach. Neither half is safe alone;
	 * change one and you must change the other.
	 *
	 * @see \Jetonomy\Services\Links\HTML_Fetcher::fetch()
	 *
	 * @param string $host Hostname or IP literal (IPv6 may be bracketed).
	 * @return string[] List of IP strings (possibly empty).
	 */
	private static function resolve_host( string $host ): array {
		$bare = trim( $host, '[]' );
		if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return array( $bare );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS lookups legitimately fail; the caller fails closed on an empty list.
		$ips = @gethostbynamel( $host );

		return is_array( $ips ) ? array_values( array_unique( $ips ) ) : array();
	}

	/**
	 * The single, non-enumerating error returned for every blocked URL so a
	 * caller can't distinguish "private IP" from "bad scheme" from "no DNS".
	 *
	 * @return \WP_Error
	 */
	private static function error(): \WP_Error {
		return new \WP_Error(
			'jetonomy_blocked_url',
			__( 'That URL could not be fetched.', 'jetonomy' ),
			array( 'status' => 400 )
		);
	}
}
