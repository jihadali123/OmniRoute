<?php
/**
 * Frontend: the signed-in user\u2019s finished clips.
 *
 * Rendered by [vvai_my_clips]. Override by copying to
 * {theme}/viral-video-ai/templates/frontend/library.php.
 *
 * @var array<int,array<string,mixed>> $clips
 * @var array<string,mixed>            $config
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="vvai-app vvai-app--library">
	<div class="vvai-shell">
		<section class="vvai-results" <?php echo $clips ? '' : 'hidden'; ?>>
			<div class="vvai-results__head">
				<h4><?php esc_html_e( 'Your clips', 'viral-video-ai' ); ?></h4>
				<span><?php echo esc_html( sprintf( /* translators: %s: count. */ _n( '%s clip', '%s clips', count( $clips ), 'viral-video-ai' ), number_format_i18n( count( $clips ) ) ) ); ?></span>
			</div>

			<div class="vvai-library">
				<?php foreach ( $clips as $clip ) : ?>
					<article class="vvai-clip">
						<div class="vvai-clip__media">
							<video preload="metadata" playsinline controls src="<?php echo esc_url( (string) $clip['previewUrl'] ); ?>"></video>
							<span class="vvai-clip__score <?php echo (int) $clip['score'] >= 80 ? 'is-hot' : ( (int) $clip['score'] >= 60 ? 'is-warm' : 'is-cool' ); ?>"><?php echo (int) $clip['score']; ?>/100</span>
						</div>
						<div class="vvai-clip__body">
							<div class="vvai-clip__meta">
								<span class="vvai-clip__number"><?php echo esc_html( sprintf( /* translators: %s: clip number. */ __( 'Clip %s', 'viral-video-ai' ), (int) $clip['number'] ) ); ?></span>
								<span class="vvai-clip__range"><?php echo esc_html( (string) $clip['startLabel'] . ' \u2192 ' . (string) $clip['endLabel'] ); ?></span>
								<span class="vvai-clip__duration"><?php echo esc_html( (string) $clip['durationLabel'] . ' \u00b7 ' . (string) $clip['sizeLabel'] ); ?></span>
							</div>
							<h5><?php echo esc_html( (string) $clip['title'] ); ?></h5>
							<p class="vvai-clip__why"><?php echo esc_html( (string) $clip['reasoning'] ); ?></p>
							<p class="vvai-clip__caption"><?php echo esc_html( (string) $clip['caption'] ); ?></p>
							<p class="vvai-clip__tags"><?php echo esc_html( (string) $clip['hashtagText'] ); ?></p>
							<div class="vvai-clip__actions">
								<a class="vvai-btn vvai-btn--primary" href="<?php echo esc_url( (string) $clip['downloadUrl'] ); ?>" download="<?php echo esc_attr( (string) $clip['fileName'] ); ?>"><?php esc_html_e( 'Download', 'viral-video-ai' ); ?></a>
								<?php if ( ! empty( $clip['hasCaptions'] ) ) : ?>
									<a class="vvai-btn vvai-btn--text" href="<?php echo esc_url( (string) $clip['captionUrl'] ); ?>" download><?php esc_html_e( 'Captions .srt', 'viral-video-ai' ); ?></a>
								<?php endif; ?>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
	</div>
</div>
