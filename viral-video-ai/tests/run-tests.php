<?php
/**
 * Run the Viral Video AI test suites.
 *
 *   php tests/run-tests.php            # unit + integration (needs ffmpeg + ffprobe on PATH)
 *   php tests/run-tests.php --core     # unit suite only (no binaries, no network)
 *
 * The integration suite talks to two local dev servers; start them first:
 *
 *   node tests/harness/exec-bridge.cjs &   # lets the sandboxed PHP run real FFmpeg
 *   node tests/harness/mock-ai.cjs     &   # local OpenAI/Gemini/Anthropic/Groq/OpenRouter
 *
 * On a machine with a normal PHP CLI the exec bridge is unnecessary: set
 * VVAI_NO_BRIDGE=1 and the tests call ffmpeg directly.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

$php     = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
$only    = in_array( '--core', $argv, true );
$suites  = $only
	? array( '01-core.php' )
	: array( '01-core.php', '02-integration.php', '03-lifecycle.php', '04-ui-contract.php' );
$failed  = 0;

foreach ( $suites as $suite ) {
	$path = __DIR__ . '/' . $suite;

	if ( ! is_file( $path ) ) {
		echo "missing suite: {$suite}\n";
		$failed++;
		continue;
	}

	echo "\n=== {$suite} ===\n";

	passthru( escapeshellcmd( $php ) . ' ' . escapeshellarg( $path ), $code );

	if ( 0 !== (int) $code ) {
		$failed++;
	}
}

echo "\n" . ( $failed ? $failed . " suite(s) failed\n" : "all suites passed\n" );

exit( $failed ? 1 : 0 );
