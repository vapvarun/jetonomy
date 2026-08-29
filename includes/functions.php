<?php
/**
 * Global helper functions.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

function table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'jt_' . $name;
}

/**
 * Get the community base URL (e.g. http://forums.local/discussion).
 *
 * Reads the `base_slug` from jetonomy_settings (default: 'community').
 * Every template and PHP class should call this instead of hardcoding /community/.
 *
 * @return string Base URL without trailing slash.
 */
function base_url(): string {
	$settings  = get_option( 'jetonomy_settings', [] );
	$base_slug = $settings['base_slug'] ?? 'community';
	return home_url( '/' . $base_slug );
}

/**
 * Get the URL of the page currently being requested.
 *
 * Jetonomy routes are virtual (rendered by the Router without a real WP post),
 * so `get_permalink()` inside a route template does NOT return the current URL -
 * it resolves to whatever stray `$post` the main query left behind (typically
 * the site's front page or first post). Any "return here after X" URL - most
 * importantly the login redirect on a logged-out topic/reply - must be built
 * from the actual request URI instead. This is the single source of truth for
 * "where am I", mirroring the expression already used in Template_Loader's
 * auth-gate redirects.
 *
 * @return string Absolute URL of the current request.
 */
function current_url(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw sanitizes.
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	return home_url( esc_url_raw( $request_uri ) );
}

/**
 * The renamable product nouns and their translated defaults [singular, plural].
 *
 * Product NOUNS only - never verbs like Save/Delete. Called at runtime (not a
 * static const) so __() resolves after the textdomain is loaded.
 *
 * @return array<string, array{0:string,1:string}>
 */
function label_defaults(): array {
	return array(
		'space'    => array( __( 'Space', 'jetonomy' ), __( 'Spaces', 'jetonomy' ) ),
		'topic'    => array( __( 'Topic', 'jetonomy' ), __( 'Topics', 'jetonomy' ) ),
		'reply'    => array( __( 'Reply', 'jetonomy' ), __( 'Replies', 'jetonomy' ) ),
		'member'   => array( __( 'Member', 'jetonomy' ), __( 'Members', 'jetonomy' ) ),
		'category' => array( __( 'Category', 'jetonomy' ), __( 'Categories', 'jetonomy' ) ),
	);
}

/**
 * The site owner's label for a product noun (Space, Topic, Reply, Member,
 * Category), e.g. Space -> Forum/Channel, Topic -> Thread, Member -> Player.
 *
 * Single source of truth for the nouns so an owner can rename them everywhere
 * from Settings → General. Reads {noun}_label_singular / {noun}_label_plural
 * from jetonomy_settings and falls back to the translated default. Both forms
 * are stored explicitly - we never auto-pluralise, because that breaks the
 * moment an owner types an irregular noun ("Person" -> "Persons").
 *
 * A custom label bypasses translation (a German site with an English custom
 * label renders mixed language). That trade is inherent to the feature and is
 * called out in the settings field help.
 *
 * @param string $noun   One of: space, topic, reply, member, category. Unknown
 *                        nouns fall back to 'space' rather than fatal.
 * @param bool   $plural True for the plural form.
 * @param bool   $lower  True to lowercase (mid-sentence use, e.g. "join this space").
 * @return string
 */
function jetonomy_label( string $noun, bool $plural = false, bool $lower = false ): string {
	$defaults = label_defaults();
	$noun     = strtolower( $noun );
	if ( ! isset( $defaults[ $noun ] ) ) {
		$noun = 'space';
	}

	$settings = get_option( 'jetonomy_settings', array() );
	$key      = $noun . ( $plural ? '_label_plural' : '_label_singular' );
	$custom   = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';

	$label = '' !== $custom ? $custom : $defaults[ $noun ][ $plural ? 1 : 0 ];

	/**
	 * Filter any product-noun label.
	 *
	 * @param string $label  Resolved label.
	 * @param string $noun   The noun key (space|topic|reply|member|category).
	 * @param bool   $plural Plural form requested.
	 * @param bool   $lower  Lowercase requested.
	 */
	$label = (string) apply_filters( 'jetonomy_label', $label, $noun, $plural, $lower );

	// Back-compat: the Space label predates the generic filter and shipped its
	// own. Keep it firing so existing jetonomy_space_label consumers still work.
	if ( 'space' === $noun ) {
		/**
		 * Filter the Space label. $plural/$lower give context for per-surface tweaks.
		 *
		 * @param string $label  Resolved label.
		 * @param bool   $plural Plural form requested.
		 * @param bool   $lower  Lowercase requested.
		 */
		$label = (string) apply_filters( 'jetonomy_space_label', $label, $plural, $lower );
	}

	return $lower ? mb_strtolower( $label ) : $label;
}

/**
 * The site owner's label for a "Space". Back-compat wrapper over jetonomy_label()
 * kept so all existing space_label() call sites (150+) keep working untouched.
 *
 * @param bool $plural True for the plural form.
 * @param bool $lower  True to lowercase (for mid-sentence use, e.g. "join this space").
 * @return string
 */
function space_label( bool $plural = false, bool $lower = false ): string {
	return jetonomy_label( 'space', $plural, $lower );
}

function now(): string {
	return current_time( 'mysql', true );
}

/**
 * The verb a space type uses for "create something here".
 *
 * Single source of truth for the compose label. The same four strings used to
 * be written out separately in the composer heading, the space CTA button, the
 * BuddyPress tab CTA and (type-blind, so wrong on three of the four types) the
 * browser tab title — a Q&A space offered "Ask a Question" on the page while
 * the tab said "Start a discussion".
 *
 * @param string $space_type Space type: qa, ideas, feed, or anything else (forum).
 * @return string
 */
function compose_label( string $space_type ): string {
	switch ( $space_type ) {
		case 'qa':
			$label = __( 'Ask a Question', 'jetonomy' );
			break;
		case 'ideas':
			$label = __( 'Share an Idea', 'jetonomy' );
			break;
		case 'feed':
			$label = __( 'New Status', 'jetonomy' );
			break;
		default:
			/* translators: %s: the singular topic label the site owner configured (e.g. Topic, Thread). */
			$label = sprintf( __( 'New %s', 'jetonomy' ), jetonomy_label( 'topic' ) );
			break;
	}

	/**
	 * Filter the compose label for a space type.
	 *
	 * @param string $label      Resolved label.
	 * @param string $space_type The space type it was resolved for.
	 */
	return (string) apply_filters( 'jetonomy_compose_label', $label, $space_type );
}

/**
 * The post type a space type creates.
 *
 * Paired with {@see compose_label()} so the two never drift apart.
 *
 * @param string $space_type Space type: qa, ideas, feed, or anything else (forum).
 * @return string
 */
function compose_post_type( string $space_type ): string {
	$map = array(
		'qa'    => 'question',
		'ideas' => 'idea',
		'feed'  => 'status',
	);
	return $map[ $space_type ] ?? 'topic';
}

/**
 * Format a stored UTC MySQL datetime as a UTC ISO-8601 instant with a literal `Z`.
 *
 * All Jetonomy datetime columns are written via {@see now()} (`current_time('mysql', true)`),
 * i.e. already GMT/UTC. This is therefore a pure reformat with no offset math: the value
 * is reinterpreted as UTC and rendered as `Y-m-d\TH:i:s\Z` (e.g. `2026-06-13T05:17:42Z`).
 *
 * Serializers emit this as an additive `*_gmt` field so the app can format relative-then-
 * absolute time in the site owner's WordPress timezone client-side. The transport contract is
 * UTC ISO-8601 with `Z`; do NOT convert to site-local here (that is the display layer's job,
 * per docs/standards/datetime-timezone.md).
 *
 * `gmdate('c')` is deliberately avoided because it emits `+00:00` rather than the `Z` the
 * cross-plugin timestamp standard specifies.
 *
 * @param string|null $utc_mysql Stored UTC datetime (`Y-m-d H:i:s`), or null/empty/zero-date.
 * @return string|null ISO-8601 UTC string ending in `Z`, or null when the input is empty.
 */
function to_iso8601_z( ?string $utc_mysql ): ?string {
	if ( empty( $utc_mysql ) || '0000-00-00 00:00:00' === $utc_mysql ) {
		return null;
	}
	$ts = strtotime( $utc_mysql . ' UTC' );
	return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : null;
}

/**
 * Whether the private-messaging (DM) feature is available on this request.
 *
 * The Pro private-messaging extension registers its `/messages/` route via the
 * `jetonomy_template_map` filter only when the extension is enabled AND licensed
 * (its boot() ran). Gate every Messages / DM link on this so it never points at a
 * 404 when Pro is installed but messaging is off. Result is cached per request.
 *
 * @return bool True when the messages route is registered.
 */
function messaging_active(): bool {
	static $active = null;
	if ( null === $active ) {
		$active = array_key_exists( 'messages', (array) apply_filters( 'jetonomy_template_map', array() ) );
	}
	return (bool) $active;
}

/**
 * Resolve the requesting client's IP, honouring a trusted reverse-proxy chain.
 *
 * Single source of truth for "who is this request from" — used by IP-bans and
 * rate limiting. Defaults to REMOTE_ADDR, the only value an attacker cannot
 * forge. X-Forwarded-For is honoured ONLY when the request demonstrably arrived
 * through a proxy the site owner declared via `jetonomy_trusted_proxies`;
 * otherwise the header is attacker-controlled and trusting it would let anyone
 * spoof their IP to dodge a ban or reset a rate-limit bucket. Behind a CDN /
 * reverse proxy the owner adds the edge IP(s) to the filter and bans/limits then
 * see the real visitor. `jetonomy_client_ip` allows a full override.
 *
 * @return string Client IP (may be empty if REMOTE_ADDR is unset, e.g. CLI).
 */
function client_ip(): string {
	$remote = isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
		: '';

	$ip = $remote;

	/**
	 * Trusted reverse-proxy / CDN addresses (exact REMOTE_ADDR match). Empty by
	 * default so X-Forwarded-For is ignored unless the request really came from
	 * a declared proxy.
	 *
	 * @param string[] $proxies Trusted proxy IPs.
	 */
	$trusted = (array) apply_filters( 'jetonomy_trusted_proxies', array() );

	if ( '' !== $remote && ! empty( $trusted ) && in_array( $remote, $trusted, true ) ) {
		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
			: '';
		if ( '' !== $xff ) {
			// XFF is "client, proxy1, proxy2…". Walk right-to-left, skip our own
			// trusted proxies, and take the first remaining address — the real
			// client as seen at our edge. A spoofed left-hand entry can't promote
			// itself past a trusted hop.
			foreach ( array_reverse( array_map( 'trim', explode( ',', $xff ) ) ) as $candidate ) {
				if ( '' === $candidate || in_array( $candidate, $trusted, true ) ) {
					continue;
				}
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
				}
				break;
			}
		}
	}

	/**
	 * Final resolved client IP. Override for setups the trusted-proxy logic
	 * above does not cover.
	 *
	 * @param string $ip     Resolved IP.
	 * @param string $remote Raw REMOTE_ADDR.
	 */
	return (string) apply_filters( 'jetonomy_client_ip', $ip, $remote );
}

/**
 * Whether a name impersonates the site or its staff.
 *
 * Compared case-insensitively after trimming and collapsing whitespace, so
 * "  ADMIN  " and "Admin" are both caught. Substring matching is deliberately
 * NOT used: "Adminah" and "Support Sarah" are legitimate names, and blocking
 * them would be a worse failure than the one this prevents.
 *
 * The site title is included because impersonating the community itself is the
 * same attack with a different word.
 *
 * @param string $name Candidate name.
 * @return bool
 */
function is_reserved_display_name( string $name ): bool {
	$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ?? '' ) );
	if ( '' === $normalized ) {
		return false;
	}

	$reserved = array(
		'admin',
		'administrator',
		'moderator',
		'mod',
		'staff',
		'support',
		'owner',
		'root',
		'system',
		'webmaster',
		'official',
	);

	$site_title = strtolower( trim( (string) get_bloginfo( 'name' ) ) );
	if ( '' !== $site_title ) {
		$reserved[] = $site_title;
	}

	/**
	 * Filter the reserved names members may not publish as.
	 *
	 * Security boundary: PATCH /users/me rejects anything matching this list,
	 * so entries are enforced server-side. Values are compared lowercased and
	 * whitespace-collapsed.
	 *
	 * @since 1.9.3
	 *
	 * @param string[] $reserved   Reserved names, lowercase.
	 * @param string   $normalized The name being checked, normalized.
	 */
	$reserved = (array) apply_filters( 'jetonomy_reserved_display_names', $reserved, $normalized );

	return in_array( $normalized, array_map( 'strtolower', $reserved ), true );
}

/**
 * The @handle to show for a member.
 *
 * The EMIT half of the mention contract. `jetonomy_resolve_mention_handles`
 * turns a typed handle back into a user; this decides what handle we put in
 * front of them in the first place - the composer typeahead, the profile page
 * title, schema alternateName.
 *
 * Both halves are needed and they must agree. Jetonomy shipped only the resolve
 * half, so a member whose partner plugin gives them a custom slug was offered
 * as `@their-nicename` here and `@their-slug` there. Mentions still resolved,
 * because resolution was already filterable - but the two products showed a
 * different handle for the same person, which is the split the nicename work
 * was meant to close.
 *
 * Default is `user_nicename`: WordPress's own public slug, and the field
 * BuddyNext documents as the shared contract. Falls back to `user_login` only
 * when nicename is somehow empty.
 *
 * Distinct from user_display_name(): the handle is an IDENTIFIER people type
 * after an `@`, the display name is a label. A site may well show real names
 * and still mention by handle.
 *
 * @param int|\WP_User|object $user User ID, WP_User, or a row carrying user_id.
 * @return string Handle without the leading `@`, or '' when unresolvable.
 */
function user_handle( $user ): string {
	if ( ! $user instanceof \WP_User ) {
		$user_id = is_object( $user )
			? (int) ( $user->user_id ?? $user->ID ?? 0 )
			: (int) $user;

		$user = $user_id > 0 ? get_userdata( $user_id ) : null;
	}
	if ( ! $user instanceof \WP_User ) {
		return '';
	}

	$handle = (string) $user->user_nicename;
	if ( '' === trim( $handle ) ) {
		$handle = (string) $user->user_login;
	}

	/**
	 * Filter the @handle shown for a member.
	 *
	 * MUST be paired with `jetonomy_resolve_mention_handles`. Anything returned
	 * here will be typed back at us by members, so whatever claims a handle on
	 * emit has to claim it on resolve too - otherwise the composer offers a
	 * mention the parser cannot resolve, which is the exact failure BuddyNext's
	 * Handle contract warns about.
	 *
	 * @since 1.9.3
	 *
	 * @param string   $handle Resolved handle, no leading '@'.
	 * @param \WP_User $user   The user.
	 */
	return (string) apply_filters( 'jetonomy_user_handle', $handle, $user );
}

/**
 * The set of names a member is allowed to publish as.
 *
 * WordPress does not let a member author an arbitrary display name: wp-admin
 * offers a "Display name publicly as" select built from permutations of fields
 * they already own. Jetonomy's front-end editor used to be a free-text box that
 * wrote straight through to wp_users.display_name, which meant a member could
 * publish as "Administrator", could take another member's exact name, and could
 * silently overwrite a value the site owner had chosen in wp-admin - the stored
 * name then matched none of core's own options.
 *
 * Shared by the form that renders the select and the endpoint that validates the
 * submission, so the two cannot disagree about what is allowed.
 *
 * The member's CURRENT display_name is always included, exactly as core does.
 * Sites upgrading from the free-text era have members whose stored name is not a
 * permutation of anything; excluding it would make simply saving the form fail,
 * or silently rename them. New arbitrary values still cannot be introduced.
 *
 * @param \WP_User $user User to build choices for.
 * @return string[] Unique, non-empty candidate names.
 */
function display_name_choices( \WP_User $user ): array {
	$first = (string) get_user_meta( $user->ID, 'first_name', true );
	$last  = (string) get_user_meta( $user->ID, 'last_name', true );
	$nick  = (string) get_user_meta( $user->ID, 'nickname', true );

	$choices = array(
		(string) $user->display_name,
		(string) $user->user_login,
		$nick,
		$first,
		$last,
		trim( $first . ' ' . $last ),
		trim( $last . ' ' . $first ),
	);

	// Strip names that impersonate the site or its staff. This is applied to the
	// CHOICES, not just to the submitted value, so the select never offers one
	// and PATCH /users/me - which validates against this same list - rejects it.
	//
	// It has to live here rather than on display_name alone, because the parts
	// are member-writable through the same endpoint: setting
	// nickname="Administrator" and then selecting it was a two-call
	// impersonation that the first version of this check missed entirely
	// (Basecamp 10210055850, QA bounce).
	$choices = array_filter(
		$choices,
		static fn( $name ) => ! is_reserved_display_name( (string) $name )
	);

	/**
	 * Filter the names a member may publish as.
	 *
	 * Add to this to offer another form (e.g. a pseudonym field); remove from it
	 * to stop offering the raw username. Anything not in the returned list is
	 * rejected by PATCH /users/me, so this is a security boundary, not a
	 * cosmetic one - do not add unvalidated user input.
	 *
	 * @since 1.9.3
	 *
	 * @param string[] $choices Candidate names.
	 * @param \WP_User $user    The user.
	 */
	$choices = (array) apply_filters( 'jetonomy_display_name_choices', $choices, $user );

	return array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $choices ) ) ) ) );
}

/**
 * How members are identified across the community.
 *
 * One reader for the setting so the templates, REST and CLI cannot disagree -
 * which is exactly what happened with the jetonomy_user_display_name filter,
 * whose own docblock admits it "does not affect REST/CLI payloads". A site
 * using that filter shows handles on the web and display names in the app.
 *
 * @return string 'display_name' | 'handle' | 'both'.
 */
function name_display_mode(): string {
	$settings = get_option( 'jetonomy_settings', array() );
	$mode     = isset( $settings['member_name_display'] ) ? (string) $settings['member_name_display'] : 'display_name';

	return in_array( $mode, array( 'display_name', 'handle', 'both' ), true ) ? $mode : 'display_name';
}

/**
 * The name to show for a member on any display surface.
 *
 * THE single source of truth for "what do we call this person on screen",
 * paired with get_profile_url() for "where does their name link to". Every
 * byline, member row, mention chip, moderation card AND the REST/CLI payloads
 * resolve through here (migration 1_9_4_1 routed the data surfaces in too), so
 * the site-wide member_name_display setting and the jetonomy_user_display_name
 * filter apply once, uniformly - web and app can no longer disagree.
 *
 * Default chain is display_name -> user_nicename -> user_login. The fallbacks
 * matter: display_name can be empty on users created by an importer or a raw
 * SQL insert, and an empty byline reads as a broken row.
 *
 * @param int|\WP_User|object $user User ID, WP_User, or a row from one of our own
 *                                  tables carrying user_id (or ID).
 * @return string Display name, or '' when the user does not exist.
 */
function user_display_name( $user ): string {
	if ( ! $user instanceof \WP_User ) {
		// Callers legitimately hold three different things: a user ID, a WP_User,
		// or a row from one of Jetonomy's own tables (space_members joins, member
		// lists) which is a stdClass carrying user_id. Casting a stdClass to int
		// emits a warning and yields 0, so a member row rendered an EMPTY name -
		// which is how the managed-by card lost its names. Resolve by id and let
		// get_userdata()'s cache absorb the lookup.
		$user_id = is_object( $user )
			? (int) ( $user->user_id ?? $user->ID ?? 0 )
			: (int) $user;

		$user = $user_id > 0 ? get_userdata( $user_id ) : null;
	}
	if ( ! $user instanceof \WP_User ) {
		return '';
	}

	$name = (string) $user->display_name;
	if ( '' === trim( $name ) ) {
		$name = (string) $user->user_nicename;
	}
	if ( '' === trim( $name ) ) {
		$name = (string) $user->user_login;
	}

	/*
	 * Site-owner choice of how members are identified.
	 *
	 * display_name is NOT unique - WordPress lets any number of accounts share
	 * one, and a community with two "Alex Rivera" bylines gives a reader
	 * nothing to tell them apart. user_nicename is unique (WP enforces it) and
	 * is already the handle @mentions resolve against, so it is the honest
	 * identifier; it is just never shown outside the mention picker.
	 *
	 * Default stays 'display_name' so nothing changes on update. Applied here,
	 * BEFORE the filter below, so a developer override still wins over the
	 * setting rather than the other way round.
	 */
	$handle = (string) $user->user_nicename;
	switch ( name_display_mode() ) {
		case 'handle':
			if ( '' !== trim( $handle ) ) {
				$name = '@' . $handle;
			}
			break;
		case 'both':
			// Skip the suffix when it would just repeat the name - a member
			// whose display_name IS their nicename does not need "bob @bob".
			if ( '' !== trim( $handle ) && strcasecmp( $name, $handle ) !== 0 ) {
				$name = $name . ' @' . $handle;
			}
			break;
		default:
			// 'display_name' mode (the default). WordPress does not make
			// display_name unique, so two accounts can both read "Alex
			// Rivera" and leave a reader nothing to tell them apart. Append
			// the unique handle ONLY when this name is actually shared on the
			// site - names that collide with no one are left untouched, so
			// most communities never see an @handle they did not ask for.
			if (
				'' !== trim( $handle )
				&& strcasecmp( $name, $handle ) !== 0
				&& '' !== trim( (string) $user->display_name )
				&& $name === (string) $user->display_name
				&& display_name_is_shared( $name )
			) {
				$name = $name . ' @' . $handle;
			}
			break;
	}

	/**
	 * Filter the name shown for a member on display surfaces.
	 *
	 * Return $user->user_nicename to show handles, $user->user_login to show
	 * usernames, or compose anything else. Applies uniformly across web, REST
	 * and CLI - every surface routes through user_display_name().
	 *
	 * @since 1.9.3
	 *
	 * @param string   $name Resolved display name.
	 * @param \WP_User $user The user.
	 */
	return (string) apply_filters( 'jetonomy_user_display_name', $name, $user );
}

/**
 * Whether a member's display_name is shared by another account on this site.
 *
 * WordPress does not make display_name unique; user_display_name() appends the
 * unique @handle for the members whose name actually collides. Backed by a
 * cached set (see colliding_display_names()) so a member list of N rows costs
 * one lookup, not N queries.
 *
 * @param string $display_name The name being rendered.
 * @return bool True when 2+ accounts share this display_name.
 */
function display_name_is_shared( string $display_name ): bool {
	$key = strtolower( trim( $display_name ) );

	return '' !== $key && isset( colliding_display_names()[ $key ] );
}

/**
 * The set of display_names shared by 2+ accounts, keyed lower-cased for O(1)
 * lookup. Cached in a transient (object-cache-backed when a persistent drop-in
 * is present, DB-backed otherwise) and busted whenever a user is registered,
 * renamed or removed - see the $bust_user_row hook in class-jetonomy.php.
 *
 * @return array<string,bool> Map of lower-cased colliding display_name => true.
 */
function colliding_display_names(): array {
	$cached = get_transient( 'jetonomy_display_name_collisions' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	// ponytail: one unindexed GROUP BY scan of wp_users, cached until a user is
	// added/renamed/removed. Fine at community scale; if a site ever reaches
	// millions of users, maintain a collisions table on the user-write path.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_col(
		"SELECT LOWER(TRIM(display_name)) FROM {$wpdb->users}
		 WHERE display_name <> ''
		 GROUP BY LOWER(TRIM(display_name)) HAVING COUNT(*) > 1"
	);

	$set = array_fill_keys( array_map( 'strval', (array) $rows ), true );
	set_transient( 'jetonomy_display_name_collisions', $set, DAY_IN_SECONDS );

	return $set;
}

/**
 * Get the profile URL for a user.
 *
 * Returns the Jetonomy profile URL by default, but can be filtered
 * to point to BuddyPress, BuddyBoss, Ultimate Member, or any other
 * profile system.
 *
 * @param int $user_id The user ID.
 * @return string The profile URL.
 */
function get_profile_url( int $user_id ): string {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return '';
	}

	$settings  = get_option( 'jetonomy_settings', [] );
	$base_slug = $settings['base_slug'] ?? 'community';
	// rawurlencode because a login may legally contain a space or non-ASCII;
	// most call sites that hand-built this URL already did, the helper did not.
	$default = home_url( '/' . $base_slug . '/u/' . rawurlencode( $user->user_login ) . '/' );

	/**
	 * Filter the user profile URL.
	 *
	 * Allows third-party plugins (BuddyPress, BuddyBoss, Ultimate Member)
	 * to override where user profile links point to.
	 *
	 * @param string $url     The default Jetonomy profile URL.
	 * @param int    $user_id The user ID.
	 * @param object $user    The WP_User object.
	 */
	return apply_filters( 'jetonomy_profile_url', $default, $user_id, $user );
}

/**
 * Top-level replies rendered per page on a topic.
 *
 * Single source of truth for the `replies_per_page` setting, shared by the
 * view that paginates the thread and by reply_permalink(), which has to
 * compute the SAME page the view would render or every deep link lands on
 * the wrong one.
 *
 * @return int Always >= 1.
 */
function replies_per_page(): int {
	$settings = get_option( 'jetonomy_settings', array() );
	return max( 1, (int) ( $settings['replies_per_page'] ?? 30 ) );
}

/**
 * Permalink to a single reply, paged to where the reply actually renders.
 *
 * THE single producer of reply deep links. Every surface that points at a
 * reply — notifications (web, REST, email), the profile's reply list, JSON-LD
 * accepted answers, the FluentCommunity profile block — routes through here.
 * They each used to concatenate their own '#reply-' . $id, which produced an
 * anchor for an element that (a) had no matching id in the DOM and (b) was
 * usually not on the rendered page at all, because the thread paginates.
 *
 * Slugs are passed in rather than resolved here: most callers already hold
 * them from a JOIN, and re-querying Post + Space per link would put two
 * queries on every row of a notification list.
 *
 * ?rpg is omitted for page 1 so the canonical topic URL stays clean — that is
 * the page a bare topic URL already renders, and adding ?rpg=1 would fork one
 * page into two URLs for crawlers.
 *
 * No ?rsort is emitted, deliberately. Reply::page_of() computes the page under
 * Reply::DEFAULT_SORT, so the link must let the view fall back to that same
 * default — inheriting the linking reader's sort would put the anchor on a
 * different page, and 'best' would make the link rot as votes move. See the
 * ordering contract on Reply::page_of().
 *
 * $page is an optimisation for callers that ALREADY know which page the reply
 * is on and would otherwise pay a COUNT to re-derive it. The single-post view
 * is the reason it exists: every reply it renders is on the page it just
 * fetched, so passing $reply_page turns a per-card query into zero. A 30-reply
 * thread would otherwise issue 30+ COUNTs to render 30 permalinks — the N+1
 * the repo's big-site rule exists to prevent. Callers that genuinely don't
 * know (notifications, the profile reply list, the off-page accepted-answer
 * callout) omit it and let page_of() resolve.
 *
 * @param string   $space_slug Space slug.
 * @param string   $post_slug  Post (topic) slug.
 * @param int      $reply_id   Reply ID.
 * @param int|null $page       Known 1-based page, or null to compute it.
 * @return string Deep-link URL, or '' when the slugs are unusable.
 */
/**
 * A space's front URL.
 *
 * `base_url()` already keeps the configurable base slug in one place, but the
 * `/s/` segment after it is a literal repeated in ~90 places. That is fine for
 * a template inside this plugin, which moves whenever the router does, and not
 * fine for an integration in another plugin, which would keep pointing at a
 * path this one no longer serves.
 *
 * Same shape as reply_permalink() below. Existing call sites are deliberately
 * left alone - sweeping ninety of them is a change of its own - but nothing new
 * should compose this by hand.
 *
 * @param string $space_slug Space slug.
 * @return string Front URL, or '' when the slug is unusable.
 */
function space_permalink( string $space_slug ): string {
	$space_slug = trim( $space_slug, '/' );

	return '' === $space_slug ? '' : base_url() . '/s/' . $space_slug . '/';
}

function reply_permalink( string $space_slug, string $post_slug, int $reply_id, ?int $page = null ): string {
	if ( '' === $space_slug || '' === $post_slug || $reply_id < 1 ) {
		return '';
	}

	$url  = base_url() . '/s/' . $space_slug . '/t/' . $post_slug . '/';
	$page = null !== $page ? max( 1, $page ) : Models\Reply::page_of( $reply_id, replies_per_page() );

	if ( $page > 1 ) {
		$url = add_query_arg( 'rpg', $page, $url );
	}

	return $url . '#reply-' . $reply_id;
}

/**
 * Resolve a deep-link URL for a notification's target object.
 *
 * Single source of truth for notification deep links. Used by the notifier,
 * the mentions dispatcher, and the notifications REST controller, and passed
 * as the `$link` argument of the `jetonomy_notification_created` action so
 * consumers (e.g. BuddyNext's central notification center) can mirror the
 * notification 1:1 without re-deriving the URL from object IDs.
 *
 * @param string $object_type 'post', 'reply', or 'user'.
 * @param int    $object_id   The target object ID.
 * @return string Deep-link URL, or '' if unresolvable.
 */
function notification_deep_link( string $object_type, int $object_id ): string {
	if ( 'post' === $object_type ) {
		$post = Models\Post::find( $object_id );
		if ( ! $post ) {
			return '';
		}
		$space = Models\Space::find( (int) $post->space_id );
		if ( ! $space ) {
			return '';
		}
		return base_url() . '/s/' . $space->slug . '/t/' . $post->slug . '/';
	}

	if ( 'reply' === $object_type ) {
		$reply = Models\Reply::find( $object_id );
		if ( ! $reply ) {
			return '';
		}
		$post = Models\Post::find( (int) $reply->post_id );
		if ( ! $post ) {
			return '';
		}
		$space = Models\Space::find( (int) $post->space_id );
		if ( ! $space ) {
			return '';
		}
		return reply_permalink( (string) $space->slug, (string) $post->slug, $object_id );
	}

	if ( 'user' === $object_type ) {
		return get_profile_url( $object_id );
	}

	if ( 'space' === $object_type ) {
		$space = Models\Space::find( $object_id );
		return $space ? base_url() . '/s/' . $space->slug . '/' : '';
	}

	/**
	 * Resolve a deep link for an object type free doesn't know about (e.g. Pro's
	 * 'message'/'conversation'). Lets Pro map its own object types to URLs
	 * without free hardcoding a Pro route. Existing types return above, so this
	 * only fires for unknown types — zero behavior change for them.
	 *
	 * @param string $url         Default ('').
	 * @param string $object_type Notification object type.
	 * @param int    $object_id   Notification object id.
	 */
	return (string) apply_filters( 'jetonomy_notification_deep_link', '', $object_type, $object_id );
}

/**
 * Where a given recipient should land to act on a pending join request.
 *
 * Single source of truth for the three surfaces that link a `join_request`
 * notification: the email (Notifier), the /notifications/ page, and the REST
 * bell dropdown. They used to build this URL independently — the first two
 * had copies of the same logic and the third had none at all, so the bell
 * resolved join requests to an empty URL and fell back to /notifications/
 * (Basecamp 10118686521).
 *
 * Recipient-dependent by necessity: wp-admin cap-holders get the Join
 * Requests tab, everyone else (space admins and moderators who own the space
 * but not wp-admin) gets the front-end members page. It must NOT be the
 * space-mod queue at /s/{slug}/mod/, which renders pending FLAGS only — the
 * approve / reject UI for join requests lives on the members page.
 *
 * @param int         $recipient_id WP user id of the notification recipient.
 * @param object|null $space        Space row.
 * @return string URL, or '' when the space is gone.
 */
function join_request_url_for( int $recipient_id, $space ): string {
	if ( ! $space ) {
		return '';
	}

	// EVERY recipient lands on the frontend pending-requests anchor - admins
	// included. The capability branch that sent jetonomy_manage_spaces /
	// manage_options holders to the wp-admin Join Requests tab meant the same
	// notification promised one destination and delivered two, and QA kept
	// reproducing "empty moderation screen" for the admin audience (Basecamp
	// 10118686521). The frontend members page approves/rejects for both
	// audiences, and the wp-admin tab remains reachable through Spaces > Edit
	// for owners who prefer it. $recipient_id stays in the signature: the
	// notifier passes it per-recipient and a filter may re-branch on it.
	unset( $recipient_id );

	// #jt-pending-requests anchors the pending list on the members page, so a
	// space with many members doesn't land the reader above the fold and away
	// from the thing the notification was about.
	return base_url() . '/s/' . $space->slug . '/members/#jt-pending-requests';
}

/**
 * Get a linked avatar + name for a user.
 *
 * Returns HTML with avatar and display name wrapped in a profile link.
 *
 * @param int    $user_id    The user ID.
 * @param string $avatar_class CSS class for avatar size (jt-avatar-sm, jt-avatar-md).
 * @param int    $avatar_size  Avatar pixel size.
 * @param bool   $show_name   Whether to show the display name.
 * @return string HTML output.
 */
function get_user_link( int $user_id, string $avatar_class = 'jt-avatar-sm', int $avatar_size = 30, bool $show_name = true ): string {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		// Unknown / anonymous author: show a generic user-silhouette icon rather
		// than a "??" placeholder, so the avatar reads as a real (if nameless)
		// person instead of looking broken.
		return '<span class="jt-avatar jt-avatar-anon ' . esc_attr( $avatar_class ) . '">'
			. jetonomy_icon( 'user', max( 14, (int) round( $avatar_size * 0.6 ) ) )
			. '</span>';
	}

	$url  = get_profile_url( $user_id );
	$name = user_display_name( $user );
	// Resolve through Avatar::display_url() so a real uploaded avatar (local /
	// BuddyPress / Gravatar-that-exists) is shown, and members with no real
	// avatar fall back to initials instead of Gravatar's generic mystery-person.
	$avatar_url = Avatar::display_url( $user_id, $avatar_size * 2 );
	$initials   = strtoupper( mb_substr( $name, 0, 2 ) );

	// The hidden .jt-avatar-fallback sibling is the initials the reader sees
	// if the image URL is dead - view.js swaps the pair on the img's error
	// event. Same contract as templates/partials/avatar.php (Basecamp
	// 10110833991): an <img> with no fallback renders as broken-image alt
	// text in Firefox when the upload behind it has been deleted.
	$avatar_html = $avatar_url
		? '<img src="' . esc_url( $avatar_url ) . '" alt="' . esc_attr( $name ) . '" class="jt-avatar ' . esc_attr( $avatar_class ) . '" width="' . (int) $avatar_size . '" height="' . (int) $avatar_size . '" loading="lazy">'
			. '<span class="jt-avatar ' . esc_attr( $avatar_class ) . ' jt-avatar-fallback" hidden>' . esc_html( $initials ) . '</span>'
		: '<span class="jt-avatar ' . esc_attr( $avatar_class ) . '">' . esc_html( $initials ) . '</span>';

	$name_html = $show_name ? ' <span class="jt-user-name">' . esc_html( $name ) . '</span>' : '';

	if ( $url ) {
		return '<a href="' . esc_url( $url ) . '" class="jt-user-link">' . $avatar_html . $name_html . '</a>';
	}

	return $avatar_html . $name_html;
}

/**
 * Return the URL where a space admin should land to edit a space.
 *
 * 1.4.0 G5 shipped the front-end edit view at /community/s/:slug/edit/, so
 * this now defaults to that URL. Integrators can flip the filter back to
 * false to send admins to wp-admin instead, e.g. for a custom workflow.
 *
 * @param object $space Space row (must have `slug` and `id`).
 * @return string Absolute URL.
 */
function get_space_edit_url( $space ): string {
	$slug = isset( $space->slug ) ? (string) $space->slug : '';
	$id   = isset( $space->id ) ? (int) $space->id : 0;

	/**
	 * Filter whether to use the front-end space-edit URL (G5).
	 *
	 * Default true since G5 shipped in 1.4.0. Set false to route the
	 * sidebar Edit-space link to wp-admin instead.
	 *
	 * @param bool   $use_frontend Whether to return the front-end URL.
	 * @param object $space        Space row.
	 */
	$use_frontend = (bool) apply_filters( 'jetonomy_use_frontend_space_edit', true, $space );

	if ( $use_frontend && '' !== $slug ) {
		return base_url() . '/s/' . rawurlencode( $slug ) . '/edit/';
	}

	if ( $id > 0 ) {
		return admin_url( 'admin.php?page=jetonomy-spaces&edit=' . $id );
	}

	return admin_url( 'admin.php?page=jetonomy-spaces' );
}

/**
 * Return 'admin' / 'moderator' / null for a user in a space.
 *
 * Thin namespaced wrapper around `Models\SpaceMember::role_label()` so
 * templates can write `\Jetonomy\get_space_role_label( $author_id, $space_id )`
 * without a long-form class reference. The model method is the source
 * of truth for the per-request cache; this helper only exists for
 * template ergonomics (1.4.0 G3).
 *
 * Templates that render a list of authors should call
 * `Models\SpaceMember::warm_role_cache($space_id, $author_ids)` BEFORE
 * the loop so each per-row call here is O(1) instead of O(N).
 *
 * @param int $user_id
 * @param int $space_id
 * @return ?string  'admin' | 'moderator' | null
 */
function get_space_role_label( int $user_id, int $space_id ): ?string {
	if ( $user_id <= 0 || $space_id <= 0 ) {
		return null;
	}
	return \Jetonomy\Models\SpaceMember::role_label( $space_id, $user_id );
}

/**
 * Header logo URL for Jetonomy-rendered surfaces (emails, blocks, shortcodes).
 *
 * Themes own the site header — this helper exists for surfaces Jetonomy
 * renders itself. Filterable via `jetonomy_header_logo` so extensions
 * (e.g. Pro white-label) can override the default with a custom URL.
 *
 * @since 1.4.1
 *
 * @param string $default Default logo URL when no override is set.
 * @return string Filtered logo URL (may be empty for "no logo, use site name").
 */
function header_logo( string $default = '' ): string {
	/**
	 * Filter the header logo URL used by Jetonomy-rendered surfaces.
	 *
	 * @param string $url The current logo URL. Empty string means "no logo set".
	 */
	return (string) apply_filters( 'jetonomy_header_logo', $default );
}

/**
 * Footer text for Jetonomy-rendered surfaces (emails, blocks, shortcodes).
 *
 * Filterable via `jetonomy_footer_text` so extensions (e.g. Pro white-label)
 * can replace the default copy with their own.
 *
 * @since 1.4.1
 *
 * @param string $default Default footer text.
 * @return string Filtered footer text (may be empty).
 */
function footer_text( string $default = '' ): string {
	/**
	 * Filter the footer text used by Jetonomy-rendered surfaces.
	 *
	 * @param string $text The current footer text. May be empty.
	 */
	return (string) apply_filters( 'jetonomy_footer_text', $default );
}

/**
 * jetonomy_settings option merged with SEO defaults (single source of truth).
 *
 * The admin SEO checkboxes render "Default: On" via `?? true`, but the
 * consumers used `empty($settings['seo_x'])` which treats an absent key as OFF
 * — so a fresh install had the sitemap/noindex features silently disabled
 * despite the UI. Routing BOTH the render and the consumers through this
 * defaults union makes them agree, and it applies to existing installs on the
 * next page load (an activation seed would never re-fire on a plugin update).
 *
 * Uses the `+` union (stored values win, only absent keys fall to defaults) so
 * an admin's explicit `false`/`0` is preserved.
 *
 * @return array
 */
function seo_settings(): array {
	$defaults = array(
		'seo_schema'           => true,
		'seo_sitemap'          => true,
		'seo_noindex_profiles' => true,
		'seo_noindex_search'   => true,
		'seo_post_title'       => '{post_title} - {space_name} | {site_name}',
		'seo_space_title'      => '{space_name} | {site_name}',
		'seo_twitter_handle'   => '',
		'seo_default_og_image' => '',
	);
	$stored   = get_option( 'jetonomy_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return $stored + $defaults;
}

/**
 * Echo the <option> list for a space's visibility, from the one source of truth.
 *
 * Every form that offers the choice calls this, so none of them can drift out of
 * step with the `visibility` enum again — which is what let a hidden space be
 * silently republished as public (see Space::visibility_levels()).
 *
 * `$current` matters more than it looks: passing the space's real value is what
 * makes selected() mark it. A form that offers no option matching the stored
 * value does not fail loudly — it quietly shows the first one and saves that.
 *
 * @param string $current      The space's stored visibility, '' when creating.
 * @param bool   $with_details Append the level description to each label. On by
 *                             default for member-facing forms, where the whole
 *                             question is "which one hides it"; wp-admin passes
 *                             false because its table row carries its own copy.
 */
function space_visibility_options( string $current = '', bool $with_details = true ): void {
	foreach ( \Jetonomy\Models\Space::visibility_levels() as $value => $level ) {
		$label = $with_details
			/* translators: 1: visibility level, e.g. "Private". 2: what it means. */
			? sprintf( _x( '%1$s: %2$s', 'space visibility option', 'jetonomy' ), $level['label'], $level['description'] )
			: $level['label'];

		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}
}

/**
 * Human label for a space's stored `visibility` enum.
 *
 * Reads Space::visibility_levels(), the same source space_visibility_options()
 * uses, so a listing badge and the form that set it always agree.
 *
 * @param string $visibility Stored enum value.
 * @return string
 */
function space_visibility_label( string $visibility ): string {
	$levels = \Jetonomy\Models\Space::visibility_levels();
	return isset( $levels[ $visibility ]['label'] )
		? (string) $levels[ $visibility ]['label']
		: ucfirst( $visibility );
}

/**
 * Human labels for the post/reply `status` enum, keyed by stored value.
 *
 * One map for the Content and Replies screens, which each carried their own
 * copy for the filter tabs while their status badge printed ucfirst() of the
 * raw enum instead — so the tab said "Published" and the badge said "Publish",
 * and neither badge could be translated at all.
 *
 * @param bool $with_all Include the 'all' pseudo-status the filter tabs use.
 * @return array<string, string>
 */
function content_status_labels( bool $with_all = false ): array {
	$labels = array(
		'publish' => __( 'Published', 'jetonomy' ),
		'pending' => __( 'Pending', 'jetonomy' ),
		'spam'    => __( 'Spam', 'jetonomy' ),
		'trash'   => __( 'Trash', 'jetonomy' ),
	);

	return $with_all ? array( 'all' => __( 'All', 'jetonomy' ) ) + $labels : $labels;
}

/**
 * Human label for a single post/reply status.
 *
 * @param string $status Stored enum value.
 * @return string
 */
function content_status_label( string $status ): string {
	$labels = content_status_labels();
	return $labels[ $status ] ?? ucfirst( $status );
}

/**
 * Human label for a space's stored `status` enum.
 *
 * The admin listing used to print ucfirst() of the raw enum, which is a
 * machine value — untranslatable, and wrong in any language that does not
 * capitalise the way English does.
 *
 * @param string $status Stored enum value.
 * @return string
 */
function space_status_label( string $status ): string {
	switch ( $status ) {
		case 'active':
			return __( 'Active', 'jetonomy' );
		case 'archived':
			return __( 'Archived', 'jetonomy' );
		case 'locked':
			return __( 'Locked', 'jetonomy' );
		default:
			return ucfirst( $status );
	}
}

/**
 * Human label for a space's stored `join_policy` enum.
 *
 * Same reasoning as space_status_label(). Casing is fixed here once instead
 * of drifting between forms ("Invite Only" in wp-admin, "Invite only" on the
 * front end).
 *
 * @param string $policy Stored enum value.
 * @return string
 */
function space_join_policy_label( string $policy ): string {
	switch ( $policy ) {
		case 'open':
			return __( 'Open', 'jetonomy' );
		case 'approval':
			return __( 'Requires Approval', 'jetonomy' );
		case 'invite':
			return __( 'Invite Only', 'jetonomy' );
		default:
			return ucfirst( $policy );
	}
}
