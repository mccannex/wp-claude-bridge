<?php
/**
 * Plugin Name: Claude Bridge
 * Description: Optional server-side deep layer for wp-claude-bridge. Exposes a one-call
 *              site context endpoint and read-only introspection routes. Auth rides the
 *              logged-in admin session via the standard WP REST nonce.
 * Version:     0.1.0
 * GitHub Plugin URI: mccannex/wp-claude-bridge
 * Primary Branch:    master
 * Release Asset:     true
 *
 * Endpoints:
 *   GET  claude-bridge/v1/context        one-call site snapshot
 *   GET  claude-bridge/v1/introspect/hooks
 *   GET  claude-bridge/v1/introspect/scheduler
 *   GET  claude-bridge/v1/introspect/schema/{table}
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', function () {

    $ns = 'claude-bridge/v1';
    $auth = fn( $req ) => current_user_can( 'manage_options' );

    // -------------------------------------------------------------------------
    // GET /context
    // -------------------------------------------------------------------------
    register_rest_route( $ns, '/context', [
        'methods'             => 'GET',
        'callback'            => 'claude_bridge_context',
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
// /context
// =============================================================================
function claude_bridge_context() {
    return rest_ensure_response( [
        'site'       => claude_bridge_site_info(),
        'plugins'    => claude_bridge_active_plugins(),
        'woocommerce'=> claude_bridge_woocommerce(),
        'post_types' => claude_bridge_post_types(),
        'taxonomies' => claude_bridge_taxonomies(),
        'acf'        => claude_bridge_acf(),
        'lms'        => claude_bridge_lms(),
        'rest_roots' => claude_bridge_rest_roots(),
        'capabilities' => claude_bridge_current_caps(),
    ] );
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
    // Return the capabilities that are actually granted (value === true).
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
