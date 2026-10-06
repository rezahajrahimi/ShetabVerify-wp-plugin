<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

class WebDide_CV_Gateway extends WC_Payment_Gateway {

	/** Default awaiting-payment message (payment page). */
	const DEFAULT_AWAITING_MESSAGE = 'سفارش شما ثبت شده و در انتظار پرداخت می‌باشد. لطفاً جهت نهایی شدن سفارش، مبلغ مورد نظر را طبق دستورالعمل زیر واریز نمایید.';

	public function __construct() {
		$this->id                 = 'wdcv';
		$this->has_fields         = false;
		$this->method_title       = __( 'Card-to-Card (auto verification)', 'webdide-card-to-card-verification' );
		$this->method_description = __( 'Card-to-Card payments via Shetab with automatic transaction confirmation.', 'webdide-card-to-card-verification' );
		$this->icon               = WDCV_PLUGIN_URL . 'public/assets/images/logo.png';

		$this->supports = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Card-to-Card (auto verification)', 'webdide-card-to-card-verification' ) );
		$this->description = $this->get_option( 'description', __( 'Card-to-Card payments via Shetab with automatic transaction confirmation.', 'webdide-card-to-card-verification' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'change_thankyou_text' ), 10, 2 );
	}

	public function change_thankyou_text( $text, $order ) {
		if ( $order && $order->get_payment_method() === $this->id ) {
			if ( $order->get_status() === 'on-hold' ) {
				return $this->get_awaiting_message();
			}

			if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
				return $this->get_option(
					'thankyou_success_message',
					__( 'Your payment was confirmed successfully. Your order is being processed.', 'webdide-card-to-card-verification' )
				);
			}
		}
		return $text;
	}

	/**
	 * Awaiting-payment copy shown on the intermediate pay page.
	 *
	 * @return string
	 */
	public function get_awaiting_message() {
		return $this->get_option( 'thankyou_awaiting_message', self::DEFAULT_AWAITING_MESSAGE );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                   => array(
				'title'   => __( 'Enable/Disable', 'webdide-card-to-card-verification' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Shetab Card-to-Card gateway', 'webdide-card-to-card-verification' ),
				'default' => 'yes',
			),
			'title'                     => array(
				'title'   => __( 'Title', 'webdide-card-to-card-verification' ),
				'type'    => 'text',
				'default' => __( 'Card-to-Card (auto verification)', 'webdide-card-to-card-verification' ),
			),
			'description'               => array(
				'title'       => __( 'Description', 'webdide-card-to-card-verification' ),
				'type'        => 'textarea',
				'default'     => __( 'Card-to-Card payments via Shetab with automatic transaction confirmation.', 'webdide-card-to-card-verification' ),
				'description' => __( 'Payment method description shown on checkout.', 'webdide-card-to-card-verification' ),
			),
			'thankyou_awaiting_message' => array(
				'title'       => __( 'Awaiting payment message', 'webdide-card-to-card-verification' ),
				'type'        => 'textarea',
				'default'     => self::DEFAULT_AWAITING_MESSAGE,
				'description' => __( 'Shown on the payment page while the transfer is pending confirmation.', 'webdide-card-to-card-verification' ),
			),
			'thankyou_success_message'  => array(
				'title'       => __( 'Payment success message', 'webdide-card-to-card-verification' ),
				'type'        => 'textarea',
				'default'     => __( 'Your payment was confirmed successfully. Your order is being processed.', 'webdide-card-to-card-verification' ),
				'description' => __( 'Shown after the payment has been confirmed.', 'webdide-card-to-card-verification' ),
			),
			'show_card_number'          => array(
				'title'   => __( 'Show card number', 'webdide-card-to-card-verification' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show destination card number on the payment page', 'webdide-card-to-card-verification' ),
				'default' => 'yes',
			),
			'show_account_number'       => array(
				'title'   => __( 'Show account number', 'webdide-card-to-card-verification' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show destination account number on the payment page', 'webdide-card-to-card-verification' ),
				'default' => 'no',
			),
			'show_sheba'                => array(
				'title'   => __( 'Show Sheba (IBAN)', 'webdide-card-to-card-verification' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show destination Sheba on the payment page', 'webdide-card-to-card-verification' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Which destination fields to show on the pay page.
	 *
	 * @return array{card:bool,account:bool,sheba:bool}
	 */
	public static function get_pay_display_flags() {
		$settings = class_exists( 'WebDide_CV_Admin' )
			? WebDide_CV_Admin::get_gateway_settings()
			: array();

		$flags = array(
			'card'    => ( ( $settings['show_card_number'] ?? 'yes' ) === 'yes' ),
			'account' => ( ( $settings['show_account_number'] ?? 'no' ) === 'yes' ),
			'sheba'   => ( ( $settings['show_sheba'] ?? 'no' ) === 'yes' ),
		);

		if ( ! $flags['card'] && ! $flags['account'] && ! $flags['sheba'] ) {
			$flags['card'] = true;
		}

		return $flags;
	}

	/**
	 * Render one destination detail block (card / account / sheba).
	 *
	 * @param string $caption Label above the value.
	 * @param string $value   Display value.
	 * @param string $copy    Clipboard / QR payload.
	 * @param string $copy_id Button element id.
	 * @param string $qr_alt  Image alt text.
	 * @param string $extra_html Optional HTML under the value (e.g. bank label).
	 */
	private static function render_pay_detail_block( $caption, $value, $copy, $copy_id, $qr_alt, $extra_html = '' ) {
		$copy = (string) $copy;
		$qr   = $copy ? WebDide_CV_Utils::get_qr_code_data_uri( $copy, '160x160' ) : '';
		?>
		<div class="wdcv-card-box">
			<span class="wdcv-card-caption"><?php echo esc_html( $caption ); ?></span>
			<strong class="wdcv-card-number" dir="ltr"><?php echo esc_html( $value ); ?></strong>
			<?php
			if ( $extra_html ) {
				echo $extra_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by caller.
			}
			?>
			<?php if ( $qr ) : ?>
				<div class="wdcv-card-qr">
					<img
						src="<?php echo esc_attr( $qr ); ?>"
						width="160"
						height="160"
						alt="<?php echo esc_attr( $qr_alt ); ?>"
						loading="lazy"
					>
					<span class="wdcv-card-qr-hint"><?php esc_html_e( 'Scan with your bank app', 'webdide-card-to-card-verification' ); ?></span>
				</div>
			<?php endif; ?>
			<button
				type="button"
				class="shetab-copy-card-btn shetab-copy-btn"
				id="<?php echo esc_attr( $copy_id ); ?>"
				data-copy="<?php echo esc_attr( $copy ); ?>"
			>
				<?php esc_html_e( 'Copy', 'webdide-card-to-card-verification' ); ?>
			</button>
		</div>
		<?php
	}

	public function is_available() {
		if ( 'yes' !== $this->get_option( 'enabled', 'yes' ) ) {
			return false;
		}

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return true;
		}

		if ( ! is_admin() && function_exists( 'WC' ) && WC()->cart ) {
			if ( floatval( WC()->cart->total ) <= 0 ) {
				return false;
			}
		}

		$cards = array();
		if ( method_exists( 'WebDide_CV_DB', 'get_active_cards' ) ) {
			$cards = WebDide_CV_DB::get_active_cards();
			if ( empty( $cards ) ) {
				set_transient(
					'wdcv_is_available_debug',
					array(
						'time'           => current_time( 'mysql' ),
						'enabled_option' => $this->get_option( 'enabled', 'no' ),
						'cards_count'    => 0,
						'cart_total'     => ( function_exists( 'WC' ) && WC()->cart ) ? floatval( WC()->cart->total ) : null,
						'is_admin'       => is_admin(),
						'result'         => false,
					),
					60
				);
				return false;
			}
		}

		set_transient(
			'wdcv_is_available_debug',
			array(
				'time'           => current_time( 'mysql' ),
				'enabled_option' => $this->get_option( 'enabled', 'no' ),
				'cards_count'    => is_array( $cards ) ? count( $cards ) : 0,
				'cart_total'     => ( function_exists( 'WC' ) && WC()->cart ) ? floatval( WC()->cart->total ) : null,
				'is_admin'       => is_admin(),
				'result'         => true,
			),
			60
		);

		return true;
	}

	public function payment_fields() {
		$description = $this->get_description();
		if ( $description ) {
			echo wp_kses_post( wpautop( $description ) );
		}
	}

	/**
	 * Intermediate payment page URL (bank-like step before thank-you).
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public static function get_pay_url( $order ) {
		return add_query_arg(
			array(
				'wdcv_pay'  => '1',
				'order_id'  => $order->get_id(),
				'key'       => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Whether the order still needs card-to-card payment.
	 *
	 * @param WC_Order $order Order object.
	 * @param object|null $txn Transaction row.
	 * @return bool
	 */
	public static function order_needs_payment( $order, $txn = null ) {
		if ( ! $order || $order->get_payment_method() !== 'wdcv' ) {
			return false;
		}

		if ( in_array( $order->get_status(), array( 'processing', 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) {
			return false;
		}

		if ( null === $txn ) {
			$txn = WebDide_CV_DB::get_transaction_by_order_id( $order->get_id() );
		}

		if ( ! $txn ) {
			return 'on-hold' === $order->get_status() || $order->has_status( 'pending' );
		}

		return 'pending' === $txn->status;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Invalid order.', 'webdide-card-to-card-verification' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$original_amount = (int) round( $order->get_total() );
		$unique_amount   = WebDide_CV_Utils::generate_unique_amount( $original_amount );
		if ( ! $unique_amount ) {
			wc_add_notice( __( 'Unable to generate a unique payment amount. Please try again.', 'webdide-card-to-card-verification' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$cards = WebDide_CV_DB::get_active_cards();
		if ( empty( $cards ) ) {
			wc_add_notice( __( 'No destination bank cards configured. Please contact the store owner.', 'webdide-card-to-card-verification' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$card = null;
		foreach ( $cards as $c ) {
			$usage    = WebDide_CV_DB::get_card_usage( $c->id, $c->reset_period );
			$count_ok = ( 0 == $c->max_deposits_count || $usage['count'] < $c->max_deposits_count );
			$sum_ok   = ( 0 == $c->max_total_amount || $usage['total'] + $unique_amount <= $c->max_total_amount );
			if ( $count_ok && $sum_ok && $c->active ) {
				$card = $c;
				break;
			}
		}

		if ( ! $card ) {
			wc_add_notice( __( 'No available bank cards are currently accepting payments. Please contact the store owner.', 'webdide-card-to-card-verification' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 10 * MINUTE_IN_SECONDS );

		$txn_id = WebDide_CV_DB::create_transaction(
			array(
				'order_id'        => $order_id,
				'card_id'         => $card->id,
				'original_amount' => $original_amount,
				'unique_amount'   => $unique_amount,
				'status'          => 'pending',
				'expires_at'      => $expires_at,
			)
		);

		$order->update_meta_data( 'shetab_transaction_id', $txn_id );
		$order->update_meta_data( 'shetab_unique_amount', $unique_amount );
		$order->save();

		$order->update_status( 'on-hold', __( 'Awaiting bank transfer (WebDide_CV).', 'webdide-card-to-card-verification' ) );

		return array(
			'result'   => 'success',
			'redirect' => self::get_pay_url( $order ),
		);
	}

	/**
	 * Render standalone pay page when ?wdcv_pay=1 is present.
	 */
	public static function handle_pay_page() {
		if ( empty( $_GET['wdcv_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) $key ) ) {
			wp_die( esc_html__( 'Invalid or expired payment link.', 'webdide-card-to-card-verification' ), esc_html__( 'Payment', 'webdide-card-to-card-verification' ), array( 'response' => 403 ) );
		}

		if ( $order->get_payment_method() !== 'wdcv' ) {
			wp_die( esc_html__( 'This order does not use card-to-card payment.', 'webdide-card-to-card-verification' ), esc_html__( 'Payment', 'webdide-card-to-card-verification' ), array( 'response' => 400 ) );
		}

		$txn = WebDide_CV_DB::get_transaction_by_order_id( $order_id );
		if ( ! self::order_needs_payment( $order, $txn ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		status_header( 200 );
		nocache_headers();

		// Enqueue before template calls wp_head().
		self::prepare_pay_page( $order );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- template expects $order.
		include WDCV_PLUGIN_DIR . 'templates/pay.php';
		exit;
	}

	/**
	 * Load transaction context and enqueue assets for the pay page.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function prepare_pay_page( $order ) {
		$txn = WebDide_CV_DB::get_transaction_by_order_id( $order->get_id() );
		if ( ! $txn ) {
			return;
		}

		$expires_at_ts = strtotime( $txn->expires_at );
		$remaining     = max( 0, $expires_at_ts - current_time( 'timestamp' ) );
		$should_poll   = ( 'pending' === $txn->status );

		self::enqueue_front_assets( $order, $txn, $remaining, $should_poll );
	}

	/**
	 * Send pending wdcv orders away from thank-you back to the pay page.
	 */
	public static function redirect_pending_thankyou() {
		if ( is_admin() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		global $wp;
		$order_id = absint( $wp->query_vars['order-received'] ?? 0 );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== 'wdcv' ) {
			return;
		}

		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $key && ! hash_equals( $order->get_order_key(), (string) $key ) ) {
			return;
		}

		if ( self::order_needs_payment( $order ) ) {
			wp_safe_redirect( self::get_pay_url( $order ) );
			exit;
		}
	}

	/**
	 * Enqueue pay/thank-you front-end assets and localize script vars.
	 *
	 * @param WC_Order $order Order.
	 * @param object   $txn Transaction row.
	 * @param int      $remaining Seconds remaining.
	 * @param bool     $should_poll Whether status polling is active.
	 */
	public static function enqueue_front_assets( $order, $txn, $remaining, $should_poll ) {
		wp_enqueue_style(
			'wdcv-checkout',
			WDCV_PLUGIN_URL . 'public/css/checkout.css',
			array(),
			WDCV_VERSION
		);

		wp_enqueue_script(
			'wdcv-checkout',
			WDCV_PLUGIN_URL . 'public/js/checkout.js',
			array(),
			WDCV_VERSION,
			true
		);

		wp_localize_script(
			'wdcv-checkout',
			'wdcvCheckoutVars',
			array(
				'remaining'       => (int) $remaining,
				'txnId'           => (int) $txn->id,
				'orderId'         => (int) $order->get_id(),
				'orderKey'        => $order->get_order_key(),
				'statusUrl'       => esc_url_raw( get_rest_url( null, 'webdide-cv/v1/status' ) ),
				'uploadUrl'       => esc_url_raw( get_rest_url( null, 'webdide-cv/v1/upload-receipt' ) ),
				'returnUrl'       => esc_url_raw( $order->get_checkout_order_received_url() ),
				'shouldPoll'      => (bool) $should_poll,
				'expiredText'     => __( 'Your time has expired.', 'webdide-card-to-card-verification' ),
				'timerText'       => __( 'Time remaining to transfer:', 'webdide-card-to-card-verification' ),
				'uploadingText'   => __( 'Uploading…', 'webdide-card-to-card-verification' ),
				'sendText'        => __( 'Submit receipt', 'webdide-card-to-card-verification' ),
				'errorText'       => __( 'Error:', 'webdide-card-to-card-verification' ),
				'uploadErrorText' => __( 'There was a problem uploading the file.', 'webdide-card-to-card-verification' ),
				'systemErrorText' => __( 'A system error occurred during upload.', 'webdide-card-to-card-verification' ),
				'copiedText'      => __( 'Copied', 'webdide-card-to-card-verification' ),
				'copyFailedText'  => __( 'Copy failed. Please copy manually:', 'webdide-card-to-card-verification' ),
			)
		);
	}

	/**
	 * Content for the intermediate payment page.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_pay_content( $order ) {
		$order_id = $order->get_id();
		$txn      = WebDide_CV_DB::get_transaction_by_order_id( $order_id );
		$receipts = $order->get_meta( '_shetab_receipts' );

		if ( ! $txn ) {
			echo '<div class="shetab-instructions"><p>' . esc_html__( 'Payment details are not available for this order.', 'webdide-card-to-card-verification' ) . '</p></div>';
			return;
		}

		$cards = WebDide_CV_DB::get_cards();
		$card  = null;
		foreach ( $cards as $c ) {
			if ( (int) $c->id === (int) $txn->card_id ) {
				$card = $c;
				break;
			}
		}

		$expires_at_ts = strtotime( $txn->expires_at );
		$now_ts        = current_time( 'timestamp' );
		$remaining     = max( 0, $expires_at_ts - $now_ts );

		$whatsapp     = get_option( 'wdcv_support_whatsapp' );
		$telegram     = get_option( 'wdcv_support_telegram' );
		$manager_text = get_option( 'wdcv_support_manager_text' );

		$full_card_number = $card ? WebDide_CV_Utils::decrypt_card_number( $card->encrypted_number ) : '';
		$awaiting_message = self::DEFAULT_AWAITING_MESSAGE;
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways['wdcv'] ) && $gateways['wdcv'] instanceof self ) {
				$awaiting_message = $gateways['wdcv']->get_awaiting_message();
			}
		} else {
			$stored = get_option( 'woocommerce_wdcv_settings', array() );
			if ( ! empty( $stored['thankyou_awaiting_message'] ) ) {
				$awaiting_message = $stored['thankyou_awaiting_message'];
			}
		}

		$show_transfer = ( 'pending' === $txn->status && empty( $receipts ) );
		?>
		<div class="shetab-instructions wdcv-pay-panel">
			<div class="wdcv-awaiting-banner">
				<?php echo esc_html( $awaiting_message ); ?>
			</div>

			<?php if ( ! empty( $receipts ) ) : ?>
				<div class="wdcv-receipt-pending">
					<strong><?php esc_html_e( 'Your payment slip was received and is awaiting admin approval.', 'webdide-card-to-card-verification' ); ?></strong>
					<p><?php esc_html_e( 'After staff approval, your order will move to the shipping stage.', 'webdide-card-to-card-verification' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $show_transfer ) : ?>
				<?php
				$amount_toman = (int) $txn->unique_amount;
				$amount_rial  = $amount_toman * 10;
				?>
				<p class="shetab-transfer-hint"><?php esc_html_e( 'Please transfer the exact amount below using the destination details:', 'webdide-card-to-card-verification' ); ?></p>

				<button
					type="button"
					class="shetab-amount"
					id="shetab-copy-amount"
					data-copy="<?php echo esc_attr( (string) $amount_rial ); ?>"
					title="<?php esc_attr_e( 'Click to copy amount', 'webdide-card-to-card-verification' ); ?>"
				>
					<span class="shetab-amount-rial">
						<?php
						printf(
							/* translators: %s: amount in Rials */
							esc_html__( 'Amount: %s Rials', 'webdide-card-to-card-verification' ),
							esc_html( number_format_i18n( $amount_rial ) )
						);
						?>
					</span>
					<span class="shetab-amount-toman">
						<?php
						printf(
							/* translators: %s: amount in Tomans */
							esc_html__( 'Equivalent: %s Tomans', 'webdide-card-to-card-verification' ),
							esc_html( number_format_i18n( $amount_toman ) )
						);
						?>
					</span>
					<span class="shetab-amount-hint"><?php esc_html_e( 'Click to copy', 'webdide-card-to-card-verification' ); ?></span>
				</button>

				<?php if ( $card ) : ?>
					<?php
					$flags       = self::get_pay_display_flags();
					$card_digits = preg_replace( '/\D+/', '', (string) $full_card_number );
					$account_num = ! empty( $card->encrypted_account ) ? WebDide_CV_Utils::decrypt_card_number( $card->encrypted_account ) : '';
					$sheba_num   = ! empty( $card->encrypted_sheba ) ? WebDide_CV_Utils::decrypt_card_number( $card->encrypted_sheba ) : '';
					// Holder / bank name under every shown destination block.
					$label_html  = $card->label
						? '<p class="wdcv-card-label">' . esc_html( $card->label ) . '</p>'
						: '';
					$shown_any   = false;

					if ( $flags['card'] && $card_digits ) {
						self::render_pay_detail_block(
							__( 'Card number:', 'webdide-card-to-card-verification' ),
							$full_card_number,
							$card_digits,
							'shetab-copy-card',
							__( 'Card number QR code', 'webdide-card-to-card-verification' ),
							$label_html
						);
						$shown_any = true;
					}

					if ( $flags['account'] && $account_num ) {
						self::render_pay_detail_block(
							__( 'Account number:', 'webdide-card-to-card-verification' ),
							$account_num,
							$account_num,
							'shetab-copy-account',
							__( 'Account number QR code', 'webdide-card-to-card-verification' ),
							$label_html
						);
						$shown_any = true;
					}

					if ( $flags['sheba'] && $sheba_num ) {
						self::render_pay_detail_block(
							__( 'Sheba (IBAN):', 'webdide-card-to-card-verification' ),
							$sheba_num,
							$sheba_num,
							'shetab-copy-sheba',
							__( 'Sheba QR code', 'webdide-card-to-card-verification' ),
							$label_html
						);
						$shown_any = true;
					}

					// Fallback: first available destination so the pay page is never empty.
					if ( ! $shown_any ) {
						if ( $card_digits ) {
							self::render_pay_detail_block(
								__( 'Card number:', 'webdide-card-to-card-verification' ),
								$full_card_number,
								$card_digits,
								'shetab-copy-card',
								__( 'Card number QR code', 'webdide-card-to-card-verification' ),
								$label_html
							);
						} elseif ( $account_num ) {
							self::render_pay_detail_block(
								__( 'Account number:', 'webdide-card-to-card-verification' ),
								$account_num,
								$account_num,
								'shetab-copy-account',
								__( 'Account number QR code', 'webdide-card-to-card-verification' ),
								$label_html
							);
						} elseif ( $sheba_num ) {
							self::render_pay_detail_block(
								__( 'Sheba (IBAN):', 'webdide-card-to-card-verification' ),
								$sheba_num,
								$sheba_num,
								'shetab-copy-sheba',
								__( 'Sheba QR code', 'webdide-card-to-card-verification' ),
								$label_html
							);
						}
					}
					?>
				<?php endif; ?>

				<p class="shetab-countdown" id="shetab-countdown-<?php echo esc_attr( $txn->id ); ?>">
					<?php printf( esc_html__( 'Time remaining to transfer: %s', 'webdide-card-to-card-verification' ), esc_html( gmdate( 'i:s', (int) $remaining ) ) ); ?>
				</p>

				<div class="shetab-upload-box" id="shetab-upload-container">
					<strong><?php esc_html_e( 'Upload payment slip image (optional):', 'webdide-card-to-card-verification' ); ?></strong>
					<p class="shetab-upload-hint"><?php esc_html_e( 'If your transaction was not confirmed automatically, you can upload the slip image here.', 'webdide-card-to-card-verification' ); ?></p>
					<input type="file" id="shetab-receipt-files" class="shetab-file-input" multiple accept="image/*">
					<div class="shetab-upload-actions">
						<button type="button" id="shetab-pick-receipt" class="button shetab-upload-btn"><?php esc_html_e( 'Choose slip image', 'webdide-card-to-card-verification' ); ?></button>
						<button type="button" id="shetab-do-upload" class="button alt shetab-upload-btn is-hidden"><?php esc_html_e( 'Submit receipt', 'webdide-card-to-card-verification' ); ?></button>
					</div>
					<div id="file-list-preview" class="shetab-receipt-preview"></div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $receipts ) ) : ?>
				<div class="wdcv-receipts-list">
					<strong><?php esc_html_e( 'Uploaded receipt images:', 'webdide-card-to-card-verification' ); ?></strong>
					<div class="shetab-receipt-preview">
						<?php foreach ( (array) $receipts as $aid ) : ?>
							<a href="<?php echo esc_url( wp_get_attachment_url( $aid ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo wp_get_attachment_image( $aid, 'thumbnail' ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
				<p class="wdcv-orders-cta">
					<a class="button alt shetab-upload-btn wdcv-orders-btn" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
						<?php esc_html_e( 'Go to my orders', 'webdide-card-to-card-verification' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php self::render_support_block( $whatsapp, $telegram, $manager_text ); ?>
		</div>
		<?php
	}

	/**
	 * Thank-you hook: success summary only (no polling / no transfer UI).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function render_payment_instructions( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$txn = WebDide_CV_DB::get_transaction_by_order_id( $order_id );
		if ( ! $txn ) {
			return;
		}

		// Pending orders are redirected to the pay page; keep this as a safety no-op.
		if ( self::order_needs_payment( $order, $txn ) ) {
			return;
		}

		$gateway = null;
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway  = $gateways['wdcv'] ?? null;
		}
		$success = $gateway instanceof self
			? $gateway->get_option(
				'thankyou_success_message',
				__( 'Your payment was confirmed successfully. Your order is being processed.', 'webdide-card-to-card-verification' )
			)
			: __( 'Your payment was confirmed successfully. Your order is being processed.', 'webdide-card-to-card-verification' );

		?>
		<div class="shetab-instructions wdcv-thankyou-panel">
			<div class="wdcv-success-banner">
				<strong><?php esc_html_e( 'Payment confirmed', 'webdide-card-to-card-verification' ); ?></strong>
				<?php echo esc_html( $success ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string $whatsapp WhatsApp number.
	 * @param string $telegram Telegram handle.
	 * @param string $manager_text Manager note.
	 */
	private static function render_support_block( $whatsapp, $telegram, $manager_text ) {
		if ( ! $whatsapp && ! $telegram && ! $manager_text ) {
			return;
		}
		?>
		<div class="shetab-support-info">
			<strong><?php esc_html_e( 'Help & support:', 'webdide-card-to-card-verification' ); ?></strong>
			<div class="shetab-support-links">
				<?php if ( $whatsapp ) : ?>
					<a href="https://wa.me/<?php echo esc_attr( $whatsapp ); ?>" class="shetab-support-item" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'WhatsApp:', 'webdide-card-to-card-verification' ); ?> <?php echo esc_html( $whatsapp ); ?>
					</a>
				<?php endif; ?>

				<?php if ( $telegram ) : ?>
					<a href="https://t.me/<?php echo esc_attr( str_replace( '@', '', $telegram ) ); ?>" class="shetab-support-item" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Telegram:', 'webdide-card-to-card-verification' ); ?> <?php echo esc_html( $telegram ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( $manager_text ) : ?>
				<div class="shetab-manager-msg">
					<strong><?php esc_html_e( 'Manager message:', 'webdide-card-to-card-verification' ); ?></strong>
					<?php echo nl2br( esc_html( $manager_text ) ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! class_exists( 'WC_Gateway_WDCV' ) ) {
	class_alias( 'WebDide_CV_Gateway', 'WC_Gateway_WDCV' );
}
