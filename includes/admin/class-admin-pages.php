<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShetabVerify_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
    }

    public static function register_menu() {
        add_menu_page(
            __( 'ShetabVerify', 'shetab-verify' ),
            __( 'ShetabVerify', 'shetab-verify' ),
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
                $messages[] = __( 'API secret saved.', 'shetab-verify' );
            }

            if ( $action === 'run_diagnostics' ) {
                shetab_verify_run_diagnostics();
                $messages[] = __( 'Diagnostics run — check the debug output below.', 'shetab-verify' );
            }

            if ( $action === 'create_test_order' ) {
                $res = shetab_verify_create_test_order();
                if ( ! empty( $res['error'] ) ) {
                    $messages[] = __( 'Error creating test order: ', 'shetab-verify' ) . esc_html( $res['error'] );
                } else {
                    $messages[] = sprintf( __( 'Test product created (ID: %d). Visit checkout to see if the gateway appears.', 'shetab-verify' ), absint( $res['product_id'] ) );
                }
            }
        }

        $cards = ShetabVerify_DB::get_cards();
        $secret_hash = get_option( 'shetab_api_secret_hash' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ShetabVerify settings', 'shetab-verify' ); ?></h1>

            <?php foreach ( $messages as $m ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $m ); ?></p></div>
            <?php endforeach; ?>

            <h2><?php esc_html_e( 'API Secret', 'shetab-verify' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                <input type="hidden" name="shetab_action" value="save_secret">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Secret', 'shetab-verify' ); ?></th>
                        <td>
                            <input name="api_secret" type="password" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php echo $secret_hash ? esc_html__( 'A secret is already set.', 'shetab-verify' ) : esc_html__( 'No secret set yet.', 'shetab-verify' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Secret', 'shetab-verify' ) ); ?>
            </form>

            <h2><?php esc_html_e( 'Bank cards', 'shetab-verify' ); ?></h2>
            <form method="post" style="max-width:700px;">
                <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                <input type="hidden" name="shetab_action" value="add_card">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="label"><?php esc_html_e( 'Label', 'shetab-verify' ); ?></label></th>
                        <td><input name="label" id="label" type="text" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="card_number"><?php esc_html_e( 'Card number', 'shetab-verify' ); ?></label></th>
                        <td><input name="card_number" id="card_number" type="text" class="regular-text" placeholder="0000 0000 0000 0000" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_deposits_count"><?php esc_html_e( 'Max deposits (count)', 'shetab-verify' ); ?></label></th>
                        <td><input name="max_deposits_count" id="max_deposits_count" type="number" min="0" value="0"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_total_amount"><?php esc_html_e( 'Max total amount (Toman)', 'shetab-verify' ); ?></label></th>
                        <td><input name="max_total_amount" id="max_total_amount" type="number" min="0" value="0"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="reset_period"><?php esc_html_e( 'Reset period', 'shetab-verify' ); ?></label></th>
                        <td>
                            <select name="reset_period" id="reset_period">
                                <option value="none"><?php esc_html_e( 'None (lifetime)', 'shetab-verify' ); ?></option>
                                <option value="daily"><?php esc_html_e( 'Daily', 'shetab-verify' ); ?></option>
                                <option value="monthly"><?php esc_html_e( 'Monthly', 'shetab-verify' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Active', 'shetab-verify' ); ?></th>
                        <td><label><input name="active" type="checkbox" checked> <?php esc_html_e( 'Enable this card', 'shetab-verify' ); ?></label></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Add Card', 'shetab-verify' ) ); ?>
            </form>

            <h3><?php esc_html_e( 'Existing cards', 'shetab-verify' ); ?></h3>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'shetab-verify' ); ?></th>
                        <th><?php esc_html_e( 'Label', 'shetab-verify' ); ?></th>
                        <th><?php esc_html_e( 'Masked', 'shetab-verify' ); ?></th>
                        <th><?php esc_html_e( 'Limits', 'shetab-verify' ); ?></th>
                        <th><?php esc_html_e( 'Active', 'shetab-verify' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'shetab-verify' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $cards ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No cards configured.', 'shetab-verify' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $cards as $c ) : ?>
                            <tr>
                                <td><?php echo esc_html( $c->id ); ?></td>
                                <td><?php echo esc_html( $c->label ); ?></td>
                                <td><?php echo esc_html( $c->masked_number ); ?></td>
                                <td><?php echo esc_html( $c->max_deposits_count ); ?> / <?php echo esc_html( number_format_i18n( $c->max_total_amount ) ); ?></td>
                                <td><?php echo $c->active ? esc_html__( 'Yes', 'shetab-verify' ) : esc_html__( 'No', 'shetab-verify' ); ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                                        <input type="hidden" name="shetab_action" value="delete_card">
                                        <input type="hidden" name="delete_card" value="<?php echo esc_attr( $c->id ); ?>">
                                        <?php submit_button( __( 'Delete', 'shetab-verify' ), 'small', '', false ); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'REST API', 'shetab-verify' ); ?></h2>
            <p><?php esc_html_e( 'Confirm endpoint: POST /wp-json/shetab-verify/v1/confirm (requires secret).', 'shetab-verify' ); ?></p>
            <p><?php esc_html_e( 'Status endpoint: GET /wp-json/shetab-verify/v1/status?order_id=123', 'shetab-verify' ); ?></p>

            <h2><?php esc_html_e( 'Diagnostics', 'shetab-verify' ); ?></h2>
            <div style="margin-bottom:1rem;">
                <form method="post" style="display:inline-block;margin-right:1rem;">
                    <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="run_diagnostics">
                    <?php submit_button( __( 'Run gateway diagnostics', 'shetab-verify' ), 'secondary', '', false ); ?>
                </form>
                <form method="post" style="display:inline-block;">
                    <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="create_test_order">
                    <?php submit_button( __( 'Create test order & cart', 'shetab-verify' ), 'secondary', '', false ); ?>
                </form>
            </div>

            <?php $diag = get_transient( 'shetab_verify_debug_available' ); ?>
            <div style="background:#fff;padding:12px;border:1px solid #ddd;max-width:900px;">
                <strong><?php esc_html_e( 'Last diagnostics', 'shetab-verify' ); ?>:</strong>
                <?php if ( empty( $diag ) ) : ?>
                    <p><?php esc_html_e( 'No diagnostics run yet.', 'shetab-verify' ); ?></p>
                <?php else : ?>
                    <p><strong><?php esc_html_e( 'Time', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['time'] ?? '' ); ?></p>
                    <p><strong><?php esc_html_e( 'shetab_verify present (available)', 'shetab-verify' ); ?>:</strong> <?php echo ! empty( $diag['found_available'] ) ? esc_html__( 'Yes', 'shetab-verify' ) : esc_html__( 'No', 'shetab-verify' ); ?></p>
                    <p><strong><?php esc_html_e( 'Available gateways', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( implode( ', ', $diag['available'] ?? array() ) ); ?></p>

                    <p><strong><?php esc_html_e( 'shetab_verify present (registered)', 'shetab-verify' ); ?>:</strong> <?php echo ! empty( $diag['found_registered'] ) ? esc_html__( 'Yes', 'shetab-verify' ) : esc_html__( 'No', 'shetab-verify' ); ?></p>
                    <p><strong><?php esc_html_e( 'Registered gateway classes (filter)', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( implode( ', ', $diag['registered_gateways'] ?? array() ) ); ?></p>

                    <?php if ( ! empty( $diag['filter_invoked_recently'] ) ) : ?>
                        <p style="color:#080;"><strong><?php esc_html_e( 'Gateway registration filter invoked recently', 'shetab-verify' ); ?></strong></p>
                    <?php endif; ?>

                    <?php if ( ! empty( $diag['available_filter_called_recently'] ) ) : ?>
                        <p style="color:#080;"><strong><?php esc_html_e( 'woocommerce_available_payment_gateways filter ran recently', 'shetab-verify' ); ?></strong></p>
                    <?php endif; ?>
                    <?php if ( ! empty( $diag['available_filter_last_added'] ) ) : ?>
                        <p><strong><?php esc_html_e( 'Fallback filter last added gateway', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['available_filter_last_added'] ); ?></p>
                    <?php endif; ?>

                    <?php if ( ! empty( $diag['store_api_last_injected'] ) || ! empty( $diag['store_api_seen'] ) ) : ?>
                        <h4><?php esc_html_e( 'WooCommerce Blocks store API', 'shetab-verify' ); ?></h4>
                        <?php if ( ! empty( $diag['store_api_last_injected'] ) ) : ?>
                            <p><strong><?php esc_html_e( 'ShetabVerify injected on route', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['store_api_route'] ?? '' ); ?></p>
                            <p><strong><?php esc_html_e( 'Injection time', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['store_api_last_injected'] ); ?></p>
                        <?php else : ?>
                            <p><strong><?php esc_html_e( 'Store API reported the gateway already', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['store_api_seen'] ); ?></p>
                        <?php endif; ?>
                    <?php else : ?>
                        <p style="color:#c60;"><strong><?php esc_html_e( 'Blocks store API has not returned ShetabVerify yet; this may be why the new checkout never shows the option.', 'shetab-verify' ); ?></strong></p>
                    <?php endif; ?>

                    <?php if ( ! empty( $diag['is_available_debug'] ) ) : ?>
                        <h4><?php esc_html_e( 'Last is_available() check', 'shetab-verify' ); ?></h4>
                        <p><strong><?php esc_html_e( 'Time', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['is_available_debug']['time'] ?? '' ); ?></p>
                        <p><strong><?php esc_html_e( 'enabled option', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['is_available_debug']['enabled_option'] ?? '' ); ?></p>
                        <p><strong><?php esc_html_e( 'cards_count', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['is_available_debug']['cards_count'] ?? 0 ); ?></p>
                        <p><strong><?php esc_html_e( 'cart_total', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['is_available_debug']['cart_total'] ?? 'n/a' ); ?></p>
                        <p><strong><?php esc_html_e( 'is_admin', 'shetab-verify' ); ?>:</strong> <?php echo ! empty( $diag['is_available_debug']['is_admin'] ) ? esc_html__( 'Yes', 'shetab-verify' ) : esc_html__( 'No', 'shetab-verify' ); ?></p>
                        <p><strong><?php esc_html_e( 'result', 'shetab-verify' ); ?>:</strong> <?php echo ! empty( $diag['is_available_debug']['result'] ) ? esc_html__( 'Available', 'shetab-verify' ) : esc_html__( 'Not available', 'shetab-verify' ); ?></p>
                    <?php endif; ?>

                    <?php if ( ! empty( $diag['error'] ) ) : ?><p style="color:#900;"><strong><?php esc_html_e( 'Error', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $diag['error'] ); ?></p><?php endif; ?>
                <?php endif; ?>
            </div>

            <?php $test_order = get_transient( 'shetab_verify_test_order_info' ); ?>
            <div style="background:#f9f9f9;padding:12px;border:1px solid #ddd;max-width:900px;margin-top:2rem;">
                <strong><?php esc_html_e( 'Test order info', 'shetab-verify' ); ?>:</strong>
                <?php if ( empty( $test_order ) ) : ?>
                    <p><?php esc_html_e( 'No test order created yet. Click "Create test order & cart" above.', 'shetab-verify' ); ?></p>
                <?php else : ?>
                    <p><strong><?php esc_html_e( 'Product ID', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $test_order['product_id'] ?? '' ); ?></p>
                    <p><strong><?php esc_html_e( 'Cart total', 'shetab-verify' ); ?>:</strong> <?php echo esc_html( $test_order['cart_total'] ?? '' ); ?></p>
                    <p><a href="<?php echo esc_url( $test_order['checkout_url'] ?? '' ); ?>" class="button" target="_blank"><?php esc_html_e( 'Visit checkout (in new tab)', 'shetab-verify' ); ?></a></p>
                    <p style="font-size:0.9em;color:#666;"><strong><?php esc_html_e( 'Note', 'shetab-verify' ); ?>:</strong> <?php esc_html_e( 'After visiting checkout, return here and run diagnostics again to see if the gateway was available.', 'shetab-verify' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
