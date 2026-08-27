<?php
/**
 * Admin: diagnostics.
 *
 * @var array<string,mixed> $report
 * @var array<string,mixed> $log
 * @var array<string,int>   $usage
 * @var array<string,mixed> $engine  VVAI_Diagnostics::ffmpeg_engine('status')
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap vvai-wrap" data-vvai-diagnostics>
	<h1><?php esc_html_e( 'Diagnostics', 'viral-video-ai' ); ?>
		<button type="button" class="button" data-vvai-recheck><?php esc_html_e( 'Re-check now', 'viral-video-ai' ); ?></button>
	</h1>

	<p class="vvai-lead">
		<?php
		printf(
			/* translators: 1: problems, 2: warnings. */
			esc_html__( 'Ready for video processing: %1$s blockers, %2$s warnings. Every line below is a real check against this server, not a guess.', 'viral-video-ai' ),
			'<strong>' . (int) $report['problems'] . '</strong>',
			'<strong>' . (int) $report['warnings'] . '</strong>'
		);
		?>
	</p>

	<?php
	$vvai_engine = ( isset( $engine ) && is_array( $engine ) ) ? $engine : array();
	$vvai_bins   = (array) vvai_array_get( $vvai_engine, 'bins', array() );
	$vvai_steps  = array_values( array_filter( array_map( 'strval', (array) vvai_array_get( $vvai_engine, 'steps', array() ) ) ) );
	$vvai_found  = (array) vvai_array_get( $vvai_engine, 'found', array() );
	$vvai_exec   = (array) vvai_array_get( $vvai_engine, 'executable', array() );
	$vvai_os     = (string) vvai_array_get( $vvai_engine, 'os', 'unix' );
	?>
	<section class="vvai-panel vvai-engine" data-vvai-engine>
		<h2><?php esc_html_e( 'Video engine (FFmpeg)', 'viral-video-ai' ); ?></h2>

		<p class="vvai-lead">
			<?php if ( ! empty( $vvai_engine ) && empty( $vvai_engine['ok'] ) ) : ?>
				<strong><?php echo esc_html( (string) vvai_array_get( $vvai_engine, 'title', __( 'Clips cannot be rendered yet.', 'viral-video-ai' ) ) ); ?></strong>
			<?php elseif ( ! empty( $vvai_engine['ok'] ) ) : ?>
				<strong><?php esc_html_e( 'FFmpeg is working: uploads will produce real MP4 clips.', 'viral-video-ai' ); ?></strong>
			<?php endif; ?>
		</p>

		<table class="widefat striped vvai-table vvai-engine__bins">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Binary', 'viral-video-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Configured', 'viral-video-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actually used', 'viral-video-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'viral-video-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $vvai_bins as $vvai_bin ) : ?>
					<tr class="is-<?php echo empty( $vvai_bin['ok'] ) ? 'problem' : 'ready'; ?>">
						<th scope="row"><?php echo esc_html( (string) $vvai_bin['kind'] ); ?></th>
						<td><code><?php echo esc_html( (string) $vvai_bin['configured'] ); ?></code></td>
						<td><code><?php echo esc_html( (string) $vvai_bin['resolved'] ); ?></code></td>
						<td>
							<span class="vvai-dot <?php echo empty( $vvai_bin['ok'] ) ? 'is-bad' : 'is-ok'; ?>"></span>
							<?php
							if ( ! empty( $vvai_bin['ok'] ) ) {
								echo esc_html( (string) $vvai_bin['version'] );
							} else {
								echo esc_html( (string) ( $vvai_bin['error'] ? $vvai_bin['error'] : __( 'Not found', 'viral-video-ai' ) ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="vvai-engine__actions">
			<button type="button" class="button button-primary" data-vvai-engine-search><?php esc_html_e( 'Search this server for FFmpeg', 'viral-video-ai' ); ?></button>
			<span class="vvai-muted" data-vvai-engine-state><?php esc_html_e( 'Scans PATH, the usual install folders and the folder above, and tests each candidate by running it.', 'viral-video-ai' ); ?></span>
		</p>

		<div class="vvai-engine__found" data-vvai-engine-found<?php echo $vvai_found ? '' : ' hidden'; ?>>
			<?php if ( $vvai_found ) : ?>
				<h3><?php esc_html_e( 'Found on this server', 'viral-video-ai' ); ?></h3>
				<ul class="vvai-found-list">
					<?php foreach ( $vvai_found as $vvai_row ) : ?>
						<li>
							<code><?php echo esc_html( (string) $vvai_row['dir'] ); ?></code>
							<?php if ( ! empty( $vvai_row['ok'] ) ) : ?>
								<button type="button" class="button button-small" data-vvai-engine-apply data-dir="<?php echo esc_attr( (string) $vvai_row['dir'] ); ?>"><?php esc_html_e( 'Use this folder', 'viral-video-ai' ); ?></button>
							<?php else : ?>
								<span class="vvai-muted"><?php esc_html_e( 'Incomplete or not runnable', 'viral-video-ai' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php if ( $vvai_steps ) : ?>
			<h3><?php esc_html_e( 'How to fix it', 'viral-video-ai' ); ?></h3>
			<ol class="vvai-steps">
				<?php foreach ( $vvai_steps as $vvai_step ) : ?>
					<li><?php echo esc_html( $vvai_step ); ?></li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<details class="vvai-details">
			<summary><?php esc_html_e( 'What the plugin looked at', 'viral-video-ai' ); ?></summary>
			<ul class="vvai-kv-list">
				<li><?php esc_html_e( 'Platform', 'viral-video-ai' ); ?>: <code><?php echo esc_html( $vvai_os ); ?></code></li>
				<li><?php esc_html_e( 'PHP can execute', 'viral-video-ai' ); ?>: <code><?php echo esc_html( implode( ', ', array_map( 'strval', (array) vvai_array_get( $vvai_exec, 'methods', array() ) ) ) ?: __( 'no', 'viral-video-ai' ) ); ?></code></li>
				<li><?php esc_html_e( 'PHP settings', 'viral-video-ai' ); ?>: <code>disable_functions=<?php echo esc_html( (string) ( ini_get( 'disable_functions' ) ?: __( '(none)', 'viral-video-ai' ) ) ); ?></code></li>
				<li>
					<?php esc_html_e( 'Folders searched', 'viral-video-ai' ); ?>:
					<ol class="vvai-dir-list">
						<?php foreach ( (array) vvai_array_get( $vvai_engine, 'searched', array() ) as $vvai_dir ) : ?>
							<li><code><?php echo esc_html( (string) $vvai_dir ); ?></code></li>
						<?php endforeach; ?>
					</ol>
				</li>
			</ul>
		</details>
	</section>

	<?php if ( ! empty( $log['enabled'] ) ) : ?>
		<p>
			<button type="button" class="button" data-vvai-clear-log><?php esc_html_e( 'Clear debug log', 'viral-video-ai' ); ?></button>
			<span class="vvai-muted"><?php echo esc_html( (string) $log['file'] ? __( 'logs/vvai-debug.log', 'viral-video-ai' ) : __( 'log file not writable', 'viral-video-ai' ) ); ?></span>
		</p>
	<?php endif; ?>

	<table class="widefat striped vvai-table vvai-diagnostics">
		<tbody>
			<?php foreach ( (array) $report['items'] as $item ) : ?>
				<tr class="is-<?php echo esc_attr( (string) $item['status'] ); ?>">
					<th scope="row"><?php echo esc_html( (string) $item['label'] ); ?></th>
					<td><span class="vvai-status-icon"><?php echo esc_html( (string) $item['icon'] ); ?></span> <?php echo esc_html( (string) $item['value'] ); ?></td>
					<td class="vvai-muted"><?php echo esc_html( (string) $item['hint'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Recent log', 'viral-video-ai' ); ?></h2>
	<p class="vvai-muted"><?php esc_html_e( 'Job ids, provider endpoints, HTTP statuses and FFmpeg exit codes only. Keys and headers are never written here.', 'viral-video-ai' ); ?></p>
	<pre class="vvai-log"><?php echo esc_html( $log['tail'] ? implode( "\n", array_map( 'strval', (array) $log['tail'] ) ) : __( 'No log entries yet (enable debug logging in Settings).', 'viral-video-ai' ) ); ?></pre>
</div>
