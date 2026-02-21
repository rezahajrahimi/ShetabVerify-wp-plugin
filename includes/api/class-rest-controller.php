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
    }

    public static function confirm_payment( WP_REST_Request $request ) {
        $params = $request->get_json_params();

        if ( empty( $params['order_id'] ) || empty( $params['amount'] ) ) {
            return new WP_Error( 'invalid_request', 'order_id and amount are required', array( 'status' => 400 ) );
        }

        // Authenticate using X-Shetab-Secret or Bearer token
        $secret = $request->get_header( 'x-shetab-secret' );
        if ( empty( $secret ) ) {
            $auth = $request->get_header( 'authorization' );
            if ( $auth && preg_match( '/Bearer\s+(.*)/i', $auth, $m ) ) {
                $secret = $m[1];
            }
        }

        if ( ! ShetabVerify_Utils::verify_api_secret( $secret ) ) {
            return new WP_Error( 'unauthorized', 'Invalid secret', array( 'status' => 401 ) );
        }

        $order_id = absint( $params['order_id'] );
        $amount   = absint( $params['amount'] );

        $txn = ShetabVerify_DB::get_pending_transaction_by_order_and_amount( $order_id, $amount );
        if ( ! $txn ) {
            return new WP_Error( 'not_found', 'Pending transaction not found or expired', array( 'status' => 404 ) );
        }

        // mark confirmed
        ShetabVerify_DB::mark_transaction_confirmed( $txn->id, isset( $params['remote_ref'] ) ? sanitize_text_field( $params['remote_ref'] ) : '', $params );

        // mark order as paid
        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->payment_complete( isset( $params['remote_ref'] ) ? sanitize_text_field( $params['remote_ref'] ) : '' );
            }
        }

        return rest_ensure_response( array( 'success' => true, 'message' => 'confirmed' ) );
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
