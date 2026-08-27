<?php
/**
 * OpenRouter adapter.
 *
 * OpenAI-compatible chat completions with the required attribution headers.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_OpenRouter_Provider
 */
class VVAI_OpenRouter_Provider extends VVAI_Provider_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'openrouter';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return 'OpenRouter';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_model() {
		return 'openai/gpt-4o-mini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_base_url() {
		return 'https://openrouter.ai/api/v1';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_model_options() {
		return array(
			'openai/gpt-4o-mini',
			'anthropic/claude-3.5-haiku',
			'google/gemini-2.0-flash-001',
			'meta-llama/llama-3.3-70b-instruct',
			'deepseek/deepseek-chat',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_native_json() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function auth_style() {
		return 'bearer+referer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities() {
		return array_merge(
			parent::get_capabilities(),
			array(
				'transcription_models' => array(),
				'api_key_prefix'       => 'sk-or-',
				'docs'                 => 'https://openrouter.ai/settings/keys',
				'notes'                => __( 'One key for hundreds of models, including free variants (`*:free`). No audio transcription endpoint: pair it with OpenAI, Groq or a custom transcription endpoint.', 'viral-video-ai' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base_headers( array $connection, $secret ) {
		$headers = parent::base_headers( $connection, $secret );

		// OpenRouter asks for attribution headers; without them some routing
		// and rate-limit tiers behave differently.
		$headers['HTTP-Referer'] = (string) get_option( 'home', '' );
		$headers['X-Title']      = substr( (string) get_option( 'blogname', 'WordPress' ), 0, 60 );

		return $headers;
	}
}
