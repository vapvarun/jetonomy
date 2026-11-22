<?php
/**
 * Browser-only behaviour from the 2.0.3 wave-3 walk (lane B), asserted on the
 * shipped source because PHPUnit has no browser: the mention picker, Load
 * More, dropdown placement above the phone tab bar, link-card direction and
 * the layouts that cut or overlapped names at 390px and 1440px.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

class MemberUiAssetsTest extends WP_UnitTestCase {

	private function src( string $path ): string {
		return (string) file_get_contents( JETONOMY_DIR . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** The CSS rule for $selector (first match), or ''. */
	private function rule( string $selector ): string {
		preg_match( '/(?:^|\n)\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $this->src( 'assets/css/jetonomy.css' ), $m );
		return $m[1] ?? '';
	}

	public function test_mention_picker_draws_initials_wraps_names_and_keeps_the_space(): void {
		$js = $this->src( 'assets/js/composer.js' );
		$this->assertStringContainsString( 'if ( m.avatar_url ) {', $js, 'no <img src=""> for a member without an avatar' );
		$this->assertStringContainsString( "avatar.className = 'jt-avatar jt-mention-avatar';", $js );
		$this->assertStringContainsString( "var inserted = '@' + pickHandle + '\\u00a0';", $js, 'a no-break space survives at the end of the line' );

		$name = $this->rule( '.jt-mention-name' );
		$this->assertStringNotContainsString( 'ellipsis', $name );
		$this->assertStringNotContainsString( 'nowrap', $name );
	}

	public function test_long_names_and_titles_wrap_instead_of_being_cut(): void {
		foreach ( array( '.jt-blocked-name', '.jt-space-card-title { font-size: 0.9375rem' ) as $selector ) {
			preg_match( '/' . preg_quote( $selector, '/' ) . '[^}]*\}/', $this->src( 'assets/css/jetonomy.css' ), $m );
			$this->assertNotEmpty( $m, $selector );
			$this->assertStringNotContainsString( 'ellipsis', $m[0], $selector );
			$this->assertStringContainsString( 'overflow-wrap: anywhere', $m[0], $selector );
		}
	}

	public function test_bookmark_toggle_has_its_own_column_instead_of_covering_the_row(): void {
		$toggle = $this->rule( '.jt-bookmark-toggle' );
		$this->assertStringNotContainsString( 'absolute', $toggle );
		$this->assertStringContainsString( '.jt-row:has(.jt-bookmark-toggle) { grid-template-columns: 48px minmax(0, 1fr) 96px 104px var(--jt-tap); }', $this->src( 'assets/css/jetonomy.css' ) );
		$this->assertStringContainsString( '.jt-row:has(.jt-bookmark-toggle) .jt-row-stat:has(.jt-row-time) { display: none; }', $this->src( 'assets/css/jetonomy.css' ), 'phone hides the time cell, which is no longer :last-child' );
	}

	public function test_load_more_skips_rows_already_shown_reports_failure_and_moves_focus(): void {
		$js = $this->src( 'assets/js/pagination-frontend.js' );
		$this->assertStringContainsString( "el.getAttribute('data-jt-key') || el.id", $js );
		$this->assertStringContainsString( 'if (k && seen[k]) { return; }', $js );
		$this->assertStringContainsString( "if (!r.ok) { throw new Error(String(r.status)); }", $js, 'an HTTP error is a failure too' );
		$this->assertStringContainsString( "err.setAttribute('role', 'alert');", $js );
		$this->assertStringContainsString( 'focusTarget.focus();', $js );
		$this->assertStringContainsString( '@media (max-width: 640px) { .jt-pagination-btn { width: 100%; } }', $this->src( 'assets/css/jetonomy.css' ) );
	}

	public function test_dropdowns_flip_above_the_phone_tab_bar(): void {
		$js = $this->src( 'assets/js/lib/smart-dropdown.js' );
		$this->assertStringContainsString( "document.querySelector( '.jt-mobile-tabs' )", $js );
		$this->assertStringContainsString( 'top + ph > viewportBottom() - flipPadding', $js );
	}

	public function test_link_card_text_takes_its_own_direction(): void {
		$js = $this->src( 'assets/js/view.js' );
		$this->assertStringContainsString( "title.dir = 'auto';", $js );
		$this->assertStringContainsString( "descEl.dir = 'auto';", $js );
	}

	public function test_join_request_decision_is_confirmed_on_the_members_tab(): void {
		$this->assertStringContainsString( 'if ( res && res.ok && res.data && res.data.message && window.bnToast ) {', $this->src( 'assets/js/view.js' ) );
	}

	public function test_scheduled_topic_offers_change_and_cancel_through_rest(): void {
		$js = $this->src( 'assets/js/view.js' );
		$this->assertStringContainsString( "body: { published_at: when }", $js );
		$this->assertStringContainsString( "body: { published_at: '' }", $js );
		$this->assertStringContainsString( 'data-wp-on--submit="actions.reschedulePost"', $this->src( 'templates/views/single-post.php' ) );
	}

	public function test_reply_polling_stops_when_the_topic_is_gone_and_backs_off_on_errors(): void {
		$js   = $this->src( 'assets/js/view.js' );
		$poll = substr( $js, strpos( $js, 'initReplyPolling()' ), 4000 );
		// A topic deleted while open used to be polled at the same pace forever.
		$this->assertMatchesRegularExpression( '/\[ 401, 403, 404, 410 \]\.includes\( response\.status \) \) \{\s*return;/', $poll );
		$this->assertMatchesRegularExpression( '/\} else \{\s*interval = Math\.min\( interval \* 2, MAX_INTERVAL \);\s*\}\s*\} catch/', $poll );
	}

	// 2.0.3 release walk.

	/**
	 * The report dialog closes only when the request succeeds: a failed or
	 * rejected request keeps it open with the chosen reason and typed details.
	 *
	 * Runs the shipped jetonomyReport() in jsdom (node), clicking Report the way
	 * a member does, so a refactor that keeps the behaviour keeps the test green.
	 */
	public function test_report_dialog_stays_open_with_inputs_when_the_request_fails(): void {
		$js = $this->src( 'assets/js/view.js' );
		foreach ( array( 'post', 'reply', 'user' ) as $type ) {
			$this->assertStringContainsString( "jtSubmitFlag( '{$type}', ", $js );
		}
		$this->assertSame( 1, substr_count( $js, "restFetch( '/flags'" ), 'one report request path' );

		$node  = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		$jsdom = JETONOMY_DIR . 'node_modules/jsdom';
		if ( '' === $node || ! is_dir( $jsdom ) ) {
			$this->markTestSkipped( 'node and node_modules/jsdom are needed to run the report dialog (npm install).' );
		}

		$start = strpos( $js, 'function jetonomyReport(' );
		$this->assertNotFalse( $start, 'jetonomyReport() is gone from view.js' );
		$fn = substr( $js, $start, strpos( $js, "\n}\n", $start ) + 2 - $start );

		$harness = <<<'JS'
const { JSDOM } = require( process.argv[2] );
const fs = require( 'fs' );
const { window } = new JSDOM( '<body></body>', { runScripts: 'outside-only' } );
window.eval( 'function jtModalI18n() { return {}; }\n' + fs.readFileSync( process.argv[3], 'utf8' ) + '\nwindow.jetonomyReport = jetonomyReport;' );
const d = window.document;
const tick = () => new Promise( ( r ) => setTimeout( r, 0 ) );
async function attempt( outcome ) {
	const calls = [];
	let result = 'pending';
	window.jetonomyReport( 'Why?', {}, ( report ) => {
		calls.push( report );
		return 'reject' === outcome ? Promise.reject( new Error( 'net' ) ) : Promise.resolve( outcome );
	} ).then( ( v ) => { result = v; } );
	d.querySelector( 'input[value="spam"]' ).click();
	d.querySelector( 'textarea' ).value = 'Selling things';
	const ok = d.querySelector( '.jt-btn-fill' );
	ok.click();
	await tick();
	const open = !! d.querySelector( '.jt-modal-overlay' );
	const row = {
		calls, result, open,
		reason: open ? d.querySelector( 'input:checked' )?.value : null,
		details: open ? d.querySelector( 'textarea' ).value : null,
		okEnabled: open ? ! ok.disabled : null,
		okFocused: open ? d.activeElement === ok : null,
	};
	if ( open ) { d.querySelector( '.jt-btn-ghost' ).click(); await tick(); }
	return row;
}
( async () => {
	const out = {};
	for ( const o of [ false, 'reject', true ] ) out[ String( o ) ] = await attempt( o );
	process.stdout.write( JSON.stringify( out ) );
} )();
JS;
		$dir = get_temp_dir();
		file_put_contents( $dir . 'jt-report-fn.js', $fn ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . 'jt-report-harness.js', $harness ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$raw = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $dir . 'jt-report-harness.js' ) . ' ' . escapeshellarg( $jsdom ) . ' ' . escapeshellarg( $dir . 'jt-report-fn.js' ) . ' 2>&1' );
		$out = json_decode( $raw, true );
		$this->assertIsArray( $out, 'harness output: ' . $raw );

		$sent = array(
			array(
				'reason'      => 'spam',
				'description' => 'Selling things',
			),
		);
		foreach ( array( 'false', 'reject' ) as $failed ) {
			$this->assertSame( $sent, $out[ $failed ]['calls'], "$failed: submit gets the reason and details" );
			$this->assertTrue( $out[ $failed ]['open'], "$failed: the dialog stays open" );
			$this->assertSame( 'spam', $out[ $failed ]['reason'], "$failed: the reason is kept" );
			$this->assertSame( 'Selling things', $out[ $failed ]['details'], "$failed: the details are kept" );
			$this->assertTrue( $out[ $failed ]['okEnabled'], "$failed: Report can be pressed again" );
			$this->assertTrue( $out[ $failed ]['okFocused'], "$failed: focus stays on Report, inside the dialog" );
		}
		$this->assertSame( $sent, $out['true']['calls'] );
		$this->assertFalse( $out['true']['open'], 'a filed report closes the dialog' );
		$this->assertTrue( $out['true']['result'], 'and resolves true' );
	}

	/** Deleting or bulk-acting on notifications shows the server's fresh tab counts (All, Unread, type tabs). */
	public function test_notification_tab_counts_follow_deletes(): void {
		$js = $this->src( 'assets/js/view.js' );
		$this->assertSame( 2, substr_count( $js, 'notifSetTabCounts( res.data?.counts );' ), 'single delete and bulk' );
		$this->assertStringContainsString( "window.jtSetCountBadge( tab, 'jt-notif-tab__count', counts[ slug ] );", $js );
		$this->assertStringContainsString( 'window.jtSetCountBadge = function (host, cls, count)', $this->src( 'assets/js/header.js' ) );
	}

	/** @covers-existing The bell's focus refresh reaches the page's Unread tab: it is one of the unread hosts. */
	public function test_unread_refresh_updates_the_unread_tab(): void {
		$this->assertStringContainsString( "'unread' === \$tab_slug ? 'data-jt-unread-host=\"jt-notif-tab__count\"'", $this->src( 'templates/views/notifications.php' ) );
		$this->assertStringContainsString( "document.querySelectorAll('[data-jt-unread-host]')", $this->src( 'assets/js/header.js' ) );
	}

	/** Only a 409/404 blames another moderator; a request that never reached the server says so. */
	public function test_approval_network_failure_is_not_blamed_on_another_moderator(): void {
		$js = $this->src( 'assets/js/view.js' );
		$this->assertMatchesRegularExpression( "/if \\( 0 === res\\.status \\) \\{\\s*p\\.textContent = i18n\\.networkErrorRetry/", $js );
		$this->assertMatchesRegularExpression( "/\\} else if \\( 409 === res\\.status \\|\\| 404 === res\\.status \\) \\{\\s*p\\.textContent = i18n\\.approvalFailed/", $js );
	}
}
