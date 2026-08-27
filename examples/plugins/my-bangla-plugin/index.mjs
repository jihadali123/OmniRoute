/**
 * My Bangla Plugin — OmniRoute er jonno amar prothom custom plugin
 * 
 * Eta ki kore:
 * 1. Protita request e greeting + timing add kore
 * 2. Kharap word thakle block korte pare (optional)
 * 3. Response e custom header/metadata add kore
 * 4. Error hole sundor kore log kore
 * 5. Install/activate/deactivate lifecycle handle kore
 * 
 * @module my-bangla-plugin
 */

// Helper: config theke value naoa
function getConfig(ctx) {
  return ctx?.config || {};
}

// Helper: log level check
function shouldLog(configLevel, current) {
  const levels = { debug: 0, info: 1, warn: 2, none: 3 };
  return (levels[current] ?? 1) >= (levels[configLevel] ?? 1);
}

/**
 * onRequest — protita API request er AGE cholbe
 * Ekhane tumi request block korte, modify korte, ba metadata add korte paro
 */
export function onRequest(ctx) {
  const config = getConfig(ctx);
  
  if (config.enabled === false) return;

  // 1. Timing start koro (response e latency dekhanor jonno)
  if (ctx.metadata) {
    ctx.metadata.__myPluginStart = Date.now();
    ctx.metadata.__myPluginGreeting = config.greeting || "Hello from my-bangla-plugin!";
  }

  // 2. Bad word filtering (optional)
  if (config.blockBadWords && config.badWords) {
    const badList = config.badWords.split(',').map(w => w.trim().toLowerCase()).filter(Boolean);
    const bodyStr = JSON.stringify(ctx.body || {}).toLowerCase();
    
    for (const bad of badList) {
      if (bodyStr.includes(bad)) {
        if (shouldLog(config.logLevel, 'warn')) {
          console.warn(`[my-bangla-plugin] Blocked request containing banned word: ${bad}`);
        }
        // Request block kore dao
        return {
          blocked: true,
          response: {
            error: {
              message: `Request blocked: contains banned word "${bad}"`,
              type: "moderation_blocked",
              code: "banned_word"
            }
          },
          metadata: {
            blockedBy: "my-bangla-plugin",
            reason: `banned_word:${bad}`
          }
        };
      }
    }
  }

  // 3. Logging
  if (shouldLog(config.logLevel, 'info')) {
    console.log(`[my-bangla-plugin] 🚀 Request: ${ctx.requestId} | Model: ${ctx.model} | Provider: ${ctx.provider}`);
    if (shouldLog(config.logLevel, 'debug')) {
      console.log(`[my-bangla-plugin] Body preview:`, JSON.stringify(ctx.body).slice(0, 200));
    }
  }

  // 4. Metadata add (optional)
  if (config.addMetadata && ctx.metadata) {
    ctx.metadata.pluginProcessedBy = "my-bangla-plugin";
    ctx.metadata.processedAt = new Date().toISOString();
  }
}

/**
 * onResponse — protita response er PORE cholbe
 * Ekhane tumi response modify korte paro, log korte paro
 */
export function onResponse(ctx, response) {
  const config = getConfig(ctx);
  
  if (config.enabled === false) return response;

  const start = ctx.metadata?.__myPluginStart || Date.now();
  const latency = Date.now() - start;

  if (shouldLog(config.logLevel, 'info')) {
    console.log(`[my-bangla-plugin] ✅ Response: ${ctx.requestId} | Latency: ${latency}ms`);
  }

  // Response e custom field add korte chaile (example)
  // Note: OmniRoute er response structure er upor depend kore
  // Eta sudhu example — chaile comment out kore rakho
  if (config.addMetadata && response && typeof response === 'object') {
    // Jodi response OpenAI format e hoy, tahole metadata inject kora jabe na,
    // kintu tumi chaile console e extra info dekhate paro
    if (shouldLog(config.logLevel, 'debug')) {
      console.log(`[my-bangla-plugin] Greeting was: ${ctx.metadata?.__myPluginGreeting}`);
    }
  }

  // Response return kortei hobe (modify kore ba same)
  return response;
}

/**
 * onError — kono error hole cholbe
 */
export function onError(ctx, error) {
  const config = getConfig(ctx);
  
  if (shouldLog(config.logLevel, 'warn')) {
    console.error(`[my-bangla-plugin] ❌ Error for ${ctx.requestId}:`, {
      model: ctx.model,
      provider: ctx.provider,
      error: error?.message || String(error),
      stack: config.logLevel === 'debug' ? error?.stack : undefined
    });
  }

  // Chaile error recover o korte paro, kintu ekhane just log korlam
}

/**
 * onStreamComplete — streaming sesh hole usage/timing data pabe (v3.8.50+)
 */
export function onStreamComplete(payload) {
  const { status, usage, timing, model, provider } = payload || {};
  console.log(`[my-bangla-plugin] 📊 Stream complete: ${model}@${provider} | Status: ${status} | Tokens: ${usage?.prompt_tokens || 0} -> ${usage?.completion_tokens || 0} | Latency: ${timing?.latencyMs}ms`);
}

/**
 * Lifecycle hooks — plugin install/activate/deactivate er somoy cholbe
 */

export function onInstall(ctx) {
  console.log(`[my-bangla-plugin] 🎉 Installed! Version: ${ctx?.version || '1.0.0'}`);
  console.log(`[my-bangla-plugin] ${ctx?.manifest?.description || ''}`);
}

export function onActivate(ctx) {
  console.log(`[my-bangla-plugin] 🟢 Activated! Config:`, ctx?.config || {});
  console.log(`[my-bangla-plugin] Greeting: ${ctx?.config?.greeting || 'default'}`);
}

export function onDeactivate() {
  console.log(`[my-bangla-plugin] 🔴 Deactivated — abar activate koro jokhon dorkar`);
}

export function onUninstall(ctx) {
  console.log(`[my-bangla-plugin] 🗑️ Uninstalling v${ctx?.version || 'unknown'} — dhonnobad use korar jonno!`);
}
