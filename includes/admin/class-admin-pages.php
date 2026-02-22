<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShetabVerify_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_receipt_metabox' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_receipt_actions' ) );
    }

    public static function add_order_receipt_metabox() {
        add_meta_box(
            'shetab_order_receipts',
            __( 'Bank Transfer Slips (Shetab)', 'shetab-verify' ),
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
            echo '<p style="color:#666; font-style:italic;">' . esc_html__( 'No slips have been uploaded for this order.', 'shetab-verify' ) . '</p>';
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
                echo '<div style="background:#eee; height:80px; display:flex; align-items:center; justify-content:center; font-size:10px; color:#999; text-align:center;">' . esc_html__( 'Image not found', 'shetab-verify' ) . '<br>(#'.esc_html($aid).')</div>';
            }
        }
        echo '</div>';

        $confirm_nonce = wp_create_nonce( 'shetab_receipt_action' );
        ?>
        <div style="display:flex; gap:5px; margin-top:10px;">
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=shetab_confirm_receipt&order_id=' . $order_id . '&_nonce=' . $confirm_nonce ) ); ?>" 
               class="button button-primary" style="background:#38a169; border-color:#38a169;">✅ <?php esc_html_e( 'Confirm Slip', 'shetab-verify' ); ?></a>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=shetab_reject_receipt&order_id=' . $order_id . '&_nonce=' . $confirm_nonce ) ); ?>" 
               class="button button-secondary" style="color:#e53e3e; border-color:#e53e3e;">❌ <?php esc_html_e( 'Invalid', 'shetab-verify' ); ?></a>
        </div>
        <p style="color:#666; font-size:0.85em; margin-top:10px;"><?php esc_html_e( 'Confirming the slip will set the order status to "Processing".', 'shetab-verify' ); ?></p>
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
            $order->add_order_note( __( 'Payment slip confirmed by admin.', 'shetab-verify' ) );
            
            // Sync with local transactions table to update card statistics
            $txn = ShetabVerify_DB::get_transaction_by_order_id( $order_id );
            if ( $txn && $txn->status !== 'confirmed' ) {
                ShetabVerify_DB::mark_transaction_confirmed( $txn->id, 'manual_admin_confirmation' );
            }
        } else {
            $order->update_status( 'failed', __( 'Payment slip marked as invalid by admin.', 'shetab-verify' ) );
            $order->add_order_note( __( 'Payment slip was marked as invalid and order rejected.', 'shetab-verify' ) );
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
            __( 'Shetab Management', 'shetab-verify' ),
            __( 'Shetab Management', 'shetab-verify' ),
            'manage_woocommerce',
            'shetab-verify',
            array( __CLASS__, 'render_settings_page' ),
            'dashicons-admin-generic',
            56
        );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'Insufficient permissions', 'shetab-verify' ) );
        }

        $messages = array();
        if ( isset( $_POST['shetab_action'] ) && check_admin_referer( 'shetab_verify_admin' ) ) {
            $action = sanitize_text_field( wp_unslash( $_POST['shetab_action'] ) );

            if ( $action === 'add_card' ) {
                $label = sanitize_text_field( wp_unslash( $_POST['label'] ) );
                $number = preg_replace( '/\D/', '', wp_unslash( $_POST['card_number'] ) );
                $encrypted = ShetabVerify_Utils::encrypt_card_number( $number );
                $masked = ShetabVerify_Utils::mask_card_number( $number );
                ShetabVerify_DB::insert_card( array(
                    'label' => $label,
                    'encrypted_number' => $encrypted,
                    'masked_number' => $masked,
                    'max_deposits_count' => absint( $_POST['max_deposits_count'] ?? 0 ),
                    'max_total_amount' => absint( $_POST['max_total_amount'] ?? 0 ),
                    'reset_period' => sanitize_text_field( $_POST['reset_period'] ?? 'none' ),
                    'active' => isset( $_POST['active'] ) ? 1 : 0,
                ) );
                $messages[] = __( 'Card added.', 'shetab-verify' );
            }

            if ( $action === 'delete_card' && ! empty( $_POST['delete_card'] ) ) {
                ShetabVerify_DB::delete_card( absint( $_POST['delete_card'] ) );
                $messages[] = __( 'Card removed.', 'shetab-verify' );
            }

            if ( $action === 'save_secret' && isset( $_POST['api_secret'] ) ) {
                $secret = sanitize_text_field( wp_unslash( $_POST['api_secret'] ) );
                ShetabVerify_Utils::set_api_secret( $secret );
                $messages[] = __( 'API Secret saved successfully.', 'shetab-verify' );
            }

            if ( $action === 'save_support_info' ) {
                update_option( 'shetab_support_whatsapp', sanitize_text_field( $_POST['support_whatsapp'] ?? '' ) );
                update_option( 'shetab_support_telegram', sanitize_text_field( $_POST['support_telegram'] ?? '' ) );
                update_option( 'shetab_support_manager_text', sanitize_textarea_field( $_POST['support_manager_text'] ?? '' ) );
                $messages[] = __( 'Support information saved successfully.', 'shetab-verify' );
            }
        }

        $cards = ShetabVerify_DB::get_cards();
        $api_secret = ShetabVerify_Utils::get_api_secret();

        $confirm_api_url = home_url( '/wp-json/shetab-verify/v1/confirm' );
        $status_api_url = home_url( '/wp-json/shetab-verify/v1/status' );
        ?>
        <style>
            .shetab-admin-wrap {
                direction: rtl;
                font-family: 'Tahoma', sans-serif;
                margin: 20px;
                background: #fdfdfd;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            }
            .shetab-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 24px;
                transition: all 0.3s ease;
            }
            .shetab-card:hover { box-shadow: 0 8px 16px rgba(0,0,0,0.05); }
            .shetab-card h2 { margin-top: 0; color: #2d3748; border-bottom: 2px solid #edf2f7; padding-bottom: 12px; margin-bottom: 20px; font-size: 1.5rem; }
            .shetab-form-group { margin-bottom: 15px; }
            .shetab-form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #4a5568; }
            .shetab-form-group input[type="text"], .shetab-form-group input[type="password"], .shetab-form-group input[type="number"], .shetab-form-group select, .shetab-form-group textarea {
                width: 100%; max-width: 400px; padding: 10px; border: 1px solid #cbd5e0; border-radius: 6px; box-sizing: border-box;
            }
            .shetab-api-info { display: flex; align-items: center; gap: 10px; background: #f7fafc; padding: 12px; border-radius: 8px; margin-top: 10px; }
            .shetab-qr-container { display: flex; flex-direction: column; align-items: center; margin-top: 15px; }
            .shetab-qr-image { border: 1px solid #edf2f7; padding: 8px; border-radius: 8px; background: #fff; margin-bottom: 10px; }
            .shetab-copy-btn { background: #4a5568; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
            .shetab-copy-btn:hover { background: #2d3748; }
            .shetab-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .shetab-table th, .shetab-table td { text-align: right; padding: 12px; border-bottom: 1px solid #edf2f7; }
            .shetab-table th { background: #f8fafc; color: #64748b; font-weight: 600; }
            .shetab-btn { background: #3182ce; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 1rem; }
            .shetab-btn:hover { background: #2b6cb0; }
            .shetab-btn-danger { background: #e53e3e; }
            .shetab-btn-danger:hover { background: #c53030; }
            .notice { direction: rtl; }

            /* Modal Styles */
            .shetab-modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
            .shetab-modal-content { background: #fff; position: relative; margin: 5% auto; padding: 25px; border-radius: 12px; width: 60%; max-width: 800px; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
            .shetab-modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #edf2f7; padding-bottom: 15px; margin-bottom: 15px; }
            .shetab-close { color: #a0aec0; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1; }
            .shetab-close:hover { color: #4a5568; }
            .shetab-modal-title { font-size: 1.25rem; font-weight: bold; color: #2d3748; margin: 0; }
            .shetab-detail-table { width: 100%; border-collapse: collapse; }
            .shetab-detail-table th, .shetab-detail-table td { text-align: right; padding: 10px; border-bottom: 1px solid #f7fafc; }
            .shetab-detail-table th { color: #718096; font-size: 0.85rem; background: #f8fafc; }

            /* Pagination Styles */
            .shetab-pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #edf2f7; }
            .shetab-page-btn { padding: 5px 10px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; border-radius: 4px; font-size: 0.85rem; }
            .shetab-page-btn.active { background: #3182ce; color: #fff; border-color: #3182ce; }
            .shetab-page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        </style>

        <div class="shetab-admin-wrap">
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 30px;">
                <img src="<?php echo SSV_PLUGIN_URL . 'public/assets/images/logo.png'; ?>" style="height: 60px; width: auto;" alt="Shetab Logo">
                <h1 style="margin: 0; padding: 0;"><?php esc_html_e( 'ShetabVerify Management', 'shetab-verify' ); ?></h1>
            </div>

            <?php if ( get_option( 'permalink_structure' ) === '' ) : ?>
                <div class="notice notice-error" style="border-right-color: #d63638; background: #fff; padding: 15px; border-right-width: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin: 20px 0;">
                    <h3 style="color: #d63638; margin-top: 0; font-weight: bold;"><?php esc_html_e( '⚠️ Warning: Missing Permalinks Configuration', 'shetab-verify' ); ?></h3>
                    <p style="font-size: 1rem; line-height: 1.6;">
                        <?php _e( 'To ensure the automatic confirmation system (API) works correctly, you must set your WordPress <strong>"Permalinks"</strong> to anything other than "Plain".', 'shetab-verify' ); ?>
                        <br>
                        <?php printf( __( 'Please go to <a href="%s" target="_blank"><strong>Settings > Permalinks</strong></a> and set the structure (e.g., to "Post name").', 'shetab-verify' ), admin_url( 'options-permalink.php' ) ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php foreach ( $messages as $m ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $m ); ?></p></div>
            <?php endforeach; ?>

            <div class="shetab-card">
                <h2><?php esc_html_e( 'Guides & Resources', 'shetab-verify' ); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #edf2f7; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h3 style="margin-top: 0; font-size: 1.1rem; color: #2d3748;">
                                <span class="dashicons dashicons-download" style="margin-left: 8px; color: #3182ce;"></span>
                                <?php esc_html_e( 'Download Android App', 'shetab-verify' ); ?>
                            </h3>
                            <p style="font-size: 0.9rem; color: #4a5568; line-height: 1.6;">
                                <?php esc_html_e( 'To use automatic bank transfer confirmation, download and install the Shetab app from Cafe Bazaar.', 'shetab-verify' ); ?>
                            </p>
                        </div>
                        <a href="https://cafebazaar.ir/app/com.example.shetab_verification" target="_blank" class="shetab-btn" style="display: block; text-decoration: none; text-align: center; margin-top: 15px;">
                            <?php esc_html_e( 'Download from Cafe Bazaar', 'shetab-verify' ); ?>
                        </a>
                    </div>

                    <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #edf2f7; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h3 style="margin-top: 0; font-size: 1.1rem; color: #2d3748;">
                                <span class="dashicons dashicons-welcome-learn-more" style="margin-left: 8px; color: #38a169;"></span>
                                <?php esc_html_e( 'User Guide', 'shetab-verify' ); ?>
                            </h3>
                            <p style="font-size: 0.9rem; color: #4a5568; line-height: 1.6;">
                                <?php esc_html_e( 'Watch tutorials and read guides for a correct configuration.', 'shetab-verify' ); ?>
                            </p>
                        </div>
                        <a href="http://verify.webdide.ir/" target="_blank" class="shetab-btn" style="display: block; text-decoration: none; text-align: center; margin-top: 15px; background: #4a5568;">
                            <?php esc_html_e( 'View Tutorials', 'shetab-verify' ); ?>
                        </a>
                    </div>

                    <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #edf2f7; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h3 style="margin-top: 0; font-size: 1.1rem; color: #2d3748;">
                                <span class="dashicons dashicons-admin-site-alt3" style="margin-left: 8px; color: #718096;"></span>
                                <?php esc_html_e( 'Developer Website', 'shetab-verify' ); ?>
                            </h3>
                            <p style="font-size: 0.9rem; color: #4a5568; line-height: 1.6;">
                                <?php esc_html_e( 'Visit our website for support and the latest updates.', 'shetab-verify' ); ?>
                            </p>
                        </div>
                        <a href="http://verify.webdide.ir/" target="_blank" class="shetab-btn" style="display: block; text-decoration: none; text-align: center; margin-top: 15px; background: #718096;">
                            <?php esc_html_e( 'Visit Website', 'shetab-verify' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="shetab-card">
                <h2><?php esc_html_e( 'Secret API (Private Key)', 'shetab-verify' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="save_secret">
                    <div class="shetab-form-group">
                        <label><?php echo 'مقدار کلید:'; ?></label>
                        <input name="api_secret" type="text" class="regular-text" value="<?php echo esc_attr($api_secret); ?>">
                        <p class="description"><?php echo $api_secret ? 'کلید هم اکنون تنظیم شده است.' : 'هنوز کلیدی تنظیم نشده است.'; ?></p>
                    </div>
                    <?php if ( $api_secret ) : ?>
                        <div class="shetab-api-info">
                            <span><?php echo esc_html($api_secret); ?></span>
                            <button type="button" class="shetab-copy-btn" onclick="copyToClipboard('<?php echo esc_js($api_secret); ?>')"><?php echo 'کپی به کلیپبورد'; ?></button>
                        </div>
                        <div class="shetab-qr-container">
                            <div class="shetab-qr-image">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($api_secret); ?>" alt="QR Secret">
                            </div>
                            <span style="font-size:0.8rem; color:#718096;"><?php echo 'اسکن برای کپی کلید'; ?></span>
                        </div>
                    <?php endif; ?>
                    <p style="margin-top:20px;"><button type="submit" class="shetab-btn"><?php echo 'ذخیره کلید مخفی'; ?></button></p>
                </form>
            </div>

            <div class="shetab-card">
                <h2><?php esc_html_e( 'API URL Addresses', 'shetab-verify' ); ?></h2>
                <div class="shetab-form-group">
                    <label><?php esc_html_e( 'Confirm Payment (POST):', 'shetab-verify' ); ?></label>
                    <div class="shetab-api-info">
                        <code><?php echo esc_html($confirm_api_url); ?></code>
                        <button type="button" class="shetab-copy-btn" onclick="copyToClipboard('<?php echo esc_js($confirm_api_url); ?>')"><?php esc_html_e( 'Copy', 'shetab-verify' ); ?></button>
                    </div>
                    <div class="shetab-qr-container" style="display:inline-flex; margin-right:20px;">
                        <img class="shetab-qr-image" src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($confirm_api_url); ?>" width="100">
                    </div>
                </div>
                <div class="shetab-form-group">
                    <label><?php esc_html_e( 'Payment Status (GET):', 'shetab-verify' ); ?></label>
                    <div class="shetab-api-info">
                        <code><?php echo esc_html($status_api_url); ?></code>
                        <button type="button" class="shetab-copy-btn" onclick="copyToClipboard('<?php echo esc_js($status_api_url); ?>')"><?php esc_html_e( 'Copy', 'shetab-verify' ); ?></button>
                    </div>
                    <div class="shetab-qr-container" style="display:inline-flex;">
                        <img class="shetab-qr-image" src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($status_api_url); ?>" width="100">
                    </div>
                </div>
            </div>

            <div class="shetab-card">
                <h2><?php esc_html_e( 'Add New Bank Card', 'shetab-verify' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="add_card">
                    <div class="shetab-form-group">
                        <label for="label"><?php esc_html_e( 'Card Label/Name:', 'shetab-verify' ); ?></label>
                        <input name="label" id="label" type="text" required placeholder="<?php esc_attr_e( 'e.g. Primary Shop Card', 'shetab-verify' ); ?>">
                    </div>
                    <div class="shetab-form-group">
                        <label for="card_number"><?php esc_html_e( '16-Digit Card Number:', 'shetab-verify' ); ?></label>
                        <input name="card_number" id="card_number" type="text" maxlength="16" required placeholder="0000000000000000">
                    </div>
                    <div class="shetab-form-group">
                        <label for="max_deposits_count"><?php esc_html_e( 'Transaction Limit (Count):', 'shetab-verify' ); ?></label>
                        <input name="max_deposits_count" id="max_deposits_count" type="number" min="0" value="0">
                        <p class="description"><?php esc_html_e( '0 means unlimited', 'shetab-verify' ); ?></p>
                    </div>
                    <div class="shetab-form-group">
                        <label for="max_total_amount"><?php esc_html_e( 'Total Amount Limit (Toman):', 'shetab-verify' ); ?></label>
                        <input name="max_total_amount" id="max_total_amount" type="number" min="0" value="0">
                        <p class="description"><?php esc_html_e( '0 means unlimited', 'shetab-verify' ); ?></p>
                    </div>
                    <div class="shetab-form-group">
                        <label for="reset_period"><?php esc_html_e( 'Limit Reset Period:', 'shetab-verify' ); ?></label>
                        <select name="reset_period" id="reset_period">
                            <option value="none"><?php esc_html_e( 'No Reset (Forever)', 'shetab-verify' ); ?></option>
                            <option value="daily"><?php esc_html_e( 'Daily', 'shetab-verify' ); ?></option>
                            <option value="monthly"><?php esc_html_e( 'Monthly', 'shetab-verify' ); ?></option>
                        </select>
                    </div>
                    <div class="shetab-form-group">
                        <label><input name="active" type="checkbox" checked> <?php esc_html_e( 'Card is active', 'shetab-verify' ); ?></label>
                    </div>
                    <button type="submit" class="shetab-btn"><?php esc_html_e( 'Add Card', 'shetab-verify' ); ?></button>
                </form>
            </div>

            <div class="shetab-card">
                <h2><?php esc_html_e( 'Existing Cards', 'shetab-verify' ); ?></h2>
                <table class="shetab-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ID', 'shetab-verify' ); ?></th>
                            <th><?php esc_html_e( 'Label', 'shetab-verify' ); ?></th>
                            <th><?php esc_html_e( 'Full Card Number', 'shetab-verify' ); ?></th>
                            <th><?php esc_html_e( 'Limits (Count/Amount)', 'shetab-verify' ); ?></th>
                            <th><?php esc_html_e( 'Current Usage', 'shetab-verify' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'shetab-verify' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'shetab-verify' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $cards ) ) : ?>
                            <tr><td colspan="7" style="text-align:center; padding: 20px;"><?php esc_html_e( 'No cards have been configured yet.', 'shetab-verify' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $cards as $c ) : ?>
                                <?php 
                                    $full_number = ShetabVerify_Utils::decrypt_card_number($c->encrypted_number); 
                                    $usage = ShetabVerify_DB::get_card_usage( $c->id, $c->reset_period );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $c->id ); ?></td>
                                    <td><?php echo esc_html( $c->label ); ?></td>
                                    <td>
                                        <div style="direction:ltr; text-align:right;">
                                            <?php echo esc_html( $full_number ); ?>
                                            <button type="button" class="shetab-copy-btn" onclick="copyToClipboard('<?php echo esc_js($full_number); ?>')" style="padding: 2px 5px; font-size: 0.7rem;"><?php esc_html_e( 'Copy', 'shetab-verify' ); ?></button>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html( $c->max_deposits_count ?: '∞' ); ?> / <?php echo esc_html( $c->max_total_amount ? number_format_i18n( $c->max_total_amount ) : '∞' ); ?></td>
                                    <td>
                                        <div style="font-size: 0.85rem; line-height: 1.4;">
                                            <strong><?php esc_html_e( 'Txns:', 'shetab-verify' ); ?></strong> <?php echo esc_html($usage['count']); ?>
                                            <br><strong><?php esc_html_e( 'Sum:', 'shetab-verify' ); ?></strong> <?php echo number_format_i18n($usage['total']); ?> <?php esc_html_e( 'Tomans', 'shetab-verify' ); ?>
                                            <?php if ( $usage['count'] > 0 ) : ?>
                                                <div style="margin-top:5px;">
                                                    <button type="button" style="color: #3182ce; background: none; border: none; padding: 0; cursor: pointer; text-decoration: underline; font-size: 0.8rem; font-weight: 600;" 
                                                        onclick='showCardOrders(<?php echo json_encode($usage["orders"]); ?>, "<?php echo esc_js($c->label); ?>")'>
                                                        <?php printf( esc_html__( 'View Details (%d orders)', 'shetab-verify' ), $usage['count'] ); ?>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo $c->active ? '<span style="color:#38a169;">✅ ' . esc_html__( 'Active', 'shetab-verify' ) . '</span>' : '<span style="color:#e53e3e;">❌ ' . esc_html__( 'Inactive', 'shetab-verify' ) . '</span>'; ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to remove this card?', 'shetab-verify' ); ?>');">
                                            <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                                            <input type="hidden" name="shetab_action" value="delete_card">
                                            <input type="hidden" name="delete_card" value="<?php echo esc_attr( $c->id ); ?>">
                                            <button type="submit" class="shetab-copy-btn shetab-btn-danger"><?php esc_html_e( 'Delete', 'shetab-verify' ); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="shetab-card">
                <h2><?php esc_html_e( 'Support Information', 'shetab-verify' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="save_support_info">
                    <div class="shetab-form-group">
                        <label><?php esc_html_e( 'WhatsApp ID (e.g. 989123456789):', 'shetab-verify' ); ?></label>
                        <input name="support_whatsapp" type="text" value="<?php echo esc_attr(get_option('shetab_support_whatsapp')); ?>" placeholder="989...">
                    </div>
                    <div class="shetab-form-group">
                        <label><?php esc_html_e( 'Telegram ID:', 'shetab-verify' ); ?></label>
                        <input name="support_telegram" type="text" value="<?php echo esc_attr(get_option('shetab_support_telegram')); ?>" placeholder="@username">
                    </div>
                    <div class="shetab-form-group">
                        <label><?php esc_html_e( 'Manager Note for Users:', 'shetab-verify' ); ?></label>
                        <textarea name="support_manager_text" rows="4" style="max-width:600px;"><?php echo esc_textarea(get_option('shetab_support_manager_text')); ?></textarea>
                    </div>
                    <button type="submit" class="shetab-btn"><?php esc_html_e( 'Save Support Info', 'shetab-verify' ); ?></button>
                </form>
            </div>
        </div>

        <!-- Order List Modal -->
        <div id="orderModal" class="shetab-modal">
            <div class="shetab-modal-content">
                <div class="shetab-modal-header">
                    <h3 class="shetab-modal-title" id="modalTitle"><?php esc_html_e( 'Order List', 'shetab-verify' ); ?></h3>
                    <span class="shetab-close" onclick="closeModal()">&times;</span>
                </div>
                <div id="modalBody">
                    <table class="shetab-detail-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Order ID', 'shetab-verify' ); ?></th>
                                <th><?php esc_html_e( 'Amount (Toman)', 'shetab-verify' ); ?></th>
                                <th><?php esc_html_e( 'Confirmation Date', 'shetab-verify' ); ?></th>
                                <th><?php esc_html_e( 'View', 'shetab-verify' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="orderTableBody"></tbody>
                    </table>
                    <div id="modalPagination" class="shetab-pagination"></div>
                </div>
            </div>
        </div>

        <script>
        var currentModalOrders = [];
        var itemsPerPage = 10;
        var currentModalPage = 1;
        var currentCardLabel = "";

        function copyToClipboard(text) {
            var tempInput = document.createElement("input");
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand("copy");
            document.body.removeChild(tempInput);
            alert("<?php esc_js_e( 'Copied to clipboard:', 'shetab-verify' ); ?> " + text);
        }

        function showCardOrders(orders, cardLabel) {
            currentModalOrders = orders || [];
            currentCardLabel = cardLabel || "";
            currentModalPage = 1;
            
            var modal = document.getElementById("orderModal");
            modal.style.display = "block";
            document.body.style.overflow = "hidden"; // Prevent background scroll
            
            renderModalPage();
        }

        function renderModalPage() {
            var tbody = document.getElementById("orderTableBody");
            var title = document.getElementById("modalTitle");
            var pagination = document.getElementById("modalPagination");
            
            title.textContent = "<?php esc_js_e( 'Successful Transactions for', 'shetab-verify' ); ?> " + currentCardLabel;
            tbody.innerHTML = "";
            pagination.innerHTML = "";
            
            if (currentModalOrders.length === 0) {
                tbody.innerHTML = "<tr><td colspan='4' style='text-align:center;'><?php esc_js_e( 'No transactions found.', 'shetab-verify' ); ?></td></tr>";
                return;
            }

            // Pagination logic
            var totalPages = Math.ceil(currentModalOrders.length / itemsPerPage);
            var start = (currentModalPage - 1) * itemsPerPage;
            var end = start + itemsPerPage;
            var pageItems = currentModalOrders.slice(start, end);

            pageItems.forEach(function(order) {
                var row = document.createElement("tr");
                var editUrl = '<?php echo admin_url("post.php?post="); ?>' + order.id + '&action=edit';
                
                row.innerHTML = 
                    "<td>#" + order.id + "</td>" +
                    "<td>" + new Intl.NumberFormat('fa-IR').format(order.amount) + "</td>" +
                    "<td>" + (order.date || '---') + "</td>" +
                    "<td><a href='" + editUrl + "' class='button button-small' target='_blank'>📎 <?php esc_js_e( 'Details', 'shetab-verify' ); ?></a></td>";
                tbody.appendChild(row);
            });

            // Render pagination buttons if more than one page
            if (totalPages > 1) {
                for (var i = 1; i <= totalPages; i++) {
                    (function(p) {
                        var btn = document.createElement("button");
                        btn.textContent = new Intl.NumberFormat('fa-IR').format(p);
                        btn.className = "shetab-page-btn" + (p === currentModalPage ? " active" : "");
                        btn.onclick = function() {
                            currentModalPage = p;
                            renderModalPage();
                        };
                        pagination.appendChild(btn);
                    })(i);
                }
            }
        }

        function closeModal() {
            document.getElementById("orderModal").style.display = "none";
            document.body.style.overflow = "auto";
        }

        // Close on outside click
        window.onclick = function(event) {
            var modal = document.getElementById("orderModal");
            if (event.target == modal) {
                closeModal();
            }
        }
        </script>
        <?php
    }
}
