<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends receipt review notifications to Telegram / Bale.
 */
class WebDide_CV_Bot_Notifier {

	/**
	 * HMAC secret for callback_data.
	 *
	 * @return string
	 */
	public static function get_signing_key() {
		$key = get_option( 'wdcv_bot_signing_key', '' );
		if ( '' === $key ) {
			$key = wp_generate_password( 32, false, false );
			update_option( 'wdcv_bot_signing_key', $key, false );
		}
		return $key;
	}

	/**
	 * Build signed callback_data (max ~64 bytes for Telegram).
	 *
	 * @param string $action a|r
	 * @param int    $order_id Order ID.
	 * @return string
	 */
	public static function build_callback_data( $action, $order_id ) {
		$order_id = absint( $order_id );
		$sig      = substr( hash_hmac( 'sha256', $action . ':' . $order_id, self::get_signing_key() ), 0, 8 );
		return $action . ':' . $order_id . ':' . $sig;
	}

	/**
	 * @param string $data Callback data.
	 * @return array|false { action, order_id } or false.
	 */
	public static function parse_callback_data( $data ) {
		$parts = explode( ':', (string) $data );
		if ( count( $parts ) !== 3 ) {
			return false;
		}
		list( $action, $order_id, $sig ) = $parts;
		if ( ! in_array( $action, array( 'a', 'r' ), true ) ) {
			return false;
		}
		$order_id = absint( $order_id );
		$expect   = substr( hash_hmac( 'sha256', $action . ':' . $order_id, self::get_signing_key() ), 0, 8 );
		if ( ! hash_equals( $expect, $sig ) ) {
			return false;
		}
		return array(
			'action'   => $action,
			'order_id' => $order_id,
		);
	}

	/**
	 * Formal caption for receipt review.
	 *
	 * @param WC_Order $order Order.
	 * @return string HTML caption.
	 */
	public static function build_caption( $order ) {
		$order_id = $order->get_id();
		$amount   = $order->get_meta( 'shetab_unique_amount' );
		if ( ! $amount ) {
			$txn = WebDide_CV_DB::get_transaction_by_order_id( $order_id );
			$amount = $txn ? $txn->unique_amount : $order->get_total();
		}
		$amount_fmt = number_format_i18n( (float) $amount );
		$currency   = __( 'Tomans', 'webdide-card-to-card-verification' );

		$lines = array(
			'<b>' . esc_html__( 'Payment receipt submitted', 'webdide-card-to-card-verification' ) . '</b>',
			'',
			esc_html(
				sprintf(
					/* translators: 1: order ID, 2: formatted amount, 3: currency label */
					__( 'Order #%1$s — Amount: %2$s %3$s', 'webdide-card-to-card-verification' ),
					(string) $order_id,
					$amount_fmt,
					$currency
				)
			),
			'',
			esc_html__( 'The customer uploaded the receipt below for review.', 'webdide-card-to-card-verification' ),
			esc_html__( 'Do you approve this deposit?', 'webdide-card-to-card-verification' ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * @param int $order_id Order ID.
	 * @return array Inline keyboard markup.
	 */
	public static function build_keyboard( $order_id ) {
		return array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => __( 'Approve', 'webdide-card-to-card-verification' ),
						'callback_data' => self::build_callback_data( 'a', $order_id ),
					),
					array(
						'text'          => __( 'Reject', 'webdide-card-to-card-verification' ),
						'callback_data' => self::build_callback_data( 'r', $order_id ),
					),
				),
			),
		);
	}

	/**
	 * Notify all enabled bot channels about a new receipt upload.
	 *
	 * @param WC_Order $order        Order.
	 * @param array    $uploaded_ids Attachment IDs just uploaded.
	 */
	public static function notify_receipt_uploaded( $order, array $uploaded_ids ) {
		if ( ! $order instanceof WC_Order || empty( $uploaded_ids ) ) {
			return;
		}

		$channels = array( 'telegram', 'bale' );
		$meta     = $order->get_meta( '_shetab_bot_messages' );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$caption  = self::build_caption( $order );
		$keyboard = self::build_keyboard( $order->get_id() );
		$photo_url = wp_get_attachment_url( $uploaded_ids[0] );

		foreach ( $channels as $channel ) {
			$client = WebDide_CV_Bot_Client::from_options( $channel );
			if ( ! $client ) {
				continue;
			}

			$chat_ids = WebDide_CV_Bot_Client::get_allowed_chat_ids( $channel );
			if ( empty( $chat_ids ) ) {
				continue;
			}

			foreach ( $chat_ids as $chat_id ) {
				$used_photo = false;
				$result     = null;
				if ( $photo_url ) {
					$result = $client->send_photo( $chat_id, $photo_url, $caption, $keyboard );
					if ( ! is_wp_error( $result ) && $result ) {
						$used_photo = true;
					}
				}
				if ( ! $used_photo ) {
					$result = $client->send_message( $chat_id, $caption, $keyboard );
				}

				if ( is_wp_error( $result ) ) {
					if ( function_exists( 'wc_get_logger' ) ) {
						wc_get_logger()->warning(
							sprintf( 'Bot notify failed (%s/%s): %s', $channel, $chat_id, $result->get_error_message() ),
							array( 'source' => 'webdide-card-to-card-verification' )
						);
					}
					continue;
				}

				$message_id = isset( $result['message_id'] ) ? (int) $result['message_id'] : 0;
				if ( $message_id ) {
					$meta[] = array(
						'channel'    => $channel,
						'chat_id'    => (string) $chat_id,
						'message_id' => $message_id,
						'has_photo'  => $used_photo,
					);
				}

				// Send extra receipt images without keyboard.
				if ( count( $uploaded_ids ) > 1 ) {
					for ( $i = 1; $i < count( $uploaded_ids ); $i++ ) {
						$extra = wp_get_attachment_url( $uploaded_ids[ $i ] );
						if ( $extra ) {
							$client->send_photo( $chat_id, $extra, sprintf(
								/* translators: %d: order ID */
								__( 'Additional receipt for order #%d', 'webdide-card-to-card-verification' ),
								$order->get_id()
							) );
						}
					}
				}
			}
		}

		if ( ! empty( $meta ) ) {
			$order->update_meta_data( '_shetab_bot_messages', $meta );
			$order->save();
		}
	}
}
