<?php
/**
 * Settings -> Email lists every email type from one source (Admin::email_types()).
 *
 * `reaction` and `flag_resolved` had an on/off default and a sanitizer entry but
 * no template-editor row, because the view kept its own copy of the list. These
 * tests hold the editor, both sanitizers, the preview fixtures and Notifier's
 * defaults to the same set of types.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit\Admin;

use WP_UnitTestCase;
use Jetonomy\Admin\Admin;
use Jetonomy\Admin\Ajax\Settings_Handler;
use Jetonomy\Notifications\Notifier;

class EmailTypesTest extends WP_UnitTestCase {

	public function test_every_type_has_a_label_a_default_template_and_a_preview_fixture(): void {
		$fixtures = ( new \ReflectionMethod( Settings_Handler::class, 'sample_fixtures' ) )->invoke( null );

		foreach ( Admin::email_types() as $type => $def ) {
			$this->assertNotSame( '', $def['label'], "$type has no label" );
			$this->assertStringNotContainsString( '_', $def['label'], "$type label looks like a raw key" );
			$this->assertNotSame( '', Notifier::get_default_template( $type )['subject'], "$type has no default template" );
			$this->assertArrayHasKey( $type, $fixtures, "$type has no preview fixture, so Preview fails" );
		}
	}

	public function test_editor_lists_the_types_that_were_missing(): void {
		$types = Admin::email_types();
		foreach ( array( 'reaction', 'flag_resolved', 'join_request_result', 'verification' ) as $type ) {
			$this->assertArrayHasKey( $type, $types );
		}
		$this->assertFalse( $types['verification']['toggle'], 'verification is not a notification, so it has no on/off default' );
		$this->assertTrue( $types['join_request_result']['toggle'] );
	}

	public function test_template_overrides_for_every_listed_type_survive_save(): void {
		$input = array( '_submitted' => '1' );
		foreach ( array_keys( Admin::email_types() ) as $type ) {
			$input[ $type ] = array(
				'subject' => "Subject $type",
				'body'    => '',
			);
		}

		$clean = ( new \ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor()->sanitize_email_templates( $input );

		foreach ( array_keys( Admin::email_types() ) as $type ) {
			$this->assertSame( "Subject $type", $clean[ $type ]['subject'] ?? null, "$type override was discarded" );
		}
	}
}
