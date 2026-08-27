/**
 * Viral Video AI — admin screens.
 *
 * One small SPA per page: connection manager, job table, settings form,
 * diagnostics. Everything goes through the plugin's REST routes, so the same
 * permission checks that protect the frontend also protect the backend.
 */
(function () {
	'use strict';

	var config = window.VVAIAdmin || {};
	var rest = (config.restUrl || '').replace(/\/+$/, '');
	var i18n = config.i18n || {};

	function request(method, path, body) {
		var init = {
			method: method,
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce || '' }
		};

		if (body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		}

		return fetch(rest + path, init).then(function (response) {
			return response.text().then(function (text) {
				var data = null;

				try { data = text ? JSON.parse(text) : null; } catch (e) { data = { message: text || '' }; }

				if (data && data.success === true) { data = data.data; }

				if (!response.ok) {
					var error = new Error((data && data.message) ? data.message : 'HTTP ' + response.status);
					error.code = data && data.code ? data.code : 'http_error';
					error.data = (data && data.data) ? data.data : data;
					error.payload = data;
					throw error;
				}

				return data;
			});
		});
	}

	function ajax(action, fields) {
		var form = new URLSearchParams();
		form.set('action', action);
		form.set('nonce', config.ajaxNonce || '');

		Object.keys(fields || {}).forEach(function (key) {
			var value = fields[key];

			// Nested form arrays (vvai[key]) are expanded here.
			if (value && typeof value === 'object' && !(value instanceof Date)) {
				Object.keys(value).forEach(function (inner) {
					form.set(key + '[' + inner + ']', value[inner]);
				});

				return;
			}

			form.set(key, value === null || value === undefined ? '' : value);
		});

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: form.toString()
		}).then(function (response) {
			return response.json().catch(function () {
				throw new Error('The server returned an unexpected response.');
			});
		}).then(function (payload) {
			if (!payload || payload.success !== true) {
				var message = (payload && payload.data && payload.data.message) ? payload.data.message : 'Request failed';
				var error = new Error(message);
				error.data = (payload && payload.data) ? payload.data : {};
				throw error;
			}

			return payload.data;
		});
	}

	function el(sel, root) { return (root || document).querySelector(sel); }
	function els(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function feedback(node, kind, message, hint) {
		if (!node) { return; }

		node.hidden = false;
		node.className = 'vvai-form-result is-' + kind;
		node.textContent = message;

		if (hint) {
			var small = document.createElement('small');
			small.textContent = hint;
			node.appendChild(small);
		}
	}

	// ------------------------------------------------------------------ connections
	function initConnections() {
		var wrap = el('[data-vvai-connections]');

		if (!wrap) { return; }

		var providers = JSON.parse(wrap.getAttribute('data-providers') || '[]');
		var form = el('[data-vvai-conn-form]', wrap);
		var result = el('[data-vvai-form-result]', wrap);
		var providerSelect = el('[data-vvai-field="provider"]', form);
		var keyField = el('[data-vvai-field="api_key"]', form);
		var submit = el('[data-vvai-submit]', form);

		function describeProvider() {
			var provider = providers.filter(function (item) { return item.key === providerSelect.value; })[0];

			if (!provider) { return; }

			var note = el('[data-vvai-provider-note]', form);
			var bits = [];

			if (provider.notes) { bits.push(provider.notes); }

			note.textContent = bits.join(' ');

			var placeholder = provider.prefix ? provider.prefix + '…' : 'paste your key';
			keyField.placeholder = placeholder;

			var keyNote = el('[data-vvai-key-note]', form);

			keyNote.innerHTML = '';
			keyNote.appendChild(document.createTextNode('Key from ' + (provider.docs ? provider.docs : 'your provider dashboard') + '. Stored encrypted, never sent to the browser.'));

			if (provider.docs) {
				var link = document.createElement('a');
				link.href = provider.docs;
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				link.textContent = ' ' + 'Get a key';
				keyNote.appendChild(link);
			}

			var model = el('[data-vvai-field="model"]', form);

			if (model && !model.value) {
				model.placeholder = provider.defaultModel + (provider.models && provider.models.length ? ' · ' + provider.models.slice(0, 4).join(', ') : '');
			}

			el('[data-vvai-field="base_url"]', form).placeholder = provider.baseUrl || 'https://…';
		}

		providerSelect.addEventListener('change', describeProvider);
		describeProvider();

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var payload = {};

			els('[data-vvai-field]', form).forEach(function (input) {
				var key = input.getAttribute('data-vvai-field');

				if (input.type === 'checkbox') {
					payload[key] = input.checked ? 1 : 0;
					return;
				}

				payload[key] = input.value;
			});

			payload.id = el('[data-vvai-field-id]', form).value || '';

			if (!payload.id && !payload.api_key) {
				feedback(result, 'error', i18n.reconnectRequired || 'An API key is required.');
				return;
			}

			submit.disabled = true;
			submit.textContent = i18n.connecting || 'Connecting…';

			var when = payload.id ? request('PUT', '/connections/' + encodeURIComponent(payload.id), payload) : ajax('vvai_connection_save', payload);

			when.then(function (data) {
				var failed = data && (data.failed || data.connected === false);

				feedback(
					result,
					failed ? 'error' : 'success',
					failed ? (data.message || i18n.failed) : (data.message || i18n.connected),
					data.hint || ''
				);

				if (!failed) {
					renderList(data.connections || []);
					form.reset();
					el('[data-vvai-field-id]', form).value = '';
					el('[data-vvai-form-title]').textContent = 'Add connection';
					describeProvider();
				}
			}).catch(function (error) {
				feedback(result, 'error', error.message, (error.data && (error.data.hint || error.data.message)) || '');
			}).then(function () {
				submit.disabled = false;
				submit.textContent = 'Connect';
			});
		});

		function renderList(connections) {
			var grid = el('[data-vvai-conn-grid]', wrap);

			grid.innerHTML = '';

			if (!connections.length) {
				grid.innerHTML = '<p class="vvai-empty-inline">No connections yet.</p>';
				return;
			}

			connections.forEach(function (connection) {
				var card = document.createElement('article');
				card.className = 'vvai-conn';
				card.setAttribute('data-conn', connection.id);

				var statusClass = connection.status === 'connected' ? 'is-ok' : (connection.status === 'failed' ? 'is-bad' : 'is-idle');
				var errorMessage = connection.lastError && connection.lastError.message ? connection.lastError.message : '';

				card.innerHTML =
					'<header><h3></h3><span class="vvai-badge-provider"></span></header>' +
					'<p class="vvai-conn__status"><span class="vvai-dot ' + statusClass + '"></span> <strong></strong></p>' +
					'<p class="vvai-conn__key"><code></code></p>' +
					(errorMessage ? '<p class="vvai-conn__error"></p>' : '') +
					'<footer></footer>';

				card.querySelector('h3').textContent = connection.title;
				card.querySelector('.vvai-badge-provider').textContent = connection.providerLabel;
				card.querySelector('.vvai-conn__status strong').textContent = connection.statusLabel;
				card.querySelector('.vvai-conn__key code').textContent = connection.secretMask || '(no key saved)';

				if (errorMessage) {
					card.querySelector('.vvai-conn__error').textContent = errorMessage;
				}

				var footer = card.querySelector('footer');

				function button(label, action, primary) {
					var node = document.createElement('button');
					node.type = 'button';
					node.className = 'button' + (primary ? ' button-primary' : '');
					node.textContent = label;
					node.setAttribute('data-id', connection.id);

					node.addEventListener('click', function () {
						if (action === 'delete' && !window.confirm(i18n.confirmDelete || 'Delete?')) {
							return;
						}

						if (action === 'connect') {
							node.disabled = true;
							node.textContent = i18n.connecting || 'Connecting…';
						}

						ajax('vvai_connection_' + action, { id: connection.id }).then(function (data) {
							renderList(data.connections || []);

							if (data.message) {
								feedback(result, data.failed ? 'error' : 'success', data.message, data.hint || '');
							}
						}).catch(function (error) {
							node.disabled = false;
							node.textContent = label;
							feedback(result, 'error', error.message, (error.data && (error.data.hint || '')) || '');

							if (error.data && error.data.connections) {
								renderList(error.data.connections);
							}
						});
					});

					return node;
				}

				if (connection.status === 'connected') {
					footer.appendChild(button('Disconnect', 'disconnect'));

					if (!connection.isActive) {
						footer.appendChild(button(i18n.setActive || 'Set as active', 'activate'));
					}
				} else {
					footer.appendChild(button('Connect', 'connect', true));
				}

				var edit = document.createElement('button');
				edit.type = 'button';
				edit.className = 'button-link';
				edit.textContent = 'Edit';
				edit.addEventListener('click', function () {
					el('[data-vvai-field-id]', form).value = connection.id;
					el('[data-vvai-field="title"]', form).value = connection.title || '';
					el('[data-vvai-field="provider"]', form).value = connection.provider || 'openai';
					el('[data-vvai-field="api_key"]', form).value = '';
					el('[data-vvai-field="model"]', form).value = connection.model || '';
					el('[data-vvai-field="base_url"]', form).value = connection.baseUrl || '';
					el('[data-vvai-field="temperature"]', form).value = connection.temperature === undefined ? '' : connection.temperature;
					el('[data-vvai-field="max_tokens"]', form).value = connection.max_tokens === undefined ? '' : connection.max_tokens;
					el('[data-vvai-field="timeout"]', form).value = connection.timeout === undefined ? '' : connection.timeout;
					el('[data-vvai-cancel-edit]', form).hidden = false;
					el('[data-vvai-form-title]').textContent = 'Edit ' + connection.title;
					describeProvider();
					form.scrollIntoView({ behavior: 'smooth', block: 'start' });
				});
				footer.appendChild(edit);

				var del = document.createElement('button');
				del.type = 'button';
				del.className = 'button-link-delete';
				del.textContent = 'Delete';
				del.addEventListener('click', function () {
					if (!window.confirm(i18n.confirmDelete || 'Delete this connection?')) { return; }

					ajax('vvai_connection_delete', { id: connection.id }).then(function () {
						location.reload();
					});
				});
				footer.appendChild(del);

				grid.appendChild(card);
			});
		}

		var cancel = el('[data-vvai-cancel-edit]', form);

		if (cancel) {
			cancel.addEventListener('click', function () {
				form.reset();
				el('[data-vvai-field-id]', form).value = '';
				cancel.hidden = true;
				el('[data-vvai-form-title]').textContent = 'Add connection';
			});
		}

		// Advanced: list the models actually available on this account.
		var loadModels = el('[data-vvai-load-models]', form);

		if (loadModels) {
			loadModels.addEventListener('click', function () {
				var id = el('[data-vvai-field-id]', form).value;

				if (!id) {
					feedback(result, 'warning', 'Save the connection first, then load its models.');
					return;
				}

				loadModels.disabled = true;
				loadModels.textContent = i18n.processing || 'Working…';

				ajax('vvai_connection_models', { id: id }).then(function (data) {
					var list = el('[data-vvai-model-list]', form);

					list.innerHTML = '';
					list.hidden = false;

					(data.models || []).forEach(function (model) {
						var option = document.createElement('option');
						option.value = model;
						option.textContent = model;
						list.appendChild(option);
					});

					list.onchange = function () {
						el('[data-vvai-field="model"]', form).value = list.value;
					};

					feedback(result, 'success', (data.models || []).length + ' models available on this account.');
				}).catch(function (error) {
					feedback(result, 'error', error.message);
				}).then(function () {
					loadModels.disabled = false;
					loadModels.textContent = 'Load models from this account';
				});
			});
		}

		// Active + fallback selectors.
		els('[data-vvai-save-active], [data-vvai-save-fallback]', wrap).forEach(function (button) {
			button.addEventListener('click', function () {
				var isFallback = button.hasAttribute('data-vvai-save-fallback');
				var select = el(isFallback ? '[data-vvai-fallback-select]' : '[data-vvai-active-select]', wrap);

				if (isFallback) {
					request('POST', '/settings', { fallback_connection_id: select.value, allow_fallback: !!select.value })
						.then(function () { location.reload(); });
					return;
				}

				if (!select.value) {
					request('POST', '/settings', { active_connection_id: '' }).then(function () { location.reload(); });
					return;
				}

				ajax('vvai_connection_activate', { id: select.value, slot: 'active' }).then(function () {
					location.reload();
				}).catch(function (error) {
					feedback(result, 'error', error.message);
				});
			});
		});
	}

	// ------------------------------------------------------------------ jobs
	function initJobs() {
		var wrap = el('[data-vvai-jobs]');

		if (!wrap) { return; }

		els('[data-vvai-job-action]', wrap).forEach(function (button) {
			button.addEventListener('click', function () {
				var action = button.getAttribute('data-vvai-job-action');
				var id = button.getAttribute('data-id');

				if (action === 'delete' && !window.confirm(i18n.confirmJobDelete || 'Delete this job?')) {
					return;
				}

				button.disabled = true;
				button.textContent = i18n.processing || 'Working…';

				ajax('vvai_job_action', { id: id, job_action: action }).then(function (data) {
					if (action === 'delete') {
						location.reload();
						return;
					}

					var row = wrap.querySelector('[data-job="' + id + '"]');

					if (row && data.job) {
						var bar = row.querySelector('.vvai-minibar i');
						var status = row.querySelector('.vvai-badge-status');

						if (bar) { bar.style.width = (data.job.progress || 0) + '%'; }
						if (status) { status.textContent = data.job.stageLabel || data.job.status; }
					}

					button.disabled = false;
					button.textContent = action.charAt(0).toUpperCase() + action.slice(1);

					if (action === 'retry' || action === 'start') {
						window.setTimeout(function () { location.reload(); }, 1200);
					}
				}).catch(function (error) {
					button.disabled = false;
					window.alert(error.message);
				});
			});
		});

		var refresh = el('[data-vvai-refresh]', wrap);

		if (refresh) {
			refresh.addEventListener('click', function () { location.reload(); });
		}

		// Auto-poll while anything is running.
		if (wrap.querySelector('.is-running')) {
			window.setTimeout(function () { location.reload(); }, 3000);
		}
	}

	// ------------------------------------------------------------------ settings
	function initSettings() {
		var form = el('[data-vvai-settings-form]');

		if (!form) { return; }

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var payload = {};

			els('input[name^="vvai["], select[name^="vvai["]', form).forEach(function (input) {
				var name = input.name.replace(/^vvai\[/, '').replace(/\]$/, '');

				payload[name] = input.type === 'checkbox' ? (input.checked ? 1 : 0) : input.value;
			});

			var state = el('[data-vvai-save-state]', form);

			if (state) { state.textContent = i18n.saving || 'Saving…'; }

			ajax('vvai_settings_save', { vvai: payload }).then(function (data) {
				if (state) { state.textContent = data.message || i18n.saved || 'Saved'; }
			}).catch(function (error) {
				if (state) { state.textContent = error.message; }
			});
		});
	}

	// ------------------------------------------------------------------ diagnostics
	function initDiagnostics() {
		var wrap = el('[data-vvai-diagnostics]');

		if (!wrap) { return; }

		var recheck = el('[data-vvai-recheck]', wrap);

		if (recheck) {
			recheck.addEventListener('click', function () {
				recheck.disabled = true;
				recheck.textContent = i18n.processing || 'Working…';

				ajax('vvai_diagnostics_recheck', {}).then(function () {
					location.reload();
				}).catch(function (error) {
					window.alert(error.message);
					recheck.disabled = false;
					recheck.textContent = 'Re-check now';
				});
			});
		}

		var clear = el('[data-vvai-clear-log]', wrap);

		if (clear) {
			clear.addEventListener('click', function () {
				ajax('vvai_log_clear', {}).then(function () { location.reload(); });
			});
		}
	}

	function boot() {
		initConnections();
		initJobs();
		initSettings();
		initDiagnostics();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
