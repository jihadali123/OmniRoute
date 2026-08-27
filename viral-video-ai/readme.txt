=== Viral Video AI — AI-Powered Long Video to Viral Shorts Generator ===
Contributors: jihadali123
Tags: video, ai, ffmpeg, shorts, reels, tiktok, transcription, clips
Requires at least: 6.1
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.3
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
The plugin never installs binaries; it finds and uses what the server has.

**The short version (works on every platform, including Local by Flywheel on Windows):**

1. Install FFmpeg (see the per-OS commands below) — on Windows, extract a build to a short, permanent folder such as `C:\ffmpeg`, so that `C:\ffmpeg\bin\ffmpeg.exe` exists.
2. Open **Viral Video AI → Diagnostics** and press **Search this server for FFmpeg**.
3. Every candidate folder that contains both binaries is listed, and each binary is only offered after it has been *executed* and answered with a real version banner. Press **Use this folder** and the plugin saves that folder, re-probes, and reports the version.
4. Try it again — the widget's "not ready" notice disappears as soon as FFmpeg and FFprobe both respond.

Prefer to type it? Put the **folder** (not the `.exe`) in Viral Video AI → Settings → *FFmpeg folder*, e.g. `C:\ffmpeg\bin` or `/usr/local/bin`. `FFmpeg path` / `FFprobe path` remain available if you need to force one specific executable. Enabling *Look for FFmpeg in the usual install folders* (default) is what makes a normal install work without typing anything: a web server process frequently does **not** inherit the `PATH` you see in a terminal, which is the single most common reason "FFmpeg is installed but the plugin says it is not".

* Debian/Ubuntu: `sudo apt-get install ffmpeg`
* Alma/RHEL: `sudo dnf install --enablerepo=crb ffmpeg-free` (or RPM Fusion) then confirm `ffmpeg -version`
* Alpine: `apk add ffmpeg`
* Docker/`ffmpeg-static` builds: any build with `libx264` + `aac` works; `libass` is needed for burned-in captions
* Windows (XAMPP/WAMP/MAMP): download a release from `https://www.gyan.dev/ffmpeg/builds/` (e.g. `ffmpeg-release-essentials.zip`), extract it to a short path such as `C:\ffmpeg`, then either add `C:\ffmpeg\bin` to the system `PATH` and restart Apache/your site, or set the absolute paths in Viral Video AI → Settings:
  `C:\ffmpeg\bin\ffmpeg.exe` and `C:\ffmpeg\bin\ffprobe.exe`
* Local by Flywheel (Windows/macOS): Local's PHP cannot see your user `PATH` unless the app was restarted after changing it, so use the *FFmpeg folder* setting (or Diagnostics → Search this server → Use this folder) instead of relying on `PATH`. Local serves the site with nginx — see "Protecting rendered files" below.

If the binaries are not on `PATH`, set the folder (or the absolute paths) in **Viral Video AI → Settings → Server & rendering** and press "Re-check now" in Diagnostics. Common locations: `/usr/bin/ffmpeg`, `/usr/local/bin/ffmpeg`, `/opt/homebrew/bin/ffmpeg`, `C:\ffmpeg\bin`.

Verify from the shell that the web user can execute it: `sudo -u www-data ffmpeg -version`. On Windows, verify as the account running PHP — a build that needs its sibling DLLs will only start when `ffmpeg.exe`'s own folder is used (the plugin runs each binary from inside that folder for exactly this reason).

== Protecting rendered files ==

The plugin writes sources and clips under `wp-content/uploads/vvai/` and guards each folder with an `.htaccess` deny rule plus an `index.php`. Every download is *also* authorised in PHP, so a direct link is never required to watch or fetch a clip.

Apache and LiteSpeed honour the `.htaccess` rule automatically. **nginx ignores `.htaccess`** (Local by Flywheel, most managed nginx hosts), so deny it in the server config — for Local, edit the site's `conf/nginx.conf` (or add to the site root's `nginx.conf` include) and restart the site:

`
location ~* ^/wp-content/uploads/vvai/ {
    deny all;
    return 404;
}
`

On nginx hosts where you cannot edit config, either move the storage root out of the web root with the `vvai_storage_dir` filter (recommended for production anyway):

`
add_filter( 'vvai_storage_dir', function () {
	return '/var/www/vvai-data'; // outside any document root
} );
`

or set `allow_public_downloads` to off (the default) and accept that filenames under `/uploads/vvai/` are guessable only by someone who already knows a job id. With the storage root outside the web root, clips are reachable *only* through the plugin's authorising controller.

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

**"Fatal error: fclose(): supplied resource is not a valid stream resource" after activating (Windows/Local)** — fixed in 1.0.1; update the plugin. If it still appears on an older build, the host's PHP closes proc_open pipes eagerly and FFmpeg cannot be probed safely.

**Diagnostics says FFmpeg is not available** — the binary is missing, PHP is not allowed to start programs, or the path is wrong. The Diagnostics screen now states which of the three it is ("FFmpeg was not found for the web server process", "PHP is not allowed to run programs on this server", or an error quoting what your binary actually answered), lists the folders that were searched, and gives per-platform steps. Press **Search this server for FFmpeg** to find and apply a working folder in one click. From a shell, test with `which ffmpeg && ffmpeg -version`, then `sudo -u www-data ffmpeg -version`. On hosts that block `proc_open` and `exec`, no server-side rendering is possible: ask the host to allow it, or route rendering to a worker (`vvai_process_runner`).

**A visitor sees "This site cannot render clips yet"** — that is the plugin protecting them: with no working FFmpeg, uploads are refused *before* a single byte is transferred, instead of failing after a long upload and a paid API call. Fix FFmpeg on the server and the notice disappears on the next page view.

**I fixed the path but the plugin kept failing for a few minutes** — no longer true as of 1.0.3: saving any FFmpeg setting clears both the availability cache and the discovery cache, and the Diagnostics screen re-scans on every load.

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

= 1.0.3 =
* Added automatic FFmpeg discovery: when a bare `ffmpeg` is not on the web server's `PATH`, the plugin now searches `PATH`, the conventional install folders (`C:\ffmpeg\bin`, Program Files, chocolatey/scoop/winGet links, `/usr/local/bin`, `/opt/homebrew/bin`, `wp-content/bin`, the plugin's own `bin/`) and `which`/`where` before giving up — the usual reason a working local install looked "not available".
* Added an *FFmpeg folder* setting (one value for both binaries, Windows paths and pasted `.exe` paths accepted) plus **Diagnostics → Video engine** panel: per-binary configured vs. actually-used path, real version or the exact error, the folders searched, per-platform fix steps, a "Search this server for FFmpeg" button and one-click **Use this folder** apply.
* Added a pre-flight readiness gate: the widget shows "This site cannot render clips yet" with the administrator's fix steps *before* anyone uploads, and `POST /jobs` refuses with the same explanation plus a hint instead of failing after an upload and a paid AI call.
* Fixed FFmpeg settings appearing to be ignored: saving a binary path (or folder) now clears the availability cache and grants a fresh probe, and the Diagnostics screen drops the discovery cache on load.
* Fixed shared FFmpeg builds failing to start: every binary is now executed from its own folder with that folder prepended to the child `PATH` (missing sibling DLLs on Windows), and the environment is inherited explicitly.
* Hardened binary verification: a candidate is only ever saved as the renderer after its own `-version` banner identifies it as FFmpeg/FFprobe, so a renamed or unrelated executable cannot be adopted from a searched folder.
* Changed engine failures to report *why* (`not_found`, `path_invalid`, `php_exec_disabled`) with the exit code and first line of the program's own output when the binary exists but refuses to run.
* Tests: 766 assertions across four suites (+26 Windows-only regressions), including discovery in a synthetic bin folder, decoy-binary rejection, the folder sanitizer's Windows cases, cache invalidation on save, and the admin-only search/apply endpoints over REST and admin-ajax.

= 1.0.2 =
* Simplified the frontend and Elementor UI: one card instead of three numbered panels. A visitor now chooses only video → number of clips (1-5 chips) → shape → quality. Everything else (content focus, clip length preset, framing, captions, connection) is collapsed behind a single "Advanced options" disclosure and already defaults to good values, so one click is enough.
* Elementor panel trimmed to match: the clip-length, focus, custom-direction, min/max duration and framing controls were removed from the panel and fall back to the site defaults. Power users can still set every one of them via shortcode attributes (`[vvai_generator clip_length="medium" focus="dialogue" min_duration="90"]`), which the server keeps validating.
* Upload size limit removed by default: `max_upload_mb` is now `0` = no plugin cap. Because videos arrive in chunks, a 10 GB source works even when `upload_max_filesize` is 20M. An explicit cap still works and now explains itself; the PHP "no limit" values (0 / -1) are no longer read as tiny limits.
* Uploads no longer pre-allocate the full declared size on disk, and out-of-order or resumed chunks are handled instead of failing.
* No REST route can print a raw PHP fatal any more: an unexpected error becomes a readable JSON error in the widget plus a log line with file, line and handler name (Viral Video AI → Diagnostics → Recent log). The generic "There has been a critical error on this website" that the widget previously displayed verbatim is now diagnosable.
* Media Library became a default source (`upload,url,media`) using core's own picker, so hosts where chunked POSTs are awkward still have a working path.
* 04-ui-contract suite added: it fails the build if the JavaScript looks up a `data-vvai-*` hook the template does not render (the class of bug that makes a button silently do nothing), and pins the compact layout. 675 assertions across four suites, 0 failures.

= 1.0.1 =
* Fixed a fatal error on activation for Windows/Local by Flywheel: `fclose()` was called on FFmpeg pipes that `proc_close()` had already closed (PHP 8 raises TypeError for the dead resource). Pipe handling is now resource-guarded and the whole process call is exception-contained, so a host quirk reports a diagnostics row instead of white-screening wp-admin.
* Fixed FFmpeg/FFprobe paths on Windows being impossible to configure: the sanitizers rejected every backslash, so `C:\ffmpeg\bin\ffmpeg.exe` silently fell back to `ffmpeg`. Windows absolute paths (including spaces) are now accepted, while shell chaining, quotes, traversal and uploads-basedir binaries are still refused.
* Binary probing is no longer repeated on every admin page load: Diagnostics "Re-check now" opts into a single uncached probe, everything else uses the 5-minute cache (fewer spawned processes on shared hosting).
* Added "Protecting rendered files": nginx ignores `.htaccess`, so a deny snippet and the `vvai_storage_dir` option to store media outside the document root are documented.

= 1.0.0 =
* First production release: chunked resumable uploads, ffprobe inspection, chunked transcription with timestamped segments, provider-agnostic AI analysis (OpenAI, Gemini, Claude, Groq, OpenRouter, custom), strict JSON validation with timestamp snapping and overlap removal, FFmpeg clip rendering with smart vertical reframing and no-upscale clamping, SRT sidecars and optional burn-in, viral score/title/caption/hashtags per clip, secure ranged downloads, resumable background queue, per-stage retry, retention cleanup, diagnostics, Elementor widget and shortcode.
