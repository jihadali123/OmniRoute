# Amar Prothom OmniRoute Plugin 🇧🇩

Eta holo `my-bangla-plugin` — OmniRoute er jonno akta full-featured starter plugin.

## Ki Ki Ache?

- ✅ **onRequest** — protita request er age cholbe (block/modify/metadata add)
- ✅ **onResponse** — response er pore cholbe (log/modify)
- ✅ **onError** — error hole log korbe
- ✅ **onStreamComplete** — streaming sesh hole usage/latency pabe
- ✅ **Lifecycle** — onInstall, onActivate, onDeactivate, onUninstall
- ✅ **Configurable** — Dashboard theke config change kora jabe

## Install Kivabe Korbe?

### Method 1: API diye (Running OmniRoute thakle)

```bash
curl -X POST http://localhost:20128/api/plugins/install \
  -H "Content-Type: application/json" \
  -d '{"path": "./examples/plugins/my-bangla-plugin"}'
```

### Method 2: Direct copy (Dev mode)

```bash
# Plugin folder ta OmniRoute er plugin dir e copy koro
# Default plugin dir: ~/.omniroute/plugins/ ba ./data/plugins/

cp -r examples/plugins/my-bangla-plugin ~/.omniroute/plugins/

# Tarpor OmniRoute restart koro ba scan API call koro
curl -X POST http://localhost:20128/api/plugins/scan
```

### Method 3: Dashboard diye

1. OmniRoute dashboard kholo: http://localhost:20128/dashboard/plugins
2. "Install Plugin" e click koro
3. Path dao: `examples/plugins/my-bangla-plugin`
4. Activate koro

## Config Kivabe Change Korbe?

Dashboard e plugin er config edit korte parbe, ba API diye:

```bash
curl -X PUT http://localhost:20128/api/plugins/my-bangla-plugin/config \
  -H "Content-Type: application/json" \
  -d '{
    "greeting": "Salam! Kemon acho?",
    "blockBadWords": true,
    "badWords": "spam,galagali",
    "logLevel": "debug"
  }'
```

## Nijer Moto Kivabe Banabe?

1. `plugin.json` e `name` change koro (kebab-case, e.g. `amar-super-plugin`)
2. `index.mjs` e logic lekho
3. `version` bump koro
4. Install koro!

### Example: Simple Rate Limiter Plugin

```js
// index.mjs
const counts = new Map();

export function onRequest(ctx) {
  const ip = ctx.headers?.['x-forwarded-for'] || 'unknown';
  const now = Date.now();
  const entry = counts.get(ip) || { count: 0, start: now };
  
  if (now - entry.start > 60000) { // 1 min window
    entry.count = 0;
    entry.start = now;
  }
  entry.count++;
  counts.set(ip, entry);

  if (entry.count > 100) {
    return { blocked: true, response: { error: "Rate limit exceeded" } };
  }
}
```

## Hook List

| Hook | Kobe Chole? | Ki Korte Paro? |
|------|-------------|----------------|
| `onRequest` | Request er AGE | Block, body modify, metadata add |
| `onResponse` | Response er PORE | Response modify, log |
| `onError` | Error hole | Log, recover |
| `onStreamComplete` | Streaming sesh | Usage/timing analytics |
| `onInstall` | Install er somoy | Setup |
| `onActivate` | Activate hole | Init |
| `onDeactivate` | Deactivate hole | Cleanup |
| `onUninstall` | Uninstall er somoy | Final cleanup |

## Help Lagle?

- Discord: https://discord.gg/U47eFqAXCn
- Docs: `docs/reference/` folder e
- Example plugins: `examples/plugins/` e aro 4 ta example ache

**Happy Coding! 🚀**
