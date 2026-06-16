<?php
/**
 * Agreement Model
 *
 * @category    Model
 * @package     bkash-dynamic-charging
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2023 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

namespace bKash\PGW\DC\Models;

use bKash\PGW\DC\Utils;

class Agreement {
	public $errorMessage     = '';
	private $agreementID     = '';
	private $agreementStatus = '';
	private $userID          = '';
	private $mobileNo        = '';
	private $dateTime;
	private $tableName;
	private $wpdb;


	final public function __construct() {
		global $wpdb;
		$this->wpdb      = $wpdb;
		$this->tableName = $wpdb->prefix . 'bkash_dc_agreement_mapping';
		$this->dateTime  = date( 'now' );
	}

	/**
	 * @return mixed
	 */
	final public function getAgreementID(): string {
		return $this->agreementID;
	}

	/**
	 * @param mixed $agreementID
	 *
	 * @return Agreement
	 */
	final public function setAgreementID( $agreementID ): Agreement {
		$this->agreementID = $agreementID;

		return $this;
	}

	/**
	 * @return mixed
	 */
	final public function getAgreementStatus(): string {
		return $this->agreementStatus;
	}

	/**
	 * @param mixed $agreementStatus
	 *
	 * @return Agreement
	 */
	final public function setAgreementStatus( $agreementStatus ): Agreement {
		$this->agreementStatus = $agreementStatus;

		return $this;
	}

	/**
	 * @return mixed
	 */
	final public function getUserID(): string {
		return $this->userID;
	}

	/**
	 * @param mixed $userID
	 *
	 * @return Agreement
	 */
	final public function setUserID( $userID ): Agreement {
		$this->userID = $userID;

		return $this;
	}

	/**
	 * @return mixed
	 */
	final public function getMobileNo(): string {
		return $this->mobileNo;
	}

	/**
	 * @param mixed $mobileNo
	 *
	 * @return Agreement
	 */
	final public function setMobileNo( $mobileNo ): Agreement {
		$this->mobileNo = $mobileNo;

		return $this;
	}

	/**
	 * @return false|string
	 */
	final public function getDateTime() {
		return $this->dateTime;
	}

	/**
	 * @param mixed $dateTime
	 *
	 * @return Agreement
	 */
	final public function setDateTime( $dateTime ): Agreement {
		$this->dateTime = $dateTime;

		return $this;
	}


	/**
	 * Save this transaction in DB table
	 *
	 * table name: wp_bkash_agreement_mapping where wp_ is the prefix set by application
	 * Ensures only one agreement per user by updating existing or inserting new.
	 * Also creates/updates a WC_Payment_Token for compatibility with WooCommerce checkout.
	 *
	 * @return Agreement|false|null
	 */
	final public function save() {
		if ( empty( $this->agreementID ) ) {
			$this->errorMessage = 'Agreement ID field is missing, it is required';

			return false;
		}

		if ( empty( $this->userID ) ) {
			$this->errorMessage = 'User ID field is missing, it is required';

			return false;
		}

		// Check if an agreement already exists for this user
		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT ID FROM {$this->tableName} WHERE user_id = %s",
				$this->userID
			)
		);

		if ( $existing ) {
			// Update existing agreement
			$updated = $this->wpdb->update(
				$this->tableName,
				array(
					'agreement_token' => $this->agreementID,
					'phone'           => $this->mobileNo,
					'datetime'        => $this->dateTime,
				),
				array( 'user_id' => $this->userID )
			);

			$this->errorMessage = $this->wpdb->last_error;
			$save_result = $updated !== false ? $this : null;
		} else {
			// Insert new agreement
			$insert = $this->wpdb->insert(
				$this->tableName,
				array(
					'agreement_token' => $this->agreementID,
					'phone'           => $this->mobileNo,
					'user_id'         => $this->userID,
					'datetime'        => $this->dateTime,
				)
			);

			$this->errorMessage = $this->wpdb->last_error;
			$save_result = $insert > 0 ? $this : null;
		}

		// Also sync to WC_Payment_Token for checkout compatibility
		if ( $save_result && class_exists( 'WC_Payment_Tokens' ) ) {
			$this->syncToWcPaymentToken();
		}

		return $save_result;
	}

	/**
	 * Sync agreement to WC_Payment_Token record for checkout display.
	 */
	private function syncToWcPaymentToken(): void {
		if ( empty( $this->agreementID ) || empty( $this->userID ) || ! class_exists( '\WC_Payment_Token' ) ) {
			return;
		}

		try {
			// Check if token already exists for this agreement
			$existing_tokens = \WC_Payment_Tokens::get_tokens( array(
				'gateway_id' => BKASH_DC_PLUGIN_SLUG,
				'user_id'    => $this->userID,
			) );

			$token_exists = false;
			if ( is_array( $existing_tokens ) ) {
				foreach ( $existing_tokens as $t ) {
					if ( method_exists( $t, 'get_token' ) && $t->get_token() === $this->agreementID ) {
						$token_exists = true;
						// Update existing token meta
						if ( method_exists( $t, 'update_meta_data' ) ) {
							$t->update_meta_data( 'phone', $this->mobileNo );
							if ( method_exists( $t, 'save' ) ) {
								$t->save();
							}
						}
						break;
					}
				}
			}

			// Create new token if it doesn't exist
			if ( ! $token_exists ) {
				$token = new \WC_Payment_Token();
				$token->set_token( $this->agreementID );
				$token->set_gateway_id( BKASH_DC_PLUGIN_SLUG );
				$token->set_user_id( (int) $this->userID );
				$token->set_type( 'bKash' );
				if ( method_exists( $token, 'add_meta_data' ) ) {
					$token->add_meta_data( 'phone', $this->mobileNo );
				}
				if ( method_exists( $token, 'save' ) ) {
					$token->save();
				}
			}
		} catch ( \Throwable $e ) {
			// Silent fail - token sync is a nice-to-have, not critical for operation
		}
	}

	/**
	 * Delete the corresponding WC_Payment_Token if present.
	 *
	 * @param string $agreementID
	 *
	 * @return void
	 */
	final public function deleteWcTokenByToken( string $agreementID ): void {
		if ( empty( $agreementID ) || ! class_exists( 'WC_Payment_Tokens' ) ) {
			return;
		}
		try {
			$tokens = \WC_Payment_Tokens::get_tokens( array( 'gateway_id' => BKASH_DC_PLUGIN_SLUG ) );
			foreach ( $tokens as $token ) {
				if ( method_exists( $token, 'get_token' ) && $token->get_token() === $agreementID ) {
					$token->delete();
				}
			}
		} catch ( \Exception $e ) {
			// ignore
		}
	}

	final public function update( array $data, array $where = array() ): bool {
		$updated = $this->wpdb->update( $this->tableName, $data, $where );

		$this->errorMessage = $this->wpdb->last_error; // set if any error or null

		// Sync updated phone to WC_Payment_Token if phone was updated
		if ( $updated > 0 && ! empty( $data['phone'] ) && ! empty( $data['agreement_token'] ) && class_exists( 'WC_Payment_Tokens' ) ) {
			try {
				$tokens = \WC_Payment_Tokens::get_tokens( array( 'gateway_id' => BKASH_DC_PLUGIN_SLUG ) );
				foreach ( $tokens as $token ) {
					if ( method_exists( $token, 'get_token' ) && $token->get_token() === $data['agreement_token'] ) {
						if ( method_exists( $token, 'update_meta_data' ) ) {
							$token->update_meta_data( 'phone', $data['phone'] );
							if ( method_exists( $token, 'save' ) ) {
								$token->save();
							}
						}
						break;
					}
				}
			} catch ( \Throwable $e ) {
				// Silent fail - metadata sync is non-critical
			}
		}

		return $updated > 0;
	}

	final public function delete( $agreementID = '', $id = '' ): bool {
		$updated = 0;
		if ( ! empty( $agreementID ) ) {
			$updated = $this->wpdb->delete( $this->tableName, array( 'agreement_token' => $agreementID ) );
			// Also delete from WC_Payment_Tokens for consistency
			if ( $updated > 0 ) {
				$this->deleteWcTokenByToken( $agreementID );
			}
		}
		if ( ! empty( $id ) ) {
			$updated = $this->wpdb->delete( $this->tableName, array( 'ID' => $id ) );
		}

		$this->errorMessage = $this->wpdb->last_error; // set if any error or null

		return $updated > 0;
	}

	/**
	 * @param string $agreementID
	 * @param string $user_id
	 * @param string $id
	 *
	 * @return $this|null
	 */
	final public function getAgreement( string $agreementID = '', string $user_id = '', string $id = '' ) {
		// Decode any HTML entities (e.g. &#10; &#9;) injected by FILTER_SANITIZE_SPECIAL_CHARS and trim whitespace.
		$agreementID = trim( html_entity_decode( $agreementID, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$user_id     = trim( html_entity_decode( $user_id, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$id          = trim( html_entity_decode( $id, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		$primaryKey = 'ID';
		$tableName  = Utils::safeSqlString( $this->tableName );
		if ( ! empty( $agreementID ) ) {
			$whereColumn = '`agreement_token`';
			$whereValue  = Utils::safeString( $agreementID );
		} elseif ( ! empty( $user_id ) ) {
			$whereColumn = '`user_id`';
			$whereValue  = Utils::safeString( $user_id );
		} else {
			$whereColumn = $primaryKey;
			$whereValue  = Utils::safeString( $id );
		}

		$sqlQuery = "SELECT * FROM $tableName WHERE $whereColumn = %s ORDER BY `ID` DESC";
		if ( ! is_null( $this->wpdb ) ) {
			$agreement = $this->wpdb->get_row(
				$this->wpdb->prepare( $sqlQuery, $whereValue )
			);
			if ( $agreement ) {
				$this->agreementID = $agreement->agreement_token ?? null;
				$this->mobileNo    = $agreement->phone ?? null;
				$this->userID      = $agreement->user_id ?? null;
				$this->dateTime    = $agreement->datetime ?? null;

				return $this;
			}
		}

		// If not found in legacy table, try WC_Payment_Tokens for the user
		if ( ! empty( $user_id ) && class_exists( 'WC_Payment_Tokens' ) ) {
			try {
				$tokens = \WC_Payment_Tokens::get_customer_tokens( (int) $user_id, BKASH_DC_PLUGIN_SLUG );
				if ( is_array( $tokens ) && ! empty( $tokens ) ) {
					// take the most recent token
					$token = reset( $tokens );
					if ( method_exists( $token, 'get_token' ) ) {
						$this->agreementID = $token->get_token();
						$this->mobileNo    = $token->get_meta( 'phone' );
						$this->userID      = $user_id;
						return $this;
					}
				}
			} catch ( \Exception $e ) {
				// ignore and return null
			}
		}

		return null;
	}

	final public function getAgreements( $user_id ) {
		$agreements = array();
		$tableName  = Utils::safeSqlString( $this->tableName );
		$sqlQuery   = "SELECT * FROM $tableName WHERE `user_id` = %d ORDER BY ID DESC";

		if ( ! empty( $user_id ) && ! is_null( $this->wpdb ) ) {
			$agreements = $this->wpdb->get_results(
				$this->wpdb->prepare(
					$sqlQuery,
					(int) $user_id
					)
				);
		}

		if ( ! empty( $agreements ) ) {
			return $agreements;
		}

		// Try legacy WC payment tokens if the DB table does not contain any agreements.
		if ( ! empty( $user_id ) && class_exists( 'WC_Payment_Tokens' ) ) {
			try {
				if ( method_exists( '\WC_Payment_Tokens', 'get_tokens' ) ) {
					$tokens = \WC_Payment_Tokens::get_tokens(
						array(
							'gateway_id' => BKASH_DC_PLUGIN_SLUG,
							'user_id'    => (int) $user_id,
						)
					);
				} elseif ( method_exists( '\WC_Payment_Tokens', 'get_customer_tokens' ) ) {
					$tokens = \WC_Payment_Tokens::get_customer_tokens( (int) $user_id, BKASH_DC_PLUGIN_SLUG );
				} else {
					$tokens = array();
				}

				if ( is_array( $tokens ) && ! empty( $tokens ) ) {
					foreach ( $tokens as $token ) {
						if ( is_object( $token ) && method_exists( $token, 'get_token' ) ) {
							$agreements[] = (object) array(
								'agreement_token' => $token->get_token(),
								'phone'           => $token->get_meta( 'phone' ),
								'ID'              => $token->get_id(),
							);
						}
					}
				}
			} catch ( \Throwable $e ) {
				// ignore token read errors
			}
		}

		return $agreements;
	}
}