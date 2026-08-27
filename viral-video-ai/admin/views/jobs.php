<?php
/**
 * Admin: job list with real statuses, progress and actions.
 *
 * @var array<string,mixed> $page
 * @var array<string,mixed> $args
 * @var array<string,int>   $stats
 * @var array<int,array<string,mixed>> $jobs
 * @var array<int,array<string,mixed>> $rows
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

$vvai_view = get_query_var( 'vvai_job' );
?>
<div class="wrap vvai-wrap" data-vvai-jobs>
	<h1><?php esc_html_e( 'Video AI Jobs', 'viral-video-ai' ); ?></h1>

	<ul class="vvai-subsub">
		<li><a class="<?php echo '' === (string) $args['status'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-jobs' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: count. */ __( 'All (%s)', 'viral-video-ai' ), number_format_i18n( (int) $page['total'] ) ) ); ?></a></li>
		<?php foreach ( array( 'completed', 'failed', 'analyzing', 'rendering_clips', 'transcribing' ) as $vvai_status ) : ?>
			<li>
				<a class="<?php echo $args['status'] === $vvai_status ? 'is-active' : ''; ?>"
				   href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-jobs&status=' . $vvai_status ) ); ?>">
					<?php echo esc_html( VVAI_Job_Status::label( $vvai_status ) ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<form method="get" class="vvai-filters">
		<input type="hidden" name="page" value="vvai-jobs" />
		<input type="search" name="s" value="<?php echo esc_attr( (string) $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search titles', 'viral-video-ai' ); ?>" />
		<button class="button"><?php esc_html_e( 'Search', 'viral-video-ai' ); ?></button>
		<button type="button" class="button" data-vvai-refresh><?php esc_html_e( 'Refresh', 'viral-video-ai' ); ?></button>
		<span class="vvai-muted" data-vvai-refresh-hint><?php esc_html_e( 'Running jobs update automatically.', 'viral-video-ai' ); ?></span>
	</form>

	<table class="widefat striped vvai-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Job', 'viral-video-ai' ); ?></th>
				<th><?php esc_html_e( 'User', 'viral-video-ai' ); ?></th>
				<th><?php esc_html_e( 'Status', 'viral-video-ai' ); ?></th>
				<th><?php esc_html_e( 'Progress', 'viral-video-ai' ); ?></th>
				<th><?php esc_html_e( 'Clips', 'viral-video-ai' ); ?></th>
				<th><?php esc_html_e( 'Source', 'viral-video-ai' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'viral-video-ai' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No jobs found.', 'viral-video-ai' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $index => $row ) : ?>
			<?php
			$vvai_payload = $jobs[ $index ];
			$vvai_source  = (string) $row['source_path'];
			$vvai_media   = (array) $row['media'] ?? array();
			?>
			<tr data-job="<?php echo (int) $row['id']; ?>">
				<td>
					<strong>#<?php echo (int) $row['id']; ?></strong> <?php echo esc_html( (string) $row['title'] ); ?>
					<?php if ( '' !== (string) $row['error_message'] && 'completed' !== (string) $row['status'] ) : ?>
						<div class="vvai-row-error"><?php echo esc_html( (string) $row['error_message'] ); ?></div>
					<?php endif; ?>
				</td>
				<td><?php $vvai_user = get_user_by( 'id', (int) $row['author_id'] ); echo esc_html( $vvai_user ? (string) $vvai_user->display_name : __( 'deleted user', 'viral-video-ai' ) ); ?></td>
				<td>
					<span class="vvai-badge-status <?php echo esc_attr( VVAI_Job_Status::badge_class( (string) $row['status'] ) ); ?>">
						<?php echo esc_html( VVAI_Job_Status::label( (string) $row['status'] ) ); ?>
					</span>
					<div class="vvai-muted"><?php echo esc_html( (string) $vvai_payload['stageLabel'] ); ?></div>
				</td>
				<td>
					<div class="vvai-minibar"><i style="width:<?php echo (int) $vvai_payload['progress']; ?>%"></i></div>
					<span class="vvai-muted"><?php echo (int) $vvai_payload['progress']; ?>%</span>
				</td>
				<td><?php echo (int) $vvai_payload['renderedCount']; ?>/<?php echo (int) $vvai_payload['clipCount']; ?></td>
				<td class="vvai-muted">
					<?php
					echo esc_html( trim( (string) $vvai_payload['humanSize'] . ' · ' . vvai_format_time( (float) $row['duration'] ) . ( (int) $row['width'] > 0 ? ' · ' . (int) $row['width'] . '×' . (int) $row['height'] : '' ) ) );
					?>
					<?php if ( '' === $vvai_source || ! is_file( $vvai_source ) ) : ?>
						<div class="vvai-muted"><?php esc_html_e( 'source file removed', 'viral-video-ai' ); ?></div>
					<?php endif; ?>
				</td>
				<td class="vvai-actions">
					<?php if ( in_array( (string) $row['status'], array( 'failed', 'cancelled' ), true ) ) : ?>
						<button type="button" class="button button-small" data-vvai-job-action="retry" data-id="<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Retry', 'viral-video-ai' ); ?></button>
					<?php elseif ( 'queued' === (string) $row['status'] ) : ?>
						<button type="button" class="button button-small" data-vvai-job-action="start" data-id="<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Start', 'viral-video-ai' ); ?></button>
					<?php elseif ( ! VVAI_Job_Status::is_terminal( (string) $row['status'] ) ) : ?>
						<button type="button" class="button button-small" data-vvai-job-action="cancel" data-id="<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Cancel', 'viral-video-ai' ); ?></button>
					<?php endif; ?>
					<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-jobs&job=' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Details', 'viral-video-ai' ); ?></a>
					<button type="button" class="button-link-delete" data-vvai-job-action="delete" data-id="<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Delete', 'viral-video-ai' ); ?></button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( (int) $page['pages'] > 1 ) : ?>
		<p class="vvai-pagination">
			<?php for ( $vvai_p = 1; $vvai_p <= min( 12, (int) $page['pages'] ); $vvai_p++ ) : ?>
				<a class="button <?php echo (int) $page['page'] === $vvai_p ? 'button-primary' : ''; ?>"
				   href="<?php echo esc_url( admin_url( 'admin.php?page=vvai-jobs&paged=' . $vvai_p . ( '' !== (string) $args['status'] ? '&status=' . $args['status'] : '' ) ) ); ?>"><?php echo (int) $vvai_p; ?></a>
			<?php endfor; ?>
		</p>
	<?php endif; ?>
</div>
