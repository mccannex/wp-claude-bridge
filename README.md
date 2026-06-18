# wp-claude-bridge

A WordPress plugin that lets Claude (via the Chrome extension) drive a logged-in WP admin session through the site's existing REST API and JS libraries — instead of clicking through the slow admin UI.

## How it works

Install the plugin on a WP site. On every admin page it:

1. Enqueues `walker.js` — discovers what JS libraries are loaded (`wp`, `elementor`, `wcSettings`, etc.) and maps their API surface.
2. Enqueues `facades.js` — exposes `window.__claude.api`, `window.__claude.store`, and `window.__claude.elementor` with a `dry_run` safety gate on all mutations.
3. Bootstraps `window.__claude.rest` — a fetch wrapper pre-loaded with the WP REST nonce so REST calls work from the browser console.
4. Assembles `window.__claude.manifest` — combines the above with server-side site context (plugins, WooCommerce state, snippet plugins, capabilities).

Claude reads `window.__claude.manifest` as its first action, then drives the site through REST and JS.

## Design principles

- **Latency, not complexity, is the problem.** Stop clicking through the WP admin UI; drive the underlying system directly.
- **Composition over bespoke code.** Operations are sequences of existing WP/WC REST calls. The plugin only fills genuine gaps.
- **No arbitrary code execution against production.**
- **Plan → checkpoint → execute → verify → resumable.** Mutating operations default to `dry_run` and return a plan for human approval.
- **UI clicking is a fallback**, not the default.

## Installation

1. Download or clone this repo.
2. Copy `claude-bridge.php` and the `bridge/` folder into your WP plugins directory as `wp-claude-bridge-main/`.
3. Activate **Claude Bridge** in WP Admin → Plugins.

That's it — no Tampermonkey userscript, no other dependencies.

## Updating

The plugin self-updates from this GitHub repo. In WP Admin:

- The toolbar **Claude Bridge → Check for updates** button checks the current version against the repo.
- **Update now** downloads and installs the latest `main` branch zip directly.
- Standard WP plugin update notifications also appear in the Plugins list when a new version is available.

After an update the page reloads automatically.

## Session workflow

1. Open any WP admin page on the target site.
2. Open the Claude Chrome extension sidebar.
3. Click **Claude Bridge → Copy session prompt** in the admin toolbar.
4. Paste into the Claude conversation to bootstrap the session with site context and operating instructions.
5. Claude can now call `window.__claude.*` helpers and REST endpoints directly.

## Repo layout

```
claude-bridge.php          # WP plugin (REST endpoints, admin scripts, self-updater)
manifest.json              # repo metadata
bridge/
  payload/
    facades.js             # window.__claude.api / .store / .elementor
    walker.js              # runtime library discovery
```

## REST endpoints

All under `/wp-json/claude-bridge/v1/`. Require `manage_options` except `/instructions`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/instructions` | Session bootstrap doc (public) |
| GET | `/context` | Full structured site snapshot |
| GET | `/snippets` | List all snippets (both snippet plugins) |
| GET | `/snippets/{plugin}/{id}` | Get one snippet |
| POST | `/snippets/{plugin}` | Create a snippet |
| PUT | `/snippets/{plugin}/{id}` | Update a snippet |
| DELETE | `/snippets/{plugin}/{id}` | Delete a snippet |
| POST | `/snippets/{plugin}/{id}/toggle` | Enable / disable a snippet |
| POST | `/snippets/code-snippets/{id}/migrate` | Migrate snippet → WP Code Pro |
| GET | `/introspect/hooks` | All registered WP hooks |
| GET | `/introspect/scheduler` | Action Scheduler / wp-cron jobs |
| GET | `/introspect/schema/{table}` | DB table column definitions |

Supported snippet plugins: **WP Code Pro** (`wpcode`) and **Code Snippets** (`code-snippets`).
