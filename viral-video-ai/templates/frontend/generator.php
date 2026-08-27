<?php
/**
 * Frontend generator template — deliberately minimal.
 *
 * The visitor sees two things: the video, and how many clips in which shape.
 * Everything else (focus, framing, captions, connection, custom length) lives
 * behind one collapsed "Advanced" panel and is already set to good defaults, so
 * the widget works with a single click.
 *
 * Override by copying to {theme}/viral-video-ai/generator.php.
 *
 * @var array<string,mixed> $config    Bootstrap config (VVAI_Frontend::config()).
 * @var array<string,mixed> $settings  Widget/shortcode settings.
 * @var string              $instance  Unique id for this instance.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

$vvai_id        = esc_attr( (string) $instance );
$vvai_defaults  = (array) vvai_array_get( $config, 'defaults', array() );
$vvai_modes     = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $settings['show_source'] ?? 'upload,url,media' ) ) ) ) );
$vvai_modes     = $vvai_modes ? array_values( array_intersect( $vvai_modes, array( 'upload', 'url', 'media' ) ) ) : array( 'upload', 'url', 'media' );
$vvai_adv       = ! isset( $settings['show_advanced'] ) || 'no' !== (string) $settings['show_advanced'];
$vvai_button    = trim( (string) ( $settings['button_text'] ?? '' ) ) ?: __( 'Generate Clips', 'viral-video-ai' );
$vvai_max_bytes = (int) vvai_array_get( $config, 'maxUploadBytes', 0 );
$vvai_conns     = (array) vvai_array_get( $config, 'connections', array() );
$vvai_needs     = (bool) vvai_array_get( $config, 'requireLogin', true ) && ! vvai_array_get( $config, 'loggedIn', false );
$vvai_no_conn   = '' !== (string) vvai_array_get( $config, 'connectionError', '' );
$vvai_max_clips = (int) vvai_array_get( $config, 'maxClips', 5 );
?>
<div class="vvai-app" id="<?php echo esc_attr( $vvai_id ); ?>" data-vvai-app data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
	<div class="vvai-shell">

		<?php if ( $vvai_needs ) : ?>
			<div class="vvai-notice vvai-notice--warning">
				<p><?php esc_html_e( 'Please log in to generate clips.', 'viral-video-ai' ); ?></p>
				<p><a class="vvai-btn vvai-btn--ghost" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in', 'viral-video-ai' ); ?></a></p>
			</div>
		<?php elseif ( $vvai_no_conn ) : ?>
			<div class="vvai-notice vvai-notice--warning" role="status">
				<p><?php echo esc_html( (string) vvai_array_get( $config, 'connectionError', '' ) ); ?></p>
			</div>
		<?php endif; ?>

		<div class="vvai-card vvai-card--main">

			<div class="vvai-drop" data-vvai-drop tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Choose a video', 'viral-video-ai' ); ?>">
				<?php if ( in_array( 'upload', $vvai_modes, true ) ) : ?>
					<input type="file" class="vvai-file" data-vvai-file accept="video/*" />
				<?php endif; ?>
				<div class="vvai-drop__inner">
					<strong><?php esc_html_e( 'Drop your long video here, or click to choose', 'viral-video-ai' ); ?></strong>
					<span>
						<?php
						if ( $vvai_max_bytes > 0 ) {
							echo esc_html( sprintf( /* translators: %s: size limit. */ __( 'MP4, MOV, WebM or MKV · up to %s on this server', 'viral-video-ai' ), size_format( $vvai_max_bytes ) ) );
						} else {
							esc_html_e( 'MP4, MOV, WebM or MKV · no size limit set on this site', 'viral-video-ai' );
						}
						?>
					</span>
				</div>
			</div>

			<?php if ( in_array( 'url', $vvai_modes, true ) ) : ?>
				<div class="vvai-urlrow">
					<input type="url" data-vvai-url placeholder="<?php esc_attr_e( '…or paste a direct video URL (https://site.com/video.mp4)', 'viral-video-ai' ); ?>" autocomplete="off" />
					<button type="button" class="vvai-btn vvai-btn--ghost" data-vvai-url-fetch><?php esc_html_e( 'Import', 'viral-video-ai' ); ?></button>
				</div>
			<?php endif; ?>

			<?php if ( in_array( 'media', $vvai_modes, true ) ) : ?>
				<div class="vvai-urlrow">
					<input type="text" data-vvai-media-search placeholder="<?php esc_attr_e( '…or pick a video already in your Media Library', 'viral-video-ai' ); ?>" readonly />
					<button type="button" class="vvai-btn vvai-btn--ghost" data-vvai-media-pick><?php esc_html_e( 'Browse', 'viral-video-ai' ); ?></button>
				</div>
			<?php endif; ?>

			<div class="vvai-source" data-vvai-source hidden>
				<div class="vvai-source__info">
					<strong data-vvai-source-name></strong>
					<span data-vvai-source-size></span>
				</div>
				<button type="button" class="vvai-btn vvai-btn--text" data-vvai-source-clear><?php esc_html_e( 'Remove', 'viral-video-ai' ); ?></button>
			</div>

			<div class="vvai-progress" data-vvai-upload hidden>
				<div class="vvai-progress__row">
					<span data-vvai-upload-label><?php esc_html_e( 'Uploading', 'viral-video-ai' ); ?></span>
					<span data-vvai-upload-pct>0%</span>
				</div>
				<div class="vvai-bar"><i data-vvai-upload-bar style="width:0%"></i></div>
				<div class="vvai-progress__meta">
					<span data-vvai-upload-bytes>0 / 0</span>
					<span data-vvai-upload-speed></span>
					<span data-vvai-upload-eta></span>
				</div>
			</div>

			<div class="vvai-controls">
				<label class="vvai-chips" data-vvai-clips-group>
					<span><?php esc_html_e( 'Clips', 'viral-video-ai' ); ?></span>
					<span class="vvai-chips__row">
						<?php
						$vvai_preferred = (int) vvai_array_get( $vvai_defaults, 'targetClips', 3 );
						$vvai_preferred = max( 1, min( 5, $vvai_preferred ) );

						for ( $vvai_n = 1; $vvai_n <= 5; $vvai_n++ ) :
							if ( $vvai_n > $vvai_max_clips ) {
								break;
							}
							?>
							<button type="button" class="vvai-chip<?php echo $vvai_n === $vvai_preferred ? ' is-on' : ''; ?>" data-vvai-clip-count="<?php echo (int) $vvai_n; ?>"><?php echo (int) $vvai_n; ?></button>
						<?php endfor; ?>
					</span>
				</label>

				<label class="vvai-chips">
					<span><?php esc_html_e( 'Shape', 'viral-video-ai' ); ?></span>
					<span class="vvai-chips__row" data-vvai-aspect-group>
						<?php
						$vvai_ratios = array(
							'9:16' => __( 'Vertical', 'viral-video-ai' ),
							'16:9' => __( 'Wide', 'viral-video-ai' ),
							'1:1'  => __( 'Square', 'viral-video-ai' ),
							'4:5'  => __( 'Portrait', 'viral-video-ai' ),
						);

						foreach ( $vvai_ratios as $vvai_key => $vvai_label ) : ?>
							<button type="button" class="vvai-chip<?php echo (string) vvai_array_get( $vvai_defaults, 'aspect', '9:16' ) === $vvai_key ? ' is-on' : ''; ?>" data-vvai-aspect="<?php echo esc_attr( $vvai_key ); ?>"><?php echo esc_html( $vvai_label ); ?></button>
						<?php endforeach; ?>
					</span>
				</label>

				<label class="vvai-chips">
					<span><?php esc_html_e( 'Quality', 'viral-video-ai' ); ?></span>
					<span class="vvai-chips__row" data-vvai-quality-group>
						<?php foreach ( array( '720p', '1080p', '4k' ) as $vvai_q ) : ?>
							<button type="button" class="vvai-chip<?php echo (string) vvai_array_get( $vvai_defaults, 'quality', '1080p' ) === $vvai_q ? ' is-on' : ''; ?>" data-vvai-quality="<?php echo esc_attr( $vvai_q ); ?>"><?php echo esc_html( '4k' === $vvai_q ? '4K' : strtoupper( $vvai_q ) ); ?></button>
						<?php endforeach; ?>
					</span>
				</label>
			</div>

			<button type="button" class="vvai-btn vvai-btn--primary vvai-cta" data-vvai-generate <?php disabled( $vvai_needs || $vvai_no_conn ); ?>>
				<?php echo esc_html( $vvai_button ); ?>
			</button>
			<p class="vvai-cta-hint" data-vvai-hint><?php esc_html_e( 'Processing runs in the background — you can leave this page.', 'viral-video-ai' ); ?></p>

			<?php if ( $vvai_adv ) : ?>
				<details class="vvai-advanced">
					<summary><?php esc_html_e( 'Advanced options', 'viral-video-ai' ); ?></summary>

					<div class="vvai-advanced__grid">
						<label>
							<?php esc_html_e( 'Look for', 'viral-video-ai' ); ?>
							<select data-vvai-focus>
								<?php
								foreach ( VVAI_Prompt_Builder::focuses() as $vvai_key => $vvai_focus ) :
									?>
									<option value="<?php echo esc_attr( $vvai_key ); ?>" <?php selected( (string) vvai_array_get( $vvai_defaults, 'focus', 'viral' ), $vvai_key ); ?>><?php echo esc_html( $vvai_focus['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<label>
							<?php esc_html_e( 'Clip length', 'viral-video-ai' ); ?>
							<select data-vvai-length>
								<option value="short" <?php selected( (string) vvai_array_get( $vvai_defaults, 'clipLength', 'short' ), 'short' ); ?>><?php esc_html_e( 'Short · 30–60s', 'viral-video-ai' ); ?></option>
								<option value="medium" <?php selected( (string) vvai_array_get( $vvai_defaults, 'clipLength', 'short' ), 'medium' ); ?>><?php esc_html_e( 'Medium · 2–3min', 'viral-video-ai' ); ?></option>
								<option value="long" <?php selected( (string) vvai_array_get( $vvai_defaults, 'clipLength', 'short' ), 'long' ); ?>><?php esc_html_e( 'Long · 4–5min', 'viral-video-ai' ); ?></option>
								<option value="custom" <?php selected( (string) vvai_array_get( $vvai_defaults, 'clipLength', 'short' ), 'custom' ); ?>><?php esc_html_e( 'Custom seconds', 'viral-video-ai' ); ?></option>
							</select>
						</label>

						<span data-vvai-custom-length hidden>
							<label><?php esc_html_e( 'Min (s)', 'viral-video-ai' ); ?><input type="number" min="5" max="1800" value="30" data-vvai-min-duration /></label>
							<label><?php esc_html_e( 'Max (s)', 'viral-video-ai' ); ?><input type="number" min="10" max="3600" value="60" data-vvai-max-duration /></label>
						</span>

						<label class="vvai-chips">
							<?php esc_html_e( 'Framing', 'viral-video-ai' ); ?>
							<span class="vvai-chips__row" data-vvai-crop-group>
								<button type="button" class="vvai-chip<?php echo 'center' !== (string) vvai_array_get( $vvai_defaults, 'cropMode', 'smart' ) ? ' is-on' : ''; ?>" data-vvai-crop="smart"><?php esc_html_e( 'Smart', 'viral-video-ai' ); ?></button>
								<button type="button" class="vvai-chip<?php echo 'center' === (string) vvai_array_get( $vvai_defaults, 'cropMode', 'smart' ) ? ' is-on' : ''; ?>" data-vvai-crop="center"><?php esc_html_e( 'Centre', 'viral-video-ai' ); ?></button>
							</span>
						</label>

						<?php if ( count( $vvai_conns ) > 1 ) : ?>
							<label>
								<?php esc_html_e( 'AI connection', 'viral-video-ai' ); ?>
								<select data-vvai-connection>
									<option value=""><?php esc_html_e( 'Default', 'viral-video-ai' ); ?></option>
									<?php foreach ( $vvai_conns as $vvai_conn ) : ?>
										<option value="<?php echo esc_attr( (string) $vvai_conn['id'] ); ?>"><?php echo esc_html( (string) $vvai_conn['title'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php else : ?>
							<input type="hidden" data-vvai-connection value="" />
						<?php endif; ?>
					</div>

					<label class="vvai-check"><input type="checkbox" data-vvai-srt <?php checked( (bool) vvai_array_get( $vvai_defaults, 'generateSrt', true ) ); ?> /> <span><?php esc_html_e( 'Also give me an .srt caption file per clip', 'viral-video-ai' ); ?></span></label>
					<label class="vvai-check"><input type="checkbox" data-vvai-burn <?php checked( (bool) vvai_array_get( $vvai_defaults, 'burnCaptions', false ) ); ?> /> <span><?php esc_html_e( 'Burn the captions into the video', 'viral-video-ai' ); ?></span></label>

					<label>
						<?php esc_html_e( 'Tell the AI something specific', 'viral-video-ai' ); ?>
						<textarea rows="2" maxlength="300" data-vvai-custom-focus placeholder="<?php esc_attr_e( 'optional — e.g. only moments where a number is shown on screen', 'viral-video-ai' ); ?>"></textarea>
					</label>
				</details>
			<?php endif; ?>
		</div>

		<section class="vvai-card vvai-status" data-vvai-status hidden aria-live="polite">
			<div class="vvai-status__head">
				<strong data-vvai-status-title><?php esc_html_e( 'Processing', 'viral-video-ai' ); ?></strong>
				<span data-vvai-status-pct>0%</span>
			</div>
			<div class="vvai-bar vvai-bar--big"><i data-vvai-status-bar style="width:0%"></i></div>
			<div class="vvai-status__foot">
				<span data-vvai-status-stage></span>
				<button type="button" class="vvai-btn vvai-btn--text" data-vvai-cancel><?php esc_html_e( 'Cancel', 'viral-video-ai' ); ?></button>
			</div>
		</section>

		<section class="vvai-card vvai-error" data-vvai-error hidden role="alert">
			<strong><?php esc_html_e( 'Processing failed', 'viral-video-ai' ); ?></strong>
			<p data-vvai-error-message></p>
			<p class="vvai-error__hint" data-vvai-error-hint hidden></p>
			<div class="vvai-error__actions">
				<button type="button" class="vvai-btn vvai-btn--ghost" data-vvai-retry><?php esc_html_e( 'Retry', 'viral-video-ai' ); ?></button>
				<a class="vvai-btn vvai-btn--text" data-vvai-open-job href="#" hidden><?php esc_html_e( 'Job details', 'viral-video-ai' ); ?></a>
			</div>
		</section>

		<section class="vvai-results" data-vvai-results hidden>
			<div class="vvai-results__head">
				<h4><?php esc_html_e( 'Your clips', 'viral-video-ai' ); ?></h4>
				<span data-vvai-results-count></span>
			</div>
			<div class="vvai-results__grid" data-vvai-results-grid></div>
		</section>
	</div>

	<template data-vvai-clip-template>
		<article class="vvai-clip" data-vvai-clip>
			<div class="vvai-clip__media">
				<video preload="metadata" playsinline controls data-vvai-clip-video></video>
				<span class="vvai-clip__score" data-vvai-clip-score></span>
			</div>
			<div class="vvai-clip__body">
				<div class="vvai-clip__meta">
					<span class="vvai-clip__number" data-vvai-clip-number></span>
					<span class="vvai-clip__range" data-vvai-clip-range></span>
					<span class="vvai-clip__duration" data-vvai-clip-duration></span>
				</div>
				<h5 data-vvai-clip-title></h5>
				<p class="vvai-clip__why" data-vvai-clip-reasoning></p>
				<p class="vvai-clip__caption" data-vvai-clip-caption></p>
				<p class="vvai-clip__tags" data-vvai-clip-hashtags></p>
				<div class="vvai-clip__actions">
					<a class="vvai-btn vvai-btn--primary" download data-vvai-clip-download><?php esc_html_e( 'Download', 'viral-video-ai' ); ?></a>
					<button type="button" class="vvai-btn vvai-btn--ghost" data-vvai-clip-copy-caption><?php esc_html_e( 'Copy caption', 'viral-video-ai' ); ?></button>
					<button type="button" class="vvai-btn vvai-btn--ghost" data-vvai-clip-copy-title><?php esc_html_e( 'Copy title', 'viral-video-ai' ); ?></button>
					<a class="vvai-btn vvai-btn--text" data-vvai-clip-srt hidden><?php esc_html_e( 'Captions .srt', 'viral-video-ai' ); ?></a>
				</div>
			</div>
		</article>
	</template>
</div>
<?php
