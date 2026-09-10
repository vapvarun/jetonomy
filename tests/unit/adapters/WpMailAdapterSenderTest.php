<?php
namespace Jetonomy\Tests\Unit\Adapters;

use WP_UnitTestCase;
use Jetonomy\Adapters\WP_Mail_Adapter;

/**
 * Jetonomy asserts its own From on its own mail, and on nothing else.
 *
 * A `From:` header alone does not survive. wp_mail() parses the header, then
 * passes the address it found through the `wp_mail_from` filter, so any plugin
 * filtering site-wide gets the last word - and a plugin that returns its own
 * sender without inspecting the incoming value discards ours. With Learnomy
 * active, a community configured as community@example.com sent as the
 * WordPress admin address instead, breaking SPF/DKIM alignment on every
 * notification (Basecamp 10280327270).
 *
 * The fix registers wp_mail_from / wp_mail_from_name at PHP_INT_MAX around our
 * own send and removes them immediately after. Two things therefore need
 * pinning, and the second matters as much as the first:
 *
 *   1. our sender wins on our mail, even against a rude site-wide filter
 *   2. the filters do not leak - a later wp_mail() by anyone else is untouched
 *
 * Without (2) the fix would simply move the bug onto every other plugin, which
 * is the same bug pointed outward.
 *
 * A stand-in filter plays the part of the offending plugin so the suite has no
 * third-party dependency; the mechanism is what is under test, not Learnomy.
 */
class WpMailAdapterSenderTest extends WP_UnitTestCase {

	private const RUDE_SENDER = 'stomper@other-plugin.test';
	private const OURS        = 'community@jetonomy.test';

	public function set_up(): void {
		parent::set_up();

		$settings                       = get_option( 'jetonomy_settings', array() );
		$settings['email_from_email']   = self::OURS;
		$settings['email_from_name']    = 'Community Team';
		update_option( 'jetonomy_settings', $settings );

		// Stand-in for a plugin that filters the sender site-wide and returns
		// its own value without looking at what it was handed. Priority 20 is
		// what Learnomy used.
		add_filter( 'wp_mail_from', array( $this, 'rude_sender' ), 20 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_mail_from', array( $this, 'rude_sender' ), 20 );
		parent::tear_down();
	}

	public function rude_sender(): string {
		return self::RUDE_SENDER;
	}

	/**
	 * Resolve the sender the way wp_mail() does, so the assertion measures the
	 * value that would actually go on the wire rather than a header string we
	 * hopefully built correctly.
	 */
	private function effective_sender(): string {
		return (string) apply_filters( 'wp_mail_from', 'placeholder@example.test' );
	}

	public function test_our_sender_wins_on_our_own_mail(): void {
		$seen = null;

		// Sample the sender from inside the send, which is the only moment the
		// scoped filters are registered.
		add_action( 'phpmailer_init', function () use ( &$seen ) {
			$seen = $this->effective_sender();
		} );

		( new WP_Mail_Adapter() )->send(
			'member@example.test',
			'Scoped From test',
			'<p>body</p>',
			'body'
		);

		$this->assertSame(
			self::OURS,
			$seen,
			'Jetonomy mail must carry the configured sender even when another plugin filters wp_mail_from site-wide.'
		);
	}

	public function test_other_plugins_mail_is_left_alone(): void {
		// Before: the rude filter owns the sender.
		$this->assertSame(
			self::RUDE_SENDER,
			$this->effective_sender(),
			'Precondition: the stand-in filter should own the sender before we send anything.'
		);

		( new WP_Mail_Adapter() )->send( 'member@example.test', 'Ours', '<p>x</p>', 'x' );

		// After: unchanged. If the scoped filters leaked, ours would still win
		// here and every other plugin's mail would now be sent as Jetonomy.
		$this->assertSame(
			self::RUDE_SENDER,
			$this->effective_sender(),
			'The scoped From filters must be removed after our send - inflicting this bug outward is still this bug.'
		);
	}

	/**
	 * The filters are removed in a `finally`, so an exception mid-send must not
	 * strand them. Without that, one failed send turns Jetonomy into the
	 * site-wide stomper for the rest of the request.
	 */
	public function test_filters_are_removed_even_when_the_send_throws(): void {
		$boom = function () {
			throw new \RuntimeException( 'PHPMailer exploded' );
		};
		add_action( 'phpmailer_init', $boom );

		try {
			( new WP_Mail_Adapter() )->send( 'member@example.test', 'Boom', '<p>x</p>', 'x' );
		} catch ( \Throwable $e ) {
			// wp_mail may surface or swallow this depending on the WP version;
			// either way the assertion below is the point.
		}

		remove_action( 'phpmailer_init', $boom );

		$this->assertSame(
			self::RUDE_SENDER,
			$this->effective_sender(),
			'A throwing send must still clean up its wp_mail_from filters.'
		);
	}
}
