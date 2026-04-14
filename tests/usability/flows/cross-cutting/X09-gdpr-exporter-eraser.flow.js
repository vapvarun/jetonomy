// @ts-check
/**
 * X09 — GDPR exporter/eraser (P1)
 *
 * Verify that Jetonomy registers privacy hooks for the WordPress personal
 * data exporter and eraser. Pure CLI test.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'X09 — GDPR privacy exporter and eraser hooks registered', () => {

	test( 'personal data exporter filter is registered', () => {
		const hasExporter = wp( [ 'eval', `
			echo has_filter( 'wp_privacy_personal_data_exporters' ) ? 'yes' : 'no';
		` ] );
		expect( hasExporter ).toBe( 'yes' );
	} );

	test( 'personal data eraser filter is registered', () => {
		const hasEraser = wp( [ 'eval', `
			echo has_filter( 'wp_privacy_personal_data_erasers' ) ? 'yes' : 'no';
		` ] );
		expect( hasEraser ).toBe( 'yes' );
	} );

	test( 'jetonomy exporter is in the registered exporters list', () => {
		const inList = wp( [ 'eval', `
			$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
			$found = false;
			foreach ( $exporters as $key => $exporter ) {
				if ( stripos( $key, 'jetonomy' ) !== false || stripos( $exporter['exporter_friendly_name'] ?? '', 'jetonomy' ) !== false ) {
					$found = true;
					break;
				}
			}
			echo $found ? 'yes' : 'no';
		` ] );
		expect( inList ).toBe( 'yes' );
	} );
} );
