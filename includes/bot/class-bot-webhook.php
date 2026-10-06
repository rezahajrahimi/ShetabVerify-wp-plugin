<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Telegram / Bale webhook updates for receipt approve / reject.
 */
class WebDide_CV_Bot_Webhook {

	/**
	 * Register REST webhook routes.
	 */
	public static function register_routes() {
		register_rest_route(
			'webdide-cv/v1',
			'/bot-webhook/(?P<channel>telegram|bale)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Webhook URL for a channel.
	 *
	 * @param string $channel telegram|bale
	 * @return string
	 */
	public static function get_webhook_url( $channel ) {
		return rest_url( 'webdide-cv/v1/bot-webhook/' . $channel );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$channel = sanitize_key( $request->get_param( 'channel' ) );
		if ( ! in_array( $channel, array( 'telegram', 'bale' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$client = WebDide_CV_Bot_Client::from_options( $channel );
		if ( ! $client ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'disabled' ), 200 );
		}

		$update = $request->get_json_params();
		if ( ! is_array( $update ) ) {
			$update = $request->get_body_params();
		}
		if ( ! is_array( $update ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		if ( ! empty( $update['callback_query'] ) ) {
			self::handle_callback( $channel, $client, $update['callback_query'] );
		} elseif ( ! empty( $update['message'] ) ) {
			self::handle_message( $channel, $client, $update['message'] );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * @param string               $channel Channel.
	 * @param WebDide_CV_Bot_Client $client Client.
	 * @param array                $cq Callback query.
	 */
	private static function handle_callback( $channel, $client, array $cq ) {
		$chat_id = isset( $cq['message']['chat']['id'] ) ? $cq['message']['chat']['id'] : ( isset( $cq['from']['id'] ) ? $cq['from']['id'] : '' );
		if ( ! WebDide_CV_Bot_Client::is_allowed_chat( $channel, $chat_id ) ) {
			$client->answer_callback_query( $cq['id'], __( 'Unauthorized chat.', 'webdide-card-to-card-verification' ) );
			return;
		}

		$parsed = WebDide_CV_Bot_Notifier::parse_callback_data( isset( $cq['data'] ) ? $cq['data'] : '' );
		if ( ! $parsed ) {
			$client->answer_callback_query( $cq['id'], __( 'Invalid action.', 'webdide-card-to-card-verification' ) );
			return;
		}

		$order = wc_get_order( $parsed['order_id'] );
		if ( ! $order ) {
			$client->answer_callback_query( $cq['id'], __( 'Order not found.', 'webdide-card-to-card-verification' ) );
			return;
		}

		$message_id = isset( $cq['message']['message_id'] ) ? (int) $cq['message']['message_id'] : 0;
		$has_photo  = ! empty( $cq['message']['photo'] );

		if ( 'a' === $parsed['action'] ) {
			$result = WebDide_CV_Admin::approve_receipt( $order, 'bot_' . $channel );
			if ( is_wp_error( $result ) ) {
				$client->answer_callback_query( $cq['id'], $result->get_error_message() );
				return;
			}
			$client->answer_callback_query( $cq['id'], __( 'Deposit approved.', 'webdide-card-to-card-verification' ) );
			$new_text = sprintf(
				/* translators: %d: order ID */
				__( 'Approved. Order #%d has been marked as paid.', 'webdide-card-to-card-verification' ),
				$order->get_id()
			);
			self::update_review_message( $client, $chat_id, $message_id, $has_photo, $new_text );
			delete_transient( self::reject_state_key( $channel, $chat_id ) );
			return;
		}

		// Reject: ask for optional note.
		$client->answer_callback_query( $cq['id'], __( 'Please send a rejection note.', 'webdide-card-to-card-verification' ) );
		set_transient(
			self::reject_state_key( $channel, $chat_id ),
			array(
				'order_id'   => $order->get_id(),
				'message_id' => $message_id,
				'has_photo'  => $has_photo,
			),
			15 * MINUTE_IN_SECONDS
		);
		$client->send_message(
			$chat_id,
			sprintf(
				/* translators: %d: order ID */
				__( 'Please send a rejection note for order #%d (or send /skip to reject without a note).', 'webdide-card-to-card-verification' ),
				$order->get_id()
			)
		);
	}

	/**
	 * @param string               $channel Channel.
	 * @param WebDide_CV_Bot_Client $client Client.
	 * @param array                $message Message.
	 */
	private static function handle_message( $channel, $client, array $message ) {
		$chat_id = isset( $message['chat']['id'] ) ? $message['chat']['id'] : '';
		if ( ! WebDide_CV_Bot_Client::is_allowed_chat( $channel, $chat_id ) ) {
			return;
		}

		$state = get_transient( self::reject_state_key( $channel, $chat_id ) );
		if ( ! is_array( $state ) || empty( $state['order_id'] ) ) {
			return;
		}

		$text = isset( $message['text'] ) ? trim( (string) $message['text'] ) : '';
		if ( '' === $text ) {
			return;
		}

		$note = '';
		if ( ! in_array( strtolower( $text ), array( '/skip', 'skip' ), true ) ) {
			$note = $text;
		}

		$order = wc_get_order( absint( $state['order_id'] ) );
		if ( ! $order ) {
			delete_transient( self::reject_state_key( $channel, $chat_id ) );
			$client->send_message( $chat_id, __( 'Order not found.', 'webdide-card-to-card-verification' ) );
			return;
		}

		$result = WebDide_CV_Admin::reject_receipt( $order, $note, 'bot_' . $channel );
		delete_transient( self::reject_state_key( $channel, $chat_id ) );

		if ( is_wp_error( $result ) ) {
			$client->send_message( $chat_id, $result->get_error_message() );
			return;
		}

		$summary = sprintf(
			/* translators: %d: order ID */
			__( 'Rejected. Order #%d was marked as failed.', 'webdide-card-to-card-verification' ),
			$order->get_id()
		);
		if ( $note ) {
			$summary .= "\n" . sprintf(
				/* translators: %s: admin note */
				__( 'Note: %s', 'webdide-card-to-card-verification' ),
				$note
			);
		}

		self::update_review_message(
			$client,
			$chat_id,
			isset( $state['message_id'] ) ? (int) $state['message_id'] : 0,
			! empty( $state['has_photo'] ),
			$summary
		);
		$client->send_message( $chat_id, $summary );
	}

	/**
	 * @param string $channel Channel.
	 * @param string|int $chat_id Chat ID.
	 * @return string
	 */
	private static function reject_state_key( $channel, $chat_id ) {
		return 'wdcv_bot_reject_' . $channel . '_' . md5( (string) $chat_id );
	}

	/**
	 * @param WebDide_CV_Bot_Client $client Client.
	 * @param string|int            $chat_id Chat ID.
	 * @param int                   $message_id Message ID.
	 * @param bool                  $has_photo Whether original had photo caption.
	 * @param string                $text New text.
	 */
	private static function update_review_message( $client, $chat_id, $message_id, $has_photo, $text ) {
		if ( ! $message_id ) {
			return;
		}
		if ( $has_photo ) {
			$client->edit_message_caption( $chat_id, $message_id, $text, null );
		} else {
			$client->edit_message_text( $chat_id, $message_id, $text, null );
		}
	}
}
