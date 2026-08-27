<?php
/**
 * Admin: diagnostics.
 *
 * @var array<string,mixed> $report
 * @var array<string,mixed> $log
 * @var array<string,int>   $usage
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
