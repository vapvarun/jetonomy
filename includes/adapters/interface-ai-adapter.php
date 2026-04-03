<?php
/**
 * AI adapter interface.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

interface AI_Adapter {

	/**
	 * Whether this adapter is configured and ready.
	 */
	public function is_active(): bool;

	/**
	 * Unique provider identifier (e.g. 'openai', 'anthropic', 'ollama').
	 */
	public function get_id(): string;

	/**
	 * Human-readable provider name.
	 */
	public function get_name(): string;

	/**
	 * Send a chat completion request.
	 *
	 * @param array $messages Array of ['role' => 'system'|'user'|'assistant', 'content' => string].
	 * @param array $options  Optional: model, temperature, max_tokens, json_mode.
	 * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}, model: string}
	 * @throws \RuntimeException On API failure.
	 */
	public function chat( array $messages, array $options = [] ): array;

	/**
	 * Generate embeddings for text.
	 *
	 * @param string $text    Input text.
	 * @param array  $options Optional: model.
	 * @return array{embedding: float[], model: string, usage: array{total_tokens: int}}
	 * @throws \RuntimeException On API failure or if provider does not support embeddings.
	 */
	public function embed( string $text, array $options = [] ): array;

	/**
	 * Return supported models as id => display_name.
	 *
	 * @return array<string, string>
	 */
	public function get_models(): array;

	/**
	 * Test the connection (validates API key + reachability).
	 *
	 * @return array{ok: bool, error?: string, model?: string}
	 */
	public function test(): array;
}
