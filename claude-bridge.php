<?php
/**
 * Plugin Name: Claude Bridge
 * Description: Server-side deep layer for wp-claude-bridge. REST endpoints for site context, snippet management, hook/scheduler introspection, and DB schema.
 * Version:     2026.06.17.9
 * GitHub Plugin URI: https://github.com/mccannex/wp-claude-bridge
 * Primary Branch:    main
 * Release Asset:     true
 *
 * Endpoints:
 *   GET    claude-bridge/v1/instructions                        markdown briefing for session bootstrap
 *   GET    claude-bridge/v1/context                             one-call site snapshot
 *   GET    claude-bridge/v1/snippets                           list all snippets (both plugins)
 *   GET    claude-bridge/v1/snippets/{plugin}/{id}             get one snippet
 *   POST   claude-bridge/v1/snippets/{plugin}                  create snippet
 *   PUT    claude-bridge/v1/snippets/{plugin}/{id}             update snippet
 *   POST   claude-bridge/v1/snippets/{plugin}/{id}/toggle      enable/disable
 *   DELETE claude-bridge/v1/snippets/{plugin}/{id}             delete
 *   POST   claude-bridge/v1/snippets/code-snippets/{id}/migrate migrate → WP Code Pro
 *   GET    claude-bridge/v1/introspect/hooks
 *   GET    claude-bridge/v1/introspect/scheduler
 *   GET    claude-bridge/v1/introspect/schema/{table}
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CLAUDE_BRIDGE_REPO',        'mccannex/wp-claude-bridge' );
define( 'CLAUDE_BRIDGE_BRANCH',      'main' );
define( 'CLAUDE_BRIDGE_PLUGIN_FILE', plugin_basename( __FILE__ ) );
// Read version from own header so the pre-commit bump is the single source of truth.
define( 'CLAUDE_BRIDGE_VERSION', get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'] );

add_action( 'rest_api_init', function () {

    $ns = 'claude-bridge/v1';
    $auth = fn( $req ) => current_user_can( 'manage_options' );

    // -------------------------------------------------------------------------
    // GET /instructions
    // -------------------------------------------------------------------------
    register_rest_route( $ns, '/instructions', [
        'methods'             => 'GET',
        'callback'            => 'claude_bridge_instructions',
        'permission_callback' => '__return_true', // intentionally public — read-only bootstrap doc
    ] );

    // -------------------------------------------------------------------------
    // GET /context
    // -------------------------------------------------------------------------
    register_rest_route( $ns, '/context', [
        'methods'             => 'GET',
        'callback'            => 'claude_bridge_context',
        'permission_callback' => $auth,
    ] );

    // -------------------------------------------------------------------------
    // Snippets — plugin slug is 'wpcode' or 'code-snippets'
    // -------------------------------------------------------------------------
    $plugin_regex = '(?P<plugin>wpcode|code-snippets)';
    $id_regex     = '(?P<id>[\d]+)';

    register_rest_route( $ns, '/snippets', [
        'methods'             => 'GET',
        'callback'            => 'claude_bridge_snippets_list',
        'permission_callback' => $auth,
    ] );
    register_rest_route( $ns, "/snippets/{$plugin_regex}/{$id_regex}", [
        [
            'methods'             => 'GET',
            'callback'            => 'claude_bridge_snippet_get',
            'permission_callback' => $auth,
        ],
        [
            'methods'             => 'PUT',
            'callback'            => 'claude_bridge_snippet_update',
            'permission_callback' => $auth,
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'claude_bridge_snippet_delete',
            'permission_callback' => $auth,
        ],
    ] );
    register_rest_route( $ns, "/snippets/{$plugin_regex}", [
        'methods'             => 'POST',
        'callback'            => 'claude_bridge_snippet_create',
        'permission_callback' => $auth,
    ] );
    register_rest_route( $ns, "/snippets/{$plugin_regex}/{$id_regex}/toggle", [
        'methods'             => 'POST',
        'callback'            => 'claude_bridge_snippet_toggle',
        'permission_callback' => $auth,
    ] );
    register_rest_route( $ns, "/snippets/code-snippets/{$id_regex}/migrate", [
        'methods'             => 'POST',
        'callback'            => 'claude_bridge_snippet_migrate',
        'permission_callback' => $auth,
    ] );

    // -------------------------------------------------------------------------
    // GET /introspect/hooks
    // -------------------------------------------------------------------------
    register_rest_route( $ns, '/introspect/hooks', [
        'methods'             => 'GET',
        'callback'            => 'claude_bridge_introspect_hooks',
        'permission_callback' => $auth,
    ] );

    // -------------------------------------------------------------------------
    // GET /introspect/scheduler
    // -------------------------------------------------------------------------
    register_rest_route( $ns, '/introspect/scheduler', [
        'methods'             => 'GET',
        'callback'            => 'claude_bridge_introspect_scheduler',
        'permission_callback' => $auth,
    ] );

    // -------------------------------------------------------------------------
    // GET /introspect/schema/{table}
    // -------------------------------------------------------------------------
    register_rest_route( $ns, '/introspect/schema/(?P<table>[a-zA-Z0-9_]+)', [
        'methods'             => 'GET',
        'callback'            => 'claude_bridge_introspect_schema',
        'permission_callback' => $auth,
        'args'                => [
            'table' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
        ],
    ] );
} );

// =============================================================================
// Admin toolbar button — fetches /instructions and copies to clipboard
// =============================================================================

add_action( 'admin_bar_menu', function ( WP_Admin_Bar $bar ) {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $logo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" style="width:16px;height:16px;vertical-align:middle;margin-right:5px;fill:currentColor;" aria-hidden="true"><path d="m19.6 66.5 19.7-11 .3-1-.3-.5h-1l-3.3-.2-11.2-.3L14 53l-9.5-.5-2.4-.5L0 49l.2-1.5 2-1.3 2.9.2 6.3.5 9.5.6 6.9.4L38 49.1h1.6l.2-.7-.5-.4-.4-.4L29 41l-10.6-7-5.6-4.1-3-2-1.5-2-.6-4.2 2.7-3 3.7.3.9.2 3.7 2.9 8 6.1L37 36l1.5 1.2.6-.4.1-.3-.7-1.1L33 25l-6-10.4-2.7-4.3-.7-2.6c-.3-1-.4-2-.4-3l3-4.2L28 0l4.2.6L33.8 2l2.6 6 4.1 9.3L47 29.9l2 3.8 1 3.4.3 1h.7v-.5l.5-7.2 1-8.7 1-11.2.3-3.2 1.6-3.8 3-2L61 2.6l2 2.9-.3 1.8-1.1 7.7L59 27.1l-1.5 8.2h.9l1-1.1 4.1-5.4 6.9-8.6 3-3.5L77 13l2.3-1.8h4.3l3.1 4.7-1.4 4.9-4.4 5.6-3.7 4.7-5.3 7.1-3.2 5.7.3.4h.7l12-2.6 6.4-1.1 7.6-1.3 3.5 1.6.4 1.6-1.4 3.4-8.2 2-9.6 2-14.3 3.3-.2.1.2.3 6.4.6 2.8.2h6.8l12.6 1 3.3 2 1.9 2.7-.3 2-5.1 2.6-6.8-1.6-16-3.8-5.4-1.3h-.8v.4l4.6 4.5 8.3 7.5L89 80.1l.5 2.4-1.3 2-1.4-.2-9.2-7-3.6-3-8-6.8h-.5v.7l1.8 2.7 9.8 14.7.5 4.5-.7 1.4-2.6 1-2.7-.6-5.8-8-6-9-4.7-8.2-.5.4-2.9 30.2-1.3 1.5-3 1.2-2.5-2-1.4-3 1.4-6.2 1.6-8 1.3-6.4 1.2-7.9.7-2.6v-.2H49L43 72l-9 12.3-7.2 7.6-1.7.7-3-1.5.3-2.8L24 86l10-12.8 6-7.9 4-4.6-.1-.5h-.3L17.2 77.4l-4.7.6-2-2 .2-3 1-1 8-5.5Z"/></svg>';
    $bar->add_node( [
        'id'    => 'claude-bridge',
        'title' => $logo . 'Claude Bridge',
        'href'  => '#',
    ] );
    $bar->add_node( [
        'parent' => 'claude-bridge',
        'id'     => 'claude-bridge-copy',
        'title'  => 'Copy session prompt',
        'href'   => '#',
        'meta'   => [ 'onclick' => 'claudeBridgeCopyPrompt(event)' ],
    ] );
    $bar->add_node( [
        'parent' => 'claude-bridge',
        'id'     => 'claude-bridge-check',
        'title'  => 'Check for updates',
        'href'   => '#',
        'meta'   => [ 'onclick' => 'claudeBridgeCheckUpdate(event)' ],
    ] );
    $bar->add_node( [
        'parent' => 'claude-bridge',
        'id'     => 'claude-bridge-update',
        'title'  => 'Update now',
        'href'   => '#',
        'meta'   => [ 'onclick' => 'claudeBridgeRunUpdate(event)' ],
    ] );
}, 100 );

add_action( 'admin_footer', function () {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    ?>
    <script>
    function claudeBridgeCopyPrompt(e) {
        e.preventDefault();
        const node  = document.getElementById('wp-admin-bar-claude-bridge-copy');
        const label = node ? node.querySelector('.ab-item') : null;

        try {
            const m = window.__claude && window.__claude.manifest;
            if (!m) { throw new Error('window.__claude.manifest not ready'); }
            const s = m.server || {};

            const wc = s.woocommerce
                ? `WooCommerce ${s.woocommerce.version} (${s.woocommerce.currency}) — statuses: ${Object.keys(s.woocommerce.order_statuses || {}).join(', ')}`
                : 'Not active';

            const snippetLines = Object.entries(s.snippets || {})
                .map(([k,v]) => `  ${k}: ${v.active ? 'active v'+v.version : 'not active'}`)
                .join('\n');

            const pluginNames = (s.plugins || []).map(p => p.name).join(', ');

            const text = [
                `I'm working on my WordPress site and I'd like your help with some tasks. Please follow these operating guidelines for this session:`,
                '',
                <?php echo wp_json_encode( CLAUDE_BRIDGE_BASE_DOCTRINE ); ?>,
                '',
                '---',
                '',
                `Site: ${s.site ? s.site.name + ' (' + s.site.url + ')' : location.origin}`,
                `WP version: ${s.site ? s.site.wp_version : 'unknown'} | Timezone: ${s.site ? s.site.timezone : 'unknown'}`,
                `REST root: ${m.restRoot}`,
                '',
                `Active plugins: ${pluginNames}`,
                '',
                `WooCommerce: ${wc}`,
                '',
                `Snippet plugins:\n${snippetLines}`,
                '',
                `Claude Bridge v${m.bridgeVersion} — endpoints at ${m.restRoot}claude-bridge/v1/`,
            ].join('\n');

            navigator.clipboard.writeText(text).then(() => {
                if (label) { label.textContent = 'Copied!'; setTimeout(() => label.textContent = 'Copy session prompt', 2000); }
            });
        } catch(err) {
            if (label) { label.textContent = 'Error — see console'; }
            console.error('[claude-bridge] copy failed:', err);
        }
    }

    async function claudeBridgeAjax(action, label, workingText) {
        const original = label ? label.textContent : '';
        if (label) label.textContent = workingText;
        const body = new URLSearchParams({
            action,
            _ajax_nonce: <?php echo wp_json_encode( wp_create_nonce( 'claude_bridge_update' ) ); ?>,
        });
        try {
            const res  = await fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body });
            const data = await res.json();
            if (label) {
                label.textContent = data.data?.message || (data.success ? 'Done' : 'Failed');
                setTimeout(() => label.textContent = original, 4000);
            }
            return data;
        } catch(err) {
            if (label) { label.textContent = 'Error — see console'; setTimeout(() => label.textContent = original, 4000); }
            console.error('[claude-bridge]', err);
        }
    }

    async function claudeBridgeCheckUpdate(e) {
        e.preventDefault();
        const node  = document.getElementById('wp-admin-bar-claude-bridge-check');
        const label = node ? node.querySelector('.ab-item') : null;
        await claudeBridgeAjax('claude_bridge_check_update', label, 'Checking…');
    }

    async function claudeBridgeRunUpdate(e) {
        e.preventDefault();
        const node  = document.getElementById('wp-admin-bar-claude-bridge-update');
        const label = node ? node.querySelector('.ab-item') : null;
        const result = await claudeBridgeAjax('claude_bridge_run_update', label, 'Updating…');
        if (result && result.data?.reload) {
            setTimeout(() => location.reload(), 1500);
        }
    }
    </script>
    <?php
} );

// =============================================================================
// Admin scripts — enqueue walker + facades directly as WP scripts
// =============================================================================

add_action( 'admin_enqueue_scripts', function () {
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    $base = plugin_dir_url( __FILE__ );
    $ver  = CLAUDE_BRIDGE_VERSION;

    wp_register_script( 'claude-bridge-walker',  $base . 'bridge/payload/walker.js',  [],                       $ver, true );
    wp_register_script( 'claude-bridge-facades', $base . 'bridge/payload/facades.js', ['claude-bridge-walker'],  $ver, true );
    wp_enqueue_script( 'claude-bridge-facades' );

    // Pass REST credentials + server context to page JS safely.
    wp_localize_script( 'claude-bridge-facades', 'wpClaudeBridge', [
        'restRoot' => get_rest_url(),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'version'  => CLAUDE_BRIDGE_VERSION,
        'server'   => [
            'version'     => CLAUDE_BRIDGE_VERSION,
            'site'        => claude_bridge_site_info(),
            'plugins'     => claude_bridge_active_plugins(),
            'woocommerce' => claude_bridge_woocommerce(),
            'snippets'    => claude_bridge_snippet_plugins_info(),
            'is_admin'    => current_user_can( 'manage_options' ),
        ],
    ] );

    // Bootstrap: defines window.__claude.rest before facades.js runs.
    wp_add_inline_script( 'claude-bridge-facades', '
(function () {
  var cfg = window.wpClaudeBridge;
  window.__claude       = window.__claude || {};
  window.__claude._root  = cfg.restRoot;
  window.__claude._nonce = cfg.nonce;
  window.__claude.rest   = async function (path, opts) {
    opts = opts || {};
    var url     = cfg.restRoot.replace(/\/$/, "/") + path.replace(/^\//, "");
    var headers = Object.assign(
      { "Content-Type": "application/json" },
      cfg.nonce ? { "X-WP-Nonce": cfg.nonce } : {},
      opts.headers || {}
    );
    var res  = await fetch(url, Object.assign({ credentials: "same-origin" }, opts, { headers: headers }));
    var body = await res.text();
    try  { return { ok: res.ok, status: res.status, json: JSON.parse(body) }; }
    catch (e) { return { ok: res.ok, status: res.status, text: body }; }
  };
})();
', 'before' );

    // Manifest: assembles window.__claude.manifest after facades.js.
    // Libraries are NOT pre-walked — call window.__claudeWalker() on demand.
    wp_add_inline_script( 'claude-bridge-facades', '
(function () {
  var cfg = window.wpClaudeBridge;
  window.__claude.manifest = {
    bridgeVersion : cfg.version,
    restRoot      : cfg.restRoot,
    server        : cfg.server,
  };
  console.info("[wp-claude-bridge] ready v%s", cfg.version);
})();
', 'after' );
} );

// =============================================================================
// Self-updater — replaces Git Updater dependency
// =============================================================================

function claude_bridge_fetch_remote_version( bool $force = false ): ?string {
    $transient_key = 'claude_bridge_remote_version';
    if ( ! $force ) {
        $cached = get_transient( $transient_key );
        if ( $cached ) { return $cached; }
    }

    $url = "https://raw.githubusercontent.com/" . CLAUDE_BRIDGE_REPO . "/" . CLAUDE_BRIDGE_BRANCH . "/claude-bridge.php";
    $res = wp_remote_get( $url, [ 'timeout' => 8 ] );

    if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
        return null;
    }

    // Parse Version: header from the raw PHP file.
    preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', wp_remote_retrieve_body( $res ), $m );
    $version = isset( $m[1] ) ? trim( $m[1] ) : null;

    if ( $version ) {
        set_transient( $transient_key, $version, HOUR_IN_SECONDS );
    }
    return $version;
}

// Inject into WP's passive update check.
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
    if ( empty( $transient->checked ) ) { return $transient; }

    $remote = claude_bridge_fetch_remote_version();
    if ( $remote && version_compare( $remote, CLAUDE_BRIDGE_VERSION, '>' ) ) {
        $transient->response[ CLAUDE_BRIDGE_PLUGIN_FILE ] = (object) [
            'slug'        => dirname( CLAUDE_BRIDGE_PLUGIN_FILE ),
            'plugin'      => CLAUDE_BRIDGE_PLUGIN_FILE,
            'new_version' => $remote,
            'package'     => "https://github.com/" . CLAUDE_BRIDGE_REPO . "/archive/refs/heads/" . CLAUDE_BRIDGE_BRANCH . ".zip",
            'url'         => "https://github.com/" . CLAUDE_BRIDGE_REPO,
        ];
    }
    return $transient;
} );


// Populate the "View details" plugin info popup.
add_filter( 'plugins_api', function ( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || $args->slug !== dirname( CLAUDE_BRIDGE_PLUGIN_FILE ) ) {
        return $result;
    }
    return (object) [
        'name'     => 'Claude Bridge',
        'slug'     => dirname( CLAUDE_BRIDGE_PLUGIN_FILE ),
        'version'  => claude_bridge_fetch_remote_version() ?? CLAUDE_BRIDGE_VERSION,
        'homepage' => "https://github.com/" . CLAUDE_BRIDGE_REPO,
        'sections' => [ 'description' => 'Server-side deep layer for wp-claude-bridge.' ],
    ];
}, 10, 3 );

// =============================================================================
// AJAX — manual update check + force update
// =============================================================================

add_action( 'wp_ajax_claude_bridge_check_update', function () {
    check_ajax_referer( 'claude_bridge_update' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( [ 'message' => 'Unauthorized' ] ); }

    $remote = claude_bridge_fetch_remote_version( force: true );
    if ( ! $remote ) {
        wp_send_json_error( [ 'message' => 'Could not reach GitHub' ] );
    }
    if ( version_compare( $remote, CLAUDE_BRIDGE_VERSION, '>' ) ) {
        wp_send_json_success( [ 'message' => "Update available: v{$remote} → click Update now" ] );
    } else {
        wp_send_json_success( [ 'message' => 'No update needed — already on latest' ] );
    }
} );

add_action( 'wp_ajax_claude_bridge_run_update', function () {
    check_ajax_referer( 'claude_bridge_update' );
    if ( ! current_user_can( 'update_plugins' ) ) { wp_send_json_error( [ 'message' => 'Unauthorized' ] ); }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

    $remote = claude_bridge_fetch_remote_version( force: true );
    if ( ! $remote ) {
        wp_send_json_error( [ 'message' => 'Could not reach GitHub' ] );
    }

    $package  = "https://github.com/" . CLAUDE_BRIDGE_REPO . "/archive/refs/heads/" . CLAUDE_BRIDGE_BRANCH . ".zip";
    $skin     = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result   = $upgrader->install( $package, [ 'overwrite_package' => true ] );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }
    if ( $skin->get_errors()->has_errors() ) {
        wp_send_json_error( [ 'message' => $skin->get_error_messages() ] );
    }

    wp_send_json_success( [ 'message' => "Updated to v{$remote} — reloading…", 'reload' => true ] );
} );

// =============================================================================
// /instructions — markdown briefing assembled fresh each request
// =============================================================================

define( 'CLAUDE_BRIDGE_BASE_DOCTRINE', <<<'MD'
# Claude WP Bridge — Operating Instructions

You are assisting with WordPress / WooCommerce client work through the Chrome
extension. These are your standing instructions for this session.

## Orientation
- The Claude Bridge plugin is active on this site. Use `GET /wp-json/claude-bridge/v1/context`
  for a full structured site snapshot whenever you need details not in this briefing.
- Never assume a capability is present — check before acting. Degrade gracefully when
  something is unavailable (fall back to core REST, then to UI clicking).

## Core discipline
- Prefer composing existing WP/WC REST endpoints over bespoke actions.
- All mutating operations follow: plan → checkpoint → execute → verify → (resumable).
- Mutations default to `dry_run`. Show the full plan and wait for explicit approval
  before anything writes.
- UI clicking is a fallback, not the default. Reach for REST / JS first.

## Hard limits
- No arbitrary code execution against production.
- Treat client data as confidential — never send it off-site or log it anywhere
  outside this session.
- On any failure mid-sequence: STOP, report current state, and let the operator
  take over as admin.

## Output
- Lead with the plan / diff, not prose. Reference order / user / post IDs explicitly.
- After each step, state what changed and what you verified.
- For any multi-step operation, propose the full decomposition and wait for approval before acting.
MD
);

function claude_bridge_instructions() {
    $ctx = claude_bridge_context()->get_data();

    return rest_ensure_response( [
        'doctrine'    => CLAUDE_BRIDGE_BASE_DOCTRINE,
        'site'        => $ctx['site'],
        'plugins'     => array_column( $ctx['plugins'], 'version', 'name' ),
        'woocommerce' => $ctx['woocommerce'],
        'snippets'    => $ctx['snippets'],
        'acf'         => $ctx['acf'],
        'lms'         => $ctx['lms'],
        'bridge_endpoints' => [
            [ 'method' => 'GET',            'path' => '/claude-bridge/v1/instructions',                  'purpose' => 'This document' ],
            [ 'method' => 'GET',            'path' => '/claude-bridge/v1/context',                       'purpose' => 'Full structured site snapshot' ],
            [ 'method' => 'GET',            'path' => '/claude-bridge/v1/snippets',                      'purpose' => 'List all snippets (?plugin=wpcode|code-snippets to filter)' ],
            [ 'method' => 'GET',            'path' => '/claude-bridge/v1/snippets/{plugin}/{id}',        'purpose' => 'Get one snippet' ],
            [ 'method' => 'POST',           'path' => '/claude-bridge/v1/snippets/{plugin}',             'purpose' => 'Create a snippet' ],
            [ 'method' => 'PUT',            'path' => '/claude-bridge/v1/snippets/{plugin}/{id}',        'purpose' => 'Update a snippet' ],
            [ 'method' => 'DELETE',         'path' => '/claude-bridge/v1/snippets/{plugin}/{id}',        'purpose' => 'Delete a snippet' ],
            [ 'method' => 'POST',           'path' => '/claude-bridge/v1/snippets/{plugin}/{id}/toggle', 'purpose' => 'Enable / disable a snippet' ],
            [ 'method' => 'POST',           'path' => '/claude-bridge/v1/snippets/code-snippets/{id}/migrate', 'purpose' => 'Migrate snippet to WP Code Pro' ],
            [ 'method' => 'GET',            'path' => '/claude-bridge/v1/introspect/hooks',              'purpose' => 'All registered WP hooks' ],
            [ 'method' => 'GET',            'path' => '/claude-bridge/v1/introspect/scheduler',          'purpose' => 'Action Scheduler / wp-cron jobs' ],
            [ 'method' => 'GET',            'path' => '/claude-bridge/v1/introspect/schema/{table}',     'purpose' => 'DB table column definitions' ],
        ],
        'rest_namespaces' => $ctx['rest_roots'],
    ] );
}

// =============================================================================
// /context
// =============================================================================
function claude_bridge_context() {
    return rest_ensure_response( [
        'site'         => claude_bridge_site_info(),
        'plugins'      => claude_bridge_active_plugins(),
        'woocommerce'  => claude_bridge_woocommerce(),
        'post_types'   => claude_bridge_post_types(),
        'taxonomies'   => claude_bridge_taxonomies(),
        'acf'          => claude_bridge_acf(),
        'lms'          => claude_bridge_lms(),
        'snippets'     => claude_bridge_snippet_plugins_info(),
        'rest_roots'   => claude_bridge_rest_roots(),
        'capabilities' => claude_bridge_current_caps(),
    ] );
}

function claude_bridge_snippet_plugins_info() {
    return [
        'wpcode'        => defined( 'WPCODE_VERSION' ) ? [ 'active' => true, 'version' => WPCODE_VERSION ] : [ 'active' => false ],
        'code-snippets' => defined( 'CODE_SNIPPETS_VERSION' ) ? [ 'active' => true, 'version' => CODE_SNIPPETS_VERSION, 'rest' => true ] : [ 'active' => false ],
    ];
}

function claude_bridge_site_info() {
    return [
        'name'       => get_bloginfo( 'name' ),
        'url'        => get_bloginfo( 'url' ),
        'wp_version' => get_bloginfo( 'version' ),
        'timezone'   => wp_timezone_string(),
        'language'   => get_bloginfo( 'language' ),
    ];
}

function claude_bridge_active_plugins() {
    $active = get_option( 'active_plugins', [] );
    $data   = get_plugins();
    $out    = [];
    foreach ( $active as $file ) {
        $meta = $data[ $file ] ?? [];
        $out[] = [
            'file'    => $file,
            'name'    => $meta['Name'] ?? $file,
            'version' => $meta['Version'] ?? null,
        ];
    }
    return $out;
}

function claude_bridge_woocommerce() {
    if ( ! function_exists( 'WC' ) ) { return null; }
    return [
        'version'        => WC()->version,
        'currency'       => get_woocommerce_currency(),
        'order_statuses' => wc_get_order_statuses(),
        'payment_gateways' => array_keys( WC()->payment_gateways()->get_available_payment_gateways() ),
        'shipping_zones' => array_map(
            fn( $z ) => [ 'id' => $z->get_id(), 'name' => $z->get_zone_name() ],
            WC_Shipping_Zones::get_zones()
        ),
    ];
}

function claude_bridge_post_types() {
    $types = get_post_types( [ 'public' => true ], 'objects' );
    $out   = [];
    foreach ( $types as $slug => $obj ) {
        $out[ $slug ] = [
            'label'        => $obj->label,
            'hierarchical' => $obj->hierarchical,
            'rest_base'    => $obj->rest_base ?: $slug,
            'supports'     => get_all_post_type_supports( $slug ),
        ];
    }
    return $out;
}

function claude_bridge_taxonomies() {
    $taxs = get_taxonomies( [ 'public' => true ], 'objects' );
    $out  = [];
    foreach ( $taxs as $slug => $obj ) {
        $out[ $slug ] = [
            'label'       => $obj->label,
            'hierarchical'=> $obj->hierarchical,
            'rest_base'   => $obj->rest_base ?: $slug,
            'post_types'  => $obj->object_type,
        ];
    }
    return $out;
}

function claude_bridge_acf() {
    if ( ! function_exists( 'acf_get_field_groups' ) ) { return null; }
    $groups = acf_get_field_groups();
    $out    = [];
    foreach ( $groups as $group ) {
        $fields = array_map(
            fn( $f ) => [ 'key' => $f['key'], 'name' => $f['name'], 'type' => $f['type'], 'label' => $f['label'] ],
            acf_get_fields( $group['key'] ) ?: []
        );
        $out[] = [
            'key'      => $group['key'],
            'title'    => $group['title'],
            'location' => $group['location'],
            'fields'   => $fields,
        ];
    }
    return $out;
}

function claude_bridge_lms() {
    // Detect common LMS plugins and return their key facts.
    $lms = null;

    if ( defined( 'LEARNDASH_VERSION' ) ) {
        $lms = [ 'plugin' => 'learndash', 'version' => LEARNDASH_VERSION ];
        if ( function_exists( 'learndash_get_post_type_slug' ) ) {
            $lms['post_types'] = [
                'course' => learndash_get_post_type_slug( 'course' ),
                'lesson' => learndash_get_post_type_slug( 'lesson' ),
                'topic'  => learndash_get_post_type_slug( 'topic' ),
                'quiz'   => learndash_get_post_type_slug( 'quiz' ),
            ];
        }
    } elseif ( defined( 'TUTOR_VERSION' ) ) {
        $lms = [ 'plugin' => 'tutor-lms', 'version' => TUTOR_VERSION ];
    } elseif ( class_exists( 'LifterLMS' ) ) {
        $lms = [ 'plugin' => 'lifterlms', 'version' => LLMS_VERSION ?? null ];
    } elseif ( defined( 'LP_PLUGIN_VER' ) ) {
        $lms = [ 'plugin' => 'learnpress', 'version' => LP_PLUGIN_VER ];
    }

    return $lms;
}

function claude_bridge_rest_roots() {
    // Return every registered REST namespace so Claude knows what's callable.
    $server = rest_get_server();
    $routes = $server->get_routes();
    $ns     = [];
    foreach ( array_keys( $routes ) as $route ) {
        // Extract the namespace (everything before the second /)
        if ( preg_match( '#^/([^/]+/v\d+)#', $route, $m ) || preg_match( '#^/([^/]+)#', $route, $m ) ) {
            $ns[ $m[1] ] = true;
        }
    }
    return array_keys( $ns );
}

function claude_bridge_current_caps() {
    $user = wp_get_current_user();
    if ( ! $user->exists() ) { return []; }
    return array_keys( array_filter( $user->allcaps ) );
}

// =============================================================================
// /introspect/hooks
// =============================================================================
function claude_bridge_introspect_hooks() {
    global $wp_filter;
    $out = [];
    foreach ( $wp_filter as $tag => $hook ) {
        $callbacks = [];
        foreach ( $hook->callbacks as $priority => $cbs ) {
            foreach ( $cbs as $cb ) {
                $fn = $cb['function'];
                if ( is_string( $fn ) ) {
                    $label = $fn;
                } elseif ( is_array( $fn ) ) {
                    $label = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1];
                } else {
                    $label = '{closure}';
                }
                $callbacks[] = [ 'priority' => $priority, 'accepted_args' => $cb['accepted_args'], 'callback' => $label ];
            }
        }
        $out[ $tag ] = $callbacks;
    }
    return rest_ensure_response( $out );
}

// =============================================================================
// /introspect/scheduler
// =============================================================================
function claude_bridge_introspect_scheduler() {
    // Action Scheduler (WooCommerce, etc.)
    if ( class_exists( 'ActionScheduler' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'actionscheduler_actions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT hook, status, COUNT(*) AS count FROM {$table} GROUP BY hook, status ORDER BY hook, status",
            ARRAY_A
        );
        return rest_ensure_response( [ 'source' => 'action-scheduler', 'summary' => $rows ] );
    }

    // Fall back to wp_cron
    $crons = (array) get_option( 'cron' );
    $out   = [];
    foreach ( $crons as $timestamp => $jobs ) {
        if ( ! is_array( $jobs ) ) { continue; } // skip 'version' key
        foreach ( $jobs as $hook => $variants ) {
            foreach ( $variants as $args ) {
                $out[] = [
                    'hook'     => $hook,
                    'next_run' => gmdate( 'c', (int) $timestamp ),
                    'interval' => $args['interval'] ?? null,
                    'schedule' => $args['schedule'] ?? null,
                ];
            }
        }
    }
    return rest_ensure_response( [ 'source' => 'wp-cron', 'jobs' => $out ] );
}

// =============================================================================
// /introspect/schema/{table}
// =============================================================================
function claude_bridge_introspect_schema( WP_REST_Request $req ) {
    global $wpdb;
    $table = $wpdb->prefix . $req['table'];

    // Reject tables that don't exist to avoid leaking info about arbitrary names.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        return new WP_Error( 'not_found', 'Table not found.', [ 'status' => 404 ] );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $columns = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
    return rest_ensure_response( [ 'table' => $table, 'columns' => $columns ] );
}

// =============================================================================
// SNIPPETS — shared helpers
// =============================================================================

// Normalised snippet shape returned by every endpoint.
function claude_bridge_snippet_format( $plugin, $data ) {
    return [
        'plugin'      => $plugin,
        'id'          => (int) $data['id'],
        'title'       => $data['title'],
        'code'        => $data['code'],
        'code_type'   => $data['code_type'] ?? 'php',
        'active'      => (bool) $data['active'],
        'description' => $data['description'] ?? '',
        'tags'        => $data['tags'] ?? [],
        'created'     => $data['created'] ?? null,
        'modified'    => $data['modified'] ?? null,
    ];
}

function claude_bridge_error_no_plugin( $plugin ) {
    return new WP_Error( 'plugin_inactive', "{$plugin} is not active on this site.", [ 'status' => 400 ] );
}

// =============================================================================
// SNIPPETS — WP Code Pro (wpcode custom post type)
// =============================================================================

function claude_bridge_wpcode_list() {
    if ( ! defined( 'WPCODE_VERSION' ) ) { return []; }
    $posts = get_posts( [
        'post_type'      => 'wpcode',
        'post_status'    => [ 'publish', 'draft' ],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    return array_map( 'claude_bridge_wpcode_format', $posts );
}

function claude_bridge_wpcode_get_post( $id ) {
    $post = get_post( (int) $id );
    if ( ! $post || $post->post_type !== 'wpcode' ) { return null; }
    return $post;
}

function claude_bridge_wpcode_format( $post ) {
    $type_terms = get_the_terms( $post->ID, 'wpcode_type' );
    $tag_terms  = get_the_terms( $post->ID, 'wpcode_tags' );
    return claude_bridge_snippet_format( 'wpcode', [
        'id'          => $post->ID,
        'title'       => $post->post_title,
        'code'        => $post->post_content,
        'code_type'   => ( $type_terms && ! is_wp_error( $type_terms ) ) ? $type_terms[0]->slug : 'php',
        'active'      => $post->post_status === 'publish',
        'description' => $post->post_excerpt,
        'tags'        => ( $tag_terms && ! is_wp_error( $tag_terms ) ) ? wp_list_pluck( $tag_terms, 'name' ) : [],
        'created'     => $post->post_date_gmt,
        'modified'    => $post->post_modified_gmt,
    ] );
}

function claude_bridge_wpcode_save( $fields, $id = 0 ) {
    $postarr = [
        'post_type'    => 'wpcode',
        'post_title'   => sanitize_text_field( $fields['title'] ?? '' ),
        'post_content' => $fields['code'] ?? '',
        'post_excerpt' => sanitize_textarea_field( $fields['description'] ?? '' ),
        'post_status'  => isset( $fields['active'] ) ? ( $fields['active'] ? 'publish' : 'draft' ) : 'draft',
    ];
    if ( $id ) { $postarr['ID'] = $id; }

    $post_id = $id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
    if ( is_wp_error( $post_id ) ) { return $post_id; }

    if ( ! empty( $fields['code_type'] ) ) {
        wp_set_object_terms( $post_id, sanitize_key( $fields['code_type'] ), 'wpcode_type' );
    }
    if ( isset( $fields['tags'] ) ) {
        wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', (array) $fields['tags'] ), 'wpcode_tags' );
    }

    return get_post( $post_id );
}

// =============================================================================
// SNIPPETS — Code Snippets (internal REST proxy)
// =============================================================================

function claude_bridge_cs_active() {
    return defined( 'CODE_SNIPPETS_VERSION' );
}

// Dispatch an internal REST request to the code-snippets namespace.
function claude_bridge_cs_request( $method, $path, $body = null ) {
    $req = new WP_REST_Request( $method, '/code-snippets/v1' . $path );
    if ( $body ) { $req->set_body_params( $body ); }
    $res = rest_do_request( $req );
    return [ 'status' => $res->get_status(), 'data' => $res->get_data() ];
}

function claude_bridge_cs_list() {
    if ( ! claude_bridge_cs_active() ) { return []; }
    $r = claude_bridge_cs_request( 'GET', '/snippets' );
    if ( $r['status'] !== 200 ) { return []; }
    return array_map( 'claude_bridge_cs_format', (array) $r['data'] );
}

function claude_bridge_cs_format( $s ) {
    // Code Snippets returns objects or arrays depending on version.
    $s = (array) $s;
    return claude_bridge_snippet_format( 'code-snippets', [
        'id'          => $s['id'],
        'title'       => $s['name'] ?? $s['title'] ?? '',
        'code'        => $s['code'] ?? '',
        'code_type'   => $s['type'] ?? 'php',
        'active'      => (bool) ( $s['active'] ?? false ),
        'description' => $s['desc'] ?? $s['description'] ?? '',
        'tags'        => $s['tags'] ?? [],
        'created'     => $s['created'] ?? null,
        'modified'    => $s['modified'] ?? null,
    ] );
}

// Map our unified field names → Code Snippets field names for writes.
function claude_bridge_cs_body( $fields ) {
    $body = [];
    if ( isset( $fields['title'] ) )       { $body['name']   = $fields['title']; }
    if ( isset( $fields['code'] ) )        { $body['code']   = $fields['code']; }
    if ( isset( $fields['code_type'] ) )   { $body['type']   = $fields['code_type']; }
    if ( isset( $fields['active'] ) )      { $body['active'] = (bool) $fields['active']; }
    if ( isset( $fields['description'] ) ) { $body['desc']   = $fields['description']; }
    if ( isset( $fields['tags'] ) )        { $body['tags']   = $fields['tags']; }
    return $body;
}

// =============================================================================
// SNIPPETS — endpoint callbacks
// =============================================================================

function claude_bridge_snippets_list( WP_REST_Request $req ) {
    $plugin = $req->get_param( 'plugin' ) ?: 'all';
    $out    = [];
    if ( $plugin === 'all' || $plugin === 'wpcode' )        { $out = array_merge( $out, claude_bridge_wpcode_list() ); }
    if ( $plugin === 'all' || $plugin === 'code-snippets' ) { $out = array_merge( $out, claude_bridge_cs_list() ); }
    return rest_ensure_response( $out );
}

function claude_bridge_snippet_get( WP_REST_Request $req ) {
    $plugin = $req['plugin'];
    $id     = (int) $req['id'];

    if ( $plugin === 'wpcode' ) {
        if ( ! defined( 'WPCODE_VERSION' ) ) { return claude_bridge_error_no_plugin( 'wpcode' ); }
        $post = claude_bridge_wpcode_get_post( $id );
        if ( ! $post ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
        return rest_ensure_response( claude_bridge_wpcode_format( $post ) );
    }

    if ( ! claude_bridge_cs_active() ) { return claude_bridge_error_no_plugin( 'code-snippets' ); }
    $r = claude_bridge_cs_request( 'GET', "/snippets/{$id}" );
    if ( $r['status'] === 404 ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
    return rest_ensure_response( claude_bridge_cs_format( $r['data'] ) );
}

function claude_bridge_snippet_create( WP_REST_Request $req ) {
    $plugin = $req['plugin'];
    $fields = $req->get_json_params() ?: $req->get_body_params();

    if ( $plugin === 'wpcode' ) {
        if ( ! defined( 'WPCODE_VERSION' ) ) { return claude_bridge_error_no_plugin( 'wpcode' ); }
        $post = claude_bridge_wpcode_save( $fields );
        if ( is_wp_error( $post ) ) { return $post; }
        return rest_ensure_response( claude_bridge_wpcode_format( $post ) );
    }

    if ( ! claude_bridge_cs_active() ) { return claude_bridge_error_no_plugin( 'code-snippets' ); }
    $r = claude_bridge_cs_request( 'POST', '/snippets', claude_bridge_cs_body( $fields ) );
    if ( $r['status'] >= 400 ) { return new WP_Error( 'cs_error', 'Code Snippets error.', [ 'status' => $r['status'], 'data' => $r['data'] ] ); }
    return rest_ensure_response( claude_bridge_cs_format( $r['data'] ) );
}

function claude_bridge_snippet_update( WP_REST_Request $req ) {
    $plugin = $req['plugin'];
    $id     = (int) $req['id'];
    $fields = $req->get_json_params() ?: $req->get_body_params();

    if ( $plugin === 'wpcode' ) {
        if ( ! defined( 'WPCODE_VERSION' ) ) { return claude_bridge_error_no_plugin( 'wpcode' ); }
        $post = claude_bridge_wpcode_get_post( $id );
        if ( ! $post ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
        $post = claude_bridge_wpcode_save( $fields, $id );
        if ( is_wp_error( $post ) ) { return $post; }
        return rest_ensure_response( claude_bridge_wpcode_format( $post ) );
    }

    if ( ! claude_bridge_cs_active() ) { return claude_bridge_error_no_plugin( 'code-snippets' ); }
    $r = claude_bridge_cs_request( 'PUT', "/snippets/{$id}", claude_bridge_cs_body( $fields ) );
    if ( $r['status'] >= 400 ) { return new WP_Error( 'cs_error', 'Code Snippets error.', [ 'status' => $r['status'], 'data' => $r['data'] ] ); }
    return rest_ensure_response( claude_bridge_cs_format( $r['data'] ) );
}

function claude_bridge_snippet_toggle( WP_REST_Request $req ) {
    $plugin = $req['plugin'];
    $id     = (int) $req['id'];
    $body   = $req->get_json_params() ?: $req->get_body_params();

    if ( $plugin === 'wpcode' ) {
        if ( ! defined( 'WPCODE_VERSION' ) ) { return claude_bridge_error_no_plugin( 'wpcode' ); }
        $post = claude_bridge_wpcode_get_post( $id );
        if ( ! $post ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
        // Accept explicit { active: bool } or toggle current state.
        $active = isset( $body['active'] ) ? (bool) $body['active'] : ( $post->post_status !== 'publish' );
        wp_update_post( [ 'ID' => $id, 'post_status' => $active ? 'publish' : 'draft' ] );
        return rest_ensure_response( claude_bridge_wpcode_format( get_post( $id ) ) );
    }

    if ( ! claude_bridge_cs_active() ) { return claude_bridge_error_no_plugin( 'code-snippets' ); }
    $r_get  = claude_bridge_cs_request( 'GET', "/snippets/{$id}" );
    if ( $r_get['status'] === 404 ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
    $current = (array) $r_get['data'];
    $active  = isset( $body['active'] ) ? (bool) $body['active'] : ! (bool) $current['active'];
    $r       = claude_bridge_cs_request( 'PUT', "/snippets/{$id}", [ 'active' => $active ] );
    return rest_ensure_response( claude_bridge_cs_format( $r['data'] ) );
}

function claude_bridge_snippet_delete( WP_REST_Request $req ) {
    $plugin = $req['plugin'];
    $id     = (int) $req['id'];

    if ( $plugin === 'wpcode' ) {
        if ( ! defined( 'WPCODE_VERSION' ) ) { return claude_bridge_error_no_plugin( 'wpcode' ); }
        $post = claude_bridge_wpcode_get_post( $id );
        if ( ! $post ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
        wp_delete_post( $id, true );
        return rest_ensure_response( [ 'deleted' => true, 'id' => $id, 'plugin' => 'wpcode' ] );
    }

    if ( ! claude_bridge_cs_active() ) { return claude_bridge_error_no_plugin( 'code-snippets' ); }
    $r = claude_bridge_cs_request( 'DELETE', "/snippets/{$id}" );
    if ( $r['status'] === 404 ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
    return rest_ensure_response( [ 'deleted' => true, 'id' => $id, 'plugin' => 'code-snippets' ] );
}

// =============================================================================
// SNIPPETS — migration: Code Snippets → WP Code Pro
// =============================================================================

function claude_bridge_snippet_migrate( WP_REST_Request $req ) {
    $id     = (int) $req['id'];
    $body   = $req->get_json_params() ?: $req->get_body_params();
    $delete = isset( $body['delete_source'] ) ? (bool) $body['delete_source'] : false;

    if ( ! claude_bridge_cs_active() )   { return claude_bridge_error_no_plugin( 'code-snippets' ); }
    if ( ! defined( 'WPCODE_VERSION' ) ) { return claude_bridge_error_no_plugin( 'wpcode' ); }

    // Fetch the source snippet.
    $r = claude_bridge_cs_request( 'GET', "/snippets/{$id}" );
    if ( $r['status'] === 404 ) { return new WP_Error( 'not_found', 'Source snippet not found.', [ 'status' => 404 ] ); }
    $source = claude_bridge_cs_format( $r['data'] );

    // Create in WP Code Pro.
    $new_post = claude_bridge_wpcode_save( [
        'title'       => $source['title'],
        'code'        => $source['code'],
        'code_type'   => $source['code_type'],
        'active'      => false, // inactive until operator confirms
        'description' => $source['description'],
        'tags'        => $source['tags'],
    ] );
    if ( is_wp_error( $new_post ) ) { return $new_post; }

    $result = [
        'migrated'      => claude_bridge_wpcode_format( $new_post ),
        'source_id'     => $id,
        'delete_source' => $delete,
        'source_deleted'=> false,
    ];

    if ( $delete ) {
        $rd = claude_bridge_cs_request( 'DELETE', "/snippets/{$id}" );
        $result['source_deleted'] = $rd['status'] < 400;
    }

    return rest_ensure_response( $result );
}
