<?php
/**
 * Admin: settings.
 *
 * The form is generated from a declarative field spec so every control gets the
 * same escaping and the view stays short enough to audit. Values are sanitized by
 * VVAI_Settings::sanitize() on save, not here, so this file cannot introduce a
 * bypass.
 *
 * @var array<string,mixed>              $settings
 * @var array<string,mixed>              $defaults
 * @var array<string,int|string>         $limits
 * @var array<int,array<string,mixed>>   $connections
 * @var array<string,mixed>              $ffmpeg
 * @var array<string,mixed>              $scheduler
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

$vvai_connected = array( '' => __( '— none —', 'viral-video-ai' ) );

foreach ( (array) $connections as $vvai_connection ) {
	if ( 'connected' === (string) $vvai_connection['status'] ) {
		$vvai_connected[ (string) $vvai_connection['id'] ] = (string) $vvai_connection['title'] . ' · ' . (string) $vvai_connection['providerLabel'];
	}
}

$vvai_sections = array(
	array(
		'title'  => __( 'AI engine', 'viral-video-ai' ),
		'fields' => array(
			array( 'active_connection_id', __( 'Active connection', 'viral-video-ai' ), 'select', array( 'options' => $vvai_connected, 'hint' => __( 'Video jobs use this connection. A disconnected key can never be selected.', 'viral-video-ai' ) ) ),
			array( 'allow_fallback', __( 'Fall back to a second connection on network or capacity errors', 'viral-video-ai' ), 'checkbox' ),
			array( 'fallback_connection_id', __( 'Fallback connection', 'viral-video-ai' ), 'select', array( 'options' => $vvai_connected ) ),
			array( 'max_clips', __( 'Maximum clips per job', 'viral-video-ai' ), 'number', array( 'min' => 1, 'max' => 20 ) ),
			array( 'temperature', __( 'Model temperature', 'viral-video-ai' ), 'number', array( 'min' => 0, 'max' => 2, 'step' => '0.1' ) ),
			array( 'results_order', __( 'Clip order', 'viral-video-ai' ), 'select', array( 'options' => array(
				'score'  => __( 'Viral score, highest first', 'viral-video-ai' ),
				'chrono' => __( 'Chronological', 'viral-video-ai' ),
			) ) ),
		),
	),
	array(
		'title'  => __( 'Transcription', 'viral-video-ai' ),
		'fields' => array(
			array( 'transcription_source', __( 'Engine', 'viral-video-ai' ), 'select', array(
				'options' => array(
					'auto'        => __( 'Automatic (recommended)', 'viral-video-ai' ),
					'connection'  => __( 'The connected AI provider', 'viral-video-ai' ),
					'custom'      => __( 'Custom OpenAI-compatible endpoint', 'viral-video-ai' ),
					'whisper-cli' => __( 'Local Whisper binary', 'viral-video-ai' ),
					'disabled'    => __( 'Disabled', 'viral-video-ai' ),
				),
				'hint'    => __( 'Automatic uses the connection when it can transcribe (OpenAI, Groq, Gemini), then a custom endpoint, then a local binary.', 'viral-video-ai' ),
			) ),
			array( 'transcription_model', __( 'Transcription model', 'viral-video-ai' ), 'text', array( 'placeholder' => 'whisper-1' ) ),
			array( 'transcription_base_url', __( 'Custom endpoint base URL', 'viral-video-ai' ), 'url', array( 'placeholder' => 'https://gateway.example.com/v1' ) ),
			array( 'transcription_api_key', __( 'Custom endpoint API key', 'viral-video-ai' ), 'password', array( 'value' => '', 'hint' => __( 'Leave blank to keep the stored key.', 'viral-video-ai' ) ) ),
			array( 'whisper_binary', __( 'Local Whisper binary', 'viral-video-ai' ), 'text', array( 'placeholder' => '/usr/local/bin/whisper-cpp' ) ),
			array( 'transcription_chunk_minutes', __( 'Audio chunk length (minutes)', 'viral-video-ai' ), 'number', array( 'min' => 1, 'max' => 60, 'hint' => __( 'Long videos are split into chunks so a single request never exceeds the provider limit.', 'viral-video-ai' ) ) ),
			array( 'transcript_language', __( 'Spoken language code', 'viral-video-ai' ), 'text', array( 'placeholder' => 'en', 'hint' => __( 'Blank means auto-detect.', 'viral-video-ai' ) ) ),
		),
	),
	array(
		'title'  => __( 'Server & rendering', 'viral-video-ai' ),
		'fields' => array(
			array( 'ffmpeg_path', __( 'FFmpeg path', 'viral-video-ai' ), 'text', array( 'placeholder' => 'ffmpeg' ) ),
			array( 'ffprobe_path', __( 'FFprobe path', 'viral-video-ai' ), 'text', array( 'placeholder' => 'ffprobe' ) ),
			array( 'max_upload_mb', __( 'Maximum upload (MB - 0 means no limit)', 'viral-video-ai' ), 'number', array( 'min' => 0, 'max' => 2621440, 'hint' => sprintf( /* translators: %s: size. */ __( 'Videos arrive in chunks, so only one chunk has to fit the PHP limits. Current ceiling: %s. Set 0 for no cap.', 'viral-video-ai' ), size_format( (int) $limits['effective'] ) ) ) ),
			array( 'upload_chunk_size', __( 'Upload chunk size (bytes)', 'viral-video-ai' ), 'number', array( 'min' => 262144, 'max' => 33554432, 'step' => 65536 ) ),
			array( 'process_timeout', __( 'FFmpeg / API timeout (seconds)', 'viral-video-ai' ), 'number', array( 'min' => 30, 'max' => 14400 ) ),
			array( 'max_execution_budget', __( 'Work budget per request (seconds)', 'viral-video-ai' ), 'number', array( 'min' => 5, 'max' => 240, 'hint' => __( 'The pipeline yields after this and resumes in the background, so max_execution_time is never hit.', 'viral-video-ai' ) ) ),
			array( 'max_concurrent_jobs', __( 'Parallel jobs', 'viral-video-ai' ), 'number', array( 'min' => 1, 'max' => 10 ) ),
			array( 'default_aspect_ratio', __( 'Default aspect ratio', 'viral-video-ai' ), 'select', array( 'options' => array(
				'9:16' => __( '9:16 vertical (Shorts / Reels / TikTok)', 'viral-video-ai' ),
				'16:9' => __( '16:9 landscape', 'viral-video-ai' ),
				'1:1'  => __( '1:1 square', 'viral-video-ai' ),
				'4:5'  => __( '4:5 portrait', 'viral-video-ai' ),
			) ) ),
			array( 'default_quality', __( 'Default quality', 'viral-video-ai' ), 'select', array( 'options' => array(
				'720p'  => '720p',
				'1080p' => '1080p',
				'4k'    => __( '4K (only if the source supports it)', 'viral-video-ai' ),
			) ) ),
			array( 'crop_mode', __( 'Vertical framing', 'viral-video-ai' ), 'select', array( 'options' => array(
				'smart'  => __( 'Smart (content aware)', 'viral-video-ai' ),
				'center' => __( 'Centre crop', 'viral-video-ai' ),
			), 'hint' => __( 'Smart derives the real content box with FFmpeg cropdetect (also removes letterbox bars). Face/person tracking can replace it through the vvai_crop_analysis filter.', 'viral-video-ai' ) ) ),
			array( 'encode_preset', __( 'x264 preset', 'viral-video-ai' ), 'select', array( 'options' => array_combine(
				array( 'ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow' ),
				array( 'ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow' )
			) ) ),
			array( 'video_crf', __( 'Quality (CRF — lower is better)', 'viral-video-ai' ), 'number', array( 'min' => 14, 'max' => 35 ) ),
			array( 'audio_bitrate', __( 'Audio bitrate', 'viral-video-ai' ), 'text', array( 'placeholder' => '160k' ) ),
			array( 'ffmpeg_extra_args', __( 'Extra FFmpeg arguments', 'viral-video-ai' ), 'text', array( 'placeholder' => '-preset slow', 'hint' => __( 'Shell characters are rejected outright; only plain flags are accepted.', 'viral-video-ai' ) ) ),
			array( 'allow_upscale', __( 'Allow upscaling small sources', 'viral-video-ai' ), 'checkbox', array( 'hint' => __( 'Off by default: render at the source size instead of inventing pixels.', 'viral-video-ai' ) ) ),
			array( 'burn_captions', __( 'Burn captions into clips by default', 'viral-video-ai' ), 'checkbox' ),
			array( 'generate_srt', __( 'Write an .srt sidecar per clip', 'viral-video-ai' ), 'checkbox' ),
		),
	),
	array(
		'title'  => __( 'Frontend, retention & debug', 'viral-video-ai' ),
		'fields' => array(
			array( 'require_login', __( 'Require visitors to be logged in', 'viral-video-ai' ), 'checkbox', array( 'hint' => __( 'Recommended: rendering costs CPU. Turn off only with an upload cap and monitoring in place.', 'viral-video-ai' ) ) ),
			array( 'auto_start_job', __( 'Start processing right after the upload', 'viral-video-ai' ), 'checkbox' ),
			array( 'show_processing_stage', __( 'Show visitors the current stage label', 'viral-video-ai' ), 'checkbox' ),
			array( 'allow_public_downloads', __( 'Anyone with the link may download finished clips', 'viral-video-ai' ), 'checkbox', array( 'hint' => __( 'Off by default: downloads are authorised per user or by a signed token.', 'viral-video-ai' ) ) ),
			array( 'download_link_ttl', __( 'Signed link lifetime (seconds)', 'viral-video-ai' ), 'number', array( 'min' => 60, 'max' => 604800 ) ),
			array( 'clip_retention_days', __( 'Keep clips for (days — 0 keeps them forever)', 'viral-video-ai' ), 'number', array( 'min' => 0, 'max' => 365 ) ),
			array( 'delete_source_retention_days', __( 'Keep the uploaded source for (days)', 'viral-video-ai' ), 'number', array( 'min' => 0, 'max' => 90 ) ),
			array( 'temp_retention_hours', __( 'Delete scratch audio after (hours)', 'viral-video-ai' ), 'number', array( 'min' => 0, 'max' => 168 ) ),
			array( 'auto_cleanup', __( 'Run cleanup daily', 'viral-video-ai' ), 'checkbox' ),
			array( 'delete_source_after_job', __( 'Delete the uploaded source when a job completes', 'viral-video-ai' ), 'checkbox', array( 'hint' => __( 'Saves disk space but makes later re-renders impossible.', 'viral-video-ai' ) ) ),
			array( 'debug_log', __( 'Write the debug log', 'viral-video-ai' ), 'checkbox', array( 'hint' => __( 'Never contains API keys, headers or transcript text.', 'viral-video-ai' ) ) ),
			array( 'log_max_kb', __( 'Log size cap (KB)', 'viral-video-ai' ), 'number', array( 'min' => 64, 'max' => 16384 ) ),
			array( 'delete_data_on_uninstall', __( 'Delete all plugin data (jobs, clips, connections, tables) on uninstall', 'viral-video-ai' ), 'checkbox', array( 'hint' => __( 'Off by default so an accidental deactivation never costs you rendered clips. Turn on to remove everything when the plugin is deleted.', 'viral-video-ai' ) ) ),
		),
	),
);

/**
 * Render one settings control.
 *
 * @param array{0:string,1:string,2:string,3?:array<string,mixed>} $field Spec.
 * @param array<string,mixed>                                       $values Current values.
 */
$vvai_render_field = static function ( array $field, array $values ) {
	$key     = $field[0];
	$label   = $field[1];
	$type    = $field[2];
	$atts    = isset( $field[3] ) ? (array) $field[3] : array();
	$value   = array_key_exists( 'value', $atts ) ? $atts['value'] : ( $values[ $key ] ?? '' );
	$name    = 'vvai[' . $key . ']';
	$id      = 'vvai-set-' . $key;
	$hint    = isset( $atts['hint'] ) ? (string) $atts['hint'] : '';

	echo '<p class="vvai-field vvai-field--' . esc_attr( $type ) . '">';

	if ( 'checkbox' === $type ) {
		echo '<label class="vvai-check"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $value, true, false ) . ' /> <span>' . esc_html( $label ) . '</span></label>';

		if ( '' !== $hint ) {
			echo '<small>' . esc_html( $hint ) . '</small>';
		}

		echo '</p>';

		return;
	}

	echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

	if ( 'select' === $type ) {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';

		foreach ( (array) ( $atts['options'] ?? array() ) as $option_key => $option_label ) {
			echo '<option value="' . esc_attr( (string) $option_key ) . '" ' . selected( (string) $value, (string) $option_key, false ) . '>' . esc_html( (string) $option_label ) . '</option>';
		}

		echo '</select>';
	} else {
		echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"';

		foreach ( array( 'min', 'max', 'step', 'placeholder' ) as $attr ) {
			if ( isset( $atts[ $attr ] ) ) {
				echo ' ' . esc_attr( $attr ) . '="' . esc_attr( (string) $atts[ $attr ] ) . '"';
			}
		}

		echo ' autocomplete="off" />';
	}

	if ( '' !== $hint ) {
		echo '<small>' . esc_html( $hint ) . '</small>';
	}

	echo '</p>';
};
?>
<div class="wrap vvai-wrap">
	<h1><?php esc_html_e( 'Settings', 'viral-video-ai' ); ?></h1>

	<form data-vvai-settings-form>
		<div class="vvai-columns">
			<?php foreach ( $vvai_sections as $vvai_section ) : ?>
				<section class="vvai-panel">
					<h2><?php echo esc_html( (string) $vvai_section['title'] ); ?></h2>
					<?php
					foreach ( (array) $vvai_section['fields'] as $vvai_field ) {
						$vvai_render_field( (array) $vvai_field, (array) $settings );
					}
					?>
				</section>
			<?php endforeach; ?>

			<section class="vvai-panel">
				<h2><?php esc_html_e( 'Runtime check', 'viral-video-ai' ); ?></h2>
				<ul class="vvai-kv-list">
					<li>
						<span class="vvai-dot <?php echo empty( $ffmpeg['ok'] ) ? 'is-bad' : 'is-ok'; ?>"></span>
						<?php
						if ( ! empty( $ffmpeg['ok'] ) ) {
							echo esc_html( sprintf( /* translators: %s: version. */ __( 'FFmpeg works: %s', 'viral-video-ai' ), (string) $ffmpeg['ffmpeg']['version'] ) );
						} else {
							echo esc_html( (string) ( $ffmpeg['ffmpeg']['error'] ?? __( 'FFmpeg not detected', 'viral-video-ai' ) ) );
						}
						?>
					</li>
					<li><?php echo esc_html( $scheduler['async'] ? __( 'Action Scheduler detected — jobs run through it.', 'viral-video-ai' ) : __( 'WP-Cron heartbeat (every minute) plus a loopback spawn drives the queue.', 'viral-video-ai' ) ); ?></li>
					<li><?php echo esc_html( $scheduler['rest'] ? __( 'REST API is reachable.', 'viral-video-ai' ) : __( 'REST API is NOT reachable — the frontend falls back to admin-ajax.', 'viral-video-ai' ) ); ?></li>
					<li><?php echo esc_html( $limits['memory'] ? sprintf( /* translators: %s: memory limit. */ __( 'PHP memory limit: %s', 'viral-video-ai' ), (string) $limits['memory'] ) : '' ); ?></li>
				</ul>
			</section>
		</div>

		<p class="vvai-submit">
			<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save settings', 'viral-video-ai' ); ?></button>
			<span class="vvai-muted" data-vvai-save-state></span>
		</p>
	</form>
</div>
