// @ts-check

/**
 * Layer 4 — Ease-of-use metrics collector.
 *
 * Wraps a Playwright page with instrumentation that counts clicks, network
 * errors, console errors, and time-to-goal. Flow tests assert on these
 * counters directly so a feature that technically works but requires 7
 * clicks and 3 dismissals to complete a basic task gets surfaced as a
 * usability regression, not a pass.
 *
 * Usage:
 *   const metrics = new EaseMetrics( page );
 *   metrics.start();
 *   await page.click( '.btn' ); metrics.recordClick();
 *   ...
 *   metrics.assertClickCount( { lessThanOrEqual: 3 } );
 *   metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
 *   metrics.assertErrorCount( 0 );
 */
class EaseMetrics {
	constructor( page ) {
		this.page = page;
		this.clicks = 0;
		this.startedAt = 0;
		this.consoleErrors = [];
		this.pageErrors = [];
		this.failedRequests = [];

		page.on( 'console', ( msg ) => {
			if ( msg.type() === 'error' ) {
				this.consoleErrors.push( msg.text() );
			}
		} );
		page.on( 'pageerror', ( err ) => {
			this.pageErrors.push( err.message );
		} );
		page.on( 'requestfailed', ( request ) => {
			this.failedRequests.push( `${ request.method() } ${ request.url() }: ${ request.failure()?.errorText }` );
		} );
		page.on( 'response', ( response ) => {
			if ( response.status() >= 500 ) {
				this.failedRequests.push( `5xx ${ response.status() } ${ response.url() }` );
			}
		} );
	}

	start() {
		this.startedAt = Date.now();
		this.clicks = 0;
		this.consoleErrors = [];
		this.pageErrors = [];
		this.failedRequests = [];
	}

	recordClick() {
		this.clicks += 1;
	}

	getElapsedMs() {
		if ( ! this.startedAt ) {
			return 0;
		}
		return Date.now() - this.startedAt;
	}

	getErrorCount() {
		return this.consoleErrors.length + this.pageErrors.length + this.failedRequests.length;
	}

	summary() {
		return {
			clicks: this.clicks,
			elapsed_ms: this.getElapsedMs(),
			console_errors: this.consoleErrors,
			page_errors: this.pageErrors,
			failed_requests: this.failedRequests,
			error_count: this.getErrorCount(),
		};
	}

	assertClickCount( { lessThanOrEqual } ) {
		if ( this.clicks > lessThanOrEqual ) {
			throw new Error(
				`EaseMetrics: too many clicks to complete the flow. Got ${ this.clicks }, budget ${ lessThanOrEqual }.`
			);
		}
	}

	assertTimeToGoal( { lessThanSeconds } ) {
		const elapsedSec = this.getElapsedMs() / 1000;
		if ( elapsedSec > lessThanSeconds ) {
			throw new Error(
				`EaseMetrics: flow took ${ elapsedSec.toFixed( 1 ) }s, budget ${ lessThanSeconds }s.`
			);
		}
	}

	assertErrorCount( expected ) {
		const count = this.getErrorCount();
		if ( count !== expected ) {
			throw new Error(
				`EaseMetrics: expected ${ expected } errors, got ${ count }.\n`
				+ `  console: ${ JSON.stringify( this.consoleErrors ) }\n`
				+ `  page: ${ JSON.stringify( this.pageErrors ) }\n`
				+ `  requests: ${ JSON.stringify( this.failedRequests ) }`
			);
		}
	}
}

module.exports = { EaseMetrics };
