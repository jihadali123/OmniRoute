=== Viral Video AI — AI-Powered Long Video to Viral Shorts Generator ===
Contributors: jihadali123
Tags: video, ai, ffmpeg, shorts, reels, tiktok, transcription, clips
Requires at least: 6.1
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn one long video into ready-to-post short clips: your server analyses the transcript with the AI you connect, picks the strongest moments with exact timestamps, then renders real MP4 clips with FFmpeg.

== Description ==

Viral Video AI is a production video pipeline, not a demo. The whole flow runs on your server:

1. A visitor uploads a long video (resumable, chunked upload) or points at a direct video URL / media library item.
2. FFprobe inspects the file: duration, dimensions, fps, codecs, audio, rotation.
3. FFmpeg extracts a mono 16 kHz audio track; long videos are split into windows automatically.
4. The audio is transcribed with timestamped segments (OpenAI Whisper, Groq Whisper, Gemini audio, any OpenAI-compatible endpoint, or a local Whisper binary).
5. The timestamped transcript is sent to the AI model you connected (OpenAI, Gemini, Claude, Groq, OpenRouter, or a custom gateway) with editorial instructions: hooks, emotion, surprise, conflict, insight, payoff, shareability.
6. The model returns strict JSON: start_time, end_time, viral score 1–100, reasoning, title, social caption, hashtags.
7. The server validates every timestamp against the real transcript and the real video duration, snaps cut points to sentence boundaries, removes overlaps, and rejects anything it cannot place on the timeline. A model that invents timestamps produces no clips — and says so.
8. FFmpeg renders each accepted moment into a real MP4 (9:16, 16:9, 1:1 or 4:5; 720p/1080p/4K) with smart centre-crop reframing, H.264 + AAC, faststart, and optional burned-in captions plus an .srt sidecar.
9. The visitor previews, copies the metadata and downloads the files. Progress is read from the server on every poll — percentages and "Completed" only ever reflect real work.

Nothing is simulated: no fake progress bars, no canned results, no "connection OK" without a provider that actually answered.

= Who it is for =
* Creators and agencies repurposing podcasts, webinars, interviews, lectures, gaming VODs and corporate footage.
* Site owners who want an AI video product on their own hosting, with their own API key and their own data.

= Built for extension =
Provider adapters share one interface, the AI call goes through a router, and FFmpeg calls are built as argv arrays in one place. Add-ons can register new providers, replace the crop engine (`vvai_crop_analysis`), extend the render plan (`vvai_render_plan`), route rendering to a worker (`vvai_process_runner`) or append prompts (`vvai_analysis_prompt`) without touching the core. The data model (jobs + clips tables, staged files, background queue) is already shaped for subtitles/word-level captions, speaker detection, face tracking, silence removal, thumbnails, S3/R2 storage and direct publishing.

== Installation ==

1. In WordPress go to Plugins → Add New → Upload Plugin, choose `viral-video-ai-x.y.z.zip`, then Activate.
2. Open **Viral Video AI → Diagnostics**. FFmpeg and FFprobe must both show 🟢. If not, see "FFmpeg setup" below.
3. Open **Viral Video AI → AI Connections**, pick a provider, paste the API key and press **Connect**. The status only turns 🟢 Connected after your server made a real authenticated request to the provider.
4. Choose that connection as **Active AI connection** (it is selected automatically when it is the only connected one).
5. Edit a page with Elementor and add the **Viral Video AI** widget — or paste the shortcode `[vvai_generator]` anywhere. Elementor is optional: the shortcode works on any theme.
6. Upload a video, pick duration/focus/aspect/quality, press **Generate Clips**.

= Requirements =
* WordPress 6.1+, PHP 7.4+ (8.1+ recommended), MySQL 5.7+/MariaDB 10.3+
* FFmpeg 4+ and FFprobe reachable from PHP (see below)
* `proc_open` or `exec` enabled (not listed in `disable_functions`)
* HTTPS + outbound HTTPS to your AI provider
* Free disk space: roughly 3× the source size while a job runs (source + audio + clips)
* `upload_max_filesize` / `post_max_size` at least as large as your biggest source (the plugin also accepts chunked uploads, so a modest `post_max_size` is fine — only the chunk size must fit it)
* Optional but recommended: [Action Scheduler](https://wordpress.org/plugins/action-scheduler/) (bundled with WooCommerce, also standalone) for proper background queues

= FFmpeg setup =
The plugin never installs binaries; it uses what the server has.

* Debian/Ubuntu: `sudo apt-get install ffmpeg`
* Alma/RHEL: `sudo dnf install --enablerepo=crb ffmpeg-free` (or RPM Fusion) then confirm `ffmpeg -version`
* Alpine: `apk add ffmpeg`
* Docker/`ffmpeg-static` builds: any build with `libx264` + `aac` works; `libass` is needed for burned-in captions
* Windows: download a static build, then point the settings at `C:\path\ffmpeg.exe` and `C:\path\ffprobe.exe`

If the binaries are not on `PATH`, set the absolute paths in **Viral Video AI → Settings → Server & rendering** and press "Re-check now" in Diagnostics. Common locations: `/usr/bin/ffmpeg`, `/usr/local/bin/ffmpeg`, `/opt/homebrew/bin/ffmpeg`.

Verify from the shell that the web user can execute it: `sudo -u www-data ffmpeg -version`.

== AI connections ==

Only three fields exist in the normal flow: provider, API key, Connect. Base URL, model, temperature and token limits live behind "Advanced" and already have sane per-provider defaults.

* **OpenAI** — chat completions + `whisper-1` transcription. JSON mode supported.
* **Google Gemini** — `generateContent`; huge context, `responseMimeType: application/json`, and audio can be transcribed by the model itself.
* **Anthropic Claude** — Messages API with `x-api-key` + `anthropic-version`.
* **Groq** — OpenAI-compatible, very fast, plus `whisper-large-v3-turbo` transcription.
* **OpenRouter** — one key for hundreds of models (including `*:free` variants); send `HTTP-Referer`/`X-Title` automatically.
* **Custom** — anything OpenAI-compatible (`/chat/completions`) or Anthropic-compatible (`/messages`): LiteLLM, vLLM, Ollama, llama.cpp server, Azure proxies.

You can store many connections (OpenAI — Main, OpenAI — Backup, Groq — Fast, …). Each one is verified, disconnected, deleted or reconnected independently. Exactly one is **Active**; a disconnected connection can never be used for processing. Enable "fallback" to retry a transient failure (timeout, DNS, 429, 5xx) on a second connection — authentication failures are never hidden by a fallback, because a bad key is your problem to fix, not the plugin's to paper over.

Free vs paid is not the plugin's business: if the provider accepts your key and serves the model, it works. Rate limits, quotas and model access stay enforced by the provider, and their exact error is surfaced (invalid key, forbidden, rate limit, quota exceeded, model unavailable, timeout, provider down).

= How keys are stored =
Keys are encrypted at rest (AES-256-CBC with an HMAC integrity check, derived from your WordPress salts; set `VVAI_ENCRYPTION_KEY` in `wp-config.php` if you move or clone the site and want stored keys to survive). They are decrypted in memory for the duration of one request, masked (`sk-•••••4f2a`) in every screen, never returned by any endpoint, and stripped from log lines. `Authorization` headers and request bodies are never written to the log.

== Usage ==

= Shortcodes =
* `[vvai_generator]` — the full upload → options → progress → results UI.
* `[vvai_generator clip_length="medium" focus="dialogue" aspect_ratio="16:9" quality="1080p" target_clips="4" button_text="Cut my clips"]`
* `[vvai_generator show_source="upload,url,media" show_advanced="yes"]`
* `[vvai_my_clips count="12"]` — the signed-in user's finished clips.

Accepted values: `clip_length` = short|medium|long|custom, `focus` = viral|action|dialogue|emotional|insight|custom, `aspect_ratio` = 9:16|16:9|1:1|4:5, `quality` = 720p|1080p|4k. Anything else is clamped, never trusted.

= Clip lengths =
Short 30–60 s, Medium 2–3 min, Long 4–5 min, Custom with a min/max you choose. The requested window is validated against the actual source: a video shorter than the minimum is refused with a clear message instead of producing a truncated clip, and long-requested windows are shrunk to what the source can supply.

= Quality & resolution =
The quality label is the target for the *short* side on vertical output and the height on landscape. Small sources are never upscaled: the clip renders at the source size and the result records that upscaling was prevented (visible in the job). 4K is only produced when the source can carry it.

= Framing =
9:16 output is produced by covering the target box then cropping the overflow. "Smart" framing runs FFmpeg's own `cropdetect` over sampled moments to find the real content box — so letterbox bars are removed and the crop window follows the action — with a hook (`vvai_crop_analysis`) for a face/person/speaker tracker to take over completely. "Centre crop" is available if you want deterministic framing.

== Security model ==

* Every mutation goes through REST (`vvai/v1`, `X-WP-Nonce`) or nonce-checked admin-ajax, then through capability checks (`manage_options` for admin, `upload_files`/`vvai_generate` for job creation) and an ownership check per job.
* Clip files live in `wp-content/uploads/vvai/jobs/job-{id}/`, protected by an `.htaccess` deny rule and an `index.php` guard. They are only ever served by a controller that re-resolves the path from the database row, verifies it is inside the plugin's own storage root and matches the deterministic expected filename, then streams with byte-range support. A tampered path value cannot be used to read anything else.
* Downloads are authorised per user; guests may only use a short-lived signed token (`vvai_token`) bound to one clip and one expiry — configurable lifetime, default 1 hour. `allow_public_downloads` exists for deliberately public sites and is off by default.
* Filenames are generated by the plugin (`clip-003.mp4`), never taken from user input; every user-supplied name is stripped of traversal and shell syntax.
* Upload chunks must arrive as PHP-staged files, are size-checked per chunk, and the assembled file is verified against the declared size plus the container's magic bytes before it is kept.
* All SQL uses `prepare()` with whitelisted column/table identifiers; sort columns and directions are matched against a literal allowlist.
* All AI output is treated as hostile input: markup stripped, hashtags validated, scores clamped, timestamps range-checked and snapped.
* Directories, paths and URLs from the client are never accepted as-is; private/loopback addresses are refused for custom endpoints (a filter allows them deliberately for internal gateways).

== Background processing ==

Jobs are never processed inside the request that created them. The queue prefers Action Scheduler when present, always fires a non-blocking loopback request so work starts within milliseconds, and a WP-Cron heartbeat every minute picks up anything left behind — including jobs whose PHP process was killed by a timeout or an OOM. Each worker run takes a database lock, does one bounded stage (default 25 s budget) and re-queues, so multi-gigabyte sources process fine on shared hosting.

Statuses: queued → uploading → uploaded → inspecting → extracting audio → transcribing → analysing → selecting clips → rendering clips → finalizing → completed (or failed). Failures record the reason and the stage, and **Retry** resumes from that stage without re-uploading and without re-transcribing a transcript that is already stored. Rendering is resumable per clip, so a crash mid-render only re-renders the missing files.

== Retention & cleanup ==
Daily housekeeping deletes scratch audio, orphaned upload sessions and clips older than your retention window; the job row survives as history so the log stays readable. Sources are kept for their own retention window (or deleted with the job). Every value is in Settings; "Run cleanup now" is available in Diagnostics.

== Troubleshooting ==

**Diagnostics says FFmpeg is not available** — the binary is missing, or `exec`/`proc_open` are in `disable_functions`, or the path is wrong. Test with `which ffmpeg && ffmpeg -version`, then `sudo -u www-data ffmpeg -version`. On hosts that block `proc_open`, no server-side rendering is possible: ask the host to allow it, or use a rendering worker (`vvai_process_runner`).

**"Connection failed — invalid API key"** — the key is wrong, revoked, or belongs to a different provider. Paste it again; check the provider's billing page. Free/trial keys can expire or be region-restricted.

**"Model unavailable"** — the key is valid but that model is not offered to the account. Use Advanced → Load models, pick one from the list.

**429 / quota exceeded** — the provider throttled you. Wait, lower "Maximum clips per job", shorten the transcription chunk, or select a connection with more quota.

**"No transcription engine is available"** — Claude and OpenRouter do not transcribe audio. Connect OpenAI or Groq (both transcribe), point "Custom transcription endpoint" at your own `/audio/transcriptions` service, or set a local Whisper binary path.

**Nothing found / "no clip candidate survived validation"** — the model returned timestamps that do not match the video, or the transcript is unusable (silent or music-only source). Retry with the Viral Moments focus, a wider length window, or a stronger model. This is the anti-hallucination guard working, not a silent failure.

**Upload stops at a percentage** — chunks resume automatically; if the server has a small `post_max_size`, lower "Upload chunk size" (e.g. 2 MB). The Diagnostics page shows the effective ceiling.

**Jobs sit in "Queued"** — WP-Cron is not firing (common on quiet sites or with `DISABLE_WP_CRON`). Install Action Scheduler, or add a real system cron for `wp-cron.php`. The Diagnostics page tells you which path is in use.

**Elementor widget missing** — Elementor must be installed and active; the plugin works fully without it via the shortcode, and says so in the admin notice.

**Clips look badly framed** — switch framing to Centre crop for a predictable result, or (recommended) keep Smart and check the job's recorded crop metadata.

== Developer notes ==

Key hooks: `vvai_provider_instance`, `vvai_register_providers`, `vvai_provider_payload`, `vvai_http_request_args`, `vvai_analysis_prompt`, `vvai_ranking_prompt`, `vvai_render_plan`, `vvai_crop_analysis`, `vvai_process_runner`, `vvai_whisper_cli_args`, `vvai_allow_private_endpoints`, `vvai_allow_clip_access`, `vvai_max_upload_bytes`, `vvai_storage_dir`, `vvai_template_candidates`, `vvai_job_created`, `vvai_job_completed`, `vvai_job_failed`, `vvai_job_dispatched`, `vvai_cleanup_report`.

Actions: `vvai_loaded`, `vvai/frontend_ready`.

Custom tables: `{prefix}vvai_jobs`, `{prefix}vvai_clips`, `{prefix}vvai_uploads`. Options: `vvai_settings`, `vvai_connections`. Constants: `VVAI_ENCRYPTION_KEY` (recommended on hosts that move/clone sites).

REST: `vvai/v1` — `/providers`, `/connections` (+`/{id}/connect|disconnect|activate|models`), `/uploads` (+`/{handle}/chunk|complete`), `/sources/url|media`, `/jobs` (+`/{id}/status|results|retry|start|cancel`), `/clips/{id}/file?mode=preview|download|captions`, `/diagnostics`, `/settings`, `/config`.

Tests: the plugin ships a standalone suite that runs against a real PHP CLI (`php tests/run-tests.php`) and, when the dev harness is present, drives real FFmpeg renders and a real local provider. See `tests/README.md`.

== Changelog ==

= 1.0.0 =
* First production release: chunked resumable uploads, ffprobe inspection, chunked transcription with timestamped segments, provider-agnostic AI analysis (OpenAI, Gemini, Claude, Groq, OpenRouter, custom), strict JSON validation with timestamp snapping and overlap removal, FFmpeg clip rendering with smart vertical reframing and no-upscale clamping, SRT sidecars and optional burn-in, viral score/title/caption/hashtags per clip, secure ranged downloads, resumable background queue, per-stage retry, retention cleanup, diagnostics, Elementor widget and shortcode.
