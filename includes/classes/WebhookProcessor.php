<?php
/**
 * Webhook Processor
 *
 * @category    Api
 * @package     bkash-for-woocommerce
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2023 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

namespace bKash\PGW\DC;

use bKash\PGW\DC\Models\Webhook;
use DateTime;
use Exception;

class WebhookProcessor {
	private $payload;
	private $context = array( "source" => BKASH_DC_PLUGIN_SLUG );
	private $canSubscribe;

	private $messageType = "";
	private $signingCertURL;

	public function __construct( bool $canSubscribe = false, string $rawInput = '' ) {
		$this->canSubscribe = $canSubscribe;

		if ( '' === trim( $rawInput ) ) {
			$rawInput = file_get_contents( 'php://input' );
		}

		if ( is_string( $rawInput ) && '' !== trim( $rawInput ) ) {
			$this->payload = json_decode( $rawInput, false );
		}

		if ( $this->payload ) {
			$this->messageType = Sanitizer::safeServerValue( 'HTTP_X_AMZ_SNS_MESSAGE_TYPE' );
			$this->signingCertURL = isset( $this->payload->SigningCertURL ) ? esc_url_raw( sanitize_text_field( $this->payload->SigningCertURL ) ) : '';
		}
	}

	/**
	 * @return void
	 */
	final public function processRequest() {
		if ( $this->messageType === 'SubscriptionConfirmation' ) {
			$this->subscribe();

			return true;
		} elseif ( $this->messageType === 'Notification' ) {
			$this->storeNotification();

			return true;
		} else {
			$this->writeLog( "No method present to process the webhook" );

			return false;
		}
	}

	/**
	 * Subscribe a WebhookModule URL
	 * @return void
	 */
	final public function subscribe() {
		if ( $this->canSubscribe ) {
			if ( $this->payload && $this->verifySource() ) {
				if ( isset( $this->payload->SubscribeURL ) ) {
					$subscribeUrl = esc_url_raw( sanitize_text_field( $this->payload->SubscribeURL ) );
					$this->writeLog( "Subscribing to ==> " . $subscribeUrl );
					$subscriptionResponse = wp_remote_get( $subscribeUrl );
					if ( is_wp_error( $subscriptionResponse ) ) {
						$this->writeLog( "Error subscribing the URL: " . wp_json_encode( $subscriptionResponse ) );
					} else {
						$subscriptionResponse = wp_remote_retrieve_body( $subscriptionResponse );
						$this->writeLog( $subscriptionResponse );
					}
				} else {
					$this->writeLog( "Could not found subscription URL" );
				}
			} else {
				$this->writeLog( "WebhookModule source can not be verified" );
			}
		} else {
			$this->writeLog( "Subscription to webhook disabled from settings" );
		}
	}

	/**
	 * Supporting Operations
	 * @methods verifySource, validateURL, getContent, getStringToSign, writeLog
	 * @return bool
	 */
	final public function verifySource(): bool {
		if ( $this->signingCertURL ) {
			$isValidURL = $this->validateURL( $this->signingCertURL );
			if ( $isValidURL ) {
				$publicCert = $this->getContent( $this->signingCertURL );
				$this->writeLog( $publicCert );
				$signature = isset( $this->payload->Signature ) ? base64_decode( $this->payload->Signature ) : null;

				$formattedString = $this->getStringToSign( $this->payload );
				if ( $formattedString ) {
					$verify = openssl_verify( $formattedString, $signature, $publicCert, OPENSSL_ALGO_SHA1 );
					$this->writeLog( "Verifying ..." . wp_json_encode( $verify ) );

					return $verify;
				}
			}
		}

		return false;
	}

	/**
	 * @param string $url
	 *
	 * @return bool
	 */
	final public function validateURL( string $url ): bool {
		$url = esc_url_raw( sanitize_text_field( $url ) );
		$defaultHostPattern = '/^sns\.[a-zA-Z\d\-]{3,}\.amazonaws\.com(\.cn)?$/';
		$parsed             = wp_parse_url( $url );

		return ! (
			empty( $parsed['scheme'] ) || empty( $parsed['host'] )
			|| $parsed['scheme'] !== 'https' || substr( $url, - 4 ) !== '.pem'
			|| ! preg_match( $defaultHostPattern, $parsed['host'] )
		);
	}

	/**
	 * @param string $url
	 *
	 * @return string
	 */
	final public function getContent( string $url ): string {
		$body = '';
		$url = esc_url_raw( sanitize_text_field( $url ) );
		$response = wp_remote_get( $url );
		if ( is_wp_error( $response ) ) {
			$this->writeLog( "Error in getting content.. " . wp_json_encode( $response ) );
		} else {
			$body = wp_remote_retrieve_body( $response );
		}

		return $body;
	}

	/**
	 * @param mixed $logging_item
	 *
	 * @return void
	 */
	final public function writeLog( $logging_item ) {
		Log::debug( $logging_item );
	}

	/**
	 * @param $message
	 *
	 * @return string
	 */
	final public function getStringToSign( $message ): string {
		$signAbleKeys = [
			'Message',
			'MessageId',
			'Subject',
			'SubscribeURL',
			'Timestamp',
			'Token',
			'TopicArn',
			'Type'
		];

		$stringToSign = '';

		if ( isset( $message->SignatureVersion ) && $message->SignatureVersion !== '1' ) {
			$errorLog = "The SignatureVersion \"$message->SignatureVersion\" is not supported.";
			$this->writeLog( $errorLog );
		} else {
			foreach ( $signAbleKeys as $key ) {
				if ( isset( $message->$key ) ) {
					if ( is_string( $message->$key ) ) {
						$data = $message->$key;
					} else {
						$data = wp_json_encode( $message->$key );
					}
					$stringToSign .= "$key\n$data\n";
				}
			}
			$this->writeLog( $stringToSign . "\n" );
		}

		return $stringToSign;
	}

	/**
	 * Store WebhookModule notification payload
	 *
	 * @return bool
	 */
	final public function storeNotification(): bool {
		if ( $this->payload && $this->verifySource() ) {
			if ( is_string( $this->payload->Message ) ) {
				$this->payload->Message = json_decode( $this->payload->Message, false );
			}
			$message = $this->payload->Message;
			if ( $message ) {
				// process date format
				try {
					$parseDate = DateTime::createFromFormat( 'YmdHis', $message->dateTime );
				} catch ( Exception $e ) {
					$parseDate = date( 'Y-m-d H:i:s' );
				}

				$webhooks = new Webhook();
				$webhooks->setSender( sanitize_text_field( $message->debitMSISDN ?? '' ) );
				$webhooks->setReceiver( sanitize_text_field( $message->creditShortCode ?? '' ) );
				$webhooks->setAmount( isset( $message->amount ) ? (float) $message->amount : '' );
				$webhooks->setTrxId( sanitize_text_field( $message->trxID ?? '' ) );
				$webhooks->setCurrency( sanitize_text_field( $message->currency ?? '' ) );
				$webhooks->setDatetime(
					$parseDate->format( "Y-m-d H:i:s" )
				);
				$webhooks->setType( sanitize_text_field( $message->transactionType ?? '' ) );
				if ( isset( $message->creditOrganizationName ) ) {
					$webhooks->setReceiverName( sanitize_text_field( $message->creditOrganizationName ) );
				}
				$webhooks->setStatus( sanitize_text_field( $message->transactionStatus ?? '' ) );
				$isSaved = $webhooks->save();
				$this->writeLog( "Saving webhook payment, " . wp_json_encode( $isSaved ) );
				if ( $isSaved ) {
					$this->writeLog( "Payment added successfully, " . wp_json_encode( $message ) );

					return true;
				}

				$this->writeLog( "Payment can't be added, " . wp_json_encode( $webhooks->errorMessage ) );
			}
		}

		return false;
	}
}
