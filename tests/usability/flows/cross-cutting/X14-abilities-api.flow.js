// @ts-check
/**
 * X14 — Abilities API (P2)
 *
 * Verify that Jetonomy registers abilities via the WordPress Abilities API.
 * Pure CLI test.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'X14 — Abilities API registration', () => {

	test( 'jetonomy/create-post ability is registered', () => {
		const registered = wp( [ 'eval', `
			if ( ! function_exists( 'wp_abilities_get_registered' ) ) {
				echo 'no-api';
			} else {
				$abilities = wp_abilities_get_registered();
				echo isset( $abilities['jetonomy/create-post'] ) ? 'yes' : 'no';
			}
		` ] );

		if ( registered === 'no-api' ) {
			test.fixme( true, 'WP Abilities API not available — function wp_abilities_get_registered not found' );
			return;
		}

		expect( registered ).toBe( 'yes' );
	} );

	test( 'jetonomy ability category is registered', () => {
		const hasCat = wp( [ 'eval', `
			if ( ! function_exists( 'wp_abilities_get_categories' ) ) {
				echo 'no-api';
			} else {
				$cats = wp_abilities_get_categories();
				$found = false;
				foreach ( $cats as $cat ) {
					if ( stripos( $cat['slug'] ?? $cat['name'] ?? '', 'jetonomy' ) !== false ) {
						$found = true;
						break;
					}
				}
				echo $found ? 'yes' : 'no';
			}
		` ] );

		if ( hasCat === 'no-api' ) {
			test.fixme( true, 'WP Abilities API not available' );
			return;
		}

		expect( hasCat ).toBe( 'yes' );
	} );
} );
