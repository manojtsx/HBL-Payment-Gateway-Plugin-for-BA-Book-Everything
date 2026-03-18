<?php

namespace Nexhbp\HimalayanBank;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\AudienceChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\ExpirationTimeChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\InvalidClaimException;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Checker\MissingMandatoryClaimException;
use Jose\Component\Checker\NotBeforeChecker;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A128CBCHS256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP;
use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\JWELoader;
use Jose\Component\Encryption\JWETokenSupport;
use Jose\Component\Encryption\Serializer\CompactSerializer as JWECompactSerializer;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSLoader;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer as JWSCompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Jose\Easy\ContentEncryptionAlgorithmChecker;
use Psr\Http\Message\RequestInterface;

abstract class ActionRequest {
	protected Client $client;

	private JWSCompactSerializer $jwsCompactSerializer;
	private JWSBuilder $jwsBuilder;
	private JWSLoader $jwsLoader;
	private ClaimCheckerManager $claimCheckerManager;

	private JWECompactSerializer $jweCompactSerializer;
	private JWEBuilder $jweBuilder;
	private JWELoader $jweLoader;

	public function __construct() {
		$handler = HandlerStack::create();

		$handler->push( Middleware::mapRequest( function ( RequestInterface $request ) {
			return $request->withoutHeader( 'User-Agent' );
		} ) );

		$this->client = new Client( [
			'base_uri' => $this->getPaymentEndpoint(),
			'handler'  => $handler,
		] );

		$this->jwsCompactSerializer = new JWSCompactSerializer();
		$this->jwsBuilder           = new JWSBuilder(
			signatureAlgorithmManager: new AlgorithmManager(
				algorithms: [
					new PS256(),
				]
			)
		);
		$this->jwsLoader = new JWSLoader(
			serializerManager: new JWSSerializerManager(
				serializers: [
					new JWSCompactSerializer(),
				]
			),
			jwsVerifier: new JWSVerifier(
				signatureAlgorithmManager: new AlgorithmManager(
					algorithms: [
						new PS256(),
					]
				)
			),
			headerCheckerManager: new HeaderCheckerManager(
				checkers: [
					new AlgorithmChecker(
						supportedAlgorithms: [ SecurityData::$JWSAlgorithm ],
						protectedHeader: true
					),
				],
				tokenTypes: [
					new JWSTokenSupport(),
				]
			),
		);
		$this->claimCheckerManager = new ClaimCheckerManager(
			checkers: [
				new NotBeforeChecker(),
				new ExpirationTimeChecker(),
				new AudienceChecker( SecurityData::get_access_token() ),
				new IssuerChecker( [ 'PacoIssuer' ] ),
			]
		);

		$this->jweCompactSerializer = new JWECompactSerializer();
		$this->jweBuilder           = new JWEBuilder(
			keyEncryptionAlgorithmManager: new AlgorithmManager(
				algorithms: [
					new RSAOAEP(),
				]
			),
			contentEncryptionAlgorithmManager: new AlgorithmManager(
				algorithms: [
					new A128CBCHS256(),
				]
			),
			compressionManager: new CompressionMethodManager(
				methods: []
			)
		);
		$this->jweLoader = new JWELoader(
			serializerManager: new JWESerializerManager(
				serializers: [
					new JWECompactSerializer(),
				]
			),
			jweDecrypter: new JWEDecrypter(
				keyEncryptionAlgorithmManager: new AlgorithmManager(
					algorithms: [
						new RSAOAEP(),
					]
				),
				contentEncryptionAlgorithmManager: new AlgorithmManager(
					algorithms: [
						new A128CBCHS256(),
					]
				),
				compressionMethodManager: new CompressionMethodManager(
					methods: [],
				)
			),
			headerCheckerManager: new HeaderCheckerManager(
				checkers: [
					new AlgorithmChecker(
						supportedAlgorithms: [ SecurityData::$JWEAlgorithm ],
						protectedHeader: true
					),
					new ContentEncryptionAlgorithmChecker(
						supportedAlgorithms: [ SecurityData::$JWEEncrptionAlgorithm ],
						protectedHeader: true
					),
				],
				tokenTypes: [
					new JWETokenSupport(),
				]
			)
		);
	}

	private function getPaymentEndpoint(): string {
		// Production-only endpoint.
		return 'https://core.paco.2c2p.com/';
	}

	// protected function GetPrivateKey( string $key, ?string $password = null, array $additional_values = [] ): JWK {
	// 	$privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" . $key . "\n-----END RSA PRIVATE KEY-----";
	// 	return JWKFactory::createFromKey( $privateKey, $password, $additional_values );
	// }

	protected function GetPrivateKey( string $key, ?string $password = null, array $additional_values = [] ): JWK {
    $privateKey = "-----BEGIN PRIVATE KEY-----\n" . $key . "\n-----END PRIVATE KEY-----";
    return JWKFactory::createFromKey( $privateKey, $password, $additional_values );
}

	protected function GetPublicKey( string $key, ?string $password = null, array $additional_values = [] ): JWK {
		$publicKey = "-----BEGIN PUBLIC KEY-----\n" . $key . "\n-----END PUBLIC KEY-----";
		return JWKFactory::createFromKey( $publicKey, $password, $additional_values );
	}

	protected function EncryptPayload( string $payload, JWK $signingKey, JWK $encryptingKey ): string {
		$jws = $this->jwsBuilder
			->create()
			->withPayload( $payload )
			->addSignature( $signingKey, [
				'alg' => SecurityData::$JWSAlgorithm,
				'typ' => SecurityData::$TokenType,
			] )
			->build();

			$jwe = $this->jweBuilder
			->create()
			->withPayload( $this->jwsCompactSerializer->serialize( $jws ) )
			->withSharedProtectedHeader( [
				'alg' => SecurityData::$JWEAlgorithm,
				'enc' => SecurityData::$JWEEncrptionAlgorithm,
				'kid' => SecurityData::get_encryption_key_id(),
				'typ' => SecurityData::$TokenType,
			] )
			->addRecipient( $encryptingKey )
			->build();

		return $this->jweCompactSerializer->serialize( $jwe, 0 );
	}

	/**
	 * @throws InvalidClaimException
	 * @throws MissingMandatoryClaimException
	 * @throws Exception
	 */
	protected function DecryptToken( string $token, JWK $decryptingKey, JWK $signatureVerificationKey ): string {
		$jwe = $this->jweLoader->loadAndDecryptWithKey( $token, $decryptingKey, $recipient );
		$jws = $this->jwsLoader->loadAndVerifyWithKey( $jwe->getPayload(), $signatureVerificationKey, $signature );

		$tokenPayload = $jws->getPayload();
		$claims       = json_decode( $tokenPayload, true );

		$this->claimCheckerManager->check( $claims );

		return $tokenPayload;
	}

	protected function Guid(): string {
		if ( function_exists( 'com_create_guid' ) ) {
			return com_create_guid();
		}

		$charId = strtoupper( md5( uniqid( wp_rand(), true ) ) );
		$hyphen = chr( 45 );
		return strtolower(
			substr( $charId, 0, 8 ) . $hyphen
			. substr( $charId, 8, 4 ) . $hyphen
			. substr( $charId, 12, 4 ) . $hyphen
			. substr( $charId, 16, 4 ) . $hyphen
			. substr( $charId, 20, 12 )
		);
	}
}
