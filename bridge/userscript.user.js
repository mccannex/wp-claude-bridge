// ==UserScript==
// @name         wp-claude-bridge (deprecated)
// @namespace    https://github.com/mccannex/wp-claude-bridge
// @version      0.4.0
// @description  Superseded — bridge functionality is now built into the claude-bridge.php plugin directly. This script is a no-op and can be removed from Tampermonkey.
// @author       mccannex
// @match        *://*/wp-admin/*
// @grant        none
// ==/UserScript==

// The Claude Bridge plugin (claude-bridge.php) now enqueues walker.js and
// facades.js directly via WordPress admin_enqueue_scripts, bootstraps
// window.__claude.rest with the WP REST nonce, and assembles
// window.__claude.manifest — all without a Tampermonkey userscript.
//
// To use the bridge: install and activate claude-bridge.php on your WP site.
// You can safely delete this script from Tampermonkey.
