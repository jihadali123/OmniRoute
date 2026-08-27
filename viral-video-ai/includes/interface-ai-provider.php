<?php
/**
 * The contract every AI provider adapter must satisfy.
 *
 * The video engine only talks to this interface (through VVAI_AI_Router), which
 * is what makes swapping OpenAI ⇄ Gemini ⇄ Claude ⇄ Groq ⇄ OpenRouter ⇄ Custom a
 * configuration change instead of a code change.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface VVAI_AI_Provider_Interface
 */
interface VVAI_AI_Provider_Interface {

	/**
	 * Machine key, e.g. `openai`.
	 *
	 * @return string
	 */
	public function get_key();

	/**
	 * Human label, e.g. `OpenAI`.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Model used when the administrator did not choose one.
	 *
	 * @return string
	 */
	public function get_default_model();

	/**
	 * Suggested models shown in the Advanced section.
	 *
	 * @return array<int,string>
	 */
	public function get_model_options();

	/**
	 * Default API root, without trailing slash.
	 *
	 * @return string
	 */
	public function get_base_url();

	/**
	 * Whether the provider supports constrained JSON output.
	 *
	 * @return bool
	 */
	public function supports_json();

	/**
	 * Whether this adapter can transcribe audio (and how).
	 *
	 * @return string `none`, `audio_api` or `inline_content`.
	 */
	public function transcription_mode();

	/**
	 * Feature map used by the UI and the diagnostics screen.
	 *
	 * @return array<string,mixed>
	 */
	public function get_capabilities();

	/**
	 * Verify the credentials with a real request to the provider.
	 *
	 * @param array<string,mixed> $connection Connection record (includes the decrypted secret under `api_key`).
	 * @return array{ok:bool,code:string,message:string,http:int,latency:int,retryable:bool,models:array<int,string>}
	 */
	public function validate_credentials( array $connection );

	/**
	 * Generate text (optionally JSON) from a prompt.
	 *
	 * @param array<string,mixed> $connection Connection record.
	 * @param array<string,mixed> $request    {
	 *     @type string $prompt       User prompt.
	 *     @type string $system       System instructions.
	 *     @type bool   $json         Require JSON output.
	 *     @type float  $temperature  Sampling temperature.
	 *     @type int    $max_tokens   Response ceiling.
	 *     @type int    $timeout      HTTP timeout.
	 *     @type string $job_hint     Log context.
	 * }
	 * @return array{ok:bool,text:string,json:mixed,code:string,message:string,http:int,latency:int,retryable:bool,usage:array<string,int>,model:string}
	 */
	public function generate( array $connection, array $request );

	/**
	 * Convenience wrapper: send the viral-analysis prompt and decode the result.
	 *
	 * @param array<string,mixed> $connection Connection record.
	 * @param array<string,mixed> $payload     {prompt, system, expected_clips, …}.
	 * @return array{ok:bool,clips:array<int,array<string,mixed>>,code:string,message:string,raw:string,usage:array<string,int>}
	 */
	public function analyze_transcript( array $connection, array $payload );

	/**
	 * Transcribe an extracted audio file into normalized segments.
	 *
	 * @param array<string,mixed> $connection  Connection record.
	 * @param string              $audio_path  Absolute path to the audio file.
	 * @param array<string,mixed> $args        {language, model, prompt, offset}.
	 * @return array{ok:bool,segments:array<int,array{start:float,end:float,text:string}>,text:string,code:string,message:string}
	 */
	public function transcribe( array $connection, $audio_path, array $args = array() );

	/**
	 * Normalise a provider error into the shared error shape.
	 *
	 * @param array<string,mixed> $error Error from VVAI_Api_Connection.
	 * @return array<string,mixed>
	 */
	public function normalize_error( array $error );
}
