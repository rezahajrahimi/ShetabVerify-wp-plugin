<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared HTTP client for Telegram and Bale Bot APIs.
 */
class WebDide_CV_Bot_Client {

	/** @var string telegram|bale */
	private $channel;

	/** @var string */
	private $token;

	/**
	 * @param string $channel telegram|bale
	 * @param string $token   Bot token.
	 */
	public function __construct( $channel, $token ) {
		$this->channel = $channel;
		$this->token   = trim( (string) $token );
	}

	/**
	 * @return string
	 */
	public function get_base_url() {
		if ( 'bale' === $this->channel ) {
			return 'https://tapi.bale.ai/bot' . $this->token . '/';
		}
		return 'https://api.telegram.org/bot' . $this->token . '/';
	}

	/**
	 * @param string $method API method name.
	 * @param array  $body   Request body (JSON).
	 * @return array|WP_Error Decoded response or error.
	 */
	public function request( $method, array $body = array() ) {
		if ( '' === $this->token ) {
			return new WP_Error( 'wdcv_bot_no_token', __( 'Bot token is missing.', 'webdide-card-to-card-verification' ) );
		}

		$url = $this->get_base_url() . ltrim( $method, '/' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['ok'] ) ) {
			$desc = is_array( $data ) && ! empty( $data['description'] ) ? $data['description'] : __( 'Bot API request failed.', 'webdide-card-to-card-verification' );
			return new WP_Error( 'wdcv_bot_api', $desc, array( 'status' => $code, 'response' => $data ) );
		}

		return isset( $data['result'] ) ? $data['result'] : $data;
	}

	/**
	 * @param string|int $chat_id Chat ID.
	 * @param string     $photo   Public URL of the photo.
	 * @param string     $caption Caption text.
	 * @param array|null $reply_markup Inline keyboard markup.
	 * @return array|WP_Error
	 */
	public function send_photo( $chat_id, $photo, $caption = '', $reply_markup = null ) {
		$body = array(
			'chat_id'    => $chat_id,
			'photo'      => $photo,
			'caption'    => $caption,
			'parse_mode' => 'HTML',
		);
		if ( null !== $reply_markup ) {
			$body['reply_markup'] = $reply_markup;
		}
		return $this->request( 'sendPhoto', $body );
	}

	/**
	 * @param string|int $chat_id Chat ID.
	 * @param string     $text    Message text.
	 * @param array|null $reply_markup Inline keyboard.
	 * @return array|WP_Error
	 */
	public function send_message( $chat_id, $text, $reply_markup = null ) {
		$body = array(
			'chat_id'    => $chat_id,
			'text'       => $text,
			'parse_mode' => 'HTML',
		);
		if ( null !== $reply_markup ) {
			$body['reply_markup'] = $reply_markup;
		}
		return $this->request( 'sendMessage', $body );
	}

	/**
	 * @param string|int $chat_id    Chat ID.
	 * @param int        $message_id Message ID.
	 * @param string     $caption    New caption.
	 * @param array|null $reply_markup New markup (empty to remove).
	 * @return array|WP_Error
	 */
	public function edit_message_caption( $chat_id, $message_id, $caption, $reply_markup = null ) {
		$body = array(
			'chat_id'    => $chat_id,
			'message_id' => (int) $message_id,
			'caption'    => $caption,
			'parse_mode' => 'HTML',
		);
		if ( null !== $reply_markup ) {
			$body['reply_markup'] = $reply_markup;
		} else {
			$body['reply_markup'] = array( 'inline_keyboard' => array() );
		}
		return $this->request( 'editMessageCaption', $body );
	}

	/**
	 * @param string|int $chat_id    Chat ID.
	 * @param int        $message_id Message ID.
	 * @param string     $text       New text.
	 * @param array|null $reply_markup Markup.
	 * @return array|WP_Error
	 */
	public function edit_message_text( $chat_id, $message_id, $text, $reply_markup = null ) {
		$body = array(
			'chat_id'    => $chat_id,
			'message_id' => (int) $message_id,
			'text'       => $text,
			'parse_mode' => 'HTML',
		);
		if ( null !== $reply_markup ) {
			$body['reply_markup'] = $reply_markup;
		} else {
			$body['reply_markup'] = array( 'inline_keyboard' => array() );
		}
		return $this->request( 'editMessageText', $body );
	}

	/**
	 * @param string $callback_query_id Callback query ID.
	 * @param string $text              Optional toast text.
	 * @return array|WP_Error
	 */
	public function answer_callback_query( $callback_query_id, $text = '' ) {
		$body = array( 'callback_query_id' => $callback_query_id );
		if ( '' !== $text ) {
			$body['text'] = $text;
		}
		return $this->request( 'answerCallbackQuery', $body );
	}

	/**
	 * @param string $url Webhook URL.
	 * @return array|WP_Error
	 */
	public function set_webhook( $url ) {
		return $this->request(
			'setWebhook',
			array(
				'url' => $url,
			)
		);
	}

	/**
	 * Build a client from stored options for a channel.
	 *
	 * @param string $channel telegram|bale
	 * @return WebDide_CV_Bot_Client|null
	 */
	public static function from_options( $channel ) {
		$enabled = get_option( 'wdcv_bot_' . $channel . '_enabled', 'no' );
		$token   = get_option( 'wdcv_bot_' . $channel . '_token', '' );
		if ( 'yes' !== $enabled || '' === $token ) {
			return null;
		}
		return new self( $channel, $token );
	}

	/**
	 * Allowed chat IDs for a channel.
	 *
	 * @param string $channel telegram|bale
	 * @return array
	 */
	public static function get_allowed_chat_ids( $channel ) {
		$raw = (string) get_option( 'wdcv_bot_' . $channel . '_chat_ids', '' );
		$ids = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ) );
		return array_values( $ids );
	}

	/**
	 * @param string     $channel telegram|bale
	 * @param string|int $chat_id Chat ID.
	 * @return bool
	 */
	public static function is_allowed_chat( $channel, $chat_id ) {
		$allowed = self::get_allowed_chat_ids( $channel );
		if ( empty( $allowed ) ) {
			return false;
		}
		$chat_id = (string) $chat_id;
		foreach ( $allowed as $id ) {
			if ( hash_equals( (string) $id, $chat_id ) ) {
				return true;
			}
		}
		return false;
	}
}
