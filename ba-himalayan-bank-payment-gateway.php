<?php
ob_start();
/**
 * Plugin Name: BA Himalayan Bank Payment Gateway
 * Description: Himalayan Bank (2C2P PACO) payment gateway for BA Book Everything.
 * Version: 1.0.5
 * Author: Surox and Manoj
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ba-himalayan-bank-payment-gateway
 * Requires PHP: 8.1
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
		add_action( 'wp_ajax_ba_hbl_get_transaction_status', 'ba_hbl_ajax_get_transaction_status' );
		add_action( 'add_meta_boxes', 'ba_hbl_register_txn_status_metabox' );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( strpos( $hook, 'ba-hbl-gateway' ) !== false ) {
				// Some admin pages rely on WP Pointer (jQuery plugin). Ensure it's loaded
				// to prevent: "TypeError: $(...).pointer is not a function".
				if ( function_exists( 'wp_enqueue_style' ) ) {
					wp_enqueue_style( 'wp-pointer' );
				}
				if ( function_exists( 'wp_enqueue_script' ) ) {
					wp_enqueue_script( 'wp-pointer' );
				}

				wp_enqueue_script(
					'ba-hbl-admin',
					BA_HBL_PLUGIN_URL . 'assets/admin.js',
					array( 'jquery' ),
					'1.0.0',
					true
				);
			}
		} );
	}
}

// Front-end UI tweak: hide the order summary box above the "Complete My Order" button on ba-book's checkout-3 page.
add_action( 'wp_footer', 'ba_hbl_maybe_hide_checkout_3_box', 99 );

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
 * Hides the summary card above the "Complete My Order" button on checkout-3.
 * The markup is generated by ba-book-everything, so we do a safe DOM-based hide.
 */
function ba_hbl_maybe_hide_checkout_3_box(): void {
	if ( is_admin() ) {
		return;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( strpos( $uri, '/checkout-3' ) === false ) {
		return;
	}

	?>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var candidates = Array.prototype.slice.call(document.querySelectorAll('button, a'));
			var btn = candidates.find(function (el) {
				return el && el.textContent && el.textContent.trim().toUpperCase().indexOf('COMPLETE MY ORDER') !== -1;
			});

			if (!btn) return;

			// Most layouts keep the summary box immediately above the button in the same column.
			var btnParent = btn.parentElement;
			var prev = btnParent && btnParent.previousElementSibling ? btnParent.previousElementSibling : (btn.previousElementSibling || null);

			if (prev) {
				prev.style.display = 'none';
			}
		});
	</script>
	<?php
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

				<tr><th><label for="ba_hbl_signpub"><?php esc_html_e( 'Merchant Signing Public Key', 'ba-himalayan-bank-payment-gateway' ); ?></label></th>
					<td><textarea id="ba_hbl_signpub" name="<?php echo BA_HBL_OPTION_NAME; ?>[merchant_sign_public_key]" rows="4" class="large-text code"><?php echo esc_textarea( $s['merchant_sign_public_key'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Public key you provide to PACO for verifying your request signature (PEM body only).', 'ba-himalayan-bank-payment-gateway' ); ?></p></td></tr>

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

/**
 * Pick first non-empty scalar string from an array by trying several key names (PACO uses mixed casing).
 */
function ba_hbl_paco_pick_string( $arr, array $keys ): string {
	if ( ! is_array( $arr ) ) {
		return '';
	}
	foreach ( $keys as $k ) {
		if ( array_key_exists( $k, $arr ) && $arr[ $k ] !== null && $arr[ $k ] !== '' ) {
			if ( is_scalar( $arr[ $k ] ) ) {
				return (string) $arr[ $k ];
			}
		}
	}
	return '';
}

/**
 * Pick first existing child array by trying several parent key names.
 */
function ba_hbl_paco_pick_subarray( $arr, array $keys ): ?array {
	if ( ! is_array( $arr ) ) {
		return null;
	}
	foreach ( $keys as $k ) {
		if ( isset( $arr[ $k ] ) && is_array( $arr[ $k ] ) ) {
			return $arr[ $k ];
		}
	}
	return null;
}

/**
 * Unwrap row if PACO nests the transaction under transaction/Transaction/item.
 */
function ba_hbl_paco_normalize_transaction_row( $txn ): array {
	if ( ! is_array( $txn ) ) {
		return [];
	}
	foreach ( [ 'transaction', 'Transaction', 'txn', 'Txn', 'item', 'Item', 'record', 'Record' ] as $w ) {
		if ( isset( $txn[ $w ] ) && is_array( $txn[ $w ] ) ) {
			return $txn[ $w ];
		}
	}
	return $txn;
}

/**
 * Extract transaction list array from decrypted PACO JSON (several response shapes).
 */
function ba_hbl_paco_extract_transaction_list( $decoded ): array {
	if ( ! is_array( $decoded ) ) {
		return [];
	}
	$path_segments = [
		// Inquiry/TransactionList: transactions are often a direct array under Data (not transactionList).
		[ 'response', 'Data' ],
		[ 'Response', 'Data' ],
		[ 'response', 'data' ],
		[ 'request', 'Data' ],
		[ 'request', 'data' ],
		[ 'response', 'Data', 'transactionList' ],
		[ 'response', 'data', 'transactionList' ],
		[ 'Response', 'Data', 'transactionList' ],
		[ 'request', 'Data', 'transactionList' ],
		[ 'request', 'data', 'transactionList' ],
		[ 'data', 'transactionList' ],
		[ 'Data', 'transactionList' ],
		[ 'response', 'Data', 'transactions' ],
		[ 'data', 'transactions' ],
		[ 'transactionList' ],
	];
	foreach ( $path_segments as $path ) {
		$cur = $decoded;
		$ok  = true;
		foreach ( $path as $seg ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $seg, $cur ) ) {
				$ok = false;
				break;
			}
			$cur = $cur[ $seg ];
		}
		if ( ! $ok || ! is_array( $cur ) ) {
			continue;
		}
		// Sequential list of rows.
		if ( $cur === [] ) {
			return [];
		}
		$keys = array_keys( $cur );
		$is_list = ( $keys === range( 0, count( $cur ) - 1 ) );
		if ( $is_list ) {
			return $cur;
		}
		// Single transaction object returned instead of list.
		if ( ba_hbl_paco_pick_string( $cur, [ 'orderNo', 'OrderNo', 'invoiceNo', 'InvoiceNo' ] ) !== '' ) {
			return [ $cur ];
		}
	}
	return [];
}

/**
 * Format PACO ISO 8601 transactionDateTime for admin display (site timezone / locale).
 */
function ba_hbl_format_paco_transaction_datetime( $iso_string ): string {
	if ( empty( $iso_string ) || ! is_string( $iso_string ) ) {
		return '—';
	}
	$ts = strtotime( $iso_string );
	if ( false === $ts ) {
		return sanitize_text_field( $iso_string );
	}
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
}

/**
 * Turn PACO AmountText (fixed digits, implied decimals) into a readable amount string.
 * Uses DecimalPlaces from transactionAmount when provided (e.g. 2 → last 2 digits are fraction).
 */
function ba_hbl_format_paco_amount_text( string $amount_text, ?int $decimal_places = null ): string {
	$digits = preg_replace( '/\D/', '', $amount_text );
	if ( $digits === '' || $digits === null ) {
		return $amount_text !== '' ? $amount_text : '—';
	}
	$dp = $decimal_places !== null ? max( 0, min( 8, (int) $decimal_places ) ) : 2;
	if ( $dp === 0 ) {
		return number_format( (float) $digits, 0, '.', ',' );
	}
	if ( strlen( $digits ) <= $dp ) {
		$digits = str_pad( $digits, $dp + 1, '0', STR_PAD_LEFT );
	}
	$whole = substr( $digits, 0, -$dp );
	$frac  = substr( $digits, -$dp );
	$num   = (float) ( $whole . '.' . $frac );
	return number_format( $num, $dp, '.', ',' );
}

function ba_hbl_transactions_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = ba_hbl_get_settings();
	SecurityData::init_from_settings( $settings );

	// --- Handle filters from GET params ---
	$from_date = isset( $_GET['from_date'] ) ? sanitize_text_field( wp_unslash( $_GET['from_date'] ) ) : date( 'Y-m-d', strtotime( '-30 days' ) );
	$to_date   = isset( $_GET['to_date'] ) ? sanitize_text_field( wp_unslash( $_GET['to_date'] ) ) : date( 'Y-m-d' );
	$order_no  = isset( $_GET['order_no'] ) ? sanitize_text_field( wp_unslash( $_GET['order_no'] ) ) : '';
	$page_size = 500;

	// --- Fetch from PACO ---
	$transactions = [];
	$fetch_error  = '';
	$decoded_response = null;

	try {
		$payment      = new Payment();
		$raw          = $payment->ExecuteTransactionList(
			$from_date . 'T00:00:00Z',
			$to_date . 'T23:59:59Z',
			$order_no ?: null,	
		);
		$decoded = json_decode( $raw, true );
		$decoded_response = $decoded;

		$transactions = ba_hbl_paco_extract_transaction_list( $decoded );
	} catch ( \Exception $e ) {
		$fetch_error = $e->getMessage();
	}

	// Filter: show all transactions by default.
	// Allowed PACO PaymentStatus codes:
	// - S (Success)
	// - F (Failed)
	// - PCPS (Pending)
	$status_filter = isset( $_GET['payment_status'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_status'] ) ) : 'ALL';
	$status_filter = strtoupper( $status_filter );

	$allowed_statuses = [ 'ALL', 'S', 'F', 'PCPS' ];
	if ( ! in_array( $status_filter, $allowed_statuses, true ) ) {
		$status_filter = 'ALL';
	}

	$filtered_transactions = [];
	foreach ( $transactions as $txn ) {
		if ( ! is_array( $txn ) ) {
			continue;
		}

		$t   = ba_hbl_paco_normalize_transaction_row( $txn );
		$psi = ba_hbl_paco_pick_subarray( $t, [ 'paymentStatusInfo', 'PaymentStatusInfo' ] );
		$payment_status = strtoupper( ba_hbl_paco_pick_string( $psi ?? [], [ 'PaymentStatus', 'paymentStatus' ] ) );

		if ( $status_filter === 'ALL' ) {
			$filtered_transactions[] = $txn;
			continue;
		}

		if ( $payment_status === $status_filter ) {
			$filtered_transactions[] = $txn;
		}
	}
	$transactions = $filtered_transactions;
	// Cache WP order mapping by OrderNo to avoid repeated DB lookups per page render.
	$order_id_cache = [];

	// Debug: show the whole decoded/filtered transaction list in browser console.
	if ( \defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		echo '<script>console.log("PACO TransactionList decoded_response", ' . \wp_json_encode( $decoded_response ) . ');</script>';
		echo '<script>console.log("PACO TransactionList filtered_transactions", ' . \wp_json_encode( $transactions ) . ');</script>';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'HBL PACO Transactions', 'ba-himalayan-bank-payment-gateway' ); ?></h1>

		<?php if ( $fetch_error ) : ?>
			<div><p><?php echo esc_html( $fetch_error ); ?></p></div>
		<?php endif; ?>

		<!-- Filter Form -->
		<form method="get" action="" style="margin: 16px 0; display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
			<input type="hidden" name="page" value="ba-hbl-gateway-transactions">
			<label>
				<?php esc_html_e( 'From', 'ba-himalayan-bank-payment-gateway' ); ?><br>
				<input type="date" name="from_date" value="<?php echo esc_attr( $from_date ); ?>" class="regular-text">
			</label>
			<label>
				<?php esc_html_e( 'To', 'ba-himalayan-bank-payment-gateway' ); ?><br>
				<input type="date" name="to_date" value="<?php echo esc_attr( $to_date ); ?>" class="regular-text">
			</label>
			<label>
				<?php esc_html_e( 'Order No', 'ba-himalayan-bank-payment-gateway' ); ?><br>
				<input type="text" name="order_no" value="<?php echo esc_attr( $order_no ); ?>" class="regular-text" placeholder="Optional">
			</label>
			<label>
				<?php esc_html_e( 'Payment Status', 'ba-himalayan-bank-payment-gateway' ); ?><br>
				<select name="payment_status" class="regular-text">
					<option value="ALL" <?php selected( $status_filter, 'ALL' ); ?>>All</option>
					<option value="S" <?php selected( $status_filter, 'S' ); ?>>Success (S)</option>
					<option value="F" <?php selected( $status_filter, 'F' ); ?>>Failed (F)</option>
					<option value="PCPS" <?php selected( $status_filter, 'PCPS' ); ?>>Pending (PCPS)</option>
				</select>
			</label>
			<div>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'ba-himalayan-bank-payment-gateway' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ba-hbl-gateway-transactions' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'ba-himalayan-bank-payment-gateway' ); ?></a>
			</div>
		</form>

		<!-- Transactions Table -->
		<table class="widefat striped" style="margin-top:8px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Transaction date & time', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Order no.', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Payment status', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Payment category', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Payment type', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Amount (from AmountText)', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Currency', 'ba-himalayan-bank-payment-gateway' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ba-himalayan-bank-payment-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $transactions ) ) : ?>
				<tr>
					<td colspan="8">
						<?php esc_html_e( 'No transactions found for the selected period.', 'ba-himalayan-bank-payment-gateway' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $transactions as $i => $txn ) :
					$t = ba_hbl_paco_normalize_transaction_row( $txn );

					$ta = ba_hbl_paco_pick_subarray( $t, [ 'transactionAmount', 'TransactionAmount' ] ) ?? [];

					$amount_text_raw = ba_hbl_paco_pick_string( $ta, [ 'AmountText', 'amountText' ] );
					$currency_code   = ba_hbl_paco_pick_string( $ta, [ 'CurrencyCode', 'currencyCode' ] );

					$decimal_places = null;
					foreach ( [ 'DecimalPlaces', 'decimalPlaces' ] as $dp_key ) {
						if ( isset( $ta[ $dp_key ] ) && $ta[ $dp_key ] !== '' && $ta[ $dp_key ] !== null && is_numeric( $ta[ $dp_key ] ) ) {
							$decimal_places = (int) $ta[ $dp_key ];
							break;
						}
					}

					$amount_num = null;
					foreach ( [ 'Amount', 'amount' ] as $ak ) {
						if ( isset( $ta[ $ak ] ) && is_numeric( $ta[ $ak ] ) ) {
							$amount_num = (float) $ta[ $ak ];
							break;
						}
					}

					$txn_order_no = ba_hbl_paco_pick_string( $t, [ 'orderNo', 'OrderNo', 'invoiceNo', 'InvoiceNo' ] );
					if ( $txn_order_no === '' ) {
						$txn_order_no = '—';
					}

					$order_id   = 0;
					$order_link = '';
					if ( $txn_order_no !== '—' ) {
						if ( isset( $order_id_cache[ $txn_order_no ] ) ) {
							$order_id = (int) $order_id_cache[ $txn_order_no ];
						} else {
							$order_id = ba_hbl_find_order_by_number( $txn_order_no );
							$order_id_cache[ $txn_order_no ] = $order_id;
						}
						if ( $order_id > 0 ) {
							$order_link = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
						}
					}

					$txn_iso = ba_hbl_paco_pick_string(
						$t,
						[ 'transactionDateTime', 'TransactionDateTime', 'transactionDate', 'TransactionDate' ]
					);
					$txn_datetime_display = ba_hbl_format_paco_transaction_datetime( $txn_iso );

					$psi = ba_hbl_paco_pick_subarray( $t, [ 'paymentStatusInfo', 'PaymentStatusInfo' ] );
					$txn_payment_status_raw = strtoupper( ba_hbl_paco_pick_string( $psi ?? [], [ 'PaymentStatus', 'paymentStatus' ] ) );

					$psp = ba_hbl_paco_pick_subarray( $t, [ 'pspResponse', 'PspResponse' ] );
					$txn_acquirer_code = ba_hbl_paco_pick_string( $psp ?? [], [ 'acquirerResponseCode', 'AcquirerResponseCode' ] );
					if ( $txn_acquirer_code === '' ) {
						$txn_acquirer_code = ba_hbl_paco_pick_string( $t, [ 'acquirerResponseCode', 'AcquirerResponseCode' ] );
					}

					$txn_resp_code = $txn_acquirer_code !== '' ? $txn_acquirer_code : $txn_payment_status_raw;

					// Map PACO PaymentStatus to label+color (same keys as modal / API).
					$payment_status = ba_hbl_paco_pick_string( $psi ?? [], [ 'PaymentStatus', 'paymentStatus' ] );
					if ( $payment_status === '' ) {
						$payment_status = $txn_resp_code !== '' ? $txn_resp_code : '—';
					}

					switch ( strtoupper( (string) $payment_status ) ) {
						case 'S':
							$status_color = 'green';
							$status_label = 'Success';
							break;
						case 'F':
							$status_color = 'red';
							$status_label = 'Failed';
							break;
						case 'PCPS':
							$status_color = 'orange';
							$status_label = 'Pending';
							break;
						default:
							$status_color = 'gray';
							$status_label = (string) $payment_status;
							break;
					}

					$payment_category = ba_hbl_paco_pick_string( $t, [ 'paymentCategory', 'PaymentCategory' ] );
					if ( $payment_category === '' ) {
						$payment_category = '—';
					}
					$payment_type = ba_hbl_paco_pick_string(
						$t,
						[ 'paymentType', 'PaymentType', 'channelCode', 'ChannelCode' ]
					);
					if ( $payment_type === '' ) {
						$payment_type = '—';
					}

					if ( $amount_text_raw !== '' ) {
						$amount_display = ba_hbl_format_paco_amount_text( $amount_text_raw, $decimal_places );
						if ( $amount_display !== '—' ) {
							$amount_display .= ' <span class="description" style="font-weight:normal;">(' . esc_html( $amount_text_raw ) . ')</span>';
						}
					} elseif ( $amount_num !== null ) {
						$dp_fmt = $decimal_places !== null ? max( 0, min( 8, (int) $decimal_places ) ) : 2;
						$amount_display = number_format( $amount_num, $dp_fmt, '.', ',' );
					} else {
						$amount_display = '—';
					}

					$modal_json = esc_attr( wp_json_encode( $txn ) );
					?>
					<tr>
						<td>
							<?php if ( $txn_iso !== '' ) : ?>
								<time datetime="<?php echo esc_attr( $txn_iso ); ?>"><?php echo esc_html( $txn_datetime_display ); ?></time>
							<?php else : ?>
								<?php echo esc_html( $txn_datetime_display ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $order_link ) : ?>
								<a href="<?php echo esc_url( $order_link ); ?>"><strong><?php echo esc_html( $txn_order_no ); ?></strong></a>
							<?php else : ?>
								<strong><?php echo esc_html( $txn_order_no ); ?></strong>
							<?php endif; ?>
						</td>
						<td>
							<span style="color:<?php echo esc_attr( $status_color ); ?>; font-weight:600;">
								<?php echo esc_html( $status_label ); ?>
							</span>
							<?php if ( $txn_payment_status_raw !== '' && $txn_payment_status_raw !== '—' ) : ?>
								<br><code class="description" style="font-size:11px;"><?php echo esc_html( $txn_payment_status_raw ); ?></code>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $payment_category ); ?></code></td>
						<td><code><?php echo esc_html( $payment_type ); ?></code></td>
						<td><?php echo wp_kses_post( $amount_display ); ?></td>
						<td><strong><?php echo esc_html( $currency_code !== '' ? $currency_code : '—' ); ?></strong></td>
						<td>
							<button
								class="button button-small ba-hbl-view-txn"
								data-txn="<?php echo $modal_json; ?>"
							><?php esc_html_e( 'View', 'ba-himalayan-bank-payment-gateway' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<!-- PACO TransactionList paging -->
		<p style="margin-top:12px;">
			<?php
			// Show filtered list size (after filtering by PaymentStatus).
			echo esc_html( (string) \count( $transactions ) );
			?>
			<?php esc_html_e( ' transaction(s) shown (PACO maxResults based).', 'ba-himalayan-bank-payment-gateway' ); ?>
		</p>

		<!-- View Modal -->
		<div id="ba-hbl-txn-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
			background:rgba(0,0,0,0.6); z-index:99999; overflow:auto;">
			<div style="background:#fff; margin:40px auto; padding:24px; max-width:700px;
				border-radius:6px; position:relative; max-height:80vh; overflow-y:auto;">
				<button id="ba-hbl-modal-close" style="position:absolute; top:12px; right:12px;
					font-size:20px; background:none; border:none; cursor:pointer;">✕</button>
				<h2 style="margin-top:0;"><?php esc_html_e( 'Transaction Details', 'ba-himalayan-bank-payment-gateway' ); ?></h2>
				<table class="widefat striped" id="ba-hbl-modal-table">
					<tbody></tbody>
				</table>
			</div>
		</div>

		<!-- Inline JS for modal -->
		<script>
		(function() {
			var modal      = document.getElementById('ba-hbl-txn-modal');
			var closeBtn   = document.getElementById('ba-hbl-modal-close');
			var modalTable = document.getElementById('ba-hbl-modal-table').querySelector('tbody');

			document.querySelectorAll('.ba-hbl-view-txn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var txn = JSON.parse(this.getAttribute('data-txn'));
					modalTable.innerHTML = '';
					renderObject(txn, modalTable, '');
					modal.style.display = 'block';
				});
			});

			closeBtn.addEventListener('click', function() {
				modal.style.display = 'none';
			});

			modal.addEventListener('click', function(e) {
				if (e.target === modal) modal.style.display = 'none';
			});

			// Recursively renders nested object as table rows
			function renderObject(obj, tbody, prefix) {
				Object.keys(obj).forEach(function(key) {
					var fullKey = prefix ? prefix + '.' + key : key;
					var val = obj[key];
					if (val === null || val === undefined) val = '—';

					if (typeof val === 'object' && !Array.isArray(val)) {
						// Nested object: add a sub-header row then recurse
						var headerRow = document.createElement('tr');
						headerRow.innerHTML = '<td colspan="2" style="background:#f0f0f0; font-weight:700; padding:6px 10px;">' + escHtml(fullKey) + '</td>';
						tbody.appendChild(headerRow);
						renderObject(val, tbody, fullKey);
					} else if (Array.isArray(val)) {
						var arrRow = document.createElement('tr');
						arrRow.innerHTML = '<td style="font-weight:600;">' + escHtml(fullKey) + '</td><td>' + escHtml(JSON.stringify(val)) + '</td>';
						tbody.appendChild(arrRow);
					} else {
						var row = document.createElement('tr');
						row.innerHTML = '<td style="font-weight:600; width:40%;">' + escHtml(fullKey) + '</td><td>' + escHtml(String(val)) + '</td>';
						tbody.appendChild(row);
					}
				});
			}

			function escHtml(str) {
				return String(str)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;');
			}
		})();
		</script>
	</div>
	<?php
}

/**
 * AJAX: Get latest PACO TransactionStatus by orderNo.
 * Used by the Transactions "View" modal to display latest status and a conditional Refund button.
 */
function ba_hbl_ajax_get_transaction_status(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}

	check_ajax_referer( 'ba_hbl_transaction_status_nonce', 'nonce' );

	$order_no = isset( $_POST['order_no'] ) ? sanitize_text_field( wp_unslash( $_POST['order_no'] ) ) : '';
	if ( empty( $order_no ) ) {
		wp_send_json_error( [ 'message' => 'Missing order_no' ], 400 );
	}

	$settings = ba_hbl_get_settings();
	SecurityData::init_from_settings( $settings );

	try {
		$payment = new Payment();
		$raw     = $payment->ExecuteTransactionStatusJose( $order_no );
		$decoded = json_decode( $raw, true );
	} catch ( \Exception $e ) {
		wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
	}

	$data_item = null;
	// PACO TransactionStatus schema varies: it can be { data: [...] } or { response: { data: [...] } }.
	foreach ( [
		[ 'response', 'data' ],
		[ 'Response', 'data' ],
		[ 'data' ],
	] as $path ) {
		$cur = $decoded;
		$ok  = true;
		foreach ( $path as $seg ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $seg, $cur ) ) {
				$ok = false;
				break;
			}
			$cur = $cur[ $seg ];
		}
		if ( ! $ok || ! is_array( $cur ) ) {
			continue;
		}

		if ( $cur === [] ) {
			continue;
		}

		$keys    = array_keys( $cur );
		$is_list = ( $keys === range( 0, count( $cur ) - 1 ) );
		$data_item = $is_list ? reset( $cur ) : $cur;
		break;
	}

	$data_item = is_array( $data_item ) ? $data_item : [];

	$psi = ba_hbl_paco_pick_subarray( $data_item, [ 'paymentStatusInfo', 'PaymentStatusInfo' ] ) ?? [];
	$payment_status_code = strtoupper( ba_hbl_paco_pick_string( $psi, [ 'PaymentStatus', 'paymentStatus' ] ) );

	$expiry = ba_hbl_paco_pick_string(
		$data_item,
		[
			'paymentExpiryDateTime',
			'PaymentExpiryDateTime',
		]
	);

	$payment_status_label = '—';
	switch ( $payment_status_code ) {
		case 'S':
			$payment_status_label = 'Success';
			break;
		case 'F':
			$payment_status_label = 'Failed';
			break;
		case 'PCPS':
			$payment_status_label = 'Pending';
			break;
		default:
			if ( $payment_status_code !== '' ) {
				$payment_status_label = $payment_status_code;
			}
			break;
	}

	wp_send_json_success(
		[
			'payment_status_code'  => $payment_status_code,
			'payment_status_label' => $payment_status_label,
			'payment_expiry'        => $expiry,
			'status_data'          => $data_item,
		]
	);
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

/**
 * Add PACO TransactionStatus metabox to the order edit page.
 */
function ba_hbl_register_txn_status_metabox(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$post_type = class_exists( 'BABE_Post_types' ) && ! empty( BABE_Post_types::$order_post_type )
		? BABE_Post_types::$order_post_type
		: 'to_book';

	add_meta_box(
		'ba-hbl-txn-status-metabox',
		__( 'HBL PACO Transaction Status', 'ba-himalayan-bank-payment-gateway' ),
		'ba_hbl_txn_status_metabox',
		$post_type,
		'side',
		'default'
	);
}

/**
 * Metabox renderer.
 */
function ba_hbl_txn_status_metabox( \WP_Post $post ): void {
	$order_no = '';
	if ( class_exists( 'BABE_Order' ) ) {
		$order_no = BABE_Order::get_order_number( $post->ID );
	}
	if ( empty( $order_no ) ) {
		$order_no = get_post_meta( $post->ID, '_order_number', true );
	}

	$nonce     = wp_create_nonce( 'ba_hbl_transaction_status_nonce' );
	$ajax_url  = admin_url( 'admin-ajax.php' );
	$order_no_json = wp_json_encode( (string) $order_no );

	echo '<div id="ba-hbl-txn-status-result" style="margin-top:6px;">Loading latest status...</div>';
	echo '<p style="margin:8px 0 0;"><small>OrderNo: <code>' . esc_html( (string) $order_no ) . '</code></small></p>';

	echo '<script>
		(function() {
			var el = document.getElementById("ba-hbl-txn-status-result");
			if (!el) return;

			var orderNo = ' . $order_no_json . ';
			var ajaxUrl = ' . wp_json_encode( $ajax_url ) . ';
			var ajaxNonce = ' . wp_json_encode( $nonce ) . ';

			function escHtml(str) {
				return String(str)
					.replace(/&/g, "&amp;")
					.replace(/</g, "&lt;")
					.replace(/>/g, "&gt;")
					.replace(/"/g, "&quot;")
					.replace(/\\x27/g, "&#039;");
			}

			if (!orderNo) {
				el.innerHTML = "<div>No OrderNo found.</div>";
				return;
			}

			var form = new URLSearchParams();
			form.append("action", "ba_hbl_get_transaction_status");
			form.append("nonce", ajaxNonce);
			form.append("order_no", orderNo);

			fetch(ajaxUrl, {
				method: "POST",
				credentials: "same-origin",
				headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
				body: form.toString()
			})
			.then(function(r) {
				return r.text().then(function(t) {
					try {
						return JSON.parse(t);
					} catch (e) {
						return { success: false, data: { message: t } };
					}
				});
			})
			.then(function(res) {
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) ? res.data.message : "Failed to load latest status.";
					el.innerHTML = "<div style=\\"color:#b32d2e;\\">" + escHtml(msg) + "</div>";
					return;
				}

				var data = res.data || {};
				var html = "";

				if (data.payment_status_label) {
					html += "<div><strong>Latest status:</strong> " + escHtml(data.payment_status_label) + "</div>";
				}
				if (data.payment_expiry) {
					html += "<div><strong>Expiry:</strong> " + escHtml(data.payment_expiry) + "</div>";
				}

				el.innerHTML = html || "";
			})
			.catch(function() {
				el.innerHTML = "<div style=\\"color:#b32d2e;\\">Failed to load latest status.</div>";
			});
		})();
	</script>';
}