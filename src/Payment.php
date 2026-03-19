<?php

namespace Nexhbp\HimalayanBank;

use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;

class Payment extends ActionRequest
{

	public string $lastRequestPayload = '';
	public string $lastRawResponse = '';

	private function is_debug_enabled(): bool
	{
		return \defined('WP_DEBUG') && WP_DEBUG;
	}

	private function log_debug(string $message, array $context = []): void
	{
		$context = array_merge(['source' => 'ba-hbl-paco'], $context);

		if (\function_exists('wc_get_logger')) {
			\wc_get_logger()->debug($message, $context);
			return;
		}

		// Fallback (e.g., if WooCommerce logger not available).
		\error_log($message . (!empty($context) ? ' ' . \wp_json_encode($context) : ''));
	}

	private function console_debug(string $message, $payload = []): void
	{
		if (!$this->is_debug_enabled()) {
			return;
		}
		if (!\function_exists('is_user_logged_in') || !\is_user_logged_in()) {
			return;
		}
		if (!\function_exists('current_user_can') || !\current_user_can('manage_options')) {
			return;
		}

		if (!\is_array($payload)) {
			$payload = ['value' => $payload];
		}

		$entry = [
			'message' => $message,
			'payload' => $payload,
		];

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>console.log(' . \wp_json_encode($entry) . ');</script>';
	}

	private function looks_like_compact_jwe(string $maybeToken): bool
	{
		$parts = \explode('.', \trim($maybeToken));
		return \count($parts) === 5 && $parts[0] !== '' && $parts[1] !== '' && $parts[2] !== '' && $parts[3] !== '' && $parts[4] !== '';
	}

	private function extract_compact_jwe(string $text): ?string
	{
		$text = \trim($text);
		if ($this->looks_like_compact_jwe($text)) {
			return $text;
		}

		// Sometimes providers wrap the JWE in JSON or other text. Try to extract the first compact JWE-looking token.
		\preg_match('/([A-Za-z0-9\-_]+(?:\.[A-Za-z0-9\-_]+){4})/', $text, $m);
		if (!empty($m[1]) && $this->looks_like_compact_jwe($m[1])) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Build JOSE request for prePaymentUi and return decrypted response.
	 *
	 * @throws GuzzleException
	 */
	public function ExecuteFormJose(
		$orderNo,
		$curr,
		$amt,
		$threeD,
		$success_url,
		$failed_url,
		$cancel_url,
		$backend_url,
		$product_detail
	): string {
		$now = Carbon::now();

		$productDescription = apply_filters(
			'ba_hbl_product_description',
			sprintf("Booking order '%s'", $orderNo),
			$orderNo
		);

		$product_detail = array(
			array(
				'purchaseItemType'        => 'ticket',
				'referenceNo'             => (string) $orderNo,
				'purchaseItemDescription' => 'Booking',
				'purchaseItemPrice'       => array(
					'amountText'    => sprintf('%012d', (int) round(($amt ?? 0) * 100)),
					'currencyCode'  => $curr ?: 'NPR',
					'decimalPlaces' => 2,
					'amount'        => (float) $amt,
				),
				'passengerSeqNo' => 1,
			),
		);

		$request = [
			'apiRequest'        => array(
				'requestMessageID' => $this->Guid(),
				'requestDateTime'  => $now->utc()->format('Y-m-d\TH:i:s.v\Z'),
				'language'         => 'en-US',
			),
			'officeId'          => SecurityData::get_merchant_id(),
			'orderNo'           => $orderNo,
			'productDescription' => $productDescription,
			'paymentType'       => 'CC',
			'paymentCategory'   => 'ECOM',
			'mcpFlag'           => 'N',
			'request3dsFlag'    => $threeD,
			'transactionAmount' => [
				'amountText'    => sprintf('%012d', (int) round(($amt ?? 0) * 100)),
				'currencyCode'  => $curr,
				'decimalPlaces' => 2,
				'amount'        => (float) $amt,
			],
			'notificationURLs'  => [
				'confirmationURL' => $success_url,
				'failedURL'       => $failed_url,
				'cancellationURL' => $cancel_url,
				'backendURL'      => $backend_url,
			],
			'purchaseItems'     => $product_detail,
		];

		$payload = [
			'request'       => $request,
			'iss'           => SecurityData::get_access_token(),
			'aud'           => 'PacoAudience',
			'CompanyApiKey' => SecurityData::get_access_token(),
			'iat'           => $now->unix(),
			'nbf'           => $now->unix(),
			'exp'           => $now->copy()->addHour()->unix(),
		];

		$stringPayload = \wp_json_encode($payload);
		$this->lastRequestPayload = $stringPayload;
		$this->log_debug('PACO Request Payload', ['payload' => $payload]);
		$signingKey    = $this->GetPrivateKey(SecurityData::get_merchant_signing_private_key());
		$encryptingKey = $this->GetPublicKey(SecurityData::get_paco_encryption_public_key());
		$this->log_debug('PACO Signing Key', ['key' => $signingKey]);
		$this->log_debug('PACO Encrypting Key', ['key' => $encryptingKey]);
		$body = $this->EncryptPayload($stringPayload, $signingKey, $encryptingKey);
		// $this->console_debug('PACO Request Body', $body);
		try {
			$response = $this->client->post('api/1.0/Payment/prePaymentUi', array(
				'headers' => array(
					'Accept'        => 'application/jose',
					'CompanyApiKey' => SecurityData::get_access_token(),
					'Content-Type'  => 'application/jose; charset=utf-8',
				),
				'body' => $body,
				'timeout' => 60,
				'redirection' => 5,
				'blocking' => true,
				'http_version' => '1.0',
				'data_format' => 'body',
			));
			$rawResponse = (string) $response->getBody();
			$this->lastRawResponse = $rawResponse;

			$this->log_debug('PACO Response', [
				'status' => $response->getStatusCode(),
				'raw'    => $rawResponse,
			]);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$errBody = $e->getResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
			$this->lastRawResponse = $errBody;

			$this->log_debug('PACO Client Error (raw)', [
				'status' => $e->getResponse() ? $e->getResponse()->getStatusCode() : null,
				'raw'    => $errBody,
			]);

			$decryptedForException = null;
			$jweToken = \is_string($errBody) ? $this->extract_compact_jwe($errBody) : null;

			// If PACO sends encrypted error bodies, attempt to decrypt and log the JSON.
			if (\is_string($jweToken) && $jweToken !== '') {
				try {
					$decryptingKey            = $this->GetPrivateKey(SecurityData::get_merchant_decryption_private_key());
					$signatureVerificationKey = $this->GetPublicKey(SecurityData::get_paco_signing_public_key());
					$decryptedForException     = $this->DecryptToken($jweToken, $decryptingKey, $signatureVerificationKey);

					$decoded = \json_decode($decryptedForException, true);
					$this->log_debug('PACO Client Error (decrypted)', [
						'decrypted' => $decoded ?? $decryptedForException,
					]);
				} catch (\Throwable $decryptErr) {
					$this->log_debug('PACO Client Error decrypt failed', [
						'message' => $decryptErr->getMessage(),
					]);
				}
			}

			if ($this->is_debug_enabled() && \is_string($decryptedForException) && $decryptedForException !== '') {
				throw new \Exception('PACO error (decrypted): ' . $decryptedForException);
			}
			throw new \Exception('PACO error: ' . $errBody);
		}

		$token                    = $this->lastRawResponse;
		$decryptingKey            = $this->GetPrivateKey(SecurityData::get_merchant_decryption_private_key());
		$signatureVerificationKey = $this->GetPublicKey(SecurityData::get_paco_signing_public_key());

		$decrypted = $this->DecryptToken($token, $decryptingKey, $signatureVerificationKey);
		$decoded   = \json_decode($decrypted, true);
		$this->log_debug('PACO Response (decrypted)', ['decrypted' => $decoded ?? $decrypted]);

		return $decrypted;
	}

	/**
	 * Decrypt a PACO response/IPN token — public wrapper around DecryptToken.
	 *
	 * @throws \Exception
	 */
	public function DecryptTokenPublic(string $token): string
	{
		$decryptingKey            = $this->GetPrivateKey(SecurityData::get_merchant_decryption_private_key());
		$signatureVerificationKey = $this->GetPublicKey(SecurityData::get_paco_signing_public_key());

		return $this->DecryptToken($token, $decryptingKey, $signatureVerificationKey);
	}

	/**
	 * Execute a PACO TransactionList inquiry and return decrypted response.
	 *
	 * @throws GuzzleException
	 * @throws \Exception
	 */
	public function ExecuteTransactionList(
		?string $fromDate = null,
		?string $toDate = null,
		?string $orderNo = null,
		?int $pageNo = null,
		?int $pageSize = null
	): string {
		$now = Carbon::now();

		// PACO TransactionList uses:
		// - request.apiRequest (required)
		// - request.advSearchParams (required container)
		// - fromDate/toDate/orderNo/maxResults are optional fields inside advSearchParams.
		$maxResults = $pageSize !== null ? (int) $pageSize : 500;
		$maxResults = max(1, min(500, $maxResults));

		$request = array(
			'apiRequest' => array(
				'requestMessageID' => $this->Guid(),
				'requestDateTime'  => $now->utc()->format('Y-m-d\TH:i:s.v\Z'),
				'language'         => 'en-US',
			),
			'advSearchParams' => array(
				'officeId'   => [ SecurityData::get_merchant_id() ],
				'dateOption' => 'CreatedDate',
				'maxResults' => $maxResults,
			),
		);

		// if ($fromDate !== null) {
		// 	$request['advSearchParams']['fromDate'] = $fromDate;
		// }
		// if ($toDate !== null) {
		// 	$request['advSearchParams']['toDate'] = $toDate;
		// }
		// if ($orderNo !== null) {
		// 	$request['advSearchParams']['orderNo'] = [ $orderNo ];
		// }
		
		$payload = array(
			'request'       => $request,
			'iss'           => SecurityData::get_access_token(),
			'aud'           => 'PacoAudience',
			'CompanyApiKey' => SecurityData::get_access_token(),
			'iat'           => $now->unix(),
			'nbf'           => $now->unix(),
			'exp'           => $now->copy()->addHour()->unix(),
		);
		
		$stringPayload = \wp_json_encode($payload);
		$this->console_debug('PACO TransactionList string payload', $stringPayload);
		$signingKey    = $this->GetPrivateKey(SecurityData::get_merchant_signing_private_key());
		$encryptingKey = $this->GetPublicKey(SecurityData::get_paco_encryption_public_key());
		$body          = $this->EncryptPayload($stringPayload, $signingKey, $encryptingKey);

		$response = $this->client->post('api/1.0/Inquiry/TransactionList', array(
			'headers' => array(
				'Accept'        => 'application/jose',
				'CompanyApiKey' => SecurityData::get_access_token(),
				'Content-Type'  => 'application/jose; charset=utf-8',
			),
			'body'    => $body,
			'timeout' => 60,
			'redirection' => 5,
			'blocking' => true,
			'http_version' => '1.0',
			'data_format' => 'body',
		));

		$rawResponse = (string) $response->getBody();
		$this->lastRawResponse   = $rawResponse;
		$this->console_debug('PACO TransactionList raw (encrypted)', [
			'rawResponse' => $rawResponse,
		]);

		$token                    = $rawResponse;
		$decryptingKey            = $this->GetPrivateKey(SecurityData::get_merchant_decryption_private_key());
		$signatureVerificationKey = $this->GetPublicKey(SecurityData::get_paco_signing_public_key());

		$decrypted = $this->DecryptToken($token, $decryptingKey, $signatureVerificationKey);
		$decoded   = \json_decode($decrypted, true);

		$this->console_debug('PACO TransactionList decrypted', [
			'decoded' => $decoded ?? $decrypted,
		]);

		return $decrypted;
	}

	/**
	 * Execute a PACO refund and return decrypted response.
	 *
	 * @throws GuzzleException|\Exception
	 */
	public function ExecuteRefundJose(string $orderNo, float $amount, string $currency = 'NPR'): string
	{
		$now = Carbon::now();

		$amountFormatted = number_format($amount, 2, '.', '');
		$amountText      = str_pad(str_replace(['.', ','], '', $amountFormatted), 12, '0', STR_PAD_LEFT);

		$request = [
			'apiRequest' => [
				'requestMessageID' => $this->Guid(),
				'requestDateTime'  => $now->utc()->format('Y-m-d\TH:i:s.v\Z'),
				'language'         => 'en-US',
			],
			'officeId'     => SecurityData::get_merchant_id(),
			'orderNo'      => $orderNo,
			'refundAmount' => [
				'AmountText'    => $amountText,
				'CurrencyCode'  => $currency,
				'DecimalPlaces' => 2,
				'Amount'        => (float) $amountFormatted,
			],
			'refundItems'        => [],
			'localMakerChecker'  => [
				'maker' => [
					'username' => \function_exists('wp_get_current_user') ? \wp_get_current_user()->user_login : 'Admin',
					'email'    => \function_exists('wp_get_current_user') ? \wp_get_current_user()->user_email : '',
				],
			],
		];

		$payload = [
			'request'       => $request,
			'iss'           => SecurityData::get_access_token(),
			'aud'           => 'PacoAudience',
			'CompanyApiKey' => SecurityData::get_access_token(),
			'iat'           => $now->unix(),
			'nbf'           => $now->unix(),
			'exp'           => $now->addHour()->unix(),
		];

		$stringPayload = \wp_json_encode($payload);
		$signingKey    = $this->GetPrivateKey(SecurityData::get_merchant_signing_private_key());
		$encryptingKey = $this->GetPublicKey(SecurityData::get_paco_encryption_public_key());

		$body = $this->EncryptPayload($stringPayload, $signingKey, $encryptingKey);

		$response = $this->client->post('api/1.0/Refund/refund', [
			'headers' => [
				'Accept'        => 'application/jose',
				'Content-Type'  => 'application/jose; charset=utf-8',
				'CompanyApiKey' => SecurityData::get_access_token(),
			],
			'body' => $body,
		]);

		$token              = (string) $response->getBody();
		$this->lastRawResponse = $token;
		$decryptingKey            = $this->GetPrivateKey(SecurityData::get_merchant_decryption_private_key());
		$signatureVerificationKey = $this->GetPublicKey(SecurityData::get_paco_signing_public_key());

		$decrypted = $this->DecryptToken($token, $decryptingKey, $signatureVerificationKey);
		$decoded   = \json_decode($decrypted, true);
		$this->log_debug('PACO Refund Response (decrypted)', ['decrypted' => $decoded ?? $decrypted]);
		$this->console_debug('PACO Refund Response (decrypted)', ['decrypted' => $decoded ?? $decrypted]);

		return $decrypted;
	}
}
