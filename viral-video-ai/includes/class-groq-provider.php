<?php
/**
 * Groq adapter (OpenAI-compatible endpoints, very fast inference).
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Groq_Provider
 */
class VVAI_Groq_Provider extends VVAI_Provider_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'groq';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return 'Groq';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_model() {
		return 'llama-3.3-70b-versatile';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_base_url() {
		return 'https://api.groq.com/openai/v1';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_model_options() {
		return array(
			'llama-3.3-70b-versatile',
			'llama-3.1-8b-instant',
			'llama-3.2-90b-vision-preview',
			'openai/gpt-oss-120b',
			'qwen/qwen3-32b',
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
	public function transcription_mode() {
		return 'audio_api';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities() {
		return array_merge(
			parent::get_capabilities(),
			array(
				'transcription_models' => array( 'whisper-large-v3-turbo', 'whisper-large-v3' ),
				'api_key_prefix'       => 'gsk_',
				'docs'                 => 'https://console.groq.com/keys',
				'notes'                => __( 'Free tier with generous rate limits; response_format json_object is supported. Timestamped transcription uses Whisper large v3 turbo.', 'viral-video-ai' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_transcription_model() {
		return 'whisper-large-v3-turbo';
	}
}
