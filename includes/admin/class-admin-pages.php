<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebDide_CV_Admin {
	public static function get_qr_code_data_uri( $data, $size = '150x150' ) {
		return WebDide_CV_Utils::get_qr_code_data_uri( $data, $size );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_receipt_metabox' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_receipt_actions' ) );
	}

	/**
	 * Approve a payment receipt and mark the order paid.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $source Source label (manual_admin_confirmation, bot_telegram, …).
	 * @return true|WP_Error
	 */
	/**
	 * Resolve receipt review status for an order (approved / rejected / pending).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function get_receipt_review_status( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 'pending';
		}

		$review = $order->get_meta( '_shetab_receipt_review' );
		if ( is_array( $review ) && ! empty( $review['status'] ) ) {
			$status = sanitize_key( $review['status'] );
			if ( in_array( $status, array( 'approved', 'rejected', 'pending' ), true ) ) {
				return $status;
			}
		}

		// Fallback for older orders without review meta.
		if ( $order->is_paid() ) {
			return 'approved';
		}
		if ( 'failed' === $order->get_status() ) {
			return 'rejected';
		}

		return 'pending';
	}

	/**
	 * Persist receipt review status on the order.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status approved|rejected|pending.
	 * @param string   $source Source label.
	 * @param string   $note   Optional note.
	 */
	private static function set_receipt_review_status( $order, $status, $source = '', $note = '' ) {
		$order->update_meta_data(
			'_shetab_receipt_review',
			array(
				'status'    => sanitize_key( $status ),
				'source'    => sanitize_text_field( $source ),
				'note'      => sanitize_textarea_field( $note ),
				'updated_at'=> current_time( 'mysql' ),
			)
		);
		$order->save();
	}

	public static function approve_receipt( $order, $source = 'manual_admin_confirmation' ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'invalid_order', __( 'Order not found.', 'webdide-card-to-card-verification' ) );
		}

		if ( $order->is_paid() ) {
			return new WP_Error( 'already_paid', __( 'This order is already paid.', 'webdide-card-to-card-verification' ) );
		}

		$order->payment_complete();
		$order->add_order_note(
			sprintf(
				/* translators: %s: confirmation source */
				__( 'Payment slip approved (%s).', 'webdide-card-to-card-verification' ),
				$source
			)
		);

		$txn = WebDide_CV_DB::get_transaction_by_order_id( $order->get_id() );
		if ( $txn && 'confirmed' !== $txn->status ) {
			WebDide_CV_DB::mark_transaction_confirmed( $txn->id, $source );
		}

		self::set_receipt_review_status( $order, 'approved', $source );

		return true;
	}

	/**
	 * Reject a payment receipt and fail the order.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $note   Optional admin note.
	 * @param string   $source Source label.
	 * @return true|WP_Error
	 */
	public static function reject_receipt( $order, $note = '', $source = 'manual_admin_rejection' ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'invalid_order', __( 'Order not found.', 'webdide-card-to-card-verification' ) );
		}

		$status_note = __( 'Payment slip marked as invalid by admin.', 'webdide-card-to-card-verification' );
		$order->update_status( 'failed', $status_note );

		$order_note = sprintf(
			/* translators: %s: rejection source */
			__( 'Payment slip was marked as invalid and the order was rejected (%s).', 'webdide-card-to-card-verification' ),
			$source
		);
		if ( $note ) {
			$order_note .= ' ' . sprintf(
				/* translators: %s: admin note */
				__( 'Note: %s', 'webdide-card-to-card-verification' ),
				$note
			);
		}
		$order->add_order_note( $order_note );

		$txn = WebDide_CV_DB::get_transaction_by_order_id( $order->get_id() );
		if ( $txn && 'rejected' !== $txn->status ) {
			WebDide_CV_DB::mark_transaction_rejected( $txn->id, $source );
		}

		self::set_receipt_review_status( $order, 'rejected', $source, $note );

		return true;
	}

	public static function add_order_receipt_metabox() {
		add_meta_box(
			'shetab_order_receipts',
			__( 'Bank Transfer Slips (Shetab)', 'webdide-card-to-card-verification' ),
			array( __CLASS__, 'render_order_receipt_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'high'
		);
	}

	public static function render_order_receipt_metabox( $post_or_order ) {
		$order = null;
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} elseif ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
		} elseif ( is_numeric( $post_or_order ) ) {
			$order = wc_get_order( $post_or_order );
		}

		if ( ! $order ) {
			return;
		}

		$order_id = $order->get_id();
		$receipts = $order->get_meta( '_shetab_receipts' );

		if ( empty( $receipts ) || ! is_array( $receipts ) ) {
			echo '<p style="color:#666; font-style:italic;">' . esc_html__( 'No slips have been uploaded for this order.', 'webdide-card-to-card-verification' ) . '</p>';
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
				echo '<div style="background:#eee; height:80px; display:flex; align-items:center; justify-content:center; font-size:10px; color:#999; text-align:center;">' . esc_html__( 'Image not found', 'webdide-card-to-card-verification' ) . '<br>(#' . esc_html( $aid ) . ')</div>';
			}
		}
		echo '</div>';

		$review_status = self::get_receipt_review_status( $order );
		$confirm_nonce = wp_create_nonce( 'shetab_receipt_action' );

		if ( 'approved' === $review_status ) {
			?>
			<div style="background:#f0fff4; border:1px solid #9ae6b4; border-radius:6px; padding:10px; margin-bottom:12px;">
				<strong style="color:#276749; display:block; margin-bottom:4px;">✅ <?php esc_html_e( 'Slip approved', 'webdide-card-to-card-verification' ); ?></strong>
				<span style="color:#2f855a; font-size:0.9em;"><?php esc_html_e( 'This payment slip was approved. You can still mark it as invalid below if needed.', 'webdide-card-to-card-verification' ); ?></span>
			</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="margin-top:10px;">
				<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
				<input type="hidden" name="_nonce" value="<?php echo esc_attr( $confirm_nonce ); ?>">
				<p>
					<label for="wdcv_reject_note"><?php esc_html_e( 'Rejection note (optional):', 'webdide-card-to-card-verification' ); ?></label>
					<textarea name="reject_note" id="wdcv_reject_note" rows="2" style="width:100%;"></textarea>
				</p>
				<button type="submit" name="action" value="shetab_reject_receipt" class="button button-secondary" style="color:#e53e3e; border-color:#e53e3e; width:100%;">❌ <?php esc_html_e( 'Mark as invalid', 'webdide-card-to-card-verification' ); ?></button>
			</form>
			<?php
			return;
		}

		if ( 'rejected' === $review_status ) {
			?>
			<div style="background:#fff5f5; border:1px solid #feb2b2; border-radius:6px; padding:10px; margin-bottom:12px;">
				<strong style="color:#c53030; display:block; margin-bottom:4px;">❌ <?php esc_html_e( 'Slip marked as invalid', 'webdide-card-to-card-verification' ); ?></strong>
				<span style="color:#9b2c2c; font-size:0.9em;"><?php esc_html_e( 'This payment slip was marked as invalid. You can approve it again if this was a mistake.', 'webdide-card-to-card-verification' ); ?></span>
			</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="margin-top:10px;">
				<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
				<input type="hidden" name="_nonce" value="<?php echo esc_attr( $confirm_nonce ); ?>">
				<button type="submit" name="action" value="shetab_confirm_receipt" class="button button-primary" style="background:#38a169; border-color:#38a169; width:100%;">✅ <?php esc_html_e( 'Confirm Slip', 'webdide-card-to-card-verification' ); ?></button>
			</form>
			<p style="color:#666; font-size:0.85em; margin-top:10px;"><?php esc_html_e( 'Confirming the slip will set the order status to Processing.', 'webdide-card-to-card-verification' ); ?></p>
			<?php
			return;
		}
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="margin-top:10px;">
			<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
			<input type="hidden" name="_nonce" value="<?php echo esc_attr( $confirm_nonce ); ?>">
			<p>
				<label for="wdcv_reject_note"><?php esc_html_e( 'Rejection note (optional):', 'webdide-card-to-card-verification' ); ?></label>
				<textarea name="reject_note" id="wdcv_reject_note" rows="2" style="width:100%;"></textarea>
			</p>
			<div style="display:flex; gap:5px; margin-top:10px;">
				<button type="submit" name="action" value="shetab_confirm_receipt" class="button button-primary" style="background:#38a169; border-color:#38a169;">✅ <?php esc_html_e( 'Confirm Slip', 'webdide-card-to-card-verification' ); ?></button>
				<button type="submit" name="action" value="shetab_reject_receipt" class="button button-secondary" style="color:#e53e3e; border-color:#e53e3e;">❌ <?php esc_html_e( 'Invalid', 'webdide-card-to-card-verification' ); ?></button>
			</div>
		</form>
		<p style="color:#666; font-size:0.85em; margin-top:10px;"><?php esc_html_e( 'Confirming the slip will set the order status to Processing.', 'webdide-card-to-card-verification' ); ?></p>
		<?php
	}

	public static function handle_receipt_actions() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! in_array( $action, array( 'shetab_confirm_receipt', 'shetab_reject_receipt' ), true ) ) {
			return;
		}

		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
		$nonce    = isset( $_REQUEST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'shetab_receipt_action' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( 'shetab_confirm_receipt' === $action ) {
			self::approve_receipt( $order, 'manual_admin_confirmation' );
		} else {
			$note = isset( $_REQUEST['reject_note'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['reject_note'] ) ) : '';
			self::reject_receipt( $order, $note, 'manual_admin_rejection' );
		}

		$redirect_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		$edit_link    = get_edit_post_link( $order_id, 'raw' );
		if ( $edit_link ) {
			$redirect_url = $edit_link;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Shetab Management', 'webdide-card-to-card-verification' ),
			__( 'Shetab Management', 'webdide-card-to-card-verification' ),
			'manage_woocommerce',
			'webdide-card-to-card-verification',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-admin-generic',
			56
		);
	}

	/**
	 * Available admin tabs.
	 *
	 * @return array
	 */
	public static function get_admin_tabs() {
		return array(
			'overview' => __( 'Overview', 'webdide-card-to-card-verification' ),
			'stats'    => __( 'Statistics', 'webdide-card-to-card-verification' ),
			'gateway'  => __( 'Payment gateway', 'webdide-card-to-card-verification' ),
			'api'      => __( 'API & App', 'webdide-card-to-card-verification' ),
			'cards'    => __( 'Bank cards', 'webdide-card-to-card-verification' ),
			'bots'     => __( 'Telegram & Bale', 'webdide-card-to-card-verification' ),
			'support'  => __( 'Customer support', 'webdide-card-to-card-verification' ),
		);
	}

	/**
	 * WooCommerce gateway option defaults.
	 *
	 * @return array
	 */
	public static function get_gateway_defaults() {
		return array(
			'enabled'                   => 'yes',
			'title'                     => __( 'Card-to-Card (auto verification)', 'webdide-card-to-card-verification' ),
			'description'               => __( 'Card-to-Card payments via Shetab with automatic transaction confirmation.', 'webdide-card-to-card-verification' ),
			'thankyou_awaiting_message' => 'سفارش شما ثبت شده و در انتظار پرداخت می‌باشد. لطفاً جهت نهایی شدن سفارش، مبلغ مورد نظر را طبق دستورالعمل زیر واریز نمایید.',
			'thankyou_success_message'  => __( 'Your payment was confirmed successfully. Your order is being processed.', 'webdide-card-to-card-verification' ),
			'show_card_number'          => 'yes',
			'show_account_number'       => 'no',
			'show_sheba'                => 'no',
		);
	}

	/**
	 * Build encrypted account/sheba fields from POST.
	 *
	 * @param bool $clear_when_empty When true, empty inputs clear stored values.
	 * @return array{account:array,sheba:array,errors:string[]}
	 */
	private static function parse_card_bank_fields_from_post( $clear_when_empty = true ) {
		$result = array(
			'account' => array(),
			'sheba'   => array(),
			'errors'  => array(),
		);

		$account_raw = isset( $_POST['account_number'] ) ? wp_unslash( $_POST['account_number'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$account     = WebDide_CV_Utils::normalize_account_number( $account_raw );
		if ( '' !== $account ) {
			$result['account'] = array(
				'encrypted_account' => WebDide_CV_Utils::encrypt_card_number( $account ),
				'masked_account'    => WebDide_CV_Utils::mask_sensitive_number( $account ),
			);
		} elseif ( $clear_when_empty && isset( $_POST['account_number'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$result['account'] = array(
				'encrypted_account' => '',
				'masked_account'    => '',
			);
		}

		$sheba_raw = isset( $_POST['sheba_number'] ) ? wp_unslash( $_POST['sheba_number'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sheba_raw = is_string( $sheba_raw ) ? trim( $sheba_raw ) : '';
		if ( '' !== $sheba_raw ) {
			$sheba = WebDide_CV_Utils::normalize_sheba( $sheba_raw );
			if ( '' === $sheba ) {
				$result['errors'][] = __( 'Sheba must be IR followed by 24 digits.', 'webdide-card-to-card-verification' );
			} else {
				$result['sheba'] = array(
					'encrypted_sheba' => WebDide_CV_Utils::encrypt_card_number( $sheba ),
					'masked_sheba'    => WebDide_CV_Utils::mask_sensitive_number( $sheba ),
				);
			}
		} elseif ( $clear_when_empty && isset( $_POST['sheba_number'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$result['sheba'] = array(
				'encrypted_sheba' => '',
				'masked_sheba'    => '',
			);
		}

		return $result;
	}

	/**
	 * Current gateway settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_gateway_settings() {
		$stored = get_option( 'woocommerce_wdcv_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::get_gateway_defaults() );
	}

	/**
	 * Current admin tab slug.
	 *
	 * @return string
	 */
	public static function get_current_tab() {
		$tab  = isset( $_REQUEST['wdcv_tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['wdcv_tab'] ) ) : '';
		$tabs = self::get_admin_tabs();
		if ( ! $tab && isset( $_GET['tab'] ) ) {
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
		}
		return isset( $tabs[ $tab ] ) ? $tab : 'overview';
	}

	/**
	 * Admin page URL for a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function get_tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => 'webdide-card-to-card-verification',
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Hidden field to keep the active tab after POST.
	 *
	 * @param string $tab Tab slug.
	 */
	private static function render_tab_field( $tab ) {
		echo '<input type="hidden" name="wdcv_tab" value="' . esc_attr( $tab ) . '">';
	}

	/**
	 * Help icon that opens a guide modal.
	 *
	 * @param string $guide_id Modal id without prefix.
	 * @param string $label    Accessible label.
	 */
	private static function render_help_button( $guide_id, $label ) {
		?>
		<button type="button" class="wdcv-help-btn" data-wdcv-guide="<?php echo esc_attr( $guide_id ); ?>" title="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
			<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
			<span class="wdcv-help-btn-text"><?php echo esc_html( $label ); ?></span>
		</button>
		<?php
	}

	/**
	 * Inline tooltip next to a label.
	 *
	 * @param string $text Tooltip text.
	 */
	private static function render_tooltip( $text ) {
		?>
		<span class="wdcv-tooltip" tabindex="0">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<span class="wdcv-tooltip-text"><?php echo esc_html( $text ); ?></span>
		</span>
		<?php
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'webdide-card-to-card-verification' ) );
		}

		$messages = array();
		if ( isset( $_POST['shetab_action'] ) && check_admin_referer( 'wdcv_admin' ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['shetab_action'] ) );

			if ( 'add_card' === $action ) {
				$label  = sanitize_text_field( wp_unslash( $_POST['label'] ) );
				$number = preg_replace( '/\D/', '', wp_unslash( $_POST['card_number'] ?? '' ) );
				$bank   = self::parse_card_bank_fields_from_post( true );
				$has_account = ! empty( $bank['account']['encrypted_account'] );
				$has_sheba   = ! empty( $bank['sheba']['encrypted_sheba'] );
				if ( ! empty( $bank['errors'] ) ) {
					$messages = array_merge( $messages, $bank['errors'] );
				} elseif ( '' === $label ) {
					$messages[] = __( 'Please enter the account holder / bank name.', 'webdide-card-to-card-verification' );
				} elseif ( '' !== $number && strlen( $number ) < 16 ) {
					$messages[] = __( 'Please enter a valid 16-digit card number, or leave it empty.', 'webdide-card-to-card-verification' );
				} elseif ( '' === $number && ! $has_account && ! $has_sheba ) {
					$messages[] = __( 'Enter at least one of: card number, account number, or Sheba.', 'webdide-card-to-card-verification' );
				} else {
					$payload = array_merge(
						array(
							'label'              => $label,
							'encrypted_number'   => '' !== $number ? WebDide_CV_Utils::encrypt_card_number( $number ) : '',
							'masked_number'      => '' !== $number ? WebDide_CV_Utils::mask_card_number( $number ) : '',
							'max_deposits_count' => absint( $_POST['max_deposits_count'] ?? 0 ),
							'max_total_amount'   => absint( $_POST['max_total_amount'] ?? 0 ),
							'reset_period'       => sanitize_text_field( wp_unslash( $_POST['reset_period'] ?? 'none' ) ),
							'active'             => isset( $_POST['active'] ) ? 1 : 0,
						),
						$bank['account'],
						$bank['sheba']
					);
					WebDide_CV_DB::insert_card( $payload );
					$messages[] = __( 'Card added.', 'webdide-card-to-card-verification' );
				}
			}

			if ( 'update_card' === $action && ! empty( $_POST['card_id'] ) ) {
				$card_id  = absint( $_POST['card_id'] );
				$existing = WebDide_CV_DB::get_card( $card_id );
				$bank     = self::parse_card_bank_fields_from_post( true );
				if ( ! empty( $bank['errors'] ) ) {
					$messages = array_merge( $messages, $bank['errors'] );
				} elseif ( $existing ) {
					$label  = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
					$number = preg_replace( '/\D/', '', wp_unslash( $_POST['card_number'] ?? '' ) );
					$payload = array(
						'label'              => $label,
						'max_deposits_count' => absint( $_POST['max_deposits_count'] ?? 0 ),
						'max_total_amount'   => absint( $_POST['max_total_amount'] ?? 0 ),
						'reset_period'       => sanitize_text_field( wp_unslash( $_POST['reset_period'] ?? 'none' ) ),
						'active'             => isset( $_POST['active'] ) ? 1 : 0,
					);
					if ( '' !== $number && strlen( $number ) < 16 ) {
						$messages[] = __( 'Please enter a valid 16-digit card number, or leave it empty.', 'webdide-card-to-card-verification' );
					} else {
						// Empty field clears the stored card number.
						$payload['encrypted_number'] = '' !== $number ? WebDide_CV_Utils::encrypt_card_number( $number ) : '';
						$payload['masked_number']    = '' !== $number ? WebDide_CV_Utils::mask_card_number( $number ) : '';
						$payload                     = array_merge( $payload, $bank['account'], $bank['sheba'] );

						$has_card    = '' !== $payload['encrypted_number'];
						$has_account = array_key_exists( 'encrypted_account', $payload )
							? ! empty( $payload['encrypted_account'] )
							: ! empty( $existing->encrypted_account );
						$has_sheba   = array_key_exists( 'encrypted_sheba', $payload )
							? ! empty( $payload['encrypted_sheba'] )
							: ! empty( $existing->encrypted_sheba );

						if ( '' === $label ) {
							$messages[] = __( 'Please enter the account holder / bank name.', 'webdide-card-to-card-verification' );
						} elseif ( ! $has_card && ! $has_account && ! $has_sheba ) {
							$messages[] = __( 'Enter at least one of: card number, account number, or Sheba.', 'webdide-card-to-card-verification' );
						} else {
							WebDide_CV_DB::update_card( $card_id, $payload );
							$messages[] = __( 'Card updated.', 'webdide-card-to-card-verification' );
						}
					}
				} else {
					$messages[] = __( 'Card not found.', 'webdide-card-to-card-verification' );
				}
			}

			if ( 'delete_card' === $action && ! empty( $_POST['delete_card'] ) ) {
				WebDide_CV_DB::delete_card( absint( $_POST['delete_card'] ) );
				$messages[] = __( 'Card removed.', 'webdide-card-to-card-verification' );
			}

			if ( 'save_secret' === $action && isset( $_POST['api_secret'] ) ) {
				$secret = sanitize_text_field( wp_unslash( $_POST['api_secret'] ) );
				WebDide_CV_Utils::set_api_secret( $secret );
				$messages[] = __( 'API Secret saved successfully.', 'webdide-card-to-card-verification' );
			}

			if ( 'save_support_info' === $action ) {
				update_option( 'wdcv_support_whatsapp', sanitize_text_field( wp_unslash( $_POST['support_whatsapp'] ?? '' ) ) );
				update_option( 'wdcv_support_telegram', sanitize_text_field( wp_unslash( $_POST['support_telegram'] ?? '' ) ) );
				update_option( 'wdcv_support_manager_text', sanitize_textarea_field( wp_unslash( $_POST['support_manager_text'] ?? '' ) ) );
				$messages[] = __( 'Support information saved successfully.', 'webdide-card-to-card-verification' );
			}

			if ( 'save_gateway_settings' === $action ) {
				$current = self::get_gateway_settings();
				$current['enabled']                   = isset( $_POST['gateway_enabled'] ) ? 'yes' : 'no';
				$current['title']                     = sanitize_text_field( wp_unslash( $_POST['gateway_title'] ?? '' ) );
				$current['description']               = sanitize_textarea_field( wp_unslash( $_POST['gateway_description'] ?? '' ) );
				$current['thankyou_awaiting_message'] = sanitize_textarea_field( wp_unslash( $_POST['gateway_thankyou_awaiting'] ?? '' ) );
				$current['thankyou_success_message']  = sanitize_textarea_field( wp_unslash( $_POST['gateway_thankyou_success'] ?? '' ) );
				$current['show_card_number']          = isset( $_POST['gateway_show_card_number'] ) ? 'yes' : 'no';
				$current['show_account_number']       = isset( $_POST['gateway_show_account_number'] ) ? 'yes' : 'no';
				$current['show_sheba']                = isset( $_POST['gateway_show_sheba'] ) ? 'yes' : 'no';
				if ( 'yes' !== $current['show_card_number'] && 'yes' !== $current['show_account_number'] && 'yes' !== $current['show_sheba'] ) {
					$current['show_card_number'] = 'yes';
				}
				update_option( 'woocommerce_wdcv_settings', $current );
				$messages[] = __( 'Payment gateway settings saved successfully.', 'webdide-card-to-card-verification' );
			}

			if ( 'save_bot_settings' === $action ) {
				foreach ( array( 'telegram', 'bale' ) as $channel ) {
					update_option( 'wdcv_bot_' . $channel . '_enabled', isset( $_POST[ 'bot_' . $channel . '_enabled' ] ) ? 'yes' : 'no' );
					update_option( 'wdcv_bot_' . $channel . '_token', sanitize_text_field( wp_unslash( $_POST[ 'bot_' . $channel . '_token' ] ?? '' ) ) );
					update_option( 'wdcv_bot_' . $channel . '_chat_ids', sanitize_text_field( wp_unslash( $_POST[ 'bot_' . $channel . '_chat_ids' ] ?? '' ) ) );
				}
				$messages[] = __( 'Bot settings saved successfully.', 'webdide-card-to-card-verification' );
			}

			if ( 'set_bot_webhook' === $action ) {
				$channel = sanitize_key( wp_unslash( $_POST['bot_channel'] ?? '' ) );
				if ( in_array( $channel, array( 'telegram', 'bale' ), true ) ) {
					$client = WebDide_CV_Bot_Client::from_options( $channel );
					if ( ! $client ) {
						$token = get_option( 'wdcv_bot_' . $channel . '_token', '' );
						if ( $token ) {
							$client = new WebDide_CV_Bot_Client( $channel, $token );
						}
					}
					if ( $client ) {
						$url    = WebDide_CV_Bot_Webhook::get_webhook_url( $channel );
						$result = $client->set_webhook( $url );
						if ( is_wp_error( $result ) ) {
							$messages[] = sprintf(
								/* translators: 1: channel, 2: error */
								__( 'Failed to set %1$s webhook: %2$s', 'webdide-card-to-card-verification' ),
								$channel,
								$result->get_error_message()
							);
						} else {
							$messages[] = sprintf(
								/* translators: %s: channel name */
								__( '%s webhook set successfully.', 'webdide-card-to-card-verification' ),
								ucfirst( $channel )
							);
						}
					} else {
						$messages[] = __( 'Enable the bot and save a token before setting the webhook.', 'webdide-card-to-card-verification' );
					}
				}
			}

			if ( ! empty( $messages ) ) {
				set_transient( 'wdcv_admin_notices_' . get_current_user_id(), $messages, 60 );
				wp_safe_redirect( self::get_tab_url( self::get_current_tab() ) );
				exit;
			}
		}

		$flash = get_transient( 'wdcv_admin_notices_' . get_current_user_id() );
		if ( is_array( $flash ) ) {
			$messages = array_merge( $messages, $flash );
			delete_transient( 'wdcv_admin_notices_' . get_current_user_id() );
		}

		$current_tab     = self::get_current_tab();
		$tabs            = self::get_admin_tabs();
		$cards           = WebDide_CV_DB::get_cards();
		$api_secret      = WebDide_CV_Utils::get_api_secret();
		$api_secret_qr   = $api_secret ? self::get_qr_code_data_uri( $api_secret, '150x150' ) : '';
		$confirm_api_url = rest_url( 'webdide-cv/v1/confirm' );
		$status_api_url  = rest_url( 'webdide-cv/v1/status' );
		$confirm_api_qr  = self::get_qr_code_data_uri( $confirm_api_url, '100x100' );
		$status_api_qr   = self::get_qr_code_data_uri( $status_api_url, '100x100' );
		?>
		<div class="wdcv-admin-wrap">
			<div class="wdcv-admin-header">
				<img src="<?php echo esc_url( WDCV_PLUGIN_URL . 'public/assets/images/logo.png' ); ?>" class="wdcv-admin-logo" alt="Shetab Logo">
				<div>
					<h1><?php esc_html_e( 'Card-to-Card verification', 'webdide-card-to-card-verification' ); ?></h1>
					<p class="wdcv-admin-subtitle"><?php esc_html_e( 'Configure API, bank cards, receipt bots, and customer support in separate sections.', 'webdide-card-to-card-verification' ); ?></p>
				</div>
			</div>

			<?php self::render_woopilot_promo(); ?>

			<?php if ( get_option( 'permalink_structure' ) === '' ) : ?>
				<div class="notice notice-error wdcv-notice-box">
					<h3><?php esc_html_e( 'Warning: Missing Permalinks Configuration', 'webdide-card-to-card-verification' ); ?></h3>
					<p>
						<?php echo wp_kses_post( __( 'To ensure the automatic confirmation system (API) works correctly, you must set your WordPress <strong>"Permalinks"</strong> to anything other than "Plain".', 'webdide-card-to-card-verification' ) ); ?>
						<br>
						<?php
						printf(
							/* translators: %s: Link to the permalinks settings page */
							wp_kses_post( __( 'Please go to %s and set the structure (e.g., to "Post name").', 'webdide-card-to-card-verification' ) ),
							sprintf(
								'<a href="%1$s" target="_blank"><strong>%2$s</strong></a>',
								esc_url( admin_url( 'options-permalink.php' ) ),
								esc_html__( 'Settings > Permalinks', 'webdide-card-to-card-verification' )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php foreach ( $messages as $m ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $m ); ?></p></div>
			<?php endforeach; ?>

			<nav class="wdcv-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'webdide-card-to-card-verification' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="wdcv-tab<?php echo $current_tab === $slug ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::get_tab_url( $slug ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="wdcv-tab-panels">
				<?php
				if ( 'overview' === $current_tab ) {
					self::render_tab_overview();
				} elseif ( 'stats' === $current_tab ) {
					self::render_tab_stats();
				} elseif ( 'gateway' === $current_tab ) {
					self::render_tab_gateway();
				} elseif ( 'api' === $current_tab ) {
					self::render_tab_api( $api_secret, $api_secret_qr, $confirm_api_url, $status_api_url, $confirm_api_qr, $status_api_qr );
				} elseif ( 'cards' === $current_tab ) {
					self::render_tab_cards( $cards );
				} elseif ( 'bots' === $current_tab ) {
					self::render_tab_bots();
				} else {
					self::render_tab_support();
				}
				?>
			</div>
		</div>

		<?php self::render_help_modals(); ?>

		<div id="orderModal" class="wdcv-modal">
			<div class="wdcv-modal-content">
				<div class="wdcv-modal-header">
					<h3 class="wdcv-modal-title" id="modalTitle"><?php esc_html_e( 'Order List', 'webdide-card-to-card-verification' ); ?></h3>
					<span class="wdcv-close" onclick="closeModal()">&times;</span>
				</div>
				<div id="modalBody">
					<table class="wdcv-detail-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Order ID', 'webdide-card-to-card-verification' ); ?></th>
								<th><?php esc_html_e( 'Amount (Toman)', 'webdide-card-to-card-verification' ); ?></th>
								<th><?php esc_html_e( 'Confirmation Date', 'webdide-card-to-card-verification' ); ?></th>
								<th><?php esc_html_e( 'View', 'webdide-card-to-card-verification' ); ?></th>
							</tr>
						</thead>
						<tbody id="orderTableBody"></tbody>
					</table>
					<div id="modalPagination" class="wdcv-pagination"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Eye-catching promo for WooPilot (sibling product).
	 */
	private static function render_woopilot_promo() {
		$url = 'https://woopilot.ir';
		?>
		<aside class="wdcv-woopilot-promo" aria-label="<?php esc_attr_e( 'WooPilot', 'webdide-card-to-card-verification' ); ?>">
			<div class="wdcv-woopilot-promo__glow" aria-hidden="true"></div>
			<div class="wdcv-woopilot-promo__body">
				<span class="wdcv-woopilot-promo__badge"><?php esc_html_e( 'From the same team', 'webdide-card-to-card-verification' ); ?></span>
				<div class="wdcv-woopilot-promo__brand">
					<span class="wdcv-woopilot-promo__mark" aria-hidden="true">✈</span>
					<strong class="wdcv-woopilot-promo__name"><?php esc_html_e( 'WooPilot', 'webdide-card-to-card-verification' ); ?></strong>
					<span class="wdcv-woopilot-promo__tagline"><?php esc_html_e( "Your store's co-pilot", 'webdide-card-to-card-verification' ); ?></span>
				</div>
				<p class="wdcv-woopilot-promo__text">
					<?php esc_html_e( 'Sell your store products on Instagram (smart bot & Direct), Telegram, and Bale too — fully synced with WooCommerce.', 'webdide-card-to-card-verification' ); ?>
				</p>
				<a class="wdcv-woopilot-promo__cta" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Discover WooPilot', 'webdide-card-to-card-verification' ); ?>
					<span aria-hidden="true">←</span>
				</a>
			</div>
		</aside>
		<?php
	}

	/**
	 * Overview tab.
	 */
	private static function render_tab_overview() {
		?>
		<div class="wdcv-card">
			<h2><?php esc_html_e( 'Getting started', 'webdide-card-to-card-verification' ); ?></h2>
			<ol class="wdcv-steps">
				<li><?php esc_html_e( 'Open the Payment gateway tab, enable the method, and set the checkout title and messages.', 'webdide-card-to-card-verification' ); ?></li>
				<li><?php esc_html_e( 'Open the API & App tab, set your secret key, and scan the QR code in the Shetab Android app.', 'webdide-card-to-card-verification' ); ?></li>
				<li><?php esc_html_e( 'Add at least one active destination bank card in the Bank cards tab.', 'webdide-card-to-card-verification' ); ?></li>
				<li><?php esc_html_e( 'Place a test order and confirm the gateway appears on checkout.', 'webdide-card-to-card-verification' ); ?></li>
				<li><?php esc_html_e( 'Optional: configure Telegram or Bale bots so uploaded receipts can be approved from your phone.', 'webdide-card-to-card-verification' ); ?></li>
			</ol>
		</div>
		<div class="wdcv-card">
			<h2><?php esc_html_e( 'Guides & Resources', 'webdide-card-to-card-verification' ); ?></h2>
			<div class="wdcv-resource-grid">
				<div class="wdcv-resource-item">
					<h3><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download Android App', 'webdide-card-to-card-verification' ); ?></h3>
					<p><?php esc_html_e( 'To use automatic bank transfer confirmation, download and install the Shetab app from Cafe Bazaar.', 'webdide-card-to-card-verification' ); ?></p>
					<a href="https://cafebazaar.ir/app/ir.webdide.verify" target="_blank" class="wdcv-btn"><?php esc_html_e( 'Download from Cafe Bazaar', 'webdide-card-to-card-verification' ); ?></a>
				</div>
				<div class="wdcv-resource-item">
					<h3><span class="dashicons dashicons-welcome-learn-more"></span> <?php esc_html_e( 'User Guide', 'webdide-card-to-card-verification' ); ?></h3>
					<p><?php esc_html_e( 'Watch tutorials and read guides for a correct configuration.', 'webdide-card-to-card-verification' ); ?></p>
					<a href="http://verify.webdide.ir/" target="_blank" class="wdcv-btn wdcv-btn-muted"><?php esc_html_e( 'View Tutorials', 'webdide-card-to-card-verification' ); ?></a>
				</div>
				<div class="wdcv-resource-item">
					<h3><span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'Developer Website', 'webdide-card-to-card-verification' ); ?></h3>
					<p><?php esc_html_e( 'Visit our website for support and the latest updates.', 'webdide-card-to-card-verification' ); ?></p>
					<a href="http://verify.webdide.ir/" target="_blank" class="wdcv-btn wdcv-btn-muted"><?php esc_html_e( 'Visit Website', 'webdide-card-to-card-verification' ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Statistics tab.
	 */
	private static function render_tab_stats() {
		$stats     = WebDide_CV_DB::get_statistics();
		$confirmed = $stats['summary']['confirmed'];
		$pending   = $stats['summary']['pending'];
		$expired   = $stats['summary']['expired'];
		$rejected  = $stats['summary']['rejected'];
		?>
		<div class="wdcv-card">
			<h2><?php esc_html_e( 'Statistics', 'webdide-card-to-card-verification' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Overview of card-to-card transactions, card performance, and recent activity.', 'webdide-card-to-card-verification' ); ?></p>

			<div class="wdcv-stats-grid">
				<div class="wdcv-stat-tile wdcv-stat-success">
					<span class="wdcv-stat-label"><?php esc_html_e( 'Confirmed (all time)', 'webdide-card-to-card-verification' ); ?></span>
					<strong class="wdcv-stat-value"><?php echo esc_html( number_format_i18n( $confirmed['count'] ) ); ?></strong>
					<span class="wdcv-stat-sub"><?php echo esc_html( number_format_i18n( $confirmed['amount'] ) ); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?></span>
				</div>
				<div class="wdcv-stat-tile">
					<span class="wdcv-stat-label"><?php esc_html_e( 'Confirmed today', 'webdide-card-to-card-verification' ); ?></span>
					<strong class="wdcv-stat-value"><?php echo esc_html( number_format_i18n( $stats['today']['count'] ) ); ?></strong>
					<span class="wdcv-stat-sub"><?php echo esc_html( number_format_i18n( $stats['today']['amount'] ) ); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?></span>
				</div>
				<div class="wdcv-stat-tile">
					<span class="wdcv-stat-label"><?php esc_html_e( 'Confirmed this month', 'webdide-card-to-card-verification' ); ?></span>
					<strong class="wdcv-stat-value"><?php echo esc_html( number_format_i18n( $stats['month']['count'] ) ); ?></strong>
					<span class="wdcv-stat-sub"><?php echo esc_html( number_format_i18n( $stats['month']['amount'] ) ); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?></span>
				</div>
				<div class="wdcv-stat-tile wdcv-stat-warn">
					<span class="wdcv-stat-label"><?php esc_html_e( 'Pending', 'webdide-card-to-card-verification' ); ?></span>
					<strong class="wdcv-stat-value"><?php echo esc_html( number_format_i18n( $pending['count'] ) ); ?></strong>
					<span class="wdcv-stat-sub"><?php echo esc_html( number_format_i18n( $pending['amount'] ) ); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?></span>
				</div>
				<div class="wdcv-stat-tile wdcv-stat-danger">
					<span class="wdcv-stat-label"><?php esc_html_e( 'Rejected', 'webdide-card-to-card-verification' ); ?></span>
					<strong class="wdcv-stat-value"><?php echo esc_html( number_format_i18n( $rejected['count'] ) ); ?></strong>
					<span class="wdcv-stat-sub"><?php echo esc_html( number_format_i18n( $rejected['amount'] ) ); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?></span>
				</div>
				<div class="wdcv-stat-tile wdcv-stat-muted">
					<span class="wdcv-stat-label"><?php esc_html_e( 'Expired', 'webdide-card-to-card-verification' ); ?></span>
					<strong class="wdcv-stat-value"><?php echo esc_html( number_format_i18n( $expired['count'] ) ); ?></strong>
					<span class="wdcv-stat-sub"><?php echo esc_html( number_format_i18n( $expired['amount'] ) ); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?></span>
				</div>
				<div class="wdcv-stat-tile">
					<span class="wdcv-stat-label"><?php esc_html_e( 'Orders with receipt upload', 'webdide-card-to-card-verification' ); ?></span>
					<strong class="wdcv-stat-value"><?php echo esc_html( number_format_i18n( $stats['receipt_orders'] ) ); ?></strong>
					<span class="wdcv-stat-sub"><?php printf( esc_html__( 'Cards: %1$d active / %2$d total', 'webdide-card-to-card-verification' ), (int) $stats['cards_active'], (int) $stats['cards_total'] ); ?></span>
				</div>
			</div>
		</div>

		<div class="wdcv-card">
			<h2><?php esc_html_e( 'Performance by card', 'webdide-card-to-card-verification' ); ?></h2>
			<div class="wdcv-table-wrap">
				<table class="wdcv-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Card', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Number', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Status', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Confirmed count', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Confirmed amount', 'webdide-card-to-card-verification' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $stats['by_card'] ) ) : ?>
							<tr><td colspan="5" class="wdcv-empty"><?php esc_html_e( 'No cards have been configured yet.', 'webdide-card-to-card-verification' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $stats['by_card'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row->label ); ?></td>
									<td class="wdcv-ltr"><?php echo esc_html( $row->masked_number ); ?></td>
									<td><?php echo $row->active ? '<span class="wdcv-status-on">' . esc_html__( 'Active', 'webdide-card-to-card-verification' ) . '</span>' : '<span class="wdcv-status-off">' . esc_html__( 'Inactive', 'webdide-card-to-card-verification' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row->confirmed_count ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row->confirmed_amount ) ); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="wdcv-card">
			<h2><?php esc_html_e( 'Recent transactions', 'webdide-card-to-card-verification' ); ?></h2>
			<div class="wdcv-table-wrap">
				<table class="wdcv-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order ID', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Amount (Toman)', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Card', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Status', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Date', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'View', 'webdide-card-to-card-verification' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $stats['recent'] ) ) : ?>
							<tr><td colspan="6" class="wdcv-empty"><?php esc_html_e( 'No transactions found.', 'webdide-card-to-card-verification' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $stats['recent'] as $txn ) : ?>
								<?php
								$status_label = $txn->status;
								if ( 'confirmed' === $txn->status ) {
									$status_label = __( 'Confirmed', 'webdide-card-to-card-verification' );
								} elseif ( 'pending' === $txn->status ) {
									$status_label = __( 'Pending', 'webdide-card-to-card-verification' );
								} elseif ( 'expired' === $txn->status ) {
									$status_label = __( 'Expired', 'webdide-card-to-card-verification' );
								} elseif ( 'rejected' === $txn->status ) {
									$status_label = __( 'Rejected', 'webdide-card-to-card-verification' );
								}
								$date = $txn->confirmed_at ? $txn->confirmed_at : $txn->created_at;
								$edit = get_edit_post_link( (int) $txn->order_id, 'raw' );
								if ( ! $edit && function_exists( 'wc_get_order' ) ) {
									$order = wc_get_order( (int) $txn->order_id );
									$edit  = $order ? $order->get_edit_order_url() : '';
								}
								?>
								<tr>
									<td>#<?php echo esc_html( $txn->order_id ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $txn->unique_amount ) ); ?></td>
									<td><?php echo esc_html( $txn->card_label ? $txn->card_label : '—' ); ?></td>
									<td><?php echo esc_html( $status_label ); ?></td>
									<td><?php echo esc_html( $date ); ?></td>
									<td>
										<?php if ( $edit ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $edit ); ?>" target="_blank"><?php esc_html_e( 'View', 'webdide-card-to-card-verification' ); ?></a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Payment gateway tab (same options as WooCommerce → Payments).
	 */
	private static function render_tab_gateway() {
		$settings = self::get_gateway_settings();
		$wc_url   = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wdcv' );
		?>
		<div class="wdcv-card">
			<div class="wdcv-card-title-row">
				<h2><?php esc_html_e( 'Payment gateway', 'webdide-card-to-card-verification' ); ?></h2>
			</div>
			<p class="description">
				<?php esc_html_e( 'These settings control how the Card-to-Card method appears on checkout and the thank-you page. Changes are saved to the same WooCommerce gateway options.', 'webdide-card-to-card-verification' ); ?>
				<?php
				printf(
					' <a href="%1$s" target="_blank">%2$s</a>',
					esc_url( $wc_url ),
					esc_html__( 'Open in WooCommerce Payments', 'webdide-card-to-card-verification' )
				);
				?>
			</p>
			<form method="post">
				<?php
				wp_nonce_field( 'wdcv_admin' );
				self::render_tab_field( 'gateway' );
				?>
				<input type="hidden" name="shetab_action" value="save_gateway_settings">

				<div class="wdcv-form-group">
					<label>
						<?php esc_html_e( 'Enable/Disable', 'webdide-card-to-card-verification' ); ?>
						<?php self::render_tooltip( __( 'When enabled, the gateway can appear on checkout if at least one active bank card is configured.', 'webdide-card-to-card-verification' ) ); ?>
					</label>
					<label class="wdcv-checkbox-label">
						<input type="checkbox" name="gateway_enabled" value="1" <?php checked( $settings['enabled'], 'yes' ); ?>>
						<?php esc_html_e( 'Enable Shetab Card-to-Card gateway', 'webdide-card-to-card-verification' ); ?>
					</label>
				</div>

				<div class="wdcv-form-group">
					<label for="gateway_title">
						<?php esc_html_e( 'Title', 'webdide-card-to-card-verification' ); ?>
						<?php self::render_tooltip( __( 'Payment method name shown to customers on the checkout page.', 'webdide-card-to-card-verification' ) ); ?>
					</label>
					<input name="gateway_title" id="gateway_title" type="text" class="regular-text" value="<?php echo esc_attr( $settings['title'] ); ?>">
				</div>

				<div class="wdcv-form-group">
					<label for="gateway_description">
						<?php esc_html_e( 'Description', 'webdide-card-to-card-verification' ); ?>
					</label>
					<textarea name="gateway_description" id="gateway_description" rows="3"><?php echo esc_textarea( $settings['description'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Payment method description shown on checkout.', 'webdide-card-to-card-verification' ); ?></p>
				</div>

				<div class="wdcv-form-group">
					<label for="gateway_thankyou_awaiting">
						<?php esc_html_e( 'Awaiting payment message', 'webdide-card-to-card-verification' ); ?>
					</label>
					<textarea name="gateway_thankyou_awaiting" id="gateway_thankyou_awaiting" rows="4"><?php echo esc_textarea( $settings['thankyou_awaiting_message'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown on the payment page while the transfer is pending confirmation.', 'webdide-card-to-card-verification' ); ?></p>
				</div>

				<div class="wdcv-form-group">
					<label for="gateway_thankyou_success">
						<?php esc_html_e( 'Payment success message', 'webdide-card-to-card-verification' ); ?>
					</label>
					<textarea name="gateway_thankyou_success" id="gateway_thankyou_success" rows="3"><?php echo esc_textarea( $settings['thankyou_success_message'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown after the payment has been confirmed.', 'webdide-card-to-card-verification' ); ?></p>
				</div>

				<div class="wdcv-form-group">
					<label>
						<?php esc_html_e( 'Payment page destination details', 'webdide-card-to-card-verification' ); ?>
						<?php self::render_tooltip( __( 'Choose which bank details customers see on the payment page. At least one option stays enabled.', 'webdide-card-to-card-verification' ) ); ?>
					</label>
					<label class="wdcv-checkbox-label">
						<input type="checkbox" name="gateway_show_card_number" value="1" <?php checked( $settings['show_card_number'], 'yes' ); ?>>
						<?php esc_html_e( 'Show card number', 'webdide-card-to-card-verification' ); ?>
					</label>
					<label class="wdcv-checkbox-label">
						<input type="checkbox" name="gateway_show_account_number" value="1" <?php checked( $settings['show_account_number'], 'yes' ); ?>>
						<?php esc_html_e( 'Show account number', 'webdide-card-to-card-verification' ); ?>
					</label>
					<label class="wdcv-checkbox-label">
						<input type="checkbox" name="gateway_show_sheba" value="1" <?php checked( $settings['show_sheba'], 'yes' ); ?>>
						<?php esc_html_e( 'Show Sheba (IBAN)', 'webdide-card-to-card-verification' ); ?>
					</label>
				</div>

				<p class="wdcv-actions">
					<button type="submit" class="wdcv-btn"><?php esc_html_e( 'Save gateway settings', 'webdide-card-to-card-verification' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * API tab.
	 */
	private static function render_tab_api( $api_secret, $api_secret_qr, $confirm_api_url, $status_api_url, $confirm_api_qr, $status_api_qr ) {
		?>
		<div class="wdcv-card">
			<h2><?php esc_html_e( 'Secret API (Private Key)', 'webdide-card-to-card-verification' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'wdcv_admin' ); self::render_tab_field( 'api' ); ?>
				<input type="hidden" name="shetab_action" value="save_secret">
				<div class="wdcv-form-group">
					<label>
						<?php esc_html_e( 'Key value:', 'webdide-card-to-card-verification' ); ?>
						<?php self::render_tooltip( __( 'This private key authenticates the Android app with your store. Keep it secret and never share it publicly.', 'webdide-card-to-card-verification' ) ); ?>
					</label>
					<input name="api_secret" type="text" class="regular-text" value="<?php echo esc_attr( $api_secret ); ?>">
					<p class="description"><?php echo esc_html( $api_secret ? __( 'A key is currently set.', 'webdide-card-to-card-verification' ) : __( 'No key has been set yet.', 'webdide-card-to-card-verification' ) ); ?></p>
				</div>
				<?php if ( $api_secret ) : ?>
					<div class="wdcv-api-info">
						<span><?php echo esc_html( $api_secret ); ?></span>
						<button type="button" class="wdcv-copy-btn" onclick="copyToClipboard('<?php echo esc_js( $api_secret ); ?>')"><?php esc_html_e( 'Copy to clipboard', 'webdide-card-to-card-verification' ); ?></button>
					</div>
					<div class="wdcv-qr-container">
						<div class="wdcv-qr-image">
							<?php if ( $api_secret_qr ) : ?>
								<img src="<?php echo esc_attr( $api_secret_qr ); ?>" alt="QR Secret" loading="lazy">
							<?php else : ?>
								<p class="description" style="margin:0; max-width: 180px;"><?php esc_html_e( 'QR preview is temporarily unavailable. Please use the copy button.', 'webdide-card-to-card-verification' ); ?></p>
							<?php endif; ?>
						</div>
						<span class="wdcv-qr-caption"><?php esc_html_e( 'Scan to copy the key', 'webdide-card-to-card-verification' ); ?></span>
					</div>
				<?php endif; ?>
				<p class="wdcv-actions"><button type="submit" class="wdcv-btn"><?php esc_html_e( 'Save secret key', 'webdide-card-to-card-verification' ); ?></button></p>
			</form>
		</div>
		<div class="wdcv-card">
			<h2><?php esc_html_e( 'API URL Addresses', 'webdide-card-to-card-verification' ); ?></h2>
			<div class="wdcv-form-group">
				<label><?php esc_html_e( 'Confirm Payment (POST):', 'webdide-card-to-card-verification' ); ?></label>
				<div class="wdcv-api-info">
					<code><?php echo esc_html( $confirm_api_url ); ?></code>
					<button type="button" class="wdcv-copy-btn" onclick="copyToClipboard('<?php echo esc_js( $confirm_api_url ); ?>')"><?php esc_html_e( 'Copy', 'webdide-card-to-card-verification' ); ?></button>
				</div>
				<div class="wdcv-qr-container wdcv-qr-inline">
					<?php if ( $confirm_api_qr ) : ?>
						<img class="wdcv-qr-image" src="<?php echo esc_attr( $confirm_api_qr ); ?>" width="100" alt="Confirm API QR" loading="lazy">
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'QR preview unavailable.', 'webdide-card-to-card-verification' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<div class="wdcv-form-group">
				<label><?php esc_html_e( 'Payment Status (GET):', 'webdide-card-to-card-verification' ); ?></label>
				<div class="wdcv-api-info">
					<code><?php echo esc_html( $status_api_url ); ?></code>
					<button type="button" class="wdcv-copy-btn" onclick="copyToClipboard('<?php echo esc_js( $status_api_url ); ?>')"><?php esc_html_e( 'Copy', 'webdide-card-to-card-verification' ); ?></button>
				</div>
				<div class="wdcv-qr-container wdcv-qr-inline">
					<?php if ( $status_api_qr ) : ?>
						<img class="wdcv-qr-image" src="<?php echo esc_attr( $status_api_qr ); ?>" width="100" alt="Status API QR" loading="lazy">
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'QR preview unavailable.', 'webdide-card-to-card-verification' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Cards tab.
	 *
	 * @param array $cards Cards.
	 */
	private static function render_tab_cards( $cards ) {
		$edit_id   = isset( $_GET['edit_card'] ) ? absint( $_GET['edit_card'] ) : 0;
		$edit_card = $edit_id ? WebDide_CV_DB::get_card( $edit_id ) : null;
		$is_edit   = (bool) $edit_card;
		$full_num     = ( $is_edit && ! empty( $edit_card->encrypted_number ) ) ? WebDide_CV_Utils::decrypt_card_number( $edit_card->encrypted_number ) : '';
		$full_account = ( $is_edit && ! empty( $edit_card->encrypted_account ) ) ? WebDide_CV_Utils::decrypt_card_number( $edit_card->encrypted_account ) : '';
		$full_sheba   = ( $is_edit && ! empty( $edit_card->encrypted_sheba ) ) ? WebDide_CV_Utils::decrypt_card_number( $edit_card->encrypted_sheba ) : '';
		?>
		<div class="wdcv-card" id="wdcv-card-form">
			<div class="wdcv-card-title-row">
				<h2>
					<?php
					echo $is_edit
						? esc_html( sprintf( /* translators: %d: card ID */ __( 'Edit bank card #%d', 'webdide-card-to-card-verification' ), $edit_card->id ) )
						: esc_html__( 'Add New Bank Card', 'webdide-card-to-card-verification' );
					?>
				</h2>
				<?php if ( $is_edit ) : ?>
					<a class="button" href="<?php echo esc_url( self::get_tab_url( 'cards' ) ); ?>"><?php esc_html_e( 'Cancel edit', 'webdide-card-to-card-verification' ); ?></a>
				<?php endif; ?>
			</div>
			<form method="post">
				<?php wp_nonce_field( 'wdcv_admin' ); self::render_tab_field( 'cards' ); ?>
				<input type="hidden" name="shetab_action" value="<?php echo $is_edit ? 'update_card' : 'add_card'; ?>">
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="card_id" value="<?php echo esc_attr( $edit_card->id ); ?>">
				<?php endif; ?>
				<div class="wdcv-form-group">
					<label for="label"><?php esc_html_e( 'Account holder / bank name:', 'webdide-card-to-card-verification' ); ?></label>
					<input name="label" id="label" type="text" required placeholder="<?php esc_attr_e( 'e.g. Mellat Bank — Reza HajRahimi', 'webdide-card-to-card-verification' ); ?>" value="<?php echo esc_attr( $is_edit ? $edit_card->label : '' ); ?>">
					<p class="description"><?php esc_html_e( 'Shown under card, account, and Sheba on the payment page.', 'webdide-card-to-card-verification' ); ?></p>
				</div>
				<div class="wdcv-form-group">
					<label for="card_number"><?php esc_html_e( '16-Digit Card Number:', 'webdide-card-to-card-verification' ); ?></label>
					<input name="card_number" id="card_number" type="text" maxlength="16" placeholder="0000000000000000" value="<?php echo esc_attr( $full_num ); ?>">
					<p class="description"><?php esc_html_e( 'Optional. Leave empty if customers pay only by account or Sheba. Empty clears a stored card number.', 'webdide-card-to-card-verification' ); ?></p>
				</div>
				<div class="wdcv-form-group">
					<label for="account_number"><?php esc_html_e( 'Account number:', 'webdide-card-to-card-verification' ); ?></label>
					<input name="account_number" id="account_number" type="text" inputmode="numeric" placeholder="<?php esc_attr_e( 'Optional bank account number', 'webdide-card-to-card-verification' ); ?>" value="<?php echo esc_attr( $full_account ); ?>">
					<p class="description"><?php esc_html_e( 'Digits only. Leave empty to clear.', 'webdide-card-to-card-verification' ); ?></p>
				</div>
				<div class="wdcv-form-group">
					<label for="sheba_number"><?php esc_html_e( 'Sheba (IBAN):', 'webdide-card-to-card-verification' ); ?></label>
					<input name="sheba_number" id="sheba_number" type="text" maxlength="26" class="wdcv-ltr" placeholder="IR000000000000000000000000" value="<?php echo esc_attr( $full_sheba ); ?>">
					<p class="description"><?php esc_html_e( 'IR followed by 24 digits. Leave empty to clear.', 'webdide-card-to-card-verification' ); ?></p>
				</div>
				<div class="wdcv-form-row">
					<div class="wdcv-form-group">
						<label for="max_deposits_count"><?php esc_html_e( 'Transaction Limit (Count):', 'webdide-card-to-card-verification' ); ?></label>
						<input name="max_deposits_count" id="max_deposits_count" type="number" min="0" value="<?php echo esc_attr( $is_edit ? $edit_card->max_deposits_count : 0 ); ?>">
						<p class="description"><?php esc_html_e( '0 means unlimited', 'webdide-card-to-card-verification' ); ?></p>
					</div>
					<div class="wdcv-form-group">
						<label for="max_total_amount"><?php esc_html_e( 'Total Amount Limit (Toman):', 'webdide-card-to-card-verification' ); ?></label>
						<input name="max_total_amount" id="max_total_amount" type="number" min="0" value="<?php echo esc_attr( $is_edit ? $edit_card->max_total_amount : 0 ); ?>">
						<p class="description"><?php esc_html_e( '0 means unlimited', 'webdide-card-to-card-verification' ); ?></p>
					</div>
					<div class="wdcv-form-group">
						<label for="reset_period"><?php esc_html_e( 'Limit Reset Period:', 'webdide-card-to-card-verification' ); ?></label>
						<select name="reset_period" id="reset_period">
							<option value="none" <?php selected( $is_edit ? $edit_card->reset_period : 'none', 'none' ); ?>><?php esc_html_e( 'No Reset (Forever)', 'webdide-card-to-card-verification' ); ?></option>
							<option value="daily" <?php selected( $is_edit ? $edit_card->reset_period : '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'webdide-card-to-card-verification' ); ?></option>
							<option value="monthly" <?php selected( $is_edit ? $edit_card->reset_period : '', 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'webdide-card-to-card-verification' ); ?></option>
						</select>
					</div>
				</div>
				<div class="wdcv-form-group">
					<label class="wdcv-checkbox-label">
						<input name="active" type="checkbox" <?php checked( $is_edit ? (int) $edit_card->active : 1, 1 ); ?>>
						<?php esc_html_e( 'Card is active', 'webdide-card-to-card-verification' ); ?>
					</label>
				</div>
				<button type="submit" class="wdcv-btn">
					<?php echo $is_edit ? esc_html__( 'Save card changes', 'webdide-card-to-card-verification' ) : esc_html__( 'Add Card', 'webdide-card-to-card-verification' ); ?>
				</button>
			</form>
		</div>
		<div class="wdcv-card">
			<h2><?php esc_html_e( 'Existing Cards', 'webdide-card-to-card-verification' ); ?></h2>
			<div class="wdcv-table-wrap">
				<table class="wdcv-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Label', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Full Card Number', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Account', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Sheba', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Limits (Count/Amount)', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Current Usage', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Status', 'webdide-card-to-card-verification' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'webdide-card-to-card-verification' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $cards ) ) : ?>
							<tr><td colspan="9" class="wdcv-empty"><?php esc_html_e( 'No cards have been configured yet.', 'webdide-card-to-card-verification' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $cards as $c ) : ?>
								<?php
								$full_number = ! empty( $c->encrypted_number ) ? WebDide_CV_Utils::decrypt_card_number( $c->encrypted_number ) : '';
								$usage       = WebDide_CV_DB::get_card_usage( $c->id, $c->reset_period );
								$edit_url    = add_query_arg( 'edit_card', (int) $c->id, self::get_tab_url( 'cards' ) ) . '#wdcv-card-form';
								?>
								<tr<?php echo ( $is_edit && (int) $edit_card->id === (int) $c->id ) ? ' class="wdcv-row-editing"' : ''; ?>>
									<td><?php echo esc_html( $c->id ); ?></td>
									<td><?php echo esc_html( $c->label ); ?></td>
									<td>
										<?php if ( $full_number ) : ?>
											<div class="wdcv-ltr">
												<?php echo esc_html( $full_number ); ?>
												<button type="button" class="wdcv-copy-btn wdcv-copy-btn-sm" onclick="copyToClipboard('<?php echo esc_js( $full_number ); ?>')"><?php esc_html_e( 'Copy', 'webdide-card-to-card-verification' ); ?></button>
											</div>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td class="wdcv-ltr"><?php echo esc_html( ! empty( $c->masked_account ) ? $c->masked_account : '—' ); ?></td>
									<td class="wdcv-ltr"><?php echo esc_html( ! empty( $c->masked_sheba ) ? $c->masked_sheba : '—' ); ?></td>
									<td><?php echo esc_html( $c->max_deposits_count ? $c->max_deposits_count : '∞' ); ?> / <?php echo esc_html( $c->max_total_amount ? number_format_i18n( $c->max_total_amount ) : '∞' ); ?></td>
									<td>
										<strong><?php esc_html_e( 'Txns:', 'webdide-card-to-card-verification' ); ?></strong> <?php echo esc_html( $usage['count'] ); ?><br>
										<strong><?php esc_html_e( 'Sum:', 'webdide-card-to-card-verification' ); ?></strong> <?php echo esc_html( number_format_i18n( $usage['total'] ) ); ?> <?php esc_html_e( 'Tomans', 'webdide-card-to-card-verification' ); ?>
										<?php if ( $usage['count'] > 0 ) : ?>
											<div class="wdcv-mt-5">
												<button type="button" class="wdcv-link-btn" data-orders="<?php echo esc_attr( wp_json_encode( $usage['orders'] ) ); ?>" data-card-label="<?php echo esc_attr( $c->label ); ?>" onclick="showCardOrdersFromData(this)">
													<?php printf( esc_html__( 'View Details (%d orders)', 'webdide-card-to-card-verification' ), (int) $usage['count'] ); ?>
												</button>
											</div>
										<?php endif; ?>
									</td>
									<td><?php echo $c->active ? '<span class="wdcv-status-on">✅ ' . esc_html__( 'Active', 'webdide-card-to-card-verification' ) . '</span>' : '<span class="wdcv-status-off">❌ ' . esc_html__( 'Inactive', 'webdide-card-to-card-verification' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td>
										<div class="wdcv-row-actions">
											<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'webdide-card-to-card-verification' ); ?></a>
											<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to remove this card?', 'webdide-card-to-card-verification' ) ); ?>');">
												<?php wp_nonce_field( 'wdcv_admin' ); self::render_tab_field( 'cards' ); ?>
												<input type="hidden" name="shetab_action" value="delete_card">
												<input type="hidden" name="delete_card" value="<?php echo esc_attr( $c->id ); ?>">
												<button type="submit" class="wdcv-copy-btn wdcv-btn-danger"><?php esc_html_e( 'Delete', 'webdide-card-to-card-verification' ); ?></button>
											</form>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Bots tab.
	 */
	private static function render_tab_bots() {
		$channels = array(
			'telegram' => __( 'Telegram', 'webdide-card-to-card-verification' ),
			'bale'     => __( 'Bale', 'webdide-card-to-card-verification' ),
		);
		?>
		<div class="wdcv-card">
			<div class="wdcv-card-title-row">
				<h2><?php esc_html_e( 'Telegram & Bale bots', 'webdide-card-to-card-verification' ); ?></h2>
				<div class="wdcv-help-actions">
					<?php
					self::render_help_button( 'telegram', __( 'Telegram setup guide', 'webdide-card-to-card-verification' ) );
					self::render_help_button( 'bale', __( 'Bale setup guide', 'webdide-card-to-card-verification' ) );
					?>
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'When a customer uploads a payment receipt, enabled bots receive the image with Approve / Reject buttons. On reject, the bot asks for an optional note.', 'webdide-card-to-card-verification' ); ?></p>
			<div class="wdcv-callout">
				<strong><?php esc_html_e( 'Quick checklist', 'webdide-card-to-card-verification' ); ?></strong>
				<ol>
					<li><?php esc_html_e( 'Create a bot and copy its token.', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php esc_html_e( 'Start a chat with the bot and get your numeric chat ID.', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php esc_html_e( 'Save settings below, then click Set webhook.', 'webdide-card-to-card-verification' ); ?></li>
				</ol>
			</div>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'wdcv_admin' ); self::render_tab_field( 'bots' ); ?>
			<input type="hidden" name="shetab_action" value="save_bot_settings">

			<?php foreach ( $channels as $channel => $label ) : ?>
				<div class="wdcv-card wdcv-bot-card">
					<div class="wdcv-card-title-row">
						<h3><?php echo esc_html( $label ); ?></h3>
						<?php self::render_help_button( $channel, sprintf( /* translators: %s: Telegram or Bale */ __( 'How to set up %s', 'webdide-card-to-card-verification' ), $label ) ); ?>
					</div>
					<div class="wdcv-form-group">
						<label>
							<input type="checkbox" name="bot_<?php echo esc_attr( $channel ); ?>_enabled" value="1" <?php checked( get_option( 'wdcv_bot_' . $channel . '_enabled', 'no' ), 'yes' ); ?>>
							<?php
							printf(
								/* translators: %s: Telegram or Bale */
								esc_html__( 'Enable %s bot notifications', 'webdide-card-to-card-verification' ),
								esc_html( $label )
							);
							?>
						</label>
					</div>
					<div class="wdcv-form-group">
						<label>
							<?php esc_html_e( 'Bot token:', 'webdide-card-to-card-verification' ); ?>
							<?php self::render_tooltip( __( 'Paste the token you received when creating the bot (for example from BotFather).', 'webdide-card-to-card-verification' ) ); ?>
						</label>
						<input name="bot_<?php echo esc_attr( $channel ); ?>_token" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'wdcv_bot_' . $channel . '_token', '' ) ); ?>" autocomplete="off">
					</div>
					<div class="wdcv-form-group">
						<label>
							<?php esc_html_e( 'Allowed chat IDs (comma-separated):', 'webdide-card-to-card-verification' ); ?>
							<?php self::render_tooltip( __( 'Numeric IDs of managers who may approve or reject receipts. Separate multiple IDs with commas.', 'webdide-card-to-card-verification' ) ); ?>
						</label>
						<input name="bot_<?php echo esc_attr( $channel ); ?>_chat_ids" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'wdcv_bot_' . $channel . '_chat_ids', '' ) ); ?>" placeholder="123456789">
						<p class="description"><?php esc_html_e( 'Only these chat IDs can approve or reject receipts.', 'webdide-card-to-card-verification' ); ?></p>
					</div>
					<div class="wdcv-form-group">
						<label>
							<?php esc_html_e( 'Webhook URL:', 'webdide-card-to-card-verification' ); ?>
							<?php self::render_tooltip( __( 'This HTTPS endpoint receives Approve/Reject actions from the bot. Set it with the button below after saving the token.', 'webdide-card-to-card-verification' ) ); ?>
						</label>
						<div class="wdcv-api-info">
							<code><?php echo esc_html( WebDide_CV_Bot_Webhook::get_webhook_url( $channel ) ); ?></code>
							<button type="button" class="wdcv-copy-btn" onclick="copyToClipboard('<?php echo esc_js( WebDide_CV_Bot_Webhook::get_webhook_url( $channel ) ); ?>')"><?php esc_html_e( 'Copy', 'webdide-card-to-card-verification' ); ?></button>
						</div>
					</div>
				</div>
			<?php endforeach; ?>

			<p class="wdcv-actions">
				<button type="submit" class="wdcv-btn"><?php esc_html_e( 'Save bot settings', 'webdide-card-to-card-verification' ); ?></button>
			</p>
		</form>

		<form method="post" class="wdcv-webhook-actions">
			<?php wp_nonce_field( 'wdcv_admin' ); self::render_tab_field( 'bots' ); ?>
			<input type="hidden" name="shetab_action" value="set_bot_webhook">
			<button type="submit" name="bot_channel" value="telegram" class="button button-secondary"><?php esc_html_e( 'Set Telegram webhook', 'webdide-card-to-card-verification' ); ?></button>
			<button type="submit" name="bot_channel" value="bale" class="button button-secondary"><?php esc_html_e( 'Set Bale webhook', 'webdide-card-to-card-verification' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Support tab.
	 */
	private static function render_tab_support() {
		?>
		<div class="wdcv-card">
			<h2><?php esc_html_e( 'Support Information', 'webdide-card-to-card-verification' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These contacts are shown to customers on the payment instructions page. They are separate from the admin Telegram/Bale bots.', 'webdide-card-to-card-verification' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'wdcv_admin' ); self::render_tab_field( 'support' ); ?>
				<input type="hidden" name="shetab_action" value="save_support_info">
				<div class="wdcv-form-group">
					<label><?php esc_html_e( 'WhatsApp ID (e.g. 989123456789):', 'webdide-card-to-card-verification' ); ?></label>
					<input name="support_whatsapp" type="text" value="<?php echo esc_attr( get_option( 'wdcv_support_whatsapp' ) ); ?>" placeholder="989...">
				</div>
				<div class="wdcv-form-group">
					<label><?php esc_html_e( 'Telegram ID:', 'webdide-card-to-card-verification' ); ?></label>
					<input name="support_telegram" type="text" value="<?php echo esc_attr( get_option( 'wdcv_support_telegram' ) ); ?>" placeholder="@username">
					<p class="description"><?php esc_html_e( 'Public support username shown to customers (not the admin bot).', 'webdide-card-to-card-verification' ); ?></p>
				</div>
				<div class="wdcv-form-group">
					<label><?php esc_html_e( 'Manager Note for Users:', 'webdide-card-to-card-verification' ); ?></label>
					<textarea name="support_manager_text" rows="4"><?php echo esc_textarea( get_option( 'wdcv_support_manager_text' ) ); ?></textarea>
				</div>
				<button type="submit" class="wdcv-btn"><?php esc_html_e( 'Save Support Info', 'webdide-card-to-card-verification' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Setup guide modals for Telegram and Bale.
	 */
	private static function render_help_modals() {
		?>
		<div id="wdcv-guide-telegram" class="wdcv-modal wdcv-guide-modal" hidden>
			<div class="wdcv-modal-content wdcv-guide-content">
				<div class="wdcv-modal-header">
					<h3 class="wdcv-modal-title"><?php esc_html_e( 'Telegram bot setup', 'webdide-card-to-card-verification' ); ?></h3>
					<button type="button" class="wdcv-close wdcv-guide-close" aria-label="<?php esc_attr_e( 'Close', 'webdide-card-to-card-verification' ); ?>">&times;</button>
				</div>
				<ol class="wdcv-guide-steps">
					<li><?php echo wp_kses_post( __( 'Open Telegram and search for <strong>@BotFather</strong>.', 'webdide-card-to-card-verification' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Send <code>/newbot</code>, choose a name and a username ending with <code>bot</code>.', 'webdide-card-to-card-verification' ) ); ?></li>
					<li><?php esc_html_e( 'Copy the bot token BotFather gives you and paste it into the Bot token field.', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php esc_html_e( 'Open your new bot and press Start (or send any message).', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php echo wp_kses_post( __( 'Get your numeric chat ID with a helper bot such as <strong>@userinfobot</strong> or <strong>@getidsbot</strong>, then paste it into Allowed chat IDs.', 'webdide-card-to-card-verification' ) ); ?></li>
					<li><?php esc_html_e( 'Enable the Telegram bot, click Save bot settings, then click Set Telegram webhook.', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php esc_html_e( 'Upload a test receipt on an order. You should receive the image with Approve and Reject buttons.', 'webdide-card-to-card-verification' ); ?></li>
				</ol>
				<p class="wdcv-guide-note"><?php esc_html_e( 'Webhook requires a public HTTPS URL. On local development, expose your site with a tunnel (for example ngrok) and use that base URL.', 'webdide-card-to-card-verification' ); ?></p>
			</div>
		</div>

		<div id="wdcv-guide-bale" class="wdcv-modal wdcv-guide-modal" hidden>
			<div class="wdcv-modal-content wdcv-guide-content">
				<div class="wdcv-modal-header">
					<h3 class="wdcv-modal-title"><?php esc_html_e( 'Bale bot setup', 'webdide-card-to-card-verification' ); ?></h3>
					<button type="button" class="wdcv-close wdcv-guide-close" aria-label="<?php esc_attr_e( 'Close', 'webdide-card-to-card-verification' ); ?>">&times;</button>
				</div>
				<ol class="wdcv-guide-steps">
					<li><?php echo wp_kses_post( __( 'Open Bale (بله) and find the official bot creator / BotFather equivalent for Bale.', 'webdide-card-to-card-verification' ) ); ?></li>
					<li><?php esc_html_e( 'Create a new bot and copy the token provided for your bot.', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php esc_html_e( 'Paste the token into the Bale Bot token field in this plugin.', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php esc_html_e( 'Start a conversation with your bot from the manager account that should approve receipts.', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php echo wp_kses_post( __( 'Get your numeric account/chat ID from the Bale bot <a href="https://ble.ir/idbotbot" target="_blank" rel="noopener noreferrer"><strong>@Idbotbot</strong></a>, then enter it in Allowed chat IDs.', 'webdide-card-to-card-verification' ) ); ?></li>
					<li><?php esc_html_e( 'Enable the Bale bot, save settings, then click Set Bale webhook.', 'webdide-card-to-card-verification' ); ?></li>
					<li><?php esc_html_e( 'Test by uploading a receipt; approve or reject from Bale. On reject you can send a note or /skip.', 'webdide-card-to-card-verification' ); ?></li>
				</ol>
				<p class="wdcv-guide-note"><?php esc_html_e( 'Bale uses an API compatible with Telegram bots (tapi.bale.ai). Your WordPress site still needs a public HTTPS address for webhooks.', 'webdide-card-to-card-verification' ); ?></p>
			</div>
		</div>
		<?php
	}
}
