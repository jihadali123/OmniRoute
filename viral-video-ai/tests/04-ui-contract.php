<?php
/**
 * UI contract + upload-policy tests.
 *
 * These guard the two things that silently break a widget: markup that the
 * script expects but the template no longer renders, and a size rule that
 * refuses files the host would happily accept.
 */

require __DIR__ . '/framework/bootstrap.php';

$runner = new VVAI_Test_Runner();
$plugin = vvai_test_boot();

$root = VVAI_PLUGIN_UNDER_TEST;

/**
 * All data-vvai-* hooks the shipped JavaScript looks up.
 *
 * @return string[]
 */
$vvai_js_handles = static function ( $js ) {
	$found = array();

	if ( preg_match_all( "/this\.el\(\s*'([a-z0-9-]+)'/", $js, $m ) ) {
		foreach ( $m[1] as $name ) {
			$found[ $name ] = true;
		}
	}

	if ( preg_match_all( '/\[data-vvai-([a-z0-9-]+)\]/', $js, $m2 ) ) {
		foreach ( $m2[1] as $name ) {
			$found[ $name ] = true;
		}
	}

	// The clip card attributes live inside the <template> in the same file.
	if ( preg_match_all( "/el\(\s*'(clip-[a-z-]+)'/", $js, $m3 ) ) {
		foreach ( $m3[1] as $name ) {
			$found[ $name ] = true;
		}
	}

	unset( $found['stages'] ); // optional: only rendered when the stage list is shown

	return array_keys( $found );
};

// ---------------------------------------------------------------------------
$runner->section( 'Markup contract: the script must find what it queries' );

$js       = (string) file_get_contents( $root . '/assets/js/vvai-frontend.js' );
$template = (string) file_get_contents( $root . '/templates/frontend/generator.php' );
$handles  = $vvai_js_handles( $js );

$runner->test( 'every data-vvai hook the script uses exists in the template', function () use ( $runner, $handles, $template ) {
	$missing = array();

	foreach ( $handles as $handle ) {
		if ( false === strpos( $template, 'data-vvai-' . $handle ) ) {
			$missing[] = $handle;
		}
	}

	$runner->assert( ! $missing, 'template is missing: ' . implode( ', ', $missing ) );
	$runner->assert( count( $handles ) >= 18, 'contract list unexpectedly small (' . count( $handles ) . ')' );
} );

$runner->test( 'the results <template> carries every clip field the renderer writes', function () use ( $runner, $template, $js ) {
	$fields = array(
		'clip-video', 'clip-score', 'clip-number', 'clip-range', 'clip-duration',
		'clip-title', 'clip-reasoning', 'clip-caption', 'clip-hashtags',
		'clip-download', 'clip-copy-caption', 'clip-srt',
	);

	$missing = array();

	foreach ( $fields as $field ) {
		if ( false === strpos( $template, 'data-vvai-' . $field ) ) {
			$missing[] = $field;
		}

		if ( false === strpos( $js, "'" . $field . "'" ) && false === strpos( $js, '[data-vvai-' . $field . ']' ) && false === strpos( $js, "el('{$field}')" ) ) {
			$missing[] = 'js:' . $field;
		}
	}

	$runner->assert( ! $missing, 'missing on one side: ' . implode( ', ', $missing ) );
} );

$runner->test( 'the compact UI really is compact: one card, no step headings', function () use ( $runner, $template ) {
	$runner->assert( false !== strpos( $template, 'vvai-card--main' ), 'single main card present' );
	$runner->assert( substr_count( $template, 'vvai-card__label' ) <= 1, 'the old numbered 1/2/3 card labels are gone' );
	$runner->assert( false !== strpos( $template, 'vvai-chips' ), 'chip rows used for the choices' );
	$runner->assert( false === strpos( $template, 'vvai-segmented' ), 'the bulky segmented control set is gone' );
	$runner->same( '0', (string) substr_count( $template, '"1. ' ), 'no step numbering left' );
	$runner->assert( false !== strpos( $template, '<details class="vvai-advanced">' ), 'the extras are collapsed behind one disclosure' );
	$runner->assert( substr_count( $template, 'data-vvai-clip-count' ) > 0, 'clip count offered as 1-5 chips' );
} );

$runner->test( 'removed Elementor controls are gone but still accepted from a shortcode', function () use ( $runner, $root, $plugin ) {
	$widget = (string) file_get_contents( $root . '/Elementor/class-widget-generator.php' );

	foreach ( array( "'clip_length'", "'min_duration'", "'max_duration'", "'focus'", "'custom_focus'" ) as $needle ) {
		$runner->assert( false === strpos( $widget, '$this->add_control(' . "\n" . "\t\t\t" . $needle ), 'panel should not expose ' . $needle );
	}

	// The server still honours them for power users via the shortcode.
	$rest     = new VVAI_Rest_Api( $plugin );
	$method   = new ReflectionMethod( $rest, 'sanitize_job_settings' );
	$method->setAccessible( true );

	$parsed = $method->invoke(
		$rest,
		array(
			'clip_length'  => 'medium',
			'focus'        => 'dialogue',
			'min_duration' => 90,
			'max_duration' => 200,
			'custom_focus' => 'keep the whiteboard in frame',
		)
	);

		$runner->same( 'medium', $parsed['clip_length'] );
		$runner->same( 'dialogue', $parsed['focus'] );
		// A preset owns its window: 90/200 must not leak into 'medium'.
		$runner->same( 120, (int) $parsed['min_duration'] );
		$runner->same( 180, (int) $parsed['max_duration'] );
		$runner->same( 'keep the whiteboard in frame', (string) $parsed['custom_focus'] );

		$custom = $method->invoke(
			$rest,
			array(
				'clip_length'  => 'custom',
				'min_duration' => 90,
				'max_duration' => 200,
			)
		);

		$runner->same( 90, (int) $custom['min_duration'], 'custom honours explicit seconds' );
		$runner->same( 200, (int) $custom['max_duration'] );

	// And sensible defaults when nothing is sent at all (the compact UI case).
	$bare = $method->invoke( $rest, array() );

	$runner->assert( in_array( $bare['clip_length'], array( 'short', 'medium', 'long', 'custom' ), true ), 'clip_length defaulted' );
	$runner->assert( '' !== $bare['focus'], 'focus defaulted' );
	$runner->assert( (int) $bare['max_duration'] >= (int) $bare['min_duration'], 'defaults stay coherent' );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Upload size policy: as big as the host allows' );

$runner->test( '0 (default) means no plugin cap at all', function () use ( $runner, $plugin ) {
	$settings = $plugin->settings();

	$settings->override( 'max_upload_mb', 0 );

	$runner->same( 0, (int) $settings->max_upload_bytes(), 'unlimited is reported as 0, not as the PHP limit' );
	$runner->assert( true === $settings->check_upload_size( 40 * GB_IN_BYTES ), 'a 40 GB source is not refused by the plugin' );

	$settings->clear_overrides();
	$runner->same( 0, (int) $settings->get( 'max_upload_mb' ), 'factory default is unlimited' );
} );

$runner->test( 'an explicit cap still applies and explains itself', function () use ( $runner, $plugin ) {
	$settings = $plugin->settings();

	$settings->override( 'max_upload_mb', 500 );
	$effective = (int) $settings->max_upload_bytes();

	$runner->assert( $effective > 0 && $effective <= 500 * MB_IN_BYTES, 'cap respected (and lowered by the server if smaller)' );

	$error = $settings->check_upload_size( ( $effective + 1 ) * 2 );

	$runner->assert( is_wp_error( $error ), 'oversize is rejected' );
	$runner->same( 'vvai_too_large', $error->get_error_code() );
	$runner->assert( (bool) preg_match( '/[0-9.]+ ?(MB|GB)/', $error->get_error_message() ), 'message quotes real sizes: ' . $error->get_error_message() );
	$runner->contains( 'Settings', $error->get_error_message() );

	$settings->clear_overrides();
} );

$runner->test( 'php.ini "no limit" values are not read as tiny limits', function () use ( $runner ) {
	// upload_max_filesize and post_max_size are PHP_INI_PERDIR and cannot be set at
	// runtime, so ini_set() in a test would silently prove nothing. The rule lives
	// in a static helper precisely so it can be tested honestly.
	$fn = 'VVAI_Settings::php_size_limit';

	$runner->same( 0, (int) $fn( '-1', '0' ), '0 and -1 both mean unlimited' );
	$runner->same( 8 * MB_IN_BYTES, (int) $fn( '8M', '64M' ), 'the smaller value wins' );
	$runner->same( 2 * GB_IN_BYTES, (int) $fn( '2G', '2048M' ), 'units normalised' );
	$runner->same( 256 * MB_IN_BYTES, (int) $fn( '256M', '0' ), 'a one-sided limit still applies' );
	$runner->same( 0, (int) $fn( '', '' ), 'empty means unlimited' );

	$actual = (int) $fn( (string) ini_get( 'upload_max_filesize' ), (string) ini_get( 'post_max_size' ) );

	$runner->assert( 0 === $actual || $actual >= MB_IN_BYTES, 'never nonsense on this host: ' . var_export( $actual, true ) );
} );

$runner->test( 'the frontend is told 0 so it does not invent a limit', function () use ( $runner, $plugin ) {
	$plugin->settings()->override( 'max_upload_mb', 0 );

	$config = ( new VVAI_Frontend( $plugin ) )->config( array() );

	$runner->same( 0, (int) $config['maxUploadBytes'], 'maxUploadBytes 0 flows to the JS' );
	$runner->assert( false !== strpos( (string) file_get_contents( VVAI_PLUGIN_UNDER_TEST . '/templates/frontend/generator.php' ), 'no size limit set' ), 'template has the unlimited wording' );

	$plugin->settings()->override( 'max_upload_mb', 2048 );
	$config2 = ( new VVAI_Frontend( $plugin ) )->config( array() );

	$runner->assert( (int) $config2['maxUploadBytes'] > 0, 'a real cap is still shown when configured' );

	$plugin->settings()->clear_overrides();
} );

// ---------------------------------------------------------------------------
$runner->section( 'Chunked upload: big files, out-of-order chunks, honest assembly' );

/**
 * Build a container-looking file without FFmpeg (magic bytes + padding).
 *
 * @param int $size Bytes.
 * @return string Path.
 */
$vvai_fake_video = static function ( $size ) {
	$path = tempnam( sys_get_temp_dir(), 'vvai-fake-' ) . '.mp4';
	$head = pack( 'N', 32 ) . 'ftyp' . 'isom' . str_repeat( "\0", 12 );
	file_put_contents( $path, $head . str_repeat( 'A', max( 0, $size - strlen( $head ) ) ) );

	return $path;
};

$runner->test( 'a 30 MB upload in 2 MB chunks survives the whole round trip', function () use ( $runner, $plugin, $vvai_fake_video ) {
	$uploads = $plugin->uploads();
	$source  = $vvai_fake_video( 30 * MB_IN_BYTES );
	$expect  = md5_file( $source );

	$plugin->settings()->override( 'max_upload_mb', 0 );

	$session = $uploads->init_session(
		1,
		array(
			'name'  => 'big-take.mp4',
			'size'  => 30 * MB_IN_BYTES,
			'chunk' => 2 * MB_IN_BYTES,
		)
	);

	$runner->assert( ! is_wp_error( $session ), 'session: ' . ( is_wp_error( $session ) ? $session->get_error_message() : '' ) );
	$runner->same( 15, (int) $session['chunk_total'], 'chunk math' );

	$handle = (string) $session['handle'];
	$chunk  = (int) $session['chunk_size'];

	// Deliberately out of order: chunk 14 first, then 0..13.
	$order = array_merge( array( 14 ), range( 0, 13 ) );

	foreach ( $order as $index ) {
		$part = tempnam( sys_get_temp_dir(), 'vvai-part-' );
		file_put_contents( $part, (string) call_user_func( 'file_get_contents', $source, false, null, $index * $chunk, $chunk ) );

		$stored = $uploads->store_chunk( $handle, $index, $part );

		$runner->assert( ! is_wp_error( $stored ), 'chunk ' . $index . ': ' . ( is_wp_error( $stored ) ? $stored->get_error_message() : '' ) );

		@unlink( $part );
	}

	$final = $uploads->finalize( $handle, 1 );

	$runner->assert( ! is_wp_error( $final ), 'finalize: ' . ( is_wp_error( $final ) ? $final->get_error_message() : '' ) );

	if ( ! is_wp_error( $final ) ) {
		$runner->same( 30 * MB_IN_BYTES, (int) $final['size'], 'assembled size exact' );
		$runner->same( $expect, md5_file( (string) $final['path'] ), 'bytes identical after reassembly' );
		@unlink( (string) $final['path'] );
	}

	@unlink( $source );
	$plugin->settings()->clear_overrides();
} );

$runner->test( 'a short chunk is refused instead of silently truncated', function () use ( $runner, $plugin, $vvai_fake_video ) {
	$uploads = $plugin->uploads();
	$source  = $vvai_fake_video( 9 * MB_IN_BYTES );

	$session = $uploads->init_session(
		1,
		array(
			'name'  => 'truncated.mp4',
			'size'  => 9 * MB_IN_BYTES,
			'chunk' => 2 * MB_IN_BYTES,
		)
	);

	$part = tempnam( sys_get_temp_dir(), 'vvai-part-' );
	file_put_contents( $part, str_repeat( 'A', 1024 ) ); // far too small for chunk 0

	$stored = $uploads->store_chunk( (string) $session['handle'], 0, $part );

	$runner->assert( is_wp_error( $stored ), 'size mismatch must be caught' );
	$runner->contains( 'incomplete', strtolower( $stored->get_error_message() ) );

	$final = $uploads->finalize( (string) $session['handle'], 1 );

	$runner->assert( is_wp_error( $final ), 'finalize refuses a partial upload' );
	$runner->contains( 'missing', strtolower( $final->get_error_message() ) );

	@unlink( $part );
	@unlink( $source );
} );

$runner->test( 'a non-video renamed .mp4 is rejected by content, not extension', function () use ( $runner, $plugin ) {
	$uploads = $plugin->uploads();
	$fake    = tempnam( sys_get_temp_dir(), 'vvai-html-' ) . '.mp4';

	file_put_contents( $fake, str_repeat( '<html><body>not a video</body></html>', 200 ) );

	$result = $uploads->sniff( $fake, 'fake.mp4' );

	$runner->assert( is_wp_error( $result ), 'must reject' );
	$runner->assert( (bool) preg_match( '/not a video|is not a video/i', $result->get_error_message() ), 'clear wording: ' . $result->get_error_message() );

	@unlink( $fake );
} );

$runner->test( 'an upload session cannot be finished by another user', function () use ( $runner, $plugin, $vvai_fake_video ) {
	$uploads = $plugin->uploads();
	$source  = $vvai_fake_video( 2 * MB_IN_BYTES );

	vvai_test_set( 'user_id', 77 );
	$session = $uploads->init_session( 77, array( 'name' => 'mine.mp4', 'size' => 2 * MB_IN_BYTES, 'chunk' => 1024 * 1024 ) );

	// Two complete chunks, so the only failure left can be authorization.
	$blob   = (string) file_get_contents( $source );
	$chunk  = (int) $session['chunk_size'];
	$part_a = tempnam( sys_get_temp_dir(), 'vvai-part-a-' );
	$part_b = tempnam( sys_get_temp_dir(), 'vvai-part-b-' );
	file_put_contents( $part_a, substr( $blob, 0, $chunk ) );
	file_put_contents( $part_b, substr( $blob, $chunk ) );
	$uploads->store_chunk( (string) $session['handle'], 0, $part_a );
	$uploads->store_chunk( (string) $session['handle'], 1, $part_b );

	// Administrators may finish any session (legitimate support flow); an ordinary
	// co-user may not — that is the case that matters.
	vvai_test_set( 'caps', array( 'upload_files' ) );
	vvai_test_set( 'user_id', 78 );
	$theirs = $uploads->finalize( (string) $session['handle'], 78 );

	$runner->assert( is_wp_error( $theirs ), 'cross-user finalize blocked' );
	$runner->same( 'vvai_forbidden', $theirs->get_error_code() );

	vvai_test_set( 'user_id', 77 );
	vvai_test_set( 'caps', array( 'manage_options', 'upload_files', 'vvai_manage', 'vvai_generate' ) );
	$uploads->discard( (string) $session['handle'] );

	@unlink( $part_a );
	@unlink( $part_b );
	@unlink( $source );
} );

// ---------------------------------------------------------------------------
$runner->section( 'No REST path may print a PHP fatal' );

$runner->test( 'an unexpected error becomes JSON and a log line', function () use ( $runner, $plugin ) {
	$api = new class( $plugin ) extends VVAI_Rest_Api {
		/**
		 * A handler that blows up the way a host quirk would.
		 *
		 * @return never
		 */
		public function route_boom() {
			throw new RuntimeException( 'simulated host failure with /var/www/secret-path in it' );
		}
	};

	$guard  = new ReflectionMethod( 'VVAI_Rest_Api', 'guard' );
	$guard->setAccessible( true );

	$callback = $guard->invoke( $api, 'route_boom' );
	$result   = $callback( new VVAI_Test_REST_Request( 'POST', '/vvai/v1/boom' ) );

	$runner->assert( is_wp_error( $result ), 'returned a WP_Error instead of throwing' );
	$runner->same( 'vvai_server_error', $result->get_error_code() );

	$data = (array) $result->get_error_data();

	$runner->same( 500, (int) ( $data['status'] ?? 0 ), 'HTTP 500 so the widget shows it' );
	$runner->assert( false === strpos( $result->get_error_message(), 'secret-path' ), 'no absolute server path in the user-facing message' );

	$log = implode( "\n", $plugin->logger()->tail( 20 ) );

	$runner->contains( 'Rest handler crashed', $log );
		$runner->assert( false === strpos( $log, (string) ABSPATH ), 'the log must not expose the server root' );
		$runner->assert( (bool) preg_match( '/04-ui-contract\.php/u', $log ), 'the log names the file so the host can find it' );
		$runner->assert( (bool) preg_match( '/"line":\d+/u', $log ), 'the log carries a line number' );
} );

$runner->test( 'a WP_Error from a handler keeps its own status', function () use ( $runner, $plugin ) {
	$api = new class( $plugin ) extends VVAI_Rest_Api {
		/**
		 * Handler returning an error the way the real ones do.
		 *
		 * @return WP_Error
		 */
		public function route_soft() {
			return new WP_Error( 'vvai_not_connected', __( 'Please connect an AI provider before processing videos.', 'viral-video-ai' ), array( 'status' => 400 ) );
		}
	};

	$guard  = new ReflectionMethod( 'VVAI_Rest_Api', 'guard' );
	$guard->setAccessible( true );
	$result = $guard->invoke( $api, 'route_soft' )( new VVAI_Test_REST_Request( 'POST', '/x' ) );

	$runner->assert( is_wp_error( $result ), 'pass-through' );
	$runner->same( 'vvai_not_connected', $result->get_error_code() );
	$runner->contains( 'connect an AI provider', $result->get_error_message() );
} );

exit( $runner->summary() );
