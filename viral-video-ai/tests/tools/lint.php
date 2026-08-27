<?php
/**
 * Parse-check every plugin PHP file with the real engine, and flag the
 * mistake classes this codebase has actually tripped over.
 */
$root = rtrim( $argv[1] ?? getcwd(), '/' );
$skip = array( 'node_modules', '.git', 'vendor' );

$collect = static function ( $dir ) use ( &$collect, $skip ) {
	$found = array();

	foreach ( (array) scandir( $dir ) as $e ) {
		if ( '' === $e || '.' === $e[0] ) {
			continue;
		}

		$p = $dir . '/' . $e;

		if ( is_dir( $p ) ) {
			if ( ! in_array( $e, $skip, true ) ) {
				$found = array_merge( $found, $collect( $p ) );
			}
			continue;
		}

		if ( 'php' === strtolower( pathinfo( $p, PATHINFO_EXTENSION ) ) ) {
			$found[] = $p;
		}
	}

	return $found;
};

$files = $collect( $root );
sort( $files );

$errors = 0;
$warned = 0;
$php8    = array(
	'/\benum\s+[A-Z]\w*\s*[{:]/'                        => 'enum',
	'/\breadonly\s+(?:int|float|string|bool|array|mixed|\?[\w\\\\]+)\s+\$/' => 'readonly property',
	'/\bmatch\s*\(\s*\$/'                               => 'match expression',
	'/\?\->/'                                           => 'nullsafe operator',
	'/\bstr_contains\s*\(/'                             => 'str_contains',
	'/\bstr_starts_with\s*\(/'                          => 'str_starts_with',
	'/\bstr_ends_with\s*\(/'                            => 'str_ends_with',
	'/\barray_is_list\s*\(/'                            => 'array_is_list',
	'/\000/'                                            => 'NUL byte',
);

foreach ( $files as $file ) {
	$src   = (string) file_get_contents( $file );
	$label = str_replace( $root . '/', '', $file );

	try {
		token_get_all( $src, TOKEN_PARSE );
	} catch ( \Throwable $e ) {
		$errors++;
		printf( "SYNTAX %s:%d\n    %s\n", $label, $e->getLine(), $e->getMessage() );
		continue;
	}

	// Helper called with the key instead of the array as the first argument.
	if ( preg_match_all( '/vvai_array_get\(\s*[\'"][A-Za-z_][A-Za-z0-9_]*[\'"]\s*,/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$warned++;
			printf( "PATTERN %s:%d  vvai_array_get first argument must be the array\n", $label, substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1 );
		}
	}

	// A brace block that only holds a docblock: invalid inside a class body.
	if ( preg_match( '/\n\t\{\n(?:\t\t(?:\/\*\*|\*\/|\*[^\/]|\s*)[^\n]*\n)+\t\}\n/', $src ) ) {
		$warned++;
		printf( "STRUCT %s  stray brace-only block\n", $label );
	}

	// Closing a proc_open pipe without checking it is still a resource (the bug
	// that fatals on Windows/PHP 8 after proc_close()).
	if ( preg_match( '/^\s*fclose\(\s*\$pipes\[/m', $src ) && false === strpos( $src, 'is_resource' ) ) {
		$warned++;
		printf( "UNSAFE %s  fclose on a proc_open pipe without is_resource()\n", $label );
	}

	foreach ( $php8 as $pattern => $name ) {
		if ( preg_match( $pattern, $src ) ) {
			$warned++;
			printf( "COMPAT %s  uses %s (needs PHP 8, plugin targets 7.4+)\n", $label, $name );
		}
	}
}

printf( "\nPHP %s — %d files, %d syntax error(s), %d warning(s)\n", PHP_VERSION, count( $files ), $errors, $warned );
exit( $errors > 0 ? 1 : 0 );
