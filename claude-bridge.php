<?php
/**
 * Plugin Name: Claude Bridge
 * Description: Server-side deep layer for wp-claude-bridge. REST endpoints for site context, snippet management, hook/scheduler introspection, and DB schema.
 * Version:     2026.06.17
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
// /instructions — markdown briefing assembled fresh each request
// =============================================================================

define( 'CLAUDE_BRIDGE_BASE_DOCTRINE', <<<'MD'
# Claude WP Bridge — Operating Instructions

You are assisting with WordPress / WooCommerce client work through the Chrome
extension. These are your standing instructions on every site. A per-site overlay
may be appended below this section; where it conflicts, the overlay wins.

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

## Recipes
- For known operations load the matching recipe and follow its decomposed steps.
- If no recipe fits, propose a decomposition for review before acting.

## Output
- Lead with the plan / diff, not prose. Reference order / user / post IDs explicitly.
- After each step, state what changed and what you verified.
MD
);

function claude_bridge_instructions() {
    $ctx  = claude_bridge_context()->get_data();
    $site = $ctx['site'];
    $md   = CLAUDE_BRIDGE_BASE_DOCTRINE;

    // ---- Site ----
    $md .= "\n---\n\n## Site\n\n";
    $md .= "- **Name:** {$site['name']}\n";
    $md .= "- **URL:** {$site['url']}\n";
    $md .= "- **WP version:** {$site['wp_version']}\n";
    $md .= "- **Timezone:** {$site['timezone']}\n";
    $md .= "- **REST root:** {$site['url']}/wp-json/\n";

    // ---- Active plugins (summary) ----
    $plugin_names = array_column( $ctx['plugins'], 'name' );
    $md .= "\n## Active plugins\n\n" . implode( ', ', $plugin_names ) . "\n";

    // ---- WooCommerce ----
    if ( $ctx['woocommerce'] ) {
        $wc = $ctx['woocommerce'];
        $md .= "\n## WooCommerce\n\n";
        $md .= "- Version: {$wc['version']}, currency: {$wc['currency']}\n";
        $md .= "- Order statuses: " . implode( ', ', array_keys( $wc['order_statuses'] ) ) . "\n";
        $md .= "- Payment gateways: " . implode( ', ', $wc['payment_gateways'] ) . "\n";
    }

    // ---- Snippet plugins ----
    $snip = $ctx['snippets'];
    $md  .= "\n## Snippet plugins\n\n";
    foreach ( $snip as $slug => $info ) {
        $status = $info['active'] ? "active v{$info['version']}" : 'not active';
        $md    .= "- **{$slug}:** {$status}\n";
    }
    if ( $snip['wpcode']['active'] || $snip['code-snippets']['active'] ) {
        $md .= "\nSnippet endpoints available at `claude-bridge/v1/snippets`.\n";
    }

    // ---- ACF ----
    if ( ! empty( $ctx['acf'] ) ) {
        $md .= "\n## ACF field groups\n\n";
        foreach ( $ctx['acf'] as $group ) {
            $field_names = array_column( $group['fields'], 'name' );
            $md .= "- **{$group['title']}** (" . implode( ', ', $field_names ) . ")\n";
        }
    }

    // ---- LMS ----
    if ( $ctx['lms'] ) {
        $md .= "\n## LMS\n\n- Plugin: {$ctx['lms']['plugin']} v{$ctx['lms']['version']}\n";
    }

    // ---- Claude Bridge endpoints ----
    $md .= "\n## Claude Bridge endpoints (`/wp-json/claude-bridge/v1/`)\n\n";
    $md .= "| Method | Path | Purpose |\n|---|---|---|\n";
    $md .= "| GET | `/instructions` | This document |\n";
    $md .= "| GET | `/context` | Full structured site snapshot |\n";
    $md .= "| GET | `/snippets` | List all snippets (both plugins) |\n";
    $md .= "| GET/PUT/DELETE | `/snippets/{plugin}/{id}` | Read, update, delete a snippet |\n";
    $md .= "| POST | `/snippets/{plugin}` | Create a snippet |\n";
    $md .= "| POST | `/snippets/{plugin}/{id}/toggle` | Enable / disable |\n";
    $md .= "| POST | `/snippets/code-snippets/{id}/migrate` | Migrate to WP Code Pro |\n";
    $md .= "| GET | `/introspect/hooks` | All registered WP hooks |\n";
    $md .= "| GET | `/introspect/scheduler` | Action Scheduler / wp-cron jobs |\n";
    $md .= "| GET | `/introspect/schema/{table}` | DB table column definitions |\n";

    // ---- Available REST namespaces ----
    $md .= "\n## Other REST namespaces on this site\n\n";
    foreach ( $ctx['rest_roots'] as $ns ) {
        $md .= "- `{$ns}`\n";
    }

    return new WP_REST_Response( $md, 200, [ 'Content-Type' => 'text/markdown; charset=utf-8' ] );
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
    $crons = _get_cron_array();
    $out   = [];
    foreach ( $crons as $timestamp => $jobs ) {
        foreach ( $jobs as $hook => $variants ) {
            foreach ( $variants as $args ) {
                $out[] = [
                    'hook'      => $hook,
                    'next_run'  => date( 'c', $timestamp ),
                    'interval'  => $args['interval'] ?? null,
                    'schedule'  => $args['schedule'] ?? null,
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
