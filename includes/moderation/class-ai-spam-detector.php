<?php
/**
 * AI-powered spam detection (free version — Ollama only).
 *
 * @package Jetonomy
 */

namespace Jetonomy\Moderation;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Models\UserProfile;

class AI_Spam_Detector {

	public function __construct() {
		$settings = get_option( 'jetonomy_settings', [] );
		if ( ! empty( $settings['ai']['spam_detection']['enabled'] ) ) {
			add_filter( 'jetonomy_check_content', [ $this, 'check_spam' ], 15, 4 );
		}
	}

	/**
	 * Check content for spam via AI.
	 *
	 * @param string|null $action     Current moderation action (null = no action yet).
	 * @param array       $data       Content data with 'title' and 'content' keys.
	 * @param int         $space_id   Space ID.
	 * @param int         $user_id    Author user ID.
	 * @return string|null 'spam', 'hold', or null (pass through).
	 */
	public function check_spam( ?string $action, array $data, int $space_id, int $user_id ): ?string {
		if ( $action ) {
			return $action;
		}

		$adapter = Adapter_Registry::get_ai( 'ollama' );
		if ( ! $adapter || ! $adapter->is_active() ) {
			return $action;
		}

		$content = trim( ( $data['title'] ?? '' ) . ' ' . ( $data['content'] ?? '' ) );
		if ( '' === $content ) {
			return $action;
		}

		$profile     = UserProfile::find_by_user( $user_id );
		$trust_level = $profile ? (int) $profile->trust_level : 0;
		$account_age = $profile ? (int) floor( ( time() - strtotime( $profile->created_at ) ) / DAY_IN_SECONDS ) : 0;

		$settings  = get_option( 'jetonomy_settings', [] );
		$threshold = (float) ( $settings['ai']['spam_detection']['threshold'] ?? 0.8 );
		$hold_at   = (float) ( $settings['ai']['spam_detection']['hold_threshold'] ?? 0.5 );

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are a spam classifier for a community forum. Respond ONLY with JSON: {"spam": true|false, "confidence": 0.0-1.0, "reason": "brief reason"}. Do not include any other text.',
			],
			[
				'role'    => 'user',
				'content' => sprintf(
					"Classify this forum post as spam or not spam.\n\nAuthor trust level: %d\nAccount age: %d days\n\nContent:\n%s",
					$trust_level,
					$account_age,
					mb_substr( $content, 0, 2000 )
				),
			],
		];

		try {
			$result = $adapter->chat( $messages, [
				'json_mode'  => true,
				'max_tokens' => 100,
			] );

			$parsed = json_decode( $result['content'], true );
			if ( ! is_array( $parsed ) ) {
				return $action;
			}

			$is_spam    = ! empty( $parsed['spam'] );
			$confidence = (float) ( $parsed['confidence'] ?? 0 );

			if ( $is_spam && $confidence >= $threshold ) {
				return 'spam';
			}

			if ( $is_spam && $confidence >= $hold_at ) {
				return 'hold';
			}
		} catch ( \Throwable $e ) {
			// AI unavailable — don't block content.
		}

		return $action;
	}
}
