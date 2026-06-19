<?php
/**
 * Plugin Name: Claude Bridge
 * Description: Server-side deep layer for wp-claude-bridge. REST endpoints for site context, snippet management, hook/scheduler introspection, and DB schema.
 * Version:     2026.06.18.4
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
 *   GET    claude-bridge/v1/files/list                             directory listing (owner only)
 *   GET    claude-bridge/v1/files/read                            read a file (owner only)
 *   GET    claude-bridge/v1/files/search                          grep-style search (owner only)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Claude_Bridge {

    const REPO   = 'mccannex/wp-claude-bridge';
    const BRANCH = 'main';

    private static ?Claude_Bridge $instance = null;
    private string $version;
    private string $plugin_file;

    // =========================================================================
    // Singleton
    // =========================================================================

    private function __construct() {
        $this->version     = get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'];
        $this->plugin_file = plugin_basename( __FILE__ );
        $this->init();
    }

    private function __clone() {}

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init(): void {
        add_action( 'rest_api_init',         [ $this, 'register_routes' ] );
        add_action( 'admin_bar_menu',        [ $this, 'admin_bar_menu' ], 100 );
        add_action( 'admin_footer',          [ $this, 'admin_footer' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'admin_notices',         [ $this, 'admin_notices' ] );
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api',           [ $this, 'plugins_api' ], 10, 3 );
        add_action( 'wp_ajax_claude_bridge_check_update', [ $this, 'ajax_check_update' ] );
        add_action( 'wp_ajax_claude_bridge_run_update',   [ $this, 'ajax_run_update' ] );
        add_filter( 'code_snippets/list_table/row_actions', [ $this, 'cs_row_action_migrate' ], 10, 2 );
        add_action( 'admin_post_claude_bridge_migrate_snippet', [ $this, 'handle_migrate_snippet' ] );
        add_filter( 'all_plugins', [ $this, 'hide_from_plugins_list' ] );
    }

    // =========================================================================
    // Owner gate — all *@mccannex.net and *@colinmccann.com accounts
    // =========================================================================

    private function is_owner(): bool {
        $user = wp_get_current_user();
        if ( ! $user->exists() ) { return false; }
        $domain = substr( $user->user_email, strpos( $user->user_email, '@' ) + 1 );
        return in_array( $domain, [ 'mccannex.net', 'colinmccann.com' ], true );
    }

    public function hide_from_plugins_list( array $plugins ): array {
        if ( $this->is_owner() ) { return $plugins; }
        return array_filter( $plugins, fn( $k ) => ! str_starts_with( $k, 'wp-claude-bridge' ), ARRAY_FILTER_USE_KEY );
    }

    // =========================================================================
    // REST routes
    // =========================================================================

    public function register_routes(): void {
        $ns   = 'claude-bridge/v1';
        $auth = fn( $req ) => $this->is_owner();

        register_rest_route( $ns, '/instructions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_instructions' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/context', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_context' ],
            'permission_callback' => $auth,
        ] );

        $plugin = '(?P<plugin>wpcode|code-snippets)';
        $id     = '(?P<id>[\d]+)';

        register_rest_route( $ns, '/snippets', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_snippets_list' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, "/snippets/{$plugin}/{$id}", [
            [ 'methods' => 'GET',    'callback' => [ $this, 'rest_snippet_get' ],    'permission_callback' => $auth ],
            [ 'methods' => 'PUT',    'callback' => [ $this, 'rest_snippet_update' ], 'permission_callback' => $auth ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'rest_snippet_delete' ], 'permission_callback' => $auth ],
        ] );
        register_rest_route( $ns, "/snippets/{$plugin}", [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_snippet_create' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, "/snippets/{$plugin}/{$id}/toggle", [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_snippet_toggle' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, "/snippets/code-snippets/{$id}/migrate", [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_snippet_migrate' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, '/introspect/hooks', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_introspect_hooks' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, '/introspect/scheduler', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_introspect_scheduler' ],
            'permission_callback' => $auth,
        ] );
        register_rest_route( $ns, '/introspect/schema/(?P<table>[a-zA-Z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_introspect_schema' ],
            'permission_callback' => $auth,
            'args'                => [ 'table' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ] ],
        ] );

        $owner = fn( $req ) => $this->is_owner();

        register_rest_route( $ns, '/files/list', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_files_list' ],
            'permission_callback' => $owner,
        ] );
        register_rest_route( $ns, '/files/read', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_files_read' ],
            'permission_callback' => $owner,
        ] );
        register_rest_route( $ns, '/files/search', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_files_search' ],
            'permission_callback' => $owner,
        ] );
    }

    // =========================================================================
    // Admin toolbar — session prompt clipboard + manual update controls
    // =========================================================================

    public function admin_bar_menu( WP_Admin_Bar $bar ): void {
        if ( ! $this->is_owner() ) { return; }
        $logo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" style="width:16px;height:16px;vertical-align:middle;margin-right:5px;fill:currentColor;" aria-hidden="true"><path d="m19.6 66.5 19.7-11 .3-1-.3-.5h-1l-3.3-.2-11.2-.3L14 53l-9.5-.5-2.4-.5L0 49l.2-1.5 2-1.3 2.9.2 6.3.5 9.5.6 6.9.4L38 49.1h1.6l.2-.7-.5-.4-.4-.4L29 41l-10.6-7-5.6-4.1-3-2-1.5-2-.6-4.2 2.7-3 3.7.3.9.2 3.7 2.9 8 6.1L37 36l1.5 1.2.6-.4.1-.3-.7-1.1L33 25l-6-10.4-2.7-4.3-.7-2.6c-.3-1-.4-2-.4-3l3-4.2L28 0l4.2.6L33.8 2l2.6 6 4.1 9.3L47 29.9l2 3.8 1 3.4.3 1h.7v-.5l.5-7.2 1-8.7 1-11.2.3-3.2 1.6-3.8 3-2L61 2.6l2 2.9-.3 1.8-1.1 7.7L59 27.1l-1.5 8.2h.9l1-1.1 4.1-5.4 6.9-8.6 3-3.5L77 13l2.3-1.8h4.3l3.1 4.7-1.4 4.9-4.4 5.6-3.7 4.7-5.3 7.1-3.2 5.7.3.4h.7l12-2.6 6.4-1.1 7.6-1.3 3.5 1.6.4 1.6-1.4 3.4-8.2 2-9.6 2-14.3 3.3-.2.1.2.3 6.4.6 2.8.2h6.8l12.6 1 3.3 2 1.9 2.7-.3 2-5.1 2.6-6.8-1.6-16-3.8-5.4-1.3h-.8v.4l4.6 4.5 8.3 7.5L89 80.1l.5 2.4-1.3 2-1.4-.2-9.2-7-3.6-3-8-6.8h-.5v.7l1.8 2.7 9.8 14.7.5 4.5-.7 1.4-2.6 1-2.7-.6-5.8-8-6-9-4.7-8.2-.5.4-2.9 30.2-1.3 1.5-3 1.2-2.5-2-1.4-3 1.4-6.2 1.6-8 1.3-6.4 1.2-7.9.7-2.6v-.2H49L43 72l-9 12.3-7.2 7.6-1.7.7-3-1.5.3-2.8L24 86l10-12.8 6-7.9 4-4.6-.1-.5h-.3L17.2 77.4l-4.7.6-2-2 .2-3 1-1 8-5.5Z"/></svg>';
        $bar->add_node( [ 'id' => 'claude-bridge', 'title' => $logo . 'Claude Bridge', 'href' => '#' ] );
        $bar->add_node( [ 'parent' => 'claude-bridge', 'id' => 'claude-bridge-copy',   'title' => 'Copy session prompt', 'href' => '#', 'meta' => [ 'onclick' => 'claudeBridgeCopyPrompt(event)' ] ] );
        $bar->add_node( [ 'parent' => 'claude-bridge', 'id' => 'claude-bridge-check',  'title' => 'Check for updates',  'href' => '#', 'meta' => [ 'onclick' => 'claudeBridgeCheckUpdate(event)' ] ] );
        $bar->add_node( [ 'parent' => 'claude-bridge', 'id' => 'claude-bridge-update', 'title' => 'Update now',         'href' => '#', 'meta' => [ 'onclick' => 'claudeBridgeRunUpdate(event)' ] ] );
    }

    public function admin_footer(): void {
        if ( ! $this->is_owner() ) { return; }
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

                const snippetLines = Object.entries(s.snippets || {})
                    .map(([k,v]) => `  ${k}: ${v.active ? 'active v'+v.version : 'not active'}`)
                    .join('\n');

                const pluginNames = (s.plugins || []).map(p => p.name).join(', ');

                const wcLine = s.woocommerce
                    ? `WooCommerce ${s.woocommerce.version} (${s.woocommerce.currency}) — statuses: ${Object.keys(s.woocommerce.order_statuses || {}).join(', ')}`
                    : null;

                const text = [
                    `I'm working on my WordPress site and I'd like your help with some tasks. Please follow these operating guidelines for this session:`,
                    '',
                    <?php echo wp_json_encode( $this->doctrine() ); ?>,
                    '',
                    '---',
                    '',
                    `Site: ${s.site ? s.site.name + ' (' + s.site.url + ')' : location.origin}`,
                    `WP version: ${s.site ? s.site.wp_version : 'unknown'} | Timezone: ${s.site ? s.site.timezone : 'unknown'}`,
                    `REST root: ${m.restRoot}`,
                    '',
                    `Active plugins: ${pluginNames}`,
                    wcLine ? `\nWooCommerce: ${wcLine}` : null,
                    '',
                    `Snippet plugins:\n${snippetLines}`,
                    '',
                    `Claude Bridge v${m.bridgeVersion} — endpoints at ${m.restRoot}claude-bridge/v1/`,
                ].filter(l => l !== null).join('\n');

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
    }

    // =========================================================================
    // Admin scripts — enqueue walker + facades, bootstrap window.__claude
    // =========================================================================

    public function enqueue_scripts(): void {
        if ( ! $this->is_owner() ) { return; }

        $base = plugin_dir_url( __FILE__ );
        $ver  = $this->version;

        wp_register_script( 'claude-bridge-walker',  $base . 'assets/walker.js',  [],                      $ver, true );
        wp_register_script( 'claude-bridge-facades', $base . 'assets/facades.js', ['claude-bridge-walker'], $ver, true );
        wp_enqueue_script( 'claude-bridge-facades' );

        wp_localize_script( 'claude-bridge-facades', 'wpClaudeBridge', [
            'restRoot' => get_rest_url(),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'version'  => $this->version,
            'server'   => [
                'site'        => $this->site_info(),
                'plugins'     => $this->active_plugins(),
                'woocommerce' => $this->woocommerce(),
                'snippets'    => $this->snippet_plugins_info(),
            ],
        ] );

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
    }

    // =========================================================================
    // Self-updater
    // =========================================================================

    private function fetch_remote_version( bool $force = false ): ?string {
        $transient_key = 'claude_bridge_remote_version';
        if ( ! $force ) {
            $cached = get_transient( $transient_key );
            if ( $cached ) { return $cached; }
        }

        $url = 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/claude-bridge.php';
        $res = wp_remote_get( $url, [ 'timeout' => 8 ] );

        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
            return null;
        }

        preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', wp_remote_retrieve_body( $res ), $m );
        $version = isset( $m[1] ) ? trim( $m[1] ) : null;

        if ( $version ) {
            set_transient( $transient_key, $version, HOUR_IN_SECONDS );
        }
        return $version;
    }

    public function inject_update( $transient ) {
        if ( empty( $transient->checked ) ) { return $transient; }

        $remote = $this->fetch_remote_version();
        if ( $remote && version_compare( $remote, $this->version, '>' ) ) {
            $transient->response[ $this->plugin_file ] = (object) [
                'slug'        => dirname( $this->plugin_file ),
                'plugin'      => $this->plugin_file,
                'new_version' => $remote,
                'package'     => 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip',
                'url'         => 'https://github.com/' . self::REPO,
            ];
        }
        return $transient;
    }

    public function plugins_api( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || $args->slug !== dirname( $this->plugin_file ) ) {
            return $result;
        }
        return (object) [
            'name'     => 'Claude Bridge',
            'slug'     => dirname( $this->plugin_file ),
            'version'  => $this->fetch_remote_version() ?? $this->version,
            'homepage' => 'https://github.com/' . self::REPO,
            'sections' => [ 'description' => 'Server-side deep layer for wp-claude-bridge.' ],
        ];
    }

    public function ajax_check_update(): void {
        check_ajax_referer( 'claude_bridge_update' );
        if ( ! $this->is_owner() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ] ); }

        $remote = $this->fetch_remote_version( force: true );
        if ( ! $remote ) {
            wp_send_json_error( [ 'message' => 'Could not reach GitHub' ] );
        }
        if ( version_compare( $remote, $this->version, '>' ) ) {
            wp_send_json_success( [ 'message' => "Update available: v{$remote} → click Update now" ] );
        } else {
            wp_send_json_success( [ 'message' => 'No update needed — already on latest' ] );
        }
    }

    public function ajax_run_update(): void {
        check_ajax_referer( 'claude_bridge_update' );
        if ( ! $this->is_owner() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ] ); }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

        $remote = $this->fetch_remote_version( force: true );
        if ( ! $remote ) {
            wp_send_json_error( [ 'message' => 'Could not reach GitHub' ] );
        }

        $package  = 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $package, [ 'overwrite_package' => true ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        if ( $skin->get_errors()->has_errors() ) {
            wp_send_json_error( [ 'message' => $skin->get_error_messages() ] );
        }

        set_transient( 'claude_bridge_updated', $remote, 60 );
        wp_send_json_success( [ 'message' => "Updated to v{$remote} — reloading…", 'reload' => true ] );
    }

    public function admin_notices(): void {
        if ( ! $this->is_owner() ) { return; }

        $version = get_transient( 'claude_bridge_updated' );
        if ( $version ) {
            delete_transient( 'claude_bridge_updated' );
            printf(
                '<div class="notice notice-success is-dismissible"><p><strong>Claude Bridge updated to v%s.</strong></p></div>',
                esc_html( $version )
            );
        }

        $notice = get_transient( 'claude_bridge_migration_notice' );
        if ( $notice ) {
            delete_transient( 'claude_bridge_migration_notice' );
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $notice['type'] ),
                wp_kses( $notice['message'], [ 'a' => [ 'href' => [], 'target' => [] ], 'strong' => [] ] )
            );
        }
    }

    // =========================================================================
    // REST callbacks — /instructions + /context
    // =========================================================================

    public function rest_instructions(): WP_REST_Response {
        return rest_ensure_response( [
            'doctrine'    => $this->doctrine(),
            'site'        => $this->site_info(),
            'plugins'     => array_column( $this->active_plugins(), 'version', 'name' ),
            'woocommerce' => $this->woocommerce(),
            'snippets'    => $this->snippet_plugins_info(),
            'acf'         => $this->acf(),
            'lms'         => $this->lms(),
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
                [ 'method' => 'GET',            'path' => '/claude-bridge/v1/files/list',                    'purpose' => 'List files in a directory (?path=wp-content/plugins/foo/&depth=3)' ],
                [ 'method' => 'GET',            'path' => '/claude-bridge/v1/files/read',                    'purpose' => 'Read a file (?path=wp-content/plugins/foo/bar.php)' ],
                [ 'method' => 'GET',            'path' => '/claude-bridge/v1/files/search',                  'purpose' => 'Search file contents (?path=wp-content/plugins/foo/&pattern=my_hook&extensions=php,js)' ],
            ],
            'rest_namespaces' => $this->rest_roots(),
        ] );
    }

    public function rest_context(): WP_REST_Response {
        return rest_ensure_response( [
            'site'         => $this->site_info(),
            'plugins'      => $this->active_plugins(),
            'woocommerce'  => $this->woocommerce(),
            'post_types'   => $this->post_types(),
            'taxonomies'   => $this->taxonomies(),
            'acf'          => $this->acf(),
            'lms'          => $this->lms(),
            'snippets'     => $this->snippet_plugins_info(),
            'rest_roots'   => $this->rest_roots(),
            'capabilities' => $this->current_caps(),
        ] );
    }

    // =========================================================================
    // REST callbacks — introspect
    // =========================================================================

    public function rest_introspect_hooks(): WP_REST_Response {
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

    public function rest_introspect_scheduler(): WP_REST_Response {
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

        $crons = (array) get_option( 'cron' );
        $out   = [];
        foreach ( $crons as $timestamp => $jobs ) {
            if ( ! is_array( $jobs ) ) { continue; }
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

    public function rest_introspect_schema( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . $req['table'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return new WP_Error( 'not_found', 'Table not found.', [ 'status' => 404 ] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $columns = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
        return rest_ensure_response( [ 'table' => $table, 'columns' => $columns ] );
    }

    // =========================================================================
    // REST callbacks — file access
    // =========================================================================

    private function resolve_safe_path( string $path ): string|WP_Error {
        $abspath = realpath( ABSPATH );
        $target  = realpath( $abspath . DIRECTORY_SEPARATOR . ltrim( $path, '/\\' ) );

        if ( $target === false || ! str_starts_with( $target, $abspath ) ) {
            return new WP_Error( 'forbidden', 'Path is outside the webroot.', [ 'status' => 403 ] );
        }

        $basename = basename( $target );
        $blocked_names = [ 'wp-config.php', '.env', '.htpasswd', '.htaccess', 'wp-config-sample.php' ];
        if ( in_array( $basename, $blocked_names, true ) ) {
            return new WP_Error( 'forbidden', 'Access to this file is not permitted.', [ 'status' => 403 ] );
        }

        if ( preg_match( '/\.(key|pem|cert|crt|p12|pfx|ppk)$/i', $basename ) ) {
            return new WP_Error( 'forbidden', 'Access to this file type is not permitted.', [ 'status' => 403 ] );
        }

        return $target;
    }

    private const TEXT_EXTENSIONS = [
        'php', 'js', 'ts', 'jsx', 'tsx', 'css', 'scss', 'sass', 'less',
        'html', 'htm', 'xml', 'json', 'yaml', 'yml', 'md', 'txt',
        'ini', 'conf', 'config', 'env.example', 'gitignore', 'sh',
    ];

    private function is_readable_extension( string $path ): bool {
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $ext, self::TEXT_EXTENSIONS, true );
    }

    public function rest_files_list( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $rel_path = $req->get_param( 'path' ) ?: 'wp-content/plugins';
        $max_depth = min( (int) ( $req->get_param( 'depth' ) ?: 3 ), 6 );

        $abs = $this->resolve_safe_path( $rel_path );
        if ( is_wp_error( $abs ) ) { return $abs; }
        if ( ! is_dir( $abs ) ) {
            return new WP_Error( 'not_a_directory', 'Path is not a directory.', [ 'status' => 400 ] );
        }

        $abspath = realpath( ABSPATH );
        $files   = [];
        $limit   = 500;

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $abs, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $iter->setMaxDepth( $max_depth - 1 );

        foreach ( $iter as $item ) {
            if ( count( $files ) >= $limit ) {
                return rest_ensure_response( [ 'path' => $rel_path, 'truncated' => true, 'limit' => $limit, 'files' => $files ] );
            }
            $files[] = [
                'path' => str_replace( $abspath . DIRECTORY_SEPARATOR, '', $item->getPathname() ),
                'type' => $item->isDir() ? 'dir' : 'file',
                'size' => $item->isFile() ? $item->getSize() : null,
            ];
        }

        return rest_ensure_response( [ 'path' => $rel_path, 'truncated' => false, 'files' => $files ] );
    }

    public function rest_files_read( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $rel_path = $req->get_param( 'path' );
        if ( ! $rel_path ) {
            return new WP_Error( 'missing_param', 'path parameter is required.', [ 'status' => 400 ] );
        }

        $abs = $this->resolve_safe_path( $rel_path );
        if ( is_wp_error( $abs ) ) { return $abs; }
        if ( ! is_file( $abs ) ) {
            return new WP_Error( 'not_found', 'File not found.', [ 'status' => 404 ] );
        }
        if ( ! $this->is_readable_extension( $abs ) ) {
            return new WP_Error( 'forbidden', 'File type is not readable through this endpoint.', [ 'status' => 403 ] );
        }

        $size_limit = 200 * 1024; // 200 KB
        if ( filesize( $abs ) > $size_limit ) {
            return new WP_Error( 'too_large', 'File exceeds 200 KB read limit. Use /files/search to find specific content.', [ 'status' => 413 ] );
        }

        $contents = file_get_contents( $abs );
        if ( $contents === false ) {
            return new WP_Error( 'read_error', 'Could not read file.', [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'path'     => $rel_path,
            'size'     => filesize( $abs ),
            'modified' => gmdate( 'c', filemtime( $abs ) ),
            'contents' => $contents,
        ] );
    }

    public function rest_files_search( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $rel_path   = $req->get_param( 'path' );
        $pattern    = $req->get_param( 'pattern' );
        $extensions = array_filter( array_map( 'trim', explode( ',', $req->get_param( 'extensions' ) ?: 'php,js' ) ) );

        if ( ! $rel_path || ! $pattern ) {
            return new WP_Error( 'missing_param', 'path and pattern parameters are required.', [ 'status' => 400 ] );
        }

        $abs = $this->resolve_safe_path( $rel_path );
        if ( is_wp_error( $abs ) ) { return $abs; }
        if ( ! is_dir( $abs ) ) {
            return new WP_Error( 'not_a_directory', 'path must be a directory for search.', [ 'status' => 400 ] );
        }

        $limit   = 200;
        $matches = [];

        // Try grep first (much faster on large trees)
        $ext_args = implode( ' ', array_map( fn( $e ) => '--include=*.' . escapeshellarg( $e ), $extensions ) );
        $cmd      = sprintf(
            'grep -rn --no-messages %s -F %s %s 2>/dev/null',
            $ext_args,
            escapeshellarg( $pattern ),
            escapeshellarg( $abs )
        );

        $grep_available = function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );

        if ( $grep_available ) {
            $output = shell_exec( $cmd );
            if ( $output ) {
                $abspath = realpath( ABSPATH );
                foreach ( explode( "\n", trim( $output ) ) as $line ) {
                    if ( count( $matches ) >= $limit ) { break; }
                    if ( preg_match( '/^(.+?):(\d+):(.*)$/', $line, $m ) ) {
                        $matches[] = [
                            'file'    => str_replace( $abspath . DIRECTORY_SEPARATOR, '', $m[1] ),
                            'line'    => (int) $m[2],
                            'match'   => $m[3],
                        ];
                    }
                }
            }
        } else {
            // PHP fallback
            $abspath = realpath( ABSPATH );
            $iter    = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $abs, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iter as $item ) {
                if ( count( $matches ) >= $limit ) { break; }
                if ( ! $item->isFile() ) { continue; }
                if ( ! in_array( strtolower( $item->getExtension() ), $extensions, true ) ) { continue; }
                if ( filesize( $item->getPathname() ) > 500 * 1024 ) { continue; }

                $lines = @file( $item->getPathname(), FILE_IGNORE_NEW_LINES );
                if ( ! $lines ) { continue; }
                foreach ( $lines as $i => $text ) {
                    if ( count( $matches ) >= $limit ) { break; }
                    if ( str_contains( $text, $pattern ) ) {
                        $matches[] = [
                            'file'  => str_replace( $abspath . DIRECTORY_SEPARATOR, '', $item->getPathname() ),
                            'line'  => $i + 1,
                            'match' => $text,
                        ];
                    }
                }
            }
        }

        return rest_ensure_response( [
            'path'      => $rel_path,
            'pattern'   => $pattern,
            'method'    => $grep_available ? 'grep' : 'php',
            'truncated' => count( $matches ) >= $limit,
            'matches'   => $matches,
        ] );
    }

    // =========================================================================
    // REST callbacks — snippets
    // =========================================================================

    public function rest_snippets_list( WP_REST_Request $req ): WP_REST_Response {
        $plugin = $req->get_param( 'plugin' ) ?: 'all';
        $out    = [];
        if ( $plugin === 'all' || $plugin === 'wpcode' )        { $out = array_merge( $out, $this->wpcode_list() ); }
        if ( $plugin === 'all' || $plugin === 'code-snippets' ) { $out = array_merge( $out, $this->cs_list() ); }
        return rest_ensure_response( $out );
    }

    public function rest_snippet_get( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $plugin = $req['plugin'];
        $id     = (int) $req['id'];

        if ( $plugin === 'wpcode' ) {
            if ( ! defined( 'WPCODE_VERSION' ) ) { return $this->error_no_plugin( 'wpcode' ); }
            $post = $this->wpcode_get_post( $id );
            if ( ! $post ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
            return rest_ensure_response( $this->wpcode_format( $post ) );
        }

        if ( ! $this->cs_active() ) { return $this->error_no_plugin( 'code-snippets' ); }
        $r = $this->cs_request( 'GET', "/snippets/{$id}" );
        if ( $r['status'] === 404 ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
        return rest_ensure_response( $this->cs_format( $r['data'] ) );
    }

    public function rest_snippet_create( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $plugin = $req['plugin'];
        $fields = $req->get_json_params() ?: $req->get_body_params();

        if ( $plugin === 'wpcode' ) {
            if ( ! defined( 'WPCODE_VERSION' ) ) { return $this->error_no_plugin( 'wpcode' ); }
            $post = $this->wpcode_save( $fields );
            if ( is_wp_error( $post ) ) { return $post; }
            return rest_ensure_response( $this->wpcode_format( $post ) );
        }

        if ( ! $this->cs_active() ) { return $this->error_no_plugin( 'code-snippets' ); }
        $r = $this->cs_request( 'POST', '/snippets', $this->cs_body( $fields ) );
        if ( $r['status'] >= 400 ) { return new WP_Error( 'cs_error', 'Code Snippets error.', [ 'status' => $r['status'], 'data' => $r['data'] ] ); }
        return rest_ensure_response( $this->cs_format( $r['data'] ) );
    }

    public function rest_snippet_update( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $plugin = $req['plugin'];
        $id     = (int) $req['id'];
        $fields = $req->get_json_params() ?: $req->get_body_params();

        if ( $plugin === 'wpcode' ) {
            if ( ! defined( 'WPCODE_VERSION' ) ) { return $this->error_no_plugin( 'wpcode' ); }
            $post = $this->wpcode_get_post( $id );
            if ( ! $post ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
            $post = $this->wpcode_save( $fields, $id );
            if ( is_wp_error( $post ) ) { return $post; }
            return rest_ensure_response( $this->wpcode_format( $post ) );
        }

        if ( ! $this->cs_active() ) { return $this->error_no_plugin( 'code-snippets' ); }
        $r = $this->cs_request( 'PUT', "/snippets/{$id}", $this->cs_body( $fields ) );
        if ( $r['status'] >= 400 ) { return new WP_Error( 'cs_error', 'Code Snippets error.', [ 'status' => $r['status'], 'data' => $r['data'] ] ); }
        return rest_ensure_response( $this->cs_format( $r['data'] ) );
    }

    public function rest_snippet_toggle( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $plugin = $req['plugin'];
        $id     = (int) $req['id'];
        $body   = $req->get_json_params() ?: $req->get_body_params();

        if ( $plugin === 'wpcode' ) {
            if ( ! defined( 'WPCODE_VERSION' ) ) { return $this->error_no_plugin( 'wpcode' ); }
            $post = $this->wpcode_get_post( $id );
            if ( ! $post ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
            $active = isset( $body['active'] ) ? (bool) $body['active'] : ( $post->post_status !== 'publish' );
            wp_update_post( [ 'ID' => $id, 'post_status' => $active ? 'publish' : 'draft' ] );
            return rest_ensure_response( $this->wpcode_format( get_post( $id ) ) );
        }

        if ( ! $this->cs_active() ) { return $this->error_no_plugin( 'code-snippets' ); }
        $r_get  = $this->cs_request( 'GET', "/snippets/{$id}" );
        if ( $r_get['status'] === 404 ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
        $current = (array) $r_get['data'];
        $active  = isset( $body['active'] ) ? (bool) $body['active'] : ! (bool) $current['active'];
        $r       = $this->cs_request( 'PUT', "/snippets/{$id}", [ 'active' => $active ] );
        return rest_ensure_response( $this->cs_format( $r['data'] ) );
    }

    public function rest_snippet_delete( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $plugin = $req['plugin'];
        $id     = (int) $req['id'];

        if ( $plugin === 'wpcode' ) {
            if ( ! defined( 'WPCODE_VERSION' ) ) { return $this->error_no_plugin( 'wpcode' ); }
            $post = $this->wpcode_get_post( $id );
            if ( ! $post ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
            wp_delete_post( $id, true );
            return rest_ensure_response( [ 'deleted' => true, 'id' => $id, 'plugin' => 'wpcode' ] );
        }

        if ( ! $this->cs_active() ) { return $this->error_no_plugin( 'code-snippets' ); }
        $r = $this->cs_request( 'DELETE', "/snippets/{$id}" );
        if ( $r['status'] === 404 ) { return new WP_Error( 'not_found', 'Snippet not found.', [ 'status' => 404 ] ); }
        return rest_ensure_response( [ 'deleted' => true, 'id' => $id, 'plugin' => 'code-snippets' ] );
    }

    public function rest_snippet_migrate( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $id = (int) $req['id'];

        if ( ! $this->cs_active() )         { return $this->error_no_plugin( 'code-snippets' ); }
        if ( ! defined( 'WPCODE_VERSION' ) ) { return $this->error_no_plugin( 'wpcode' ); }

        $r = $this->cs_request( 'GET', "/snippets/{$id}" );
        if ( $r['status'] === 404 ) { return new WP_Error( 'not_found', 'Source snippet not found.', [ 'status' => 404 ] ); }

        $result = $this->do_migrate_snippet( $id );
        if ( is_wp_error( $result ) ) { return $result; }

        return rest_ensure_response( $result );
    }

    // =========================================================================
    // Admin — "Migrate to WPCode" row action + handler
    // =========================================================================

    public function cs_row_action_migrate( array $actions, $snippet ): array {
        if ( ! defined( 'WPCODE_VERSION' ) ) { return $actions; }
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=claude_bridge_migrate_snippet&snippet_id=' . (int) $snippet->id ),
            'claude_bridge_migrate_' . (int) $snippet->id
        );
        $actions['migrate_to_wpcode'] = '<a href="' . esc_url( $url ) . '">Migrate to WPCode</a>';
        return $actions;
    }

    public function handle_migrate_snippet(): void {
        $id = isset( $_GET['snippet_id'] ) ? (int) $_GET['snippet_id'] : 0;
        if ( ! $id || ! check_admin_referer( 'claude_bridge_migrate_' . $id ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        $result = $this->do_migrate_snippet( $id );
        $back   = admin_url( 'admin.php?page=snippets' );

        if ( is_wp_error( $result ) ) {
            set_transient( 'claude_bridge_migration_notice', [
                'type'    => 'error',
                'message' => 'Migration failed: ' . esc_html( $result->get_error_message() ),
            ], 60 );
        } else {
            $edit_url = admin_url( 'admin.php?page=wpcode-snippet-manager&snippet_id=' . $result['wpcode_id'] );
            $link     = '<a href="' . esc_url( $edit_url ) . '">Edit in WPCode &rarr;</a>';
            set_transient( 'claude_bridge_migration_notice', [
                'type'    => 'success',
                'message' => 'Snippet <strong>' . esc_html( $result['title'] ) . '</strong> migrated to WPCode successfully. ' . $link,
            ], 60 );
        }

        wp_safe_redirect( $back );
        exit;
    }

    // =========================================================================
    // Migration logic — shared by REST endpoint and admin button
    // =========================================================================

    private function do_migrate_snippet( int $id ): array|WP_Error {
        $r = $this->cs_request( 'GET', "/snippets/{$id}" );
        if ( $r['status'] === 404 ) { return new WP_Error( 'not_found', 'Source snippet not found.' ); }
        if ( $r['status'] >= 400 )  { return new WP_Error( 'cs_error', 'Could not read source snippet.' ); }
        $source = $this->cs_format( $r['data'] );

        $title    = $this->wpcode_unique_title( $source['title'] );
        $new_post = $this->wpcode_save( [
            'title'       => $title,
            'code'        => $source['code'],
            'code_type'   => $source['code_type'],
            'active'      => true,
            'description' => $source['description'],
            'tags'        => $source['tags'],
        ] );
        if ( is_wp_error( $new_post ) ) {
            return new WP_Error( 'wpcode_create_failed', 'Could not create WPCode snippet: ' . $new_post->get_error_message() );
        }

        update_post_meta( $new_post->ID, '_wpcode_auto_insert', 1 );
        wp_set_post_terms( $new_post->ID, 'everywhere', 'wpcode_location' );

        if ( get_post_status( $new_post->ID ) !== 'publish' ) {
            wp_delete_post( $new_post->ID, true );
            return new WP_Error( 'wpcode_verify_failed', 'WPCode snippet created but activation failed — Code Snippets version left unchanged.' );
        }

        if ( function_exists( 'Code_Snippets\deactivate_snippet' ) ) {
            \Code_Snippets\deactivate_snippet( $id );
        } else {
            $this->cs_request( 'PUT', "/snippets/{$id}", [ 'active' => false ] );
        }

        return [
            'wpcode_id'  => $new_post->ID,
            'title'      => $title,
            'source_id'  => $id,
            'migrated'   => $this->wpcode_format( $new_post ),
        ];
    }

    private function wpcode_unique_title( string $title ): string {
        global $wpdb;
        $candidate = $title;
        $suffix    = 2;
        while ( (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'wpcode' AND post_status IN ('publish','draft')",
            $candidate
        ) ) > 0 ) {
            $candidate = "{$title} ({$suffix})";
            $suffix++;
        }
        return $candidate;
    }

    // =========================================================================
    // Data helpers — site context
    // =========================================================================

    private function site_info(): array {
        return [
            'name'       => get_bloginfo( 'name' ),
            'url'        => get_bloginfo( 'url' ),
            'wp_version' => get_bloginfo( 'version' ),
            'timezone'   => wp_timezone_string(),
            'language'   => get_bloginfo( 'language' ),
        ];
    }

    private function active_plugins(): array {
        $active = get_option( 'active_plugins', [] );
        $data   = get_plugins();
        $out    = [];
        foreach ( $active as $file ) {
            $meta  = $data[ $file ] ?? [];
            $out[] = [
                'file'    => $file,
                'name'    => $meta['Name'] ?? $file,
                'version' => $meta['Version'] ?? null,
            ];
        }
        return $out;
    }

    private function woocommerce(): ?array {
        if ( ! function_exists( 'WC' ) ) { return null; }
        return [
            'version'          => WC()->version,
            'currency'         => get_woocommerce_currency(),
            'order_statuses'   => wc_get_order_statuses(),
            'payment_gateways' => array_keys( WC()->payment_gateways()->get_available_payment_gateways() ),
            'shipping_zones'   => array_map(
                fn( $z ) => [ 'id' => $z['zone_id'], 'name' => $z['zone_name'] ],
                WC_Shipping_Zones::get_zones()
            ),
        ];
    }

    private function post_types(): array {
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

    private function taxonomies(): array {
        $taxs = get_taxonomies( [ 'public' => true ], 'objects' );
        $out  = [];
        foreach ( $taxs as $slug => $obj ) {
            $out[ $slug ] = [
                'label'        => $obj->label,
                'hierarchical' => $obj->hierarchical,
                'rest_base'    => $obj->rest_base ?: $slug,
                'post_types'   => $obj->object_type,
            ];
        }
        return $out;
    }

    private function acf(): ?array {
        if ( ! function_exists( 'acf_get_field_groups' ) ) { return null; }
        $out = [];
        foreach ( acf_get_field_groups() as $group ) {
            $fields = array_map(
                fn( $f ) => [ 'key' => $f['key'], 'name' => $f['name'], 'type' => $f['type'], 'label' => $f['label'] ],
                acf_get_fields( $group['key'] ) ?: []
            );
            $out[] = [ 'key' => $group['key'], 'title' => $group['title'], 'location' => $group['location'], 'fields' => $fields ];
        }
        return $out;
    }

    private function lms(): ?array {
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
            return $lms;
        }
        if ( defined( 'TUTOR_VERSION' ) )  { return [ 'plugin' => 'tutor-lms',   'version' => TUTOR_VERSION ]; }
        if ( class_exists( 'LifterLMS' ) ) { return [ 'plugin' => 'lifterlms',   'version' => LLMS_VERSION ?? null ]; }
        if ( defined( 'LP_PLUGIN_VER' ) )  { return [ 'plugin' => 'learnpress',  'version' => LP_PLUGIN_VER ]; }
        return null;
    }

    private function snippet_plugins_info(): array {
        return [
            'wpcode'        => defined( 'WPCODE_VERSION' )        ? [ 'active' => true, 'version' => WPCODE_VERSION ]        : [ 'active' => false ],
            'code-snippets' => defined( 'CODE_SNIPPETS_VERSION' )  ? [ 'active' => true, 'version' => CODE_SNIPPETS_VERSION, 'rest' => true ] : [ 'active' => false ],
        ];
    }

    private function rest_roots(): array {
        $routes = rest_get_server()->get_routes();
        $ns     = [];
        foreach ( array_keys( $routes ) as $route ) {
            if ( preg_match( '#^/([^/]+/v\d+)#', $route, $m ) || preg_match( '#^/([^/]+)#', $route, $m ) ) {
                $ns[ $m[1] ] = true;
            }
        }
        return array_keys( $ns );
    }

    private function current_caps(): array {
        $user = wp_get_current_user();
        if ( ! $user->exists() ) { return []; }
        return array_keys( array_filter( $user->allcaps ) );
    }

    // =========================================================================
    // Snippet helpers — shared
    // =========================================================================

    private function snippet_format( string $plugin, array $data ): array {
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

    private function error_no_plugin( string $plugin ): WP_Error {
        return new WP_Error( 'plugin_inactive', "{$plugin} is not active on this site.", [ 'status' => 400 ] );
    }

    // =========================================================================
    // Snippet helpers — WP Code Pro
    // =========================================================================

    private function wpcode_list(): array {
        if ( ! defined( 'WPCODE_VERSION' ) ) { return []; }
        return array_map(
            [ $this, 'wpcode_format' ],
            get_posts( [ 'post_type' => 'wpcode', 'post_status' => [ 'publish', 'draft' ], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] )
        );
    }

    private function wpcode_get_post( int $id ): ?WP_Post {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'wpcode' ) { return null; }
        return $post;
    }

    private function wpcode_format( WP_Post $post ): array {
        $type_terms = get_the_terms( $post->ID, 'wpcode_type' );
        $tag_terms  = get_the_terms( $post->ID, 'wpcode_tags' );
        return $this->snippet_format( 'wpcode', [
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

    private function wpcode_save( array $fields, int $id = 0 ): WP_Post|WP_Error {
        global $wpdb;

        $code      = $fields['code'] ?? '';
        $code_type = $fields['code_type'] ?? 'php';

        // WPCode auto-inserts <?php, so strip any opening tag from PHP snippets.
        if ( 'php' === $code_type ) {
            $code = preg_replace( '/^\s*<\?(php)?\s*/i', '', ltrim( $code ) );
        }

        $postarr = [
            'post_type'    => 'wpcode',
            'post_title'   => sanitize_text_field( $fields['title'] ?? '' ),
            'post_content' => $code,
            'post_excerpt' => sanitize_textarea_field( $fields['description'] ?? '' ),
            'post_status'  => isset( $fields['active'] ) ? ( $fields['active'] ? 'publish' : 'draft' ) : 'draft',
        ];
        if ( $id ) { $postarr['ID'] = $id; }

        $post_id = $id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) { return $post_id; }

        // Write code directly to bypass content_save_pre filters (WP unslashing mangling
        // backslash sequences like \n, and WPCode Pro's superglobal safety check).
        $wpdb->update( $wpdb->posts, [ 'post_content' => $code ], [ 'ID' => $post_id ] );
        clean_post_cache( $post_id );

        if ( ! empty( $code_type ) ) {
            wp_set_object_terms( $post_id, sanitize_key( $code_type ), 'wpcode_type' );
        }
        if ( isset( $fields['tags'] ) ) {
            wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', (array) $fields['tags'] ), 'wpcode_tags' );
        }

        return get_post( $post_id );
    }

    // =========================================================================
    // Snippet helpers — Code Snippets
    // =========================================================================

    private function cs_active(): bool {
        return defined( 'CODE_SNIPPETS_VERSION' );
    }

    private function cs_request( string $method, string $path, ?array $body = null ): array {
        $req = new WP_REST_Request( $method, '/code-snippets/v1' . $path );
        if ( $body ) { $req->set_body_params( $body ); }
        $res = rest_do_request( $req );
        return [ 'status' => $res->get_status(), 'data' => $res->get_data() ];
    }

    private function cs_list(): array {
        if ( ! $this->cs_active() ) { return []; }
        $r = $this->cs_request( 'GET', '/snippets' );
        if ( $r['status'] !== 200 ) { return []; }
        return array_map( [ $this, 'cs_format' ], (array) $r['data'] );
    }

    private function cs_format( mixed $s ): array {
        $s = (array) $s;
        return $this->snippet_format( 'code-snippets', [
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

    private function cs_body( array $fields ): array {
        $body = [];
        if ( isset( $fields['title'] ) )       { $body['name']   = $fields['title']; }
        if ( isset( $fields['code'] ) )        { $body['code']   = $fields['code']; }
        if ( isset( $fields['code_type'] ) )   { $body['type']   = $fields['code_type']; }
        if ( isset( $fields['active'] ) )      { $body['active'] = (bool) $fields['active']; }
        if ( isset( $fields['description'] ) ) { $body['desc']   = $fields['description']; }
        if ( isset( $fields['tags'] ) )        { $body['tags']   = $fields['tags']; }
        return $body;
    }

    // =========================================================================
    // Doctrine
    // =========================================================================

    private function doctrine(): string {
        return <<<'MD'
# Claude WP Bridge — Operating Instructions

You are assisting with WordPress / WooCommerce client work through the Chrome extension.

## What's available

### Session context
`window.__claude.manifest` is pre-loaded on every admin page. Check it first:
- `.restRoot` — full REST root URL (e.g. `https://site.com/wp-json/`)
- `.bridgeVersion` — plugin version
- `.server` — site name/URL, WP version, active plugins, WooCommerce state, snippet plugins, is_admin

### JS helpers
All available in the browser console and via JS execution. Prefer these over raw fetch.

**`window.__claude.rest(path, opts)`** — authenticated fetch wrapper (nonce pre-loaded).
Returns `{ok, status, json}` (JSON pre-parsed) or `{ok, status, text}` for non-JSON responses.

**`window.__claude.api`** — REST caller with dry_run safety gate:
- `.get(path, params)` — always executes; params become query string
- `.post(path, body, opts)` / `.put()` / `.patch()` / `.delete()` — **blocked by default**

Mutations return `{dry_run: true, would: {method, path, body}}` until you pass `{dry_run: false}`:
```
// Preview (safe):
await window.__claude.api.post('wc/v3/orders/123', {status:'completed'})
// Execute:
await window.__claude.api.post('wc/v3/orders/123', {status:'completed'}, {dry_run: false})
```

**`window.__claude.store`** — wp.data wrappers:
- `.list()` — returns all registered store names
- `.select(storeName, selectorName, ...args)` — read; always safe
- `.dispatch(storeName, actionName, args, {dry_run: false})` — write; dry_run gated

**`window.__claude.elementor`** — Elementor editor helpers (only active on editor pages):
- `.getAllElements()` — flat array of all elements with settings
- `.findWidgets(widgetType)` — filter by type (e.g. `'heading'`, `'text-editor'`)
- `.getModel(elementId)` — Backbone model for an element
- `.getSetting(elementId, key)` — read one setting
- `.setWidgetSetting(elementId, settings, {dry_run: false})` — write settings
- `.save({dry_run: false})` — publish the document

**`window.__claudeWalker(roots?, maxDepth?)`** — maps the API surface of loaded JS libraries.
Call when you need to know what's available before acting:
```
window.__claudeWalker(['wp', 'elementor'])   // focused
window.__claudeWalker()                      // all defaults: wp, elementor, wcSettings, acf, jQuery
```

### REST API
Full REST root is at `window.__claude.manifest.restRoot`. Standard WP and WC endpoints
are available. Claude Bridge adds the following at `/wp-json/claude-bridge/v1/`:

| Method | Path | Notes |
|--------|------|-------|
| GET | `/context` | Full site snapshot: plugins, post types, taxonomies, ACF groups, WC, LMS |
| GET | `/snippets` | List all snippets; `?plugin=wpcode\|code-snippets` to filter |
| GET/PUT/DELETE | `/snippets/{plugin}/{id}` | Get, update, or delete one snippet |
| POST | `/snippets/{plugin}` | Create a snippet |
| POST | `/snippets/{plugin}/{id}/toggle` | Enable / disable; body: `{active: bool}` |
| POST | `/snippets/code-snippets/{id}/migrate` | Copy to WP Code Pro; body: `{delete_source: bool}` |
| GET | `/introspect/hooks` | All registered WP action/filter hooks and their callbacks |
| GET | `/introspect/scheduler` | Action Scheduler or wp-cron jobs |
| GET | `/introspect/schema/{table}` | DB column definitions; `{table}` is without the WP prefix |
| GET | `/files/list` | List files in a directory; `?path=wp-content/plugins/foo/&depth=3` |
| GET | `/files/read` | Read a file; `?path=wp-content/plugins/foo/bar.php` |
| GET | `/files/search` | Search file contents; `?path=wp-content/plugins/foo/&pattern=my_hook&extensions=php,js` |

**Snippet fields** (used for create and update): `title`, `code`, `code_type` (`php`\|`html`\|`css`\|`js`), `active` (bool), `description`, `tags` (array of strings).
`{plugin}` must be `wpcode` or `code-snippets` — check `manifest.server.snippets` to see which are active.

**WPCode conventions:**
- PUT is a full replace — always include all fields or they will be cleared.
- Never include `<?php` in PHP snippet code sent to WPCode; it auto-inserts the opening tag.
- Snippets containing superglobals (`$_GET`, `$_POST`, etc.) are saved correctly via the bridge (it bypasses WPCode Pro's content filters), but verify the saved code after writing.

**File access conventions:**
- All `path` parameters are relative to the WP root (e.g. `wp-content/plugins/gravity-forms/`).
- Start with `/files/list` to understand a plugin's structure before reading individual files.
- Use `/files/search` to locate a hook, function, or class name before reading whole files — it is much faster on large plugins.
- Files over 200 KB (typically minified/compiled) are blocked from `/files/read`; look for unminified source in a `src/` or `assets/src/` subdirectory.
- `/files/search` uses `grep` when available and falls back to PHP iteration; the response includes a `method` field so you know which ran.
- Sensitive files (`wp-config.php`, `.env`, certificate files) are blocked regardless of path.

## When to use what

1. **Gutenberg / block editor state** → `window.__claude.store` (wp.data)
2. **Elementor editor** → `window.__claude.elementor` (only on editor pages)
3. **Everything else** → REST via `window.__claude.api`
4. **Unsure what's loaded** → call `window.__claudeWalker()` first to map the page
5. **Reading plugin/theme source** → `/files/list` → `/files/search` → `/files/read`
6. **UI clicking** → last resort only; use when no API path exists

Always check `manifest.server` before fetching `/context` — it may already have what you need.

## Operating discipline

- Never assume a capability is present — verify before acting.
- All mutating operations: **plan → checkpoint → execute → verify** (resumable if interrupted).
- Show the full proposed plan and wait for explicit approval before passing `dry_run: false`.
- Prefer composing existing WP/WC REST endpoints over bespoke solutions.
- On any failure mid-sequence: STOP, report current state, let the operator take over.

## Hard limits

- No arbitrary code execution against production.
- Treat client data as confidential — never send it off-site or log it outside this session.

## Keeping the user in the loop

### Confirmations — use structured prompts
When you need approval before proceeding, use the `ask_followup_question` tool with explicit answer choices rather than asking an open-ended question. This lets the user click an option instead of typing.

Example — before executing a plan:
> Shall I proceed?
> a) Yes, run it  b) Show me the full plan first  c) Cancel

Example — after completing a task:
> What would you like to do next?
> a) Navigate to the Plugins page to verify  b) Undo the last change  c) Continue with the next task

Always offer at least one "show me more detail" option and one "cancel / stop" option alongside the primary action.

### Status narration — announce each action
Before every REST call, JS execution, or multi-step operation, output a single short status line:
```
→ Fetching active plugins
→ Deactivating Jetpack (ID 42)
→ Reading WC order #1082
→ Updating snippet 17
```
This line should appear before the action completes, so it functions as a live progress indicator. Keep it terse — it is not an explanation, just a marker.

### Verification — close every task with a concrete next step
After completing any task that changes site state, always end with:
1. **What changed** — a brief summary of every mutation (IDs, names, before/after state).
2. **Where to verify** — the specific admin page or UI element the user should look at to confirm the result (e.g. "Plugins page", "WC Orders list filtered by status=completed", "Appearance → Menus").
3. **Offer to navigate** — use `ask_followup_question` to ask if the user wants you to open that page now (`window.location.href = '...'`), so they don't have to click around manually.

Do not declare a task complete without a verification step. "It worked" is not enough — show the user where to look.
MD;
    }
}

Claude_Bridge::get_instance();
