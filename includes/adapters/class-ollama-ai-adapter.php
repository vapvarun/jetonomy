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
		$settings       = get_option( 'jetonomy_settings', [] );
		$ai             = $settings['ai']['providers']['ollama'] ?? [];
		$this->base_url = $base_url ?: ( $ai['base_url'] ?? 'http://localhost:11434' );
		$this->model    = $model ?: ( $ai['model'] ?? 'llama3' );
	}

	public function is_active(): bool {
		$settings = get_option( 'jetonomy_settings', [] );
		return ! empty( $settings['ai']['providers']['ollama']['enabled'] )
			&& '' !== $this->base_url;
	}

	public function get_id(): string {
		return 'ollama';
	}

	public function get_name(): string {
		return 'Ollama (Self-hosted)';
	}

	public function chat( array $messages, array $options = [] ): array {
		$model = $options['model'] ?? $this->model;
		$body  = [
			'model'    => $model,
			'messages' => $messages,
			'stream'   => false,
		];

		if ( ! empty( $options['json_mode'] ) ) {
			$body['format'] = 'json';
		}

		if ( isset( $options['temperature'] ) ) {
			$body['options']['temperature'] = (float) $options['temperature'];
		}

		$response = $this->request( '/api/chat', $body );

		$content          = $response['message']['content'] ?? '';
		$prompt_tokens     = $response['prompt_eval_count'] ?? 0;
		$completion_tokens = $response['eval_count'] ?? 0;

		return [
			'content' => $content,
			'usage'   => [
				'prompt_tokens'     => (int) $prompt_tokens,
				'completion_tokens' => (int) $completion_tokens,
				'total_tokens'      => (int) $prompt_tokens + (int) $completion_tokens,
			],
			'model'   => $response['model'] ?? $model,
		];
	}

	public function embed( string $text, array $options = [] ): array {
		$model    = $options['model'] ?? $this->model;
		$response = $this->request( '/api/embeddings', [
			'model'  => $model,
			'prompt' => $text,
		] );

		if ( empty( $response['embedding'] ) ) {
			throw new \RuntimeException( 'Ollama returned no embedding data.' );
		}

		return [
			'embedding' => $response['embedding'],
			'model'     => $model,
			'usage'     => [
				'total_tokens' => 0,
			],
		];
	}

	public function get_models(): array {
		try {
			$response = wp_remote_get(
				rtrim( $this->base_url, '/' ) . '/api/tags',
				[ 'timeout' => 10 ]
			);

			if ( is_wp_error( $response ) ) {
				return [];
			}

			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$models = [];

			foreach ( $body['models'] ?? [] as $m ) {
				$name            = $m['name'] ?? '';
				$models[ $name ] = $name;
			}

			return $models;
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	public function test(): array {
		try {
			$models = $this->get_models();
			if ( empty( $models ) ) {
				return [
					'ok'    => false,
					'error' => 'Could not connect to Ollama or no models found.',
				];
			}

			return [
				'ok'    => true,
				'model' => array_key_first( $models ),
			];
		} catch ( \Throwable $e ) {
			return [
				'ok'    => false,
				'error' => $e->getMessage(),
			];
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
			[
				'timeout' => 60,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
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
