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
        $this->method_title       = 'پرداخت شتاب (تایید خودکار)';
        $this->method_description = 'انتقال وجه بانکی با مبلغ منحصربه‌فرد (تایید خودکار تراکنش).';

        $this->supports = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'فعال/غیرفعال سازی',
                'type'    => 'checkbox',
                'label'   => 'فعال سازی درگاه شتاب',
                'default' => 'yes',
            ),
            'title'   => array(
                'title'   => 'عنوان درگاه',
                'type'    => 'text',
                'default' => 'انتقال کارت به کارت (شتاب)',
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

        $whatsapp = get_option('shetab_support_whatsapp');
        $telegram = get_option('shetab_support_telegram');
        $manager_text = get_option('shetab_support_manager_text');

        $full_card_number = $card ? ShetabVerify_Utils::decrypt_card_number($card->encrypted_number) : '';
        ?>
        <style>
            .shetab-instructions {
                direction: rtl;
                background: #fdfdfd;
                border: 2px solid #3182ce;
                border-radius: 12px;
                padding: 25px;
                margin: 20px 0;
                font-family: inherit;
                box-shadow: 0 4px 15px rgba(49, 130, 206, 0.1);
            }
            .shetab-instructions h2 { color: #2b6cb0; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-top: 0; }
            .shetab-amount { font-size: 1.4rem; color: #c53030; font-weight: bold; }
            .shetab-card-box { background: #ebf8ff; border: 1px dashed #4299e1; padding: 15px; border-radius: 8px; margin: 15px 0; font-size: 1.2rem; text-align: center; }
            .shetab-countdown { font-weight: bold; color: #718096; margin-top: 10px; }
            .shetab-support-info { background: #f7fafc; border-top: 1px solid #edf2f7; margin-top: 20px; padding-top: 15px; }
            .shetab-support-item { display: inline-block; margin-left: 20px; color: #4a5568; text-decoration: none; }
            .shetab-support-item img { vertical-align: middle; margin-left: 5px; width: 20px; }
            .shetab-manager-msg { font-style: italic; color: #4a5568; margin-top: 10px; padding: 10px; border-right: 4px solid #3182ce; background: #fff; }
        </style>

        <div class="shetab-instructions">
            <h2><?php echo 'اطلاعات پرداخت (ShetabVerify)'; ?></h2>
            <p><?php echo 'لطفاً مبلغ دقیق زیر را به شماره کارت اعلام شده منتقل نمایید:'; ?></p>
            
            <p class="shetab-amount"><?php printf( 'مبلغ: %s تومان', number_format_i18n( $txn->unique_amount ) ); ?></p>
            
            <?php if ( $card ) : ?>
                <div class="shetab-card-box">
                    <span><?php echo 'شماره کارت: '; ?></span>
                    <strong style="letter-spacing: 2px;"><?php echo esc_html( $full_card_number ); ?></strong>
                    <p style="font-size: 0.9rem; margin-top: 5px; color: #4a5568;"><?php echo esc_html( $card->label ); ?></p>
                </div>
            <?php endif; ?>

            <p class="shetab-countdown" id="shetab-countdown-<?php echo esc_attr( $txn->id ); ?>">
                <?php echo sprintf( 'زمان باقیمانده برای انتقال: %s', gmdate( 'i:s', $remaining ) ); ?>
            </p>

            <div class="shetab-support-info">
                <strong><?php echo 'راهنمایی و پشتیبانی:'; ?></strong>
                <div style="margin-top: 10px;">
                    <?php if ($whatsapp) : ?>
                        <a href="https://wa.me/<?php echo esc_attr($whatsapp); ?>" class="shetab-support-item" target="_blank">
                             واتس‌اپ: <?php echo esc_html($whatsapp); ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($telegram) : ?>
                        <a href="https://t.me/<?php echo esc_attr(str_replace('@', '', $telegram)); ?>" class="shetab-support-item" target="_blank">
                             تلگرام: <?php echo esc_html($telegram); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($manager_text) : ?>
                    <div class="shetab-manager-msg">
                        <strong><?php echo 'پیام مدیر: '; ?></strong>
                        <?php echo nl2br(esc_html($manager_text)); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
            var remaining = <?php echo (int) $remaining; ?>;
            var txnId = <?php echo (int) $txn->id; ?>;
            var orderId = <?php echo (int) $order_id; ?>;
            var el = document.getElementById('shetab-countdown-' + txnId);

            function tick(){
                if (remaining <= 0) { el.textContent = '<?php echo "زمان شما به پایان رسیده است."; ?>'; return; }
                remaining--; 
                var mm = Math.floor(remaining/60); 
                var ss = remaining % 60; 
                el.textContent = '<?php echo "زمان باقیمانده برای انتقال: "; ?> ' + (mm<10?('0'+mm):mm) + ':' + (ss<10?('0'+ss):ss);
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
