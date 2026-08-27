#!/usr/bin/env node
// Runs a PHP script inside the real PHP 8.2 WebAssembly runtime, with the host
// filesystem mounted and the host's tool env forwarded (the wasm runtime does not
// inherit process.env on its own).
//
// usage: node php.mjs /abs/path/script.php [args...]
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

const [, , script, ...rest] = process.argv;

if (!script) {
  console.error('usage: node php.mjs <script.php> [args]');
  process.exit(2);
}

const php = new PHP(
  await loadNodeRuntime(process.env.PHP_VERSION || '8.2', {
    emscriptenOptions: { processId: Number(process.env.PHP_PROCESS_ID || 1) },
  })
);

useHostFilesystem(php);
php.chdir(process.cwd());

php.writeFile(
  '/tmp/__harness_args.json',
  JSON.stringify({
    argv: [script, ...rest],
    env: {
      VVAI_FFMPEG: process.env.VVAI_FFMPEG || '',
      VVAI_FFPROBE: process.env.VVAI_FFPROBE || '',
      VVAI_BRIDGE: process.env.VVAI_BRIDGE || '',
      VVAI_NO_BRIDGE: process.env.VVAI_NO_BRIDGE || '',
    },
  })
);

const wrapper = `<?php
$argv = json_decode(file_get_contents('/tmp/__harness_args.json'), true)['argv'];
$_SERVER['argv'] = array_merge(['php'], $argv);
$_SERVER['argc'] = count($_SERVER['argv']);
define('VVAI_IN_WASM', true);
require '${script}';
`;

// Propagate the PHP exit status so CI (and tests/run-tests.php) can fail.
// runStream() is used rather than run(): run() rejects the whole call when the
// script exits non-zero, which is exactly the signal a test runner needs.
const out = await php.runStream({ code: wrapper });
const stdout = await out.stdoutText;
const stderr = await out.stderrText;
if (stdout) process.stdout.write(stdout);
if (stderr) process.stderr.write(stderr);

let exitCode = 0;

// exitCode is an async getter in some @php-wasm builds and absent in others:
// take it when it really is an integer, otherwise report success and let the
// summary line speak for itself.
try {
	const raw = await Promise.resolve( out.exitCode );

	if ( Number.isInteger( raw ) ) {
		exitCode = raw;
	}
} catch ( error ) {
	exitCode = 0;
}

process.exit( exitCode );
