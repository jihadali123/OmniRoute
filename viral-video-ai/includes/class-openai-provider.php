<?php
/**
 * OpenAI adapter.
 *
 * Chat Completions (OpenAI-compatible JSON mode) + `/audio/transcriptions`.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_OpenAI_Provider
 */
class VVAI_OpenAI_Provider extends VVAI_Provider_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return 'OpenAI';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_model() {
		return 'gpt-4o-mini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_base_url() {
		return 'https://api.openai.com/v1';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_model_options() {
		return array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-2024-11-20' );
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
				'transcription_models' => array( 'whisper-1', 'gpt-4o-transcribe', 'gpt-4o-mini-transcribe' ),
				'api_key_prefix'       => 'sk-',
				'docs'                 => 'https://platform.openai.com/api-keys',
				'notes'                => __( 'Whisper transcription and JSON mode are both available. Use a paid or credited key for the largest models.', 'viral-video-ai' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_transcription_model() {
		return 'whisper-1';
	}
}
