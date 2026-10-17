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
}
