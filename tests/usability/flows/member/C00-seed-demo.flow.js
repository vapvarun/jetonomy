// @ts-check
// Global setup — ensure demo community is seeded before flows run.
// Idempotent: demo-seed creates missing rows only.
const { test } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe.configure( { mode: 'serial' } );

test( 'aaa-seed demo community', async () => {
	try { wp( [ 'jetonomy', 'demo-seed' ] ); } catch ( e ) { /* idempotent */ }
} );
