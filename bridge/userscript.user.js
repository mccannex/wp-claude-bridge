// ==UserScript==
// @name         wp-claude-bridge
// @namespace    https://github.com/mccannex/wp-claude-bridge
// @version      0.3.0
// @description  Thin client-side shell. Bootstraps auth, discovers loaded libraries, fetches payload from the repo, and exposes window.__claude.manifest.
// @author       mccannex
// @match        *://*/wp-admin/*
// @grant        GM_xmlhttpRequest
// @grant        GM_setValue
// @grant        GM_getValue
// @connect      raw.githubusercontent.com
// @connect      api.github.com
// @updateURL    https://raw.githubusercontent.com/mccannex/wp-claude-bridge/main/bridge/userscript.user.js
// @downloadURL  https://raw.githubusercontent.com/mccannex/wp-claude-bridge/main/bridge/userscript.user.js
// ==/UserScript==

/*
 * Shell responsibilities:
 *  1. Capture REST root + nonce from wpApiSettings.
 *  2. Fetch manifest + payload files (walker, facades, prompt) via GM_xmlhttpRequest
 *     (needed for cross-origin GitHub fetches).
 *  3. Inject bootstrap + walker + facades into the PAGE via <script> elements so they
 *     run in true page context — not Tampermonkey's sandbox — and share the same
 *     window as the rest of the admin JS.
 *  4. Probe the plugin context endpoint; merge into manifest.
 *  5. Expose window.__claude.manifest.
 */
(function () {
  'use strict';

  const REPO   = 'mccannex/wp-claude-bridge';
  const BRANCH = 'main';

  // --- GM fetch wrapper -------------------------------------------------------
  function httpGet(url) {
    return new Promise((resolve, reject) => {
      GM_xmlhttpRequest({
        method: 'GET',
        url,
        onload:   (r) => resolve({ status: r.status, text: r.responseText }),
        onerror:  reject,
        ontimeout: reject,
      });
    });
  }

  // --- SHA cache (5-minute TTL to stay under unauthenticated API rate limit) --
  const SHA_CACHE_KEY = 'wp_claude_bridge_sha';
  const SHA_TTL_MS    = 5 * 60 * 1000;

  async function resolveSha() {
    try {
      const cached = JSON.parse(GM_getValue(SHA_CACHE_KEY, 'null'));
      if (cached && (Date.now() - cached.ts) < SHA_TTL_MS) { return cached.sha; }
    } catch { /* corrupt cache — fall through */ }

    const r = await httpGet(`https://api.github.com/repos/${REPO}/commits/${BRANCH}`);
    if (r.status !== 200) throw new Error('sha resolve failed: ' + r.status);
    const sha = JSON.parse(r.text).sha;
    GM_setValue(SHA_CACHE_KEY, JSON.stringify({ sha, ts: Date.now() }));
    return sha;
  }

  async function getFile(sha, path) {
    const url = `https://raw.githubusercontent.com/${REPO}/${sha}/${path}`;
    const r   = await httpGet(url);
    if (r.status !== 200) throw new Error(`fetch ${path}: ${r.status}`);
    return r.text;
  }

  async function fetchPayload() {
    const sha      = await resolveSha();
    const manifest = JSON.parse(await getFile(sha, 'manifest.json'));
    const [walkerSrc, facadesSrc, basePrompt] = await Promise.all([
      getFile(sha, manifest.payload.walker),
      getFile(sha, manifest.payload.facades),
      getFile(sha, manifest.instructions),
    ]);
    return { sha, manifest, walkerSrc, facadesSrc, basePrompt };
  }

  // --- Page-context injection -------------------------------------------------
  // Runs code in the real page window (not the TM sandbox) by appending a
  // <script> element. This is the only reliable way to share window.__claude
  // with other page scripts and avoid indirect-eval sandbox isolation.
  function injectScript(code) {
    const el = document.createElement('script');
    el.textContent = code;
    (document.head || document.documentElement).appendChild(el);
    el.remove();
  }

  // Inject the bootstrap: defines window.__claude.rest in page context with
  // the nonce and REST root serialised as string literals.
  function injectBootstrap(root, nonce) {
    injectScript(`
(function () {
  var root  = ${JSON.stringify(root)};
  var nonce = ${JSON.stringify(nonce)};
  window.__claude       = window.__claude || {};
  window.__claude._root  = root;
  window.__claude._nonce = nonce;
  window.__claude.rest   = async function (path, opts) {
    opts = opts || {};
    var url     = root.replace(/\\/$/, '/') + path.replace(/^\\//, '');
    var headers = Object.assign(
      { 'Content-Type': 'application/json' },
      nonce ? { 'X-WP-Nonce': nonce } : {},
      opts.headers || {}
    );
    var res  = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts, { headers: headers }));
    var body = await res.text();
    try  { return { ok: res.ok, status: res.status, json: JSON.parse(body) }; }
    catch { return { ok: res.ok, status: res.status, text: body }; }
  };
})();
`);
  }

  // --- Plugin probe (runs after bootstrap is injected) -----------------------
  // Uses page-context fetch via a promise resolved through a message channel
  // so we can await the result in TM context.
  function probePlugin() {
    return new Promise((resolve) => {
      const id = '__claude_probe_' + Date.now();
      window.addEventListener(id, (e) => resolve(e.detail), { once: true });
      injectScript(`
(async function () {
  try {
    var r = await window.__claude.rest('claude-bridge/v1/context');
    window.dispatchEvent(new CustomEvent(${JSON.stringify(id)}, { detail: r.ok ? r.json : null }));
  } catch { window.dispatchEvent(new CustomEvent(${JSON.stringify(id)}, { detail: null })); }
})();
`);
    });
  }

  // --- Walker (injected into page, result retrieved via CustomEvent) ----------
  function runWalker(walkerSrc) {
    return new Promise((resolve) => {
      const id = '__claude_walker_' + Date.now();
      window.addEventListener(id, (e) => resolve(e.detail), { once: true });
      injectScript(`
(function () {
  ${walkerSrc}
  var result;
  try {
    result = (typeof window.__claudeWalker === 'function')
      ? window.__claudeWalker()
      : { error: 'walker did not register window.__claudeWalker' };
  } catch (e) { result = { error: String(e) }; }
  window.dispatchEvent(new CustomEvent(${JSON.stringify(id)}, { detail: result }));
})();
`);
    });
  }

  // --- Assemble ---------------------------------------------------------------
  async function assemble() {
    const s     = window.wpApiSettings || {};
    const root  = s.root  || (location.origin + '/wp-json/');
    const nonce = s.nonce || null;

    const { sha, manifest, walkerSrc, facadesSrc, basePrompt } = await fetchPayload();

    // 1. Inject bootstrap (rest helper) into page context.
    injectBootstrap(root, nonce);

    // 2. Walker runs in page context, result comes back via CustomEvent.
    const libraries = await runWalker(walkerSrc);

    // 3. Facades run in page context; they find window.__claude.rest from step 1.
    injectScript(facadesSrc);

    // 4. Probe plugin endpoint (also page context via CustomEvent).
    const server = await probePlugin();

    // 5. Assemble manifest in page context.
    injectScript(`
(function () {
  window.__claude.manifest = {
    bridgeVersion: '0.3.0',
    pinnedSha:     ${JSON.stringify(sha)},
    site:          { origin: location.origin, restRoot: ${JSON.stringify(root)}, hasNonce: ${JSON.stringify(!!nonce)} },
    instructions:  ${JSON.stringify(basePrompt)},
    recipes:       ${JSON.stringify(manifest.recipes)},
    libraries:     ${JSON.stringify(libraries)},
    server:        ${JSON.stringify(server)},
  };
})();
`);

    console.info(
      '[wp-claude-bridge] ready @ %s — libraries: %s, plugin: %s',
      sha.slice(0, 7),
      Object.keys(libraries || {}).filter(k => !k.startsWith('__')).join(', ') || 'none',
      server ? 'present' : 'absent'
    );
  }

  assemble().catch((e) => console.error('[wp-claude-bridge] init failed:', e));
})();
