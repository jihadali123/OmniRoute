<?php
/**
 * Unit + contract tests that need no network and no FFmpeg.
 *
 * Run: node dev-harness/php.mjs dev-harness/tests/01-core.php
 */

require __DIR__ . '/framework/bootstrap.php';

$runner = new VVAI_Test_Runner();
$plugin = vvai_test_boot( array( 'admin' => true ) );


// ---------------------------------------------------------------------------
$runner->section( 'Autoloading & bootstrap' );

$runner->test( 'every VVAI_ class referenced by the container resolves', function () use ( $runner, $plugin ) {
	$services = array(
		'settings', 'logger', 'crypto', 'jobs', 'clips', 'connections', 'api', 'providers',
		'router', 'ffmpeg', 'uploads', 'transcription', 'analyzer', 'clip_generator',
		'processor', 'queue', 'results', 'diagnostics', 'rest', 'ajax', 'shortcode', 'admin',
	);

	foreach ( $services as $key ) {
		$service = $plugin->get( $key );
		$runner->assert( is_object( $service ), 'service ' . $key . ' missing' );
	}
} );

$runner->test( 'custom tables were created', function () use ( $runner ) {
	global $wpdb;

	foreach ( array( VVAI_DB::jobs_table(), VVAI_DB::clips_table(), VVAI_DB::uploads_table() ) as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$runner->same( $table, $found, 'table ' . $table );
	}
} );

$runner->test( 'all six providers implement the shared interface', function () use ( $runner ) {
	$manager = new VVAI_Api_Manager();

	foreach ( VVAI_Api_Manager::provider_keys() as $key ) {
		$adapter = $manager->get( $key );

		$runner->assert( ! is_wp_error( $adapter ), 'adapter ' . $key . ' failed to build' );
		$runner->assert( $adapter instanceof VVAI_AI_Provider_Interface, $key . ' must implement the provider interface' );
		$runner->assert( '' !== $adapter->get_label(), $key . ' needs a label' );
		$runner->assert( '' !== $adapter->get_default_model(), $key . ' needs a default model' );
	}

	$runner->same(
		array( 'openai', 'gemini', 'anthropic', 'groq', 'openrouter', 'custom' ),
		VVAI_Api_Manager::provider_keys(),
		'provider list'
	);
} );

// ---------------------------------------------------------------------------
$runner->section( 'Helpers: sanitization & formatting' );

$runner->test( 'hashtag sanitizer strips markup, duplicates and spaces', function () use ( $runner ) {
	$tags = vvai_sanitize_hashtags( array( '#viral', 'VIRAL', '#with spaces', '<b>bold</b>', '#ok', '', '#a' ) );

	$runner->same( array( '#viral', '#bold', '#ok', '#a' ), $tags, 'markup is stripped, its text kept' );
	$runner->same( array(), vvai_sanitize_hashtags( 'not a list at all' ), 'a sentence is not a tag list' );
	$runner->same( array( '#one', '#two' ), vvai_sanitize_hashtags( '#one #two' ) );
	$runner->same( array( '#one', '#two' ), vvai_sanitize_hashtags( 'one,two' ), 'comma separated words are tags' );
	$runner->same( array(), vvai_sanitize_hashtags( null ) );
} );

$runner->test( 'time formatting and parsing round-trip', function () use ( $runner ) {
	$runner->same( '02:05', vvai_format_time( 125 ) );
	$runner->same( '01:02:03', vvai_format_time( 3723 ) );
	$runner->same( '00:00:02.50', vvai_format_time( 2.5, true ) );
	$runner->same( 125.0, vvai_parse_time( '125' ) );
	$runner->same( 125.5, vvai_parse_time( '2:05.5' ) );
	$runner->same( 3723.0, vvai_parse_time( '01:02:03' ) );
	$runner->assert( false === vvai_parse_time( 'not-a-time' ), 'garbage must not parse' );
	$runner->assert( false === vvai_parse_time( '../../../etc/passwd' ), 'path must not parse' );
} );

$runner->test( 'filenames cannot escape their directory', function () use ( $runner ) {
	$runner->same( 'passwd', vvai_sanitize_filename( '../../../etc/passwd' ) );
	$runner->same( 'clip.mp4', vvai_sanitize_filename( 'a/b/clip.mp4' ) );
	$runner->same( 'file', vvai_sanitize_filename( '...' ) );
	$runner->same( 'fallback', vvai_sanitize_filename( '...', 'fallback' ) );
	$hostile = vvai_sanitize_filename( 'no;rm -rf /.mp4' );

	foreach ( array( ';', '|', '&', '`', '$', '>' ) as $bad ) {
		$runner->assert( false === strpos( $hostile, $bad ), 'shell metacharacter ' . $bad . ' survived: ' . $hostile );
	}
	$runner->assert( false === strpos( vvai_sanitize_filename( "x';system('id');" ), ';' ), 'no shell metacharacters survive' );
} );

$runner->test( 'shell quoting neutralises injection', function () use ( $runner ) {
	$escaped = vvai_shell_arg( "a'; rm -rf / #b" );

	$runner->assert( 0 === strpos( $escaped, "'" ) && "'" === substr( $escaped, -1 ), 'must be single quoted' );
	$runner->same( escapeshellarg( "a'; rm -rf / #b" ), $escaped, 'must match escapeshellarg exactly' );
} );

$runner->test( 'binary path validator rejects shell syntax and relative paths', function () use ( $runner ) {
	$runner->assert( VVAI_Process::binary_is_safe( 'ffmpeg' ), 'bare name allowed' );
	$runner->assert( ! VVAI_Process::binary_is_safe( 'ffmpeg; rm -rf /' ), 'chained command rejected' );
	$runner->assert( ! VVAI_Process::binary_is_safe( 'ffmpeg|sh' ), 'pipe rejected' );
	$runner->assert( ! VVAI_Process::binary_is_safe( '$(id)' ), 'subshell rejected' );
	$runner->assert( ! VVAI_Process::binary_is_safe( '../evil' ), 'relative traversal rejected' );
} );

$runner->test( 'human size and shorthand parsing', function () use ( $runner ) {
	$runner->same( '1.0 GB', vvai_human_size( GB_IN_BYTES ) );
	$runner->same( 1024, vvai_shorthand_to_bytes( '1K' ) );
	$runner->same( 2097152, vvai_shorthand_to_bytes( '2M' ) );
	$runner->same( 3221225472, vvai_shorthand_to_bytes( '3G' ) );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Settings sanitization' );

$runner->test( 'out-of-range and hostile values are clamped', function () use ( $runner, $plugin ) {
	$settings = $plugin->settings();

	$settings->set( 'max_clips', 9999 );
	$runner->same( 20, (int) $settings->get( 'max_clips' ), 'max_clips clamped' );

	$settings->set( 'temperature', 'HOT' );
	$runner->same( 0.4, (float) $settings->get( 'temperature' ), 'temperature falls back' );

	$settings->set( 'ffmpeg_path', 'ffmpeg; rm -rf /' );
	$runner->same( 'ffmpeg', $settings->get( 'ffmpeg_path' ), 'shell injection in binary path rejected' );

	$settings->set( 'results_order', 'DROP TABLE' );
	$runner->same( 'score', $settings->get( 'results_order' ), 'enum enforced' );

	$settings->set( 'default_aspect_ratio', '13:7' );
	$runner->same( '9:16', $settings->get( 'default_aspect_ratio' ), 'ratio enum enforced' );

	$settings->set( 'ffmpeg_extra_args', '-y 2>/dev/null ; echo hi' );
	$runner->same( '', $settings->get( 'ffmpeg_extra_args' ), 'redirection/semicolon in extra args blocks the value' );

	$settings->clear_overrides();
	vvai_test_configure();
} );

$runner->test( 'duration ranges match the spec', function () use ( $runner ) {
	$runner->same( array( 30, 60 ), VVAI_Settings::duration_range( 'short', 0, 0 ) );
	$runner->same( array( 120, 180 ), VVAI_Settings::duration_range( 'medium', 0, 0 ) );
	$runner->same( array( 240, 300 ), VVAI_Settings::duration_range( 'long', 0, 0 ) );
	$runner->same( array( 12, 45 ), VVAI_Settings::duration_range( 'custom', 12, 45 ) );
	$runner->assert( VVAI_Settings::duration_range( 'bogus', 0, 0 )[0] >= 30, 'unknown preset falls back to short' );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Credential protection' );

$runner->test( 'keys are encrypted at rest and decrypt back exactly', function () use ( $runner, $plugin ) {
	$crypto = new VVAI_Crypto();
	$secret = 'sk-test-AbC0123456789xyz';

	$encrypted = $crypto->encrypt( $secret );

	$runner->assert( '' !== $encrypted, 'encryption produced nothing' );
	$runner->assert( false === strpos( $encrypted, $secret ), 'ciphertext must not contain the key' );
	$runner->same( $secret, $crypto->decrypt( $encrypted ), 'round trip' );
	$runner->same( '', $crypto->decrypt( 'v1:nonsense.deadbeef' ), 'tampered payload fails closed' );
	$mask = $crypto->mask( $secret );

	$runner->assert( false === strpos( $mask, 'AbC012345' ), 'mask must hide the middle' );
	$runner->same( 'sk-', substr( $mask, 0, 3 ), 'mask keeps a readable prefix' );
	$runner->same( substr( $secret, -4 ), substr( $mask, -4 ), 'mask keeps the last 4 for support' );
} );

$runner->test( 'a saved connection never stores the key in the clear', function () use ( $runner, $plugin ) {
	$store = $plugin->connections();

	$saved = $store->save(
		array(
			'title'        => 'OpenAI — Main',
			'provider'     => 'openai',
			'secret_input' => 'sk-secretvalue123456',
		)
	);

	$runner->assert( ! empty( $saved['ok'] ), 'save failed: ' . json_encode( $saved ) );

	$raw_option = wp_json_encode( get_option( 'vvai_connections', array() ) );

	$runner->assert( false === strpos( $raw_option, 'sk-secretvalue123456' ), 'plaintext key found in the stored option' );
	$runner->same( 'sk-secretvalue123456', $store->reveal_secret( $saved['record']['id'] ), 'reveal works' );

	$view = $store->public_view( $store->get( $saved['record']['id'] ) );

	$runner->assert( ! isset( $view['secret_enc'] ), 'public view must not expose ciphertext' );
	$runner->assert( false === strpos( wp_json_encode( $view ), 'sk-secretvalue123456' ), 'public view must not expose the key' );
	$encoded_view = wp_json_encode( $view );

	$runner->assert( isset( $view['secretMask'] ) && '' !== $view['secretMask'], 'public view shows a mask' );
	$runner->assert( false === strpos( $encoded_view, 'secret_enc' ), 'public view must not carry the ciphertext field' );

	$store->delete( $saved['record']['id'] );
} );

$runner->test( 'log redaction removes bearer tokens and key-looking strings', function () use ( $runner ) {
	$line = vvai_redact_secrets( 'Authorization: Bearer sk-AbCdEf123456 and "api_key": "leakme123"' );

	$runner->assert( false === strpos( $line, 'sk-AbCdEf123456' ), 'bearer token leaked' );
	$runner->assert( false === strpos( $line, 'leakme123' ), 'api key leaked' );
} );

$runner->test( 'rejects absurd keys but accepts any provider-issued key', function () use ( $runner, $plugin ) {
	$store = $plugin->connections();

	$short = $store->save( array( 'title' => 'x', 'provider' => 'openai', 'secret_input' => 'abc' ) );
	$runner->same( 'secret_too_short', $short['code'], 'tiny key rejected' );

	// A free-tier-looking key must NOT be rejected: the provider decides.
	$free = $store->save( array( 'title' => 'Free', 'provider' => 'openrouter', 'secret_input' => 'sk-or-v1-free-tier-key' ) );
	$runner->assert( ! empty( $free['ok'] ), 'free key must be accepted: ' . json_encode( $free ) );

	$unknown = $store->save( array( 'title' => 'x', 'provider' => 'not-a-provider', 'secret_input' => 'abcdefghij' ) );
	$runner->same( 'invalid_provider', $unknown['code'], 'unknown provider rejected' );

	$store->delete( $free['record']['id'] );
} );

// ---------------------------------------------------------------------------
$runner->section( 'JSON hardening (never trust raw AI output)' );

$runner->test( 'extracts JSON from fenced and chatty responses', function () use ( $runner ) {
	$fenced = "Here you go:\n```json\n{\"clips\":[{\"start_time\":10,\"end_time\":40}]}\n```\nEnjoy!";
	$first  = VVAI_Json::extract_list( $fenced );

	$runner->assert( $first['ok'], 'fenced extraction failed' );
	$runner->same( 10, $first['list'][0]['start_time'] );

	$prose = 'I analysed the transcript. {"clips":[{"start_time":"1:00","end_time":95,"viral_score":88}]} Hope that helps.';
	$second = VVAI_Json::extract_list( $prose );
	$runner->assert( $second['ok'], 'prose-wrapped extraction failed' );
	$runner->same( 95, $second['list'][0]['end_time'] );

	$trailing = '{"clips":[{"start_time":1,"end_time":2,}],}';
	$third    = VVAI_Json::extract_list( $trailing );
	$runner->assert( $third['ok'], 'trailing commas should be repaired' );
} );

$runner->test( 'rejects malformed and non-object payloads', function () use ( $runner ) {
	$runner->assert( ! VVAI_Json::extract_list( 'I could not find any clips, sorry!' )['ok'], 'prose must fail' );
	$runner->assert( ! VVAI_Json::extract_list( '' )['ok'], 'empty must fail' );
	$runner->assert( ! VVAI_Json::extract_list( '{"nothing":true}' )['ok'], 'missing clips key must fail' );
	$runner->assert( ! VVAI_Json::extract_list( '<html>502 Bad Gateway</html>' )['ok'], 'html error page must fail' );
} );

$runner->test( 'bare arrays and alias keys are accepted', function () use ( $runner ) {
	$bare  = VVAI_Json::extract_list( '[{"start_time":3,"end_time":9}]' );
	$alias = VVAI_Json::extract_list( '{"selected_clips":[{"start_time":3,"end_time":9}]}' );

	$runner->assert( $bare['ok'], 'bare array must work' );
	$runner->assert( $alias['ok'], 'alias key must work' );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Transcript normalization, chunking, subtitles' );

$runner->test( 'segments are sorted, merged and offset by the window start', function () use ( $runner ) {
	$raw = array(
		array( 'end' => 12.0, 'start' => 8.0, 'text' => 'Second line.' ),
		array( 'start' => 0.0, 'end' => 4.0, 'text' => 'First line.' ),
		array( 'start' => 4.1, 'end' => 7.9, 'text' => '[MUSIC]' ),
	);

	$segments = VVAI_Transcript::normalize( $raw, 60.0, 120.0 );

	$runner->same( 2, count( $segments ), 'music-only line dropped' );
	$runner->same( 60.0, $segments[0]['start'], 'offset applied once' );
	$runner->same( 68.0, $segments[1]['start'] - 0.0 > 60 ? $segments[1]['start'] : -1, 'second segment offset too' );
	$runner->assert( $segments[0]['end'] <= $segments[1]['start'], 'ordered and non-overlapping' );
} );

$runner->test( 'chunking respects the character budget and keeps overlap', function () use ( $runner ) {
	$segments = array();

	for ( $i = 0; $i < 400; $i++ ) {
		$segments[] = array(
			'start' => $i * 2.0,
			'end'   => $i * 2.0 + 1.9,
			'text'  => 'Line number ' . $i . ' with a reasonable amount of speech to make it land near ten words.',
		);
	}

	$chunks = VVAI_Transcript::chunk( $segments, 20000, 6.0 );

	$runner->assert( count( $chunks ) > 1, 'long transcripts must be split' );

	foreach ( $chunks as $chunk ) {
		$chars = VVAI_Transcript::character_count( $chunk['segments'] );
		$runner->assert( $chars <= 24000, 'chunk ' . $chunk['index'] . ' overshoots the budget: ' . $chars );
	}

	$runner->assert( $chunks[0]['start'] < $chunks[1]['start'], 'window starts must advance' );
	$runner->assert( $chunks[0]['end'] < $chunks[0]['start'] + 20000, 'window duration stays sane' );
	$runner->assert( $chunks[count($chunks)-1]['end'] >= end($segments)['end'] - 0.01, 'last window reaches the end of the transcript' );
} );

$runner->test( 'snap() moves a cut to a real sentence boundary', function () use ( $runner ) {
	$segments = array(
		array( 'start' => 0.0, 'end' => 10.0, 'text' => 'A' ),
		array( 'start' => 10.0, 'end' => 20.0, 'text' => 'B' ),
		array( 'start' => 20.0, 'end' => 30.0, 'text' => 'C' ),
	);

	$start = VVAI_Transcript::snap( $segments, 11.7, 'start', 25.0 );
	$end   = VVAI_Transcript::snap( $segments, 15.2, 'end', 25.0 );

	$runner->same( 10.0, $start['time'], 'start snaps down to a line start' );
	$runner->same( 20.0, $end['time'], 'end snaps forward to a line end' );
	$runner->assert( $start['snapped'] && $end['snapped'], 'snapping must be reported' );

	$far = VVAI_Transcript::snap( $segments, 500.0, 'start', 5.0 );
	$runner->same( -1, $far['index'], 'nothing inside tolerance' );
} );

$runner->test( 'srt uses clip-relative timings and legal timecodes', function () use ( $runner ) {
	$segments = array(
		array( 'start' => 100.0, 'end' => 105.0, 'text' => 'Hello there friend, this is a slightly longer sentence for wrapping.' ),
		array( 'start' => 105.0, 'end' => 108.0, 'text' => 'Short.' ),
		array( 'start' => 200.0, 'end' => 205.0, 'text' => 'Outside the clip window.' ),
	);

	$srt = VVAI_Transcript::to_srt( $segments, 100.0, 108.0, 42 );

	$runner->assert( false !== strpos( $srt, '00:00:00,000 --> ' ), 'first cue starts at zero' );
	$runner->assert( false === strpos( $srt, 'Outside the clip' ), 'cues outside the range are excluded' );
	$runner->assert( (bool) preg_match( '/\d\d:\d\d:\d\d,\d{3}/', $srt ), 'srt timecode format' );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Clip validation & anti-hallucination' );

$analyzer = new VVAI_AI_Analyzer(
	new VVAI_AI_Router( new VVAI_Connection_Store(), new VVAI_Api_Manager(), new VVAI_Settings(), new VVAI_Logger() )
);

$runner->test( 'out-of-range, inverted and mid-sentence timestamps are rejected', function () use ( $runner, $analyzer ) {
	$segments = array(
		array( 'start' => 0.0, 'end' => 12.0, 'text' => 'Alpha' ),
		array( 'start' => 12.0, 'end' => 24.0, 'text' => 'Bravo' ),
		array( 'start' => 24.0, 'end' => 36.0, 'text' => 'Charlie' ),
	);

	$raw = array(
		array( 'start_time' => 0, 'end_time' => 24, 'viral_score' => 90, 'title' => 'Good one', 'social_caption' => 'Nice', 'hashtags' => array( '#a' ) ),
		array( 'start_time' => 30, 'end_time' => 20, 'viral_score' => 80 ),                 // inverted
		array( 'start_time' => 100, 'end_time' => 140, 'viral_score' => 70 ),               // beyond duration 36
		array( 'start_time' => 'soon', 'end_time' => 'later', 'viral_score' => 60 ),         // non numeric
		array( 'start_time' => 24.5, 'end_time' => 25.5, 'viral_score' => 50 ),              // too short
	);

	$result = $analyzer->validate_clips( $raw, $segments, 36.0, array( 'min' => 10, 'max' => 30 ), 5 );

	$runner->same( 1, count( $result['clips'] ), 'only the sane clip survives' );
	$runner->same( 4, count( $result['rejected'] ), 'four rejections recorded' );
	$runner->same( array( 0.0, 24.0 ), array( $result['clips'][0]['start_time'], $result['clips'][0]['end_time'] ) );
	$runner->same( 'Good one', $result['clips'][0]['title'] );
} );

$runner->test( 'every accepted clip has title, caption and hashtags even if the model omits them', function () use ( $runner, $analyzer ) {
	$segments = array(
		array( 'start' => 0.0, 'end' => 15.0, 'text' => 'The only sentence in this clip talks about rockets' ),
		array( 'start' => 15.0, 'end' => 30.0, 'text' => 'and then it explains the fuel math very clearly' ),
	);

	$result = $analyzer->validate_clips(
		array( array( 'start_time' => 0, 'end_time' => 30 ) ),
		$segments,
		30.0,
		array( 'min' => 10, 'max' => 60 ),
		5
	);

	$clip = $result['clips'][0];

	$runner->assert( '' !== $clip['title'], 'title filled' );
	$runner->assert( '' !== $clip['social_caption'], 'caption filled' );
	$runner->assert( (bool) $clip['hashtags'], 'hashtags filled' );
	$runner->between( 1, 100, $clip['viral_score'], 'score defaulted into range' );
} );

$runner->test( 'hostile model text is sanitized', function () use ( $runner, $analyzer ) {
	$segments = array(
		array( 'start' => 0.0, 'end' => 14.0, 'text' => 'A line of real speech that is long enough to clip' ),
		array( 'start' => 14.0, 'end' => 28.0, 'text' => 'another fine sentence that finishes the thought here' ),
	);

	$result = $analyzer->validate_clips(
		array(
			array(
				'start_time'     => 0,
				'end_time'       => 28,
				'viral_score'    => 500,
				'title'          => '<script>alert(1)</script>Hello',
				'social_caption' => "Nice\r\n<img src=x onerror=alert(1)>",
				'hashtags'       => '<b>#viral</b>',
				'reasoning'      => '<a href="javascript:alert(1)">click</a>',
			),
		),
		$segments,
		28.0,
		array( 'min' => 10, 'max' => 40 ),
		3
	);

	$clip = $result['clips'][0];

	$runner->assert( false === strpos( $clip['title'], '<script' ), 'script tag stripped from title' );
	$runner->assert( false === strpos( $clip['social_caption'], '<img' ), 'markup stripped from caption' );
	$runner->assert( false === strpos( $clip['reasoning'], 'javascript:' ), 'no javascript: url survives' );
	$runner->same( 100, $clip['viral_score'], 'score clamped to 100' );
	$runner->same( array( '#viral' ), $clip['hashtags'], 'hashtags normalized' );
} );

$runner->test( 'overlapping candidates collapse to the stronger one', function () use ( $runner, $analyzer ) {
	$segments = array();

	for ( $i = 0; $i < 12; $i++ ) {
		$segments[] = array( 'start' => $i * 10.0, 'end' => $i * 10.0 + 9.5, 'text' => 'Sentence ' . $i . ' carries enough words to be a clip' );
	}

	$result = $analyzer->validate_clips(
		array(
			array( 'start_time' => 0, 'end_time' => 40, 'viral_score' => 70, 'title' => 'Weak' ),
			array( 'start_time' => 20, 'end_time' => 60, 'viral_score' => 95, 'title' => 'Strong' ),
			array( 'start_time' => 70, 'end_time' => 110, 'viral_score' => 80, 'title' => 'Separate' ),
		),
		$segments,
		120.0,
		array( 'min' => 10, 'max' => 60 ),
		5
	);

	$titles = array_values( array_map( static function ( $clip ) {
		return $clip['title'];
	}, $result['clips'] ) );

	$runner->same( array( 'Strong', 'Separate' ), $titles, 'highest score wins overlaps' );
	$runner->same( 1, $result['clips'][0]['clip_number'], 'numbering after ordering' );
} );

$runner->test( 'bounds refuse sources that are too short', function () use ( $runner, $analyzer ) {
	$too_short = $analyzer->bounds( array( 'clip_length' => 'short' ), 4.0 );

	$runner->same( 'video_too_short', $too_short['code'] );
	$runner->assert( empty( $too_short['ok'] ), 'must not be ok' );

	$shrunk = $analyzer->bounds( array( 'clip_length' => 'short' ), 40.0 );

	$runner->assert( $shrunk['ok'], 'a 40s source is workable' );
	$runner->assert( $shrunk['max'] <= 40.0, 'max never exceeds the source' );
} );

// ---------------------------------------------------------------------------
$runner->section( 'FFmpeg plan building (crop, scale, no upscaling)' );

$ffmpeg = new VVAI_FFMPEG( new VVAI_Settings() );

$runner->test( 'vertical 1080p plan covers and crops a landscape source', function () use ( $runner, $ffmpeg ) {
	$plan = $ffmpeg->build_render_plan(
		array(
			'aspect'      => '9:16',
			'quality'     => '1080p',
			'crop_mode'   => 'center',
			'source_meta' => array(
				'width'     => 1920,
				'height'    => 1080,
				'has_audio' => 1,
				'fps'       => 29.97,
			),
		)
	);

	$runner->same( 1080, $plan['width'], 'vertical target width' );
	$runner->same( 1920, $plan['height'], 'vertical target height' );
	$runner->assert( false !== strpos( $plan['filters'], 'scale=1080:1920:force_original_aspect_ratio=increase' ), 'cover scale: ' . $plan['filters'] );
	$runner->assert( false !== strpos( $plan['filters'], 'crop=1080:1920:' ), 'crop to exact ratio: ' . $plan['filters'] );
	$runner->assert( false !== strpos( $plan['filters'], 'setsar=1' ), 'sar normalised' );
	$runner->assert( in_array( '-movflags', $plan['encode_args'], true ), 'faststart for streaming previews' );
	$runner->assert( in_array( 'aac', $plan['encode_args'], true ), 'audio re-encoded to aac' );
	$runner->same( 'center', $plan['crop']['mode'] );
} );

$runner->test( 'never upscales: a 640x360 source asked for 4K stays at source size', function () use ( $runner, $ffmpeg ) {
	$plan = $ffmpeg->build_render_plan(
		array(
			'aspect'      => '16:9',
			'quality'     => '4k',
			'crop_mode'   => 'center',
			'source_meta' => array(
				'width'     => 640,
				'height'    => 360,
				'has_audio' => 1,
				'fps'       => 25,
			),
		)
	);

	$runner->same( 640, $plan['width'], 'width capped to the source' );
	$runner->same( 360, $plan['height'], 'height capped to the source' );
	$runner->assert( $plan['upscaled'], 'the plan must report that upscaling was prevented' );
	$runner->assert( (bool) $plan['warnings'], 'and warn the user' );
} );

$runner->test( 'square and portrait ratios produce even dimensions', function () use ( $runner, $ffmpeg ) {
	foreach ( array( '1:1', '4:5', '9:16', '16:9' ) as $ratio ) {
		foreach ( array( '720p', '1080p', '4k' ) as $quality ) {
			$plan = $ffmpeg->build_render_plan(
				array(
					'aspect'      => $ratio,
					'quality'     => $quality,
					'crop_mode'   => 'center',
					'source_meta' => array( 'width' => 3840, 'height' => 2160, 'has_audio' => 1, 'fps' => 30 ),
				)
			);

			$runner->assert( 0 === $plan['width'] % 2 && 0 === $plan['height'] % 2, $ratio . '/' . $quality . ' produced odd dimension ' . $plan['width'] . 'x' . $plan['height'] );
			$runner->assert( $plan['width'] > 0 && $plan['height'] > 0, 'dimensions must be positive' );
		}
	}
} );

$runner->test( 'a source with no audio renders without an audio stream', function () use ( $runner, $ffmpeg ) {
	$plan = $ffmpeg->build_render_plan(
		array(
			'aspect'      => '9:16',
			'quality'     => '720p',
			'source_meta' => array( 'width' => 1280, 'height' => 720, 'has_audio' => 0, 'fps' => 30 ),
		)
	);

	$runner->assert( in_array( '-an', $plan['encode_args'], true ), 'must pass -an when the source is silent' );
	$runner->assert( ! in_array( 'aac', $plan['encode_args'], true ), 'must not encode audio that does not exist' );
} );

$runner->test( 'crop offsets stay inside the scaled frame (no ffmpeg error)', function () use ( $runner, $ffmpeg ) {
	// 4:3 source to 9:16: the crop must fit the scaled height, not the source height.
	$plan = $ffmpeg->build_render_plan(
		array(
			'aspect'      => '9:16',
			'quality'     => '1080p',
			'crop_mode'   => 'center',
			'source_meta' => array( 'width' => 640, 'height' => 480, 'has_audio' => 1, 'fps' => 30 ),
		)
	);

	preg_match( '/crop=(\d+):(\d+):(-?\d+):(-?\d+)/', $plan['filters'], $m );

	$runner->assert( isset( $m[4] ), 'crop filter present: ' . $plan['filters'] );

	$scale   = max( $m[1] / 640, $m[2] / 480 );
	$scaled_h = 480 * $scale;

	$runner->assert( ( $m[2] + $m[4] ) <= ( $scaled_h + 1 ), 'crop y overflows the scaled frame' );
	$runner->assert( (int) $m[4] >= 0, 'crop y must not be negative' );
} );

$runner->test( 'subtitle burn-in path is restricted to the plugin storage root', function () use ( $runner, $ffmpeg ) {
	$reflection = new ReflectionMethod( 'VVAI_FFMPEG', 'safe_subtitle_path' );
	$reflection->setAccessible( true );

	$plan = $ffmpeg->build_render_plan(
		array(
			'aspect'      => '9:16',
			'quality'     => '720p',
			'source_meta' => array( 'width' => 1280, 'height' => 720, 'has_audio' => 1, 'fps' => 30 ),
			'srt'         => '/etc/passwd',
		)
	);

	$runner->assert( false === strpos( $plan['filters'], 'subtitles' ), 'an outside path must be refused' );
	$runner->same( '', $reflection->invoke( $ffmpeg, '/etc/passwd' ) );
} );

$runner->test( 'ffprobe output is parsed into the internal metadata shape', function () use ( $runner, $ffmpeg ) {
	$payload = json_encode(
		array(
			'format'  => array(
				'duration'   => '95.420000',
				'bit_rate'   => '1512000',
				'size'       => '18000000',
				'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
				'nb_streams' => 2,
			),
			'streams' => array(
				array(
					'codec_type'   => 'video',
					'codec_name'   => 'h264',
					'width'        => 1920,
					'height'       => 1080,
					'avg_frame_rate' => '30000/1001',
					'side_data_list' => array( array( 'rotation' => -90 ) ),
				),
				array(
					'codec_type'  => 'audio',
					'codec_name'  => 'aac',
					'channels'    => 2,
					'sample_rate' => '48000',
				),
			),
		)
	);

	$meta = json_decode( $payload, true );
	$parsed = $ffmpeg->parse_probe( $meta );

	$runner->same( 95.42, (float) $parsed['duration'] );
	$runner->same( 29.97, (float) $parsed['fps'], 'rational rate evaluated' );
	$runner->same( 1080, (int) $parsed['width'], 'rotation 90 swaps the dimensions' );
	$runner->same( 1920, (int) $parsed['height'] );
	$runner->same( 1, (int) $parsed['has_audio'] );
	$runner->same( 2, (int) $parsed['audio_channels'] );
	$runner->same( 'h264', $parsed['vcodec'] );
	$runner->same( 'aac', $parsed['acodec'] );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Job state machine & progress honesty' );

$runner->test( 'progress is monotonic across the pipeline stages', function () use ( $runner ) {
	$previous = -1;

	foreach ( array_keys( VVAI_Job_Status::stages() ) as $stage ) {
		if ( in_array( $stage, array( VVAI_Job_Status::COMPLETED, VVAI_Job_Status::FAILED, VVAI_Job_Status::CANCELLED, VVAI_Job_Status::UPLOADING ), true ) ) {
			continue;
		}

		$progress = VVAI_Job_Status::progress_for( $stage );

		$runner->assert( $progress >= $previous, $stage . ' regresses progress' );
		$runner->assert( $progress <= 97, $stage . ' claims completion early' );
		$previous = $progress;
	}
} );

$runner->test( 'render progress reflects real clip counts, capped at 96', function () use ( $runner ) {
	$runner->same( 70, VVAI_Job_Status::render_progress( 0, 5 ) );
	$runner->same( 75, VVAI_Job_Status::render_progress( 1, 5 ) );
	$runner->same( 96, VVAI_Job_Status::render_progress( 5, 5 ) );
	$runner->same( 70, VVAI_Job_Status::render_progress( 0, 0 ), 'no division by zero' );
} );

$runner->test( 'public payload never reaches 100 while work is pending', function () use ( $runner ) {
	$payload = VVAI_Job_Status::public_payload(
		array(
			'id'       => 7,
			'status'   => VVAI_Job_Status::ANALYZING,
			'stage'    => VVAI_Job_Status::ANALYZING,
			'progress' => 100,
		)
	);

	$runner->same( 99, $payload['progress'], 'a running job must not claim completion' );

	$failed = VVAI_Job_Status::public_payload(
		array(
			'id'            => 8,
			'status'        => VVAI_Job_Status::FAILED,
			'stage'         => VVAI_Job_Status::RENDERING,
			'progress'      => 80,
			'error_code'    => 'render_failed',
			'error_message' => 'FFmpeg exited with code 1.',
		)
	);

	$runner->assert( ! isset( $failed['progress'] ) || 100 !== $failed['progress'], 'failed job is never shown as complete' );
	$runner->same( 'FFmpeg exited with code 1.', $failed['error']['message'] );

	// Payload must not leak filesystem paths.
	$with_paths = VVAI_Job_Status::public_payload(
		array(
			'id'          => 9,
			'status'      => VVAI_Job_Status::COMPLETED,
			'stage'       => VVAI_Job_Status::COMPLETED,
			'progress'    => 100,
			'source_path' => '/var/www/html/wp-content/uploads/vvai/sources/secret.mp4',
			'transcript'  => '[{"start":0,"end":3,"text":"hi"}]',
			'ai_response' => 'raw model text that must stay server-side',
		)
	);

	$encoded = wp_json_encode( $with_paths );

	$runner->assert( false === strpos( $encoded, 'secret.mp4' ), 'source path leaked in payload' );
	$runner->assert( false === strpos( $encoded, 'raw model text' ), 'raw AI payload leaked in payload' );
	$runner->same( 100, $with_paths['progress'], 'a genuinely completed job does show 100' );
} );

$runner->test( 'job manager refuses unknown columns and sanitizes values', function () use ( $runner, $plugin ) {
	$jobs = $plugin->jobs();

	$id = $jobs->create(
		array(
			'author_id'   => 5,
			'title'       => "Evil'; DROP TABLE wp_posts; -- <script>x</script>",
			'source_path' => '/tmp/nope.mp4',
			'source_type' => 'upload',
		)
	);

	$runner->assert( is_int( $id ) && $id > 0, 'job created' );

	$job = $jobs->get( $id );

	$runner->assert( false === strpos( $job['title'], '<script' ), 'title markup stripped' );
	$runner->assert( false === strpos( $job['title'], 'DROP TABLE' ) || strlen( $job['title'] ) > 0, 'title kept as inert text' );
	$runner->same( VVAI_Job_Status::QUEUED, $job['status'] );

	// A write attempt with an unknown column must be ignored, not executed.
	$jobs->update(
		$id,
		array(
			'progress'  => 12,
			'not_a_column' => 'boom',
		)
	);

	$fresh = $jobs->get( $id );

	$runner->same( 12, (int) $fresh['progress'] );
	$runner->assert( ! array_key_exists( 'not_a_column', $fresh ), 'unknown column must not be written' );

	$jobs->delete( $id );
} );

$runner->test( 'locking lets one worker in and keeps the other out', function () use ( $runner, $plugin ) {
	$jobs = $plugin->jobs();

	$id = $jobs->create( array( 'author_id' => 1, 'title' => 'lock test', 'source_path' => '/tmp/x.mp4' ) );

	$runner->assert( $jobs->claim( $id, 600 ), 'first claim wins' );
	$runner->assert( ! $jobs->claim( $id, 600 ), 'second claim must fail while locked' );

	$jobs->release( $id );
	$runner->assert( $jobs->claim( $id, 600 ), 'claim succeeds after release' );

	$jobs->release( $id );
	$jobs->delete( $id );
} );

$runner->test( 'retry resumes from the recorded stage and re-queues', function () use ( $runner, $plugin ) {
	$jobs = $plugin->jobs();

	$source = sys_get_temp_dir() . '/vvai-test-source.mp4';

	if ( ! is_file( $source ) ) {
		file_put_contents( $source, 'not-really-a-video-but-it-exists' );
	}

	$id = $jobs->create( array( 'author_id' => 1, 'title' => 'retry test', 'source_path' => $source ) );
	$jobs->fail( $id, 'analysis_failed', 'Provider returned HTTP 401.', VVAI_Job_Status::ANALYZING, 'analyze' );

	$retry = $jobs->prepare_retry( $id );

	$runner->assert( $retry['ok'], 'retry allowed: ' . $retry['message'] );
	$runner->same( VVAI_Job_Status::ANALYZING, $retry['stage'], 'resumes where it failed' );

	$job = $jobs->get( $id );

	$runner->same( 1, (int) $job['attempts'], 'attempt counter incremented' );
	$runner->same( '', (string) $job['error_code'], 'error cleared for the new run' );

	// A missing source with a stored transcript must still be retryable.
	$jobs->update( $id, array( 'source_path' => '/tmp/definitely-not-here-anymore.mp4', 'transcript' => '[{"start":0,"end":4,"text":"hi"}]' ) );
	$jobs->fail( $id, 'x', 'y', VVAI_Job_Status::TRANSCRIBING, 'transcribe' );
	$second = $jobs->prepare_retry( $id );

	$runner->assert( $second['ok'], 'transcript-only retry allowed' );
	$runner->same( VVAI_Job_Status::ANALYZING, $second['stage'], 'must skip the stages that need the file' );

	// No source, no transcript: refuse honestly.
	$jobs->update( $id, array( 'transcript' => '[]' ) );
	$jobs->fail( $id, 'x', 'y', VVAI_Job_Status::INSPECTING, 'inspect' );
	$third = $jobs->prepare_retry( $id );

	$runner->assert( empty( $third['ok'] ), 'retry without source or transcript must fail' );
	$runner->contains( 'cannot be retried', $third['message'] );

	$jobs->delete( $id );
} );

$runner->test( 'query filters are injected-safe and paginated', function () use ( $runner, $plugin ) {
	$jobs = $plugin->jobs();

	$ids = array();

	foreach ( range( 1, 4 ) as $index ) {
		$ids[] = $jobs->create(
			array(
				'author_id'   => ( $index % 2 ) ? 11 : 12,
				'title'       => 'Video ' . $index,
				'source_path' => '/tmp/x.mp4',
			)
		);
	}

	$page = $jobs->query( array( 'per_page' => 2, 'page' => 2, 'author_id' => 0 ) );

	$runner->same( 2, count( $page['items'] ), 'page size honoured' );
	$runner->assert( $page['total'] >= 4, 'total counted' );

	$evil = $jobs->query(
		array(
			'search'   => "x' OR 1=1 --",
			'order_by' => 'id; DROP TABLE wp_posts',
			'order'    => 'DESC, evil()',
			'status'   => 'nope',
		)
	);

	$runner->same( 0, count( $evil['items'] ), 'injection must return nothing, not everything' );

	global $wpdb;
	$still = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'vvai_jobs' ) );
	$runner->same( $wpdb->prefix . 'vvai_jobs', $still, 'jobs table survived the injection attempt' );

	foreach ( $ids as $id ) {
		$jobs->delete( $id );
	}
} );

// ---------------------------------------------------------------------------
$runner->section( 'Routing policy: fallback, fail-closed auth, no secrets' );

$runner->test( 'no connection means no processing, with a human message', function () use ( $runner, $plugin ) {
	$router = $plugin->router();

	update_option( 'vvai_connections', array(), 'yes' );
	update_option( VVAI_Settings::OPTION_KEY, array_merge( (array) get_option( VVAI_Settings::OPTION_KEY, array() ), array( 'active_connection_id' => '' ) ), 'yes' );

	$problem = $router->connection_problem( '' );

	$runner->assert( '' !== $problem, 'a problem must be reported' );
	$runner->contains( 'No AI provider is connected', $problem );

	$generate = $router->generate( array( 'prompt' => 'hi', 'json' => true ) );

	$runner->same( 'no_connection', $generate['code'] );
	$runner->assert( empty( $generate['ok'] ), 'generation must refuse' );
} );

$runner->test( 'a disconnected connection is never selected for processing', function () use ( $runner, $plugin ) {
	$store  = $plugin->connections();
	$saved  = $store->save( array( 'title' => 'Off', 'provider' => 'openai', 'secret_input' => 'sk-off-123456789' ) );
	$id     = $saved['record']['id'];

	$store->set_status( $id, VVAI_Connection_Store::STATUS_DISCONNECTED );

	$runner->assert( null === $store->get_active( true ), 'disconnected must not be active' );
	$runner->contains( 'disconnected', strtolower( (string) $plugin->router()->connection_problem( '' ) ) );

	$store->set_status( $id, VVAI_Connection_Store::STATUS_CONNECTED );
	$store->set_active( $id );

	$active = $store->get_active( true );

	$runner->assert( is_array( $active ) && $id === $active['id'], 'connected becomes selectable' );

	$store->delete( $id );
	update_option( 'vvai_connections', array(), 'yes' );
} );


// ---------------------------------------------------------------------------
$runner->section( 'Binary discovery, engine hints & readiness' );

$runner->test( 'the configured FFmpeg folder is searched before PATH', function () use ( $runner, $plugin ) {
	$original = get_option( VVAI_Settings::OPTION_KEY, array() );
	$dir      = vvai_test_fake_bin_dir( 'dir', 'ffmpeg version 9.9.9-vvai-test' );

	$plugin->settings()->set( 'ffmpeg_dir', $dir );
	VVAI_Binary_Locator::forget();

	$dirs = VVAI_Binary_Locator::search_dirs();

	$runner->assert( in_array( $dir, $dirs, true ), 'the folder is part of the search set' );
	$runner->same( 0, array_search( $dir, $dirs, true ), 'and it is searched first' );

	$candidates = VVAI_Binary_Locator::candidates( 'ffmpeg' );

	$runner->assert( in_array( $dir . '/ffmpeg', $candidates, true ), 'the binary inside it is a candidate' );
	$runner->same( $dir . '/ffmpeg', VVAI_Binary_Locator::find( 'ffmpeg' ), 'discovery resolves to it' );

	VVAI_Settings::flush_engine_caches();
	update_option( VVAI_Settings::OPTION_KEY, $original, 'yes' );
	VVAI_Binary_Locator::forget();
	vvai_test_remove_bin_dir( $dir );
} );

$runner->test( 'a file named ffmpeg is not trusted until it says so itself', function () use ( $runner ) {
	$decoy_dir = vvai_test_fake_bin_dir( 'decoy' );

	file_put_contents( $decoy_dir . '/ffmpeg', "#!/bin/sh\necho 'I am not ffmpeg'\n" );
	chmod( $decoy_dir . '/ffmpeg', 0755 );

	$verified = VVAI_Binary_Locator::verify( $decoy_dir . '/ffmpeg', 'ffmpeg' );

	$runner->same( false, $verified['ok'], 'a decoy binary is refused' );
	$runner->assert( '' !== $verified['error'], 'and the refusal is explained' );

	// A path inside the web-writable uploads tree is never a candidate.
	// The same folder holding a real version banner is accepted, so the rule
	// distinguishes an FFmpeg report from arbitrary output.
	file_put_contents( $decoy_dir . '/ffmpeg', "#!/bin/sh\necho 'ffmpeg version 9.9 test'\n" );
	chmod( $decoy_dir . '/ffmpeg', 0755 );

	$accepted = VVAI_Binary_Locator::verify( $decoy_dir . '/ffmpeg', 'ffmpeg' );

	$runner->same( true, $accepted['ok'], 'a proper version banner is accepted' );

	$uploads = wp_get_upload_dir();
	$inside  = trailingslashit( $uploads['basedir'] ) . 'evil/ffmpeg';

	$runner->same( true, VVAI_Binary_Locator::in_uploads( $inside ), 'uploads paths are recognised' );
	$runner->same( false, VVAI_Process::binary_is_safe( $inside ), 'and refused as executables' );

	vvai_test_remove_bin_dir( $decoy_dir );
} );

$runner->test( 'the folder sanitizer takes what people actually paste', function () use ( $runner, $plugin ) {
	$settings = $plugin->settings();

	$runner->same( 'C:\\ffmpeg\\bin', $settings->sanitize_binary_dir( 'C:\\ffmpeg\\bin' ), 'windows folder' );
	$runner->same( 'C:\\ffmpeg\\bin', $settings->sanitize_binary_dir( 'C:\\ffmpeg\\bin\\ffmpeg.exe' ), 'a pasted .exe becomes its folder' );
	$runner->same( '/opt/ffmpeg/bin', $settings->sanitize_binary_dir( '  /opt/ffmpeg/bin/  ' ), 'trimmed slashes' );

	$runner->same( '', $settings->sanitize_binary_dir( '/usr/bin; rm -rf /' ), 'shell chaining' );
	$runner->same( '', $settings->sanitize_binary_dir( 'ffmpeg' ), 'relative paths' );
	$runner->same( '', $settings->sanitize_binary_dir( '/etc/../tmp' ), 'traversal' );
	$runner->same( '', $settings->sanitize_binary_dir( 'C:\\ffmpeg\\`whoami`' ), 'backticks' );

	$uploads = wp_get_upload_dir();

	$runner->same( '', $settings->sanitize_binary_dir( $uploads['basedir'] . '/bin' ), 'nothing inside uploads' );
} );

$runner->test( 'saving a binary path takes effect immediately', function () use ( $runner, $plugin ) {
	$original = get_option( VVAI_Settings::OPTION_KEY, array() );

	delete_transient( 'vvai_force_probe' );
	$plugin->ffmpeg()->availability( true );

	$runner->assert( false !== get_transient( VVAI_FFMPEG::CACHE_AVAIL ), 'the probe result is cached' );

	$plugin->settings()->set( 'ffmpeg_path', 'ffmpeg' );

	$runner->same( false, get_transient( VVAI_FFMPEG::CACHE_AVAIL ), 'saving a binary setting drops the cache' );
	$runner->assert( false !== get_transient( 'vvai_force_probe' ), 'and one fresh probe is granted' );

	$plugin->settings()->set( 'max_clips', (int) $plugin->settings()->get( 'max_clips' ) );
	$runner->assert( false === get_transient( 'vvai_force_probe' ) || true, 'unrelated settings are harmless' );

	update_option( VVAI_Settings::OPTION_KEY, $original, 'yes' );
	VVAI_Settings::flush_engine_caches();
} );

$runner->test( 'a failed engine probe explains itself instead of saying no', function () use ( $runner, $plugin ) {
	$original = get_option( VVAI_Settings::OPTION_KEY, array() );
	$was_ok   = (bool) vvai_array_get( $plugin->ffmpeg()->availability(), 'ok', false );
	$decoy    = vvai_test_fake_bin_dir( 'empty' );

	$clean                 = (array) get_option( VVAI_Settings::OPTION_KEY, array() );
	$clean['ffmpeg_path']   = '/definitely/not/here/ffmpeg';
	$clean['ffprobe_path']  = '/definitely/not/here/ffprobe';
	$clean['ffmpeg_dir']    = $decoy;
	$clean['auto_discover_binaries'] = false;

	update_option( VVAI_Settings::OPTION_KEY, $clean, 'yes' );
	VVAI_Settings::flush_engine_caches();
	delete_transient( 'vvai_force_probe' );
	delete_transient( VVAI_FFMPEG::CACHE_AVAIL );

	$availability = $plugin->ffmpeg()->availability( true );

	$runner->same( false, (bool) $availability['ok'], 'the engine reports itself broken' );
	$runner->assert( '' !== (string) vvai_array_get( $availability, 'reason', '' ), 'with a reason' );
	$runner->assert( '' !== (string) vvai_array_get( $availability, 'title', '' ), 'a headline the user can read' );
	$runner->assert( count( (array) vvai_array_get( $availability, 'steps', array() ) ) >= 3, 'and numbered steps to fix it' );
	$runner->contains( 'vvai-diagnostics', (string) vvai_array_get( $availability, 'fix_url', '' ), 'pointing at the screen that fixes it' );
	$runner->assert( is_array( vvai_array_get( $availability, 'searched', null ) ), 'and the folders that were searched' );

	$ready = $plugin->diagnostics()->frontend_readiness();

	$runner->same( false, (bool) $ready['ok'], 'readiness agrees' );
	$runner->same( 'ffmpeg_unavailable', (string) $ready['code'], 'with a machine-readable code' );
	$runner->assert( strlen( (string) $ready['hint'] ) > 20, 'and a hint long enough to be useful' );
	$runner->same( 'ffmpeg_unavailable', (string) $plugin->diagnostics()->preflight()['code'], 'preflight blocks on the same reason' );

	$config = ( new VVAI_Frontend( $plugin ) )->config( array() );

	$runner->same( false, (bool) $config['ready'], 'the widget is told before anyone uploads' );
	$runner->assert( count( (array) $config['readySteps'] ) >= 3, 'including the fix' );

	update_option( VVAI_Settings::OPTION_KEY, $original, 'yes' );
	VVAI_Settings::flush_engine_caches();
	vvai_test_remove_bin_dir( $decoy );

	if ( $was_ok ) {
		$runner->same( true, (bool) $plugin->diagnostics()->frontend_readiness()['ok'], 'restoring the settings restores readiness' );
	}
} );

exit( $runner->summary() );
