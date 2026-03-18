<?php
ob_start();
/**
 * Plugin Name: BA Himalayan Bank Payment Gateway
 * Description: Himalayan Bank (2C2P PACO) payment gateway for BA Book Everything.
 * Version: 1.0.4
 * Author: Surox and Manoj
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ba-himalayan-bank-payment-gateway
 * Requires PHP: 8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BA_HBL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BA_HBL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BA_HBL_OPTION_NAME', 'ba_hbl_gateway_settings' );
define( 'BA_HBL_PAYMENT_METHOD', 'hbl_paco' );

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/SecurityData.php';
require_once __DIR__ . '/src/ActionRequest.php';
require_once __DIR__ . '/src/Payment.php';

use Nexhbp\HimalayanBank\Payment;
use Nexhbp\HimalayanBank\SecurityData;

register_activation_hook( __FILE__, 'ba_hbl_activate' );
/**
 * On activation, create default return pages (if not set).
 */
function ba_hbl_activate(): void {
	if ( ! function_exists( 'wp_insert_post' ) ) {
		return;
	}

	$settings = ba_hbl_get_settings();

	$pages = array(
		'success' => array(
			'option_id' => 'success_page_id',
			'title'     => __( 'Payment Successful', 'ba-himalayan-bank-payment-gateway' ),
			'slug'      => 'hbl-payment-success',
			'content'   => __( 'Your payment was successful. You can now return to the booking page.', 'ba-himalayan-bank-payment-gateway' ),
			'url_key'   => 'success_url',
		),
		'failed'  => array(
			'option_id' => 'failed_page_id',
			'title'     => __( 'Payment Failed', 'ba-himalayan-bank-payment-gateway' ),
			'slug'      => 'hbl-payment-failed',
			'content'   => __( 'Your payment was not successful. Please try again or contact support.', 'ba-himalayan-bank-payment-gateway' ),
			'url_key'   => 'failed_url',
		),
		'cancel'  => array(
			'option_id' => 'cancel_page_id',
			'title'     => __( 'Payment Cancelled', 'ba-himalayan-bank-payment-gateway' ),
			'slug'      => 'hbl-payment-cancelled',
			'content'   => __( 'You cancelled the payment. You can return to the booking page and try again.', 'ba-himalayan-bank-payment-gateway' ),
			'url_key'   => 'cancel_url',
		),
	);

	foreach ( $pages as $page ) {
		$existing_id = absint( $settings[ $page['option_id'] ] ?? 0 );
		if ( $existing_id && get_post_status( $existing_id ) ) {
			continue;
		}

		$found = get_page_by_path( $page['slug'] );
		if ( $found instanceof WP_Post ) {
			$page_id = (int) $found->ID;
		} else {
			$page_id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => $page['content'],
				),
				true
			);
			if ( is_wp_error( $page_id ) ) {
				continue;
			}
		}

		$settings[ $page['option_id'] ] = $page_id;
		if ( empty( $settings[ $page['url_key'] ] ) ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) {
				$settings[ $page['url_key'] ] = $permalink;
			}
		}
	}

	update_option( BA_HBL_OPTION_NAME, $settings );
}

add_action( 'admin_notices', 'ba_hbl_ba_missing_notice' );
function ba_hbl_ba_missing_notice(): void {
	if ( ! class_exists( 'BABE_Payments' ) ) {
		echo '<div class="error"><p>' .
			esc_html__( 'BA Himalayan Bank Payment Gateway requires BA Book Everything to be installed and active.', 'ba-himalayan-bank-payment-gateway' ) .
			'</p></div>';
	}
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ba_hbl_add_settings_link' );
function ba_hbl_add_settings_link( $links ) {
	$url           = admin_url( 'admin.php?page=ba-hbl-gateway-settings' );
	$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ba-himalayan-bank-payment-gateway' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

add_action( 'plugins_loaded', 'ba_hbl_gateway_init' );
function ba_hbl_gateway_init(): void {
	if ( ! class_exists( 'BABE_Payments' ) ) {
		return;
	}

	load_plugin_textdomain(
		'ba-himalayan-bank-payment-gateway',
		false,
		basename( __DIR__ ) . '/languages'
	);

	$method = BA_HBL_PAYMENT_METHOD;

	add_action( 'babe_init_payment_methods', function () use ( $method ) {
		if ( ! isset( BABE_Payments::$payment_methods[ $method ] ) ) {
			BABE_Payments::add_payment_method( $method, __( 'HBL Online Payment', 'ba-himalayan-bank-payment-gateway' ) );
		}
	} );

	add_filter( 'babe_checkout_payment_title_' . $method, function () {
		$s = ba_hbl_get_settings();
		return ! empty( $s['checkout_title'] ) ? $s['checkout_title'] : __( 'HBL Online Payment', 'ba-himalayan-bank-payment-gateway' );
	} );

	add_filter( 'babe_checkout_payment_description_' . $method, function () {
		$s = ba_hbl_get_settings();
		return ! empty( $s['checkout_description'] )
			? wp_kses_post( $s['checkout_description'] )
			: __( 'Secure online payment via Himalayan Bank.', 'ba-himalayan-bank-payment-gateway' );
	} );

	add_action( 'babe_order_start_paying_with_' . $method, 'ba_hbl_order_to_pay', 10, 5 );
	add_action( 'babe_payment_server_' . $method . '_response', 'ba_hbl_handle_ipn' );

	if ( is_admin() ) {
		add_action( 'admin_menu', 'ba_hbl_admin_menu' );
		add_action( 'admin_init', 'ba_hbl_register_settings' );
		add_action( 'admin_post_ba_hbl_refund', 'ba_hbl_process_refund' );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( strpos( $hook, 'ba-hbl-gateway' ) !== false ) {
				wp_enqueue_script(
					'ba-hbl-admin',
					BA_HBL_PLUGIN_URL . 'assets/admin.js',
					array(),
					'1.0.0',
					true
				);
			}
		} );
	}
}

/**
 * Get settings with defaults.
 */
function ba_hbl_get_settings(): array {
	$defaults = array(
		'checkout_title'                => '',
		'checkout_description'          => '',
		'3d_secure'                     => 'yes',
		// Return / notification URLs (optional overrides).
		'success_url'                   => '',
		'failed_url'                    => '',
		'cancel_url'                    => '',
		'backend_url'                   => '',
		// Default pages created on activation (used if URLs are empty).
		'success_page_id'               => 0,
		'failed_page_id'                => 0,
		'cancel_page_id'                => 0,
		'merchant_id'                   => '',
		'encryption_key'                => '',
		'access_token'                  => '',
		'merchant_sign_private_key'     => '',
		'merchant_sign_public_key'      => '',
		'merchant_decrypt_private_key'  => '',
		'merchant_encrypt_public_key'   => '',
		'debug_mode'                    => 'no',
	);
	$settings = get_option( BA_HBL_OPTION_NAME, array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	return wp_parse_args( $settings, $defaults );
}

/**
 * Start payment: build PACO session and redirect to hosted UI.
 */
function ba_hbl_order_to_pay( $order_id, $args, $current_url, $success_url, $customer_id = 0 ): void {
	$settings = ba_hbl_get_settings();

	if ( empty( $settings['access_token'] ) ) {
		wp_die( esc_html__( 'HBL Gateway is not configured. Please contact site administrator.', 'ba-himalayan-bank-payment-gateway' ) );
	}

	$order_total = BABE_Order::get_order_total_amount( $order_id );
	$currency    = BABE_Order::get_order_currency( $order_id );
	$order_num   = BABE_Order::get_order_number( $order_id );
	$order_hash  = BABE_Order::get_order_hash( $order_id );

	if ( $order_total <= 0 ) {
		wp_safe_redirect( $success_url );
		exit;
	}

	$return_base = '';

	$url_args = array(
		'order_id'       => $order_id,
		'order_num'      => $order_num,
		'order_hash'     => $order_hash,
		'current_action' => 'to_confirm',
	);

	$confirmation_url = BABE_Settings::get_confirmation_page_url( $url_args );
	$backend_url      = BABE_Payments::get_payment_server_response_page_url( BA_HBL_PAYMENT_METHOD );

	// Optional URL overrides from settings.
	$success_override = ! empty( $settings['success_url'] ) ? $settings['success_url'] : '';
	$failed_override  = ! empty( $settings['failed_url'] ) ? $settings['failed_url'] : '';
	$cancel_override  = ! empty( $settings['cancel_url'] ) ? $settings['cancel_url'] : '';
	$backend_override = ! empty( $settings['backend_url'] ) ? $settings['backend_url'] : '';

	if ( $return_base !== '' ) {
		// Only rewrite auto-generated URLs; do not rewrite explicit overrides.
		if ( $success_override === '' && $failed_override === '' && $cancel_override === '' ) {
			$frontend_path  = parse_url( $confirmation_url, PHP_URL_PATH );
			$frontend_query = parse_url( $confirmation_url, PHP_URL_QUERY );
			$confirmation_url = $return_base . ( $frontend_path ?: '/confirmation/' )
				. ( $frontend_query ? '?' . $frontend_query : '?' . http_build_query( $url_args ) );
		}

		if ( $backend_override === '' ) {
			$backend_path = parse_url( $backend_url, PHP_URL_PATH );
			$backend_url  = $return_base . ( $backend_path ?: '/babe-api/ipn_' . BA_HBL_PAYMENT_METHOD );
		}
	}

	$success_url_send = $success_override ?: $confirmation_url;
	$failed_url_send  = $failed_override ?: $confirmation_url;
	$cancel_url_send  = $cancel_override ?: $confirmation_url;
	$backend_url_send = $backend_override ?: $backend_url;

	// Use BA Book Everything base currency (site-wide currency setting).
	$currency_code = class_exists( 'BABE_Currency' )
		? ( BABE_Currency::get_currency() ?: ( $currency ?: 'NPR' ) )
		: ( $currency ?: 'NPR' );
	$threeD        = ( $settings['3d_secure'] === 'yes' ) ? 'Y' : 'N';

	$purchase_items = array(
		array(
			'purchaseItemType'        => 'ticket',
			'referenceNo'             => (string) $order_id,
			'purchaseItemDescription' => sprintf( 'Booking #%s', $order_num ),
			'purchaseItemPrice'       => array(
				'amountText'    => str_pad( (string) ( (int) round( $order_total * 100 ) ), 12, '0', STR_PAD_LEFT ),
				'currencyCode'  => $currency_code,
				'decimalPlaces' => 2,
				'amount'        => $order_total,
			),
			'subMerchantID'  => '',
			'passengerSeqNo' => 1,
		),
	);

	try {
		SecurityData::init_from_settings( $settings );

		$payment = new Payment();
		$request = $payment->ExecuteFormJose(
			$order_num,
			$currency_code,
			$order_total,
			$threeD,
			$success_url_send,
			$failed_url_send,
			$cancel_url_send,
			$backend_url_send,
			$purchase_items
		);

		$result = json_decode( $request, false );

		$paymentUrl = '';
		if ( isset( $result->response->Data->paymentPage->paymentPageURL ) ) {
			$paymentUrl = $result->response->Data->paymentPage->paymentPageURL;
		} elseif ( isset( $result->request->webPaymentUrl ) ) {
			$paymentUrl = $result->request->webPaymentUrl;
		} elseif ( isset( $result->request->paymentUrl ) ) {
			$paymentUrl = $result->request->paymentUrl;
		} elseif ( isset( $result->webPaymentUrl ) ) {
			$paymentUrl = $result->webPaymentUrl;
		} elseif ( isset( $result->paymentUrl ) ) {
			$paymentUrl = $result->paymentUrl;
		}

		if ( empty( $paymentUrl ) ) {
			wp_die(
				esc_html__( 'PACO did not return a payment URL. Please contact site administrator.', 'ba-himalayan-bank-payment-gateway' ),
				'',
				array( 'response' => 500 )
			);
		}

		wp_redirect( $paymentUrl );
		exit;

	} catch ( \GuzzleHttp\Exception\ClientException $e ) {
		$responseBody = $e->getResponse()->getBody()->getContents();
		wp_die(
			esc_html( $responseBody ),
			'',
			array( 'response' => 500 )
		);
	} catch ( \Exception $e ) {
		wp_die(
			esc_html( $e->getMessage() ) . ' — ' . esc_html__( 'Please contact site administrator.', 'ba-himalayan-bank-payment-gateway' ),
			'',
			array( 'response' => 500 )
		);
	}
}

/**
 * Handle IPN (backend callback from PACO).
 */
function ba_hbl_handle_ipn(): void {
	$settings = ba_hbl_get_settings();
	SecurityData::init_from_settings( $settings );

	$raw = file_get_contents( 'php://input' );

	if ( empty( $raw ) ) {
		$raw = isset( $_GET['paymentResponse'] ) ? sanitize_text_field( wp_unslash( $_GET['paymentResponse'] ) ) : '';
	}

	if ( empty( $raw ) ) {
		status_header( 400 );
		exit( 'No payment data received.' );
	}

	try {
		$payment   = new Payment();
		$decrypted = $payment->DecryptTokenPublic( $raw );
		$data      = json_decode( $decrypted, true );

	} catch ( \Exception $e ) {
		status_header( 400 );
		exit( 'Could not decrypt IPN.' );
	}

	$order_no       = '';
	$transaction_id = '';
	$resp_code      = '';
	$amount         = 0;

	if ( isset( $data['request'] ) ) {
		$req            = $data['request'];
		$order_no       = $req['orderNo'] ?? ( $req['invoiceNo'] ?? '' );
		$transaction_id = $req['controllerInternalId'] ?? ( $req['tranRef'] ?? '' );
		$resp_code      = $req['respCode'] ?? '';
		if ( isset( $req['transactionAmount']['amount'] ) ) {
			$amount = (float) $req['transactionAmount']['amount'];
		} elseif ( isset( $req['amount'] ) ) {
			$amount = (float) $req['amount'];
		}
	} elseif ( isset( $data['orderNo'] ) ) {
		$order_no       = $data['orderNo'];
		$transaction_id = $data['controllerInternalId'] ?? ( $data['tranRef'] ?? '' );
		$resp_code      = $data['respCode'] ?? '';
		$amount         = isset( $data['amount'] ) ? (float) $data['amount'] : 0;
	}

	if ( empty( $order_no ) ) {
		status_header( 400 );
		exit( 'Missing orderNo.' );
	}

	$order_id = ba_hbl_find_order_by_number( $order_no );
	if ( ! $order_id ) {
		status_header( 404 );
		exit( 'Order not found.' );
	}

	$is_success = ( $resp_code === '00' || $resp_code === '0000' || $resp_code === '' );

	if ( $is_success && $amount > 0 ) {
		$currency = BABE_Order::get_order_currency( $order_id );
		BABE_Payments::do_complete_order(
			$order_id,
			BA_HBL_PAYMENT_METHOD,
			$transaction_id,
			$amount,
			$currency
		);
		update_post_meta( $order_id, '_hbl_transaction_id', sanitize_text_field( $transaction_id ) );

	} else {
	}

	status_header( 200 );
	echo 'OK';
	exit;
}

/**
 * Find BA order ID by order number.
 */
function ba_hbl_find_order_by_number( string $order_num ): int {
	if ( is_numeric( $order_num ) ) {
		$order_id = absint( $order_num );
		if ( get_post_type( $order_id ) === 'to_book' || get_post_type( $order_id ) === 'babe_order' ) {
			return $order_id;
		}
	}

	global $wpdb;
	$post_type = class_exists( 'BABE_Post_types' ) && ! empty( BABE_Post_types::$order_post_type )
		? BABE_Post_types::$order_post_type
		: 'to_book';

	$found = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_order_number' AND meta_value = %s LIMIT 1",
		$order_num
	) );

	return $found ? absint( $found ) : 0;
}

// ──────────────────────────────────────────────
// Admin: Menu, Settings page, Transactions, Refund
// ──────────────────────────────────────────────

function ba_hbl_admin_menu(): void {
	add_menu_page(
		__( 'HBL PACO', 'ba-himalayan-bank-payment-gateway' ),
		__( 'HBL PACO', 'ba-himalayan-bank-payment-gateway' ),
		'manage_options',
		'ba-hbl-gateway-transactions',
		'ba_hbl_transactions_page',
		'dashicons-money-alt',
		58
	);

	add_submenu_page(
		'ba-hbl-gateway-transactions',
		__( 'Transactions', 'ba-himalayan-bank-payment-gateway' ),
		__( 'Transactions', 'ba-himalayan-bank-payment-gateway' ),
		'manage_options',
		'ba-hbl-gateway-transactions',
		'ba_hbl_transactions_page'
	);

	add_submenu_page(
		'ba-hbl-gateway-transactions',
		__( 'Settings', 'ba-himalayan-bank-payment-gateway' ),
		__( 'Settings', 'ba-himalayan-bank-payment-gateway' ),
		'manage_options',
		'ba-hbl-gateway-settings',
		'ba_hbl_settings_page'
	);
}

function ba_hbl_register_settings(): void {
	register_setting(
		'ba_hbl_gateway_settings_group',
		BA_HBL_OPTION_NAME,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'ba_hbl_sanitize_settings',
			'default'           => array(),
		)
	);
}

function ba_hbl_sanitize_settings( $input ): array {
	$output = array();

	$text_fields = array(
		'checkout_title', 'checkout_description',
		'merchant_id', 'encryption_key', 'access_token',
	);
	foreach ( $text_fields as $f ) {
		$output[ $f ] = isset( $input[ $f ] ) ? sanitize_text_field( trim( $input[ $f ] ) ) : '';
	}

	$url_fields = array( 'success_url', 'failed_url', 'cancel_url', 'backend_url' );
	foreach ( $url_fields as $f ) {
		$output[ $f ] = isset( $input[ $f ] ) ? esc_url_raw( trim( $input[ $f ] ), array( 'http', 'https' ) ) : '';
	}

	$page_id_fields = array( 'success_page_id', 'failed_page_id', 'cancel_page_id' );
	foreach ( $page_id_fields as $f ) {
		$output[ $f ] = isset( $input[ $f ] ) ? absint( $input[ $f ] ) : 0;
	}

	$textarea_fields = array(
		'merchant_sign_private_key', 'merchant_decrypt_private_key',
		'merchant_sign_public_key', 'merchant_encrypt_public_key',
	);
	foreach ( $textarea_fields as $f ) {
		$output[ $f ] = isset( $input[ $f ] ) ? trim( $input[ $f ] ) : '';
	}

	$checkbox_fields = array( '3d_secure', 'debug_mode' );
	foreach ( $checkbox_fields as $f ) {
		$output[ $f ] = ( isset( $input[ $f ] ) && $input[ $f ] === 'yes' ) ? 'yes' : 'no';
	}

	return $output;
}

function ba_hbl_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = ba_hbl_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'HBL PACO Gateway Settings', 'ba-himalayan-bank-payment-gateway' ); ?></h1>

		<div class="card" style="max-width:800px;margin:16px 0;padding:12px 16px;">
			<h2 class="title" style="margin-top:0;"><?php esc_html_e( 'Where to paste what', 'ba-himalayan-bank-payment-gateway' ); ?></h2>
			<table class="widefat striped" style="margin-top:8px;">
				<thead><tr><th><?php esc_html_e( 'Source', 'ba-himalayan-bank-payment-gateway' ); ?></th><th><?php esc_html_e( 'Paste into', 'ba-himalayan-bank-payment-gateway' ); ?></th></tr></thead>
				<tbody>
					<tr><td>Token from bank</td><td>Access Token</td></tr>
					<tr><td>Mid from bank</td><td>Merchant ID</td></tr>
					<tr><td>Encryption Key ID</td><td>Encryption Key (kid)</td></tr>
					<tr><td>Private Signing Key (PEM body)</td><td>Merchant Signing Private Key</td></tr>
					<tr><td>Private Encryption Key (PEM body)</td><td>Merchant Encryption Private Key</td></tr>
					<tr><td>Merchant Signing Public Key (PEM body)</td><td>Merchant Signing Public Key</td></tr>
					<tr><td>Merchant Encryption Public Key (PEM body)</td><td>Merchant Encryption Public Key</td></tr>
				</tbody>
			</table>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'ba_hbl_gateway_settings_group' ); ?>
			<table class="form-table">
				<tr><th colspan="2"><h2><?php esc_html_e( 'General', 'ba-himalayan-bank-payment-gateway' ); ?></h2></th></tr>

				<tr><th><label for="ba_hbl_3d"><?php esc_html_e( '3D Secure', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="checkbox" id="ba_hbl_3d" name="<?php echo BA_HBL_OPTION_NAME; ?>[3d_secure]" value="yes" <?php checked( $s['3d_secure'], 'yes' ); ?>></td></tr>

				<tr><th><label for="ba_hbl_title"><?php esc_html_e( 'Checkout Title', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="text" id="ba_hbl_title" name="<?php echo BA_HBL_OPTION_NAME; ?>[checkout_title]" value="<?php echo esc_attr( $s['checkout_title'] ); ?>" class="regular-text"></td></tr>

				<tr><th><label for="ba_hbl_desc"><?php esc_html_e( 'Checkout Description', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><textarea id="ba_hbl_desc" name="<?php echo BA_HBL_OPTION_NAME; ?>[checkout_description]" rows="2" class="large-text"><?php echo esc_textarea( $s['checkout_description'] ); ?></textarea></td></tr>

				<tr><th colspan="2"><h2><?php esc_html_e( 'Return URLs', 'ba-himalayan-bank-payment-gateway' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Optional: set custom pages/URLs for payment success, failure, cancellation, and backend notification. If left blank, the plugin will use default pages created on activation.', 'ba-himalayan-bank-payment-gateway' ); ?></p></th></tr>

				<tr><th><label for="ba_hbl_success_url"><?php esc_html_e( 'Success URL', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="url" id="ba_hbl_success_url" name="<?php echo BA_HBL_OPTION_NAME; ?>[success_url]" value="<?php echo esc_attr( $s['success_url'] ); ?>" class="regular-text">
						<?php if ( empty( $s['success_url'] ) && ! empty( $s['success_page_id'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'Default:', 'ba-himalayan-bank-payment-gateway' ); ?> <code><?php echo esc_html( get_permalink( (int) $s['success_page_id'] ) ); ?></code></p>
						<?php endif; ?>
					</td></tr>

				<tr><th><label for="ba_hbl_failed_url"><?php esc_html_e( 'Failed URL', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="url" id="ba_hbl_failed_url" name="<?php echo BA_HBL_OPTION_NAME; ?>[failed_url]" value="<?php echo esc_attr( $s['failed_url'] ); ?>" class="regular-text">
						<?php if ( empty( $s['failed_url'] ) && ! empty( $s['failed_page_id'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'Default:', 'ba-himalayan-bank-payment-gateway' ); ?> <code><?php echo esc_html( get_permalink( (int) $s['failed_page_id'] ) ); ?></code></p>
						<?php endif; ?>
					</td></tr>

				<tr><th><label for="ba_hbl_cancel_url"><?php esc_html_e( 'Cancel URL', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="url" id="ba_hbl_cancel_url" name="<?php echo BA_HBL_OPTION_NAME; ?>[cancel_url]" value="<?php echo esc_attr( $s['cancel_url'] ); ?>" class="regular-text">
						<?php if ( empty( $s['cancel_url'] ) && ! empty( $s['cancel_page_id'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'Default:', 'ba-himalayan-bank-payment-gateway' ); ?> <code><?php echo esc_html( get_permalink( (int) $s['cancel_page_id'] ) ); ?></code></p>
						<?php endif; ?>
					</td></tr>

				<tr><th><label for="ba_hbl_backend_url"><?php esc_html_e( 'Backend URL (IPN)', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="url" id="ba_hbl_backend_url" name="<?php echo BA_HBL_OPTION_NAME; ?>[backend_url]" value="<?php echo esc_attr( $s['backend_url'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Leave blank to use the BA Book Everything IPN endpoint.', 'ba-himalayan-bank-payment-gateway' ); ?></p>
					</td></tr>

				<tr><th colspan="2"><h2><?php esc_html_e( 'Credentials', 'ba-himalayan-bank-payment-gateway' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Merchant keys are always read from these settings (same procedure for Test and Production). PACO keys are displayed for reference and are used from hardcoded values in code.', 'ba-himalayan-bank-payment-gateway' ); ?></p></th></tr>

				<tr><th><label for="ba_hbl_mid"><?php esc_html_e( 'Merchant ID', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="text" id="ba_hbl_mid" name="<?php echo BA_HBL_OPTION_NAME; ?>[merchant_id]" value="<?php echo esc_attr( $s['merchant_id'] ); ?>" class="regular-text"></td></tr>

				<tr><th><label for="ba_hbl_enckey"><?php esc_html_e( 'Encryption Key (kid)', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="text" id="ba_hbl_enckey" name="<?php echo BA_HBL_OPTION_NAME; ?>[encryption_key]" value="<?php echo esc_attr( $s['encryption_key'] ); ?>" class="regular-text"></td></tr>

				<tr><th><label for="ba_hbl_token"><?php esc_html_e( 'Access Token', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><input type="text" id="ba_hbl_token" name="<?php echo BA_HBL_OPTION_NAME; ?>[access_token]" value="<?php echo esc_attr( $s['access_token'] ); ?>" class="regular-text"></td></tr>

				<tr><th colspan="2"><h3><?php esc_html_e( 'Merchant Keys', 'ba-himalayan-bank-payment-gateway' ); ?></h3></th></tr>

				<tr><th><label for="ba_hbl_signkey"><?php esc_html_e( 'Merchant Signing Private Key', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><textarea id="ba_hbl_signkey" name="<?php echo BA_HBL_OPTION_NAME; ?>[merchant_sign_private_key]" rows="4" class="large-text code"><?php echo esc_textarea( $s['merchant_sign_private_key'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'PEM body only (without BEGIN/END headers)', 'ba-himalayan-bank-payment-gateway' ); ?></p></td></tr>

				<tr><th><label for="ba_hbl_signpub"><?php esc_html_e( 'Merchant Signing Public Key', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><textarea id="ba_hbl_signpub" name="<?php echo BA_HBL_OPTION_NAME; ?>[merchant_sign_public_key]" rows="4" class="large-text code"><?php echo esc_textarea( $s['merchant_sign_public_key'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Public key you provide to PACO for verifying your request signature (PEM body only).', 'ba-himalayan-bank-payment-gateway' ); ?></p></td></tr>

				<tr><th><label for="ba_hbl_decryptkey"><?php esc_html_e( 'Merchant Encryption Private Key', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><textarea id="ba_hbl_decryptkey" name="<?php echo BA_HBL_OPTION_NAME; ?>[merchant_decrypt_private_key]" rows="4" class="large-text code"><?php echo esc_textarea( $s['merchant_decrypt_private_key'] ); ?></textarea></td></tr>

				<tr><th><label for="ba_hbl_encpub"><?php esc_html_e( 'Merchant Encryption Public Key', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><textarea id="ba_hbl_encpub" name="<?php echo BA_HBL_OPTION_NAME; ?>[merchant_encrypt_public_key]" rows="4" class="large-text code"><?php echo esc_textarea( $s['merchant_encrypt_public_key'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Public key you provide to PACO so it can encrypt responses to you (PEM body only).', 'ba-himalayan-bank-payment-gateway' ); ?></p></td></tr>

				<!-- PACO keys are hardcoded in code and intentionally not shown here. -->
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

function ba_hbl_transactions_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$post_type = class_exists( 'BABE_Post_types' ) && ! empty( BABE_Post_types::$order_post_type )
		? BABE_Post_types::$order_post_type
		: 'to_book';

	global $wpdb;
	$order_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		 WHERE p.post_type = %s AND pm.meta_key = '_payment_method' AND pm.meta_value = %s
		 ORDER BY p.ID DESC LIMIT 200",
		$post_type,
		BA_HBL_PAYMENT_METHOD
	) );

	$refund_done   = isset( $_GET['refund_done'] ) ? sanitize_text_field( $_GET['refund_done'] ) : '';
	$refund_error  = isset( $_GET['refund_error'] ) ? sanitize_text_field( $_GET['refund_error'] ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'HBL PACO Transactions', 'ba-himalayan-bank-payment-gateway' ); ?></h1>

		<?php if ( $refund_done ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $refund_done ); ?></p></div>
		<?php endif; ?>
		<?php if ( $refund_error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $refund_error ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped" style="margin-top:16px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Date', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Transaction ID', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ba-himalayan-bank-payment-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $order_ids ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No HBL transactions found.', 'ba-himalayan-bank-payment-gateway' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $order_ids as $oid ) :
					$oid          = absint( $oid );
					$order_num    = BABE_Order::get_order_number( $oid );
					$post_obj     = get_post( $oid );
					$date         = $post_obj ? $post_obj->post_date : '';
					$amount       = BABE_Order::get_order_total_amount( $oid );
					$currency     = BABE_Order::get_order_currency( $oid );
					$txn_id       = get_post_meta( $oid, '_hbl_transaction_id', true );
					$status       = get_post_status( $oid );
					$edit_url     = get_edit_post_link( $oid );
					$refund_url   = wp_nonce_url(
						admin_url( 'admin-post.php?action=ba_hbl_refund&order_id=' . $oid ),
						'ba_hbl_refund_' . $oid
					);
					?>
					<tr>
						<td><a href="<?php echo esc_url( $edit_url ); ?>">#<?php echo esc_html( $order_num ); ?></a></td>
						<td><?php echo esc_html( $date ); ?></td>
						<td><?php echo esc_html( number_format( $amount, 2 ) . ' ' . $currency ); ?></td>
						<td><?php echo esc_html( $txn_id ?: '—' ); ?></td>
						<td><?php echo esc_html( $status ); ?></td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'ba-himalayan-bank-payment-gateway' ); ?></a>
							<?php if ( $txn_id ) : ?>
								<a href="<?php echo esc_url( $refund_url ); ?>" class="button button-small"
									onclick="return confirm('<?php echo esc_js( __( 'Refund this order?', 'ba-himalayan-bank-payment-gateway' ) ); ?>');"
								><?php esc_html_e( 'Refund', 'ba-himalayan-bank-payment-gateway' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Process refund via admin-post.
 */
function ba_hbl_process_refund(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
	check_admin_referer( 'ba_hbl_refund_' . $order_id );

	$redirect_base = admin_url( 'admin.php?page=ba-hbl-gateway-transactions' );

	if ( ! $order_id ) {
		wp_safe_redirect( add_query_arg( 'refund_error', 'Invalid order.', $redirect_base ) );
		exit;
	}

	$settings = ba_hbl_get_settings();
	SecurityData::init_from_settings( $settings );

	$order_num = BABE_Order::get_order_number( $order_id );
	$currency  = BABE_Order::get_order_currency( $order_id );

	$tokens = BABE_Payments::get_order_tokens_for_refund( $order_id );
	if ( empty( $tokens ) ) {
		wp_safe_redirect( add_query_arg( 'refund_error', 'No refundable charge token found.', $redirect_base ) );
		exit;
	}

	$token_arr    = reset( $tokens );
	$charge_token = $token_arr['token'] ?? '';
	$amount       = $token_arr['amount'] ?? 0;

	if ( $amount <= 0 ) {
		wp_safe_redirect( add_query_arg( 'refund_error', 'Nothing to refund.', $redirect_base ) );
		exit;
	}

	try {
		$payment = new Payment();
		$result  = $payment->ExecuteRefundJose( $order_num, $amount,  'NPR' );
		$data    = json_decode( $result, true );

		$resp_code = '';
		if ( isset( $data['request']['respCode'] ) ) {
			$resp_code = $data['request']['respCode'];
		} elseif ( isset( $data['respCode'] ) ) {
			$resp_code = $data['respCode'];
		}

		if ( $resp_code === '00' || $resp_code === '0000' || $resp_code === '' ) {
			$refund_txn = $data['request']['controllerInternalId'] ?? ( $data['controllerInternalId'] ?? 'refund_' . time() );
			BABE_Payments::do_after_refund_order( $order_id, BA_HBL_PAYMENT_METHOD, $refund_txn, $amount, $token_arr );
			wp_safe_redirect( add_query_arg( 'refund_done', 'Refund successful for order #' . $order_num, $redirect_base ) );
			exit;
		}

		$msg = $data['request']['message'] ?? ( $data['message'] ?? 'Unknown error (respCode=' . $resp_code . ')' );
		wp_safe_redirect( add_query_arg( 'refund_error', 'Refund failed: ' . $msg, $redirect_base ) );
		exit;

	} catch ( \Exception $e ) {
		wp_safe_redirect( add_query_arg( 'refund_error', 'Refund error: ' . $e->getMessage(), $redirect_base ) );
		exit;
	}
}