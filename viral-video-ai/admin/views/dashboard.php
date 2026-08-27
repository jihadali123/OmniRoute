<?php
/**
 * Admin: dashboard.
 *
 * @var array<string,int>     $stats
 * @var array<string,mixed>   $summary
 * @var array<string,mixed>|null $active
 * @var array<int,array<string,mixed>> $connections
 * @var array<int,array<string,mixed>> $recent
 * @var array<string,int>     $usage
 * @var array<string,mixed>   $report
 * @var string                $plugin_url
 * @var string                $admin_url
 * @var string                $transcription
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

$vvai_cards = array(
	array(
		'label' => __( 'Total jobs', 'viral-video-ai' ),
		'value' => (int) $stats['total'],
		'hint'  => __( 'All time', 'viral-video-ai' ),
	),
	array(
		'label' => __( 'Completed', 'viral-video-ai' ),
		'value' => (int) $stats['completed'],
		'hint'  => __( 'Clips ready to download', 'viral-video-ai' ),
	),
	array(
		'label' => __( 'In progress', 'viral-video-ai' ),
		'value' => (int) $stats['active'] + (int) $stats['queued'],
		'hint'  => __( 'Queued or rendering', 'viral-video-ai' ),
	),
	array(
		'label' => __( 'Failed', 'viral-video-ai' ),
		'value' => (int) $stats['failed'],
		'hint'  => __( 'Retryable from the job list', 'viral-video-ai' ),
	),
	array(
		'label' => __( 'Clips generated', 'viral-video-ai' ),
		'value' => (int) $stats['clips'],
		'hint'  => __( 'Real MP4 files on disk', 'viral-video-ai' ),
	),
	array(
		'label' => __( 'Storage used', 'viral-video-ai' ),
		'value' => vvai_human_size( (int) $usage['bytes'] ),
		'hint'  => sprintf(
			/* translators: 1: file count, 2: clip count. */
			__( '%1$d files · %2$d clips', 'viral-video-ai' ),
			(int) $usage['files'],
			(int) $usage['clips']
		),
	),
);
?>
<div class="wrap vvai-wrap">
	<h1><?php esc_html_e( 'Viral Video AI', 'viral-video-ai' ); ?></h1>

	<?php if ( empty( $summary['ready'] ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Your server is not fully ready for video processing. Open Diagnostics to see exactly what is missing.', 'viral-video-ai' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-diagnostics' ) ); ?>"><?php esc_html_e( 'View diagnostics', 'viral-video-ai' ); ?></a>
		</p></div>
	<?php endif; ?>

	<div class="vvai-cards">
		<?php foreach ( $vvai_cards as $card ) : ?>
			<div class="vvai-card">
				<span class="vvai-card__value"><?php echo esc_html( is_int( $card['value'] ) ? number_format_i18n( $card['value'] ) : $card['value'] ); ?></span>
				<span class="vvai-card__label"><?php echo esc_html( $card['label'] ); ?></span>
				<span class="vvai-card__hint"><?php echo esc_html( $card['hint'] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="vvai-columns">
		<section class="vvai-panel">
			<h2><?php esc_html_e( 'Server status', 'viral-video-ai' ); ?></h2>
			<table class="vvai-kv">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'FFmpeg', 'viral-video-ai' ); ?></th>
						<td>
							<?php if ( ! empty( $summary['ffmpeg'] ) ) : ?>
								<span class="vvai-dot is-ok"></span><?php echo esc_html( sprintf( /* translators: %s: version. */ __( 'Available — %s', 'viral-video-ai' ), (string) $summary['ffmpeg_version'] ) ); ?>
							<?php else : ?>
								<span class="vvai-dot is-bad"></span><?php esc_html_e( 'Not available — clips cannot be rendered', 'viral-video-ai' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'PHP execution', 'viral-video-ai' ); ?></th>
						<td>
							<?php $vvai_proc = VVAI_Process::capability(); ?>
							<span class="vvai-dot <?php echo $vvai_proc['available'] ? 'is-ok' : 'is-bad'; ?>"></span>
							<?php echo esc_html( $vvai_proc['available'] ? implode( ', ', $vvai_proc['methods'] ) : $vvai_proc['reason'] ); ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Upload limit', 'viral-video-ai' ); ?></th>
						<td><?php echo esc_html( size_format( (int) $summary['uploads'] * MB_IN_BYTES ) ); ?> · <?php esc_html_e( 'memory', 'viral-video-ai' ); ?> <?php echo esc_html( (string) $summary['memory'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Transcription', 'viral-video-ai' ); ?></th>
						<td><?php echo esc_html( ucfirst( str_replace( '-', ' ', (string) $transcription ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Scheduler', 'viral-video-ai' ); ?></th>
						<td><?php echo esc_html( VVAI_Job_Queue::has_async_scheduler() ? __( 'Action Scheduler detected', 'viral-video-ai' ) : __( 'WP-Cron heartbeat (1 min)', 'viral-video-ai' ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</section>

		<section class="vvai-panel">
			<h2><?php esc_html_e( 'Active AI connection', 'viral-video-ai' ); ?></h2>
			<?php if ( $active ) : ?>
				<p class="vvai-active">
					<strong><?php echo esc_html( (string) $active['title'] ); ?></strong>
					<span class="vvai-chip"><?php echo esc_html( VVAI_Api_Manager::label_for( (string) $active['provider'] ) ); ?></span>
					<span class="vvai-dot is-ok"></span>
					<?php esc_html_e( 'Connected', 'viral-video-ai' ); ?>
				</p>
				<p class="vvai-muted">
					<?php
					printf(
						/* translators: %s: human time. */
						esc_html__( 'Last verified %s ago.', 'viral-video-ai' ),
						esc_html( '' !== (string) $active['lastSuccessAt'] ? human_time_diff( strtotime( (string) $active['lastSuccessAt'] ), time() ) : __( 'never', 'viral-video-ai' ) )
					);
					?>
				</p>
			<?php else : ?>
				<p class="vvai-empty-inline">
					<?php esc_html_e( 'No connected provider — generation is disabled until one is connected.', 'viral-video-ai' ); ?>
				</p>
			<?php endif; ?>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-connections' ) ); ?>"><?php esc_html_e( 'Manage connections', 'viral-video-ai' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'viral-video-ai' ); ?></a>
			</p>

			<?php if ( count( $connections ) > 1 ) : ?>
				<ul class="vvai-conn-list">
					<?php foreach ( $connections as $connection ) : ?>
						<li>
							<span><?php echo esc_html( (string) $connection['title'] ); ?></span>
							<em><?php echo esc_html( (string) $connection['statusLabel'] ); ?></em>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	</div>

	<section class="vvai-panel">
		<h2><?php esc_html_e( 'Recent jobs', 'viral-video-ai' ); ?></h2>
		<?php if ( ! $recent ) : ?>
			<p class="vvai-empty-inline"><?php esc_html_e( 'No jobs yet. Add the Viral Video AI widget to a page, or use the [vvai_generator] shortcode.', 'viral-video-ai' ); ?></p>
		<?php else : ?>
			<table class="widefat striped vvai-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job', 'viral-video-ai' ); ?></th>
						<th><?php esc_html_e( 'Status', 'viral-video-ai' ); ?></th>
						<th><?php esc_html_e( 'Stage', 'viral-video-ai' ); ?></th>
						<th><?php esc_html_e( 'Clips', 'viral-video-ai' ); ?></th>
						<th><?php esc_html_e( 'When', 'viral-video-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $job ) : ?>
						<?php $vvai_payload = VVAI_Job_Status::public_payload( (array) $job ); ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-jobs&job=' . (int) $job['id'] ) ); ?>">
									#<?php echo (int) $job['id']; ?> · <?php echo esc_html( (string) $job['title'] ); ?>
								</a>
							</td>
							<td><span class="vvai-badge-status <?php echo esc_attr( VVAI_Job_Status::badge_class( (string) $job['status'] ) ); ?>"><?php echo esc_html( VVAI_Job_Status::label( (string) $job['status'] ) ); ?></span></td>
							<td><?php echo esc_html( (string) $vvai_payload['stageLabel'] ); ?> (<?php echo (int) $vvai_payload['progress']; ?>%)</td>
							<td><?php echo (int) $vvai_payload['renderedCount']; ?>/<?php echo (int) $vvai_payload['clipCount']; ?></td>
							<td><?php echo esc_html( (string) $job['created_at'] ); ?> UTC</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-jobs' ) ); ?>"><?php esc_html_e( 'View all jobs', 'viral-video-ai' ); ?></a></p>
		<?php endif; ?>
	</section>
</div>
