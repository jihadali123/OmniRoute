# Tests

These are real execution tests, not mocks of the plugin: every class in `includes/`
is loaded and run.

| Suite | Covers | Needs |
|---|---|---|
| `01-core.php` | autoloading, helpers/sanitizers, settings clamping, credential encryption + redaction, JSON hardening, transcript normalize/chunk/snap/SRT, clip validation (anti-hallucination, overlap, hostile text), FFmpeg plan building (crop/scale/no-upscale/rotation/subtitle path), ffprobe parsing, job state machine + progress honesty, locking, retry-from-stage, injection-safe queries, routing policy (fail-closed on auth, no connection at all) | nothing beyond PHP |
| `02-integration.php` | real connections verified over HTTP (success, 401, 402, 403, 404, 429, 503, DNS, timeout), provider request payloads, fallback policy, chunked+resumable uploads, container sniffing, URL import with SSRF refusal, the **full pipeline on a real video** (ffprobe → audio extract → transcribe → AI → validate → **real FFmpeg renders**, verified with ffprobe), burned-in captions, `.srt` sidecars, no-upscale clamping, download authorization (owner/stranger/signed token/tampered path/range requests), REST permission matrix, admin-ajax nonce + capability checks, retention and temp sweeping | `ffmpeg`, `ffprobe` |

| `03-lifecycle.php` | activation/deactivation idempotence, table creation and upgrades, capability grants and removal, uninstall with and without the opt-in, Elementor widget registration only after `elementor/loaded`, shortcode degradation | nothing beyond PHP |
| `04-ui-contract.php` | every `data-*` hook the frontend and admin scripts reach for exists in the templates/views (the class of bug that silently kills a widget), template escaping, chip groups, admin screen renders (including the Diagnostics video-engine panel), no secrets in markup, REST guard behaviour | nothing beyond PHP |

## Running

```bash
php tests/run-tests.php          # all four suites
php tests/run-tests.php --core   # unit suite only
```

On a machine without a PHP CLI (the sandbox this plugin was built in), use the
bundled WebAssembly runtime instead — it runs the same suites with a real
PHP 8.2 or 7.4:

```bash
cd tests/tools && npm install
node php.mjs lint.php ../..                       # parse + pitfall lint, all files
PHP_VERSION=7.4 node php.mjs lint.php ../..       # same gate against the PHP 7.4 minimum
VVAI_FFMPEG=/usr/bin/ffmpeg VVAI_BRIDGE=http://127.0.0.1:8799/exec \
  node php.mjs ../01-core.php
node php.mjs windows-fixes.php                    # Windows-specific process/path regressions
```

## The harness servers

`tests/harness/mock-ai.cjs` implements the wire format of OpenAI, Groq, OpenRouter,
Anthropic and Gemini (chat + model lists + `verbose_json` transcription) on
`127.0.0.1:8791`. It exists so the tests exercise the plugin's real HTTP client,
real auth failure handling and real JSON parsing without spending money or being
flaky. It is a test fixture: nothing in the plugin points at it in production, and
its "intelligence" is a keyword heuristic over the transcript the plugin itself
sent — the timestamps it returns are only ever taken from the prompt.

`tests/harness/exec-bridge.cjs` (port 8799) lets a PHP build without process
spawning (the WebAssembly runtime used for these runs) execute real FFmpeg by
handing it the plugin's own argv arrays through the documented
`vvai_process_runner` seam. Set `VVAI_NO_BRIDGE=1` to skip it on a normal CLI.

Set `VVAI_FFMPEG` / `VVAI_FFPROBE` when the binaries are not on `PATH`.

`tests/tools/build-zip.py` repackages the plugin into `viral-video-ai-x.y.z.zip` for the WordPress installer (edit the `REPO` constant to match your checkout).
