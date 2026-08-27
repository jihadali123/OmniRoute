<?php
/**
 * Admin: AI connections (the "Meta Box style" connection manager).
 *
 * The normal flow is: pick provider → paste key → Connect. Everything else is
 * behind Advanced, and no key ever comes back to the browser.
 *
 * @var array<int,array<string,mixed>> $connections
 * @var array<int,array<string,mixed>> $providers
 * @var string $active
 * @var string $fallback
 * @var bool   $allow_fallback
 * @var array<string,mixed> $crypto
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap vvai-wrap" data-vvai-connections
	data-providers="<?php echo esc_attr( wp_json_encode( $providers ) ); ?>"
	data-connections="<?php echo esc_attr( wp_json_encode( $connections ) ); ?>"
	data-active="<?php echo esc_attr( $active ); ?>"
	data-fallback="<?php echo esc_attr( $fallback ); ?>">
	<h1><?php esc_html_e( 'AI Connections', 'viral-video-ai' ); ?></h1>

	<p class="vvai-lead">
		<?php esc_html_e( 'Choose a provider, paste its API key, and press Connect. The plugin verifies the key with a real request from your server — the status only turns green when the provider accepts it.', 'viral-video-ai' ); ?>
	</p>

	<?php if ( empty( $crypto['ok'] ) ) : ?>
		<div class="notice notice-warning"><p><?php echo esc_html( $crypto['message'] ); ?></p></div>
	<?php endif; ?>

	<div class="vvai-columns">
		<section class="vvai-panel vvai-panel--list">
			<h2><?php esc_html_e( 'Saved connections', 'viral-video-ai' ); ?></h2>

			<div class="vvai-active-picker">
				<label for="vvai-active-select"><strong><?php esc_html_e( 'Active AI connection', 'viral-video-ai' ); ?></strong></label>
				<select id="vvai-active-select" data-vvai-active-select>
					<option value=""><?php esc_html_e( '— none —', 'viral-video-ai' ); ?></option>
					<?php foreach ( $connections as $connection ) : ?>
						<?php if ( 'connected' === (string) $connection['status'] ) : ?>
							<option value="<?php echo esc_attr( (string) $connection['id'] ); ?>" <?php selected( $active, (string) $connection['id'] ); ?>>
								<?php echo esc_html( (string) $connection['title'] ); ?> · <?php echo esc_html( (string) $connection['providerLabel'] ); ?>
							</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" data-vvai-save-active><?php esc_html_e( 'Apply', 'viral-video-ai' ); ?></button>
				<p class="vvai-muted"><?php esc_html_e( 'Video jobs use this connection. A disconnected connection can never be used.', 'viral-video-ai' ); ?></p>
			</div>

			<label class="vvai-check">
				<input type="checkbox" data-vvai-allow-fallback <?php checked( $allow_fallback ); ?> />
				<span><?php esc_html_e( 'If the active connection fails with a network or capacity error, retry once with a fallback connection', 'viral-video-ai' ); ?></span>
			</label>

			<div class="vvai-active-picker">
				<label for="vvai-fallback-select"><?php esc_html_e( 'Fallback connection', 'viral-video-ai' ); ?></label>
				<select id="vvai-fallback-select" data-vvai-fallback-select>
					<option value=""><?php esc_html_e( '— none —', 'viral-video-ai' ); ?></option>
					<?php foreach ( $connections as $connection ) : ?>
						<?php if ( 'connected' === (string) $connection['status'] ) : ?>
							<option value="<?php echo esc_attr( (string) $connection['id'] ); ?>" <?php selected( $fallback, (string) $connection['id'] ); ?>>
								<?php echo esc_html( (string) $connection['title'] ); ?>
							</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" data-vvai-save-fallback><?php esc_html_e( 'Apply', 'viral-video-ai' ); ?></button>
				<p class="vvai-muted"><?php esc_html_e( 'Authentication failures are never hidden by a fallback: a bad key stays visible so it gets fixed.', 'viral-video-ai' ); ?></p>
			</div>

			<div class="vvai-conn-grid" data-vvai-conn-grid>
				<?php if ( ! $connections ) : ?>
					<p class="vvai-empty-inline"><?php esc_html_e( 'No connections yet — add your first one on the right.', 'viral-video-ai' ); ?></p>
				<?php else : ?>
					<?php foreach ( $connections as $connection ) : ?>
						<?php $vvai_error = (array) $connection['lastError']; ?>
						<article class="vvai-conn" data-conn="<?php echo esc_attr( (string) $connection['id'] ); ?>">
							<header>
								<h3><?php echo esc_html( (string) $connection['title'] ); ?></h3>
								<span class="vvai-badge-provider"><?php echo esc_html( (string) $connection['providerLabel'] ); ?></span>
							</header>

							<p class="vvai-conn__status">
								<span class="vvai-dot <?php echo 'connected' === $connection['status'] ? 'is-ok' : ( 'failed' === $connection['status'] ? 'is-bad' : 'is-idle' ); ?>"></span>
								<strong data-vvai-conn-status><?php echo esc_html( (string) $connection['statusLabel'] ); ?></strong>
								<?php if ( $active === (string) $connection['id'] ) : ?>
									<em class="vvai-chip"><?php esc_html_e( 'Active', 'viral-video-ai' ); ?></em>
								<?php endif; ?>
							</p>

							<p class="vvai-conn__key"><code><?php echo esc_html( (string) $connection['secretMask'] ); ?></code></p>

							<?php if ( ! empty( $vvai_error['message'] ) ) : ?>
								<p class="vvai-conn__error"><?php echo esc_html( (string) $vvai_error['message'] ); ?></p>
							<?php endif; ?>

							<?php if ( '' !== (string) $connection['lastSuccessAt'] ) : ?>
								<p class="vvai-muted">
									<?php
									printf(
										/* translators: 1: time, 2: latency in ms. */
										esc_html__( 'Last verified %1$s · %2$d ms', 'viral-video-ai' ),
										esc_html( (string) $connection['lastSuccessAt'] ),
										(int) $connection['lastLatencyMs']
									);
									?>
								</p>
							<?php endif; ?>

							<footer>
								<?php if ( 'connected' === (string) $connection['status'] ) : ?>
									<button type="button" class="button" data-vvai-action="disconnect" data-id="<?php echo esc_attr( (string) $connection['id'] ); ?>"><?php esc_html_e( 'Disconnect', 'viral-video-ai' ); ?></button>
								<?php else : ?>
									<button type="button" class="button button-primary" data-vvai-action="connect" data-id="<?php echo esc_attr( (string) $connection['id'] ); ?>" <?php disabled( empty( $connection['hasSecret'] ) ); ?>><?php esc_html_e( 'Connect', 'viral-video-ai' ); ?></button>
								<?php endif; ?>

								<?php if ( 'connected' === (string) $connection['status'] && $active !== (string) $connection['id'] ) : ?>
									<button type="button" class="button" data-vvai-action="activate" data-id="<?php echo esc_attr( (string) $connection['id'] ); ?>"><?php esc_html_e( 'Set active', 'viral-video-ai' ); ?></button>
								<?php endif; ?>

								<button type="button" class="button-link-delete" data-vvai-action="delete" data-id="<?php echo esc_attr( (string) $connection['id'] ); ?>"><?php esc_html_e( 'Delete', 'viral-video-ai' ); ?></button>
								<button type="button" class="button-link" data-vvai-edit="<?php echo esc_attr( (string) $connection['id'] ); ?>"><?php esc_html_e( 'Edit', 'viral-video-ai' ); ?></button>
							</footer>
						</article>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</section>

		<section class="vvai-panel vvai-panel--form">
			<h2 data-vvai-form-title><?php esc_html_e( 'Add connection', 'viral-video-ai' ); ?></h2>

			<form data-vvai-conn-form novalidate>
				<input type="hidden" name="id" value="" data-vvai-field-id />

				<p class="vvai-field">
					<label for="vvai-c-title"><?php esc_html_e( 'Name this connection', 'viral-video-ai' ); ?></label>
					<input id="vvai-c-title" type="text" name="title" placeholder="<?php esc_attr_e( 'OpenAI — Main', 'viral-video-ai' ); ?>" data-vvai-field="title" />
					<small><?php esc_html_e( 'Anything you will recognise later. Optional.', 'viral-video-ai' ); ?></small>
				</p>

				<p class="vvai-field">
					<label for="vvai-c-provider"><?php esc_html_e( 'AI provider', 'viral-video-ai' ); ?></label>
					<select id="vvai-c-provider" name="provider" data-vvai-field="provider">
						<?php foreach ( $providers as $provider ) : ?>
							<option value="<?php echo esc_attr( (string) $provider['key'] ); ?>"><?php echo esc_html( (string) $provider['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<small data-vvai-provider-note></small>
				</p>

				<p class="vvai-field">
					<label for="vvai-c-key"><?php esc_html_e( 'API key', 'viral-video-ai' ); ?></label>
					<input id="vvai-c-key" type="password" name="api_key" autocomplete="off" spellcheck="false" data-vvai-field="api_key" placeholder="sk-…" />
					<small data-vvai-key-note><?php esc_html_e( 'Stored encrypted on your server. Never sent to the browser.', 'viral-video-ai' ); ?></small>
				</p>

				<p class="vvai-field">
					<button type="submit" class="button button-primary button-hero" data-vvai-submit><?php esc_html_e( 'Connect', 'viral-video-ai' ); ?></button>
					<button type="button" class="button" data-vvai-cancel-edit hidden><?php esc_html_e( 'Cancel', 'viral-video-ai' ); ?></button>
				</p>

				<div class="vvai-form-result" data-vvai-form-result hidden></div>

				<details class="vvai-advanced">
					<summary><?php esc_html_e( 'Advanced (usually not needed)', 'viral-video-ai' ); ?></summary>

					<p class="vvai-field">
						<label for="vvai-c-model"><?php esc_html_e( 'Model', 'viral-video-ai' ); ?></label>
						<input id="vvai-c-model" type="text" name="model" data-vvai-field="model" placeholder="<?php esc_attr_e( 'leave empty for the provider default', 'viral-video-ai' ); ?>" />
						<button type="button" class="button-link" data-vvai-load-models><?php esc_html_e( 'Load models from this account', 'viral-video-ai' ); ?></button>
						<select data-vvai-model-list hidden></select>
					</p>

					<p class="vvai-field">
						<label for="vvai-c-base"><?php esc_html_e( 'Base URL', 'viral-video-ai' ); ?></label>
						<input id="vvai-c-base" type="url" name="base_url" data-vvai-field="base_url" placeholder="https://api.openai.com/v1" />
						<small><?php esc_html_e( 'Only for gateways/proxies. Private and loopback addresses are blocked unless a filter allows them.', 'viral-video-ai' ); ?></small>
					</p>

					<div class="vvai-inline">
						<p class="vvai-field">
							<label for="vvai-c-temp"><?php esc_html_e( 'Temperature', 'viral-video-ai' ); ?></label>
							<input id="vvai-c-temp" type="number" step="0.1" min="0" max="2" name="temperature" data-vvai-field="temperature" />
						</p>
						<p class="vvai-field">
							<label for="vvai-c-tokens"><?php esc_html_e( 'Max output tokens', 'viral-video-ai' ); ?></label>
							<input id="vvai-c-tokens" type="number" min="256" max="128000" name="max_tokens" data-vvai-field="max_tokens" />
						</p>
						<p class="vvai-field">
							<label for="vvai-c-timeout"><?php esc_html_e( 'Timeout (s)', 'viral-video-ai' ); ?></label>
							<input id="vvai-c-timeout" type="number" min="10" max="900" name="timeout" data-vvai-field="timeout" />
						</p>
					</div>

					<label class="vvai-check">
						<input type="checkbox" name="smoke_test" data-vvai-field="smoke_test" />
						<span><?php esc_html_e( 'Also send one 1-token generation to prove the model works, not just the key', 'viral-video-ai' ); ?></span>
					</label>
				</details>
			</form>
		</section>
	</div>
</div>
