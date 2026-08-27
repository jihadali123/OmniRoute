<?php
/**
 * Frontend generator template.
 *
 * Override by copying this file to
 *   {theme}/viral-video-ai/templates/frontend/generator.php
 * and editing the copy. All behaviour lives in assets/js/vvai-frontend.js;
 * this file only provides the DOM contract (data-vvai attributes).
 *
 * @package VVAI
 *
 * @var array<string,mixed> $config    Bootstrap config (see VVAI_Frontend::config()).
 * @var array<string,mixed> $settings  Widget/shortcode settings.
 * @var string              $instance  Unique id for this instance on the page.
 */

defined( 'ABSPATH' ) || exit;

$vvai_id          = esc_attr( (string) $instance );
$vvai_show_source = isset( $settings['show_source'] ) ? array_map( 'trim', explode( ',', (string) $settings['show_source'] ) ) : array( 'upload', 'url' );
$vvai_show_source = array_values( array_intersect( $vvai_show_source, array( 'upload', 'url', 'media' ) ) );
$vvai_show_adv    = ! isset( $settings['show_advanced'] ) || 'no' !== (string) $settings['show_advanced'];
$vvai_button      = isset( $settings['button_text'] ) && '' !== (string) $settings['button_text'] ? (string) $settings['button_text'] : __( 'Generate Clips', 'viral-video-ai' );
$vvai_defaults    = (array) vvai_array_get( $config, 'defaults', array() );
$vvai_limits      = (int) vvai_array_get( $config, 'maxUploadBytes', 0 );
$vvai_needs_login = (bool) vvai_array_get( $config, 'requireLogin', true ) && ! vvai_array_get( $config, 'loggedIn', false );
$vvai_no_conn     = '' !== (string) vvai_array_get( $config, 'connectionError', '' );
?>
<div class="vvai-app" id="<?php echo esc_attr( $vvai_id ); ?>" data-vvai-app data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
	<div class="vvai-shell">
		<header class="vvai-head">
			<div class="vvai-head__title">
				<span class="vvai-badge" aria-hidden="true">AI</span>
				<h3><?php esc_html_e( 'Long video to viral clips', 'viral-video-ai' ); ?></h3>
				<p><?php esc_html_e( 'Upload a long video, let the connected AI pick the strongest moments with exact timestamps, and download finished short clips.', 'viral-video-ai' ); ?></p>
			</div>
		</header>

		<?php if ( $vvai_needs_login ) : ?>
			<div class="vvai-notice vvai-notice--warning">
				<p><?php esc_html_e( 'Please log in to generate clips.', 'viral-video-ai' ); ?></p>
				<p><a class="vvai-btn vvai-btn--ghost" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in', 'viral-video-ai' ); ?></a></p>
			</div>
		<?php endif; ?>

		<?php if ( $vvai_no_conn ) : ?>
			<div class="vvai-notice vvai-notice--warning" role="status">
				<p><?php echo esc_html( (string) $config['connectionError'] ); ?></p>
			</div>
		<?php endif; ?>

		<div class="vvai-grid">
			<section class="vvai-card vvai-card--source">
				<h4 class="vvai-card__label"><?php esc_html_e( '1. Source video', 'viral-video-ai' ); ?></h4>

				<?php if ( in_array( 'upload', $vvai_show_source, true ) ) : ?>
					<div class="vvai-drop" data-vvai-drop tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Choose a video file', 'viral-video-ai' ); ?>">
						<input type="file" class="vvai-file" data-vvai-file accept="video/*<?php foreach ( (array) vvai_array_get( $config, 'allowedExtensions', array() ) as $vvai_ext ) : ?>,.<?php echo esc_attr( $vvai_ext ); ?><?php endforeach; ?>" />
						<div class="vvai-drop__inner">
							<strong><?php esc_html_e( 'Drag a long video here, or click to browse', 'viral-video-ai' ); ?></strong>
							<span><?php echo esc_html( sprintf( /* translators: %s: size limit. */ __( 'MP4, MOV, WebM or MKV · up to %s', 'viral-video-ai' ), $vvai_limits ? size_format( $vvai_limits ) : size_format( 1024 * MB_IN_BYTES ) ) ); ?></span>
						</div>
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
				<?php endif; ?>

				<?php if ( in_array( 'url', $vvai_show_source, true ) ) : ?>
					<div class="vvai-field">
						<label for="<?php echo esc_attr( $vvai_id ); ?>-url"><?php esc_html_e( '…or paste a direct video URL', 'viral-video-ai' ); ?></label>
						<div class="vvai-inline">
							<input id="<?php echo esc_attr( $vvai_id ); ?>-url" type="url" data-vvai-url placeholder="https://example.com/video.mp4" autocomplete="off" />
							<button type="button" class="vvai-btn vvai-btn--ghost" data-vvai-url-fetch><?php esc_html_e( 'Import', 'viral-video-ai' ); ?></button>
						</div>
						<small><?php esc_html_e( 'Direct file links only — YouTube and other platform page URLs are not downloadable and are rejected.', 'viral-video-ai' ); ?></small>
					</div>
				<?php endif; ?>

				<div class="vvai-source" data-vvai-source hidden>
					<div class="vvai-source__info">
						<strong data-vvai-source-name></strong>
						<span data-vvai-source-size></span>
					</div>
					<button type="button" class="vvai-btn vvai-btn--text" data-vvai-source-clear><?php esc_html_e( 'Remove', 'viral-video-ai' ); ?></button>
				</div>
			</section>

			<section class="vvai-card">
				<h4 class="vvai-card__label"><?php esc_html_e( '2. What to cut', 'viral-video-ai' ); ?></h4>

				<div class="vvai-field">
					<label><?php esc_html_e( 'Clip length', 'viral-video-ai' ); ?></label>
					<div class="vvai-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Clip length', 'viral-video-ai' ); ?>">
						<?php
						$vvai_lengths = array(
							'short'  => __( 'Short · 30-60s', 'viral-video-ai' ),
							'medium' => __( 'Medium · 2-3min', 'viral-video-ai' ),
							'long'   => __( 'Long · 4-5min', 'viral-video-ai' ),
							'custom' => __( 'Custom', 'viral-video-ai' ),
						);

						foreach ( $vvai_lengths as $vvai_key => $vvai_label ) :
							$vvai_checked = (string) vvai_array_get( $vvai_defaults, 'clipLength', 'short' ) === $vvai_key;
							?>
							<label>
								<input type="radio" name="<?php echo esc_attr( $vvai_id ); ?>-length" value="<?php echo esc_attr( $vvai_key ); ?>" data-vvai-length <?php checked( $vvai_checked ); ?> />
								<span><?php echo esc_html( $vvai_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="vvai-field" data-vvai-custom-length hidden>
					<div class="vvai-inline">
						<label>
							<?php esc_html_e( 'Min seconds', 'viral-video-ai' ); ?>
							<input type="number" min="5" max="1800" step="1" value="30" data-vvai-min-duration />
						</label>
						<label>
							<?php esc_html_e( 'Max seconds', 'viral-video-ai' ); ?>
							<input type="number" min="10" max="3600" step="1" value="60" data-vvai-max-duration />
						</label>
					</div>
				</div>

				<div class="vvai-field">
					<label for="<?php echo esc_attr( $vvai_id ); ?>-focus"><?php esc_html_e( 'Content focus', 'viral-video-ai' ); ?></label>
					<select id="<?php echo esc_attr( $vvai_id ); ?>-focus" data-vvai-focus>
						<?php foreach ( VVAI_Prompt_Builder::focuses() as $vvai_key => $vvai_focus ) : ?>
							<option value="<?php echo esc_attr( $vvai_key ); ?>" <?php selected( (string) vvai_array_get( $vvai_defaults, 'focus', 'viral' ), $vvai_key ); ?>>
								<?php echo esc_html( $vvai_focus['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<textarea
						id="<?php echo esc_attr( $vvai_id ); ?>-custom-focus"
						data-vvai-custom-focus
						rows="2"
						maxlength="300"
						placeholder="<?php esc_attr_e( 'Describe what to look for, e.g. “product demos where a number is shown on screen”', 'viral-video-ai' ); ?>"
						hidden></textarea>
				</div>

				<div class="vvai-field">
					<label for="<?php echo esc_attr( $vvai_id ); ?>-clips"><?php esc_html_e( 'Number of clips', 'viral-video-ai' ); ?></label>
					<input id="<?php echo esc_attr( $vvai_id ); ?>-clips" type="number" min="1" max="<?php echo esc_attr( (int) vvai_array_get( $config, 'maxClips', 5 ) ); ?>" value="<?php echo esc_attr( (int) vvai_array_get( $vvai_defaults, 'targetClips', 3 ) ); ?>" data-vvai-target-clips />
				</div>
			</section>

			<section class="vvai-card">
				<h4 class="vvai-card__label"><?php esc_html_e( '3. Output', 'viral-video-ai' ); ?></h4>

				<div class="vvai-field">
					<label><?php esc_html_e( 'Aspect ratio', 'viral-video-ai' ); ?></label>
					<div class="vvai-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Aspect ratio', 'viral-video-ai' ); ?>">
						<?php
						$vvai_ratios = array(
							'9:16' => __( 'Vertical 9:16', 'viral-video-ai' ),
							'16:9' => __( 'Landscape 16:9', 'viral-video-ai' ),
							'1:1'  => __( 'Square 1:1', 'viral-video-ai' ),
							'4:5'  => __( 'Portrait 4:5', 'viral-video-ai' ),
						);

						foreach ( $vvai_ratios as $vvai_key => $vvai_label ) :
							$vvai_checked = (string) vvai_array_get( $vvai_defaults, 'aspect', '9:16' ) === $vvai_key;
							?>
							<label>
								<input type="radio" name="<?php echo esc_attr( $vvai_id ); ?>-aspect" value="<?php echo esc_attr( $vvai_key ); ?>" data-vvai-aspect <?php checked( $vvai_checked ); ?> />
								<span><?php echo esc_html( $vvai_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="vvai-field">
					<label for="<?php echo esc_attr( $vvai_id ); ?>-quality"><?php esc_html_e( 'Quality', 'viral-video-ai' ); ?></label>
					<select id="<?php echo esc_attr( $vvai_id ); ?>-quality" data-vvai-quality>
						<option value="720p" <?php selected( (string) vvai_array_get( $vvai_defaults, 'quality', '1080p' ), '720p' ); ?>><?php esc_html_e( '720p — fastest', 'viral-video-ai' ); ?></option>
						<option value="1080p" <?php selected( (string) vvai_array_get( $vvai_defaults, 'quality', '1080p' ), '1080p' ); ?>><?php esc_html_e( '1080p — recommended', 'viral-video-ai' ); ?></option>
						<option value="4k" <?php selected( (string) vvai_array_get( $vvai_defaults, 'quality', '1080p' ), '4k' ); ?>><?php esc_html_e( '4K — only if the source supports it', 'viral-video-ai' ); ?></option>
					</select>
					<small><?php esc_html_e( 'Never upscaled: if the source is smaller, the clip is rendered at the source size.', 'viral-video-ai' ); ?></small>
				</div>

				<?php if ( $vvai_show_adv ) : ?>
					<details class="vvai-advanced">
						<summary><?php esc_html_e( 'Advanced', 'viral-video-ai' ); ?></summary>

						<div class="vvai-field">
							<label for="<?php echo esc_attr( $vvai_id ); ?>-connection"><?php esc_html_e( 'AI connection', 'viral-video-ai' ); ?></label>
							<select id="<?php echo esc_attr( $vvai_id ); ?>-connection" data-vvai-connection>
								<option value=""><?php esc_html_e( 'Use the active connection', 'viral-video-ai' ); ?></option>
								<?php foreach ( (array) vvai_array_get( $config, 'connections', array() ) as $vvai_conn ) : ?>
									<option value="<?php echo esc_attr( (string) $vvai_conn['id'] ); ?>">
										<?php echo esc_html( (string) $vvai_conn['title'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="vvai-field">
							<label><?php esc_html_e( 'Framing', 'viral-video-ai' ); ?></label>
							<div class="vvai-segmented">
								<label>
									<input type="radio" name="<?php echo esc_attr( $vvai_id ); ?>-crop" value="smart" data-vvai-crop <?php checked( (string) vvai_array_get( $vvai_defaults, 'cropMode', 'smart' ), 'smart' ); ?> />
									<span><?php esc_html_e( 'Smart (content aware)', 'viral-video-ai' ); ?></span>
								</label>
								<label>
									<input type="radio" name="<?php echo esc_attr( $vvai_id ); ?>-crop" value="center" data-vvai-crop <?php checked( (string) vvai_array_get( $vvai_defaults, 'cropMode', 'smart' ), 'center' ); ?> />
									<span><?php esc_html_e( 'Centre crop', 'viral-video-ai' ); ?></span>
								</label>
							</div>
						</div>

						<label class="vvai-check">
							<input type="checkbox" data-vvai-burn <?php checked( (bool) vvai_array_get( $vvai_defaults, 'burnCaptions', false ) ); ?> />
							<span><?php esc_html_e( 'Burn captions into the video', 'viral-video-ai' ); ?></span>
						</label>
						<label class="vvai-check">
							<input type="checkbox" data-vvai-srt <?php checked( (bool) vvai_array_get( $vvai_defaults, 'generateSrt', true ) ); ?> />
							<span><?php esc_html_e( 'Offer an .srt caption file per clip', 'viral-video-ai' ); ?></span>
						</label>
					</details>
				<?php endif; ?>

				<button type="button" class="vvai-btn vvai-btn--primary vvai-cta" data-vvai-generate <?php disabled( $vvai_needs_login || $vvai_no_conn ); ?>>
					<?php echo esc_html( $vvai_button ); ?>
				</button>
				<p class="vvai-cta-hint" data-vvai-hint>
					<?php esc_html_e( 'Processing runs in the background — you can leave this page and come back.', 'viral-video-ai' ); ?>
				</p>
			</section>
		</div>

		<section class="vvai-card vvai-status" data-vvai-status hidden aria-live="polite">
			<div class="vvai-status__head">
				<strong data-vvai-status-title><?php esc_html_e( 'Processing', 'viral-video-ai' ); ?></strong>
				<span data-vvai-status-pct>0%</span>
			</div>
			<div class="vvai-bar vvai-bar--big"><i data-vvai-status-bar style="width:0%"></i></div>
			<ul class="vvai-stages" data-vvai-stages></ul>
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
