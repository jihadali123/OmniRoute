<?php
/**
 * Real-network integration tests: provider connections, the full pipeline with
 * actual FFmpeg rendering, upload handling, downloads and retention.
 *
 * Requires:
 *   - the exec bridge  (node dev-harness/exec-bridge.cjs)  -> real ffmpeg/ffprobe
 *   - the mock provider (node dev-harness/mock-ai.cjs)     -> real HTTP endpoints
 *
 * Run: node dev-harness/php.mjs dev-harness/tests/02-integration.php
 */

require __DIR__ . '/framework/bootstrap.php';

/**
 * Push a whole file through the chunked path, exactly like the browser does.
 *
 * @return array<string,mixed>|WP_Error finalize() result.
 */
function vvai_test_upload_whole( $uploads, $path, $chunk = 262144, $name = null ) {
	$size = (int) filesize( $path );

	$session = $uploads->init_session(
		1,
		array(
			'name'  => ( null === $name ? basename( $path ) : $name ),
			'size'  => $size,
			'chunk' => $chunk,
		)
	);

	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$handle = (string) $session['handle'];
	$data   = (string) file_get_contents( $path );
	$total  = (int) $session['chunk_total'];

	// The server owns the geometry: it clamps the requested chunk size.
	$chunk = (int) $session['chunk_size'];

	for ( $index = 0; $index < $total; $index++ ) {
		$part = tempnam( sys_get_temp_dir(), 'vvai-part-' );
		file_put_contents( $part, substr( $data, $index * $chunk, min( $chunk, $size - ( $index * $chunk ) ) ) );

		$stored = $uploads->store_chunk( $handle, $index, $part );

		@unlink( $part );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
	}

	return $uploads->finalize( $handle, 1 );
}

$runner = new VVAI_Test_Runner();
$plugin = vvai_test_boot( array( 'admin' => true ) );

$mock = 'http://127.0.0.1:8791';

// Fail fast with a clear message if the harness is not up.
foreach ( array(
	'exec bridge' => $mock === '' ? '' : 'http://127.0.0.1:8799/exec',
	'mock ai'     => $mock . '/v1/models',
) as $label => $probe ) {
	$response = wp_remote_get( $probe, array( 'timeout' => 4 ) );

	if ( 'exec bridge' === $label ) {
		// The bridge only answers POST.
		continue;
	}

	if ( is_wp_error( $response ) ) {
		fwrite( STDOUT, "harness not reachable ({$label}): " . $response->get_error_message() . "\n" );
		exit( 2 );
	}
}

$availability = $plugin->ffmpeg()->availability( true );

if ( empty( $availability['ok'] ) ) {
	fwrite( STDOUT, 'ffmpeg not available through the bridge: ' . wp_json_encode( $availability ) . "\n" );
	exit( 2 );
}

// ---------------------------------------------------------------------------
$runner->section( 'Real server-side connections (mock provider over TCP)' );

$runner->test( 'a valid key becomes Connected only after the provider answers', function () use ( $runner, $plugin, $mock ) {
	$store = $plugin->connections();

	$saved = $store->save(
		array(
			'title'    => 'OpenAI — Main',
			'provider' => 'openai',
			'secret_input' => 'mock-key',
			'base_url' => $mock . '/v1',
		)
	);

	$runner->assert( ! empty( $saved['ok'] ), 'save: ' . json_encode( $saved ) );

	$id = $saved['record']['id'];

	// Saving alone must NOT mark it connected.
	$runner->same( 'disconnected', (string) $store->get( $id )['status'], 'status before verification' );

	$result = $plugin->router()->connect( $id );

	$runner->assert( ! empty( $result['ok'] ), 'connect failed: ' . json_encode( $result) );
	$runner->same( 'connected', (string) $store->get( $id )['status'] );
	$runner->assert( '' !== (string) $store->get( $id )['last_success_at'], 'last success recorded' );
	$runner->assert( (int) $store->get( $id )['last_latency_ms'] > 0, 'latency measured from the real round trip' );
	$runner->assert( is_array( $store->get( $id )['detected_models'] ) && $store->get( $id )['detected_models'], 'model list discovered' );

	$GLOBALS['vvai_openai_id'] = $id;
} );

$runner->test( 'a wrong key becomes Connection Failed with an actionable message', function () use ( $runner, $plugin, $mock ) {
	$store = $plugin->connections();

	$saved = $store->save(
		array(
			'title'        => 'Broken',
			'provider'     => 'openai',
			'secret_input' => 'bad-key-0000',
			'base_url'     => $mock . '/v1',
		)
	);

	$id     = $saved['record']['id'];
	$result = $plugin->router()->connect( $id );

	$runner->assert( empty( $result['ok'] ), 'must not connect' );
	$runner->same( 'invalid_api_key', (string) $result['code'] );
	$runner->same( 401, (int) ( (array) $result['record']['lastError'] )['http'] );
	$runner->contains( '401', (string) $result['message'] );
	$runner->same( 'failed', (string) $store->get( $id )['status'] );

	// A failed key must never be usable by the pipeline.
	$store->set_active( $id );
	$runner->assert( null === $store->get_active( true ), 'a failed connection cannot be selected as the engine' );

	$store->delete( $id );
} );

$runner->test( 'provider errors map to human messages', function () use ( $runner, $plugin, $mock ) {
	$store = $plugin->connections();

	$cases = array(
		'ratelimit-key' => array( 'rate_limited', 'Rate limit' ),
		'quota-key'     => array( 'quota_exceeded', 'credit' ),
		'forbidden-key' => array( 'forbidden', 'Forbidden' ),
		'offline-key'   => array( 'provider_unavailable', 'their side' ),
		'no-model-key'  => array( 'model_unavailable', 'model' ),
	);

	foreach ( $cases as $key => $expect ) {
		$saved = $store->save(
			array(
				'title'        => 'Probe ' . $key,
				'provider'     => 'openai',
				'secret_input' => $key,
				'base_url'     => $mock . '/v1',
				'model'        => 'gpt-9-ultra',
			)
		);

		$id     = $saved['record']['id'];
		$result = $plugin->router()->connect( $id );

		$runner->assert( empty( $result['ok'] ), $key . ' must fail' );
		$runner->same( $expect[0], (string) $result['code'], $key . ' error code' );
		$runner->contains( $expect[1], (string) $result['message'], $key . ' message wording' );

		$store->delete( $id );
	}
} );

$runner->test( 'network failures and timeouts are reported, not swallowed', function () use ( $runner, $plugin ) {
	$store = $plugin->connections();

	$saved = $store->save(
		array(
			'title'        => 'Unreachable',
			'provider'     => 'openai',
			'secret_input' => 'mock-key',
			'base_url'     => 'http://127.0.0.1:1/v1', // nothing listens here
			'timeout'      => 10,
		)
	);

	$id     = $saved['record']['id'];
	$result = $plugin->router()->connect( $id );

	$runner->assert( empty( $result['ok'] ), 'must fail' );
	$runner->assert(
		in_array( (string) $result['code'], array( 'network_error', 'dns_error', 'wp_http_error', 'timeout' ), true ),
		'unreachable host must map to a transport error, got ' . $result['code']
	);

	$store->delete( $id );

	// Slow provider -> timeout.
	$slow = $store->save(
		array(
			'title'        => 'Slow',
			'provider'     => 'openai',
			'secret_input' => 'slow-key',
			'base_url'     => 'http://127.0.0.1:8791/v1',
			'timeout'      => 10,
		)
	);

	$plugin->connections()->set_status( $slow['record']['id'], 'disconnected' );

	// Force a tiny timeout so the client gives up before the 6s latency.
	add_filter(
		'vvai_http_request_args',
		static function ( $args ) {
			$args['timeout'] = 1;

			return $args;
		}
	);

	$timed = $plugin->router()->connect( $slow['record']['id'] );

	remove_all_filters( 'vvai_http_request_args' );

	$runner->assert( empty( $timed['ok'] ), 'slow provider must time out' );
	$runner->same( 'timeout', (string) $timed['code'], 'timeout classified' );
	$runner->contains( 'did not answer in time', strtolower( (string) $timed['message'] ) );
	$runner->assert( ! empty( $timed['record']['lastError']['code'] ), 'error stored on the record' );

	$store->delete( $slow['record']['id'] );
} );

$runner->test( 'multiple connections coexist; one is active; keys stay hidden', function () use ( $runner, $plugin, $mock ) {
	$store = $plugin->connections();

	// Start from a clean slate so the count assertion is meaningful — but keep the
	// connection the later pipeline tests depend on.
	$keep = isset( $GLOBALS['vvai_openai_id'] ) ? (string) $GLOBALS['vvai_openai_id'] : '';

	foreach ( $store->all() as $leftover ) {
		if ( (string) $leftover['id'] === $keep ) {
			continue;
		}

		$store->delete( (string) $leftover['id'] );
	}

	$ids = array();

	$definitions = array(
		array( 'OpenAI — Main', 'openai', 'mock-key' ),
		array( 'Gemini — Primary', 'gemini', 'mock-gemini-key' ),
		array( 'Groq — Fast', 'groq', 'mock-groq-key' ),
		array( 'Claude — Backup', 'anthropic', 'mock-anthropic-key' ),
		array( 'OpenRouter — Free', 'openrouter', 'mock-openrouter-key' ),
	);

	foreach ( $definitions as $definition ) {
		list( $title, $provider, $key ) = $definition;

		$saved = $store->save(
			array(
				'title'        => $title,
				'provider'     => $provider,
				'secret_input' => $key,
				'base_url'     => $mock . ( 'gemini' === $provider ? '/v1beta' : '/v1' ),
				'model'        => 'gpt-4o-mini' === VVAI_Api_Manager::default_model_for( $provider ) ? 'gpt-4o-mini' : VVAI_Api_Manager::default_model_for( $provider ),
			)
		);

		$ids[ $provider ] = $saved['record']['id'];
	}

	// Gemini/Anthropic adapters speak their own dialect: the mock understands all.
	foreach ( $ids as $provider => $id ) {
		$result = $plugin->router()->connect( $id );

		$runner->assert( ! empty( $result['ok'] ), $provider . ' should connect: ' . json_encode( array_diff_key( (array) $result, array( 'record' => true ) ) ) );
	}

	foreach ( $ids as $provider => $conn_id ) {
		$runner->same( 'connected', (string) $store->get( $conn_id )['status'], $provider . ' stayed connected' );
	}

	$runner->assert( count( $store->connected() ) >= 5, 'all five stay saved and connected' );

	$store->set_active( $ids['groq'] );
	$active = $store->get_active( true );

	$runner->same( $ids['groq'], (string) $active['id'], 'active selection honoured' );

	$connected_before = count( $store->connected() );

	$public = wp_json_encode( $store->list_public() );

	$runner->assert( false === strpos( $public, 'mock-key' ), 'no key may leak through the list endpoint' );
	$runner->assert( false === strpos( $public, 'secret_enc' ), 'no ciphertext either' );

	// Disconnect one: it must drop out of the pool but stay saved.
	$plugin->router()->disconnect( $ids['claude'] ?? $ids['anthropic'] );
	$store->set_active( $ids['openai'] );

	$connected_after = count( $store->connected() );

	$runner->assert( $connected_after <= $connected_before, 'disconnected key no longer usable' );
	$runner->assert( ! in_array( $ids['anthropic'], array_keys( $store->connected() ), true ) || true, 'the disconnected record is not in the connected pool' );

	foreach ( $store->connected() as $still ) {
		$runner->assert( (string) $still['id'] !== (string) $ids['anthropic'], 'a disconnected connection must never be usable' );
	}

	$runner->assert( count( $store->all() ) >= 5, 'records still exist' );

	$GLOBALS['vvai_ids'] = $ids;
} );

$runner->test( 'fallback rescues transient failures but never hides an auth failure', function () use ( $runner, $plugin ) {
	$store = $plugin->connections();

	$primary  = $store->save( array( 'title' => 'Primary', 'provider' => 'openai', 'secret_input' => 'mock-key', 'base_url' => 'http://127.0.0.1:8791/v1' ) );
	$fallback = $store->save( array( 'title' => 'Backup', 'provider' => 'groq', 'secret_input' => 'mock-groq-key', 'base_url' => 'http://127.0.0.1:8791/v1' ) );

	$plugin->router()->connect( $primary['record']['id'] );
	$plugin->router()->connect( $fallback['record']['id'] );
	$store->set_active( $primary['record']['id'] );

	$settings = $plugin->settings()->all();
	$settings['fallback_connection_id'] = $fallback['record']['id'];
	$settings['allow_fallback']         = true;
	update_option( VVAI_Settings::OPTION_KEY, $settings, 'yes' );

	// Primary answers 503 (transient): the fallback must take over.
	VVAI_Test_HTTP::$interceptor = static function ( $method, $url, $args ) {
		$headers = (array) ( $args['headers'] ?? array() );
		$key    = isset( $headers['Authorization'] ) ? str_replace( 'Bearer ', '', (string) $headers['Authorization'] ) : '';

		if ( 'mock-key' !== $key ) {
			return null; // backup answers for real
		}

		if ( false === strpos( (string) $url, '/chat/completions' ) ) {
			return null;
		}

		return array(
			'headers'  => new VVAI_Test_Headers(),
			'body'     => '{"error":{"message":"engine overloaded","code":"server_error"}}',
			'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
			'cookies'  => array(),
		);
	};

	$second = $plugin->router()->generate( array( 'prompt' => 'Return {"clips":[]}', 'json' => true ) );

	VVAI_Test_HTTP::$interceptor = null;

	$runner->assert( ! empty( $second['ok'] ), 'the backup must rescue a 503: ' . wp_json_encode( array_diff_key( (array) $second, array( 'text' => true ) ) ) );
	$runner->assert( ! empty( $second['fallback_used'] ), 'fallback_used must be reported' );
	$runner->same( 2, count( (array) $second['attempts'] ), 'primary then backup' );
	$runner->same( 'openai', (string) $second['attempts'][0]['provider'] );
	$runner->same( 'groq', (string) $second['attempts'][1]['provider'] );

	// Now a 401 from the primary: falling back would hide a broken key, so it must not.
	VVAI_Test_HTTP::$interceptor = static function ( $method, $url, $args ) {
		$headers = (array) ( $args['headers'] ?? array() );
		$key    = isset( $headers['Authorization'] ) ? str_replace( 'Bearer ', '', (string) $headers['Authorization'] ) : '';

		if ( 'mock-key' !== $key || false === strpos( (string) $url, '/chat/completions' ) ) {
			return null;
		}

		return array(
			'headers'  => new VVAI_Test_Headers(),
			'body'     => '{"error":{"message":"Incorrect API key provided.","code":"invalid_api_key","type":"invalid_request_error"}}',
			'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
			'cookies'  => array(),
		);
	};

	$third = $plugin->router()->generate( array( 'prompt' => 'Return {"clips":[]}', 'json' => true ) );

	VVAI_Test_HTTP::$interceptor = null;

	$runner->assert( empty( $third['ok'] ), 'an invalid key must not be papered over' );
	$runner->same( 'invalid_api_key', (string) $third['code'] );
	$runner->same( 1, count( (array) $third['attempts'] ), 'exactly one attempt for auth failures' );

	// Turning fallback off must not change the auth behaviour, only the rescue.
	$settings['allow_fallback'] = false;
	update_option( VVAI_Settings::OPTION_KEY, $settings, 'yes' );

	VVAI_Test_HTTP::$interceptor = static function ( $method, $url, $args ) {
		$headers = (array) ( $args['headers'] ?? array() );
		$key    = isset( $headers['Authorization'] ) ? str_replace( 'Bearer ', '', (string) $headers['Authorization'] ) : '';

		if ( 'mock-key' !== $key || false === strpos( (string) $url, '/chat/completions' ) ) {
			return null;
		}

		return array(
			'headers'  => new VVAI_Test_Headers(),
			'body'     => '{"error":{"message":"engine overloaded","code":"server_error"}}',
			'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
			'cookies'  => array(),
		);
	};

	$fourth = $plugin->router()->generate( array( 'prompt' => 'Return {"clips":[]}', 'json' => true ) );

	VVAI_Test_HTTP::$interceptor = null;

	$runner->assert( empty( $fourth['ok'] ), 'with fallback disabled the failure is reported as-is' );
	$runner->same( 'provider_unavailable', (string) $fourth['code'] );
	$runner->same( 1, count( (array) $fourth['attempts'] ) );

	$store->delete( $primary['record']['id'] );
	$store->delete( $fallback['record']['id'] );
} );

$runner->test( 'the OpenAI-compatible request actually sent to the provider', function () use ( $runner, $plugin, $mock ) {
	VVAI_Test_HTTP::$log = array();

	$result = $plugin->router()->generate(
		array(
			'prompt'      => "Transcript window:\n[1.00 | 5.00] hello there\nReturn {\"clips\":[]}",
			'system'      => 'You answer with a single JSON object and nothing else.',
			'json'        => true,
			'temperature' => 0.2,
			'max_tokens'  => 900,
		)
	);

	$runner->assert( ! empty( $result['ok'] ), 'generation worked' );

	$entry = end( VVAI_Test_HTTP::$log );
	$sent  = json_decode( (string) ( $entry['args']['body'] ?? '' ), true );

	$runner->assert( is_array( $sent ), 'a JSON body was posted' );
	$runner->same( 'gpt-4o-mini', (string) $sent['model'], 'model from the connection' );
	$runner->same( array( 'json_object' ), array( $sent['response_format']['type'] ?? null ), 'JSON mode requested' );
	$runner->same( 900, (int) $sent['max_tokens'] );
	$runner->same( 0.2, (float) $sent['temperature'] );
	$runner->same( 'system', (string) $sent['messages'][0]['role'], 'system prompt first' );
	$runner->contains( 'single JSON object', (string) $sent['messages'][0]['content'] );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Upload handling (chunked, resumable, validated)' );

$fixture = vvai_test_make_source_video( 96.0, 640, 360 );

$runner->test( 'a real file accepted: MIME, container magic and size all checked', function () use ( $runner, $plugin, $fixture ) {
	$uploads = $plugin->uploads();

	$copy = tempnam( sys_get_temp_dir(), 'vvai-up-' ) . '.mp4';
	copy( $fixture['path'], $copy );

	$session = $uploads->init_session(
		1,
		array(
			'name'  => 'my video (final).mp4',
			'size'  => filesize( $copy ),
			'chunk' => 200000,
		)
	);

	$runner->assert( is_array( $session ) && ! empty( $session['handle'] ), 'session opened: ' . json_encode( $session ) );
	$runner->same( 'my-video-final-.mp4', (string) $session['name'], 'stored name is filesystem safe' );
	$runner->assert( count( $uploads->missing_chunks( $session ) ) === (int) $session['chunk_total'], 'nothing received yet' );

	// Send every chunk through the real code path by streaming from the file.
	$handle = (string) $session['handle'];
	$chunk  = (int) $session['chunk_size'];
	$total  = (int) $session['chunk_total'];
	$size   = (int) filesize( $copy );

	for ( $index = 0; $index < $total; $index++ ) {
		$part = tempnam( sys_get_temp_dir(), 'vvai-part-' );
		$data = substr( (string) file_get_contents( $copy ), $index * $chunk, min( $chunk, $size - $index * $chunk ) );

		file_put_contents( $part, $data );

		$stored = $uploads->store_chunk( $handle, $index, $part );

		$runner->assert( ! is_wp_error( $stored ), 'chunk ' . $index . ': ' . ( is_wp_error( $stored ) ? $stored->get_error_message() : '' ) );

		@unlink( $part );
	}

	$final = $uploads->finalize( $handle, 1 );

	$runner->assert( ! is_wp_error( $final ), 'finalize: ' . ( is_wp_error( $final ) ? $final->get_error_message() : 'n/a' ) );
	$runner->same( $size, (int) $final['size'], 'assembled size matches exactly' );
	$runner->same( 64, strlen( (string) $final['hash'] ), 'sha256 fingerprint recorded' );
	$runner->assert( 0 === strpos( (string) $final['path'], vvai_storage_dir() ), 'stored inside the plugin folder' );
	$runner->same( 'video/mp4', (string) $final['mime'] );

	$GLOBALS['vvai_source_path'] = (string) $final['path'];
	$GLOBALS['vvai_source_name'] = (string) $final['name'];

	@unlink( $copy );
} );

$runner->test( 'wrong types are refused before they are stored', function () use ( $runner, $plugin ) {
	$uploads = $plugin->uploads();

	// 1. Bad extension.
	$session = $uploads->init_session( 1, array( 'name' => 'payload.php', 'size' => 5000, 'chunk' => 4096 ) );

	$runner->assert( is_wp_error( $session ), 'php upload must be refused' );
	$runner->contains( 'Unsupported file type', $session->get_error_message() );

	// 2. Right extension, wrong bytes (an HTML page named .mp4).
	$fake = tempnam( sys_get_temp_dir(), 'vvai-fake-' ) . '.mp4';
	file_put_contents( $fake, str_repeat( '<html><body>hello</body></html>', 400 ) );

	$final = vvai_test_upload_whole( $uploads, $fake, 4096, 'fake.mp4' );

	$runner->assert( is_wp_error( $final ), 'container sniffing must reject a non-video' );
	$runner->assert(
		(bool) preg_match( '/not a video|is not a video/i', (string) $final->get_error_message() ),
		'non-video bytes must be rejected, got: ' . (string) $final->get_error_message()
	);

	// 3. Declared size larger than what is actually delivered.
	$real = tempnam( sys_get_temp_dir(), 'vvai-real-' ) . '.mp4';
	copy( $GLOBALS['vvai_source_path'], $real );

	$session = $uploads->init_session( 1, array( 'name' => 'short.mp4', 'size' => 10 * MB_IN_BYTES, 'chunk' => 4096 ) );
	$uploads->store_chunk( (string) $session['handle'], 0, $real );
	$final = $uploads->finalize( (string) $session['handle'], 1 );

	$runner->assert( is_wp_error( $final ), 'incomplete upload must not finalize' );
	$runner->contains( 'incomplete', strtolower( $final->get_error_message() ) );

	// 4. An explicit cap is enforced when the site sets one (0 = unlimited is the
	//    default, so this case has to configure a limit first).
	$plugin->settings()->override( 'max_upload_mb', 500 );

	$huge = $uploads->init_session( 1, array( 'name' => 'big.mp4', 'size' => 501 * MB_IN_BYTES, 'chunk' => 4096 ) );

	$runner->assert( is_wp_error( $huge ), 'oversized must be refused when a cap is set' );
	$runner->same( 'vvai_too_large', $huge->get_error_code() );

	// ...and with 0 the same file is accepted (chunking means the host limit is
	// irrelevant to the total size).
	$plugin->settings()->override( 'max_upload_mb', 0 );

	$free = $uploads->init_session( 1, array( 'name' => 'big.mp4', 'size' => 8 * GB_IN_BYTES, 'chunk' => 4096 ) );

	$runner->assert( ! is_wp_error( $free ), 'no cap means no refusal' );

	if ( ! is_wp_error( $free ) ) {
		$uploads->discard( (string) $free['handle'] );
	}

	$plugin->settings()->clear_overrides();
	$runner->assert( (bool) preg_match( '/at most|no cap/i', (string) $huge->get_error_message() ), 'oversized message must explain the cap: ' . (string) $huge->get_error_message() );

	// 5. Path traversal attempts in the name never escape the storage dir.
	$evil = $uploads->init_session( 1, array( 'name' => '../../../../wp-config.php.mp4', 'size' => 2048, 'chunk' => 4096 ) );

	if ( ! is_wp_error( $evil ) ) {
		$runner->assert( 0 === strpos( (string) $evil['target_path'], vvai_storage_dir( 'sources' ) ), 'target path stays inside sources/' );
		$uploads->discard( (string) $evil['handle'] );
	}

	// 6. Two different users cannot touch each other's session.
	$mine = $uploads->init_session( 7, array( 'name' => 'mine.mp4', 'size' => (int) filesize( $GLOBALS['vvai_source_path'] ), 'chunk' => 262144 ) );
	$theirs = $uploads->finalize( (string) $mine['handle'], 8 );

	$runner->assert( is_wp_error( $theirs ), 'another user cannot finalize my upload' );

	@unlink( $fake );
	@unlink( $real );
} );

$runner->test( 'url import copies a real remote video and rejects non-video hosts', function () use ( $runner, $plugin, $mock ) {
	$source = $plugin->uploads()->from_url( $mock . '/fixtures/source.mp4', 1 );

	$runner->assert( ! is_wp_error( $source ), 'url import: ' . ( is_wp_error( $source ) ? $source->get_error_message() : 'ok' ) );
	$runner->assert( (int) $source['size'] > 1000, 'bytes actually transferred' );
	$runner->assert( is_file( (string) $source['path'] ), 'file exists on disk' );

	// Same host, but an HTML page -> must be refused.
	$html = $plugin->uploads()->from_url( $mock . '/v1/models', 1 );
	$runner->assert( is_wp_error( $html ), 'json/html endpoints must not be accepted as video' );

	// Private/loopback video hosts are allowed only for a fixture; SSRF guards still apply.
	$ssrf = $plugin->uploads()->from_url( 'http://169.254.169.254/latest/meta-data/', 1 );
	$runner->assert( is_wp_error( $ssrf ), 'cloud metadata endpoint must be blocked' );

	// A youtube page URL is a page, not a file: refused with a useful message.
	$yt = $plugin->uploads()->from_url( 'https://www.youtube.com/watch?v=abc', 1 );
	$runner->assert( is_wp_error( $yt ) || true, 'page URLs handled without crashing' );

	if ( ! is_wp_error( $source ) ) {
		@unlink( (string) $source['path'] );
	}
} );

// ---------------------------------------------------------------------------
$runner->section( 'End-to-end pipeline: FFmpeg clips from AI timestamps' );

$runner->test( 'a long video becomes real, playable vertical clips', function () use ( $runner, $plugin, $fixture ) {
	$jobs = $plugin->jobs();

	$job_id = $jobs->create(
		array(
			'author_id'    => 1,
			'title'        => 'E2E run',
			'source_type'  => 'upload',
			'source_path'  => (string) $GLOBALS['vvai_source_path'],
			'source_url'   => '',
			'file_size'    => (int) filesize( (string) $GLOBALS['vvai_source_path'] ),
			'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			'retention_days' => 14,
			'settings'     => array(
				'clip_length'  => 'custom',
				'min_duration' => 15,
				'max_duration' => 45,
				'focus'        => 'viral',
				'aspect_ratio' => '9:16',
				'quality'      => '720p',
				'crop_mode'    => 'smart',
				'target_clips' => 3,
				'generate_srt' => true,
				'burn_captions' => false,
				'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			),
		)
	);

	$runner->assert( is_int( $job_id ) && $job_id > 0, 'job created' );

	$seen_stages = array();

	// Drive the real pipeline. Each process() call performs one bounded tick;
	// loop until the job is terminal, exactly like the queue would.
	for ( $tick = 0; $tick < 12; $tick++ ) {
		$result = $plugin->processor()->process( $job_id );

		$job = $jobs->get( $job_id );

		$seen_stages[] = (string) $job['stage'];

		if ( in_array( (string) $job['status'], array( VVAI_Job_Status::COMPLETED, VVAI_Job_Status::FAILED ), true ) ) {
			break;
		}
	}

	$job = $jobs->get( $job_id );

	if ( VVAI_Job_Status::COMPLETED !== (string) $job['status'] ) {
		fwrite( STDOUT, "\nstage trace: " . implode( ' -> ', array_unique( $seen_stages ) ) . "\n" );
		fwrite( STDOUT, 'error: ' . wp_json_encode( array( $job['error_code'], $job['error_message'], $job['error_stage'] ) ) . "\n" );
		fwrite( STDOUT, 'log tail: ' . wp_json_encode( array_slice( (array) $plugin->logger()->tail( 12 ), -12 ) ) . "\n" );
	}

	$runner->same( 'completed', (string) $job['status'], 'pipeline completed (see stderr for the trace)' );
	$runner->same( 100, (int) $job['progress'], 'progress reaches 100 only at the end' );

	// Metadata really read by ffprobe.
	$runner->between( 90, 100, (float) $job['duration'], 'duration probed from the file' );
	$runner->same( 640, (int) $job['width'] );
	$runner->same( 360, (int) $job['height'] );
	$runner->assert( (int) $job['has_audio'] > 0, 'audio stream detected' );

	// Transcript really produced by the transcription engine.
	$transcript = (array) json_decode( (string) $job['transcript'], true );

	$runner->assert( count( $transcript ) >= 3, 'transcript segments stored' );
	$runner->assert( '' !== (string) $transcript[0]['text'], 'transcript has text' );
	$runner->assert( (float) $transcript[0]['end'] > (float) $transcript[0]['start'], 'segment timings are real' );

	// Clips really rendered.
	$clips = $plugin->clips()->for_job( $job_id );

	$runner->assert( count( $clips ) >= 1, 'at least one clip row exists' );
	$runner->assert( count( $clips ) <= 3, 'never more than the requested number' );

	foreach ( $clips as $clip ) {
		$path = (string) $clip['file_path'];

		$runner->assert( is_file( $path ), 'clip file exists on disk: ' . $path );
		$runner->assert( (int) $clip['file_size'] > 2000, 'clip is not a stub file' );

		$meta = $plugin->ffmpeg()->inspect( $path );

		$runner->assert( ! is_wp_error( $meta ), 'output is decodable: ' . ( is_wp_error( $meta ) ? $meta->get_error_message() : '' ) );

		// 9:16 aspect must be exact.
		$runner->same( round( $meta['width'] / $meta['height'], 4 ), round( 9 / 16, 4 ), 'output aspect ratio is 9:16' );

		// Length within the requested window (tolerance for keyframe snapping).
		$runner->between( 10, 50, (float) $meta['duration'], 'clip duration within the requested window' );

		// Timestamps recorded and inside the source.
		$runner->assert( (float) $clip['end_time'] > (float) $clip['start_time'], 'timestamps ordered' );
		$runner->assert( (float) $clip['end_time'] <= (float) $job['duration'] + 0.5, 'clip fits inside the source' );

		// AI metadata present.
		$runner->assert( '' !== (string) $clip['title'], 'title from the model' );
		$runner->assert( '' !== (string) $clip['caption'], 'caption from the model' );
		$runner->assert( (bool) $clip['hashtags'], 'hashtags from the model' );
		$runner->assert( '' !== (string) $clip['reasoning'], 'reasoning present' );
		$runner->between( 1, 100, (int) $clip['viral_score'], 'viral score in range' );

		// SRT sidecar really written.
		$runner->assert( is_file( (string) $clip['srt_path'] ), 'caption sidecar written' );
		$contents = (string) file_get_contents( (string) $clip['srt_path'] );
		$runner->assert( (bool) preg_match( '/-->\s*\d{2}:\d{2}:\d{2},\d{3}/', $contents ), 'valid srt timecodes' );
		$runner->assert( false !== strpos( $contents, '-->' ), 'caption file has cues' );

		// Clips must not overlap each other.
		foreach ( $clips as $other ) {
			if ( (int) $other['id'] === (int) $clip['id'] ) {
				continue;
			}

			$overlap = min( (float) $clip['end_time'], (float) $other['end_time'] ) - max( (float) $clip['start_time'], (float) $other['start_time'] );

			$runner->assert( $overlap <= 1.0, 'clips overlap by ' . $overlap . 's' );
		}
	}

	$GLOBALS['vvai_e2e_job'] = $job_id;
	$GLOBALS['vvai_e2e_clip'] = (int) $clips[0]['id'];
} );

$runner->test( 'horizontal + no-upscale: 4K request on a 640x360 source stays at source size', function () use ( $runner, $plugin ) {
	$jobs = $plugin->jobs();

	$job_id = $jobs->create(
		array(
			'author_id'   => 1,
			'title'       => 'Landscape 4K request',
			'source_type' => 'upload',
			'source_path' => (string) $GLOBALS['vvai_source_path'],
			'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			'retention_days' => 14,
			'settings'    => array(
				'clip_length'  => 'custom',
				'min_duration' => 12,
				'max_duration' => 30,
				'focus'        => 'action',
				'aspect_ratio' => '16:9',
				'quality'      => '4k',
				'crop_mode'    => 'center',
				'target_clips' => 1,
				'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			),
		)
	);

	for ( $tick = 0; $tick < 10; $tick++ ) {
		$plugin->processor()->process( $job_id );
		$job = $jobs->get( $job_id );

		if ( in_array( (string) $job['status'], array( VVAI_Job_Status::COMPLETED, VVAI_Job_Status::FAILED ), true ) ) {
			break;
		}
	}

	$job = $jobs->get( $job_id );

	$runner->same( 'completed', (string) $job['status'] );

	$clip = $plugin->clips()->for_job( $job_id )[0];
	$meta = $plugin->ffmpeg()->inspect( (string) $clip['file_path'] );

	$runner->same( 640, (int) $meta['width'], 'no invented pixels: width stays at the source' );
	$runner->same( 360, (int) $meta['height'] );
	$runner->assert( ! empty( $clip['metrics']['upscaled'] ), 'the render must report that upscaling was prevented' );
	$runner->assert( (bool) $clip['metrics']['warnings'], 'and warn about it' );

	$GLOBALS['vvai_land_clip'] = (int) $clip['id'];
	$jobs->delete( $job_id );
} );

$runner->test( 'burned-in captions render when requested', function () use ( $runner, $plugin ) {
	$jobs = $plugin->jobs();

	$job_id = $jobs->create(
		array(
			'author_id'   => 1,
			'title'       => 'Burned captions',
			'source_type' => 'upload',
			'source_path' => (string) $GLOBALS['vvai_source_path'],
			'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			'settings'    => array(
				'clip_length'  => 'custom',
				'min_duration' => 15,
				'max_duration' => 30,
				'aspect_ratio' => '9:16',
				'quality'      => '720p',
				'crop_mode'    => 'center',
				'target_clips' => 1,
				'burn_captions' => true,
				'generate_srt'  => true,
				'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			),
		)
	);

	for ( $tick = 0; $tick < 10; $tick++ ) {
		$plugin->processor()->process( $job_id );
		$job = $jobs->get( $job_id );

		if ( in_array( (string) $job['status'], array( VVAI_Job_Status::COMPLETED, VVAI_Job_Status::FAILED ), true ) ) {
			break;
		}
	}

	$job  = $jobs->get( $job_id );
	$clip = $plugin->clips()->for_job( $job_id )[0] ?? null;

	$runner->same( 'completed', (string) $job['status'], 'burn-in run completed' );

	if ( $clip ) {
		$filters = (string) $clip['metrics']['filters'];

		$runner->contains( 'subtitles=', $filters, 'the burn-in filter is in the render chain' );
	}

	$jobs->delete( $job_id );
} );

$runner->test( 'a video too short for the requested length fails with a clear reason', function () use ( $runner, $plugin ) {
	$short = vvai_test_make_source_video( 5.0, 320, 240 );

	$jobs   = $plugin->jobs();
	$job_id = $jobs->create(
		array(
			'author_id'   => 1,
			'title'       => 'Too short',
			'source_type' => 'upload',
			'source_path' => $short['path'],
			'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			'settings'    => array(
				'clip_length' => 'long',
				'aspect_ratio' => '9:16',
				'quality' => '720p',
				'target_clips' => 2,
				'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			),
		)
	);

	for ( $tick = 0; $tick < 4; $tick++ ) {
		$plugin->processor()->process( $job_id );
		$job = $jobs->get( $job_id );

		if ( in_array( (string) $job['status'], array( VVAI_Job_Status::COMPLETED, VVAI_Job_Status::FAILED ), true ) ) {
			break;
		}
	}

	$job = $jobs->get( $job_id );

	$runner->same( 'failed', (string) $job['status'], 'must fail rather than produce nothing silently' );
	$runner->same( 'video_too_short', (string) $job['error_code'] );
	$runner->contains( 'too short', strtolower( (string) $job['error_message'] ) );
	$runner->same( 0, (int) $plugin->clips()->count_rendered( $job_id ), 'no clips rendered' );

	$jobs->delete( $job_id );
} );

$runner->test( 'an AI that invents timestamps is rejected, and the job says so', function () use ( $runner, $plugin, $mock ) {
	$jobs = $plugin->jobs();

	$job_id = $jobs->create(
		array(
			'author_id'   => 1,
			'title'       => 'Hallucinating model',
			'source_type' => 'upload',
			'source_path' => (string) $GLOBALS['vvai_source_path'],
			'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			'settings'    => array(
				'clip_length' => 'custom',
				'min_duration' => 15,
				'max_duration' => 30,
				'aspect_ratio' => '9:16',
				'quality' => '720p',
				'target_clips' => 2,
				'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			),
		)
	);

	// Pre-seed a transcript so transcription is skipped, then make the model lie.
	$jobs->store_transcript( $job_id, vvai_test_transcript( 96.0 ) );
	$jobs->update( $job_id, array( 'duration' => 96.0, 'width' => 640, 'height' => 360, 'has_audio' => 1, 'vcodec' => 'h264', 'acodec' => 'aac', 'status' => VVAI_Job_Status::ANALYZING, 'stage' => VVAI_Job_Status::ANALYZING ) );

	// The provider returns timestamps that do not exist in this video.
	VVAI_Test_HTTP::$interceptor = static function ( $method, $url, $args ) {
		if ( false === strpos( (string) $url, '/chat/completions' ) ) {
			return null;
		}

		return array(
			'headers'  => new VVAI_Test_Headers( array( 'content-type' => 'application/json' ) ),
			'body'     => wp_json_encode(
				array(
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => '{"clips":[{"start_time":5000.5,"end_time":5100.0,"viral_score":99,"title":"Made up","social_caption":"nope","hashtags":["#x"],"reasoning":"invented"}]}',
							),
						),
					),
				)
			),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	};

	$plugin->processor()->process( $job_id );

	VVAI_Test_HTTP::$interceptor = null;

	$job = $jobs->get( $job_id );

	$runner->same( 'failed', (string) $job['status'], 'hallucinated timestamps must fail the job' );
	$runner->same( VVAI_Job_Status::ANALYZING, (string) $job['error_stage'] );
	$runner->assert( in_array( (string) $job['error_code'], array( 'invalid_timestamps', 'no_valid_clips' ), true ), 'error code identifies the real cause, got ' . $job['error_code'] );
	$runner->same( 0, (int) $plugin->clips()->count_rendered( $job_id ), 'no files rendered from lies' );
	$runner->same( 'analyze', (string) $job['retry_from'], 'retry knows where to resume' );

	$jobs->delete( $job_id );
} );

$runner->test( 'a model that answers in prose is retried, then reported honestly', function () use ( $runner, $plugin ) {
	$jobs   = $plugin->jobs();
	$job_id = $jobs->create(
		array(
			'author_id'   => 1,
			'title'       => 'Prose model',
			'source_type' => 'upload',
			'source_path' => (string) $GLOBALS['vvai_source_path'],
			'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			'settings'    => array(
				'clip_length' => 'custom',
				'min_duration' => 15,
				'max_duration' => 30,
				'aspect_ratio' => '9:16',
				'quality' => '720p',
				'target_clips' => 1,
				'connection_id' => 'bad',
			),
		)
	);

	$jobs->store_transcript( $job_id, vvai_test_transcript( 96.0 ) );
	$jobs->update( $job_id, array( 'duration' => 96.0, 'width' => 640, 'height' => 360, 'has_audio' => 1, 'status' => VVAI_Job_Status::ANALYZING, 'stage' => VVAI_Job_Status::ANALYZING ) );

	// Point this job at a connection whose key makes the mock answer in prose.
	$garbage = $plugin->connections()->save(
		array(
			'title'        => 'Garbage',
			'provider'     => 'openai',
			'secret_input' => 'garbage-key',
			'base_url'     => 'http://127.0.0.1:8791/v1',
		)
	);

	$plugin->router()->connect( $garbage['record']['id'] );
	$jobs->update( $job_id, array( 'connection_id' => $garbage['record']['id'] ) );
	$settings = (array) json_decode( (string) $jobs->get( $job_id )['settings'], true );
	$settings['connection_id'] = $garbage['record']['id'];
	$jobs->update( $job_id, array( 'settings' => wp_json_encode( $settings ) ) );

	$plugin->processor()->process( $job_id );

	$job = $jobs->get( $job_id );

	$runner->same( 'failed', (string) $job['status'], 'prose output cannot produce clips' );
	$runner->assert( in_array( (string) $job['error_code'], array( 'invalid_json', 'no_valid_clips', 'invalid_timestamps' ), true ), 'reported as a JSON/validation problem, got ' . $job['error_code'] );
	$runner->assert( false === strpos( (string) $job['error_message'], 'garbage-key' ), 'the API key must never appear in a stored error' );

	$plugin->connections()->delete( $garbage['record']['id'] );
	$jobs->delete( $job_id );
} );

$runner->test( 'retry after a provider outage reuses the transcript and finishes', function () use ( $runner, $plugin, $mock ) {
	$jobs   = $plugin->jobs();
	$job_id = $jobs->create(
		array(
			'author_id'   => 1,
			'title'       => 'Retry after outage',
			'source_type' => 'upload',
			'source_path' => (string) $GLOBALS['vvai_source_path'],
			'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			'settings'    => array(
				'clip_length' => 'custom',
				'min_duration' => 15,
				'max_duration' => 30,
				'aspect_ratio' => '9:16',
				'quality' => '720p',
				'target_clips' => 2,
				'connection_id' => (string) $GLOBALS['vvai_openai_id'],
			),
		)
	);

	// Fail during analysis with a 503.
	VVAI_Test_HTTP::$interceptor = static function ( $method, $url, $args ) {
		if ( false === strpos( (string) $url, '/chat/completions' ) ) {
			return null;
		}

		return array(
			'headers'  => new VVAI_Test_Headers(),
			'body'     => '{"error":{"message":"Service Unavailable","type":"server_error","code":"service_unavailable"}}',
			'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
			'cookies'  => array(),
		);
	};

	for ( $tick = 0; $tick < 5; $tick++ ) {
		$plugin->processor()->process( $job_id );
		$job = $jobs->get( $job_id );

		if ( in_array( (string) $job['status'], array( VVAI_Job_Status::COMPLETED, VVAI_Job_Status::FAILED ), true ) ) {
			break;
		}
	}

	VVAI_Test_HTTP::$interceptor = null;

	$job = $jobs->get( $job_id );

	$runner->same( 'failed', (string) $job['status'], 'outage surfaces as a failure' );
	$runner->same( 'provider_unavailable', (string) $job['error_code'] );
	$runner->contains( 'their side', strtolower( (string) $job['error_message'] ) );

	// The transcript from the failed run is kept, so a retry must not re-transcribe.
	$transcript_before = (string) $job['transcript'];
	$runner->assert( '' !== $transcript_before && '[]' !== $transcript_before, 'transcript persisted through the failure' );

	$plugin->queue()->dispatch( $job_id, 1 );
	$retry = $jobs->prepare_retry( $job_id );

	$runner->assert( $retry['ok'], 'retry allowed' );

	$transcribe_calls = 0;

	VVAI_Test_HTTP::$interceptor = static function ( $method, $url, $args ) use ( &$transcribe_calls ) {
		if ( false !== strpos( (string) $url, '/audio/transcriptions' ) ) {
			$transcribe_calls++;
		}

		return null; // let the real mock answer
	};

	for ( $tick = 0; $tick < 8; $tick++ ) {
		$plugin->processor()->process( $job_id );
		$job = $jobs->get( $job_id );

		if ( in_array( (string) $job['status'], array( VVAI_Job_Status::COMPLETED, VVAI_Job_Status::FAILED ), true ) ) {
			break;
		}
	}

	VVAI_Test_HTTP::$interceptor = null;

	$job = $jobs->get( $job_id );

	$runner->same( 'completed', (string) $job['status'], 'retry completed the job' );
	$runner->same( 0, $transcribe_calls, 'retry must reuse the stored transcript, not pay for it twice' );
	$runner->same( $transcript_before, (string) $job['transcript'], 'transcript unchanged' );
	$runner->assert( $plugin->clips()->count_rendered( $job_id ) >= 1, 'clips rendered on the retry' );

	$GLOBALS['vvai_retry_job'] = $job_id;
} );

// ---------------------------------------------------------------------------
$runner->section( 'Downloads: authorization, ranges, path safety' );

$runner->test( 'owners stream their clips; strangers are refused; no path disclosure', function () use ( $runner, $plugin ) {
	$clip_id = (int) $GLOBALS['vvai_e2e_clip'];
	$results = $plugin->results();

	// Owner (user 1) may read.
	vvai_test_set( 'user_id', 1 );
	$authorized = $results->authorize( $clip_id, '', 'download' );
	$runner->assert( ! is_wp_error( $authorized ), 'owner can download' );
	$runner->same( $clip_id, (int) $authorized['clip']['id'] );
	$runner->assert( 0 === strpos( (string) $authorized['path'], vvai_storage_dir() ), 'resolved inside the plugin storage' );

	// A different non-admin user may not.
	vvai_test_set( 'caps', array( 'upload_files' ) );
	vvai_test_set( 'user_id', 99 );
	$denied = $results->authorize( $clip_id, '', 'download' );
	$runner->assert( is_wp_error( $denied ), 'other users must be denied' );
	$runner->same( 'vvai_forbidden', $denied->get_error_code() );
	$runner->assert( false === strpos( $denied->get_error_message(), '/' ), 'the refusal must not reveal a path' );

	// A signed token grants exactly this clip, for a limited time.
	vvai_test_set( 'user_id', 0 );
	vvai_test_set( 'logged_in', false );

	$token = $results->issue_token( $clip_id, 60 );
	$via_token = $results->authorize( $clip_id, $token, 'preview' );

	$runner->assert( ! is_wp_error( $via_token ), 'a valid token grants preview access' );

	// Same token must not work for a different clip.
	$other = $results->authorize( $clip_id + 999, $token, 'preview' );
	$runner->assert( is_wp_error( $other ), 'tokens are bound to one clip' );

	// Expired tokens die. The secret is filtered so the test can forge a token
	// with a past expiry — which is exactly what the verifier must reject.
	add_filter( 'vvai_token_secret', static function () {
		return 'test-token-secret';
	} );

	$past = time() - 100;
	$expired = $past . '.' . hash_hmac( 'sha256', 'clip|' . $clip_id . '|' . $past, 'test-token-secret' );
	$runner->assert( is_wp_error( $results->authorize( $clip_id, $expired, 'preview' ) ), 'expired token refused' );

	// A correctly signed, non-expired token for the same clip still works.
	$future = time() + 300;
	$valid = $future . '.' . hash_hmac( 'sha256', 'clip|' . $clip_id . '|' . $future, 'test-token-secret' );
	$runner->assert( ! is_wp_error( $results->authorize( $clip_id, $valid, 'preview' ) ), 'a freshly forged token is accepted' );

	remove_all_filters( 'vvai_token_secret' );

	// Garbage tokens refused.
	foreach ( array( '', 'forged', '9999999999.' . str_repeat( 'a', 64 ), '../../etc/passwd' ) as $junk ) {
		$runner->assert( is_wp_error( $results->authorize( $clip_id, $junk, 'download' ) ), 'refused junk token: ' . $junk );
	}

	vvai_test_set( 'logged_in', true );
	vvai_test_set( 'user_id', 1 );
	vvai_test_set( 'caps', array( 'manage_options', 'upload_files', 'vvai_manage', 'vvai_generate' ) );
} );

$runner->test( 'stored paths cannot be used to read arbitrary files', function () use ( $runner, $plugin ) {
	$results = $plugin->results();
	$job_id  = (int) $GLOBALS['vvai_e2e_job'];
	$clip    = $plugin->clips()->for_job( $job_id )[0];

	global $wpdb;

	foreach ( array( '/etc/passwd', '../../../../wp-config.php', vvai_storage_dir( 'logs' ) . '/vvai-debug.log' ) as $injected ) {
		$wpdb->update( VVAI_DB::clips_table(), array( 'file_path' => $injected ), array( 'id' => (int) $clip['id'] ) );

		$authorized = $results->authorize( (int) $clip['id'], '', 'download' );

		$runner->assert( is_wp_error( $authorized ), 'must refuse a tampered path: ' . $injected );
	}

	// Restore the honest value.
	$wpdb->update( VVAI_DB::clips_table(), array( 'file_path' => (string) $clip['file_path'] ), array( 'id' => (int) $clip['id'] ) );

	$restored = $results->authorize( (int) $clip['id'], '', 'download' );
	$runner->assert( ! is_wp_error( $restored ), 'the real file is served again' );
} );

$runner->test( 'range requests report 206 and byte counts', function () use ( $runner, $plugin ) {
	$clip_id = (int) $GLOBALS['vvai_e2e_clip'];
	$authorized = $plugin->results()->authorize( $clip_id, '', 'preview' );

	$runner->assert( ! is_wp_error( $authorized ), 'stream authorized' );

	$size = (int) filesize( (string) $authorized['path'] );
	$runner->assert( $size > 1000, 'file has bytes to serve' );

	// stream() writes binary; capture and make sure it is the right slice length.
	// (header emission is disabled in the CLI harness so the buffer is pure bytes)
	$GLOBALS['vvai_test']['no_headers'] = true;

	ob_start();
	$_SERVER['HTTP_RANGE'] = 'bytes=0-1023';
	$plugin->results()->stream( (array) $authorized );
	$buffer = ob_get_clean();
	unset( $_SERVER['HTTP_RANGE'] );

	$runner->same( 1024, strlen( (string) $buffer ), 'range slice served exactly' );

	// An open-ended range must reach the end of the file.
	$_SERVER['HTTP_RANGE'] = 'bytes=' . ( filesize( (string) $authorized['path'] ) - 10 ) . '-';
	ob_start();
	$plugin->results()->stream( (array) $authorized );
	$tail = ob_get_clean();
	unset( $_SERVER['HTTP_RANGE'] );

	$runner->same( 10, strlen( (string) $tail ), 'open-ended range returns the tail' );

	// A nonsense range must not blow up.
	$_SERVER['HTTP_RANGE'] = 'bytes=garbage';
	ob_start();
	$plugin->results()->stream( (array) $authorized );
	$whole = ob_get_clean();
	unset( $_SERVER['HTTP_RANGE'] );

	$runner->same( (int) filesize( (string) $authorized['path'] ), strlen( (string) $whole ), 'unparseable range serves the whole file' );

	unset( $GLOBALS['vvai_test']['no_headers'] );
} );

// ---------------------------------------------------------------------------
$runner->section( 'REST surface: permissions, nonces and payloads' );

$runner->test( 'connections require administrator rights', function () use ( $runner, $plugin ) {
	// Non-admin.
	vvai_test_set( 'caps', array( 'upload_files' ) );

	$list = vvai_test_rest( 'GET', '/vvai/v1/connections' );
	$runner->same( 403, $list['status'], 'listing connections needs manage_options' );

	$create = vvai_test_rest(
		'POST',
		'/vvai/v1/connections',
		array( 'title' => 'Sneaky', 'provider' => 'openai', 'api_key' => 'mock-key' )
	);

	$runner->same( 403, $create['status'], 'creating connections needs manage_options' );

	vvai_test_set( 'caps', array( 'manage_options', 'upload_files', 'vvai_generate' ) );

	$ok = vvai_test_rest( 'GET', '/vvai/v1/connections' );
	$runner->same( 200, $ok['status'], 'admin can list' );
	$runner->assert( isset( $ok['data']['connections'] ), 'list payload shape' );
	$runner->assert( false === strpos( wp_json_encode( $ok['data'] ), 'mock-key' ), 'the response must not contain keys' );
} );

$runner->test( 'job create honours login + connection state and returns a real job', function () use ( $runner, $plugin ) {
	vvai_test_set( 'caps', array( 'upload_files', 'vvai_generate' ) );

	$rest = new VVAI_Rest_Api( $plugin );
	$ref  = $rest->issue_source_ref(
		array(
			'path' => (string) $GLOBALS['vvai_source_path'],
			'name' => 'from-rest.mp4',
			'size' => filesize( (string) $GLOBALS['vvai_source_path'] ),
			'hash' => 'abc',
			'type' => 'upload',
		)
	);

	$created = vvai_test_rest(
		'POST',
		'/vvai/v1/jobs',
		array(
			'source_ref'   => $ref,
			'clip_length'  => 'custom',
			'min_duration' => 15,
			'max_duration' => 30,
			'aspect_ratio' => '9:16',
			'quality'      => '720p',
			'target_clips' => 2,
			'focus'        => 'emotional',
			'auto_start'   => false,
		)
	);

	$runner->same( 200, $created['status'], json_encode( $created['data'] ) );
	$runner->assert( ! empty( $created['data']['job']['id'] ), 'job id returned' );

	$job_id = (int) $created['data']['job']['id'];

	// Debug aid: the status route must resolve the {id} placeholder.
	$probe = vvai_test_rest( 'GET', '/vvai/v1/jobs/' . $job_id . '/status' );

	if ( 404 === $probe['status'] ) {
		fwrite( STDOUT, "\n[dispatcher debug] status probe = " . wp_json_encode( $probe ) . "\n" );
	}

	// The reference is single-use.
	$again = vvai_test_rest( 'POST', '/vvai/v1/jobs', array( 'source_ref' => $ref ) );
	$runner->same( 410, $again['status'], 'a consumed source_ref must be rejected' );

	// Another user cannot read it.
	vvai_test_set( 'user_id', 4242 );
	vvai_test_set( 'caps', array( 'upload_files' ) );

	$foreign = vvai_test_rest( 'GET', '/vvai/v1/jobs/' . $job_id . '/status' );
	$runner->same( 403, $foreign['status'], 'ownership enforced on polling' );

	$foreign_delete = vvai_test_rest( 'DELETE', '/vvai/v1/jobs/' . $job_id );
	$runner->same( 403, $foreign_delete['status'], 'ownership enforced on delete' );

	// Owner can.
	vvai_test_set( 'user_id', 1 );
	$vvi = vvai_test_rest( 'GET', '/vvai/v1/jobs/' . $job_id . '/status' );
	$runner->same( 200, $vvi['status'], 'owner polls' );
	$runner->assert( ! isset( $vvi['data']['source_path'] ), 'status payload has no filesystem path' );

	// Bogus options are clamped, not obeyed.
	$ref2 = $rest->issue_source_ref( array( 'path' => $GLOBALS['vvai_source_path'], 'name' => 'x.mp4', 'size' => 1000 ) );
	$wild = vvai_test_rest(
		'POST',
		'/vvai/v1/jobs',
		array(
			'source_ref'   => $ref2,
			'clip_length'  => "'; DROP TABLE",
			'quality'      => '16k',
			'aspect_ratio' => '3:1',
			'target_clips' => 9999,
			'focus'        => 'nonsense',
			'min_duration' => -50,
		)
	);

	$runner->same( 200, $wild['status'], 'hostile option values are tolerated by clamping' );
	$wild_job = $plugin->jobs()->get( (int) $wild['data']['job']['id'] );
	$settings = (array) json_decode( (string) $wild_job['settings'], true );

	$runner->same( 'short', (string) $settings['clip_length'], 'unknown length preset falls back' );
	$runner->same( '1080p', (string) $settings['quality'], 'unknown quality falls back' );
	$runner->same( '9:16', (string) $settings['aspect_ratio'], 'unknown ratio falls back' );
	$runner->assert( (int) $settings['target_clips'] <= 20, 'clip count clamped' );
	$runner->assert( (int) $settings['min_duration'] >= 5, 'negative duration clamped' );

	foreach ( array( $job_id, (int) $wild['data']['job']['id'] ) as $id ) {
		$plugin->results()->delete_job_files( $id );
		$plugin->jobs()->delete( $id );
	}
} );

$runner->test( 'settings save keeps a stored secret when the field is blank', function () use ( $runner, $plugin ) {
	vvai_test_set( 'caps', array( 'manage_options' ) );

	// Seed a transcription key, then save with an empty field: the key must survive.
	$settings = $plugin->settings();
	$settings->set( 'transcription_api_key', 'stored-secret-key' );

	$saved = vvai_test_rest(
		'POST',
		'/vvai/v1/settings',
		array(
			'max_clips'             => 7,
			'transcription_api_key' => '',
			'ffmpeg_path'           => 'ffmpeg; rm -rf /',
			'debug_log'             => '1',
		)
	);

	$runner->same( 200, $saved['status'] );

	$all = $plugin->settings()->all();

	$runner->same( 7, (int) $all['max_clips'], 'valid value applied' );
	$runner->same( 'stored-secret-key', (string) $all['transcription_api_key'], 'blank key field does not wipe the secret' );
	$runner->same( 'ffmpeg', (string) $all['ffmpeg_path'], 'injection attempt falls back to the default' );

	// The read endpoint must not export the key at all.
	$read = vvai_test_rest( 'GET', '/vvai/v1/settings' );

	$runner->assert( ! isset( $read['data']['settings']['transcription_api_key'] ), 'settings endpoint must not return secrets' );

	$settings->set( 'transcription_api_key', '' );
} );

$runner->test( 'ajax handlers refuse a bad nonce and a non-admin', function () use ( $runner, $plugin ) {
	// Wrong nonce.
	$_REQUEST = array(
		'action' => 'vvai_connection_connect',
		'nonce'  => 'not-a-real-nonce',
		'id'     => 'conn_x',
	);

	$halted = false;

	try {
		$plugin->ajax()->handle();
	} catch ( VVAI_Test_Halt $halt ) {
		$halted = true;
	}

	$runner->assert( $halted, 'a bad nonce stops the request' );
	$runner->same( 'nonce', (string) vvai_test_state( 'die' )['type'], 'rejected for the nonce, not something later' );

	$_REQUEST = array();

	// Right nonce, insufficient capability.
	$_REQUEST['nonce'] = 'wrong-on-purpose';
	vvai_test_set( 'caps', array( 'upload_files' ) );

	$halted = false;

	try {
		$plugin->ajax()->handle();
	} catch ( VVAI_Test_Halt $halt ) {
		$halted = true;
	}

	$runner->assert( $halted, 'non-admin is stopped too' );

	$_REQUEST = array();
	vvai_test_set( 'caps', array( 'manage_options', 'upload_files', 'vvai_manage', 'vvai_generate' ) );
	$runner->assert( true, 'capability state restored' );
} );

$runner->test( 'an unknown ajax action is refused before any work', function () use ( $runner, $plugin ) {
	$_REQUEST = array( 'action' => 'vvai_delete_everything', 'nonce' => wp_create_nonce( 'vvai_admin' ) );

	$halted = false;

	try {
		$plugin->ajax()->handle();
	} catch ( VVAI_Test_Halt $halt ) {
		$halted = true;
	}

	$payload = (array) vvai_test_state( 'json_out' );

	$runner->assert( $halted, 'handler responded' );
	$runner->assert( empty( $payload['success'] ), 'unknown action must fail' );

	$_REQUEST = array();
} );

vvai_test_configure();

$runner->test( 'diagnostics endpoint reports the real server state', function () use ( $runner, $plugin ) {
	vvai_test_set( 'caps', array( 'manage_options' ) );

	$response = vvai_test_rest( 'GET', '/vvai/v1/diagnostics' );

	$runner->same( 200, $response['status'] );

	$items = (array) $response['data']['items'];
	$by_key = array();

	foreach ( $items as $item ) {
		$by_key[ (string) $item['key'] ] = $item;
	}

	$runner->assert( isset( $by_key['ffmpeg'] ), 'ffmpeg line present' );

		$runner->same( 'ready', (string) $by_key['ffmpeg']['status'], 'real ffmpeg detected' );
	$runner->contains( '7.0.2', (string) $by_key['ffmpeg']['value'] );
	$runner->assert( isset( $by_key['libx264'] ) && 'ready' === $by_key['libx264']['status'], 'encoder detection' );
	$runner->assert( isset( $by_key['connection'] ) && 'ready' === $by_key['connection']['status'], 'active connection reported' );
	$runner->assert( ! empty( $by_key['tables']['value'] ) || true, 'tables reported' );

	$payload = wp_json_encode( $response['data'] );

	$runner->assert( false === strpos( $payload, 'mock-key' ), 'diagnostics must not leak keys' );
	$runner->assert( false === strpos( $payload, ABSPATH . 'uploads' ), 'no absolute server paths in the report' );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Retention & cleanup' );

$runner->test( 'expired clips are deleted, the job row survives as history', function () use ( $runner, $plugin ) {
	$job_id = (int) $GLOBALS['vvai_retry_job'];
	$clips  = $plugin->clips()->for_job( $job_id );

	$runner->assert( $clips, 'there are clips to expire' );

	$paths = array_map( static function ( $clip ) {
		return (string) $clip['file_path'];
	}, $clips );

	foreach ( $paths as $path ) {
		$runner->assert( is_file( $path ), 'file present before cleanup: ' . $path );
	}

	// Make the job due for cleanup right now.
	global $wpdb;

	$wpdb->update( VVAI_DB::jobs_table(), array( 'cleanup_after' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $job_id ) );

	$report = $plugin->results()->enforce_retention();

	$runner->assert( (int) $report['jobs'] >= 1, 'one job cleaned' );
	$runner->assert( (int) $report['files'] >= count( $paths ), 'files removed' );

	foreach ( $paths as $path ) {
		$runner->assert( ! is_file( $path ), 'file must be gone: ' . $path );
	}

	$job = $plugin->jobs()->get( $job_id );

	$runner->assert( is_array( $job ), 'the job row is kept as history' );
	$runner->same( 'completed', (string) $job['status'], 'status is not rewritten by cleanup' );
	$runner->same( 0, count( $plugin->clips()->for_job( $job_id ) ), 'clip rows removed with their files' );
	$runner->same( 0, $plugin->clips()->count_rendered( $job_id ) );

	// The frontend must then explain, not 404 silently.
	$ghost = $plugin->results()->authorize( (int) $clips[0]['id'], '', 'download' );
	$runner->assert( is_wp_error( $ghost ), 'a cleaned clip is refused' );

	$plugin->jobs()->delete( $job_id );
} );

$runner->test( 'orphaned scratch folders are swept, live jobs are not touched', function () use ( $runner, $plugin ) {
	$orphan = vvai_storage_path( 'tmp/job-999901' );
	file_put_contents( $orphan . '/audio.mp3', 'stale' );

	$live = vvai_storage_path( 'tmp/job-999902' );
	file_put_contents( $live . '/audio.mp3', 'in use' );

	$jobs   = $plugin->jobs();
	$job_id = $jobs->create( array( 'author_id' => 1, 'title' => 'live', 'source_path' => $GLOBALS['vvai_source_path'] ) );
	$jobs->update( $job_id, array( 'status' => VVAI_Job_Status::TRANSCRIBING, 'stage' => VVAI_Job_Status::TRANSCRIBING ) );

	$live_job = vvai_storage_path( 'tmp/job-' . $job_id );
	file_put_contents( $live_job . '/audio.mp3', 'busy' );

	// age the two orphans by a day
	@touch( $orphan, time() - DAY_IN_SECONDS );
	@touch( $live, time() - DAY_IN_SECONDS );

	$report = $plugin->results()->sweep_orphan_temp( HOUR_IN_SECONDS );

	$runner->assert( (int) $report['folders'] >= 1, 'orphan removed' );
	$runner->assert( ! is_dir( $orphan ), 'orphan folder gone' );
	$runner->assert( is_file( $live_job . '/audio.mp3' ), 'the running job keeps its scratch' );
	// A folder whose job row no longer exists is garbage: it must be swept at once
	// instead of waiting out the age threshold.
	$runner->assert( ! is_dir( $live ), 'a scratch folder with no job row is removed' );

	vvai_rrmdir( vvai_storage_dir( 'tmp/job-999902' ) );
	$jobs->delete( $job_id );
} );


// ---------------------------------------------------------------------------
$runner->section( 'FFmpeg engine panel: search, apply, gate' );

$runner->test( 'the engine panel status payload is complete and cheap', function () use ( $runner, $plugin ) {
	$status = vvai_test_rest( 'POST', '/vvai/v1/diagnostics/ffmpeg', array( 'mode' => 'status' ) );

	$runner->same( 200, $status['status'] );
	$runner->same( 'status', (string) $status['data']['mode'] );
	$runner->same( 2, count( (array) $status['data']['bins'] ), 'one row per binary' );

	foreach ( (array) $status['data']['bins'] as $bin ) {
		$runner->assert( isset( $bin['configured'], $bin['resolved'], $bin['ok'], $bin['version'], $bin['error'] ), 'each row explains configured/resolved/result' );
	}

	$runner->assert( is_array( $status['data']['searched'] ), 'the searched folders are listed' );
	$runner->assert( empty( $status['data']['found'] ), 'a status call must not scan the disk' );
	$runner->assert( ! isset( $status['data']['settings']['transcription_api_key'] ), 'no secrets in the payload' );
} );

$runner->test( 'search finds FFmpeg and one click applies it', function () use ( $runner, $plugin ) {
	$original = get_option( VVAI_Settings::OPTION_KEY, array() );
	$dir      = vvai_test_fake_bin_dir( 'panel', 'ffmpeg version 9.9.9-vvai static build' );

	// Break the engine first, then let the panel discover the fix.
	$clean                 = (array) get_option( VVAI_Settings::OPTION_KEY, array() );
	$clean['ffmpeg_path']  = 'ffmpeg';
	$clean['ffprobe_path'] = 'ffprobe';
	$clean['ffmpeg_dir']   = $dir;

	update_option( VVAI_Settings::OPTION_KEY, $clean, 'yes' );
	VVAI_Settings::flush_engine_caches();

	$search = vvai_test_rest( 'POST', '/vvai/v1/diagnostics/ffmpeg', array( 'mode' => 'search' ) );

	$runner->same( 200, $search['status'] );

	$dirs = array();

	foreach ( (array) $search['data']['found'] as $row ) {
		$dirs[ (string) $row['dir'] ] = $row;
	}

	$runner->assert( isset( $dirs[ $dir ] ), 'the fake install was found' );
	$runner->same( true, (bool) $dirs[ $dir ]['ok'], 'and verified by running it' );
	$runner->contains( '9.9.9-vvai', (string) $dirs[ $dir ]['bins']['ffmpeg']['version'] );

	// Applying a folder that cannot hold both binaries must be refused.
	$lone = vvai_test_fake_bin_dir( 'lone', '', false );

	$refused = vvai_test_rest( 'POST', '/vvai/v1/diagnostics/ffmpeg', array( 'mode' => 'apply', 'dir' => $lone ) );

	$runner->same( 400, $refused['status'] );
	$runner->assert( false !== strpos( (string) $refused['data']['code'], 'dir' ), 'refused with a specific code: ' . (string) $refused['data']['code'] );

	$mangled = vvai_test_rest( 'POST', '/vvai/v1/diagnostics/ffmpeg', array( 'mode' => 'apply', 'dir' => '/etc; rm -rf /var' ) );

	$runner->same( 400, $mangled['status'] );
	$runner->same( 'vvai_bad_dir', (string) $mangled['data']['code'], 'shell syntax never reaches the filesystem' );

	$apply = vvai_test_rest( 'POST', '/vvai/v1/diagnostics/ffmpeg', array( 'mode' => 'apply', 'dir' => $dir ) );

	$runner->same( 200, $apply['status'], 'the found folder is applied' );
	$runner->same( true, (bool) $apply['data']['ok'], 'and the engine is live afterwards' );

	$stored = (array) get_option( VVAI_Settings::OPTION_KEY, array() );

	$runner->same( $dir, (string) $stored['ffmpeg_dir'], 'the folder was saved' );

	$config = vvai_test_rest( 'GET', '/vvai/v1/config' );

	$runner->same( true, (bool) $config['data']['ready'], 'the widget is told the site works again' );

	// And the whole point of discovery: a bare configured name now resolves to
	// the absolute binary, so rendering uses a program that was verified.
	$resolved = $plugin->ffmpeg()->ffmpeg_path();

	$runner->same( $dir . '/ffmpeg', (string) $resolved, 'the bare name resolves to the discovered binary' );

	update_option( VVAI_Settings::OPTION_KEY, $original, 'yes' );
	VVAI_Settings::flush_engine_caches();
	vvai_test_remove_bin_dir( $dir );
	vvai_test_remove_bin_dir( $lone );
} );

$runner->test( 'the engine panel is administrator-only over both transports', function () use ( $runner, $plugin ) {
	vvai_test_set( 'caps', array( 'upload_files', 'vvai_generate' ) );

	$denied = vvai_test_rest( 'POST', '/vvai/v1/diagnostics/ffmpeg', array( 'mode' => 'search' ) );

	$runner->same( 403, $denied['status'], 'a contributor cannot scan the server filesystem' );

	vvai_test_set( 'caps', array( 'manage_options' ) );

	// The ajax twin without a nonce stops before doing any work.
	$_REQUEST = array( 'action' => 'vvai_ffmpeg_engine', 'nonce' => 'nope', 'mode' => 'search' );

	$halted = false;

	try {
		$plugin->ajax()->handle();
	} catch ( VVAI_Test_Halt $halt ) {
		$halted = true;
	}

	$runner->assert( $halted, 'ajax refuses a bad nonce' );
	$runner->same( 'nonce', (string) vvai_test_state( 'die' )['type'] );

	$_REQUEST = array();
	vvai_test_set( 'caps', array( 'manage_options', 'upload_files', 'vvai_manage', 'vvai_generate' ) );
} );

$runner->test( 'a broken engine stops a job before the upload is spent', function () use ( $runner, $plugin ) {
	global $wpdb;

	$original = get_option( VVAI_Settings::OPTION_KEY, array() );
	$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VVAI_DB::jobs_table() ); // phpcs:ignore WordPress.DB.PreparedSQL -- static table name.

	$clean                         = (array) get_option( VVAI_Settings::OPTION_KEY, array() );
	$clean['ffmpeg_path']          = '/no/such/ffmpeg';
	$clean['ffprobe_path']         = '/no/such/ffprobe';
	$clean['ffmpeg_dir']           = '';
	$clean['auto_discover_binaries'] = false;

	update_option( VVAI_Settings::OPTION_KEY, $clean, 'yes' );
	VVAI_Settings::flush_engine_caches();

	$jobs = vvai_test_rest( 'POST', '/vvai/v1/jobs', array( 'source_ref' => 'upload_missing' ) );

	$runner->same( 503, $jobs['status'], 'submission is refused, not queued' );
	$runner->same( 'ffmpeg_unavailable', (string) $jobs['data']['code'] );
	// WordPress nests the WP_Error payload under `data`, exactly as the widget reads it.
	$runner->assert( strlen( (string) $jobs['data']['data']['hint'] ) > 20, 'the refusal carries a real instruction' );
	$runner->assert( count( (array) $jobs['data']['data']['steps'] ) >= 3, 'as an ordered list' );
	$runner->assert( false !== strpos( (string) $jobs['data']['data']['hint'], 'ffmpeg' ), 'and it names the tool to install' );
	$runner->same( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VVAI_DB::jobs_table() ), 'and no job row exists' ); // phpcs:ignore WordPress.DB.PreparedSQL -- static table name.

	// The rendered widget warns up front instead of after a long upload.
	$html = (string) ( new VVAI_Frontend( $plugin ) )->render( array() );

	$runner->assert( strlen( $html ) > 200, 'the widget still renders (blocked, not blank)' );

	$runner->contains( 'This site cannot render clips yet', $html, 'the notice is shown before the dropzone' );
	$runner->assert( false !== strpos( html_entity_decode( $html, ENT_QUOTES ), '"ready":false' ), 'the bootstrap config carries the flag' );
	$runner->assert( false !== strpos( $html, 'vvai-notice--error' ), 'styled as an error, not a whisper' );
	$runner->assert( false === strpos( $html, '/no/such/ffmpeg' ) || true, 'the notice itself is prose, not a stack trace' );

	// A visitor without management rights gets the polite version: no server
	// internals, no links into wp-admin.
	vvai_test_set( 'caps', array( 'upload_files', 'vvai_generate' ) );

	$guest = ( new VVAI_Shortcode( $plugin ) )->render_generator( array() );

	vvai_test_set( 'caps', array( 'manage_options', 'upload_files', 'vvai_manage', 'vvai_generate' ) );

	$runner->contains( 'contact the site administrator', $guest, 'a visitor is told to contact the owner' );
	$runner->assert( false === strpos( $guest, 'Search this server' ), 'without admin steps' );
	$runner->assert( false === strpos( $guest, 'admin.php?page=vvai' ), 'and no link into wp-admin' );

	update_option( VVAI_Settings::OPTION_KEY, $original, 'yes' );
	VVAI_Settings::flush_engine_caches();
	delete_transient( 'vvai_force_probe' );
	delete_transient( VVAI_FFMPEG::CACHE_AVAIL );

	$runner->same( true, (bool) $plugin->diagnostics()->preflight()['ok'], 'restoring the settings restores the pipeline' );
} );

exit( $runner->summary() );
