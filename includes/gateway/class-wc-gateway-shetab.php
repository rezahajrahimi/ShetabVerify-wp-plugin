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
        $this->method_title       = 'کارت به کارت (تایید خودکار)';
        $this->method_description = 'کارت به کارت با استفاده از درگاه شتاب (تایید خودکار تراکنش).';
        $this->icon               = SSV_PLUGIN_URL . 'public/assets/images/logo.png';

        $this->supports = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

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
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $txn = ShetabVerify_DB::get_transaction_by_order_id( $order_id );
        
        // Always try to show receipts if they exist regardless of transaction status
        $receipts = $order->get_meta( '_shetab_receipts' );

        if ( ! $txn ) {
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

            /* Upload Styles */
            .shetab-upload-box { background: #fffaf0; border: 1px dashed #ed8936; padding: 20px; border-radius: 10px; margin-top: 20px; text-align: center; }
            .shetab-upload-btn { background: #ed8936; color: #fff; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; margin-top: 10px; display: inline-block; }
            .shetab-upload-btn:hover { background: #dd6b20; }
            .shetab-receipt-preview { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center; }
            .shetab-receipt-preview img { width: 80px; height: 80px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }

            /* Hide bulky WC elements ONLY while payment is pending/on-hold */
            <?php if ( $order->get_status() === 'on-hold' || $txn->status === 'pending' ) : ?>
            .woocommerce-order-details, 
            .woocommerce-customer-details {
                display: none !important;
            }
            <?php endif; ?>
        </style>

        <div class="shetab-instructions">
            <h2><?php echo ( in_array( $order->get_status(), array( 'processing', 'completed' ) ) ) ? 'رسید پرداخت شما' : 'اطلاعات پرداخت'; ?></h2>

            <?php if ( ! empty( $receipts ) ) : ?>
                <div style="background: #f0fff4; border: 1px solid #38a169; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="font-size: 2.5rem; margin-bottom: 10px;">⏳</div>
                    <strong style="color: #2f855a; font-size: 1.15rem;"><?php echo 'فیش واریزی شما دریافت شد و در انتظار تایید مدیریت است.'; ?></strong>
                    <p style="margin-top: 10px; color: #4a5568;"><?php echo 'پس از تایید کارشناسان، سفارش شما وارد مرحله ارسال خواهد شد.'; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ( $txn->status === 'pending' && empty( $receipts ) ) : ?>
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

                <!-- Receipt Upload Form -->
                <?php if ( empty( $receipts ) ) : ?>
                    <div class="shetab-upload-box" id="shetab-upload-container">
                        <strong><?php echo 'آپلود تصویر فیش واریزی (اختیاری):'; ?></strong>
                        <p style="font-size: 0.85rem; color: #718096; margin-bottom: 10px;"><?php echo 'اگر تراکنش شما تایید نشد، می‌توانید تصویر فیش را اینجا آپلود کنید.'; ?></p>
                        <input type="file" id="shetab-receipt-files" multiple accept="image/*" style="display:none;">
                        <label for="shetab-receipt-files" class="shetab-upload-btn"><?php echo 'انتخاب تصویر فیش'; ?></label>
                        <button type="button" id="shetab-do-upload" class="shetab-upload-btn" style="display:none; background: #38a169;"><?php echo 'ارسال فیش ها'; ?></button>
                        <div id="file-list-preview" class="shetab-receipt-preview"></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( ! empty( $receipts ) ) : ?>
                <div style="margin-top: 20px; border-top: 1px solid #edf2f7; padding-top: 15px;">
                    <strong><?php echo 'تصاویر رسید آپلود شده:'; ?></strong>
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
            var statusUrl = '<?php echo esc_url( get_rest_url( null, "shetab-verify/v1/status" ) ); ?>';
            var uploadUrl = '<?php echo esc_url( get_rest_url( null, "shetab-verify/v1/upload-receipt" ) ); ?>';

            // Timer Tick
            if (el) {
                function tick(){
                    if (remaining <= 0) { el.textContent = '<?php echo "زمان شما به پایان رسیده است."; ?>'; return; }
                    remaining--; 
                    var mm = Math.floor(remaining/60); 
                    var ss = remaining % 60; 
                    el.textContent = '<?php echo "زمان باقیمانده برای انتقال: "; ?> ' + (mm<10?('0'+mm):mm) + ':' + (ss<10?('0'+ss):ss);
                }
                setInterval(tick, 1000);
            }

            // Upload Logic
            var fileInput = document.getElementById('shetab-receipt-files');
            var uploadBtn = document.getElementById('shetab-do-upload');
            var previewBlock = document.getElementById('file-list-preview');

            if (fileInput) {
                fileInput.onchange = function() {
                    previewBlock.innerHTML = '';
                    if (this.files.length > 0) {
                        uploadBtn.style.display = 'inline-block';
                        for (var i=0; i<this.files.length; i++) {
                            var img = document.createElement('img');
                            img.src = URL.createObjectURL(this.files[i]);
                            previewBlock.appendChild(img);
                        }
                    } else {
                        uploadBtn.style.display = 'none';
                    }
                };
            }

            if (uploadBtn) {
                uploadBtn.onclick = function() {
                    var formData = new FormData();
                    formData.append('order_id', orderId);
                    for (var i=0; i<fileInput.files.length; i++) {
                        formData.append('receipts[]', fileInput.files[i]);
                    }
                    
                    this.disabled = true;
                    this.textContent = 'در حال ارسال...';

                    fetch(uploadUrl, { method: 'POST', body: formData })
                        .then(function(r){ return r.json(); })
                        .then(function(data){ 
                            if (data.success) {
                                alert(data.message);
                                window.location.reload();
                            } else {
                                alert('خطا: ' + (data.message || 'مشکلی در آپلود پیش آمد.'));
                                uploadBtn.disabled = false;
                                uploadBtn.textContent = 'ارسال فیش ها';
                            }
                        })
                        .catch(function(err){
                            console.error(err);
                            alert('خطای سیستمی در آپلود');
                            uploadBtn.disabled = false;
                        });
                };
            }

            // polling status every 5s with correct REST URL
            setInterval(function(){
                fetch( statusUrl + '?order_id=' + orderId )
                    .then(function(r){ return r.json(); })
                    .then(function(data){ 
                        if ( data && data.status === 'confirmed' ) { 
                            // Try to find if there is a redirection in return url or just reload
                            window.location.reload(); 
                        } 
                    })
                    .catch(function(err){ console.error('Error polling status:', err); });
            }, 5000);
        })();
        </script>
        <?php
    }
}
