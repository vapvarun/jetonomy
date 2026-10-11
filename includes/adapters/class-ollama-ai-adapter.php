<?php
/**
 * Ollama AI adapter (self-hosted).
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

class Ollama_AI_Adapter implements AI_Adapter {

	private string $base_url;
	private string $model;

	public function __construct( string $base_url = '', string $model = '' ) {
		$ai             = self::ollama_settings();
		$this->base_url = $base_url ?: ( $ai['base_url'] ?? 'http://localhost:11434' );
		$this->model    = $model ?: ( $ai['model'] ?? 'llama3' );
	}

	/**
	 * Stored Ollama provider settings, from Jetonomy Pro's AI tab.
	 *
	 * AI is a Pro feature: Pro's AI extension registers this adapter, and its
	 * settings live under `jetonomy_pro_ai_settings`. Free registers no AI
	 * provider and stores no AI settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function ollama_settings(): array {
		$pro = get_option( 'jetonomy_pro_ai_settings', array() );

		return is_array( $pro ) ? (array) ( $pro['providers']['ollama'] ?? array() ) : array();
	}

	public function is_active(): bool {
		$ai = self::ollama_settings();

		return ! empty( $ai['enabled'] ) && '' !== $this->base_url;
	}

	public function get_id(): string {
		return 'ollama';
	}

	public function get_name(): string {
		return 'Ollama (Self-hosted)';
	}

	public function chat( array $messages, array $options = array() ): array {
		$model = $options['model'] ?? $this->model;
		$body  = array(
			'model'    => $model,
			'messages' => $messages,
			'stream'   => false,
		);

		if ( ! empty( $options['json_mode'] ) ) {
			$body['format'] = 'json';
		}

		if ( isset( $options['temperature'] ) ) {
			$body['options']['temperature'] = (float) $options['temperature'];
		}

		$response = $this->request( '/api/chat', $body );

		$content           = $response['message']['content'] ?? '';
		$prompt_tokens     = $response['prompt_eval_count'] ?? 0;
		$completion_tokens = $response['eval_count'] ?? 0;

		return array(
			'content' => $content,
			'usage'   => array(
				'prompt_tokens'     => (int) $prompt_tokens,
				'completion_tokens' => (int) $completion_tokens,
				'total_tokens'      => (int) $prompt_tokens + (int) $completion_tokens,
			),
			'model'   => $response['model'] ?? $model,
		);
	}

	public function embed( string $text, array $options = array() ): array {
		$model    = $options['model'] ?? $this->model;
		$response = $this->request(
			'/api/embeddings',
			array(
				'model'  => $model,
				'prompt' => $text,
			)
		);

		if ( empty( $response['embedding'] ) ) {
			throw new \RuntimeException( 'Ollama returned no embedding data.' );
		}

		return array(
			'embedding' => $response['embedding'],
			'model'     => $model,
			'usage'     => array(
				'total_tokens' => 0,
			),
		);
	}

	public function get_models(): array {
		try {
			$response = wp_remote_get(
				rtrim( $this->base_url, '/' ) . '/api/tags',
				array( 'timeout' => 10 )
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$models = array();

			foreach ( $body['models'] ?? array() as $m ) {
				$name            = $m['name'] ?? '';
				$models[ $name ] = $name;
			}

			return $models;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public function test(): array {
		try {
			$models = $this->get_models();
			if ( empty( $models ) ) {
				return array(
					'ok'    => false,
					'error' => 'Could not connect to Ollama or no models found.',
				);
			}

			return array(
				'ok'    => true,
				'model' => array_key_first( $models ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * Make an HTTP POST request to the Ollama API.
	 *
	 * @param string $endpoint API path (e.g. '/api/chat').
	 * @param array  $body     Request body.
	 * @return array Decoded response.
	 * @throws \RuntimeException On HTTP or decoding failure.
	 */
	private function request( string $endpoint, array $body ): array {
		$url      = rtrim( $this->base_url, '/' ) . $endpoint;
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Ollama request failed: ' . esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$error_body = wp_remote_retrieve_body( $response );
			throw new \RuntimeException(
				'Ollama returned HTTP ' . esc_html( (string) $code ) . ': ' . esc_html( $error_body )
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( null === $decoded ) {
			throw new \RuntimeException( 'Ollama returned invalid JSON.' );
		}

		return $decoded;
	}
}
