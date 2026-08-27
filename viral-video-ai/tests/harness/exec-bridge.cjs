/**
 * Exec bridge: lets the sandboxed PHP runtime run real binaries.
 *
 * PHP (WebAssembly) has no process spawning, so VVAI_Process is pointed at this
 * local HTTP endpoint through the plugin's own `vvai_process_runner` filter.
 * That means the *plugin's real argv arrays* are executed by the *real FFmpeg*,
 * instead of a hand-written test double.
 *
 * POST /exec { argv: string[], timeout: seconds }
 *   ->   { code, stdout, stderr, error }
 */
const http = require('http');
const { spawn } = require('child_process');

const PORT = Number(process.env.VVAI_BRIDGE_PORT || 8799);

// Only these programs may be executed — the bridge is a test tool, not a shell.
const ALLOWED = [
  'ffmpeg',
  'ffprobe',
  '/home/user/.toolbin/ffmpeg',
  '/home/user/.toolbin/ffprobe',
];

http
  .createServer((req, res) => {
    if (req.method !== 'POST' || !req.url.startsWith('/exec')) {
      if (req.url === '/health') {
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ ok: true }));
        return;
      }
      res.writeHead(404);
      res.end('nope');
      return;
    }

    let raw = '';
    req.on('data', (chunk) => {
      raw += chunk;
      if (raw.length > 4_000_000) req.destroy();
    });

    req.on('end', () => {
      let payload;

      try {
        payload = JSON.parse(raw);
      } catch (error) {
        res.writeHead(400, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ code: -1, stdout: '', stderr: '', error: 'bad json' }));
        return;
      }

      const argv = Array.isArray(payload.argv) ? payload.argv.map(String) : [];

      if (!argv.length) {
        res.writeHead(400, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ code: -1, stdout: '', stderr: '', error: 'empty argv' }));
        return;
      }

      // Never let one bad command kill the bridge.
      res.on('error', () => {});

      const binary = argv[0];
      const allowed = ALLOWED.some((name) => binary === name || binary.endsWith('/' + name));

      if (!allowed) {
        res.writeHead(403, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ code: -1, stdout: '', stderr: '', error: 'binary not allowed by the test bridge: ' + binary }));
        return;
      }

      let responded = false;
      const reply = (status, payload) => {
        if (responded) return;
        responded = true;
        res.writeHead(status, { 'content-type': 'application/json' });
        res.end(JSON.stringify(payload));
      };

      const child = spawn(binary, argv.slice(1), {
        cwd: payload.cwd || undefined,
        env: { ...process.env, LC_ALL: 'C' },
      });

      let stdout = '';
      let stderr = '';
      let timedOut = false;

      const timer = setTimeout(() => {
        timedOut = true;
        child.kill('SIGKILL');
      }, Math.max(1000, (Number(payload.timeout) || 120) * 1000));

      child.stdout.on('data', (chunk) => {
        stdout += chunk;
        if (stdout.length > 8_000_000) child.kill('SIGKILL');
      });

      child.stderr.on('data', (chunk) => {
        stderr += chunk;
        if (stderr.length > 8_000_000) child.kill('SIGKILL');
      });

      child.on('error', (error) => {
        clearTimeout(timer);
        reply(200, { code: -1, stdout: '', stderr: String((error && error.message) || error), error: 'spawn failed' });
      });

      child.on('close', (code) => {
        clearTimeout(timer);

        reply(200, {
            code: timedOut ? 124 : code === null ? 0 : code,
            stdout,
            stderr: timedOut ? stderr + '\nbridge: timed out' : stderr,
            error: timedOut ? 'timed out' : '',
        });
      });
    });
  })
  .listen(PORT, '127.0.0.1', () => console.log('exec bridge on ' + PORT));

process.on('uncaughtException', (error) => console.error('bridge error:', error && error.message));
process.on('unhandledRejection', (error) => console.error('bridge rejection:', error && error.message));
