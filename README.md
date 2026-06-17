# wp-claude-bridge

A toolkit for AI-assisted WordPress / WooCommerce client work. It lets Claude (via
the Chrome extension) talk to a WordPress site through its existing JS libraries and
REST API instead of clicking through the slow admin UI.

This is the **public** repo: shells, recipes, primitives, and the base system prompt.
Per-site overlays and any client-specific context live in the private
`wp-claude-context` repo.

## Design principles

- **Latency, not complexity, is the problem.** The goal is to stop clicking through
  the slow WP admin and instead drive the underlying system directly.
- **Composition over bespoke code.** Operations are decomposed into sequences of
  *existing* WP/WC REST calls Claude orchestrates. The plugin only fills genuine gaps.
- **Fixed safe primitives only.** No arbitrary code execution against production.
- **Plan → checkpoint → execute → verify → resumable.** Mutating operations default to
  `dry_run` and return a plan for human approval. Steps are idempotent so a failed run
  can be re-run.
- **UI clicking is a fallback,** not the default.

## Three surfaces

| Surface | Lives | Role |
|---|---|---|
| **Bridge** (`bridge/`) | Client-side, Tampermonkey | Access + discovery. Auth bootstrap, runtime library discovery, curated `window.__claude.*` facades, manifest assembly. Always present, zero-install. |
| **Plugin** (`plugin/`) | Server-side PHP, optional | Server truth + gap-fillers. Deep introspection (hooks, ACF defs, DB schema), one-call site context endpoint, bounded action primitives. Installed only where wanted. |
| **Recipes** (`recipes/`) | Repo, read by Claude | Decomposed step sequences against existing endpoints, plus the execution loop. |

The Bridge runs first on any site, probes for the Plugin's context endpoint, and merges
runtime + server data into one `window.__claude.manifest` object that Claude reads as its
first action.

## Distribution model

- **Shells** (userscript, plugin PHP) change rarely → versioned self-update
  (Tampermonkey `@version`/`@updateURL`; Git Updater for the plugin).
- **Payload** (recipes, facades, prompts, context) changes constantly → fetched live
  from this repo on each access. Push to `master` = live on next access, no reinstall.
- A small `manifest.json` is fetched first; payload files are pulled by commit-pinned
  URL to dodge CDN staleness.

## Repo layout

```
claude-bridge.php          # WP plugin shell; self-updates via Git Updater
manifest.json              # version + index of payload files
bridge/
  userscript.user.js       # thin shell, self-updates via @version
  payload/
    system-prompt.base.md  # operating doctrine injected every session
    facades.js             # curated window.__claude.* helpers
    walker.js              # object-graph runtime discovery
recipes/
  merge-customers.json
  purge-failed-orders.json
shared/
  primitives.json
```

## Status

Design / scaffolding phase. Code surfaces are stubs; the architecture, base prompt, and
the first recipe decomposition are real.
