<?php 
/**
 * Api Communicator
 *
 * @category    Api
 * @package     woo-bkash-dynamic-charging
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2026 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

namespace bKash\PGW\DC;

use Exception;
use Throwable;
use UnexpectedValueException;

class BkashApi {

	public $debug;
	private $enabled;
	private $integration_product = 'paymentonly';
	private $intent;
	private $sandbox;
	private $api_version;
	private $enable_b2c;
	private $app_key;
	private $app_secret;
	private $username;
	private $password;
	private $constructed_url;
	/**
	 * @var false|mixed|void|null
	 */
	private $token;

	public function __construct() {
		/* Initializing parameters using required fields from calling class */
		$this->getSettingsConfig();

		/* Constructing API URL for later use */
		$this->generatePaymentURL();

		/* Initiate Token Generate Process */
		$this->processToken();
	}

	final public function getSettingsConfig() {
		$this->enabled 	  = $this->getOptions( 'enabled' );
		$this->integration_product = $this->getOptions( 'integration_type', 'paymentonly' );
		$this->intent              = $this->getOptions( 'intent', 'sale' );
		$this->api_version         = $this->getOptions( 'bkash_api_version', 'v1' );
		$this->enable_b2c = $this->getOptions( 'enable_b2c' );
		$this->sandbox    = $this->getOptions( 'sandbox', false );
		$this->app_key    = $this->getOptions( 'app_key' );
		$this->app_secret = $this->getOptions( 'app_secret' );
		$this->username   = $this->getOptions( 'username' );
		$this->password   = $this->getOptions( 'password' );
		$this->debug      = $this->getOptions( 'debug', 'no' );
		
	}

	final public function getOptions( $key, $default = null ) {
		$settings = get_option( 'woocommerce_' . BKASH_DC_PLUGIN_SLUG . '_settings' );
		if ( ! is_null( $settings ) ) {
			return $settings[ $key ] ?? $default;
		}

		return $default;
	}

	private function generatePaymentURL() {
		if($this->enabled === 'yes') {
			if($this->sandbox === 'yes') {
				$api_Base_url = "https://sbdynamic.pay.bka.sh/";
			} else {
				$api_Base_url = "https://dynamic.pay.bka.sh/";
			}
			
			$this->constructed_url = $api_Base_url. $this->api_version . "/";
		}
		else {
			Log::error( 'bKash Payment Plugin Not Activate!' );
		}
	}

	private function processToken() {
		try {
			$token   = get_option( 'bkash_dc_grant_token' );
			$expiry  = get_option( 'bkash_dc_grant_token_expiry' );
			$product = get_option( 'bkash_dc_integration_product' );

			// Validate stored token format and expiry
			if ( $this->integration_product === $product &&
				 ! empty( $token ) &&
				 is_numeric( $expiry ) &&
				 ( $expiry - time() > 0 ) &&
				 $this->isValidTokenFormat( $token ) ) {

				$this->token = $token;
			} else {
				Log::debug( 'Token expired, invalid, or missing - refreshing from API' );
				$this->readTokenFromAPI();
			}
		} catch ( Exception $e ) {
			Log::debug( $e );
			Log::error( 'bKash PGW ERROR: exception generated while processing token: ' . $e->getMessage() );
			// Reset token on any exception to force fresh token on next attempt
			$this->resetToken();
		}
	}

	final public function isTokenValid(): bool {
		if ( empty( $this->token ) ) {
			return false;
		}

		$expiry = get_option( 'bkash_dc_grant_token_expiry' );
		return is_numeric( $expiry ) && $expiry > time() && $this->isValidTokenFormat( $this->token );
	}

	final public function resetToken() {
		delete_option( 'bkash_dc_grant_token' );
		delete_option( 'bkash_dc_grant_token_expiry' );
		delete_option( 'bkash_dc_integration_product' );
	}

	private function readTokenFromAPI() {
		if ( empty( $this->app_key ) || empty( $this->app_secret ) || empty( $this->username ) || empty( $this->password ) ) {
			Log::error( 'App key, secret, username, or password is not set, required for bKash APIs' );
			return;
		}

		$get_token = $this->getToken();
		if ( isset( $get_token['status_code'] ) && $get_token['status_code'] === 200 ) {
			$response = json_decode( $get_token['response'], true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error( 'Invalid JSON response from token API' );
				return;
			}

			if ( isset( $response['id_token'] ) && ! empty( $response['id_token'] ) ) {
				// Validate token format (basic JWT structure check)
				if ( ! $this->isValidTokenFormat( $response['id_token'] ) ) {
					Log::error( 'Invalid token format received from API' );
					return;
				}

				$this->token = sanitize_text_field( $response['id_token'] );

				// Add buffer to expiry time (refresh 5 minutes before actual expiry)
				$expires_in = isset( $response['expires_in'] ) ? intval( $response['expires_in'] ) : 3600;
				$expiry     = time() + $expires_in - 300; // 5 minutes buffer

				$this->addOrUpdateOption( 'bkash_dc_grant_token', $this->token );
				$this->addOrUpdateOption( 'bkash_dc_grant_token_expiry', $expiry );
				$this->addOrUpdateOption( 'bkash_dc_integration_product', $this->integration_product );

				Log::debug( 'Token successfully refreshed and stored' );
			} else {
				Log::error( 'Token refresh failed: no id_token in response' );
			}
		} else {
			Log::error( 'Token refresh failed: API returned status ' . ( $get_token['status_code'] ?? 'unknown' ) );
		}
	}

	final public function getToken(): array {
		$url = $this->constructed_url . 'auth/grant-token';
		// Validate required credentials before making API call
		if ( empty( $this->app_key ) || empty( $this->app_secret ) || empty( $this->username ) || empty( $this->password ) ) {
			Log::error( 'Missing required credentials for token request' );
			return array(
				'status_code' => 400,
				'header'      => null,
				'response'    => wp_json_encode( array( 'error' => 'Missing credentials' ) ),
			);
		}

		$body = array(
			'app_key'    => sanitize_text_field( $this->app_key ),
			'app_secret' => sanitize_text_field( $this->app_secret ),
		);

		$response = $this->httpRequest( 'Grant Token', $url, $http_status, 'POST', $body, $header, true );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	private function isValidTokenFormat( string $token ): bool {
		// Basic JWT format validation (header.payload.signature)
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return false;
		}

		// Check if each part is base64url encoded
		foreach ( $parts as $part ) {
			if ( empty( $part ) || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $part ) ) {
				return false;
			}
		}

		return true;
	}

	final public function createPayment( array $params, string $mode ): array {
		$url = $this->constructed_url . 'payment/create';
		$api_title = ($mode === '1011') ? 'Create Payment' : 'Create Payment With Agreement';

		$body = array(
			'mode'                    => $mode,
			'agreementId'             => $params['agreementId'] ?? '',
			'payerReference'          => $params['payerReference'] ?? '',
			'callbackURL'             => $params['callbackURL'] ?? '',
			'amount'                  => $params['amount'] ?? '',
			'currency'                => $params['currency'] ?? '',
			'intent'                  => $params['intent'] ?? '',
			'merchantInvoiceNumber'   => $params['merchantInvoiceNumber'] ?? '',
			'merchantAssociationInfo' => $params['merchantAssociationInfo'] ?? '',
		);

		$response = $this->httpRequest( $api_title, $url, $http_status, 'POST', $body, $header );
		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	final public function executePayment( string $payment_id, string $agreement_id = '' ): array {
		$url      = $this->constructed_url . 'payment/execute';
		$apiTitle = 'DC Payment Execute';
		$body     = array( 
			'paymentId' => $payment_id,
			'agreementId' => $agreement_id
		);

		$response = $this->httpRequest( $apiTitle, $url, $http_status, 'POST', $body, $header );

		// QUERY PAYMENT IN CASE OF ANY NETWORK OR NO RESPONSE OR TIMED OUT ISSUE
		$decoded_response = isset( $response['response'] ) && is_string( $response['response'] ) ?
			json_decode( $response['response'], true ) : array();

		if ( $http_status !== 200 || isset( $decoded_response['message'] ) ) {
			return $this->queryPayment( $payment_id, $agreement_id );
		}

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	final public function queryPayment( string $payment_id, string $agreement_id = '' ): array {
		$url = $this->constructed_url . 'query/payment';

		$body = array(
			'paymentId' => $payment_id,
			'agreementId' => $agreement_id
		);

		$response = $this->httpRequest( 'Query Payment', $url, $http_status, 'POST', $body, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	/**
	 * Create Agreement
	 *
	 * Calls the bKash API to create an agreement.
	 * @param array $params ['payerReference', 'callbackURL']
	 * @return array
	 */
	final public function agreementCreate(array $params): array {
		$url = $this->constructed_url . 'agreement/create';
		$body = [
			'payerReference' => $params['payerReference'] ?? '',
			'callbackURL'    => $params['callbackURL'] ?? '',
		];

		$response = $this->httpRequest('Create Agreement', $url, $http_status, 'POST', $body, $header);
		return [
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		];
	}

	/**
	 * Execute Agreement
	 *
	 * Calls the bKash API to execute an agreement.
	 * @param string $agreementId
	 * @return array
	 */
	final public function agreementExecute(string $agreementId): array {
		$url = $this->constructed_url . 'agreement/execute';
		$body = [
			'agreementId' => $agreementId,
		];

		$response = $this->httpRequest('Execute Agreement', $url, $http_status, 'POST', $body, $header);

		return [
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		];
	}

	/**
	 * Agreement Query
	 *
	 * Get agreement status using bKash agreement ID.
	 *
	 * @param string $agreement_id
	 *
	 * @return array
	 */
	final public function queryAgreement( string $agreement_id ): array {
		$url = $this->constructed_url . 'query/agreement';

		$body = array(
			'agreementId' => $agreement_id,
		);

		$response = $this->httpRequest( 'Agreement Query', $url, $http_status, 'POST', $body, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	/**
	 * Agreement Cancellation
	 *
	 * Cancel an agreement using bKash agreement ID.
	 *
	 * @param string $agreement_id
	 *
	 * @return array
	 */
	final public function agreementCancel( string $agreement_id ): array {
		$url = $this->constructed_url . 'agreement/cancel';

		$body = array(
			'agreementId' => $agreement_id,
		);

		$response = $this->httpRequest( 'Agreement Cancel', $url, $http_status, 'POST', $body, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	final public function searchTransaction( string $trx_id ): array {
		$url = $this->constructed_url.'search/transaction';

		if ( !empty($trx_id) ) {
			$body = array(
				'trxID' => $trx_id,
			);

			$response = $this->httpRequest( 'Search Transaction', $url, $http_status, 'POST', $body, $header );
		}

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	final public function refund( $amount, $paymentID, $trxID, $SKU, $reason, $agreementID = '' ): array {
		$url = $this->constructed_url . 'payment/refund';

		$body = array(
			'amount'    => $amount,
			'paymentId' => $paymentID,
			'trxID'     => $trxID,
			'sku'       => $SKU,
			'reason'    => $reason,
		);

		if ( ! empty( $agreementID ) ) {
			$body['agreementId'] = $agreementID;
		}

		$response = $this->httpRequest( 'Refund Transaction', $url, $http_status, 'POST', $body, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	final public function refundStatus( $paymentID, $trxID, $agreementID = '' ): array {
		$url = $this->constructed_url . 'query/refund';

		$body = array(
			'paymentId' => $paymentID,
			'trxID'     => $trxID,
		);

		if ( ! empty( $agreementID ) ) {
			$body['agreementId'] = $agreementID;
		}

		$response = $this->httpRequest( 'Query Refund', $url, $http_status, 'POST', $body, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	final public function capturePayment( string $payment_id, string $mode = '0011', ?string $agreement_id = '' ): array {
		$body = array( 
			'paymentId' => $payment_id,
			'confirmationType' => 'capture'
		);

		if ( $mode === '1001' ) {
			$url      = $this->constructed_url . 'payment/confirm/';
			$apiTitle = 'DC Capture Payment With Agreement';
			if ( ! empty( $agreement_id ) ) {
				$body['agreementId'] = $agreement_id;
			}
		} elseif ( $mode === '1011' ) {
			$url      = $this->constructed_url . 'payment/confirm/';
			$apiTitle = 'DC Capture Payment';
		} else {
			return array(
				'status_code' => -1,
				'header'      => array(),
				'response'    => 'Invalid mode for capture payment',
			);
		}

		$response = $this->httpRequest( $apiTitle, $url, $http_status, 'POST', $body, $header );

		// QUERY PAYMENT IN CASE OF ANY NETWORK OR NO RESPONSE OR TIMED OUT ISSUE
		$decoded_response = isset( $response['response'] ) && is_string( $response['response'] ) ?
			json_decode( $response['response'], true ) : array();

		if ( $http_status !== 200 || isset( $decoded_response['message'] ) ) {
			return $this->queryPayment( $payment_id, $agreement_id );
		}

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	final public function voidPayment( string $payment_id, string $mode = '0011', ?string $agreement_id = '' ): array {
		$body = array( 
			'paymentId' => $payment_id,
			'confirmationType' => 'void' 
		);

		if ( $mode === '1001' ) {
			$url      = $this->constructed_url . 'payment/confirm/';
			$apiTitle = 'DC Void Payment With Agreement';
			if ( ! empty( $agreement_id ) ) {
				$body['agreementId'] = $agreement_id;
			}
		} elseif ( $mode === '1011' ) {
			$url      = $this->constructed_url . 'payment/confirm/';
			$apiTitle = 'DC Void Payment';
		} else {
			return array(
				'status_code' => -1,
				'header'      => array(),
				'response'    => 'Invalid mode for void payment',
			);
		}

		$response = $this->httpRequest( $apiTitle, $url, $http_status, 'POST', $body, $header );

		// QUERY PAYMENT IN CASE OF ANY NETWORK OR NO RESPONSE OR TIMED OUT ISSUE
		$decoded_response = isset( $response['response'] ) && is_string( $response['response'] ) ?
			json_decode( $response['response'], true ) : array();

		if ( $http_status !== 200 || isset( $decoded_response['message'] ) ) {
			return $this->queryPayment( $payment_id, $agreement_id );
		}

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
	}

	final public function checkBalances(): array {
		$url = $this->constructed_url . 'query/organization/balance';

		$response = $this->httpRequest( 'Query Organization Balance', $url, $http_status, 'GET', null, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);

		// throw new UnexpectedValueException( 'Query organization balance is only available in Checkout integration' );
	}

	final public function initiatePayout( $type ): array {
		$url = $this->constructed_url . 'payout/initiate';

		$body = array(
			'type' => $type,
    		'reference' => $type  
		);

		$response = $this->httpRequest( 'Initiate Payout', $url, $http_status, 'POST', $body, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
		// throw new UnexpectedValueException( 'Intra Account Transfer is only available in Checkout integration' );
	}

	final public function intraAccountTransfer( $amount, string $transferType ): array {
		$url = $this->constructed_url . 'payout/intra-account/transfer';
		
		$initiatePayoutResponse = $this->initiatePayout("INTRA");
		$arrayResponse = json_decode($initiatePayoutResponse['response'], true);
		$payoutId = $arrayResponse['payoutID'];

		$body = array(
			'payoutID' => $payoutId,
		    'amount' => $amount,
		    'currency' => 'BDT',
		    'transferType' => $transferType
		);

		$response = $this->httpRequest( 'Intra Account Transfer', $url, $http_status, 'POST', $body, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);
		// throw new UnexpectedValueException( 'Intra Account Transfer is only available in Checkout integration' );
	}

	final public function b2cPayout( $amount, string $invoiceNumber, string $receiver ): array {
		$url = $this->constructed_url . 'payout/b2c';

		$initiatePayoutResponse = $this->initiatePayout("B2C");
		$arrayResponse = json_decode($initiatePayoutResponse['response'], true);
		$payoutId = $arrayResponse['payoutID'];

		$body = array(
			'payoutID' 				=> $payoutId,
		    'amount' 				=> $amount,
		    'currency' 				=> "BDT",
		    'merchantInvoiceNumber' => $invoiceNumber,
		    'receiverMSISDN' 		=> $receiver
		);

		$response = $this->httpRequest( 'B2C Payout', $url, $http_status, 'POST', $body, $header );

		return array(
			'status_code' => $http_status,
			'header'      => $header,
			'response'    => $response,
		);

		// throw  new UnexpectedValueException( 'B2C Payout is only available in Checkout integration' );
	}

	final public function httpRequest($api_title,$url,&$http_status,$method = 'POST',$post_data = null,&$header = null,$grantHeader = false
	): string {
		$log  = "\n======== bKash PGW REQUEST LOG ========== \n\nAPI TITLE: $api_title \n";
		$log .= "REQUEST METHOD: $method \n";
		$log .= "REQUEST URL: $url \n";

		$headers                 = array();
		$headers['Accept']       = 'application/json';
		$headers['Content-Type'] = 'application/json';
		if ( $grantHeader ) {
			$headers['username'] = $this->username;
			$headers['password'] = $this->password;
		} else {
			$headers['authorization'] = $this->token;
			$headers['x-app-key']     = $this->app_key;
			// $headers['authorization'];
		}
		if ( ! is_null( $header ) ) {
			$headers = array_merge( $headers, $header );
		}

		// Log sanitized headers (exclude sensitive credentials and tokens)
		$safe_headers = $headers;
		unset( $safe_headers['username'], $safe_headers['password'], $safe_headers['authorization'], $safe_headers['x-app-key'] );
		$log .= 'HEADERS: ' . wp_json_encode( $safe_headers ) . "\n";

		$safe_body = $this->redactSensitiveData( $post_data );
		$log .= 'BODY: ' . $this->normalizeBodyForLog( $safe_body ) . "\n";

		$response = wp_remote_post(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 29,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => $headers,
				'body'        => strtolower( $method ) === 'get' ? $post_data : wp_json_encode( $post_data ),
			)
		);

		$body          = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		$safe_response = $this->redactSensitiveData( $body );
		$log .= 'RESPONSE: ' . $this->normalizeBodyForLog( $safe_response ) . "\n\n";

		if ( is_wp_error( $response ) ) {
			$http_status = - 1;
			$body        = $response->get_error_message();

			Log::error( 'CURL Error occurred during API request to ' . $api_title );
		} else {
			// parsing http status code
			$http_status = wp_remote_retrieve_response_code( $response );

			if ( $http_status === 401 && ! $grantHeader ) {
				// Token might be expired, try to refresh once
				Log::debug( 'Received 401, attempting token refresh' );
				$this->resetToken();
				$this->processToken();

				if ( $this->isTokenValid() ) {
					// Retry the request with new token
					Log::debug( 'Retrying request with refreshed token' );
					return $this->httpRequest( $api_title, $url, $http_status, $method, $post_data, $header, $grantHeader );
				} else {
					Log::error( 'Token refresh failed, cannot retry request' );
				}
			}

			$header = wp_remote_retrieve_headers( $response );
			$body   = wp_remote_retrieve_body( $response );
		}

		// Only log non-sensitive request details
		if ( $this->debug === 'yes' ) {
			Log::debug( $log );
		}

		return $body;
	}

	private function normalizeBodyForLog( $data ) {
		if ( is_array( $data ) ) {
			return wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		}

		if ( is_string( $data ) ) {
			return $data;
		}

		return wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
	}

	private function redactSensitiveData( $data ) {
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $this->redactSensitiveData( $decoded );
			}

			return $data;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( $this->shouldRedactKey( $key ) ) {
					$data[ $key ] = 'REDACTED';
				} elseif ( is_array( $value ) || is_string( $value ) ) {
					$data[ $key ] = $this->redactSensitiveData( $value );
				}
			}
		}

		return $data;
	}

	private function shouldRedactKey( $key ) {
		return in_array( strtolower( $key ), array(
			'app_secret',
			'password',
			'username',
			'authorization',
			'x-app-key',
			'id_token',
			'refresh_token',
			'access_token',
			'token',
			'secret',
		), true );
	}

	private function addOrUpdateOption( $key, $value ) {
		if ( ! get_option( $key ) ) {
			add_option( $key, $value );
		} else {
			update_option( $key, $value );
		}
	}
}