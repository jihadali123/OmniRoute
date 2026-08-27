/**
 * Viral Video AI — frontend application.
 *
 * Responsibilities:
 *  - chunked, resumable upload with real byte progress (XHR), speed and ETA,
 *  - job creation with the visible options,
 *  - status polling driven by the server (never a fake timer),
 *  - rendering of the finished clips with preview, metadata and downloads,
 *  - honest error reporting with retry.
 *
 * The page never sees an API key; everything sensitive happens server-side.
 */
(function () {
	'use strict';

	var VERSION = '1.0.3';

	/**
	 * Small helpers ---------------------------------------------------------
	 */
	function qs(root, sel) { return root.querySelector(sel); }
	function qsa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

	/**
	 * Turn a row of .vvai-chip buttons into a single-value control.
	 *
	 * Returns a getter for the selected value, and keeps keyboard/focus sane.
	 */
	function chipGroup(root, selector, attribute, initial) {
		var group = root.querySelector(selector);

		if (!group) { return function () { return null; }; }

		var buttons = Array.prototype.slice.call(group.querySelectorAll('[data-' + attribute + ']'));

		if (!buttons.length) { return function () { return null; }; }

		function select(button) {
			buttons.forEach(function (b) {
				var on = b === button;
				b.classList.toggle('is-on', on);
				b.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
		}

		buttons.forEach(function (button) {
			if (initial && button.getAttribute('data-' + attribute) === String(initial)) {
				select(button);
			}

			button.addEventListener('click', function () {
				select(button);
				root.dispatchEvent(new CustomEvent('vvai:change', { bubbles: true }));
			});
		});

		return function () {
			var active = buttons.filter(function (b) { return b.classList.contains('is-on'); })[0];
			return active ? active.getAttribute('data-' + attribute) : buttons[0].getAttribute('data-' + attribute);
		};
	}

	function human(bytes) {
		bytes = Number(bytes) || 0;
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var power = Math.min(units.length - 1, Math.floor(Math.log(bytes || 1) / Math.log(1024)));
		return (bytes / Math.pow(1024, power)).toFixed(power ? 1 : 0) + ' ' + units[power];
	}

	function clock(seconds) {
		seconds = Math.max(0, Math.floor(Number(seconds) || 0));
		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		function pad(v) { return (v < 10 ? '0' : '') + v; }
		return (h ? pad(h) + ':' : '') + pad(m) + ':' + pad(s);
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}

	function wait(ms) {
		return new Promise(function (resolve) { setTimeout(resolve, ms); });
	}

	/**
	 * API layer --------------------------------------------------------------
	 */
	function Api(config) {
		this.config = config;
	}

	Api.prototype.url = function (path) {
		return String(this.config.restUrl || '').replace(/\/+$/, '') + path;
	};

	Api.prototype.request = function (method, path, body) {
		var self = this;
		var init = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': self.config.nonce || '',
				'Accept': 'application/json'
			}
		};

		if (body !== undefined && body !== null) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		}

		return fetch(self.url(path), init).then(function (response) {
			return response.text().then(function (text) {
				var data = null;

				try {
					data = text ? JSON.parse(text) : null;
				} catch (error) {
					data = { message: text ? text.slice(0, 300) : '' };
				}

				if (!response.ok) {
					var error2 = new Error((data && data.message) ? data.message : 'HTTP ' + response.status);
					error2.status = response.status;
					error2.code = data && data.code ? data.code : 'http_error';
					error2.data = data && data.data ? data.data : {};
					error2.payload = data;
					throw error2;
				}

				// WordPress wraps rest_ensure_response() results for objects.
				if (data && typeof data === 'object' && Object.prototype.hasOwnProperty.call(data, 'success') && data.success === true && data.data) {
					return data.data;
				}

				return data;
			});
		});
	};

	Api.prototype.get = function (path) { return this.request('GET', path); };
	Api.prototype.post = function (path, body) { return this.request('POST', path, body || {}); };

	/**
	 * Resumable chunked uploader --------------------------------------------
	 */
	function Uploader(api, options) {
		this.api = api;
		this.options = options || {};
		this.cancelled = false;
		this.retries = 0;
	}

	Uploader.prototype.abort = function () {
		this.cancelled = true;
		if (this.xhr) {
			try { this.xhr.abort(); } catch (e) { /* already finished */ }
		}
	};

	/**
	 * Hash is only a resume hint, so it is skipped for very large files or when
	 * crypto.subtle is unavailable (non-secure context).
	 */
	Uploader.prototype.fingerprint = function (file) {
		if (!window.crypto || !crypto.subtle || file.size > 260 * 1024 * 1024) {
			return Promise.resolve('');
		}

		return file.slice(0, 8 * 1024 * 1024).arrayBuffer().then(function (buffer) {
			return crypto.subtle.digest('SHA-256', buffer);
		}).then(function (digest) {
			var bytes = new Uint8Array(digest);
			var out = '';
			for (var i = 0; i < bytes.length; i++) {
				out += ('0' + bytes[i].toString(16)).slice(-2);
			}
			return out;
		}).catch(function () { return ''; });
	};

	Uploader.prototype.sendChunk = function (handle, index, blob) {
		var self = this;

		return new Promise(function (resolve, reject) {
			var form = new FormData();
			form.append('handle', handle);
			form.append('chunk_index', String(index));
			form.append('finalize', '0');
			form.append('chunk', blob, 'part-' + index);

			var xhr = new XMLHttpRequest();
			self.xhr = xhr;

			xhr.open('POST', self.api.url('/uploads/' + encodeURIComponent(handle) + '/chunk'), true);
			xhr.withCredentials = true;
			xhr.setRequestHeader('X-WP-Nonce', self.api.config.nonce || '');
			xhr.responseType = 'text';

			xhr.upload.onprogress = function (event) {
				if (event.lengthComputable && self.options.onChunkProgress) {
					self.options.onChunkProgress(index, event.loaded, event.total);
				}
			};

			xhr.onload = function () {
				self.xhr = null;
				var data = null;

				try { data = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch (e) { data = null; }

				if (xhr.status >= 200 && xhr.status < 300 && data) {
					var payload = (data.success === true && data.data) ? data.data : data;
					resolve(payload);
					return;
				}

				var error = new Error((data && data.message) ? data.message : ('Chunk rejected with HTTP ' + xhr.status));
				error.status = xhr.status;
				error.code = data && data.code ? data.code : 'chunk_failed';
				error.data = (data && data.data) ? data.data : {};
				reject(error);
			};

			xhr.onerror = function () {
				self.xhr = null;
				var error = new Error('network');
				error.transient = true;
				reject(error);
			};

			xhr.onabort = function () {
				self.xhr = null;
				var error = new Error('aborted');
				error.aborted = true;
				reject(error);
			};

			xhr.send(form);
		});
	};

	/**
	 * Upload a whole file. Resolves with { sourceRef, name, size }.
	 */
	Uploader.prototype.upload = function (file) {
		var self = this;
		var chunkSize = Math.max(262144, Number(self.api.config.chunkSize) || 5242880);
		var started = Date.now();
		var sentBytes = 0;
		var received = [];
		var index = 0;

		return this.fingerprint(file).then(function (hash) {
			return self.api.post('/uploads', {
				name: file.name || 'video.mp4',
				size: file.size,
				chunk_size: chunkSize,
				hash: hash
			});
		}).then(function (session) {
			if (!session || !session.handle) {
				throw new Error('The server did not open an upload session.');
			}

			var handle = session.handle;
			var total = Number(session.chunkTotal) || Math.ceil(file.size / chunkSize);

			chunkSize = Number(session.chunkSize) || chunkSize;
			received = (session.received || []).map(Number);

			if (session.resume && self.options.onNote) {
				self.options.onNote('resume');
			}

			if (session.finalized && session.finalized.sourceRef) {
				// Instant finish: the server already had every byte.
				return session.finalized;
			}

			index = received.length ? Math.max.apply(null, received) + 1 : 0;

			function next() {
				if (self.cancelled) {
					var abortError = new Error('aborted');
					abortError.aborted = true;
					return Promise.reject(abortError);
				}

				if (index >= total) { return Promise.resolve(); }

				// Skip chunks the server already has (resume path).
				if (received.indexOf(index) !== -1) {
					index++;
					return next();
				}

				var start = index * chunkSize;
				var end = Math.min(file.size, start + chunkSize);
				var blob = file.slice(start, end);

				return self.sendChunk(handle, index, blob).then(function (result) {
					received.push(index);
					sentBytes += (end - start);
					index++;
					self.retries = 0;

					if (self.options.onProgress) {
						var elapsed = (Date.now() - started) / 1000;
						var speed = elapsed > 0 ? sentBytes / elapsed : 0;
						var remaining = Math.max(0, file.size - sentBytes);

						self.options.onProgress({
							sent: sentBytes,
							total: file.size,
							percent: file.size ? Math.min(99, Math.round((sentBytes / file.size) * 100)) : 0,
							chunk: index,
							chunks: total,
							speed: speed,
							eta: speed > 0 ? remaining / speed : 0
						});
					}

					return next();
				}).catch(function (error) {
					if (error.aborted || self.cancelled) { throw error; }

					// Transient network problems: back off and retry the same chunk.
					if (self.retries < 4 && (error.transient || error.status === 429 || error.status >= 500)) {
						self.retries++;
						return wait(Math.min(12000, 700 * Math.pow(2, self.retries))).then(next);
					}

					throw error;
				});
			}

			return next().then(function () {
				if (self.options.onProgress) {
					self.options.onProgress({ sent: file.size, total: file.size, percent: 100, chunk: total, chunks: total, speed: 0, eta: 0, verifying: true });
				}

				return self.api.post('/uploads/' + encodeURIComponent(handle) + '/complete', {});
			});
		});
	};

	/**
	 * Application ------------------------------------------------------------
	 */
	function App(root) {
		this.root = root;
		this.config = this.readConfig();
		this.api = new Api(this.config);
		this.file = null;
		this.sourceRef = '';
		this.sourceInfo = null;
		this.job = null;
		this.poll = null;
		this.uploader = new Uploader(this.api, {
			onProgress: this.renderUploadProgress.bind(this),
			onNote: this.note.bind(this)
		});

		this.pickChips();
		this.bind();
		this.renderDefaults();
		this.syncEnabled();
	}

	App.prototype.readConfig = function () {
		var raw = this.root.getAttribute('data-config');

		if (raw) {
			try { return JSON.parse(raw); } catch (e) { /* fall through */ }
		}

		return (window.VVAIConfig && typeof window.VVAIConfig === 'object') ? window.VVAIConfig : {};
	};

	App.prototype.str = function (key, fallback) {
		var strings = this.config.strings || {};
		return strings[key] != null ? strings[key] : (fallback || '');
	};

	App.prototype.el = function (name) {
		return qs(this.root, '[data-vvai-' + name + ']');
	};

	/**
	 * Compact controls (clips / shape / quality / framing) are button rows.
	 */
	App.prototype.pickChips = function () {
		var defaults = this.config.defaults || {};

		this.getAspect = chipGroup(this.root, '[data-vvai-aspect-group]', 'vvai-aspect', defaults.aspect);
		this.getQuality = chipGroup(this.root, '[data-vvai-quality-group]', 'vvai-quality', defaults.quality);
		this.getCrop = chipGroup(this.root, '[data-vvai-crop-group]', 'vvai-crop', defaults.cropMode);
		this.clipCount = Math.max(1, Math.min(5, parseInt(defaults.targetClips, 10) || 3));

		var countGetter = chipGroup(this.root, '[data-vvai-clips-group]', 'vvai-clip-count', this.clipCount);

		this.getCount = function () {
			var value = parseInt(countGetter(), 10);

			return isNaN(value) ? 3 : value;
		};
	};

	App.prototype.bind = function () {
		var self = this;
		var drop = this.el('drop');
		var file = this.el('file');

		if (file) {
			file.addEventListener('change', function () {
				if (file.files && file.files[0]) { self.pickFile(file.files[0]); }
			});
		}

		if (drop) {
			drop.addEventListener('click', function () { if (file) { file.click(); } });
			drop.addEventListener('keydown', function (event) {
				if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); if (file) { file.click(); } }
			});

			['dragenter', 'dragover'].forEach(function (type) {
				drop.addEventListener(type, function (event) {
					event.preventDefault();
					drop.classList.add('is-dragging');
				});
			});

			['dragleave', 'drop'].forEach(function (type) {
				drop.addEventListener(type, function (event) {
					event.preventDefault();
					drop.classList.remove('is-dragging');
				});
			});

			drop.addEventListener('drop', function (event) {
				var files = event.dataTransfer && event.dataTransfer.files;

				if (files && files[0]) { self.pickFile(files[0]); }
			});
		}

		var urlButton = this.el('url-fetch');

		if (urlButton) {
			urlButton.addEventListener('click', function () { self.importUrl(); });
		}

		var mediaButton = this.el('media-pick');

		if (mediaButton) {
			mediaButton.addEventListener('click', function () { self.pickMedia(); });
		}

		var clear = this.el('source-clear');

		if (clear) {
			clear.addEventListener('click', function () { self.clearSource(); });
		}

		var generate = this.el('generate');

		if (generate) {
			generate.addEventListener('click', function () { self.generate(); });
		}

		var cancel = this.el('cancel');

		if (cancel) {
			cancel.addEventListener('click', function () { self.cancelJob(); });
		}

		var retry = this.el('retry');

		if (retry) {
			retry.addEventListener('click', function () { self.retryJob(); });
		}

		qsa(this.root, '[data-vvai-length]').forEach(function (input) {
			input.addEventListener('change', function () { self.toggleCustomLength(); });
		});

		var focus = this.el('focus');

		if (focus) {
			focus.addEventListener('change', function () {
				var custom = self.el('custom-focus');
				if (custom) { custom.hidden = focus.value !== 'custom'; }
			});
		}

		this.toggleCustomLength();
	};

	App.prototype.renderDefaults = function () {
		var defaults = this.config.defaults || {};

		var min = this.el('min-duration');
		var max = this.el('max-duration');

		if (min && defaults.minDuration) { min.value = defaults.minDuration; }
		if (max && defaults.maxDuration) { max.value = defaults.maxDuration; }
	};

	App.prototype.toggleCustomLength = function () {
		var length = qs(this.root, '[data-vvai-length]');
		var wrap = this.el('custom-length');

		if (wrap) {
			wrap.hidden = !(length && length.value === 'custom');
		}
	};

	App.prototype.setBusy = function (busy, label) {
		var button = this.el('generate');

		if (!button) { return; }

		button.disabled = !!busy;
		button.classList.toggle('is-busy', !!busy);

		if (label) { button.textContent = label; }
		else if (!busy) { button.textContent = this.originalLabel || button.textContent; }
		else { this.originalLabel = this.originalLabel || button.textContent; button.textContent = this.str('generating', 'Working…'); }
	};

	/**
	 * Media Library picker.
	 *
	 * Uses the core wp.media frame when the site loaded it; otherwise asks for the
	 * attachment id, because /sources/media only needs that. Both paths end up in
	 * the same server-side import (which sniffs the container, as always).
	 */
	App.prototype.pickMedia = function () {
		var self = this;
		var field = this.el('media-search');

		function accept(attachment) {
			var id = attachment && attachment.id ? attachment.id : attachment;

			if (!id) { return; }

			if (field) { field.value = 'Attachment #' + id; }

			self.errorClear();
			self.importMedia(id);
		}

		if (window.wp && window.wp.media) {
			var frame = window.wp.media({
				title: this.str('mediaTitle', 'Pick a video'),
				library: { type: 'video' },
				multiple: false,
				button: { text: this.str('mediaUse', 'Use this video') }
			});

			frame.on('select', function () {
				var model = frame.state().get('selection').first();

				if (!model) { return; }

				var json = model.toJSON();

				if (json.type !== 'video' && !(json.subtype && json.subtype === 'mp4')) {
					self.error(self.str('mediaNotVideo', 'Pick a video file from the library.'));
					return;
				}

				accept(json.id);
			});

			frame.open();
			return;
		}

		var answer = window.prompt(this.str('mediaPrompt', 'Enter the Media Library attachment id of a video:'), '');

		if (answer && /^[0-9]+$/.test(String(answer).trim())) {
			accept(parseInt(String(answer).trim(), 10));
		}
	};

	/**
	 * Import a Media Library attachment as the job source.
	 */
	App.prototype.importMedia = function (attachmentId) {
		var self = this;
		var button = this.el('media-pick');

		if (button) { button.disabled = true; }

		this.errorClear();

		this.api.post('/sources/media', { attachment_id: attachmentId }).then(function (result) {
			self.sourceRef = result.sourceRef;
			self.sourceInfo = result;
			self.file = null;

			self.showSource({ name: result.name, size: result.size });

			var box = self.el('upload');

			if (box) {
				box.hidden = false;
				var label = qs(box, '[data-vvai-upload-label]');
				if (label) { label.textContent = self.str('done', 'File ready'); }
				var bar = qs(box, '[data-vvai-upload-bar]');
				if (bar) { bar.style.width = '100%'; }
				var pct = qs(box, '[data-vvai-upload-pct]');
				if (pct) { pct.textContent = '100%'; }
				var bytes = qs(box, '[data-vvai-upload-bytes]');
				if (bytes) { bytes.textContent = human(result.size); }
			}

			self.syncEnabled();

			if (self.config.autoStart) { self.generate(); }
		}).catch(function (error) {
			self.error((error && error.message) ? error.message : 'The media library item could not be read.');
		}).then(function () {
			if (button) { button.disabled = false; }
		});
	};

	App.prototype.error = function (message, hint) {
		var box = this.el('error');

		if (!box) {
			window.alert(message + (hint ? '\n\n' + hint : ''));
			return;
		}

		box.hidden = false;
		qs(box, '[data-vvai-error-message]').textContent = message;

		var hintNode = qs(box, '[data-vvai-error-hint]');
		hintNode.textContent = hint || '';
		hintNode.hidden = !hint;

		this.setBusy(false);
	}
	;

	App.prototype.note = function (kind) {
		if (kind === 'resume') {
			var hint = this.el('hint');
			if (hint) { hint.textContent = this.str('resumePrompt', 'Resuming a previous upload of this file.'); }
		}
	};

	App.prototype.pickFile = function (file) {
		var allowed = (this.config.allowedExtensions || []).map(function (e) { return String(e).toLowerCase(); });
		var extension = (file.name || '').split('.').pop().toLowerCase();
		var maxBytes = Number(this.config.maxUploadBytes) || 0;

		this.hideResults();
		qs(this.root, '.vvai-error') && (qs(this.root, '.vvai-error').hidden = true);

		if (allowed.length && allowed.indexOf(extension) === -1) {
			this.error(this.str('uploadFailed', 'Unsupported file type.') + ' ' + allowed.join(', '));
			return;
		}

		// maxUploadBytes is 0 when the site sets no cap: the server decides, and
		// because chunks are small the total size is not limited by PHP settings.
		if (maxBytes > 0 && file.size > maxBytes) {
			this.error(this.str('uploadTooLarge', 'That file is larger than this site allows.') + ' (' + human(maxBytes) + ')');
			return;
		}

		this.file = file;
		this.showSource({ name: file.name, size: file.size, uploading: true });
		this.upload();
	};

	App.prototype.showSource = function (info) {
		var box = this.el('source');

		if (!box) { return; }

		box.hidden = false;
		qs(box, '[data-vvai-source-name]').textContent = info.name || '';
		qs(box, '[data-vvai-source-size]').textContent = info.size ? human(info.size) : '';
	};

	App.prototype.clearSource = function () {
		this.uploader.abort();
		this.file = null;
		this.sourceRef = '';
		this.sourceInfo = null;

		var box = this.el('source');
		if (box) { box.hidden = true; }

		var progress = this.el('upload');
		if (progress) { progress.hidden = true; }

		var file = this.el('file');
		if (file) { file.value = ''; }

		this.syncEnabled();
	};

	App.prototype.renderUploadProgress = function (state) {
		var box = this.el('upload');

		if (!box) { return; }

		box.hidden = false;

		var percent = Math.max(0, Math.min(100, Number(state.percent) || 0));

		qs(box, '[data-vvai-upload-pct]').textContent = percent + '%';
		qs(box, '[data-vvai-upload-bar]').style.width = percent + '%';
		qs(box, '[data-vvai-upload-bytes]').textContent = human(state.sent) + ' / ' + human(state.total);

		var label = qs(box, '[data-vvai-upload-label]');

		if (state.verifying) {
			label.textContent = this.str('verifying', 'Verifying the file on the server…');
			qs(box, '[data-vvai-upload-speed]').textContent = '';
			qs(box, '[data-vvai-upload-eta]').textContent = '';
			return;
		}

		label.textContent = this.str('uploading', 'Uploading') + ' · ' + (state.chunk || 0) + '/' + (state.chunks || '?');
		qs(box, '[data-vvai-upload-speed]').textContent = state.speed ? human(state.speed) + '/s' : '';
		qs(box, '[data-vvai-upload-eta]').textContent = state.eta ? clock(state.eta) : '';
	};

	App.prototype.upload = function () {
		var self = this;

		this.setBusy(true, this.str('uploading', 'Uploading') + '…');
		this.errorClear();

		this.uploader.upload(this.file).then(function (result) {
			if (!result || !result.sourceRef) {
				throw new Error('The upload finished but the server did not confirm the file.');
			}

			self.sourceRef = result.sourceRef;
			self.sourceInfo = result;

			self.renderUploadProgress({ sent: result.size, total: result.size, percent: 100 });
			self.showSource({ name: result.name, size: result.size });

			var box = self.el('upload');
			if (box) { qs(box, '[data-vvai-upload-label]').textContent = self.str('done', 'File ready'); }

			self.setBusy(false);
			self.syncEnabled();

			if (self.config.autoStart) { self.generate(); }
		}).catch(function (error) {
			self.setBusy(false);

			if (error && error.aborted) { return; }

			self.renderUploadProgress({ sent: 0, total: (self.file && self.file.size) || 0, percent: 0 });
			self.error((error && error.message) ? error.message : self.str('uploadFailed', 'The upload failed.'));
			self.syncEnabled();
		});
	};

	App.prototype.errorClear = function () {
		var box = this.el('error');
		if (box) { box.hidden = true; }
	};

	App.prototype.hideResults = function () {
		var results = this.el('results');

		if (results) {
			results.hidden = true;
			qs(results, '[data-vvai-results-grid]').innerHTML = '';
		}
	};

	/**
	 * The payload for POST /jobs.
	 *
	 * Controls that the (deliberately) minimal UI does not render are omitted
	 * entirely rather than sent as false/0, so the server keeps its own defaults —
	 * e.g. captions stay enabled for .srt when the advanced panel is hidden.
	 */
	App.prototype.options = function () {
		var length = qs(this.root, '[data-vvai-length]');
		var focus = qs(this.root, '[data-vvai-focus]');
		var quality = this.el('quality');
		var min = this.el('min-duration');
		var max = this.el('max-duration');
		var burn = this.el('burn');
		var srt = this.el('srt');
		var connection = this.el('connection');

		var options = {
			target_clips: this.getCount ? this.getCount() : 3,
			aspect_ratio: this.getAspect ? this.getAspect() : '9:16',
			clip_length: length ? length.value : 'short'
		};

		if (focus) {
			options.focus = focus.value;
			options.custom_focus = focus.value === 'custom' ? ((qs(this.root, '[data-vvai-custom-focus]') || {}).value || '') : '';
		}

		if (this.getQuality) { options.quality = this.getQuality(); }
		if (this.getCrop) { options.crop_mode = this.getCrop(); }

		if (min && max) {
			options.min_duration = parseInt(min.value, 10) || 0;
			options.max_duration = parseInt(max.value, 10) || 0;
		}

		// Only send the caption switches when the visitor can actually see them.
		if (burn) { options.burn_captions = !!burn.checked; }
		if (srt) { options.generate_srt = !!srt.checked; }
		if (connection) { options.connection = connection.value || ''; }

		// A <select data-vvai-quality> from an older/custom template still works.
		if (!options.quality && quality) { options.quality = quality.value; }

		return options;
	};

	App.prototype.importUrl = function () {
		var input = this.el('url');
		var button = this.el('url-fetch');
		var self = this;

		if (!input || !input.value) { return; }

		this.errorClear();
		button.disabled = true;
		button.textContent = this.str('processing', 'Importing') + '…';

		this.api.post('/sources/url', { url: input.value.trim() }).then(function (result) {
			self.sourceRef = result.sourceRef;
			self.sourceInfo = result;
			self.file = null;
			self.showSource({ name: result.name, size: result.size });

			var box = self.el('upload');
			if (box) {
				box.hidden = false;
				qs(box, '[data-vvai-upload-label]').textContent = self.str('done', 'File ready');
				qs(box, '[data-vvai-upload-bar]').style.width = '100%';
				qs(box, '[data-vvai-upload-pct]').textContent = '100%';
				qs(box, '[data-vvai-upload-bytes]').textContent = human(result.size);
			}

			self.syncEnabled();
		}).catch(function (error) {
			self.error((error && error.message) ? error.message : 'The URL could not be imported.');
		}).then(function () {
			button.disabled = false;
			button.textContent = self.str('import', 'Import');
		});
	};

	App.prototype.syncEnabled = function () {
		var button = this.el('generate');

		if (!button) { return; }

		var blocked = !this.sourceRef
			|| (this.config.requireLogin && !this.config.loggedIn)
			|| (this.config.ready === false)
			|| (this.config.hasConnection === false && !this.options().connection);

		button.disabled = blocked;
		button.classList.toggle('is-disabled', blocked);

		var hint = this.el('hint');

		if (hint && !hint.hasAttribute('data-vvai-static')) {
			if (this.config.ready === false) {
				hint.textContent = this.config.readyHint || this.str('engineDown', 'Video processing is not configured on this site yet.');
			} else if (this.config.requireLogin && !this.config.loggedIn) {
				hint.textContent = this.str('loginRequired', 'Please log in to generate clips.');
			} else if (this.config.hasConnection === false && !this.config.connectionError) {
				hint.textContent = this.str('noConnection', 'Please connect an AI provider first.');
			} else if (!this.sourceRef) {
				hint.textContent = this.str('chooseVideo', 'Add a video to begin.');
			} else {
				hint.textContent = this.config.showStages === false ? '' : this.str('processing', 'Processing runs in the background.');
			}
		}
	};

	App.prototype.generate = function () {
		var self = this;

		if (this.config.ready === false) {
			this.error(
				this.config.readyMessage || this.str('engineDown', 'Video processing is not configured on this site yet.'),
				this.config.readyHint || ''
			);
			return;
		}

		if (!this.sourceRef) {
			this.error(this.str('chooseVideo', 'Choose a video first.'));
			return;
		}

		this.errorClear();
		this.hideResults();
		this.stopPolling();

		var status = this.el('status');

		if (status) {
			status.hidden = false;
			qs(status, '[data-vvai-status-title]').textContent = this.str('processing', 'Processing');
			qs(status, '[data-vvai-status-pct]').textContent = '0%';
			qs(status, '[data-vvai-status-bar]').style.width = '0%';
			qs(status, '[data-vvai-status-stage]').textContent = this.str('starting', 'Starting…');
			this.renderStages('queued');
		}

		this.setBusy(true, this.str('generating', 'Working…'));

		this.api.post('/jobs', this.optionsPayload()).then(function (result) {
			var job = result && result.job ? result.job : result;

			self.job = job;
			self.setBusy(false);
			self.startPolling();
		}).catch(function (error) {
			if (status) { status.hidden = true; }
			self.setBusy(false);
			self.error(
				(error && error.message) ? error.message : self.str('jobError', 'The server rejected this job.'),
				(error && error.data && error.data.hint) ? error.data.hint : ''
			);
		});
	};

	App.prototype.optionsPayload = function () {
		var payload = this.options();
		payload.source_ref = this.sourceRef;
		return payload;
	};

	App.prototype.startPolling = function () {
		var self = this;

		this.stopPolling();

		this.pollTick = function () {
			if (!self.job) { return Promise.resolve(); }

			return self.api.get('/jobs/' + self.job.id + '/status').then(function (status) {
				self.applyStatus(status);
			}).catch(function (error) {
				// A dropped poll is not a failed job: keep the last known state and
				// try again, but tell the user something is off.
				var box = self.el('status');

				if (box) {
					qs(box, '[data-vvai-status-stage]').textContent = self.str('pollFailed', 'Lost contact with the server — retrying…');
				}

				if (error && (error.status === 403 || error.status === 404)) {
					self.stopPolling();
					self.error(self.str('pollFailed', 'This job is no longer available.'));
				}
			});
		};

		this.pollTick();

		var interval = Math.max(800, Number(this.config.pollInterval) || 1500);

		this.poll = setInterval(function () { self.pollTick(); }, interval);
	};

	App.prototype.stopPolling = function () {
		if (this.poll) {
			clearInterval(this.poll);
			this.poll = null;
		}
	};

	App.prototype.applyStatus = function (status) {
		if (!status) { return; }

		this.job = Object.assign({}, this.job || {}, status);

		var box = this.el('status');
		var percent = Math.max(0, Math.min(100, Number(status.progress) || 0));

		if (box) {
			box.hidden = false;
			qs(box, '[data-vvai-status-pct]').textContent = percent + '%';
			qs(box, '[data-vvai-status-bar]').style.width = percent + '%';
			qs(box, '[data-vvai-status-stage]').textContent = status.stageLabel || status.stage || '';
			this.renderStages(status.stage);
		}

		if (status.status === 'completed') {
			this.stopPolling();
			if (box) { qs(box, '[data-vvai-status-title]').textContent = this.str('done', 'Your clips are ready'); }
			this.renderClips(status.clips || []);
			this.setBusy(false);
			return;
		}

		if (status.status === 'failed' || status.status === 'cancelled') {
			this.stopPolling();
			if (box) { box.hidden = true; }
			this.setBusy(false);

			var error = status.error || {};

			this.error(
				error.message || (status.status === 'cancelled' ? 'Cancelled.' : this.str('failed', 'Processing failed')),
				error.hint || ''
			);

			var open = qs(this.root, '[data-vvai-open-job]');

			if (open && status.id) {
				open.hidden = false;
				open.href = '#job-' + status.id;
				open.setAttribute('data-vvai-job-id', String(status.id));
			}

			return;
		}

		this.setBusy(true, this.str('processing', 'Processing') + ' ' + percent + '%');
	};

	App.prototype.renderStages = function (active) {
		var list = this.el('stages');

		if (!list) { return; }

		var order = ['queued', 'inspecting', 'extracting_audio', 'transcribing', 'analyzing', 'selecting_clips', 'rendering_clips', 'finalizing', 'completed'];
		var labels = this.config.stages || {};
		var position = order.indexOf(active);

		list.innerHTML = order.map(function (stage, index) {
			var cls = 'vvai-stage';

			if (position >= 0 && index < position) { cls += ' is-done'; }
			else if (index === position) { cls += ' is-active'; }

			return '<li class="' + cls + '"><span class="vvai-stage__dot" aria-hidden="true"></span>' + escapeHtml(labels[stage] || stage.replace(/_/g, ' ')) + '</li>';
		}).join('');
	};

	App.prototype.renderClips = function (clips) {
		var self = this;
		var results = this.el('results');
		var grid = results ? qs(results, '[data-vvai-results-grid]') : null;
		var template = this.el('clip-template');

		if (!grid) { return; }

		grid.innerHTML = '';

		var count = results ? qs(results, '[data-vvai-results-count]') : null;

		if (count) {
			count.textContent = clips.length + ' ' + this.str('clipsReady', 'clips');
		}

		if (results) { results.hidden = false; }

		if (!clips.length) {
			grid.innerHTML = '<p class="vvai-empty">' + escapeHtml(this.str('noClips', 'No clips were produced.')) + '</p>';
			return;
		}

		clips.forEach(function (clip) {
			var node;

			if (template && template.content && template.content.firstElementChild) {
				node = template.content.firstElementChild.cloneNode(true);
			} else {
				node = document.createElement('div');
			}

			node.setAttribute('data-clip-id', String(clip.id));

			var video = qs(node, '[data-vvai-clip-video]');
			if (video) {
				video.src = clip.previewUrl || '';
				video.poster = '';
			}

			var score = qs(node, '[data-vvai-clip-score]');
			if (score) {
				score.textContent = (clip.score || 0) + '/100';
				score.className = 'vvai-clip__score ' + (clip.score >= 80 ? 'is-hot' : (clip.score >= 60 ? 'is-warm' : 'is-cool'));
			}

			var number = qs(node, '[data-vvai-clip-number]');
			if (number) { number.textContent = '#' + (clip.number || 1); }

			var range = qs(node, '[data-vvai-clip-range]');
			if (range) { range.textContent = (clip.startLabel || clock(clip.start)) + ' → ' + (clip.endLabel || clock(clip.end)); }

			var duration = qs(node, '[data-vvai-clip-duration]');
			if (duration) { duration.textContent = (clip.durationLabel || clock(clip.duration)) + ' · ' + (clip.width || 0) + '×' + (clip.height || 0) + ' · ' + (clip.sizeLabel || human(clip.size)); }

			var title = qs(node, '[data-vvai-clip-title]');
			if (title) { title.textContent = clip.title || ''; }

			var reasoning = qs(node, '[data-vvai-clip-reasoning]');
			if (reasoning) { reasoning.textContent = clip.reasoning || ''; }

			var caption = qs(node, '[data-vvai-clip-caption]');
			if (caption) { caption.textContent = clip.caption || ''; }

			var tags = qs(node, '[data-vvai-clip-hashtags]');
			if (tags) { tags.textContent = clip.hashtagText || (clip.hashtags || []).join(' '); }

			var download = qs(node, '[data-vvai-clip-download]');
			if (download) { download.href = clip.downloadUrl || ''; download.setAttribute('download', clip.fileName || ('clip-' + (clip.number || 1) + '.mp4')); }

			var srt = qs(node, '[data-vvai-clip-srt]');
			if (srt) {
				if (clip.hasCaptions && clip.captionUrl) {
					srt.hidden = false;
					srt.href = clip.captionUrl;
					srt.setAttribute('download', 'clip-' + (clip.number || 1) + '.srt');
				} else {
					srt.hidden = true;
				}
			}

			var copyCaption = qs(node, '[data-vvai-clip-copy-caption]');
			if (copyCaption) {
				copyCaption.addEventListener('click', function () { self.copy(((clip.caption || '') + '\n\n' + (clip.hashtagText || '')).trim(), copyCaption); });
			}

			var copyTitle = qs(node, '[data-vvai-clip-copy-title]');
			if (copyTitle) { copyTitle.addEventListener('click', function () { self.copy(clip.title || '', copyTitle); }); }

			grid.appendChild(node);
		});
	};

	App.prototype.copy = function (text, button) {
		var self = this;
		var done = function () {
			if (!button) { return; }
			var previous = button.textContent;
			button.textContent = self.str('copied', 'Copied');
			setTimeout(function () { button.textContent = previous; }, 1400);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () { self.fallbackCopy(text); done(); });
			return;
		}

		this.fallbackCopy(text);
		done();
	};

	App.prototype.fallbackCopy = function (text) {
		var area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', 'readonly');
		area.style.position = 'fixed';
		area.style.left = '-9999px';
		document.body.appendChild(area);
		area.select();

		try { document.execCommand('copy'); } catch (e) { /* clipboard blocked */ }

		document.body.removeChild(area);
	};

	App.prototype.cancelJob = function () {
		var self = this;

		if (!this.job) {
			this.uploader.abort();
			this.setBusy(false);
			return;
		}

		this.api.post('/jobs/' + this.job.id + '/cancel', {}).then(function () {
			self.stopPolling();
			var status = self.el('status');
			if (status) { status.hidden = true; }
			self.setBusy(false);
			self.syncEnabled();
		}).catch(function (error) {
			self.error((error && error.message) ? error.message : 'The job could not be cancelled.');
		});
	};

	App.prototype.retryJob = function () {
		var self = this;

		if (!this.job) {
			if (this.sourceRef) { this.generate(); }
			return;
		}

		this.errorClear();
		this.setBusy(true, this.str('generating', 'Working…'));

		this.api.post('/jobs/' + this.job.id + '/retry', this.options().connection ? { connection: this.options().connection } : {}).then(function (result) {
			if (result && result.job) {
				self.job = result.job;
				self.applyStatus(result.job);
				self.startPolling();
			}
		}).catch(function (error) {
			self.setBusy(false);
			self.error((error && error.message) ? error.message : 'The job could not be restarted.');
		});
	};

	/**
	 * Boot every widget instance on the page.
	 */
	function boot() {
		qsa(document, '[data-vvai-app]').forEach(function (root) {
			if (root.__vvai) { return; }

			try {
				root.__vvai = new App(root);
			} catch (error) {
				// Never take the whole page down for one broken widget.
				if (window.console) { console.error('Viral Video AI', error); }
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	// Elementor renders widgets after load in the editor: re-scan.
	if (window.jQuery) {
		window.jQuery(document).on('elementor/frontend/init', function () {
			if (window.elementorFrontend && window.elementorFrontend.hooks) {
				window.elementorFrontend.hooks.addAction('frontend/element_ready/viral-video-ai.default', function () {
					boot();
				});
			}
		});
	}

	window.VVAIFrontend = { version: VERSION, boot: boot };
})();
