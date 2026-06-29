<?php 
namespace bKash\PGW\DC;

use bKash\PGW\DC\Models\Agreement;
use bKash\PGW\DC\BkashApi;
use bKash\PGW\DC\Models\Transaction;

class Payments {

	public $integration_type;
	private $bKashObj;

	public function __construct( string $integration_type ) {
		$this->integration_type = $integration_type;
		$this->bKashObj         = new BkashApi();
	}

	final public function createPayment(
		string $order_id,
		string $intent = 'sale',
		string $callbackURL = '',
		$transaction = null,
		string $agreementCallbackURL = '',
		string $useAgreementId = ''
	): array {
		// To receive order id and total
		$order    = wc_get_order( $order_id );
		$amount   = $order->get_total();
		$currency = get_woocommerce_currency();

		// To receive user id and order details
		$merchantCustomerId = $order->get_user_id();
		$merchantOrderId    = $order->get_order_number();

		// Check if already has agreement
		$storedAgreementID = '';
		$mode              = null;

		if ( ! empty( $useAgreementId ) ) {
			// Agreement ID passed directly (e.g. after agreement callback)
			$storedAgreementID = $useAgreementId;
		} elseif ( $this->integration_type === 'tokenized' || $this->integration_type === 'tokenized-both' ) {
			$isAgreement = Utils::hasPostField( 'agreement' );
			if ( ! $isAgreement ) {
				$isAgreement = Utils::hasGetField( 'agreement' );
			}

			$agreement_id = trim( html_entity_decode( Utils::safePostValue( 'dc_agreement_id' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( ! $agreement_id ) {
				$agreement_id = trim( html_entity_decode( Utils::safeGetValue( 'dc_agreement_id' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}

			if ( $this->integration_type === 'tokenized' ) 
			{
				// ── tokenized (With Agreement) ──
				// Tokenized requires logged-in user. Agreement is mandatory.
				if ( empty( $merchantCustomerId ) ) {
					wc_add_notice( 'Please login to proceed with bKash payment', 'error' );
					return array( 'result' => 'failure' );
				}

				if ( $agreement_id === 'new' ) {
					// Customer explicitly wants to add a new number → create agreement
					$agreementObj = new Agreement();
					$existingAgreement = $agreementObj->getAgreement( '', $merchantCustomerId );
					if ( $existingAgreement ) {
						$storedAgreementID = $existingAgreement->getAgreementID();
					} else {
						return $this->initiateAgreementCreation( $order_id, $agreementCallbackURL, $intent );
					}
				} elseif ( $agreement_id && $agreement_id !== 'no' ) {
					// Customer selected an existing agreement
					$storedAgreementID = $agreement_id;
				} else {
					// No agreement_id provided → look up stored agreement
					$agreementObj = new Agreement();
					$agreement    = $agreementObj->getAgreement( '', $merchantCustomerId );
					$storedAgreementID = $agreement ? $agreement->getAgreementID() : '';

					// If no stored agreement resolved, initiate agreement creation
					// Callback URL → same callback endpoint that triggers handleAgreementCallback
					if ( empty( $storedAgreementID ) ) {
						return $this->initiateAgreementCreation( $order_id, $agreementCallbackURL, $intent );
					}
				}
			}
			elseif ( $this->integration_type === 'tokenized-both' ) {
				// ── tokenized-both (With or Without Agreement) ──
				// Logged-in user with agreement → tokenized flow.
				// Guest user without agreement → checkout-url flow.
				if ( ! empty( $merchantCustomerId ) ) {
					// Logged-in user
					if ( $agreement_id === 'new' ) {
						// User chose to create a new agreement → initiate agreement creation
						$agreementObj = new Agreement();
						$existingAgreement = $agreementObj->getAgreement( '', $merchantCustomerId );
						if ( $existingAgreement ) {
							$storedAgreementID = $existingAgreement->getAgreementID();
						} else {
							return $this->initiateAgreementCreation( $order_id, $agreementCallbackURL, $intent );
						}
					} elseif ( $agreement_id === 'no' ) {
						// User explicitly chose "no agreement" → checkout-url flow (1011)
						$mode = '1011';
					} elseif ( $agreement_id ) {
						$storedAgreementID = $agreement_id;
					} else {
						// No selection → look up stored agreement, fallback to 1011
						$agreementObj = new Agreement();
						$agreement    = $agreementObj->getAgreement( '', $merchantCustomerId );
						$storedAgreementID = $agreement ? $agreement->getAgreementID() : '';
					}
				}
			}
		}

		// Validate storedAgreementID belongs to the logged-in user
		if ( ! empty( $storedAgreementID ) && ! empty( $merchantCustomerId ) ) {
			$agreementCheck = new Agreement();
			$foundAgreement = $agreementCheck->getAgreement( $storedAgreementID );
			if ( ! $foundAgreement || (int) $foundAgreement->getUserID() !== (int) $merchantCustomerId ) {
				wc_add_notice( 'Selected agreement does not belong to your account.', 'error' );
				return array( 'result' => 'failure', 'message' => 'Agreement validation failed.' );
			}
		}

		if ( ! $mode ) {
			$mode = Operations::getTokenizedPaymentMode(
				$this->integration_type,
				! empty( $useAgreementId ) ? false : ( $isAgreement ?? false ),
				$storedAgreementID
			);
		}

		$invoiceNumber = uniqid( 'bfw_', false ) . '_' . $merchantOrderId;
		$payment_payload = array(
			'mode'                  => $mode,
			'payerReference'        => uniqid( 'bKash_', false ) . '_' . $merchantCustomerId,
			'callbackURL'           => $callbackURL,
			'amount'                => $amount,
			'currency'              => $currency,
			'intent'                => $intent,
			'merchantInvoiceNumber' => $invoiceNumber,
		);

		// Add agreementID only for tokenized payment with agreement
		if ( ! empty( $storedAgreementID ) ) {
			$payment_payload['agreementId'] = $storedAgreementID;

			// Store agreementId in order meta for later use (capture/void)
			$order->update_meta_data( '_bkash_agreement_id', $storedAgreementID );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		}

		// if transaction is not prepared yet
		if ( empty( $transaction ) ) {
			/* Store Transaction in Database */
			$trx = new Transaction();
			$trx->setOrderID( $order_id );
			$trx->setAmount( $amount );
			$trx->setIntegrationType( $this->integration_type );
			$trx->setIntent( $intent );
			$trx->setCurrency( $currency );
			$trx->setMode( $mode ?? '' );
			$trx->setStatus( 'Initiated' );

			if ( ! empty( $payment_payload['merchantInvoiceNumber'] ) ) {
				$trx->setInvoiceID( $payment_payload['merchantInvoiceNumber'] );
			}

			$trxSaved = $trx->save();
		} else {
			$trxSaved = $transaction;
		}

		if ( $trxSaved ) {
			if ( isset( $payment_payload['callbackURL'] ) ) {
				$payment_payload['callbackURL'] .= '&invoiceID=' . $payment_payload['merchantInvoiceNumber'];
			}

			$createResponse = $this->bKashObj->createPayment( $payment_payload, $mode );

			if ( isset( $createResponse['status_code'] ) && $createResponse['status_code'] === 200 ){
				$response = array();
				if ( isset( $createResponse['response'] ) && is_string( $createResponse['response'] ) ) {
					$response = json_decode( $createResponse['response'], true );
				}

				if ( $response ) {
					// If any error for tokenized
					if ( isset( $response['statusMessage'] ) && $response['statusMessage'] !== 'Successful' ) {
						$message = $response['statusMessage'];
					} elseif ( isset( $response['errorCode'] ) ) { // If any error for checkout
						$message = $response['errorMessage'] ?? '';
					} elseif ( isset( $response['paymentId'] ) && ! empty( $response['paymentId'] ) ) {
						// Remove items from cart.
						WC()->cart->empty_cart();

						$updated = $trxSaved->update( array( 'payment_id' => $response['paymentId'] ) );
						if ( $updated ) {
							return array(
								'result'   => 'success',
								'redirect' => $response['bkashURL'],
							);
						}

						$message = $this->processResponse(
							'Cannot process this payment right now, payment ID issue'
						);
					} else {
						$message = $this->processResponse(
							'Cannot process this payment right now, unknown error message'
						);
					}
				} else {
					$message = $this->processResponse( 'Cannot process this payment right now, not a valid response' );
				}
			}
			else {
				$message = $this->processResponse( 'Cannot process this payment right now, error in communication' );
			}
		} else {
			$message = $trx->errorMessage;
		}

		wc_add_notice( $message, 'error' );

		return array(
			'result'  => 'failure',
			'message' => $message,
		);
	}

	final public function executePayment( string $orderPageURL, string $callbackURL = '', string $agreement_id = '' ) {
		$message = '';

		if ( Sanitizer::hasGetField( 'orderId' ) ) {
			$order_id   = Sanitizer::safeGetValue( 'orderId' );
			$payment_id = Sanitizer::safeGetValue( 'paymentId' );
			$invoice_id = Sanitizer::safeGetValue( 'invoiceID' );
			$status     = Sanitizer::safeGetValue( 'status' );
		} else {
			$order_id   = Sanitizer::safePostValue( 'orderId' );
			$payment_id = Sanitizer::safePostValue( 'paymentId' );
			$invoice_id = Sanitizer::safePostValue( 'invoiceID' );
			$status     = Sanitizer::safePostValue( 'status' );
		}

		// To receive order id
		$order       = wc_get_order( $order_id );
		$trx         = new Transaction();
		$transaction = $trx->getTransaction( $invoice_id );

		// Logger for diagnostics
		$logger = null;
		$log_context = array( 'source' => 'woo-bkash-dynamic-charging' );
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->debug( 'executePayment called', array_merge( $log_context, array( 'order_id' => $order_id, 'payment_id' => $payment_id, 'invoice_id' => $invoice_id, 'orderPageURL' => $orderPageURL, 'callbackURL' => $callbackURL ) ) );
		}

		if ( $status === 'success' ) {

			if ( $transaction && $transaction->getPaymentID() === $payment_id ) {
				$transaction->update(
					array(
						'status' => 'CALLBACK_REACHED',
					)
				);

				// EXECUTE OPERATION (with diagnostics)
				try {
					$response = $this->bKashObj->executePayment( $payment_id, $agreement_id );
					if ( $logger ) {
						$logger->debug( 'bKash executePayment response', array_merge( $log_context, array( 'response' => is_array( $response ) ? $response : (string) $response ) ) );
					}
				} catch ( \Throwable $e ) {
					if ( $logger ) {
						$logger->error( 'bKash executePayment exception: ' . $e->getMessage(), $log_context );
					}
					// Redirect back to checkout on fatal error to avoid blank page
					wc_add_notice( 'Payment processing error. Please try again or contact support.', 'error' );
					wp_safe_redirect( wc_get_checkout_url() );
					wp_die();
				}
				if ( isset( $response['status_code'] ) && $response['status_code'] === 200 ) {
					$mode = $transaction->getMode();

					if ( $mode === '0000' ) 
					{
						$agreementResp = Operations::processResponse( $response, 'agreementID' );

						if ( is_array( $agreementResp ) ) {
							if ( $agreementResp['agreementStatus'] === 'Completed' ) {
								$agreementObj = new Agreement();
								$agreementObj->setAgreementID( $agreementResp['agreementID'] ?? '' );
								$agreementObj->setMobileNo( $agreementResp['customerMsisdn'] ?? '' );
								$agreementObj->setDateTime( $agreementResp['agreementExecuteTime'] ?? '' );
								$agreementObj->setUserID( $order->get_user_id() );
								$stored = $agreementObj->save();

								if ( $stored ) {
									$transaction->update(
										array( 'mode' => '0001' ),
										array( 'payment_id' => $transaction->getPaymentID() )
									);
									$order->update_meta_data( '_bkmode', '0001' );
									$order->save();

									$createResp = $this->createPayment(
										$transaction->getOrderID(),
										$transaction->getIntent(),
										$callbackURL,
										$transaction
									);

									if ( isset( $createResp['redirect'] ) ) {
										wp_safe_redirect( $createResp['redirect'] );
										wp_die();
									}

									wp_send_json( $createResp );
								} else {
									$message = 'Agreement cannot be done right now, cannot store in db, try again. ' . $agreementObj->errorMessage;
									$message = $this->processResponse( $message );
								}
							} else {
								$message = $this->processResponse( 'Agreement cannot be done right now, try again' );
							}
						} else {
							$message = is_string( $agreementResp ) ? $agreementResp : '';
							$message = $this->processResponse( $message );
						}
					}
					else {
						// GET TRXID FROM BKASH RESPONSE
						$paymentResp = Operations::processResponse( $response, 'trxID' );

						if ( is_array( $paymentResp ) ) {
							// PAYMENT IS DONE SUCCESSFULLY, NOW START REST OF THE PROCESS TO UPDATE WC ORDER

							// Updating transaction status
							$status  = $paymentResp['transactionStatus'] ?? 'NO_STATUS_EXECUTE';
							$updated = $transaction->update(
								array(
									'status' => $status,
									'trx_id' => $paymentResp['trxID'] ?? '',
									'serviceFee' => $paymentResp['serviceFee'] ?? ''
								)
							);

							if ( $updated && isset( $paymentResp['trxID'] ) && ! empty( $paymentResp['trxID'] ) ) {
								// Payment complete.
								$transaction_id = $paymentResp['trxID'] ?? '';
								if ( $paymentResp['transactionStatus'] === 'Authorized' ) {
									$order->update_status( 'on-hold' );
									$order->set_transaction_id($transaction_id);
									$order->save();
								} elseif ( $paymentResp['transactionStatus'] === 'Completed' ) {
									$order->payment_complete();
									$order->set_transaction_id($transaction_id);
									$order->save();
							
								} else {
									$order->update_status( 'pending' );
								}

								// Store the transaction ID for WC 2.2 or later.
								// order->set_transaction_id() already handles transaction meta.

								// Add order note.
								$order->add_order_note(
									sprintf( 'bKash PGW payment approved (ID: %s)', $paymentResp['trxID'] )
								);

								Log::debug( 'bKash PGW payment approved (ID: ' . $paymentResp['trxID'] . ')' );

								// Reduce stock levels.
								wc_reduce_stock_levels( $order_id );

								// Return thank you page redirect.
								wp_safe_redirect( $orderPageURL );
								die();
							}

							if ( $updated && isset( $paymentResp['paymentId'] ) && ! empty( $paymentResp['paymentId'] ) ) {
								$msg = 'Transaction was not successful, last transaction status: ' . $paymentResp['transactionStatus'] ?? 'NO_STATUS_EXECUTE';

								wc_add_notice( $msg, 'error' );
								wp_safe_redirect( wc_get_checkout_url() );
								die();
							}
							$message = 'Could not get transaction status';
						} else {
							$message = is_string( $paymentResp ) ? $paymentResp : '';
						}

						$transaction->update(
							array(
								'status' => 'Failed',
							)
						);
						$order->add_order_note( 'bKash Payment: ' . $message );

						$message = $this->processResponse( $message );
					}
				}

				else {
					$message = $this->processResponse( 'Communication issue with payment gateway' );
				}
			}
		} else {
			// transaction failed/cancelled.
			$status = str_replace( array( 'cancel', 'failure' ), array( 'Cancelled', 'Failed' ), $status );
			if ( $transaction->getStatus() !== 'Completed' ) {
				$transaction->update(
					array(
						'status' => esc_html( $status ),
					)
				);
				$order->add_order_note( 'bKash Payment is not successful. Status => ' . esc_html( $status ) );
			} else {
				$order->add_order_note(
					'bKash Payment is already in Completed state. Tried to change Status to => '
					. esc_html( $status )
				);
			}

			$message = $this->processResponse( 'Transaction is ' . $status );
		}

		$order->add_order_note( 'bKash PGW payment declined (' . esc_html( $message ) . ')' );

		$message = wp_kses_post( $message );
		wc_add_notice( $message, 'error' );
		wp_safe_redirect( wc_get_cart_url() );
		
		// Return message to customer.
		die();
	}


	final public function processResponse( string $message ): string {
		return "<h3 style='color:#fff;font-weight:bold;margin:0;font-size:20px;line-height: 14px;'>Payment Failed</h3>" . wp_kses_post( $message );
	}

	/**
	 * Initiate Agreement Creation
	 *
	 * Calls the bKash agreementCreate API and returns the redirect URL or failure array.
	 * Reusable for both tokenized and tokenized-both integration types
	 *
	 * @param string $order_id      WooCommerce order ID
	 * @param string $callbackURL   The callback URL (should be the checkout page)
	 * @param string $intent        Payment intent (sale/authorization)
	 *
	 */
	final public function initiateAgreementCreation( string $order_id, string $callbackURL, string $intent = 'sale' ): array {
		$order              = wc_get_order( $order_id );
		$amount             = $order->get_total();
		$currency           = get_woocommerce_currency();
		$merchantCustomerId = $order->get_user_id();
		$merchantOrderId    = $order->get_order_number();

		$agreementPayload = array(
			'payerReference' => uniqid( 'bKash_', false ) . '_' . $merchantCustomerId,
			'callbackURL'    => $callbackURL,
		);

		$agreementResponse = $this->bKashObj->agreementCreate( $agreementPayload );
		if (
			isset( $agreementResponse['status_code'] ) && $agreementResponse['status_code'] === 200 &&
			isset( $agreementResponse['response'] ) && is_string( $agreementResponse['response'] )
		) {
			$agreementData = json_decode( $agreementResponse['response'], true );
			if ( isset( $agreementData['bkashURL'] ) && ! empty( $agreementData['bkashURL'] ) ) {
				// Store a pending transaction to track this agreement flow
				$trx = new Transaction();
				$trx->setOrderID( $order_id );
				$trx->setAmount( $amount );
				$trx->setIntegrationType( $this->integration_type );
				$trx->setIntent( $intent );
				$trx->setCurrency( $currency );
				$trx->setMode( '1000' );
				$trx->setStatus( 'AgreementInitiated' );
				$trx->setInvoiceID( uniqid( 'bfw_', false ) . '_' . $merchantOrderId );

				if ( isset( $agreementData['paymentID'] ) ) {
					$trx->setPaymentID( $agreementData['paymentID'] );
				}

				$trxSaved = $trx->save();
				// Redirect user to bKash for agreement approval
				return array(
					'result'   => 'success',
					'redirect' => $agreementData['bkashURL'],
				);
			}

			// bkashURL missing
			$errorMsg = $agreementData['statusMessage'] ?? 'Agreement creation failed, no redirect URL.';
			wc_add_notice( $errorMsg, 'error' );
			return array( 'result' => 'failure', 'message' => $errorMsg );
		}

		// API call failed
		wc_add_notice( 'Error communicating with bKash for agreement creation.', 'error' );
		return array( 'result' => 'failure', 'message' => 'Agreement creation failed.' );
	}

	/**
	 * Handle Agreement Callback
	 *
	 * Called when bKash redirects the user back after agreement approval.
	 * Executes the agreement, stores it in the database, and redirects to checkout.
	 *
	 * @param string $payment_id   The paymentID from bKash callback
	 * @param int    $user_id      The WordPress user ID
	 *
	 * @return array ['success' => bool, 'agreementID' => string, 'message' => string]
	 */
	final public function handleAgreementCallback( string $agreement_id, int $user_id ): array {
		$response = $this->bKashObj->agreementExecute( $agreement_id );
		if ( isset( $response['status_code'] ) && $response['status_code'] === 200 ) {
			$agreementResp = Operations::processResponse( $response, 'agreementId' );

			if ( is_array( $agreementResp ) ) {
				if ( ( $agreementResp['agreementStatus'] ?? '' ) === 'Completed' ) {
					$agreementObj = new Agreement();
					$agreementObj->setAgreementID( $agreementResp['agreementId'] ?? '' );
					$agreementObj->setMobileNo( $agreementResp['customerMsisdn'] ?? '' );
					$agreementObj->setDateTime( $agreementResp['agreementExecuteTime'] ?? '' );
					$agreementObj->setUserID( $user_id );
					$stored = $agreementObj->save();

					if ( $stored ) {
						return array(
							'success'     => true,
							'agreementID' => $agreementResp['agreementId'],
							'message'     => 'Agreement created successfully.',
							'data'        => $agreementResp,
						);
					}

					return array(
						'success' => false,
						'message' => 'Could not store agreement. ' . $agreementObj->errorMessage,
					);
				}

				return array(
					'success' => false,
					'message' => 'Agreement is not completed. Status: ' . ( $agreementResp['agreementStatus'] ?? 'unknown' ),
					'data'    => $agreementResp,				
				);
			}

			$errorMsg = is_string( $agreementResp ) ? $agreementResp : 'Invalid agreement response.';
			return array(
				'success' => false,
				'message' => $errorMsg,
			);
		}

		return array(
			'success' => false,
			'message' => 'Communication issue with payment gateway during agreement execution.',
		);
	}

	final public function cancelPayment( string $order_id ): array {
		// global $woocommerce;
		// To receive order id
		$order = wc_get_order( $order_id );
		if ( $order ) {
			if ( $order->get_status() === 'pending' ) {
				$trx         = new Transaction();
				$transaction = $trx->getTransactionByOrderId( $order_id );
				if ( $transaction ) {
					$transaction->update(
						array(
							'status' => 'Cancelled',
						)
					);
					$order->add_order_note( 'bKash Payment has been cancelled, either failed or customer cancelled' );
					$order->update_status( 'cancelled', 'Payment has been cancelled!' );

					return array(
						'result'   => 'success',
						'redirect' => null,
						'response' => 'Order cancelled!',
					);
				}

				return array(
					'result'  => 'failure',
					'message' => 'Transaction not found in bKash database',
				);
			}

			return array(
				'result'  => 'failure',
				'message' => 'Order is not in pending status to cancel the payment',
			);
		}

		return array(
			'result'  => 'failure',
			'message' => 'Order not found',
		);
	}
}