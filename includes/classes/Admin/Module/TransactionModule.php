<?php

namespace bKash\PGW\DC\Admin\Module;

use bKash\PGW\DC\Admin\AdminUtility;
use bKash\PGW\DC\BkashApi;
use bKash\PGW\DC\Models\Transaction;
use bKash\PGW\DC\WC_Geteway_bKash_Dc;
use bKash\PGW\DC\Sanitizer;
use Exception;

class TransactionModule {
	/**
	 * @return void
	 */
	public static function transactionList() {
		AdminUtility::loadTable(
			'All bKash Transactions',
			'bkash_dc_transactions',
			array(
				'ORDER ID'         => 'order_id_wcdc',
				'INVOICE ID'       => 'invoice_id',
				'PAYMENT ID'       => 'payment_id',
				'TRANSACTION ID'   => 'trx_id',
				'Agreement ID'     => 'agreement_id',
				'AMOUNT'           => 'amount',
				'Service Fee'      => 'serviceFee',
				'STATUS'           => 'status',
				'INTEGRATION TYPE' => 'integration_type',
				'INTENT'           => 'intent',
				'MODE'             => 'mode',
				// 'REFUND'           => array( 'refund_id', 'refund_amount' ),
				'DATETIME'         => 'datetime',
				'Action'		   => function( $row ) {
					$transactionTime = strtotime( $row->datetime );
					if ( 
						( isset($row->payment_id) && !empty($row->payment_id) ) &&
						($row->status === 'Initiated' || $row->status === 'CALLBACK_REACHED') &&
						$transactionTime >= strtotime( '-7 days' ) 
					) {
						return sprintf(
							'<a href="#" class="button button-primary button-small bkash-query-btn" data-payment-id="%s">Query</a>',
							esc_attr( $row->payment_id )
						);
					}

					if ( $row->status === 'Authorized' && $transactionTime >= strtotime( '-7 days' ) 
					) {
						return sprintf(
							'<a href="#" class="button button-primary button-small bkash-capture-btn" data-order-id="%d" style="background: #dba617; border-color: #c9970d; color: #fff;">Capture</a>
							<a href="#" class="button button-secondary button-small bkash-void-btn" data-order-id="%1$d" style="margin-left:2px; background: #d63638; border-color: #b32d2e; color: #fff;">Void</a>',
							$row->order_id_wcdc
						);
					}
					return '';
				}
			),
			array(
				'order_id_wcdc' => 'Order ID',
				'trx_id'     => 'Transaction ID',
				'invoice_id' => 'Invoice ID',
				'status' => array(
					'type' => 'select',
					'label' => 'Status',
					'options' => array(
						'Initiated',
						'CALLBACK_REACHED',
						'Authorized',
						'Completed',
						'Voided',
						'Cancelled',
						'Failed',
					),
				),
			),
			array(),
			'status != %s AND mode != %d',
			array( 'AgreementCompleted', 1000 )
		);
	}

	/**
	 * @return void
	 */
	public static function transactionSearch() {
		try {
			$trx_id = '';
			if ( Sanitizer::safeServerValue( 'REQUEST_METHOD' ) === 'POST' ) {
				check_admin_referer( 'bkash_transaction_search', 'bkash_transaction_search_nonce' );
				$trx_id = Sanitizer::safePostValue( 'trxid' );
			}

			if ( ! empty( $trx_id ) ) {
				$api  = new BkashApi();
				$call = $api->searchTransaction( $trx_id );

				if ( isset( $call['status_code'] ) && $call['status_code'] === 200 ) {
					$trx = array();
					if ( isset( $call['response'] ) && is_string( $call['response'] ) ) {
						$trx = json_decode( $call['response'], true );
					}

					// If any error
					if ( isset( $trx['statusMessage'] ) && $trx['statusMessage'] !== 'Successful' ) {
						$trx = $trx['statusMessage'];
					}
					if ( isset( $trx['errorMessageEn'] ) && ! empty( $trx['errorMessageEn'] ) ) {
						$trx = $trx['errorMessageEn'];
					}
				} else {
					$trx = 'Transaction information not found, try again';
				}
			}
		} catch ( Exception $ex ) {
			$trx = $ex->getMessage();
		}

		include_once BKASH_DC_BASE_PATH . '/includes/classes/Admin/pages/transaction_search.php';
	}

	/**
	 * @return void
	 */
	public static function queryPaymentStatus() {
		try {
			$payment_id = '';
			if ( Sanitizer::safeServerValue( 'REQUEST_METHOD' ) === 'POST' ) {
				check_admin_referer( 'bkash_query_payment', 'bkash_query_payment_nonce' );
				$payment_id = Sanitizer::safePostValue( 'payment_id' );
			}

			if ( ! empty( $payment_id ) ) {
				$transaction = new Transaction();
				$trx = $transaction->getTransactionByPaymentId( $payment_id );
				$agreement_id = $trx->getAgreementID() ?? '';

				$api  = new BkashApi();
				$call = $api->queryPayment( $payment_id, $agreement_id );

				if ( isset( $call['status_code'] ) && $call['status_code'] === 200 ) {
					$trx = array();
					if ( isset( $call['response'] ) && is_string( $call['response'] ) ) {
						$trx = json_decode( $call['response'], true );
					}

					// If any error
					if ( isset( $trx['statusMessage'] ) && $trx['statusMessage'] !== 'Successful' ) {
						$trx = $trx['statusMessage'];
					}
					if ( isset( $trx['errorMessageEn'] ) && ! empty( $trx['errorMessageEn'] ) ) {
						$trx = $trx['errorMessageEn'];
					}
				} else {
					$trx = 'Payment information not found, try again';
				}
			}
		} catch ( Exception $ex ) {
			$trx = $ex->getMessage();
		}
		
		include_once BKASH_DC_BASE_PATH . '/includes/classes/Admin/pages/query_payment.php';
	}
}
