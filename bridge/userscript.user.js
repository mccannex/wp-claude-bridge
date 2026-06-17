// ==UserScript==
// @name         wp-claude-bridge
// @namespace    https://github.com/mccannex/wp-claude-bridge
// @version      0.1.0
// @description  Thin client-side shell. Bootstraps auth, discovers loaded libraries, fetches payload from the repo, and exposes window.__claude.manifest.
// @match        *://*/wp-admin/*
// @grant        GM_xmlhttpRequest
// @grant        GM_setValue
// @grant        GM_getValue
// @updateURL    https://raw.githubusercontent.com/mccannex/wp-claude-bridge/master/bridge/userscript.user.js
// @downloadURL  https://raw.githubusercontent.com/mccannex/wp-claude-bridge/master/bridge/userscript.user.js
// ==/UserScript==

// STUB — shell responsibilities (see README):
//  1. Capture REST root + nonce for authenticated fetch.
//  2. Fetch manifest.json, then payload files by commit-pinned URL.
//  3. Run walker.js to discover loaded libraries.
//  4. Probe plugin context endpoint; merge runtime + server.
//  5. Compose base prompt + per-site overlay (from wp-claude-context).
//  6. Expose window.__claude.manifest (instructions, libraries, recipes, endpoints).
(function () {
  'use strict';
  // implementation pending
})();
