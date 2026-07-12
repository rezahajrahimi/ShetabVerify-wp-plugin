<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    return;
}

class WebDide_CV_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id                 = 'wdcv';
        $this->has_fields         = false;
        $this->method_title       = 'کارت به کارت (تایید خودکار)';
        $this->method_description = 'کارت به کارت با استفاده از درگاه شتاب (تایید خودکار تراکنش).';
        $this->icon               = WDCV_PLUGIN_URL . 'public/assets/images/logo.png';

        $this->supports = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', 'کارت به کارت (تایید خودکار)' );
        $this->description = $this->get_option( 'description', 'کارت به کارت با استفاده از درگاه شتاب (تایید خودکار تراکنش).' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'change_thankyou_text' ), 10, 2 );
    }

    public function change_thankyou_text( $text, $order ) {
        if ( $order && $order->get_payment_method() === $this->id ) {
            if ( $order->get_status() === 'on-hold' ) {
                return $this->get_option( 'thankyou_awaiting_message', 'سفارش شما ثبت شده و در انتظار پرداخت می‌باشد. لطفاً جهت نهایی شدن سفارش، مبلغ مورد نظر را طبق دستورالعمل زیر واریز نمایید.' );
            }
            
            if ( in_array( $order->get_status(), array( 'processing', 'completed' ) ) ) {
                return $this->get_option( 'thankyou_success_message', 'پرداخت شما با موفقیت تایید شد. سفارش شما در حال پردازش می‌باشد.' );
            }
        }
        return $text;
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
                'default' => 'کارت به کارت (تایید خودکار)',
            ),
            'thankyou_awaiting_message' => array(
                'title'   => 'پیام در انتظار پرداخت',
                'type'    => 'textarea',
                'default' => 'سفارش شما ثبت شده و در انتظار پرداخت می‌باشد. لطفاً جهت نهایی شدن سفارش، مبلغ مورد نظر را طبق دستورالعمل زیر واریز نمایید.',
                'description' => 'این پیام زمانی نمایش داده می‌شود که تراکنش هنوز تایید نشده است.',
            ),
            'thankyou_success_message' => array(
                'title'   => 'پیام موفقیت پرداخت',
                'type'    => 'textarea',
                'default' => 'پرداخت شما با موفقیت تایید شد. سفارش شما در حال پردازش می‌باشد.',
                'description' => 'این پیام زمانی نمایش داده می‌شود که اپلیکیشن پرداخت را تایید کرده باشد.',
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
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return true; // Allow in admin for settings/configuration
        }

        // If cart exists, require non-zero total (but allow in admin)
        if ( ! is_admin() && function_exists( 'WC' ) && WC()->cart ) {
            if ( floatval( WC()->cart->total ) <= 0 ) {
                return false;
            }
        }

        // only show the gateway when at least one active destination card is configured
        $cards = array();
        if ( method_exists( 'WebDide_CV_DB', 'get_active_cards' ) ) {
            $cards = WebDide_CV_DB::get_active_cards();
            if ( empty( $cards ) ) {
                $result = false;

                // debug transient for runtime availability checks
                set_transient( 'wdcv_is_available_debug', array(
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
        set_transient( 'wdcv_is_available_debug', array(
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
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Invalid order.', 'webdide-card-to-card-verification' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $original_amount = (int) round( $order->get_total() );
        $unique_amount   = WebDide_CV_Utils::generate_unique_amount( $original_amount );
        if ( ! $unique_amount ) {
            wc_add_notice( __( 'Unable to generate a unique payment amount. Please try again.', 'webdide-card-to-card-verification' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $cards = WebDide_CV_DB::get_active_cards();
        if ( empty( $cards ) ) {
            wc_add_notice( __( 'No destination bank cards configured. Please contact the store owner.', 'webdide-card-to-card-verification' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // choose the first active card that is within configured limits
        $card = null;
        foreach ( $cards as $c ) {
            $usage = WebDide_CV_DB::get_card_usage( $c->id, $c->reset_period );
            $count_ok = ( $c->max_deposits_count == 0 || $usage['count'] < $c->max_deposits_count );
            $sum_ok   = ( $c->max_total_amount == 0 || $usage['total'] + $unique_amount <= $c->max_total_amount );
            if ( $count_ok && $sum_ok && $c->active ) {
                $card = $c;
                break;
            }
        }

        if ( ! $card ) {
            wc_add_notice( __( 'No available bank cards are currently accepting payments. Please contact the store owner.', 'webdide-card-to-card-verification' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $expires_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 10 * MINUTE_IN_SECONDS );

        $txn_id = WebDide_CV_DB::create_transaction( array(
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
        $order->update_status( 'on-hold', __( 'Awaiting bank transfer (WebDide_CV).', 'webdide-card-to-card-verification' ) );

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    public static function render_payment_instructions( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $txn = WebDide_CV_DB::get_transaction_by_order_id( $order_id );
        
        // Always try to show receipts if they exist regardless of transaction status
        $receipts = $order->get_meta( '_shetab_receipts' );

        if ( ! $txn ) {
            return;
        }

        $cards = WebDide_CV_DB::get_cards();
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

        $whatsapp = get_option('wdcv_support_whatsapp');
        $telegram = get_option('wdcv_support_telegram');
        $manager_text = get_option('wdcv_support_manager_text');

        $full_card_number = $card ? WebDide_CV_Utils::decrypt_card_number($card->encrypted_number) : '';
        ?>
        

        <div class="shetab-instructions">
            <h2><?php echo esc_html( in_array( $order->get_status(), array( 'processing', 'completed' ) ) ? __( 'رسید پرداخت شما', 'webdide-card-to-card-verification' ) : __( 'اطلاعات پرداخت', 'webdide-card-to-card-verification' ) ); ?></h2>

            <?php if ( ! empty( $receipts ) ) : ?>
                <div style="background: #f0fff4; border: 1px solid #38a169; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="font-size: 2.5rem; margin-bottom: 10px;">⏳</div>
                    <strong style="color: #2f855a; font-size: 1.15rem;"><?php esc_html_e( 'فیش واریزی شما دریافت شد و در انتظار تایید مدیریت است.', 'webdide-card-to-card-verification' ); ?></strong>
                    <p style="margin-top: 10px; color: #4a5568;"><?php esc_html_e( 'پس از تایید کارشناسان، سفارش شما وارد مرحله ارسال خواهد شد.', 'webdide-card-to-card-verification' ); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ( $txn->status === 'pending' && empty( $receipts ) ) : ?>
                <p><?php esc_html_e( 'لطفاً مبلغ دقیق زیر را به شماره کارت اعلام شده منتقل نمایید:', 'webdide-card-to-card-verification' ); ?></p>
                
                <p class="shetab-amount"><?php printf( esc_html__( 'مبلغ: %s تومان', 'webdide-card-to-card-verification' ), number_format_i18n( $txn->unique_amount ) ); ?></p>
                
                <?php if ( $card ) : ?>
                    <div class="wdcv-card-box">
                        <span><?php esc_html_e( 'شماره کارت: ', 'webdide-card-to-card-verification' ); ?></span>
                        <strong style="letter-spacing: 2px;"><?php echo esc_html( $full_card_number ); ?></strong>
                        <p style="font-size: 0.9rem; margin-top: 5px; color: #4a5568;"><?php echo esc_html( $card->label ); ?></p>
                    </div>
                <?php endif; ?>

                <p class="shetab-countdown" id="shetab-countdown-<?php echo esc_attr( $txn->id ); ?>">
                    <?php printf( esc_html__( 'زمان باقیمانده برای انتقال: %s', 'webdide-card-to-card-verification' ), gmdate( 'i:s', (int) $remaining ) ); ?>
                </p>

                <!-- Receipt Upload Form -->
                <?php if ( empty( $receipts ) ) : ?>
                    <div class="shetab-upload-box" id="shetab-upload-container">
                        <strong><?php esc_html_e( 'آپلود تصویر فیش واریزی (اختیاری):', 'webdide-card-to-card-verification' ); ?></strong>
                        <p style="font-size: 0.85rem; color: #718096; margin-bottom: 10px;"><?php esc_html_e( 'اگر تراکنش شما تایید نشد، می‌توانید تصویر فیش را اینجا آپلود کنید.', 'webdide-card-to-card-verification' ); ?></p>
                        <input type="file" id="shetab-receipt-files" multiple accept="image/*" style="display:none;">
                        <button type="button" id="shetab-pick-receipt" class="button shetab-upload-btn"><?php esc_html_e( 'انتخاب تصویر فیش', 'webdide-card-to-card-verification' ); ?></button>
                        <button type="button" id="shetab-do-upload" class="button alt shetab-upload-btn" style="display:none;"><?php esc_html_e( 'ارسال فیش ها', 'webdide-card-to-card-verification' ); ?></button>
                        <div id="file-list-preview" class="shetab-receipt-preview"></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( ! empty( $receipts ) ) : ?>
                <div style="margin-top: 20px; border-top: 1px solid #edf2f7; padding-top: 15px;">
                    <strong><?php esc_html_e( 'تصاویر رسید آپلود شده:', 'webdide-card-to-card-verification' ); ?></strong>
                    <div class="shetab-receipt-preview">
                        <?php foreach ( $receipts as $aid ) : ?>
                            <a href="<?php echo esc_url( wp_get_attachment_url( $aid ) ); ?>" target="_blank">
                                <?php echo wp_get_attachment_image( $aid, 'thumbnail' ); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="shetab-support-info">
                <strong><?php esc_html_e( 'راهنمایی و پشتیبانی:', 'webdide-card-to-card-verification' ); ?></strong>
                <div style="margin-top: 10px;">
                    <?php if ($whatsapp) : ?>
                        <a href="https://wa.me/<?php echo esc_attr($whatsapp); ?>" class="shetab-support-item" target="_blank">
                            <?php esc_html_e( 'واتس‌اپ:', 'webdide-card-to-card-verification' ); ?> <?php echo esc_html($whatsapp); ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($telegram) : ?>
                        <a href="https://t.me/<?php echo esc_attr(str_replace('@', '', $telegram)); ?>" class="shetab-support-item" target="_blank">
                    <?php esc_html_e( 'تلگرام:', 'webdide-card-to-card-verification' ); ?> <?php echo esc_html($telegram); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($manager_text) : ?>
                    <div class="shetab-manager-msg">
                        <strong><?php esc_html_e( 'پیام مدیر:', 'webdide-card-to-card-verification' ); ?></strong>
                        <?php echo nl2br( esc_html( $manager_text ) ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        wp_enqueue_script(
            'wdcv-checkout',
            WDCV_PLUGIN_URL . 'public/js/checkout.js',
            array(),
            WDCV_VERSION,
            true
        );
        wp_localize_script( 'wdcv-checkout', 'wdcvCheckoutVars', array(
            'remaining'       => (int) $remaining,
            'txnId'           => (int) $txn->id,
            'orderId'         => (int) $order_id,
            'orderKey'        => $order->get_order_key(),
            'statusUrl'       => esc_url( get_rest_url( null, 'webdide-cv/v1/status' ) ),
            'uploadUrl'       => esc_url( get_rest_url( null, 'webdide-cv/v1/upload-receipt' ) ),
            'expiredText'     => __( 'زمان شما به پایان رسیده است.', 'webdide-card-to-card-verification' ),
            'timerText'       => __( 'زمان باقیمانده برای انتقال:', 'webdide-card-to-card-verification' ),
            'uploadingText'   => __( 'در حال ارسال...', 'webdide-card-to-card-verification' ),
            'sendText'        => __( 'ارسال فیش ها', 'webdide-card-to-card-verification' ),
            'errorText'       => __( 'خطا:', 'webdide-card-to-card-verification' ),
            'uploadErrorText' => __( 'مشکلی در آپلود پیش آمد.', 'webdide-card-to-card-verification' ),
            'systemErrorText' => __( 'خطای سیستمی در آپلود', 'webdide-card-to-card-verification' ),
        ) );
    }
}

if ( ! class_exists( 'WC_Gateway_WDCV' ) ) {
    class_alias( 'WebDide_CV_Gateway', 'WC_Gateway_WDCV' );
}










