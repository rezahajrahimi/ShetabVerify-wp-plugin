<?php
/**
 * Standalone card-to-card payment page (bank-like intermediate step).
 *
 * Expects $order (WC_Order) to be in scope from WebDide_CV_Gateway::handle_pay_page().
 *
 * @package WebDide_CV
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
	wp_die( esc_html__( 'Invalid order.', 'webdide-card-to-card-verification' ) );
}

$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
$lang_attr = function_exists( 'get_language_attributes' ) ? get_language_attributes() : 'lang="fa" dir="rtl"';
?>
<!DOCTYPE html>
<html <?php echo $lang_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( sprintf( __( 'Payment — %s', 'webdide-card-to-card-verification' ), $site_name ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="wdcv-pay-page">
	<main class="wdcv-pay-shell">
		<header class="wdcv-pay-header">
			<p class="wdcv-pay-brand"><?php echo esc_html( $site_name ); ?></p>
			<h1 class="wdcv-pay-title"><?php esc_html_e( 'Complete your payment', 'webdide-card-to-card-verification' ); ?></h1>
			<p class="wdcv-pay-order-ref">
				<?php
				printf(
					/* translators: %s: order number */
					esc_html__( 'Order #%s', 'webdide-card-to-card-verification' ),
					esc_html( $order->get_order_number() )
				);
				?>
			</p>
		</header>

		<?php WebDide_CV_Gateway::render_pay_content( $order ); ?>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
