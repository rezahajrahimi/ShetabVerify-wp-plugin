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
            'مدیریت شتاب',
            'مدیریت شتاب',
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
                $messages[] = 'Secret API با موفقیت ذخیره شد.';
            }

            if ( $action === 'save_support_info' ) {
                update_option( 'shetab_support_whatsapp', sanitize_text_field( $_POST['support_whatsapp'] ?? '' ) );
                update_option( 'shetab_support_telegram', sanitize_text_field( $_POST['support_telegram'] ?? '' ) );
                update_option( 'shetab_support_manager_text', sanitize_textarea_field( $_POST['support_manager_text'] ?? '' ) );
                $messages[] = 'اطلاعات پشتیبانی با موفقیت ذخیره شد.';
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
        </style>

        <div class="shetab-admin-wrap">
            <h1><?php echo 'مدیریت ShetabVerify'; ?></h1>

            <?php if ( get_option( 'permalink_structure' ) === '' ) : ?>
                <div class="notice notice-error" style="border-right-color: #d63638; background: #fff; padding: 15px; border-right-width: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin: 20px 0;">
                    <h3 style="color: #d63638; margin-top: 0; font-weight: bold;"><?php echo '⚠️ توجه بسیار مهم: تنظیمات وردپرس ناقص است'; ?></h3>
                    <p style="font-size: 1rem; line-height: 1.6;">
                        <?php echo 'برای کارکرد صحیح سیستم تایید خودکار (API)، باید حتماً تنظیمات <strong>"پیوندهای یکتا"</strong> وردپرس را از حالت "ساده" خارج کنید.'; ?>
                        <br>
                        <?php echo 'لطفاً به بخش <a href="' . admin_url( 'options-permalink.php' ) . '" target="_blank"><strong>تنظیمات > پیوندهای یکتا</strong></a> رفته و گزینه را روی <strong>"نام نوشته"</strong> قرار دهید.'; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php foreach ( $messages as $m ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $m ); ?></p></div>
            <?php endforeach; ?>

            <div class="shetab-card">
                <h2><?php echo 'Secret API (کلید مخفی)'; ?></h2>
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
                <h2><?php echo 'آدرس‌های API'; ?></h2>
                <div class="shetab-form-group">
                    <label><?php echo 'تایید پرداخت (Confirm):'; ?></label>
                    <div class="shetab-api-info">
                        <code><?php echo esc_html($confirm_api_url); ?></code>
                        <button type="button" class="shetab-copy-btn" onclick="copyToClipboard('<?php echo esc_js($confirm_api_url); ?>')"><?php echo 'کپی'; ?></button>
                    </div>
                    <div class="shetab-qr-container" style="display:inline-flex; margin-right:20px;">
                        <img class="shetab-qr-image" src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($confirm_api_url); ?>" width="100">
                    </div>
                </div>
                <div class="shetab-form-group">
                    <label><?php echo 'وضعیت پرداخت (Status):'; ?></label>
                    <div class="shetab-api-info">
                        <code><?php echo esc_html($status_api_url); ?></code>
                        <button type="button" class="shetab-copy-btn" onclick="copyToClipboard('<?php echo esc_js($status_api_url); ?>')"><?php echo 'کپی'; ?></button>
                    </div>
                    <div class="shetab-qr-container" style="display:inline-flex;">
                        <img class="shetab-qr-image" src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($status_api_url); ?>" width="100">
                    </div>
                </div>
            </div>

            <div class="shetab-card">
                <h2><?php echo 'افزودن کارت بانکی جدید'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="add_card">
                    <div class="shetab-form-group">
                        <label for="label"><?php echo 'نام/برچسب کارت:'; ?></label>
                        <input name="label" id="label" type="text" required placeholder="مثلا: کارت مدیریت">
                    </div>
                    <div class="shetab-form-group">
                        <label for="card_number"><?php echo 'شماره ۱۶ رقمی کارت:'; ?></label>
                        <input name="card_number" id="card_number" type="text" maxlength="16" required placeholder="0000000000000000">
                    </div>
                    <div class="shetab-form-group">
                        <label for="max_deposits_count"><?php echo 'حداکثر تعداد تراکنش:'; ?></label>
                        <input name="max_deposits_count" id="max_deposits_count" type="number" min="0" value="0">
                        <p class="description"><?php echo '۰ به معنی نامحدود'; ?></p>
                    </div>
                    <div class="shetab-form-group">
                        <label for="max_total_amount"><?php echo 'حداکثر مبلغ کل (تومان):'; ?></label>
                        <input name="max_total_amount" id="max_total_amount" type="number" min="0" value="0">
                        <p class="description"><?php echo '۰ به معنی نامحدود'; ?></p>
                    </div>
                    <div class="shetab-form-group">
                        <label for="reset_period"><?php echo 'دوره بازنشانی محدودیت:'; ?></label>
                        <select name="reset_period" id="reset_period">
                            <option value="none"><?php echo 'بدون بازنشانی (همیشگی)'; ?></option>
                            <option value="daily"><?php echo 'روزانه'; ?></option>
                            <option value="monthly"><?php echo 'ماهانه'; ?></option>
                        </select>
                    </div>
                    <div class="shetab-form-group">
                        <label><input name="active" type="checkbox" checked> <?php echo 'کارت فعال باشد'; ?></label>
                    </div>
                    <button type="submit" class="shetab-btn"><?php echo 'افزودن کارت'; ?></button>
                </form>
            </div>

            <div class="shetab-card">
                <h2><?php echo 'کارت‌های موجود (Existing Cards)'; ?></h2>
                <table class="shetab-table">
                    <thead>
                        <tr>
                            <th><?php echo 'شناسه'; ?></th>
                            <th><?php echo 'برچسب'; ?></th>
                            <th><?php echo 'شماره کارت کامل'; ?></th>
                            <th><?php echo 'محدودیت (تعداد / مبلع)'; ?></th>
                            <th><?php echo 'وضعیت'; ?></th>
                            <th><?php echo 'عملیات'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $cards ) ) : ?>
                            <tr><td colspan="6" style="text-align:center; padding: 20px;"><?php echo 'هیچ کارتی تنظیم نشده است.'; ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $cards as $c ) : ?>
                                <?php $full_number = ShetabVerify_Utils::decrypt_card_number($c->encrypted_number); ?>
                                <tr>
                                    <td><?php echo esc_html( $c->id ); ?></td>
                                    <td><?php echo esc_html( $c->label ); ?></td>
                                    <td>
                                        <div style="direction:ltr; text-align:right;">
                                            <?php echo esc_html( $full_number ); ?>
                                            <button type="button" class="shetab-copy-btn" onclick="copyToClipboard('<?php echo esc_js($full_number); ?>')" style="padding: 2px 5px; font-size: 0.7rem;">کپی</button>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html( $c->max_deposits_count ); ?> / <?php echo esc_html( number_format_i18n( $c->max_total_amount ) ); ?></td>
                                    <td><?php echo $c->active ? '<span style="color:#38a169;">✅ فعال</span>' : '<span style="color:#e53e3e;">❌ غیرفعال</span>'; ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('آیا از حذف این کارت مطمئن هستید؟');">
                                            <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                                            <input type="hidden" name="shetab_action" value="delete_card">
                                            <input type="hidden" name="delete_card" value="<?php echo esc_attr( $c->id ); ?>">
                                            <button type="submit" class="shetab-copy-btn shetab-btn-danger"><?php echo 'حذف'; ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="shetab-card">
                <h2><?php echo 'اطلاعات پشتیبانی'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'shetab_verify_admin' ); ?>
                    <input type="hidden" name="shetab_action" value="save_support_info">
                    <div class="shetab-form-group">
                        <label><?php echo 'آیدی واتس‌اپ (مثال: 989123456789):'; ?></label>
                        <input name="support_whatsapp" type="text" value="<?php echo esc_attr(get_option('shetab_support_whatsapp')); ?>" placeholder="989...">
                    </div>
                    <div class="shetab-form-group">
                        <label><?php echo 'آیدی تلگرام:'; ?></label>
                        <input name="support_telegram" type="text" value="<?php echo esc_attr(get_option('shetab_support_telegram')); ?>" placeholder="@username">
                    </div>
                    <div class="shetab-form-group">
                        <label><?php echo 'متن مدیر جهت نمایش به کاربر:'; ?></label>
                        <textarea name="support_manager_text" rows="4" style="max-width:600px;"><?php echo esc_textarea(get_option('shetab_support_manager_text')); ?></textarea>
                    </div>
                    <button type="submit" class="shetab-btn"><?php echo 'ذخیره اطلاعات پشتیبانی'; ?></button>
                </form>
            </div>
        </div>

        <script>
        function copyToClipboard(text) {
            var tempInput = document.createElement("input");
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand("copy");
            document.body.removeChild(tempInput);
            alert("در کلیپبورد کپی شد: " + text);
        }
        </script>
        <?php
    }
}
