<?php
/**
 * Plugin Name: ShetabVerify
 * Plugin URI:  https://example.com/plugins/shetab-verify
 * Description: WooCommerce payment gateway — Auto shetab transaction confirimation.
 * Version:     0.1.0
 * Author:      Reza HajRahimi
 * Text Domain: shetab-verify
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SSV_VERSION', '0.1.0' );
define( 'SSV_PLUGIN_FILE', __FILE__ );
define( 'SSV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* includes */
require_once SSV_PLUGIN_DIR . 'includes/class-activator.php';
require_once SSV_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once SSV_PLUGIN_DIR . 'includes/class-db.php';
require_once SSV_PLUGIN_DIR . 'includes/class-utils.php';
require_once SSV_PLUGIN_DIR . 'includes/api/class-rest-controller.php';
require_once SSV_PLUGIN_DIR . 'includes/admin/class-admin-pages.php';

register_activation_hook( __FILE__, array( 'ShetabVerify_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ShetabVerify_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', 'shetab_verify_init' );

// Hook in Blocks integration
// add_action( 'woocommerce_blocks_loaded', 'shetab_verify_woocommerce_block_support' );

function shetab_verify_init() {
    load_plugin_textdomain( 'shetab-verify', false, dirname( plugin_basename( SSV_PLUGIN_FILE ) ) . '/languages' );

    // Add a custom cron schedule for cleanup (every 5 minutes)
    add_filter( 'cron_schedules', function ( $schedules ) {
        if ( empty( $schedules['shetab_verify_every_5min'] ) ) {
            $schedules['shetab_verify_every_5min'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 Minutes', 'shetab-verify' ),
            );
        }
        return $schedules;
    } );

    // register cleanup handler
    add_action( 'shetab_verify_cleanup_expired', array( 'ShetabVerify_DB', 'cleanup_expired_transactions' ) );

    if ( class_exists( 'WooCommerce' ) ) {
require_once SSV_PLUGIN_DIR . 'includes/gateway/class-wc-gateway-shetab.php';

        add_filter( 'woocommerce_payment_gateways', 'shetab_verify_add_gateway' );
        add_action( 'rest_api_init', array( 'ShetabVerify_REST_Controller', 'register_routes' ) );
        ShetabVerify_Admin::init();

        // admin notice: warn if gateway enabled but no active cards configured
        add_action( 'admin_notices', function() {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }
            $gw_opts = get_option( 'woocommerce_shetab_verify_settings', array() );
            if ( empty( $gw_opts['enabled'] ) || $gw_opts['enabled'] !== 'yes' ) {
                return;
            }
            $cards = method_exists( 'ShetabVerify_DB', 'get_active_cards' ) ? ShetabVerify_DB::get_active_cards() : array();
            if ( empty( $cards ) ) {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'ShetabVerify is enabled but no destination bank cards are configured — add at least one card in ShetabVerify settings to make the gateway appear on checkout.', 'shetab-verify' ) . '</p></div>';
            }
        } );

        add_filter( 'woocommerce_available_payment_gateways', 'shetab_verify_force_available_gateway', PHP_INT_MAX );
        add_action( 'wp_loaded', function() {
            add_filter( 'woocommerce_available_payment_gateways', 'shetab_verify_force_available_gateway', PHP_INT_MAX );
        } );
        add_action( 'woocommerce_cart_loaded_from_session', 'shetab_verify_refresh_available_gateways_cache' );
        add_action( 'woocommerce_before_checkout_form', 'shetab_verify_refresh_available_gateways_cache' );
        add_action( 'woocommerce_before_cart', 'shetab_verify_refresh_available_gateways_cache' );
        add_action( 'woocommerce_checkout_update_order_review', 'shetab_verify_refresh_available_gateways_cache' );

        // Register blocks support
        add_action( 'woocommerce_blocks_loaded', function() {
            if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
                require_once SSV_PLUGIN_DIR . 'includes/gateway/class-wc-shetabverify-blocks-support.php';
                add_action( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
                    $payment_method_registry->register( new WC_ShetabVerify_Blocks_Support() );
                } );
            }
        } );

        // show payment instructions on the thankyou page
        add_action( 'woocommerce_thankyou_shetab_verify', array( 'WC_Gateway_Shetab', 'render_payment_instructions' ), 10, 1 );

        // enqueue frontend/block assets
        // add_action( 'wp_enqueue_scripts', function() {
        //     wp_register_script( 'shetab-verify-blocks', plugins_url( 'public/js/blocks.js', SSV_PLUGIN_FILE ), array(), SSV_VERSION, true );
        //     wp_localize_script( 'shetab-verify-blocks', 'shetab_verify', array(
        //         'rest_url' => esc_url_raw( rest_url( 'shetab-verify/v1' ) ),
        //         'gateway_id' => 'shetab_verify',
        //         'gateway_title' => __( 'ShetabVerify', 'shetab-verify' ),
        //     ) );

        //     // Add settings for blocks
        //     if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
        //         $gateway = WC()->payment_gateways()->payment_gateways()['shetab_verify'] ?? null;
        //         if ( $gateway ) {
        //             wp_localize_script( 'shetab-verify-blocks', 'wc_shetab_verify_settings_params', array(
        //                 'title' => $gateway->get_title(),
        //                 'description' => $gateway->get_description(),
        //                 'supports' => $gateway->supports,
        //             ) );
        //         }
        //     }

        //     if ( is_checkout() || is_account_page() || is_page() ) {
        //         wp_enqueue_script( 'shetab-verify-blocks' );
        //     }
        // } );

        // Add blocks settings
        // add_action( 'woocommerce_blocks_loaded', function() {
        //     if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry' ) ) {
        //         require_once SSV_PLUGIN_DIR . 'includes/gateway/class-wc-shetabverify-blocks-support.php';
        //         add_filter( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
        //             $payment_method_registry->register( new WC_ShetabVerify_Blocks_Support() );
        //         } );
        //     }
        // } );

        // Provide settings to blocks
        // add_filter( 'woocommerce_get_settings_for_shetab_verify', function( $settings ) {
        //     return array_merge( $settings, array(
        //         'enabled' => 'yes',
        //         'title' => __( 'Bank transfer (ShetabVerify)', 'shetab-verify' ),
        //         'description' => __( 'Pay via bank transfer with unique suffix amount.', 'shetab-verify' ),
        //         'supports' => array( 'products', 'refunds' ),
        //     ) );
        // } );

        // add_filter( 'rest_post_dispatch', 'shetab_verify_extend_store_api_payment_methods', 15, 3 );
    } else {
        add_action( 'admin_notices', 'shetab_verify_woocommerce_missing_notice' );
    }
}

function shetab_verify_add_gateway( $gateways ) {
    // debug: note that the filter was invoked
    $note = array( 'time' => current_time( 'mysql' ), 'incoming' => $gateways );
    set_transient( 'shetab_verify_filter_called', $note, 5 * MINUTE_IN_SECONDS );
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'shetab_verify_add_gateway invoked; incoming: ' . wp_json_encode( $gateways ), array( 'source' => 'shetab-verify' ) );
    } else {
        error_log( 'shetab_verify_add_gateway invoked; incoming: ' . wp_json_encode( $gateways ) );
    }

    $gateways[] = 'WC_Gateway_Shetab';
    return $gateways;
}

function shetab_verify_force_available_gateway( $available ) {
    if ( ! is_array( $available ) ) {
        $available = array();
    }

    set_transient( 'shetab_verify_available_filter_called', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );

    if ( isset( $available['shetab_verify'] ) ) {
        return $available;
    }

    if ( ! class_exists( 'WC_Gateway_Shetab' ) ) {
        return $available;
    }

    $gw = new WC_Gateway_Shetab();
    if ( $gw->is_available() ) {
        $available['shetab_verify'] = $gw;
        set_transient( 'shetab_verify_available_filter_added', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->debug( 'ShetabVerify fallback filter added the gateway to available_payment_gateways.', array( 'source' => 'shetab-verify' ) );
        }
    }

    return $available;
}

function shetab_verify_refresh_available_gateways_cache() {
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
        return;
    }

    $gateways = WC()->payment_gateways();
    if ( ! is_object( $gateways ) ) {
        return;
    }

    if ( property_exists( $gateways, 'available_gateways' ) ) {
        $gateways->available_gateways = null;
    }
}

function shetab_verify_extend_store_api_payment_methods( $response, $server, $request ) {
    if ( ! class_exists( 'WP_REST_Request' ) || ! class_exists( 'WP_REST_Response' ) ) {
        return $response;
    }

    if ( ! $request instanceof WP_REST_Request ) {
        return $response;
    }

    $route = $request->get_route();
    if ( ! in_array( $route, array( '/wc/store/checkout', '/wc/store/v1/checkout' ), true ) ) {
        return $response;
    }

    if ( $response instanceof WP_Error ) {
        return $response;
    }

    if ( ! $response instanceof WP_REST_Response ) {
        return $response;
    }

    $data = $response->get_data();
    $payment_methods = isset( $data['payment_methods'] ) && is_array( $data['payment_methods'] ) ? $data['payment_methods'] : array();

    foreach ( $payment_methods as $method ) {
        if ( ( isset( $method['method_id'] ) && $method['method_id'] === 'shetab_verify' ) || ( isset( $method['id'] ) && $method['id'] === 'shetab_verify' ) ) {
            set_transient( 'shetab_verify_store_api_seen', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );
            return $response;
        }
    }

    if ( ! class_exists( 'WC_Gateway_Shetab' ) ) {
        return $response;
    }

    $gateway = new WC_Gateway_Shetab();
    if ( ! $gateway->is_available() ) {
        return $response;
    }

    $payment_methods[] = array(
        'id' => 'shetab_verify',
        'method_id' => 'shetab_verify',
        'name' => $gateway->get_title(),
        'title' => $gateway->get_title(),
        'description' => $gateway->get_description(),
        'method_title' => $gateway->get_title(),
        'method_description' => $gateway->get_description(),
        'order' => 0,
        'enabled' => true,
        'is_active' => true,
        'type' => 'gateway',
        'gateway' => 'shetab_verify',
        'supports' => array( 'products' ),
        'requires_setup' => false,
    );

    $data['payment_methods'] = $payment_methods;
    if ( empty( $data['payment_method'] ) ) {
        $data['payment_method'] = 'shetab_verify';
    }

    set_transient( 'shetab_verify_store_api_injected', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );
    set_transient( 'shetab_verify_store_api_route', $route, 5 * MINUTE_IN_SECONDS );
    $response->set_data( $data );

    // Log the modified data
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'ShetabVerify store API modified payment_methods: ' . wp_json_encode( $data['payment_methods'] ), array( 'source' => 'shetab-verify' ) );
    } else {
        error_log( 'ShetabVerify store API modified payment_methods: ' . wp_json_encode( $data['payment_methods'] ) );
    }

    return $response;
}

/**
 * Run a diagnostic: check available payment gateways and record whether
 * `shetab_verify` is present. Result is logged and saved to a transient.
 * Returns diagnostic array.
 */
function shetab_verify_run_diagnostics() {
    $data = array( 'time' => current_time( 'mysql' ) );

    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
        $data['error'] = 'WooCommerce not active';
        set_transient( 'shetab_verify_debug_available', $data, 5 * MINUTE_IN_SECONDS );
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->debug( 'ShetabVerify diagnostics: WooCommerce not active', array( 'source' => 'shetab-verify' ) );
        } else {
            error_log( 'ShetabVerify diagnostics: WooCommerce not active' );
        }
        return $data;
    }

    $gateways = array();
    // available gateways for current context (instances)
    $available = array();
    shetab_verify_refresh_available_gateways_cache();
    if ( method_exists( WC()->payment_gateways(), 'get_available_payment_gateways' ) ) {
        $available = WC()->payment_gateways()->get_available_payment_gateways();
    }
    if ( is_array( $available ) ) {
        $data['available'] = array_keys( $available );
    } else {
        $data['available'] = array();
    }
    $data['found_available'] = in_array( 'shetab_verify', $data['available'], true );

    // if not found in available, manually check via fallback logic (force through the filter)
    if ( ! $data['found_available'] && class_exists( 'WC_Gateway_Shetab' ) ) {
        $gw = new WC_Gateway_Shetab();
        $is_avail = $gw->is_available();
        $data['manual_is_available_check'] = $is_avail;
        if ( $is_avail ) {
            $available['shetab_verify'] = $gw;
            $data['available'][] = 'shetab_verify';
            $data['found_available'] = true;
            $data['note_fallback_applied'] = 'Gateway is_available() returned true; fallback filter would add it.';
        } else {
            $data['note_fallback_not_applied'] = 'Gateway is_available() returned false.';
            // Check reasons
            $enabled = $gw->get_option( 'enabled', 'yes' );
            $data['gateway_enabled'] = $enabled;
            $cards = method_exists( 'ShetabVerify_DB', 'get_active_cards' ) ? ShetabVerify_DB::get_active_cards() : array();
            $data['active_cards_count'] = count( $cards );
            $data['is_admin'] = is_admin();
            $data['doing_ajax'] = defined( 'DOING_AJAX' ) ? DOING_AJAX : false;
            if ( function_exists( 'WC' ) && WC()->cart ) {
                $data['cart_total'] = floatval( WC()->cart->total );
            } else {
                $data['cart_total'] = 'no cart';
            }
        }
    }

    // registered gateway classes via filter
    $registered = apply_filters( 'woocommerce_payment_gateways', array() );
    $data['registered_gateways'] = is_array( $registered ) ? $registered : array();
    $data['found_registered'] = in_array( 'WC_Gateway_Shetab', $data['registered_gateways'], true );

    // helper: record transient if filter was called recently
    $filter_called = get_transient( 'shetab_verify_filter_called' );
    $data['filter_invoked_recently'] = ! empty( $filter_called );

    $available_filter_called = get_transient( 'shetab_verify_available_filter_called' );
    $data['available_filter_called_recently'] = ! empty( $available_filter_called );
    $available_filter_added = get_transient( 'shetab_verify_available_filter_added' );
    if ( $available_filter_added ) {
        $data['available_filter_last_added'] = $available_filter_added;
    }

    $store_api_injected = get_transient( 'shetab_verify_store_api_injected' );
    if ( $store_api_injected ) {
        $data['store_api_last_injected'] = $store_api_injected;
        $data['store_api_route'] = get_transient( 'shetab_verify_store_api_route' );
    }
    $store_api_seen = get_transient( 'shetab_verify_store_api_seen' );
    if ( $store_api_seen ) {
        $data['store_api_seen'] = $store_api_seen;
    }

    $blocks_initialized = get_transient( 'shetab_verify_blocks_initialized' );
    if ( $blocks_initialized ) {
        $data['blocks_initialized'] = $blocks_initialized;
    }

    // include last is_available() debug if present
    $is_avail = get_transient( 'shetab_verify_is_available_debug' );
    if ( $is_avail ) {
        $data['is_available_debug'] = $is_avail;
    }

    // log summary
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'ShetabVerify diagnostics summary: ' . wp_json_encode( $data ), array( 'source' => 'shetab-verify' ) );
    } else {
        error_log( 'ShetabVerify diagnostics summary: ' . wp_json_encode( $data ) );
    }

    // log
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'ShetabVerify diagnostics: ' . wp_json_encode( $data ), array( 'source' => 'shetab-verify' ) );
    } else {
        error_log( 'ShetabVerify diagnostics: ' . wp_json_encode( $data ) );
    }

    set_transient( 'shetab_verify_debug_available', $data, 5 * MINUTE_IN_SECONDS );

    return $data;
}

function shetab_verify_woocommerce_missing_notice() {
    if ( current_user_can( 'activate_plugins' ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'ShetabVerify requires WooCommerce to be installed and active.', 'shetab-verify' ) . '</p></div>';
    }
}

function shetab_verify_woocommerce_block_support() {
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new WC_Gateway_Shetab_Blocks_Support() );
            }
        );
    }
}

/**
 * Create a test product and cart item for checkout diagnostics.
 * Returns product ID and checkout URL.
 */
function shetab_verify_create_test_order() {

    // Ensure at least one active card exists (create test card if needed)
    $cards = method_exists( 'ShetabVerify_DB', 'get_active_cards' ) ? ShetabVerify_DB::get_active_cards() : array();
    if ( empty( $cards ) ) {
        // Create a test card automatically
        $test_card_num = '6210000000000001';
        $encrypted = method_exists( 'ShetabVerify_Utils', 'encrypt_card_number' ) 
            ? ShetabVerify_Utils::encrypt_card_number( $test_card_num ) 
            : $test_card_num;
        $masked = method_exists( 'ShetabVerify_Utils', 'mask_card_number' ) 
            ? ShetabVerify_Utils::mask_card_number( $test_card_num ) 
            : '**** **** **** 0001';
        
        ShetabVerify_DB::insert_card( array(
            'label' => 'Test Card (Auto-generated)',
            'encrypted_number' => $encrypted,
            'masked_number' => $masked,
            'max_deposits_count' => 0,
            'max_total_amount' => 0,
            'reset_period' => 'none',
            'active' => 1,
        ) );
    }

    // Try to find existing test product using get_posts (works in admin)
    $test_posts = get_posts( array(
        'post_type' => 'product',
        'meta_key' => '_shetab_test_product',
        'meta_value' => '1',
        'posts_per_page' => 1,
        'suppress_filters' => false,
    ) );

    $product_id = null;

    if ( ! empty( $test_posts ) ) {
        $product_id = (int) $test_posts[0]->ID;
    } else {
        // Create new product via post
        $product_post = array(
            'post_title'  => '[ShetabVerify Test] Test Product',
            'post_type'   => 'product',
            'post_status' => 'publish',
            'post_content' => 'Auto-generated test product for ShetabVerify diagnostics.',
        );
        $product_id = wp_insert_post( $product_post );
        if ( is_wp_error( $product_id ) ) {
            return array( 'error' => 'Failed to create product: ' . $product_id->get_error_message() );
        }
        // Add product meta
        update_post_meta( $product_id, '_shetab_test_product', '1' );
        update_post_meta( $product_id, '_regular_price', '10.00' );
        update_post_meta( $product_id, '_price', '10.00' );
        update_post_meta( $product_id, '_product_attributes', array() );
    }

    // Try to clear cart and add product (may be in admin context, so cart might not be available)
    $cart_total = '10.00'; // fallback to product price
    if ( function_exists( 'WC' ) && WC()->cart ) {
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $product_id, 1 );
        $cart_total = WC()->cart->get_total( '' );
    }

    // Get checkout URL
    $checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : 'N/A';

    $diag = array(
        'product_id' => $product_id,
        'checkout_url' => $checkout_url,
        'cart_total' => $cart_total,
        'time' => current_time( 'mysql' ),
        'note' => 'Test card auto-created if none existed. Product added to cart. Visit checkout to verify ShetabVerify gateway appears.',
    );

    set_transient( 'shetab_verify_test_order_info', $diag, 10 * MINUTE_IN_SECONDS );

    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'ShetabVerify test order created: ' . wp_json_encode( $diag ), array( 'source' => 'shetab-verify' ) );
    } else {
        error_log( 'ShetabVerify test order created: ' . wp_json_encode( $diag ) );
    }

    return $diag;
}
