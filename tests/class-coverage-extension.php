<?php
/**
 * Names the running test for the coverage recorder.
 *
 * With JETONOMY_COVERAGE_OUT set, every REST request that matches no route is
 * logged against the test that sent it, so bin/qa-runtime-coverage.php
 * can name a test that is calling a route the plugin no longer has.
 *
 * @package Jetonomy
 */

use PHPUnit\Runner\BeforeTestHook;

class Jetonomy_Coverage_Extension implements BeforeTestHook {

	public function executeBeforeTest( string $test ): void {
		if ( class_exists( '\\Jetonomy\\QA\\Coverage_Recorder', false ) ) {
			\Jetonomy\QA\Coverage_Recorder::set_test( $test );
		}
	}
}
