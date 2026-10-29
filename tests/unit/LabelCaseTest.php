<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

/**
 * German capitalises every noun, so a mid-sentence product label must keep its
 * capital there ("Mitglieder", never "mitglieder"); English lowercases it.
 */
class LabelCaseTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'locale' );
		remove_all_filters( 'jetonomy_label_lowercase' );
		parent::tear_down();
	}

	private function in_locale( string $locale ): void {
		add_filter( 'locale', static fn() => $locale );
	}

	public function test_english_lowercases_mid_sentence(): void {
		$this->assertSame( 'members', \Jetonomy\jetonomy_label( 'member', true, true ) );
	}

	public function test_german_keeps_the_capital(): void {
		$this->in_locale( 'de_DE' );
		$this->assertSame( 'Members', \Jetonomy\jetonomy_label( 'member', true, true ) );
		$this->assertSame( 'Members', \Jetonomy\label_map()['member']['many_lower'] );
	}

	public function test_filter_decides_for_other_locales(): void {
		$this->in_locale( 'xx_XX' );
		add_filter( 'jetonomy_label_lowercase', '__return_false' );
		$this->assertSame( 'Space', \Jetonomy\jetonomy_label( 'space', false, true ) );
	}
}
