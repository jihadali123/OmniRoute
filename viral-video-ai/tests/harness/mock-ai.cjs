/**
 * Mock AI provider (test fixture only — never shipped as a feature).
 *
 * It speaks the real wire formats of OpenAI, Groq, OpenRouter, Anthropic and
 * Gemini so the plugin's HTTP layer, auth handling, JSON-mode requests,
 * transcription client and error taxonomy are exercised over TCP, not stubbed.
 *
 * The "intelligence" here is a deterministic heuristic over the transcript that
 * the plugin sends in the prompt: it scores lines for hook/emotion/insight
 * keywords and returns the best spans using ONLY timestamps present in the
 * prompt. So the timestamps that end up in FFmpeg really do travel across the
 * provider boundary, exactly like a production response.
 *
 * Behaviour is selected by the API key the plugin presents:
 *   mock-key      -> success
 *   bad-key       -> 401 invalid_api_key
 *   forbidden-key -> 403
 *   ratelimit-key -> 429
 *   quota-key     -> 402
 *   offline-key   -> 503
 *   garbage-key   -> 200 with prose instead of JSON
 *   slow-key      -> 6s latency (client timeout test)
 */
const http = require('http');

const PORT = Number(process.env.VVAI_AI_PORT || 8791);

const LINES = [
  'I am going to show you the one trick that changed everything for me.',
  'Nobody talks about this, but the numbers do not lie at all today.',
  'Watch what happens when I turn the dial all the way up to eleven.',
  'That is the moment everyone remembers afterwards, guaranteed.',
  'You can copy this exactly, and the results show up within a week.',
];

const HOT = /(secret|changed everything|nobody talks|numbers do not lie|watch what happens|moment everyone remembers|copy this exactly|guaranteed|never|insane|mistake|truth|why)/i;
const COLD = /(um+|so yeah|welcome back|don't forget to subscribe|like and share)/i;

function score(line) {
  let value = 40;
  const hits = line.match(new RegExp(HOT, 'gi'));
  value += hits ? hits.length * 22 : 0;
  if (COLD.test(line)) value -= 45;
  value += Math.min(18, Math.floor(line.split(' ').length / 2));
  value += line.includes('?') ? 6 : 0;
  return Math.max(5, Math.min(99, Math.round(value)));
}

/**
 * Parse the "[start | end] text" transcript block out of the plugin's prompt.
 */
function parseTranscript(prompt) {
  const matches = [...String(prompt).matchAll(/\[(\d+(?:\.\d+)?)\s*\|\s*(\d+(?:\.\d+)?)\]\s*(.+)/g)];

  return matches.map((m) => ({
    start: parseFloat(m[1]),
    end: parseFloat(m[2]),
    text: m[3].trim(),
  }));
}

function buildClips(prompt, requested) {
  const transcript = parseTranscript(prompt);

  if (!transcript.length) {
    return { clips: [] };
  }

  const wanted = Math.max(1, requested || 2);
  const scored = transcript
    .map((segment, index) => ({ ...segment, index, score: score(segment.text) }))
    .filter((segment) => segment.score >= 55)
    .sort((a, b) => b.score - a.score)
    .slice(0, wanted);

  if (!scored.length) {
    return { clips: [] };
  }

  // Merge adjacent picks so cuts are self-contained, like a real editor would.
  const clips = scored.map((segment) => {
    let end = segment.end;

    for (const other of transcript) {
      if (other.start >= segment.end - 0.01 && other.start <= segment.end + 1.2 && other.end - segment.start < 62) {
        end = other.end;
      }
    }

    return {
      start_time: segment.start,
      end_time: Number(end.toFixed(2)),
      viral_score: segment.score,
      reasoning: `This segment opens with a curiosity hook and stays concrete: "${segment.text.slice(0, 60)}…"`,
      title: titleFor(segment.text),
      social_caption: captionFor(segment.text),
      hashtags: ['#shorts', '#viral', '#creator'],
    };
  });

  return { clips };
}

function titleFor(text) {
  const words = text.replace(/[^A-Za-z ]/g, '').split(' ').filter(Boolean).slice(0, 6);
  const title = words.join(' ');
  return (title.charAt(0).toUpperCase() + title.slice(1)).slice(0, 60) || 'Viral moment';
}

function captionFor(text) {
  return (text.charAt(0).toUpperCase() + text.slice(1)).slice(0, 200);
}

function jsonClips(prompt, requested) {
  return JSON.stringify(buildClips(prompt, requested));
}

function requestCountExpected(prompt) {
  const match = String(prompt).match(/Number of clips requested:\s*(\d+)/);
  return match ? parseInt(match[1], 10) : 2;
}

function keyFrom(req, body) {
  const bearer = String(req.headers.authorization || '').replace(/^Bearer\s+/i, '');
  const headerKey = String(req.headers['x-api-key'] || '');
  const queryKey = String((req.query && req.query.key) || '');
  const formKey = body && typeof body.model === 'string' ? '' : '';

  return bearer || headerKey || queryKey || formKey || '';
}

function statusFor(key) {
  if (key.includes('bad-key')) return { status: 401, code: 'invalid_request_error', message: 'Incorrect API key provided: <redacted>.' };
  if (key.includes('forbidden-key')) return { status: 403, code: 'permission_denied', message: 'This key may not use the models API.' };
  if (key.includes('ratelimit-key')) return { status: 429, code: 'rate_limit_exceeded', message: 'You have exceeded your requests per minute limit.' };
  if (key.includes('quota-key')) return { status: 402, code: 'insufficient_quota', message: 'Quota exceeded for this billing account.' };
  if (key.includes('offline-key')) return { status: 503, code: 'server_error', message: 'Provider temporarily unavailable.' };
  if (key.includes('no-model-key')) return { status: 404, code: 'model_not_found', message: 'The model gpt-9-ultra does not exist or you do not have access to it.' };
  return null;
}

function send(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'content-type': 'application/json',
    'content-length': Buffer.byteLength(body),
    'x-request-id': 'mock-' + Math.random().toString(36).slice(2, 10),
  });
  res.end(body);
}

function readBody(req) {
  return new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks)));
  });
}

function parseMultipart(buffer, contentType) {
  const match = /boundary=(?:"([^"]+)"|([^;]+))/.exec(String(contentType || ''));
  if (!match) return { fields: {}, file: null };

  const boundary = '--' + (match[1] || match[2]);
  const parts = buffer.toString('binary').split(boundary);
  const fields = {};
  let file = null;

  for (const part of parts) {
    const headerEnd = part.indexOf('\r\n\r\n');
    if (headerEnd === -1) continue;

    const headers = part.slice(0, headerEnd);
    let content = Buffer.from(part.slice(headerEnd + 4, part.lastIndexOf('\r\n') > headerEnd ? part.lastIndexOf('\r\n') : part.length), 'binary');

    const nameMatch = /name="([^"]+)"/.exec(headers);
    if (!nameMatch) continue;

    const fileMatch = /filename="([^"]*)"/.exec(headers);

    if (fileMatch) {
      file = { name: fileMatch[1], size: content.length, type: (/Content-Type:\s*([^\r\n]+)/.exec(headers) || [])[1] };
    } else {
      fields[nameMatch[1]] = content.toString('utf8').trim();
    }
  }

  return { fields, file };
}

/**
 * Turn the uploaded audio size into a duration: the plugin always uploads
 * 16 kHz mono 32 kbps MP3, so bytes * 8 / 32000 is the real length.
 */
function durationFromAudio(bytes) {
  const seconds = (bytes * 8) / 32000;
  return Math.max(2, Math.round(seconds * 100) / 100);
}

function transcriptFor(duration) {
  const each = duration / LINES.length;

  return LINES.map((text, index) => ({
    start: Number((index * each).toFixed(2)),
    end: Number(Math.max(0.4, (index + 1) * each - 0.2).toFixed(2)),
    text,
  }));
}

const server = http.createServer(async (req, res) => {
  const raw = await readBody(req);
  const url = new URL(req.url, 'http://localhost');
  req.query = Object.fromEntries(url.searchParams.entries());

  const isMultipart = String(req.headers['content-type'] || '').includes('multipart/form-data');
  let body = null;

  if (!isMultipart && raw.length) {
    try {
      body = JSON.parse(raw.toString('utf8'));
    } catch (error) {
      body = {};
    }
  }

  const key = keyFrom(req, body);
  const failure = statusFor(key);
  const latency = key === 'slow-key' ? 6000 : 0;

  const finish = () => {
    if (failure) {
      send(res, failure.status, {
        error: {
          message: failure.message,
          type: failure.status === 401 ? 'invalid_request_error' : 'server_error',
          code: failure.code,
          status: failure.status,
        },
      });
      return;
    }

    route();
  };

  const route = () => {
    const path = url.pathname;

    // ----------------------------------------------------------- model lists
    if (req.method === 'GET' && /\/models$/.test(path)) {
      if (key.startsWith('mock-gemini')) {
        return send(res, 200, {
          models: [
            { name: 'models/gemini-2.0-flash', supportedGenerationMethods: ['generateContent'] },
            { name: 'models/gemini-2.5-pro', supportedGenerationMethods: ['generateContent'] },
          ],
        });
      }

      if (key.includes('anthropic')) {
        return send(res, 200, {
          data: [
            { id: 'claude-3-5-haiku-latest' },
            { id: 'claude-3-5-sonnet-latest' },
          ],
          has_more: false,
        });
      }

      // Each provider only advertises its own ids, so a model that does not exist
      // on that account must be reported exactly like the real APIs do.
      if (key.includes('openrouter')) {
        return send(res, 200, {
          data: [
            { id: 'openai/gpt-4o-mini' },
            { id: 'anthropic/claude-3.5-haiku' },
            { id: 'meta-llama/llama-3.3-70b-instruct' },
          ],
        });
      }

      if (key.includes('groq')) {
        return send(res, 200, {
          object: 'list',
          data: [
            { id: 'llama-3.3-70b-versatile', object: 'model' },
            { id: 'llama-3.1-8b-instant', object: 'model' },
            { id: 'whisper-large-v3-turbo', object: 'model' },
          ],
        });
      }

      return send(res, 200, {
        object: 'list',
        data: [
          { id: 'gpt-4o-mini', object: 'model' },
          { id: 'gpt-4o', object: 'model' },
          { id: 'whisper-1', object: 'model' },
        ],
      });
    }

    // ----------------------------------------------------------- transcription
    if (req.method === 'POST' && /\/audio\/transcriptions$/.test(path)) {
      const parsed = parseMultipart(raw, req.headers['content-type']);
      const bytes = parsed.file ? parsed.file.size : 0;

      if (!bytes) {
        return send(res, 400, { error: { message: 'No audio file was provided in the multipart body.', code: 'missing_file' } });
      }

      const duration = durationFromAudio(bytes);
      const segments = transcriptFor(duration);

      return send(res, 200, {
        task: 'transcribe',
        language: 'english',
        duration,
        segments,
        text: segments.map((s) => s.text).join(' '),
      });
    }

    // ----------------------------------------------------------- chat style
    if (req.method === 'POST' && /\/(chat\/completions|completions)$/.test(path)) {
      if (key === 'garbage-key') {
        return send(res, 200, {
          id: 'chatcmpl-mock',
          choices: [{ message: { role: 'assistant', content: 'Sure! I found some great moments for you, but I will describe them in prose instead of JSON.' } }],
          usage: { prompt_tokens: 900, completion_tokens: 40, total_tokens: 940 },
        });
      }

      const messages = Array.isArray(body.messages) ? body.messages : [];
      const prompt = messages.map((m) => (typeof m.content === 'string' ? m.content : JSON.stringify(m.content))).join('\n');
      const content = jsonClips(prompt, requestCountExpected(prompt));

      return send(res, 200, {
        id: 'chatcmpl-mock',
        object: 'chat.completion',
        model: body.model || 'gpt-4o-mini',
        choices: [
          {
            index: 0,
            message: { role: 'assistant', content },
            finish_reason: 'stop',
          },
        ],
        usage: { prompt_tokens: Math.round(prompt.length / 4), completion_tokens: Math.round(content.length / 4), total_tokens: Math.round((prompt.length + content.length) / 4) },
      });
    }

    // ----------------------------------------------------------- anthropic
    if (req.method === 'POST' && /\/messages$/.test(path)) {
      const prompt = (body.messages || []).map((m) => (typeof m.content === 'string' ? m.content : '')).join('\n');
      const text = jsonClips(prompt, requestCountExpected(prompt));

      return send(res, 200, {
        id: 'msg_mock',
        type: 'message',
        role: 'assistant',
        model: body.model,
        content: [{ type: 'text', text }],
        stop_reason: 'end_turn',
        usage: { input_tokens: 800, output_tokens: 120 },
      });
    }

    // ----------------------------------------------------------- gemini
    if (req.method === 'POST' && /:generateContent$/.test(path)) {
      const parts = (((body.contents || [])[0] || {}).parts) || [];
      const prompt = parts.filter((p) => p && p.text).map((p) => p.text).join('\n');
      const inline = parts.find((p) => p && p.inline_data);

      if (inline) {
        // Audio handed straight to the model: answer with timestamped segments.
        const bytes = Buffer.from(inline.inline_data.data || '', 'base64').length;
        const segments = transcriptFor(durationFromAudio(bytes));

        return send(res, 200, {
          candidates: [{ content: { parts: [{ text: JSON.stringify({ segments }) }] }, finishReason: 'STOP' }],
          usageMetadata: { promptTokenCount: 500, candidatesTokenCount: 200, totalTokenCount: 700 },
        });
      }

      const text = jsonClips(prompt, requestCountExpected(prompt));

      return send(res, 200, {
        candidates: [{ content: { parts: [{ text }] }, finishReason: 'STOP' }],
        usageMetadata: { promptTokenCount: 1200, candidatesTokenCount: 160, totalTokenCount: 1360 },
      });
    }

    // ----------------------------------------------------------- video URL fixture
    if (req.method === 'GET' && path === '/fixtures/source.mp4') {
      const fs = require('fs');
      const fs2 = require('fs');
      const dir = '/tmp/vvai-fixtures';
      const file =
        process.env.VVAI_FIXTURE_VIDEO ||
        (fs2.existsSync(dir)
          ? fs2
              .readdirSync(dir)
              .filter((f) => f.endsWith('.mp4'))
              .map((f) => ({ f, size: fs2.statSync(dir + '/' + f).size }))
              .sort((a, b) => b.size - a.size)[0]
          : undefined)
          ? dir + '/' +
            fs2
              .readdirSync(dir)
              .filter((f) => f.endsWith('.mp4'))
              .map((f) => ({ f, size: fs2.statSync(dir + '/' + f).size }))
              .sort((a, b) => b.size - a.size)[0].f
          : '/tmp/vvai-fixtures/source-640x360-26s.mp4';

      if (!fs.existsSync(file)) {
        return send(res, 404, { error: { message: 'fixture video missing' } });
      }

      const data = fs.readFileSync(file);
      res.writeHead(200, { 'content-type': 'video/mp4', 'content-length': data.length });
      return res.end(data);
    }

    return send(res, 404, { error: { message: 'mock provider has no route for ' + req.method + ' ' + path, code: 'unknown_route' } });
  };

  if (latency) {
    setTimeout(finish, latency);
  } else {
    finish();
  }
});

server.listen(PORT, '127.0.0.1', () => console.log('mock ai on ' + PORT));
