/*
 * walker.js — object-graph runtime discovery.
 *
 * Registers window.__claudeWalker(roots?, maxDepth?). Walks selected page globals
 * and emits a STRUCTURAL map (shape, not values): property names, types, function
 * arity, and wp.data store IDs. Depth-limited and cycle-guarded so it can't hang
 * on circular references or huge object graphs.
 *
 * The point: let Claude learn what APIs are callable on a page without reading
 * minified source. This is the Elementor lightbulb generalized to any loaded lib.
 */
(function () {
  'use strict';

  // Default roots: the libraries we care about driving. Missing ones are skipped.
  const DEFAULT_ROOTS = ['wp', 'elementor', 'elementorFrontend', 'wcSettings', 'acf', 'jQuery'];
  const DEFAULT_DEPTH = 2;          // shallow by design — we want a map, not a dump
  const MAX_KEYS = 60;              // cap breadth per object to keep output readable

  function describe(val) {
    const t = typeof val;
    if (t === 'function') return { type: 'function', arity: val.length };
    if (val === null) return { type: 'null' };
    if (Array.isArray(val)) return { type: 'array', length: val.length };
    if (t === 'object') return { type: 'object' };
    return { type: t }; // string | number | boolean | undefined | symbol | bigint
  }

  function walk(obj, depth, seen) {
    if (depth < 0 || obj === null || typeof obj !== 'object') return undefined;
    if (seen.has(obj)) return { type: 'object', circular: true };
    seen.add(obj);

    const out = {};
    let keys;
    try { keys = Object.keys(obj); } catch { return { type: 'object', unreadable: true }; }

    const truncated = keys.length > MAX_KEYS;
    for (const k of keys.slice(0, MAX_KEYS)) {
      let v;
      try { v = obj[k]; } catch { out[k] = { type: 'unreadable' }; continue; }
      const d = describe(v);
      // Recurse one level into plain objects to expose nested API surface.
      if (d.type === 'object' && depth > 0) {
        const child = walk(v, depth - 1, seen);
        if (child && Object.keys(child).length) d.children = child;
      }
      out[k] = d;
    }
    if (truncated) out.__truncated = `${keys.length - MAX_KEYS} more keys`;
    return out;
  }

  window.__claudeWalker = function (roots, maxDepth) {
    roots = roots || DEFAULT_ROOTS;
    const depth = typeof maxDepth === 'number' ? maxDepth : DEFAULT_DEPTH;
    const result = {};

    for (const name of roots) {
      const root = window[name];
      if (root === undefined) continue; // not loaded on this page
      result[name] = walk(root, depth, new WeakSet()) || describe(root);
    }

    // Special case: wp.data store IDs are the most useful single fact about a
    // block-editor / Gutenberg page — surface them explicitly.
    try {
      if (window.wp && window.wp.data && typeof window.wp.data.select === 'function') {
        const stores = window.wp.data.stores || {};
        result.__wp_data_stores = Object.keys(stores);
      }
    } catch { /* ignore */ }

    return result;
  };
})();
