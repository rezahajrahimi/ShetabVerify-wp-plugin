<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebDide_CV_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_receipt_metabox' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_receipt_actions' ) );
    }

    public static function add_order_receipt_metabox() {
        add_meta_box(
            'shetab_order_receipts',
            __( 'Bank Transfer Slips (Shetab)', 'webdide-card-to-card-verification' ),
            array( __CLASS__, 'render_order_receipt_metabox' ),
            array( 'shop_order', 'woocommerce_page_wc-orders' ),
            'side',
            'high'
        );
    }

    public static function render_order_receipt_metabox( $post_or_order ) {
        // Handle both Post (Legacy) and Order (HPOS) objects
        $order = null;
        if ( $post_or_order instanceof WC_Order ) {
            $order = $post_or_order;
        } elseif ( $post_or_order instanceof WP_Post ) {
            $order = wc_get_order( $post_or_order->ID );
        } elseif ( is_numeric( $post_or_order ) ) {
            $order = wc_get_order( $post_or_order );
        }

        if ( ! $order ) return;

        $order_id = $order->get_id();
        $receipts = $order->get_meta( '_shetab_receipts' );
        
        if ( empty( $receipts ) || ! is_array( $receipts ) ) {
            echo '<p style="color:#666; font-style:italic;">' . esc_html__( 'No slips have been uploaded for this order.', 'webdide-card-to-card-verification' ) . '</p>';
            return;
        }

        echo '<div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:10px; margin-bottom:15px; background: #f9f9f9; padding: 10px; border-radius: 5px;">';
        foreach ( $receipts as $aid ) {
            $url = wp_get_attachment_url( $aid );
            $img = wp_get_attachment_image_src( $aid, 'thumbnail' );
            if ( $img ) {
                echo '<a href="' . esc_url( $url ) . '" target="_blank" style="display:block; border: 2px solid #eee; border-radius: 4px; overflow:hidden;">';
                echo '<img src="' . esc_url( $img[0] ) . '" style="width:100%; height:80px; object-fit:cover; display:block;">';
                echo '</a>';
            } else {
                echo '<div style="background:#eee; height:80px; display:flex; align-items:center; justify-content:center; font-size:10px; color:#999; text-align:center;">' . esc_html__( 'Image not found', 'webdide-card-to-card-verification' ) . '<br>(#'.esc_html($aid).')</div>';
            }
        }
        echo '</div>';

        $confirm_nonce = wp_create_nonce( 'shetab_receipt_action' );
        ?>
        <div style="display:flex; gap:5px; margin-top:10px;">
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=shetab_confirm_receipt&order_id=' . $order_id . '&_nonce=' . $confirm_nonce ) ); ?>" 
               class="button button-primary" style="background:#38a169; border-color:#38a169;">✅ <?php esc_html_e( 'Confirm Slip', 'webdide-card-to-card-verification' ); ?></a>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=shetab_reject_receipt&order_id=' . $order_id . '&_nonce=' . $confirm_nonce ) ); ?>" 
               class="button button-secondary" style="color:#e53e3e; border-color:#e53e3e;">❌ <?php esc_html_e( 'Invalid', 'webdide-card-to-card-verification' ); ?></a>
        </div>
        <p style="color:#666; font-size:0.85em; margin-top:10px;"><?php esc_html_e( 'Confirming the slip will set the order status to "Processing".', 'webdide-card-to-card-verification' ); ?></p>
        <?php
    }

    public static function handle_receipt_actions() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        $action = isset( $_GET['action'] ) ? $_GET['action'] : '';
        if ( ! in_array( $action, array( 'shetab_confirm_receipt', 'shetab_reject_receipt' ) ) ) return;

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $nonce    = isset( $_GET['_nonce'] ) ? $_GET['_nonce'] : '';
        
        if ( ! wp_verify_nonce( $nonce, 'shetab_receipt_action' ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( $action === 'shetab_confirm_receipt' ) {
            $order->payment_complete();
            $order->add_order_note( __( 'Payment slip confirmed by admin.', 'webdide-card-to-card-verification' ) );
            
            // Sync with local transactions table to update card statistics
            $txn = WebDide_CV_DB::get_transaction_by_order_id( $order_id );
            if ( $txn && $txn->status !== 'confirmed' ) {
                WebDide_CV_DB::mark_transaction_confirmed( $txn->id, 'manual_admin_confirmation' );
            }
        } else {
            $order->update_status( 'failed', __( 'Payment slip marked as invalid by admin.', 'webdide-card-to-card-verification' ) );
            $order->add_order_note( __( 'Payment slip was marked as invalid and order rejected.', 'webdide-card-to-card-verification' ) );
        }


        // Use get_edit_post_link if possible, otherwise fallback to referer or simple redirect
        $redirect_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        
        // Check if we are using the new WooCommerce Order Screen (HPOS)
        if ( function_exists( 'wc_get_container' ) && method_exists( $order, 'get_id' ) ) {
            // Modern WC way to get edit link
            $edit_link = ( function_exists( 'get_edit_post_link' ) ) ? get_edit_post_link( $order_id ) : '';
            if ( $edit_link ) {
                $redirect_url = $edit_link;
            }
        }

        wp_redirect( $redirect_url );
        exit;
    }

    public static function register_menu() {
        add_menu_page(
            __( 'Shetab Management', 'webdide-card-to-card-verification' ),
            __( 'Shetab Management', 'webdide-card-to-card-verification' ),
            'manage_woocommerce',
            'webdide-card-to-card-verification',
            array( __CLASS__, 'render_settings_page' ),
            'dashicons-admin-generic',
            56
        );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'Insufficient permissions', 'webdide-card-to-card-verification' ) );
        }

        $messages = array();
        if ( isset( $_POST['shetab_action'] ) && check_admin_referer( 'wdcv_admin' ) ) {
            $action = sanitize_text_field( wp_unslash( $_POST['shetab_action'] ) );

            if ( $action === 'add_card' ) {
                $label = sanitize_text_field( wp_unslash( $_POST['label'] ) );
                $number = preg_replace( '/\D/', '', wp_unslash( $_POST['card_number'] ) );
                $encrypted = WebDide_CV_Utils::encrypt_card_number( $number );
                $masked = WebDide_CV_Utils::mask_card_number( $number );
                WebDide_CV_DB::insert_card( array(
                    'label' => $label,
                    'encrypted_number' => $encrypted,
                    'masked_number' => $masked,
                    'max_deposits_count' => absint( $_POST['max_deposits_count'] ?? 0 ),
                    'max_total_amount' => absint( $_POST['max_total_amount'] ?? 0 ),
                    'reset_period' => sanitize_text_field( $_POST['reset_period'] ?? 'none' ),
                    'active' => isset( $_POST['active'] ) ? 1 : 0,
                ) );
                $messages[] = __( 'Card added.', 'webdide-card-to-card-verification' );
            }

            if ( $action === 'delete_card' && ! empty( $_POST['delete_card'] ) ) {
                WebDide_CV_DB::delete_card( absint( $_POST['delete_card'] ) );
                $messages[] = __( 'Card removed.', 'webdide-card-to-card-verification' );
            }

            if ( $action === 'save_secret' && isset( $_POST['api_secret'] ) ) {
                $secret = sanitize_text_field( wp_unslash( $_POST['api_secret'] ) );
                WebDide_CV_Utils::set_api_secret( $secret );
                $messages[] = __( 'API Secret saved successfully.', 'webdide-card-to-card-verification' );
            }

            if ( $action === 'save_support_info' ) {
                update_option( 'wdcv_support_whatsapp', sanitize_text_field( $_POST['support_whatsapp'] ?? '' ) );
                update_option( 'wdcv_support_telegram', sanitize_text_field( $_POST['support_telegram'] ?? '' ) );
                update_option( 'wdcv_support_manager_text', sanitize_textarea_field( $_POST['support_manager_text'] ?? '' ) );
                $messages[] = __( 'Support information saved successfully.', 'webdide-card-to-card-verification' );
            }
        }

        $cards = WebDide_CV_DB::get_cards();
        $api_secret = WebDide_CV_Utils::get_api_secret();

        $confirm_api_url = home_url( '/wp-json/webdide-cv/v1/confirm' );
        $status_api_url = home_url( '/wp-json/webdide-cv/v1/status' );
        ?>
        

        <div class="wdcv-admin-wrap">
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 30px;">
                <img src="<?php echo esc_url( WDCV_PLUGIN_URL . 'public/assets/images/logo.png' ); ?>" style="height: 60px; width: auto;" alt="Shetab Logo">
                <h1 style="margin: 0; padding: 0;"><?php esc_html_e( 'card-to-card verification', 'webdide-card-to-card-verification' ); ?></h1>
            </div>

            <?php if ( get_option( 'permalink_structure' ) === '' ) : ?>
                <div class="notice notice-error" style="border-right-color: #d63638; background: #fff; padding: 15px; border-right-width: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin: 20px 0;">
                    <h3 style="color: #d63638; margin-top: 0; font-weight: bold;"><?php esc_html_e( '⚠️ Warning: Missing Permalinks Configuration', 'webdide-card-to-card-verification' ); ?></h3>
                    <p style="font-size: 1rem; line-height: 1.6;">
                        <?php echo wp_kses_post( __( 'To ensure the automatic confirmation system (API) works correctly, you must set your WordPress <strong>"Permalinks"</strong> to anything other than "Plain".', 'webdide-card-to-card-verification' ) ); ?>
                        <br>
                        <?php
                        printf(
                            /* translators: %s: Link to the permalinks settings page */
                            wp_kses_post( __( 'Please go to %s and set the structure (e.g., to "Post name").', 'webdide-card-to-card-verification' ) ),
                            sprintf(
                                '<a href="%1$s" target="_blank"><strong>%2$s</strong></a>',
                                esc_url( admin_url( 'options-permalink.php' ) ),
                                esc_html__( 'Settings > Permalinks', 'webdide-card-to-card-verification' )
                            )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php foreach ( $messages as $m ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $m ); ?></p></div>
            <?php endforeach; ?>

            <div class="wdcv-card">
                <h2><?php esc_html_e( 'Guides & Resources', 'webdide-card-to-card-verification' ); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #edf2f7; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h3 style="margin-top: 0; font-size: 1.1rem; color: #2d3748;">
                                <span class="dashicons dashicons-download" style="margin-left: 8px; color: #3182ce;"></span>
                                <?php esc_html_e( 'Download Android App', 'webdide-card-to-card-verification' ); ?>
                            </h3>
                            <p style="font-size: 0.9rem; color: #4a5568; line-height: 1.6;">
                                <?php esc_html_e( 'To use automatic bank transfer confirmation, download and install the Shetab app from Cafe Bazaar.', 'webdide-card-to-card-verification' ); ?>
                            </p>
                        </div>
                        <a href="https://cafebazaar.ir/app/ir.webdide.verify" target="_blank" class="wdcv-btn" style="display: block; text-decoration: none; text-align: center; margin-top: 15px;">
                            <?php esc_html_e( 'Download from Cafe Bazaar', 'webdide-card-to-card-verification' ); ?>
                        </a>
                    </div>

                    <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #edf2f7; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h3 style="margin-top: 0; font-size: 1.1rem; color: #2d3748;">
                                <span class="dashicons dashicons-welcome-learn-more" style="margin-left: 8px; color: #38a169;"></span>
                                <?php esc_html_e( 'User Guide', 'webdide-card-to-card-verification' ); ?>
                            </h3>
                            <p style="font-size: 0.9rem; color: #4a5568; line-height: 1.6;">
                                <?php esc_html_e( 'Watch tutorials and read guides for a correct configuration.', 'webdide-card-to-card-verification' ); ?>
                            </p>
                        </div>
                        <a href="http://verify.webdide.ir/" target="_blank" class="wdcv-btn" style="display: block; text-decoration: none; text-align: center; margin-top: 15px; background: #4a5568;">
                            <?php esc_html_e( 'View Tutorials', 'webdide-card-to-card-verification' ); ?>
                        </a>
                    </div>

                    <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #edf2f7; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h3 style="margin-top: 0; font-size: 1.1rem; color: #2d3748;">
                                <span class="dashicons dashicons-admin-site-alt3" style="margin-left: 8px; color: #718096;"></span>
                                <?php esc_html_e( 'Developer Website', 'webdide-card-to-card-verification' ); ?>
                            </h3>
                            <p style="font-size: 0.9rem; color: #4a5568; line-height: 1.6;">
                                <?php esc_html_e( 'Visit our website for support and the latest updates.', 'webdide-card-to-card-verification' ); ?>
                            </p>
                        </div>
                        <a href="http://verify.webdide.ir/" target="_blank" class="wdcv-btn" style="display: block; text-decoration: none; text-align: center; margin-top: 15px; background: #718096;">
                            <?php esc_html_e( 'Visit Website', 'webdide-card-to-card-verification' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="wdcv-card">
                <h2><?php esc_html_e( 'Secret API (Private Key)', 'webdide-card-to-card-verification' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'wdcv_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="save_secret">
                    <div class="wdcv-form-group">
                        <label><?php echo esc_html( 'مقدار کلید:' ); ?></label>
                        <input name="api_secret" type="text" class="regular-text" value="<?php echo esc_attr($api_secret); ?>">
                        <p class="description"><?php echo esc_html( $api_secret ? 'کلید هم اکنون تنظیم شده است.' : 'هنوز کلیدی تنظیم نشده است.' ); ?></p>
                    </div>
                    <?php if ( $api_secret ) : ?>
                        <div class="wdcv-api-info">
                            <span><?php echo esc_html($api_secret); ?></span>
                            <button type="button" class="wdcv-copy-btn" onclick="copyToClipboard('<?php echo esc_js($api_secret); ?>')"><?php echo 'کپی به کلیپبورد'; ?></button>
                        </div>
                        <div class="wdcv-qr-container">
                            <div class="wdcv-qr-image">
                                <img src="<?php echo esc_url( 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $api_secret ) ); ?>" alt="QR Secret">
                            </div>
                            <span style="font-size:0.8rem; color:#718096;"><?php echo esc_html( 'اسکن برای کپی کلید' ); ?></span>
                        </div>
                    <?php endif; ?>
                    <p style="margin-top:20px;"><button type="submit" class="wdcv-btn"><?php echo 'ذخیره کلید مخفی'; ?></button></p>
                </form>
            </div>

            <div class="wdcv-card">
                <h2><?php esc_html_e( 'API URL Addresses', 'webdide-card-to-card-verification' ); ?></h2>
                <div class="wdcv-form-group">
                    <label><?php esc_html_e( 'Confirm Payment (POST):', 'webdide-card-to-card-verification' ); ?></label>
                    <div class="wdcv-api-info">
                        <code><?php echo esc_html($confirm_api_url); ?></code>
                        <button type="button" class="wdcv-copy-btn" onclick="copyToClipboard('<?php echo esc_js($confirm_api_url); ?>')"><?php esc_html_e( 'Copy', 'webdide-card-to-card-verification' ); ?></button>
                    </div>
                    <div class="wdcv-qr-container" style="display:inline-flex; margin-right:20px;">
                        <img class="wdcv-qr-image" src="<?php echo esc_url( 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode( $confirm_api_url ) ); ?>" width="100">
                    </div>
                </div>
                <div class="wdcv-form-group">
                    <label><?php esc_html_e( 'Payment Status (GET):', 'webdide-card-to-card-verification' ); ?></label>
                    <div class="wdcv-api-info">
                        <code><?php echo esc_html($status_api_url); ?></code>
                        <button type="button" class="wdcv-copy-btn" onclick="copyToClipboard('<?php echo esc_js($status_api_url); ?>')"><?php esc_html_e( 'Copy', 'webdide-card-to-card-verification' ); ?></button>
                    </div>
                    <div class="wdcv-qr-container" style="display:inline-flex;">
                        <img class="wdcv-qr-image" src="<?php echo esc_url( 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode( $status_api_url ) ); ?>" width="100">
                    </div>
                </div>
            </div>

            <div class="wdcv-card">
                <h2><?php esc_html_e( 'Add New Bank Card', 'webdide-card-to-card-verification' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'wdcv_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="add_card">
                    <div class="wdcv-form-group">
                        <label for="label"><?php esc_html_e( 'Card Label/Name:', 'webdide-card-to-card-verification' ); ?></label>
                        <input name="label" id="label" type="text" required placeholder="<?php esc_attr_e( 'e.g. Primary Shop Card', 'webdide-card-to-card-verification' ); ?>">
                    </div>
                    <div class="wdcv-form-group">
                        <label for="card_number"><?php esc_html_e( '16-Digit Card Number:', 'webdide-card-to-card-verification' ); ?></label>
                        <input name="card_number" id="card_number" type="text" maxlength="16" required placeholder="0000000000000000">
                    </div>
                    <div class="wdcv-form-group">
                        <label for="max_deposits_count"><?php esc_html_e( 'Transaction Limit (Count):', 'webdide-card-to-card-verification' ); ?></label>
                        <input name="max_deposits_count" id="max_deposits_count" type="number" min="0" value="0">
                        <p class="description"><?php esc_html_e( '0 means unlimited', 'webdide-card-to-card-verification' ); ?></p>
                    </div>
                    <div class="wdcv-form-group">
                        <label for="max_total_amount"><?php esc_html_e( 'Total Amount Limit (Toman):', 'webdide-card-to-card-verification' ); ?></label>
                        <input name="max_total_amount" id="max_total_amount" type="number" min="0" value="0">
                        <p class="description"><?php esc_html_e( '0 means unlimited', 'webdide-card-to-card-verification' ); ?></p>
                    </div>
                    <div class="wdcv-form-group">
                        <label for="reset_period"><?php esc_html_e( 'Limit Reset Period:', 'webdide-card-to-card-verification' ); ?></label>
                        <select name="reset_period" id="reset_period">
                            <option value="none"><?php esc_html_e( 'No Reset (Forever)', 'webdide-card-to-card-verification' ); ?></option>
                            <option value="daily"><?php esc_html_e( 'Daily', 'webdide-card-to-card-verification' ); ?></option>
                            <option value="monthly"><?php esc_html_e( 'Monthly', 'webdide-card-to-card-verification' ); ?></option>
                        </select>
                    </div>
                    <div class="wdcv-form-group">
                        <label><input name="active" type="checkbox" checked> <?php esc_html_e( 'Card is active', 'webdide-card-to-card-verification' ); ?></label>
                    </div>
                    <button type="submit" class="wdcv-btn"><?php esc_html_e( 'Add Card', 'webdide-card-to-card-verification' ); ?></button>
                </form>
            </div>

            <div class="wdcv-card">
                <h2><?php esc_html_e( 'Existing Cards', 'webdide-card-to-card-verification' ); ?></h2>
                <table class="wdcv-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ID', 'webdide-card-to-card-verification' ); ?></th>
                            <th><?php esc_html_e( 'Label', 'webdide-card-to-card-verification' ); ?></th>
                            <th><?php esc_html_e( 'Full Card Number', 'webdide-card-to-card-verification' ); ?></th>
                            <th><?php esc_html_e( 'Limits (Count/Amount)', 'webdide-card-to-card-verification' ); ?></th>
                            <th><?php esc_html_e( 'Current Usage', 'webdide-card-to-card-verification' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'webdide-card-to-card-verification' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'webdide-card-to-card-verification' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $cards ) ) : ?>
                            <tr><td colspan="7" style="text-align:center; padding: 20px;"><?php esc_html_e( 'No cards have been configured yet.', 'webdide-card-to-card-verification' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $cards as $c ) : ?>
                                <?php 
                                    $full_number = WebDide_CV_Utils::decrypt_card_number($c->encrypted_number); 
                                    $usage = WebDide_CV_DB::get_card_usage( $c->id, $c->reset_period );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $c->id ); ?></td>
                                    <td><?php echo esc_html( $c->label ); ?></td>
                                    <td>
                                        <div style="direction:ltr; text-align:right;">
                                            <?php echo esc_html( $full_number ); ?>
                                            <button type="button" class="wdcv-copy-btn" onclick="copyToClipboard('<?php echo esc_js($full_number); ?>')" style="padding: 2px 5px; font-size: 0.7rem;"><?php esc_html_e( 'Copy', 'webdide-card-to-card-verification' ); ?></button>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html( $c->max_deposits_count ?: '∞' ); ?> / <?php echo esc_html( $c->max_total_amount ? number_format_i18n( $c->max_total_amount ) : '∞' ); ?></td>
                                    <td>
                                        <div style="font-size: 0.85rem; line-height: 1.4;">
                                            <strong><?php esc_html_e( 'Txns:', 'webdide-card-to-card-verification' ); ?></strong> <?php echo esc_html($usage['count']); ?>
                                            <br><strong><?php esc_html_e( 'Sum:', 'webdide-card-to-card-verification' ); ?></strong> <?php echo number_format_i18n($usage['total']); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?>
                                            <?php if ( $usage['count'] > 0 ) : ?>
                                                <div style="margin-top:5px;">
                                                    <button type="button" 
                                                            style="color: #3182ce; background: none; border: none; padding: 0; cursor: pointer; text-decoration: underline; font-size: 0.8rem; font-weight: 600;" 
                                                            data-orders="<?php echo esc_attr(wp_json_encode($usage['orders'])); ?>" 
                                                            data-card-label="<?php echo esc_attr($c->label); ?>"
                                                            onclick="showCardOrdersFromData(this)">
                                                        <?php printf( esc_html__( 'View Details (%d orders)', 'webdide-card-to-card-verification' ), $usage['count'] ); ?>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo $c->active ? '<span style="color:#38a169;">✅ ' . esc_html__( 'Active', 'webdide-card-to-card-verification' ) . '</span>' : '<span style="color:#e53e3e;">❌ ' . esc_html__( 'Inactive', 'webdide-card-to-card-verification' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to remove this card?', 'webdide-card-to-card-verification' ); ?>');">
                                            <?php wp_nonce_field( 'wdcv_admin' ); ?>
                                            <input type="hidden" name="shetab_action" value="delete_card">
                                            <input type="hidden" name="delete_card" value="<?php echo esc_attr( $c->id ); ?>">
                                            <button type="submit" class="wdcv-copy-btn wdcv-btn-danger"><?php esc_html_e( 'Delete', 'webdide-card-to-card-verification' ); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="wdcv-card">
                <h2><?php esc_html_e( 'Support Information', 'webdide-card-to-card-verification' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'wdcv_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="save_support_info">
                    <div class="wdcv-form-group">
                        <label><?php esc_html_e( 'WhatsApp ID (e.g. 989123456789):', 'webdide-card-to-card-verification' ); ?></label>
                        <input name="support_whatsapp" type="text" value="<?php echo esc_attr(get_option('wdcv_support_whatsapp')); ?>" placeholder="989...">
                    </div>
                    <div class="wdcv-form-group">
                        <label><?php esc_html_e( 'Telegram ID:', 'webdide-card-to-card-verification' ); ?></label>
                        <input name="support_telegram" type="text" value="<?php echo esc_attr(get_option('wdcv_support_telegram')); ?>" placeholder="@username">
                    </div>
                    <div class="wdcv-form-group">
                        <label><?php esc_html_e( 'Manager Note for Users:', 'webdide-card-to-card-verification' ); ?></label>
                        <textarea name="support_manager_text" rows="4" style="max-width:600px;"><?php echo esc_textarea(get_option('wdcv_support_manager_text')); ?></textarea>
                    </div>
                    <button type="submit" class="wdcv-btn"><?php esc_html_e( 'Save Support Info', 'webdide-card-to-card-verification' ); ?></button>
                </form>
            </div>
        </div>

        <!-- Order List Modal -->
        <div id="orderModal" class="wdcv-modal">
            <div class="wdcv-modal-content">
                <div class="wdcv-modal-header">
                    <h3 class="wdcv-modal-title" id="modalTitle"><?php esc_html_e( 'Order List', 'webdide-card-to-card-verification' ); ?></h3>
                    <span class="wdcv-close" onclick="closeModal()">&times;</span>
                </div>
                <div id="modalBody">
                    <table class="wdcv-detail-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Order ID', 'webdide-card-to-card-verification' ); ?></th>
                                <th><?php esc_html_e( 'Amount (Toman)', 'webdide-card-to-card-verification' ); ?></th>
                                <th><?php esc_html_e( 'Confirmation Date', 'webdide-card-to-card-verification' ); ?></th>
                                <th><?php esc_html_e( 'View', 'webdide-card-to-card-verification' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="orderTableBody"></tbody>
                    </table>
                    <div id="modalPagination" class="wdcv-pagination"></div>
                </div>
            </div>
        </div>

        <?php
    }
}








