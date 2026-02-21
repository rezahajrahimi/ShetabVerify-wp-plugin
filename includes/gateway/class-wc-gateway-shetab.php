<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    return;
}

class WC_Gateway_Shetab extends WC_Payment_Gateway {
    public function __construct() {
        $this->id                 = 'shetab_verify';
        $this->has_fields         = false;
        $this->method_title       = __( 'ShetabVerify', 'shetab-verify' );
        $this->method_description = __( 'Bank transfer with unique-suffix amounts (ShetabVerify).', 'shetab-verify' );

        $this->supports = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'shetab-verify' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable ShetabVerify', 'shetab-verify' ),
                'default' => 'yes',
            ),
            'title'   => array(
                'title'   => __( 'Title', 'shetab-verify' ),
                'type'    => 'text',
                'default' => __( 'Bank transfer (ShetabVerify)', 'shetab-verify' ),
            ),
        );
    }

    /**
     * Ensure gateway is considered available on checkout when enabled.
     * WooCommerce core also performs checks, but we explicitly allow the gateway
     * so it appears in both Classic and Block checkouts when enabled.
     */
    public function is_available() {
        // Respect the "enabled" setting
        if ( 'yes' !== $this->get_option( 'enabled', 'yes' ) ) {
            return false;
        }

        // Do not expose in admin (except via AJAX previews)
        // In block editor, we want the gateway to be available so it can be configured
        // if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        //     // Check if we are in the block editor
        //     if ( function_exists( 'get_current_screen' ) ) {
        //         $screen = get_current_screen();
        //         if ( $screen && $screen->is_block_editor() ) {
        //             return true;
        //         }
        //     }
        //     // Otherwise, return false in admin
        //     // return false; // Actually, WooCommerce core gateways don't return false in admin. Let's just remove this check.
        // }

        // If cart exists, require non-zero total
        if ( ! is_admin() && function_exists( 'WC' ) && WC()->cart ) {
            if ( floatval( WC()->cart->total ) <= 0 ) {
                return false;
            }
        }

        // only show the gateway when at least one active destination card is configured
        $cards = array();
        if ( method_exists( 'ShetabVerify_DB', 'get_active_cards' ) ) {
            $cards = ShetabVerify_DB::get_active_cards();
            if ( empty( $cards ) ) {
                $result = false;

                // debug transient for runtime availability checks
                set_transient( 'shetab_verify_is_available_debug', array(
                    'time' => current_time( 'mysql' ),
                    'enabled_option' => $this->get_option( 'enabled', 'no' ),
                    'cards_count' => 0,
                    'cart_total' => ( function_exists( 'WC' ) && WC()->cart ) ? floatval( WC()->cart->total ) : null,
                    'is_admin' => is_admin(),
                    'result' => $result,
                ), 60 );

                return $result;
            }
        }

        $result = true;

        // debug transient for runtime availability checks
        set_transient( 'shetab_verify_is_available_debug', array(
            'time' => current_time( 'mysql' ),
            'enabled_option' => $this->get_option( 'enabled', 'no' ),
            'cards_count' => is_array( $cards ) ? count( $cards ) : 0,
            'cart_total' => ( function_exists( 'WC' ) && WC()->cart ) ? floatval( WC()->cart->total ) : null,
            'is_admin' => is_admin(),
            'result' => $result,
        ), 60 );

        return $result;
    }

    public function payment_fields() {
        if ( $description = $this->get_description() ) {
            echo wpautop( wp_kses_post( $description ) );
        }
        echo '<p>' . esc_html__( 'After checkout you will be shown a bank card number and an amount to transfer (including a unique suffix).', 'shetab-verify' ) . '</p>';
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Invalid order.', 'shetab-verify' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $original_amount = (int) round( $order->get_total() );
        $unique_amount   = ShetabVerify_Utils::generate_unique_amount( $original_amount );
        if ( ! $unique_amount ) {
            wc_add_notice( __( 'Unable to generate a unique payment amount. Please try again.', 'shetab-verify' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $cards = ShetabVerify_DB::get_active_cards();
        if ( empty( $cards ) ) {
            wc_add_notice( __( 'No destination bank cards configured. Please contact the store owner.', 'shetab-verify' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // choose the first active card that is within configured limits
        $card = null;
        foreach ( $cards as $c ) {
            $usage = ShetabVerify_DB::get_card_usage( $c->id, $c->reset_period );
            $count_ok = ( $c->max_deposits_count == 0 || $usage['count'] < $c->max_deposits_count );
            $sum_ok   = ( $c->max_total_amount == 0 || $usage['total'] + $unique_amount <= $c->max_total_amount );
            if ( $count_ok && $sum_ok && $c->active ) {
                $card = $c;
                break;
            }
        }

        if ( ! $card ) {
            wc_add_notice( __( 'No available bank cards are currently accepting payments. Please contact the store owner.', 'shetab-verify' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $expires_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 10 * MINUTE_IN_SECONDS );

        $txn_id = ShetabVerify_DB::create_transaction( array(
            'order_id' => $order_id,
            'card_id' => $card->id,
            'original_amount' => $original_amount,
            'unique_amount' => $unique_amount,
            'status' => 'pending',
            'expires_at' => $expires_at,
        ) );

        $order->update_meta_data( 'shetab_transaction_id', $txn_id );
        $order->update_meta_data( 'shetab_unique_amount', $unique_amount );
        $order->save();

        // mark order pending/on-hold while waiting for transfer
        $order->update_status( 'on-hold', __( 'Awaiting bank transfer (ShetabVerify).', 'shetab-verify' ) );

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    public static function render_payment_instructions( $order_id ) {
        $txn = ShetabVerify_DB::get_transaction_by_order_id( $order_id );
        if ( ! $txn || $txn->status !== 'pending' ) {
            return;
        }

        $cards = ShetabVerify_DB::get_cards();
        $card  = null;
        foreach ( $cards as $c ) {
            if ( $c->id == $txn->card_id ) {
                $card = $c;
                break;
            }
        }

        $expires_at_ts = strtotime( $txn->expires_at );
        $now_ts = current_time( 'timestamp' );
        $remaining = max( 0, $expires_at_ts - $now_ts );

        ?>
        <div class="shetab-verify-instructions">
            <h2><?php esc_html_e( 'ShetabVerify — اطلاعات پرداخت', 'shetab-verify' ); ?></h2>
            <p><?php printf( esc_html__( 'مبلغ قابل پرداخت: %s تومان', 'shetab-verify' ), number_format_i18n( $txn->unique_amount ) ); ?></p>
            <?php if ( $card ) : ?>
                <p><?php printf( esc_html__( 'شماره کارت: %s', 'shetab-verify' ), esc_html( $card->masked_number ) ); ?></p>
            <?php endif; ?>
            <p id="shetab-countdown-<?php echo esc_attr( $txn->id ); ?>"><?php echo esc_html( sprintf( __( 'زمان باقیمانده: %s', 'shetab-verify' ), gmdate( 'i:s', $remaining ) ) ); ?></p>
            <p><?php esc_html_e( 'پس از انتقال، سامانه بیرونی باید به API ما اطلاع دهد تا سفارش تأیید شود.', 'shetab-verify' ); ?></p>
        </div>
        <script>
        (function(){
            var remaining = <?php echo (int) $remaining; ?>;
            var txnId = <?php echo (int) $txn->id; ?>;
            var orderId = <?php echo (int) $order_id; ?>;
            var el = document.getElementById('shetab-countdown-' + txnId);

            function tick(){
                if (remaining <= 0) { el.textContent = '<?= esc_js( __( "زمان به پایان رسیده.", 'shetab-verify' ) ); ?>'; return; }
                remaining--; var mm = Math.floor(remaining/60); var ss = remaining % 60; el.textContent = '<?= esc_js( __( "زمان باقیمانده:", 'shetab-verify' ) ); ?> ' + (mm<10?('0'+mm):mm) + ':' + (ss<10?('0'+ss):ss);
            }
            setInterval(tick, 1000);

            // polling status every 5s
            setInterval(function(){
                fetch( window.location.origin + '/wp-json/shetab-verify/v1/status?order_id=' + orderId )
                    .then(function(r){ return r.json(); })
                    .then(function(data){ if ( data && data.status === 'confirmed' ) { location.reload(); } });
            }, 5000);
        })();
        </script>
        <?php
    }
}
