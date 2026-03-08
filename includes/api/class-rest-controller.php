<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebDide_CV_REST_Controller {
    public static function register_routes() {
        register_rest_route( 'webdide-cv/v1', '/confirm', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'confirm_payment' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'webdide-cv/v1', '/status', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'get_status' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'webdide-cv/v1', '/upload-receipt', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'handle_receipt_upload' ),
            'permission_callback' => array( __CLASS__, 'check_upload_permission' ),
        ) );
    }

    /**
     * Permission check for uploading a receipt.
     * Ensure the user owns the order or has the correct order key.
     */
    public static function check_upload_permission( WP_REST_Request $request ) {
        $order_id = absint( $request->get_param( 'order_id' ) );
        $order_key = sanitize_text_field( $request->get_param( 'order_key' ) );

        if ( ! $order_id ) {
            return false;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        // 1. If user is logged in and owns the order
        if ( is_user_logged_in() && $order->get_customer_id() === get_current_user_id() ) {
            return true;
        }

        // 2. If order key matches (typical for guests)
        if ( ! empty( $order_key ) && $order->get_order_key() === $order_key ) {
            return true;
        }

        // 3. Fallback to API secret (if the app is doing the upload)
        $secret = $request->get_header( 'authorization' );
        if ( $secret && preg_match( '/Bearer\s+(.*)/i', $secret, $m ) ) {
            $secret = $m[1];
        }
        if ( ! empty( $secret ) && WebDide_CV_Utils::verify_api_secret( $secret ) ) {
            return true;
        }

        return false;
    }

    public static function handle_receipt_upload( WP_REST_Request $request ) {
        $order_id = absint( $request->get_param( 'order_id' ) );
        if ( ! $order_id ) {
            return new WP_Error( 'invalid_order', __( 'Invalid order ID.', 'webdide-card-to-card-verification' ), array( 'status' => 400 ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Order not found.', 'webdide-card-to-card-verification' ), array( 'status' => 404 ) );
        }

        $files = $request->get_file_params();
        if ( empty( $files['receipts'] ) ) {
            return new WP_Error( 'no_files', __( 'No files were uploaded.', 'webdide-card-to-card-verification' ), array( 'status' => 400 ) );
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
            $msg = is_wp_error( $attach_id ) ? $attach_id->get_error_message() : __( 'Error uploading images.', 'webdide-card-to-card-verification' );
            return new WP_Error( 'upload_failed', $msg, array( 'status' => 500 ) );
        }

        // Use CRUD methods for compatibility with HPOS
        $existing = $order->get_meta( '_shetab_receipts' );
        if ( ! is_array( $existing ) ) { $existing = array(); }
        $all_receipts = array_merge( $existing, $uploaded_ids );
        
        $order->update_meta_data( '_shetab_receipts', $all_receipts );

        // Update order status to a state that represents "Manual Verification Required"
        $status_msg = __( 'User uploaded a payment receipt image.', 'webdide-card-to-card-verification' );
        $order->update_status( 'on-hold', $status_msg );
        $order->add_order_note( __( 'Uploaded receipts are ready for admin review.', 'webdide-card-to-card-verification' ) );
        $order->save();

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Images uploaded successfully and are awaiting admin confirmation.', 'webdide-card-to-card-verification' ),
            'receipt_ids' => $uploaded_ids
        ) );
    }

    public static function confirm_payment( WP_REST_Request $request ) {
        // Use get_params() instead of get_json_params() to support both JSON and Form Data
        $params = $request->get_params();

        // Extract amount from payload (Flutter app sends 'amount' as string or int)
        $amount = ! empty( $params['amount'] ) ? absint( preg_replace( '/\D/', '', $params['amount'] ) ) : 0;

        if ( ! $amount ) {
            return new WP_Error( 'invalid_request', __( 'Transaction amount (amount) not found in the sent data.', 'webdide-card-to-card-verification' ), array( 'status' => 400 ) );
        }

        // Authenticate using Authorization header (as used in flutter app: 'Authorization': settings.apiKey)
        $secret = $request->get_header( 'authorization' );
        
        // If Bearer is present, strip it
        if ( $secret && preg_match( '/Bearer\s+(.*)/i', $secret, $m ) ) {
            $secret = $m[1];
        }

        if ( empty( $secret ) || ! WebDide_CV_Utils::verify_api_secret( $secret ) ) {
            return new WP_Error( 'unauthorized', __( 'Invalid or missing API Secret in Authorization header.', 'webdide-card-to-card-verification' ), array( 'status' => 401 ) );
        }

        // Find match by unique_amount for pending transactions
        $txn = WebDide_CV_DB::get_pending_transaction_by_amount( $amount );
        if ( ! $txn ) {
            return new WP_Error( 'not_found', __( 'No pending transaction found with this amount or it has expired.', 'webdide-card-to-card-verification' ), array( 'status' => 404 ) );
        }

        $order_id = absint($txn->order_id);
        $remote_ref = ! empty( $params['recipeId'] ) ? sanitize_text_field( $params['recipeId'] ) : '';

        // Mark confirmed in DB
        WebDide_CV_DB::mark_transaction_confirmed( $txn->id, $remote_ref, $params );

        // Mark WooCommerce order as paid
        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->payment_complete( $remote_ref );
                $order->add_order_note( sprintf( __( 'Auto-confirmation: Amount %s verified by app. Ref Code: %s', 'webdide-card-to-card-verification' ), number_format_i18n($amount), $remote_ref ) );
            }
        }

        return rest_ensure_response( array( 
            'success' => true, 
            'message' => __( 'Payment confirmed successfully.', 'webdide-card-to-card-verification' ),
            'order_id' => $order_id 
        ) );
    }

    public static function get_status( WP_REST_Request $request ) {
        $order_id = $request->get_param( 'order_id' );
        if ( empty( $order_id ) ) {
            return new WP_Error( 'missing_order_id', 'order_id is required', array( 'status' => 400 ) );
        }

        $txn = WebDide_CV_DB::get_transaction_by_order_id( absint( $order_id ) );
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








