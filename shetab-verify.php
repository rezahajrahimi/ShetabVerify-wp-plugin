<?php

// Internal bootstrap loaded by webdide-card-to-card-verification.php.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WDCV_VERSION', '0.1.0' );
if ( ! defined( 'WDCV_PLUGIN_FILE' ) ) {
    define( 'WDCV_PLUGIN_FILE', defined( 'WDCV_MAIN_PLUGIN_FILE' ) ? WDCV_MAIN_PLUGIN_FILE : __FILE__ );
}
define( 'WDCV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDCV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function wdcv_is_persian_locale() {
    $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    return 0 === strpos( strtolower( (string) $locale ), 'fa' );
}

function wdcv_localize_installed_plugin_row( $plugins ) {
    $basename = plugin_basename( WDCV_PLUGIN_FILE );
    if ( empty( $plugins[ $basename ] ) || ! is_array( $plugins[ $basename ] ) ) {
        return $plugins;
    }

    if ( wdcv_is_persian_locale() ) {
        $plugins[ $basename ]['Name']        = 'تأیید پرداخت کارت‌به‌کارت وب‌دیده برای شتاب';
        $plugins[ $basename ]['Title']       = 'تأیید پرداخت کارت‌به‌کارت وب‌دیده برای شتاب';
        $plugins[ $basename ]['Description'] = 'افزونه تایید خودکار پرداخت کارت‌به‌کارت برای ووکامرس و شتاب با پشتیبانی از سفارشات و بارگذاری رسید.';
    } else {
        $plugins[ $basename ]['Name']        = 'WebDide Card-to-Card Payment Verification for Shetab';
        $plugins[ $basename ]['Title']       = 'WebDide Card-to-Card Payment Verification for Shetab';
        $plugins[ $basename ]['Description'] = 'Payment gateway for automated card-to-card transaction confirmation in WooCommerce.';
    }

    return $plugins;
}

add_filter( 'all_plugins', 'wdcv_localize_installed_plugin_row' );

function wdcv_plugin_action_links( $links ) {
    $settings_url = admin_url( 'admin.php?page=webdide-card-to-card-verification' );
    $label = wdcv_is_persian_locale() ? 'تنظیمات' : 'Settings';
    array_unshift( $links, '<a href="' . esc_url( $settings_url ) . '">' . esc_html( $label ) . '</a>' );
    return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( WDCV_PLUGIN_FILE ), 'wdcv_plugin_action_links' );

/**
 * Enqueue scripts and styles.
 */
function wdcv_enqueue_assets( $hook ) {
    // Load on our plugin page
    if ( $hook === 'toplevel_page_webdide-card-to-card-verification' || strpos( $hook, 'webdide-card-to-card-verification' ) !== false ) {
        wp_enqueue_style( 'wdcv-admin-style', plugins_url( 'public/css/admin-style.css', WDCV_PLUGIN_FILE ), array(), time() );
        wp_enqueue_script( 'wdcv-admin-script', plugins_url( 'public/js/admin-script.js', WDCV_PLUGIN_FILE ), array( 'jquery' ), time(), true );

        // Localize script with translation strings
        wp_localize_script( 'wdcv-admin-script', 'wdcv_admin_vars', array(
            'copied_text' => esc_js( __( 'Copied to clipboard:', 'webdide-card-to-card-verification' ) ),
            'copy_failed_text' => esc_js( __( 'Copy failed. Please copy manually:', 'webdide-card-to-card-verification' ) ),
            'successful_transactions_text' => esc_js( __( 'Successful Transactions for', 'webdide-card-to-card-verification' ) ),
            'no_transactions_text' => esc_js( __( 'No transactions found.', 'webdide-card-to-card-verification' ) ),
            'details_text' => esc_js( __( 'Details', 'webdide-card-to-card-verification' ) ),
            'admin_url' => admin_url()
        ) );
    }
}
add_action( 'admin_enqueue_scripts', 'wdcv_enqueue_assets' );

/* includes */
require_once WDCV_PLUGIN_DIR . 'includes/class-activator.php';
require_once WDCV_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once WDCV_PLUGIN_DIR . 'includes/class-db.php';
require_once WDCV_PLUGIN_DIR . 'includes/class-utils.php';
require_once WDCV_PLUGIN_DIR . 'includes/api/class-rest-controller.php';
require_once WDCV_PLUGIN_DIR . 'includes/admin/class-admin-pages.php';

add_action( 'plugins_loaded', 'wdcv_init' );

// Hook in Blocks integration
add_action( 'woocommerce_blocks_loaded', 'wdcv_woocommerce_block_support' );

function wdcv_load_persian_translations() {
    // Load Persian translations manually
    $locale = get_locale();
    if ($locale !== 'fa_IR' && $locale !== 'fa') {
        return;
    }

    $po_file = WDCV_PLUGIN_DIR . '/languages/webdide-card-to-card-verification-fa_IR.po';
    if (!file_exists($po_file)) {
        return;
    }

    // Simple PO file parser
    $content = file_get_contents($po_file);
    $translations = array();

    // Parse basic msgid/msgstr pairs
    $lines = explode("\n", $content);
    $current_msgid = '';
    $current_msgstr = '';
    $in_msgstr = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, 'msgid "') === 0) {
            if (!empty($current_msgid) && !empty($current_msgstr)) {
                $translations[trim($current_msgid, '"')] = trim($current_msgstr, '"');
            }
            $current_msgid = substr($line, 7, -1); // Remove msgid " and "
            $current_msgstr = '';
            $in_msgstr = false;
        } elseif (strpos($line, 'msgstr "') === 0) {
            $current_msgstr = substr($line, 8, -1); // Remove msgstr " and "
            $in_msgstr = true;
        } elseif ($in_msgstr && strpos($line, '"') === 0) {
            $current_msgstr .= substr($line, 1, -1); // Remove " and "
        }
    }

    // Add the last translation
    if (!empty($current_msgid) && !empty($current_msgstr)) {
        $translations[trim($current_msgid, '"')] = trim($current_msgstr, '"');
    }

    // Load translations into WordPress
    global $l10n;
    if (!isset($l10n['webdide-card-to-card-verification'])) {
        $l10n['webdide-card-to-card-verification'] = new MO();
    }

    foreach ($translations as $original => $translated) {
        $l10n['webdide-card-to-card-verification']->entries[$original] = (object) array(
            'translations' => array($translated),
            'context' => null,
            'plural' => null,
            'singular' => $original,
        );
    }
}

function wdcv_init() {
    // Load plugin text domain for translations
    load_plugin_textdomain( 'webdide-card-to-card-verification', false, dirname( plugin_basename( WDCV_PLUGIN_FILE ) ) . '/languages' );

    // Load Persian translations manually if .mo file doesn't exist
    wdcv_load_persian_translations();

    // Add a custom cron schedule for cleanup (every 5 minutes)
    add_filter( 'cron_schedules', function ( $schedules ) {
        if ( empty( $schedules['wdcv_every_5min'] ) ) {
            $schedules['wdcv_every_5min'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 Minutes', 'webdide-card-to-card-verification' ),
            );
        }
        return $schedules;
    } );

    // register cleanup handler
    add_action( 'wdcv_cleanup_expired', array( 'WebDide_CV_DB', 'cleanup_expired_transactions' ) );

    if ( class_exists( 'woocommerce' ) ) {
require_once WDCV_PLUGIN_DIR . 'includes/gateway/class-wc-gateway-wdcv.php';
require_once WDCV_PLUGIN_DIR . 'includes/gateway/class-wdcv-blocks-support.php';

        add_filter( 'woocommerce_payment_gateways', 'wdcv_add_gateway' );
        add_action( 'rest_api_init', array( 'WebDide_CV_REST_Controller', 'register_routes' ) );
        WebDide_CV_Admin::init();

        // admin notice: warn if gateway enabled but no active cards configured
        add_action( 'admin_notices', function() {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }
            $screen = get_current_screen();
            if ( $screen && $screen->id === 'woocommerce_page_WebDide_CV' ) {
                return; // Don't show on the plugin settings page itself
            }
            $gw_opts = get_option( 'woocommerce_wdcv_settings', array() );
            if ( empty( $gw_opts['enabled'] ) || $gw_opts['enabled'] !== 'yes' ) {
                return;
            }
            $cards = method_exists( 'WebDide_CV_DB', 'get_active_cards' ) ? WebDide_CV_DB::get_active_cards() : array();
            if ( empty( $cards ) ) {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'WebDide_CV gateway is enabled but no active bank cards are configured. To show the gateway on checkout, please add at least one active card in Shetab Management.', 'webdide-card-to-card-verification' ) . '</p></div>';
            }
        } );

        add_filter( 'woocommerce_available_payment_gateways', 'wdcv_force_available_gateway', PHP_INT_MAX );
        add_action( 'wp_loaded', function() {
            add_filter( 'woocommerce_available_payment_gateways', 'wdcv_force_available_gateway', PHP_INT_MAX );
        } );
        add_action( 'woocommerce_cart_loaded_from_session', 'wdcv_refresh_available_gateways_cache' );
        add_action( 'woocommerce_before_checkout_form', 'wdcv_refresh_available_gateways_cache' );
        add_action( 'woocommerce_before_cart', 'wdcv_refresh_available_gateways_cache' );
        add_action( 'woocommerce_checkout_update_order_review', 'wdcv_refresh_available_gateways_cache' );

        // Register blocks support
        add_action( 'woocommerce_blocks_loaded', function() {
            if ( class_exists( 'Automattic\woocommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
                require_once WDCV_PLUGIN_DIR . 'includes/gateway/class-wdcv-blocks-support.php';
                add_action( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
                    $payment_method_registry->register( new WebDide_CV_Blocks_Support() );
                } );
            }
        } );

        // show payment instructions on the thankyou page
        add_action( 'woocommerce_thankyou_wdcv', array( 'WebDide_CV_Gateway', 'render_payment_instructions' ), 10, 1 );

        // enqueue frontend/block assets
        // add_action( 'wp_enqueue_scripts', function() {
        //     wp_register_script( 'WebDide_CV-blocks', plugins_url( 'public/js/blocks.js', WDCV_PLUGIN_FILE ), array(), WDCV_VERSION, true );
        //     wp_localize_script( 'WebDide_CV-blocks', 'wdcv', array(
        //         'rest_url' => esc_url_raw( rest_url( 'webdide-cv/v1' ) ),
        //         'gateway_id' => 'wdcv',
        //         'gateway_title' => __( 'webdide-card-to-card-verification', 'webdide-card-to-card-verification' ),
        //     ) );

        //     // Add settings for blocks
        //     if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
        //         $gateway = WC()->payment_gateways()->payment_gateways()['wdcv'] ?? null;
        //         if ( $gateway ) {
        //             wp_localize_script( 'WebDide_CV-blocks', 'wc_wdcv_settings_params', array(
        //                 'title' => $gateway->get_title(),
        //                 'description' => $gateway->get_description(),
        //                 'supports' => $gateway->supports,
        //             ) );
        //         }
        //     }

        //     if ( is_checkout() || is_account_page() || is_page() ) {
        //         wp_enqueue_script( 'WebDide_CV-blocks' );
        //     }
        // } );

        // Add blocks settings
        // add_action( 'woocommerce_blocks_loaded', function() {
        //     if ( class_exists( 'Automattic\woocommerce\Blocks\Payments\PaymentMethodRegistry' ) ) {
        //         require_once WDCV_PLUGIN_DIR . 'includes/gateway/class-wc-wdcv-blocks-support.php';
        //         add_filter( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
        //             $payment_method_registry->register( new WC_WebDide_CV_Blocks_Support() );
        //         } );
        //     }
        // } );

        // Provide settings to blocks
        // add_filter( 'woocommerce_get_settings_for_wdcv', function( $settings ) {
        //     return array_merge( $settings, array(
        //         'enabled' => 'yes',
        //         'title' => __( 'Bank transfer (WebDide_CV)', 'webdide-card-to-card-verification' ),
        //         'description' => __( 'Pay via bank transfer with unique suffix amount.', 'webdide-card-to-card-verification' ),
        //         'supports' => array( 'products', 'refunds' ),
        //     ) );
        // } );

        // add_filter( 'rest_post_dispatch', 'wdcv_extend_store_api_payment_methods', 15, 3 );
    } else {
        add_action( 'admin_notices', 'wdcv_woocommerce_missing_notice' );
    }
}

function wdcv_add_gateway( $gateways ) {
    // debug: note that the filter was invoked
    $note = array( 'time' => current_time( 'mysql' ), 'incoming' => $gateways );
    set_transient( 'wdcv_filter_called', $note, 5 * MINUTE_IN_SECONDS );
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'wdcv_add_gateway invoked; incoming: ' . wp_json_encode( $gateways ), array( 'source' => 'webdide-card-to-card-verification' ) );
    } else {
        error_log( 'wdcv_add_gateway invoked; incoming: ' . wp_json_encode( $gateways ) );
    }

    $gateways[] = 'WebDide_CV_Gateway';
    return $gateways;
}

function wdcv_force_available_gateway( $available ) {
    if ( ! is_array( $available ) ) {
        $available = array();
    }

    set_transient( 'wdcv_available_filter_called', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );

    if ( isset( $available['wdcv'] ) ) {
        return $available;
    }

    if ( ! class_exists( 'WebDide_CV_Gateway' ) ) {
        return $available;
    }

    $gw = new WebDide_CV_Gateway();
    if ( $gw->is_available() ) {
        $available['wdcv'] = $gw;
        set_transient( 'wdcv_available_filter_added', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->debug( 'WebDide_CV fallback filter added the gateway to available_payment_gateways.', array( 'source' => 'webdide-card-to-card-verification' ) );
        }
    }

    return $available;
}

function wdcv_refresh_available_gateways_cache() {
    if ( ! class_exists( 'woocommerce' ) || ! function_exists( 'WC' ) ) {
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

function wdcv_extend_store_api_payment_methods( $response, $server, $request ) {
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
        if ( ( isset( $method['method_id'] ) && $method['method_id'] === 'wdcv' ) || ( isset( $method['id'] ) && $method['id'] === 'wdcv' ) ) {
            set_transient( 'wdcv_store_api_seen', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );
            return $response;
        }
    }

    if ( ! class_exists( 'WebDide_CV_Gateway' ) ) {
        return $response;
    }

    $gateway = new WebDide_CV_Gateway();
    if ( ! $gateway->is_available() ) {
        return $response;
    }

    $payment_methods[] = array(
        'id' => 'wdcv',
        'method_id' => 'wdcv',
        'name' => $gateway->get_title(),
        'title' => $gateway->get_title(),
        'description' => $gateway->get_description(),
        'method_title' => $gateway->get_title(),
        'method_description' => $gateway->get_description(),
        'order' => 0,
        'enabled' => true,
        'is_active' => true,
        'type' => 'gateway',
        'gateway' => 'wdcv',
        'supports' => array( 'products' ),
        'requires_setup' => false,
    );

    $data['payment_methods'] = $payment_methods;
    if ( empty( $data['payment_method'] ) ) {
        $data['payment_method'] = 'wdcv';
    }

    set_transient( 'wdcv_store_api_injected', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );
    set_transient( 'wdcv_store_api_route', $route, 5 * MINUTE_IN_SECONDS );
    $response->set_data( $data );

    // Log the modified data
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'WebDide_CV store API modified payment_methods: ' . wp_json_encode( $data['payment_methods'] ), array( 'source' => 'webdide-card-to-card-verification' ) );
    } else {
        error_log( 'WebDide_CV store API modified payment_methods: ' . wp_json_encode( $data['payment_methods'] ) );
    }

    return $response;
}

/**
 * Run a diagnostic: check available payment gateways and record whether
 * `wdcv` is present. Result is logged and saved to a transient.
 * Returns diagnostic array.
 */
function wdcv_run_diagnostics() {
    $data = array( 'time' => current_time( 'mysql' ) );

    if ( ! class_exists( 'woocommerce' ) || ! function_exists( 'WC' ) ) {
        $data['error'] = 'woocommerce not active';
        set_transient( 'wdcv_debug_available', $data, 5 * MINUTE_IN_SECONDS );
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->debug( 'WebDide_CV diagnostics: woocommerce not active', array( 'source' => 'webdide-card-to-card-verification' ) );
        } else {
            error_log( 'WebDide_CV diagnostics: woocommerce not active' );
        }
        return $data;
    }

    $gateways = array();
    // available gateways for current context (instances)
    $available = array();
    wdcv_refresh_available_gateways_cache();
    if ( method_exists( WC()->payment_gateways(), 'get_available_payment_gateways' ) ) {
        $available = WC()->payment_gateways()->get_available_payment_gateways();
    }
    if ( is_array( $available ) ) {
        $data['available'] = array_keys( $available );
    } else {
        $data['available'] = array();
    }
    $data['found_available'] = in_array( 'wdcv', $data['available'], true );

    // if not found in available, manually check via fallback logic (force through the filter)
    if ( ! $data['found_available'] && class_exists( 'WebDide_CV_Gateway' ) ) {
        $gw = new WebDide_CV_Gateway();
        $is_avail = $gw->is_available();
        $data['manual_is_available_check'] = $is_avail;
        if ( $is_avail ) {
            $available['wdcv'] = $gw;
            $data['available'][] = 'wdcv';
            $data['found_available'] = true;
            $data['note_fallback_applied'] = 'Gateway is_available() returned true; fallback filter would add it.';
        } else {
            $data['note_fallback_not_applied'] = 'Gateway is_available() returned false.';
            // Check reasons
            $enabled = $gw->get_option( 'enabled', 'yes' );
            $data['gateway_enabled'] = $enabled;
            $cards = method_exists( 'WebDide_CV_DB', 'get_active_cards' ) ? WebDide_CV_DB::get_active_cards() : array();
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
    $data['found_registered'] = in_array( 'WebDide_CV_Gateway', $data['registered_gateways'], true );

    // helper: record transient if filter was called recently
    $filter_called = get_transient( 'wdcv_filter_called' );
    $data['filter_invoked_recently'] = ! empty( $filter_called );

    $available_filter_called = get_transient( 'wdcv_available_filter_called' );
    $data['available_filter_called_recently'] = ! empty( $available_filter_called );
    $available_filter_added = get_transient( 'wdcv_available_filter_added' );
    if ( $available_filter_added ) {
        $data['available_filter_last_added'] = $available_filter_added;
    }

    $store_api_injected = get_transient( 'wdcv_store_api_injected' );
    if ( $store_api_injected ) {
        $data['store_api_last_injected'] = $store_api_injected;
        $data['store_api_route'] = get_transient( 'wdcv_store_api_route' );
    }
    $store_api_seen = get_transient( 'wdcv_store_api_seen' );
    if ( $store_api_seen ) {
        $data['store_api_seen'] = $store_api_seen;
    }

    $blocks_initialized = get_transient( 'wdcv_blocks_initialized' );
    if ( $blocks_initialized ) {
        $data['blocks_initialized'] = $blocks_initialized;
    }

    // include last is_available() debug if present
    $is_avail = get_transient( 'wdcv_is_available_debug' );
    if ( $is_avail ) {
        $data['is_available_debug'] = $is_avail;
    }

    // log summary
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'WebDide_CV diagnostics summary: ' . wp_json_encode( $data ), array( 'source' => 'webdide-card-to-card-verification' ) );
    } else {
        error_log( 'WebDide_CV diagnostics summary: ' . wp_json_encode( $data ) );
    }

    // log
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'WebDide_CV diagnostics: ' . wp_json_encode( $data ), array( 'source' => 'webdide-card-to-card-verification' ) );
    } else {
        error_log( 'WebDide_CV diagnostics: ' . wp_json_encode( $data ) );
    }

    set_transient( 'wdcv_debug_available', $data, 5 * MINUTE_IN_SECONDS );

    return $data;
}

function wdcv_woocommerce_missing_notice() {
    if ( current_user_can( 'activate_plugins' ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'WebDide_CV requires woocommerce to be installed and active.', 'webdide-card-to-card-verification' ) . '</p></div>';
    }
}

function wdcv_woocommerce_block_support() {
    if ( class_exists( 'Automattic\woocommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\woocommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new WebDide_CV_Blocks_Support() );
            }
        );
    }
}

/**
 * Create a test product and cart item for checkout diagnostics.
 * Returns product ID and checkout URL.
 */
function wdcv_create_test_order() {

    // Ensure at least one active card exists (create test card if needed)
    $cards = method_exists( 'WebDide_CV_DB', 'get_active_cards' ) ? WebDide_CV_DB::get_active_cards() : array();
    if ( empty( $cards ) ) {
        // Create a test card automatically
        $test_card_num = '6210000000000001';
        $encrypted = method_exists( 'WebDide_CV_Utils', 'encrypt_card_number' ) 
            ? WebDide_CV_Utils::encrypt_card_number( $test_card_num ) 
            : $test_card_num;
        $masked = method_exists( 'WebDide_CV_Utils', 'mask_card_number' ) 
            ? WebDide_CV_Utils::mask_card_number( $test_card_num ) 
            : '**** **** **** 0001';
        
        WebDide_CV_DB::insert_card( array(
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
            'post_title'  => '[WebDide_CV Test] Test Product',
            'post_type'   => 'product',
            'post_status' => 'publish',
            'post_content' => 'Auto-generated test product for WebDide_CV diagnostics.',
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
        'note' => 'Test card auto-created if none existed. Product added to cart. Visit checkout to verify WebDide_CV gateway appears.',
    );

    set_transient( 'wdcv_test_order_info', $diag, 10 * MINUTE_IN_SECONDS );

    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( 'WebDide_CV test order created: ' . wp_json_encode( $diag ), array( 'source' => 'webdide-card-to-card-verification' ) );
    } else {
        error_log( 'WebDide_CV test order created: ' . wp_json_encode( $diag ) );
    }

    return $diag;
}
















