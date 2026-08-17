<?php
/**
 * Public global helpers for templates and integrations.
 *
 * Functions in this file deliberately live in the GLOBAL namespace so theme
 * authors and template overrides can call them without importing namespaces.
 * Namespaced internals belong in includes/functions.php (the Jetonomy\*
 * namespace), not here.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jetonomy_post_title_or_excerpt' ) ) {
	/**
	 * Return a displayable label for a post — title if present, otherwise a
	 * short excerpt of the body. Feed-space posts (introduced in 1.4.3) are
	 * stored with empty titles by design; every template that renders a
	 * post label runs through this helper so feed posts never display a
	 * blank string or a misleading placeholder.
	 *
	 * The excerpt strips tags, collapses whitespace, and appends an ellipsis
	 * when the body is longer than $len characters. Multi-byte safe.
	 *
	 * @param object $post Post row (must expose ->title and ->content).
	 * @param int    $len  Maximum visible length when falling back to an excerpt.
	 * @return string Plain-text label, never null.
	 */
	function jetonomy_post_title_or_excerpt( $post, int $len = 80 ): string {
		if ( ! is_object( $post ) ) {
			return '';
		}

		$title = isset( $post->title ) ? trim( (string) $post->title ) : '';
		if ( '' !== $title ) {
			return $title;
		}

		$body = isset( $post->content ) ? (string) $post->content : '';
		// Prefer content_plain if a model has already cached one, but tolerate
		// either field being absent on rows materialised by older code paths.
		if ( '' === $body && isset( $post->content_plain ) ) {
			$body = (string) $post->content_plain;
		}

		$plain = trim( wp_strip_all_tags( $body ) );
		if ( '' === $plain ) {
			return '';
		}

		$plain = preg_replace( '/\s+/u', ' ', $plain );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $plain ) > $len ) {
			$truncated = mb_substr( $plain, 0, $len );
			$last_sp   = mb_strrpos( $truncated, ' ' );
			$cut       = ( false !== $last_sp && $last_sp > 0 )
				? mb_substr( $truncated, 0, $last_sp )
				: $truncated;
			return rtrim( $cut ) . '…';
		}

		if ( strlen( $plain ) > $len ) {
			$truncated = substr( $plain, 0, $len );
			$last_sp   = strrpos( $truncated, ' ' );
			$cut       = ( false !== $last_sp && $last_sp > 0 )
				? substr( $truncated, 0, $last_sp )
				: $truncated;
			return rtrim( $cut ) . '…';
		}

		return $plain;
	}
}

if ( ! function_exists( 'jetonomy_admin_empty_state' ) ) {
	/**
	 * Render the canonical admin empty-state primitive.
	 *
	 * Every "no data yet" surface in wp-admin should call this helper instead
	 * of hand-writing <div class="jetonomy-empty-state">…</div> markup so that
	 * spacing, dark mode, RTL, and mobile breakpoints stay in sync as the
	 * design evolves.
	 *
	 * @param array $args {
	 *     Render args for the empty state.
	 *
	 *     @type string $icon     Dashicon slug without the 'dashicons-' prefix. Default 'info-outline'.
	 *     @type string $title    Optional headline. Default empty.
	 *     @type string $body     Supporting copy. Default empty.
	 *     @type array  $actions  Optional CTAs: list of { label, url, primary?, attrs? } arrays.
	 *     @type int    $colspan  When > 0, wraps output in <tr class="jetonomy-empty-row"><td colspan="N">…</td></tr>.
	 *     @type string $variant  'default' | 'success' | 'compact'. Default 'default'.
	 *     @type string $class    Extra CSS classes appended to the wrapper.
	 * }.
	 */
	function jetonomy_admin_empty_state( array $args = array() ): void {
		$args = array_merge(
			array(
				'icon'    => 'info-outline',
				'title'   => '',
				'body'    => '',
				'actions' => array(),
				'colspan' => 0,
				'variant' => 'default',
				'class'   => '',
			),
			$args
		);

		$classes = array( 'jetonomy-empty-state' );
		if ( in_array( $args['variant'], array( 'success', 'compact' ), true ) ) {
			$classes[] = 'jetonomy-empty-state--' . $args['variant'];
		}
		if ( '' !== $args['class'] ) {
			$classes[] = (string) $args['class'];
		}

		$colspan = (int) $args['colspan'];
		if ( $colspan > 0 ) {
			echo '<tr class="jetonomy-empty-row"><td colspan="' . absint( $colspan ) . '">';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( '' !== $args['icon'] ) {
			echo '<span class="dashicons dashicons-' . esc_attr( $args['icon'] ) . ' jetonomy-empty-state__icon" aria-hidden="true"></span>';
		}

		if ( '' !== $args['title'] ) {
			echo '<h2 class="jetonomy-empty-state__title">' . esc_html( $args['title'] ) . '</h2>';
		}

		if ( '' !== $args['body'] ) {
			echo '<p class="jetonomy-empty-state__body">' . esc_html( $args['body'] ) . '</p>';
		}

		if ( ! empty( $args['actions'] ) && is_array( $args['actions'] ) ) {
			$rendered = '';
			foreach ( $args['actions'] as $action ) {
				if ( empty( $action['label'] ) || empty( $action['url'] ) ) {
					continue;
				}
				$btn_class = 'button';
				if ( ! empty( $action['primary'] ) ) {
					$btn_class .= ' button-primary';
				}
				$attrs = '';
				if ( ! empty( $action['attrs'] ) && is_array( $action['attrs'] ) ) {
					foreach ( $action['attrs'] as $k => $v ) {
						$attrs .= ' ' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
					}
				}
				$rendered .= sprintf(
					'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
					esc_url( (string) $action['url'] ),
					esc_attr( $btn_class ),
					$attrs,
					esc_html( (string) $action['label'] )
				);
			}
			if ( '' !== $rendered ) {
				echo '<div class="jetonomy-empty-state__actions">' . $rendered . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internals already escaped per-field above.
			}
		}

		echo '</div>';

		if ( $colspan > 0 ) {
			echo '</td></tr>';
		}
	}
}

if ( ! function_exists( 'jetonomy_space_activity_label' ) ) {
	/**
	 * Human-readable "Active N ago" label for a space, from last_activity_at.
	 *
	 * Visitors decide where to engage by whether a space is alive — lifetime
	 * post/member totals can't tell a dormant space from a thriving one. This
	 * surfaces recency on the space cards (home + category listings).
	 *
	 * @since 1.5.0
	 * @param object $space Space row (expects ->last_activity_at, may be null/empty).
	 * @return string e.g. "Active 2 hours ago", or '' when no activity is recorded.
	 */
	function jetonomy_space_activity_label( $space ): string {
		$ts = isset( $space->last_activity_at ) ? (string) $space->last_activity_at : '';
		if ( '' === $ts || '0000-00-00 00:00:00' === $ts ) {
			return '';
		}
		$time = strtotime( $ts );
		if ( ! $time ) {
			return '';
		}
		/* translators: %s: human-readable time difference, e.g. "2 hours". */
		return sprintf( __( 'Active %s ago', 'jetonomy' ), human_time_diff( $time, time() ) );
	}
}

if ( ! function_exists( 'jetonomy_community_pulse' ) ) {
	/**
	 * Community pulse stats for the home welcome block: members, total posts,
	 * and posts in the last 7 days. Cached in a 1-hour transient so the home
	 * page never runs the COUNT on the posts table per request (the plugin's
	 * extreme-scale rule — a single space can hold 10k+ posts).
	 *
	 * @since 1.5.0
	 * @return array{members:int, posts:int, posts_week:int}
	 */
	function jetonomy_community_pulse(): array {
		$cached = get_transient( 'jetonomy_community_pulse' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$posts_tbl    = \Jetonomy\table( 'posts' );
		$profiles_tbl = \Jetonomy\table( 'user_profiles' );
		$spaces_tbl   = \Jetonomy\table( 'spaces' );

		$pulse = array(
			// Total posts: sum the denormalised per-space counter (cheap) rather
			// than COUNT the posts table.
			'posts'      => (int) $wpdb->get_var( "SELECT COALESCE(SUM(post_count),0) FROM {$spaces_tbl}" ),
			'members'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$profiles_tbl}" ),
			'posts_week' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$posts_tbl} WHERE status = 'publish' AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from table().
					gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS )
				)
			),
		);

		set_transient( 'jetonomy_community_pulse', $pulse, HOUR_IN_SECONDS );
		return $pulse;
	}
}

if ( ! function_exists( 'jetonomy_after_content_allowed_html' ) ) {
	/**
	 * Shared kses allow-list for the post/reply after-content filter slots
	 * (`jetonomy_after_post_content` / `jetonomy_after_reply_content`).
	 *
	 * Extends the 'post' set with the form inputs + IA directives the polls
	 * widget relies on AND the attachment-card markup (image link, PDF trigger
	 * button, download chip, inline SVG icons). One source of truth so the two
	 * slots (single-post.php and reply-card.php) never drift. Superset of the
	 * former inline allow-list in single-post.php — nothing the poll widget
	 * needed is dropped.
	 *
	 * @return array kses allowed-HTML map.
	 */
	function jetonomy_after_content_allowed_html(): array {
		$tags = wp_kses_allowed_html( 'post' );

		$tags['input']                = array(
			'type'                   => true,
			'name'                   => true,
			'value'                  => true,
			'checked'                => true,
			'disabled'               => true,
			'class'                  => true,
			'id'                     => true,
			'aria-label'             => true,
			'aria-checked'           => true,
			'data-wp-on--click'      => true,
			'data-wp-on--change'     => true,
			'data-wp-bind--checked'  => true,
			'data-wp-bind--disabled' => true,
		);
		$tags['button']               = array(
			'type'              => true,
			'class'             => true,
			'aria-label'        => true,
			'aria-pressed'      => true,
			'title'             => true,
			'data-jt-pdf-url'   => true,
			'data-jt-pdf-name'  => true,
			'data-jt-pdf-pages' => true,
			'data-wp-on--click' => true,
		);
		$tags['a']['download']        = true;
		$tags['a']['rel']             = true;
		$tags['a']['data-jt-pdf-url'] = true;
		$tags['img']['loading']       = true;
		$tags['img']['decoding']      = true;
		$tags['svg']                  = array(
			'viewbox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'aria-hidden'     => true,
			'class'           => true,
			'xmlns'           => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		);
		$tags['path']                 = array(
			'd'      => true,
			'fill'   => true,
			'stroke' => true,
		);
		$tags['line']                 = array(
			'x1' => true,
			'y1' => true,
			'x2' => true,
			'y2' => true,
		);
		$tags['polyline']             = array( 'points' => true );
		$tags['rect']                 = array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
		);
		$tags['figure']               = array( 'class' => true );
		$tags['figcaption']           = array( 'class' => true );
		$tags['textarea']             = array(
			'name'        => true,
			'class'       => true,
			'rows'        => true,
			'cols'        => true,
			'placeholder' => true,
		);
		$tags['select']               = array(
			'name'  => true,
			'class' => true,
		);
		$tags['option']               = array(
			'value'    => true,
			'selected' => true,
		);

		// IA directives on structural tags (mirrors the poll widget's needs).
		foreach ( array( 'div', 'span', 'button', 'label', 'a', 'form', 'select', 'option', 'textarea' ) as $t ) {
			if ( isset( $tags[ $t ] ) ) {
				$tags[ $t ]['data-wp-interactive']    = true;
				$tags[ $t ]['data-wp-context']        = true;
				$tags[ $t ]['data-wp-on--click']      = true;
				$tags[ $t ]['data-wp-on--change']     = true;
				$tags[ $t ]['data-wp-on--submit']     = true;
				$tags[ $t ]['data-wp-bind--hidden']   = true;
				$tags[ $t ]['data-wp-bind--disabled'] = true;
				$tags[ $t ]['data-wp-class--active']  = true;
			}
		}
		return $tags;
	}
}

if ( ! function_exists( 'jetonomy_admin_table' ) ) {
	/**
	 * Render an admin data table that satisfies the responsive contract.
	 *
	 * THE shared list-table primitive for Free and Pro admin screens
	 * (Basecamp 10146443346). Hand-rolled `<table>` markup kept omitting the
	 * core small-screen semantics (`column-primary`, `data-colname`,
	 * `.toggle-row`), so the shared responsive CSS could never activate and
	 * every non-compliant screen clipped or overflowed on mobile/iPad. This
	 * renderer emits WP core's own list-table contract - the same markup
	 * wp-admin's common.js/CSS collapse natively at 782px - so a view that
	 * uses it is responsive by construction. bin/audit-admin-tables.php
	 * fails the release build when a new admin view bypasses it.
	 *
	 * Column widths are CLASSES applied desktop-only (`.jt-col-xs|s|m|l`),
	 * never inline pixel styles - fixed inline widths were the second half
	 * of the mobile breakage.
	 *
	 * @param array $args {
	 *     Table definition.
	 *
	 *     @type array    $columns   Required. key => { label, primary?, width?, class? }.
	 *                               Exactly one column may set primary; defaults
	 *                               to the FIRST column. width: 'xs'|'s'|'m'|'l'.
	 *     @type iterable $rows      Row items (arrays or objects).
	 *     @type callable $cell      Required. function( $row, string $key ): void - ECHOES cell content.
	 *     @type callable $row_attrs Optional. function( $row ): array of attr => value for the <tr>.
	 *     @type array    $empty     jetonomy_admin_empty_state() args when $rows is empty
	 *                               (colspan is filled in automatically).
	 *     @type string   $class     Extra classes for the <table>.
	 *     @type string   $table_id  Optional id="" for the <table> (JS hooks).
	 *     @type array    $table_attrs Optional attr => value pairs for the <table>
	 *                               (JS hooks that read off the table node).
	 *     @type string   $tbody_id  Optional id="" for the <tbody> (JS hooks).
	 *     @type bool     $wrap      Wrap in .jt-content-table-wrap. Default true.
	 * }
	 */
	function jetonomy_admin_table( array $args ): void {
		$columns = isset( $args['columns'] ) && is_array( $args['columns'] ) ? $args['columns'] : array();
		$cell    = $args['cell'] ?? null;
		if ( ! $columns || ! is_callable( $cell ) ) {
			_doing_it_wrong( __FUNCTION__, 'columns and a cell renderer are required.', '1.9.0' );
			return;
		}

		$rows      = $args['rows'] ?? array();
		$row_attrs = isset( $args['row_attrs'] ) && is_callable( $args['row_attrs'] ) ? $args['row_attrs'] : null;
		$wrap      = ! isset( $args['wrap'] ) || (bool) $args['wrap'];

		// Resolve the primary column: exactly one, defaulting to the first.
		$primary = '';
		foreach ( $columns as $key => $col ) {
			if ( ! empty( $col['primary'] ) ) {
				$primary = (string) $key;
				break;
			}
		}
		if ( '' === $primary ) {
			$primary = (string) array_key_first( $columns );
		}

		// CONTAINMENT BY CONSTRUCTION: every table gets a scroll container.
		// wrap=false call sites (tables living inside their own card) used to
		// emit a bare <table>, and in the 783-960px squeeze band the desktop
		// width classes pushed the document wide (QA 2026-07-30: Auto-Rules
		// grew the page to 840px at exactly 783px). A width the layout cannot
		// absorb now scrolls inside the container instead of widening the
		// page - at ANY viewport, for every current and future call site.
		echo $wrap ? '<div class="jt-content-table-wrap">' : '<div class="jt-table-scroll">';
		$table_id = ! empty( $args['table_id'] ) ? ' id="' . esc_attr( (string) $args['table_id'] ) . '"' : '';
		$tbody_id = ! empty( $args['tbody_id'] ) ? ' id="' . esc_attr( (string) $args['tbody_id'] ) . '"' : '';

		// Extra attributes for the <table> itself, for views whose JS reads a
		// value off the table node (e.g. the invite list's data-space-id).
		// Without this a call site would have to keep a hand-rolled <table>
		// purely to carry one attribute, which is how views drift back out of
		// the responsive contract.
		$table_attrs = '';
		if ( ! empty( $args['table_attrs'] ) && is_array( $args['table_attrs'] ) ) {
			foreach ( $args['table_attrs'] as $attr => $value ) {
				$table_attrs .= ' ' . esc_attr( (string) $attr ) . '="' . esc_attr( (string) $value ) . '"';
			}
		}

		echo '<table' . $table_id . $table_attrs . ' class="wp-list-table widefat fixed striped' . ( ! empty( $args['class'] ) ? ' ' . esc_attr( (string) $args['class'] ) : '' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- id and attrs escaped above.

		echo '<thead><tr>';
		foreach ( $columns as $key => $col ) {
			$th_class = 'manage-column column-' . sanitize_html_class( (string) $key );
			if ( (string) $key === $primary ) {
				$th_class .= ' column-primary';
			}
			if ( ! empty( $col['width'] ) && in_array( $col['width'], array( 'xs', 's', 'm', 'l' ), true ) ) {
				$th_class .= ' jt-col-' . $col['width'];
			}
			if ( ! empty( $col['class'] ) ) {
				$th_class .= ' ' . (string) $col['class'];
			}
			echo '<th scope="col" class="' . esc_attr( $th_class ) . '">' . esc_html( (string) ( $col['label'] ?? '' ) ) . '</th>';
		}
		echo '</tr></thead><tbody' . $tbody_id . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- id escaped above.

		$row_count = 0;
		foreach ( $rows as $row ) {
			++$row_count;
			$attrs = '';
			if ( $row_attrs ) {
				foreach ( (array) $row_attrs( $row ) as $a_key => $a_val ) {
					$attrs .= ' ' . esc_attr( (string) $a_key ) . '="' . esc_attr( (string) $a_val ) . '"';
				}
			}
			echo '<tr' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attrs escaped per pair above.
			foreach ( $columns as $key => $col ) {
				$is_primary = (string) $key === $primary;
				$td_class   = 'column-' . sanitize_html_class( (string) $key ) . ( $is_primary ? ' column-primary' : '' );
				if ( $is_primary ) {
					echo '<td class="' . esc_attr( $td_class ) . '">';
					$cell( $row, (string) $key );
					// Core's small-screen expander: common.js toggles
					// tr.is-expanded, the shared CSS renders the sheet.
					// aria-expanded is ours to manage (core never sets it) -
					// admin-settings.js syncs it on toggle.
					echo '<button type="button" class="toggle-row" aria-expanded="false"><span class="screen-reader-text">' . esc_html__( 'Show more details', 'jetonomy' ) . '</span></button>';
					echo '</td>';
				} else {
					echo '<td class="' . esc_attr( $td_class ) . '" data-colname="' . esc_attr( (string) ( $col['label'] ?? '' ) ) . '">';
					$cell( $row, (string) $key );
					echo '</td>';
				}
			}
			echo '</tr>';
		}

		if ( 0 === $row_count ) {
			$empty            = isset( $args['empty'] ) && is_array( $args['empty'] ) ? $args['empty'] : array( 'title' => __( 'Nothing here yet', 'jetonomy' ) );
			$empty['colspan'] = count( $columns );
			jetonomy_admin_empty_state( $empty );
		}

		echo '</tbody></table>';
		echo '</div>'; // Closes .jt-content-table-wrap or .jt-table-scroll.
	}
}
