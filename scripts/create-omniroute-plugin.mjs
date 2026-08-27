#!/usr/bin/env node
/**
 * create-omniroute-plugin.mjs — 1 command e notun OmniRoute plugin scaffold koro
 * 
 * Usage:
 *   node scripts/create-omniroute-plugin.mjs my-awesome-plugin
 *   node scripts/create-omniroute-plugin.mjs my-plugin --template=logger
 *   node scripts/create-omniroute-plugin.mjs my-plugin --dir=./custom-plugins
 * 
 * Templates: basic, logger, blocker, translator, analytics, full
 */

import { mkdir, writeFile, access } from 'fs/promises';
import { join, resolve } from 'path';

const TEMPLATES = {
  basic: {
    description: "Simple request/response logger",
    hooks: { onRequest: true, onResponse: true },
    code: `
export function onRequest(ctx) {
  console.log(\`[\${PLUGIN_NAME}] Request: \${ctx.requestId} -> \${ctx.model}\`);
  if (ctx.metadata) {
    ctx.metadata.__start = Date.now();
  }
}

export function onResponse(ctx, response) {
  const latency = Date.now() - (ctx.metadata?.__start || Date.now());
  console.log(\`[\${PLUGIN_NAME}] Response: \${ctx.requestId} in \${latency}ms\`);
  return response;
}
`.trim()
  },
  logger: {
    description: "Advanced request logger with config",
    hooks: { onRequest: true, onResponse: true, onError: true },
    config: {
      logLevel: { type: "select", default: "info", enum: ["debug","info","warn","error"], description: "Log level" },
      includeBody: { type: "boolean", default: false, description: "Include body in logs" }
    },
    code: `
export function onRequest(ctx) {
  const cfg = ctx.config || {};
  if (cfg.logLevel === 'debug' || cfg.logLevel === 'info') {
    console.log(\`[\${PLUGIN_NAME}] → \${ctx.model} | \${ctx.requestId}\`);
    if (cfg.includeBody) console.log(JSON.stringify(ctx.body).slice(0, 500));
  }
  ctx.metadata.__start = Date.now();
}

export function onResponse(ctx, response) {
  const ms = Date.now() - (ctx.metadata?.__start || Date.now());
  console.log(\`[\${PLUGIN_NAME}] ← \${ctx.requestId} | \${ms}ms\`);
  return response;
}

export function onError(ctx, error) {
  console.error(\`[\${PLUGIN_NAME}] ✖ \${ctx.requestId}: \${error.message}\`);
}
`.trim()
  },
  blocker: {
    description: "Content moderation / blocker plugin",
    hooks: { onRequest: true },
    config: {
      blockedWords: { type: "string", default: "spam,abuse", description: "Comma-separated blocked words" },
      blockMessage: { type: "string", default: "Request blocked by moderation", description: "Block message" }
    },
    code: `
export function onRequest(ctx) {
  const cfg = ctx.config || {};
  const blocked = (cfg.blockedWords || '').split(',').map(s=>s.trim().toLowerCase()).filter(Boolean);
  const body = JSON.stringify(ctx.body || '').toLowerCase();
  
  for (const word of blocked) {
    if (body.includes(word)) {
      console.warn(\`[\${PLUGIN_NAME}] Blocked word: \${word}\`);
      return {
        blocked: true,
        response: { error: { message: cfg.blockMessage || 'Blocked', code: 'blocked', banned_word: word } }
      };
    }
  }
}
`.trim()
  },
  translator: {
    description: "Auto-translate or transform messages",
    hooks: { onRequest: true, onResponse: true },
    config: {
      prefix: { type: "string", default: "[Translated] ", description: "Prefix to add" },
      enabled: { type: "boolean", default: true, description: "Enable transformation" }
    },
    code: `
export function onRequest(ctx) {
  const cfg = ctx.config || {};
  if (cfg.enabled === false) return;
  
  // Example: Add system prompt prefix
  if (ctx.body?.messages && cfg.prefix) {
    // You can modify messages here
    console.log(\`[\${PLUGIN_NAME}] Transforming request with prefix: \${cfg.prefix}\`);
    ctx.metadata.transformed = true;
  }
}

export function onResponse(ctx, response) {
  // Example: Modify response text
  // Be careful with OpenAI format!
  return response;
}
`.trim()
  },
  analytics: {
    description: "Usage analytics and Langfuse-style tracing",
    hooks: { onRequest: true, onResponse: true, onError: true, onStreamComplete: true },
    config: {
      sampleRate: { type: "number", default: 1, min: 0, max: 1, description: "Sample rate 0-1" },
      logUsage: { type: "boolean", default: true, description: "Log token usage" }
    },
    code: `
export function onRequest(ctx) {
  ctx.metadata.__start = Date.now();
  ctx.metadata.__sampled = Math.random() < (ctx.config?.sampleRate || 1);
}

export function onResponse(ctx, response) {
  if (!ctx.metadata?.__sampled) return response;
  const ms = Date.now() - (ctx.metadata.__start || Date.now());
  const usage = response?.usage || ctx.response?.usage || {};
  console.log(\`[\${PLUGIN_NAME}] \${ctx.model} | \${usage.prompt_tokens || 0}→\${usage.completion_tokens || 0} tokens | \${ms}ms\`);
  return response;
}

export function onError(ctx, error) {
  console.error(\`[\${PLUGIN_NAME}] Error: \${error.message} | Model: \${ctx.model}\`);
}

export function onStreamComplete(payload) {
  if (payload.usage && payload.timing) {
    console.log(\`[\${PLUGIN_NAME}] Stream: \${payload.model} | \${payload.usage.completion_tokens} tokens | \${payload.timing.latencyMs}ms\`);
  }
}
`.trim()
  },
  full: {
    description: "Full-featured template with all hooks",
    hooks: { onRequest: true, onResponse: true, onError: true, onInstall: true, onActivate: true, onDeactivate: true, onUninstall: true },
    config: {
      enabled: { type: "boolean", default: true, description: "Enable plugin" },
      greeting: { type: "string", default: "Hello from my plugin!", description: "Greeting message" },
      logLevel: { type: "select", default: "info", enum: ["debug","info","warn","none"], description: "Log level" }
    },
    code: `
export function onRequest(ctx) {
  if (ctx.config?.enabled === false) return;
  console.log(\`[\${PLUGIN_NAME}] \${ctx.config?.greeting || 'Hello!'} | \${ctx.requestId}\`);
  ctx.metadata.__start = Date.now();
}

export function onResponse(ctx, response) {
  const ms = Date.now() - (ctx.metadata?.__start || Date.now());
  console.log(\`[\${PLUGIN_NAME}] Done in \${ms}ms\`);
  return response;
}

export function onError(ctx, error) {
  console.error(\`[\${PLUGIN_NAME}] Error: \${error.message}\`);
}

export function onInstall(ctx) {
  console.log(\`[\${PLUGIN_NAME}] Installed v\${ctx.version}\`);
}

export function onActivate(ctx) {
  console.log(\`[\${PLUGIN_NAME}] Activated\`);
}

export function onDeactivate() {
  console.log(\`[\${PLUGIN_NAME}] Deactivated\`);
}

export function onUninstall() {
  console.log(\`[\${PLUGIN_NAME}] Uninstalled — goodbye!\`);
}
`.trim()
  }
};

function parseArgs() {
  const args = process.argv.slice(2);
  if (args.length === 0 || args.includes('--help') || args.includes('-h')) {
    console.log(`
OmniRoute Plugin Generator — 1 command e plugin banao!

Usage:
  node scripts/create-omniroute-plugin.mjs <plugin-name> [options]

Options:
  --template=<name>  Template: ${Object.keys(TEMPLATES).join(', ')} (default: full)
  --dir=<path>       Output dir (default: examples/plugins)
  --author=<name>    Author name
  --desc=<text>      Description

Examples:
  node scripts/create-omniroute-plugin.mjs my-first-plugin
  node scripts/create-omniroute-plugin.mjs rate-limiter --template=blocker
  node scripts/create-omniroute-plugin.mjs analytics-pro --template=analytics --author="Jihad"

Templates:
${Object.entries(TEMPLATES).map(([k,v])=>`  ${k.padEnd(12)} — ${v.description}`).join('\n')}
`);
    process.exit(0);
  }

  const name = args[0];
  const opts = {};
  for (const a of args.slice(1)) {
    if (a.startsWith('--')) {
      const [k,v] = a.slice(2).split('=');
      opts[k] = v || true;
    }
  }
  return { name, opts };
}

function validateName(name) {
  if (!/^[a-z0-9-]+$/.test(name)) {
    console.error(`❌ Plugin name must be kebab-case (lowercase, hyphens, numbers). Got: ${name}`);
    console.error(`   Example: my-awesome-plugin`);
    process.exit(1);
  }
  if (name.length > 100) {
    console.error(`❌ Name too long (max 100)`);
    process.exit(1);
  }
}

async function main() {
  const { name, opts } = parseArgs();
  validateName(name);

  const templateName = opts.template || 'full';
  const template = TEMPLATES[templateName];
  if (!template) {
    console.error(`❌ Unknown template: ${templateName}. Available: ${Object.keys(TEMPLATES).join(', ')}`);
    process.exit(1);
  }

  const outDir = resolve(opts.dir || 'examples/plugins');
  const pluginDir = join(outDir, name);

  try {
    await access(pluginDir);
    console.error(`❌ Directory already exists: ${pluginDir}`);
    console.error(`   Delete it first or choose another name`);
    process.exit(1);
  } catch {}

  await mkdir(pluginDir, { recursive: true });

  const author = opts.author || 'OmniRoute User';
  const description = opts.desc || template.description || `My ${name} plugin for OmniRoute`;

  const manifest = {
    name,
    version: "1.0.0",
    description,
    author,
    license: "MIT",
    main: "index.mjs",
    source: "local",
    tags: [templateName, "custom"],
    requires: {
      omniroute: ">=3.8.0",
      permissions: []
    },
    hooks: template.hooks || { onRequest: true, onResponse: true },
    enabledByDefault: true,
    configSchema: template.config || {
      enabled: { type: "boolean", default: true, description: "Enable/disable plugin" }
    }
  };

  const code = template.code.replaceAll('${PLUGIN_NAME}', name).replaceAll('PLUGIN_NAME', name);

  const indexContent = `/**
 * ${name} — ${description}
 * Template: ${templateName}
 * Generated by create-omniroute-plugin.mjs
 * 
 * @module ${name}
 */

${code}
`;

  const readmeContent = `# ${name}

${description}

Template: **${templateName}**

## Install

\`\`\`bash
# API diye
curl -X POST http://localhost:20128/api/plugins/install \\
  -H "Content-Type: application/json" \\
  -d '{"path": "./examples/plugins/${name}"}'

# Ba dashboard theke: http://localhost:20128/dashboard/plugins
\`\`\`

## Config

Dashboard e ba API diye:

\`\`\`bash
curl -X PUT http://localhost:20128/api/plugins/${name}/config \\
  -H "Content-Type: application/json" \\
  -d '{"enabled": true}'
\`\`\`

## Development

1. Edit \`index.mjs\`
2. Bump version in \`plugin.json\`
3. Reinstall:

\`\`\`bash
curl -X DELETE http://localhost:20128/api/plugins/${name}
curl -X POST http://localhost:20128/api/plugins/install -H "Content-Type: application/json" -d '{"path": "./examples/plugins/${name}"}'
\`\`\`

## Hooks Used

${Object.entries(manifest.hooks).filter(([,v])=>v).map(([k])=>`- ${k}`).join('\n')}

Happy coding! 🚀
`;

  await writeFile(join(pluginDir, 'plugin.json'), JSON.stringify(manifest, null, 2) + '\n', 'utf-8');
  await writeFile(join(pluginDir, 'index.mjs'), indexContent, 'utf-8');
  await writeFile(join(pluginDir, 'README.md'), readmeContent, 'utf-8');

  console.log(`
✅ Plugin created!

📁 Location: ${pluginDir}
📛 Name: ${name}
📦 Template: ${templateName}
🔧 Hooks: ${Object.keys(manifest.hooks).filter(k=>manifest.hooks[k]).join(', ')}

Next steps:
  1. Edit code: ${join(pluginDir, 'index.mjs')}
  2. Edit manifest: ${join(pluginDir, 'plugin.json')}
  3. Install:

     curl -X POST http://localhost:20128/api/plugins/install \\
       -H "Content-Type: application/json" \\
       -d '{"path": "${pluginDir}"}'

  4. Check dashboard: http://localhost:20128/dashboard/plugins

Boom! 🎉
`);
}

main().catch(err => {
  console.error('❌ Failed:', err.message);
  console.error(err.stack);
  process.exit(1);
});
