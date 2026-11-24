<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Integrations\Theme_Integration;

/**
 * QA J37: a Reign install in a folder named "reign" (not "reign-theme") was not recognised, so the dark-mode
 * mirror never loaded on Reign 7.9 and older. Parent and child slugs are both checked.
 */
class ThemeFamilyTest extends WP_UnitTestCase {

	private function family( string $template, string $stylesheet ): string {
		$t = static fn() => $template;
		$s = static fn() => $stylesheet;
		add_filter( 'template', $t );
		add_filter( 'stylesheet', $s );
		$family = Theme_Integration::theme_family();
		remove_filter( 'template', $t );
		remove_filter( 'stylesheet', $s );
		return $family;
	}

	public function test_folder_names_and_children_resolve_to_their_family(): void {
		$cases = array(
			array( 'reign', 'reign', 'reign' ),
			array( 'reign-theme', 'reign-theme', 'reign' ),
			array( 'reign-theme-8.1.1', 'reign-theme-8.1.1', 'reign' ),
			array( 'reign', 'reign-child', 'reign' ),
			array( 'reign-theme', 'reign-child', 'reign' ),
			array( 'my-parent', 'reign-child', 'reign' ),
			array( 'buddyx', 'buddyx', 'buddyx' ),
			array( 'buddyx', 'buddyx-child', 'buddyx' ),
			array( 'buddyx-pro', 'buddyx-pro', 'buddyx-pro' ),
			array( 'buddyx-pro', 'buddyx-pro-child', 'buddyx-pro' ),
			array( 'Reign', 'Reign', 'reign' ),
			array( 'twentytwentyfive', 'twentytwentyfive', '' ),
			array( 'reignite', 'reignite', '' ),
			array( 'twentytwentyfive', 'my-child', '' ),
		);
		foreach ( $cases as list( $template, $stylesheet, $expected ) ) {
			$this->assertSame( $expected, $this->family( $template, $stylesheet ), "{$template} / {$stylesheet}" );
		}
	}
}
