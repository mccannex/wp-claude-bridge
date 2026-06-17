// ==UserScript==
// @name         wp-claude-bridge
// @namespace    https://github.com/mccannex/wp-claude-bridge
// @version      0.2.0
// @description  Thin client-side shell. Bootstraps auth, discovers loaded libraries, fetches payload from the repo, and exposes window.__claude.manifest.
// @author       mccannex
// @match        *://*/wp-admin/*
// @grant        GM_xmlhttpRequest
// @grant        GM_setValue
// @grant        GM_getValue
// @connect      raw.githubusercontent.com
// @connect      api.github.com
// @updateURL    https://raw.githubusercontent.com/mccannex/wp-claude-bridge/master/bridge/userscript.user.js
// @downloadURL  https://raw.githubusercontent.com/mccannex/wp-claude-bridge/master/bridge/userscript.user.js
// ==/UserScript==

/*
 * Shell responsibilities (see README). Implemented here: 1-3 + manifest assembly.
 * Deferred: 4 (private overlay fetch), full plugin-endpoint probe in 5.
 *
 *  1. Capture REST root + nonce for authenticated fetch.        -> bootstrapAuth()
 *  2. Fetch manifest.json, then payload by COMMIT-PINNED URL.   -> fetchPayload()
 *  3. Run walker to discover loaded libraries.                  -> (walker.js, fetched)
 *  4. Compose base prompt + per-site overlay.                   -> base only for now
 *  5. Probe plugin context endpoint; merge runtime + server.    -> probePlugin() (soft)
 *  6. Expose window.__claude.manifest.                          -> assemble()
 */
(function () {
  'use strict';

  const REPO = 'mccannex/wp-claude-bridge';
  const BRANCH = 'master'; // channel: stable=master, dev=staging (manifest.channels)

  // --- tiny GM fetch wrapper -> Promise<{status, text}> -----------------------
  function httpGet(url, headers) {
    return new Promise((resolve, reject) => {
      GM_xmlhttpRequest({
        method: 'GET',
        url,
        headers: headers || {},
        onload: (r) => resolve({ status: r.status, text: r.responseText }),
        onerror: reject,
        ontimeout: reject,
      });
    });
  }

  // --- 1. AUTH ----------------------------------------------------------------
  // WP exposes the REST root + a fresh nonce on most admin pages via wpApiSettings.
  // We surface a same-origin fetch() helper so callers never juggle the nonce.
  function bootstrapAuth() {
    const s = window.wpApiSettings || {};
    const root = s.root || (location.origin + '/wp-json/');
    const nonce = s.nonce || null;

    async function rest(path, opts = {}) {
      const url = root.replace(/\/$/, '/') + path.replace(/^\//, '');
      const headers = Object.assign(
        { 'Content-Type': 'application/json' },
        nonce ? { 'X-WP-Nonce': nonce } : {},
        opts.headers || {}
      );
      const res = await fetch(url, {
        credentials: 'same-origin', // ride the logged-in admin session
        ...opts,
        headers,
      });
      const body = await res.text();
      try { return { ok: res.ok, status: res.status, json: JSON.parse(body) }; }
      catch { return { ok: res.ok, status: res.status, text: body }; }
    }

    return { root, hasNonce: !!nonce, rest };
  }

  // --- 2. PAYLOAD (commit-pinned to dodge raw CDN staleness) ------------------
  // Resolve the branch's current commit SHA, then fetch every file at that SHA.
  // A SHA-pinned raw URL is immutable, so it's always fresh AND cacheable.
  async function resolveSha() {
    const r = await httpGet(`https://api.github.com/repos/${REPO}/commits/${BRANCH}`);
    if (r.status !== 200) throw new Error('sha resolve failed: ' + r.status);
    return JSON.parse(r.text).sha;
  }
  function rawUrl(sha, path) {
    return `https://raw.githubusercontent.com/${REPO}/${sha}/${path}`;
  }
  async function getFile(sha, path) {
    const r = await httpGet(rawUrl(sha, path));
    if (r.status !== 200) throw new Error(`fetch ${path}: ${r.status}`);
    return r.text;
  }

  async function fetchPayload() {
    const sha = await resolveSha();
    const manifest = JSON.parse(await getFile(sha, 'manifest.json'));

    const [walkerSrc, facadesSrc, basePrompt] = await Promise.all([
      getFile(sha, manifest.payload.walker),
      getFile(sha, manifest.payload.facades),
      getFile(sha, manifest.instructions),
    ]);

    return { sha, manifest, walkerSrc, facadesSrc, basePrompt };
  }

  // --- 3. WALKER --------------------------------------------------------------
  // walker.js defines window.__claudeWalker(roots?, maxDepth?). Eval the fetched
  // source, then invoke it to map the libraries actually loaded on this page.
  function runWalker(walkerSrc) {
    // eslint-disable-next-line no-eval
    (0, eval)(walkerSrc);
    if (typeof window.__claudeWalker !== 'function') {
      return { error: 'walker did not register window.__claudeWalker' };
    }
    try { return window.__claudeWalker(); }
    catch (e) { return { error: String(e) }; }
  }

  // --- 5. PLUGIN PROBE (soft; absent is fine) ---------------------------------
  async function probePlugin(auth) {
    try {
      const r = await auth.rest('claude-bridge/v1/context');
      return r.ok ? r.json : null;
    } catch { return null; }
  }

  // --- 6. ASSEMBLE ------------------------------------------------------------
  async function assemble() {
    const auth = bootstrapAuth();
    const { sha, manifest, walkerSrc, facadesSrc, basePrompt } = await fetchPayload();
    const libraries = runWalker(walkerSrc);
    const server = await probePlugin(auth);

    window.__claude = window.__claude || {};
    window.__claude.auth = auth;
    window.__claude.rest = auth.rest;
    // eslint-disable-next-line no-eval
    (0, eval)(facadesSrc);
    window.__claude.manifest = {
      bridgeVersion: '0.2.0',
      pinnedSha: sha,
      site: { origin: location.origin, restRoot: auth.root, hasNonce: auth.hasNonce },
      instructions: basePrompt,        // base only; per-site overlay deferred
      recipes: manifest.recipes,        // index of available recipes
      libraries,                        // runtime discovery results
      server,                           // plugin context, or null if not installed
    };

    console.info(
      '[wp-claude-bridge] ready @ %s — libraries: %s, plugin: %s',
      sha.slice(0, 7),
      Object.keys(libraries || {}).join(', ') || 'none',
      server ? 'present' : 'absent'
    );
    return window.__claude.manifest;
  }

  assemble().catch((e) => console.error('[wp-claude-bridge] init failed:', e));
})();
