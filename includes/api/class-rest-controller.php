<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShetabVerify_REST_Controller {
    public static function register_routes() {
        register_rest_route( 'shetab-verify/v1', '/confirm', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'confirm_payment' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'shetab-verify/v1', '/status', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'get_status' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'shetab-verify/v1', '/upload-receipt', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'handle_receipt_upload' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function handle_receipt_upload( WP_REST_Request $request ) {
        $order_id = absint( $request->get_param( 'order_id' ) );
        if ( ! $order_id ) {
            return new WP_Error( 'invalid_order', 'شناسه سفارش نامعتبر است.', array( 'status' => 400 ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'invalid_order', 'سفارش یافت نشد.', array( 'status' => 404 ) );
        }

        $files = $request->get_file_params();
        if ( empty( $files['receipts'] ) ) {
            return new WP_Error( 'no_files', 'هیچ فایلی آپلود نشده است.', array( 'status' => 400 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $uploaded_ids = array();
        $receipts = $files['receipts'];

        // If single file, normalize to array
        if ( ! is_array( $receipts['name'] ) ) {
            $receipts = array(
                'name'     => array( $receipts['name'] ),
                'type'     => array( $receipts['type'] ),
                'tmp_name' => array( $receipts['tmp_name'] ),
                'error'    => array( $receipts['error'] ),
                'size'     => array( $receipts['size'] ),
            );
        }

        for ( $i = 0; $i < count( $receipts['name'] ); $i++ ) {
            $file = array(
                'name'     => $receipts['name'][$i],
                'type'     => $receipts['type'][$i],
                'tmp_name' => $receipts['tmp_name'][$i],
                'error'    => $receipts['error'][$i],
                'size'     => $receipts['size'][$i],
            );

            // Use 0 as parent to be safe with HPOS, we'll store relationship in meta
            $attach_id = media_handle_sideload( $file, 0 );
            if ( ! is_wp_error( $attach_id ) ) {
                $uploaded_ids[] = (int) $attach_id;
            }
        }

        if ( empty( $uploaded_ids ) ) {
            $msg = is_wp_error( $attach_id ) ? $attach_id->get_error_message() : 'خطا در آپلود تصاویر.';
            return new WP_Error( 'upload_failed', $msg, array( 'status' => 500 ) );
        }

        // Use CRUD methods for compatibility with HPOS
        $existing = $order->get_meta( '_shetab_receipts' );
        if ( ! is_array( $existing ) ) { $existing = array(); }
        $all_receipts = array_merge( $existing, $uploaded_ids );
        
        $order->update_meta_data( '_shetab_receipts', $all_receipts );

        // Update order status to a state that represents "Manual Verification Required"
        $order->update_status( 'on-hold', 'کاربر تصویر رسید بانکی را آپلود کرد.' );
        $order->add_order_note( 'رسیدهای آپلود شده توسط کاربر آماده بررسی مدیریت هستند.' );
        $order->save();

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'تصاویر با موفقیت آپلود شدند و در انتظار تایید مدیریت هستند.',
            'receipt_ids' => $uploaded_ids
        ) );
    }

    public static function confirm_payment( WP_REST_Request $request ) {
        // Use get_params() instead of get_json_params() to support both JSON and Form Data
        $params = $request->get_params();

        // Extract amount from payload (Flutter app sends 'amount' as string or int)
        $amount = ! empty( $params['amount'] ) ? absint( preg_replace( '/\D/', '', $params['amount'] ) ) : 0;

        if ( ! $amount ) {
            return new WP_Error( 'invalid_request', 'مبلغ تراکنش (amount) در داده‌های ارسالی یافت نشد.', array( 'status' => 400 ) );
        }

        // Authenticate using Authorization header (as used in flutter app: 'Authorization': settings.apiKey)
        $secret = $request->get_header( 'authorization' );
        
        // If Bearer is present, strip it
        if ( $secret && preg_match( '/Bearer\s+(.*)/i', $secret, $m ) ) {
            $secret = $m[1];
        }

        if ( empty( $secret ) || ! ShetabVerify_Utils::verify_api_secret( $secret ) ) {
            return new WP_Error( 'unauthorized', 'کلید امنیتی (Secret) نامعتبر است یا در هدر Authorization ارسال نشده است.', array( 'status' => 401 ) );
        }

        // Find match by unique_amount for pending transactions
        $txn = ShetabVerify_DB::get_pending_transaction_by_amount( $amount );
        if ( ! $txn ) {
            return new WP_Error( 'not_found', 'تراکنش در انتظار پرداختی با این مبلغ یافت نشد یا منقضی شده است.', array( 'status' => 404 ) );
        }

        $order_id = absint($txn->order_id);
        $remote_ref = ! empty( $params['recipeId'] ) ? sanitize_text_field( $params['recipeId'] ) : '';

        // Mark confirmed in DB
        ShetabVerify_DB::mark_transaction_confirmed( $txn->id, $remote_ref, $params );

        // Mark WooCommerce order as paid
        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->payment_complete( $remote_ref );
                $order->add_order_note( sprintf( 'تایید خودکار: مبلغ %s توسط اپلیکیشن تایید شد. کد رهگیری: %s', number_format_i18n($amount), $remote_ref ) );
            }
        }

        return rest_ensure_response( array( 
            'success' => true, 
            'message' => 'پرداخت با موفقیت تایید شد.',
            'order_id' => $order_id 
        ) );
    }

    public static function get_status( WP_REST_Request $request ) {
        $order_id = $request->get_param( 'order_id' );
        if ( empty( $order_id ) ) {
            return new WP_Error( 'missing_order_id', 'order_id is required', array( 'status' => 400 ) );
        }

        $txn = ShetabVerify_DB::get_transaction_by_order_id( absint( $order_id ) );
        if ( ! $txn ) {
            return rest_ensure_response( array( 'order_id' => (int) $order_id, 'status' => 'not_found' ) );
        }

        return rest_ensure_response( array(
            'order_id' => (int) $order_id,
            'status' => $txn->status,
            'unique_amount' => (int) $txn->unique_amount,
            'expires_at' => $txn->expires_at,
        ) );
    }
}
