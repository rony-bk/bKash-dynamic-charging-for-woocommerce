<?php 

/**
 * bKash WooCommerce Payment Gateway for Dynamic Charging Product.
 *
 * @category    Payment
 * @package     woo-bkash-dynamic-charging
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2023 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */
    
namespace bKash\PGW\DC;


use bKash\PGW\DC\Models\Transaction;
use bKash\PGW\DC\Models\Agreement;
use Exception;
use WC_AJAX;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;


/**
 * bKash WooCommerce Payment Gateway for Dynamic Charging Product.
 *
 * @class   WC_Geteway_bKash_Dc
 * @extends WC_Payment_Gateway
 * @version 1.0.0
 * @package bKash\PGW
 * @author  Muhamamad Abid Hasan Turzo
 */

if (!class_exists('WC_Payment_Gateway')) return;

	class WC_Geteway_bKash_Dc extends WC_Payment_Gateway {

    public $refundObj;
    public $refundError;
    private $CALLBACK_URL           = 'bkashdc_payment_process';
    private $SUCCESS_CALLBACK_URL   = 'bkashdc_payment_success';
    private $FAILURE_CALLBACK_URL   = 'bkashdc_payment_failure';
    private $EXECUTE_URL            = 'bkdc_execute';
    private $PAYMENT_CANCEL_URL     = 'bkdc_cancel';
    private $AGREEMENT_CALLBACK_URL = 'bkdc_agreement_callback';
    private $CANCEL_AGREEMENT_URL   = 'bkdc_cancel_agreement';
    private $REVIEW_ORDER_URL       = 'bkdc_review_order';
    private $WEBHOOK_URL            = 'bkashdc_webhook';

    /**
     * @var string|null
     */
    private $siteUrl;
    /**
     * @var string|null
     */
    private $is_webhook;
    private $sandbox;
    /**
     * @var string|null
     */
    private $debug;
    /**
     * @var string|null
     */
    private $password;
    /**
     * @var string|null
     */
    private $username;
    /**
     * @var string|null
     */
    private $app_secret;
    /**
     * @var string|null
     */
    private $app_key;
    /**
     * @var string|null
     */
    private $api_version;
    /**
     * @var string|null
     */
    private $intent;
    /**
     * @var string|null
     */
    private $integration_type;
    /**
     * @var string|null
     */
    private $enable_b2c;

    /**
     * Constructor for the gateway.
     *
     * @access public
     * @return void
     */

    public function __construct() {
        
        $this->initiateAdminSettings();
        $this->registerHooks();
    }

    public function initiateAdminSettings() {

        $this->id                   = BKASH_DC_PLUGIN_SLUG; 
        $this->icon                 = apply_filters('woocommerce_payment_gateway_bkash_icon',plugins_url( '../assets/images/logo.png', __DIR__ )); 
        $this->has_fields           = true; 
        $this->method_title         = 'bKash Dynamic Charging';
        $this->method_description   = 'bKash Payment Gateway provides range of payment solutions to merchants of the online sphere'; 
        $this->siteUrl              = get_site_url();

        $this->supports = array(
            'products',
            'refunds',
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled          = $this->get_option( 'enabled' );
        $this->title            = $this->get_option( 'title' );
        $this->description      = $this->get_option( 'description' );
        $this->integration_type = $this->get_option( 'integration_type' );
        $this->intent           = $this->get_option( 'intent' );
        $this->api_version      = $this->get_option( 'bkash_api_version' );
        $this->sandbox          = $this->get_option( 'sandbox' );
        $this->app_key          = $this->get_option( 'app_key' );
        $this->app_secret       = $this->get_option( 'app_secret' );
        $this->username         = $this->get_option( 'username' );
        $this->password         = $this->get_option( 'password' );
        $this->debug            = $this->get_option( 'debug' );
        $this->enable_b2c       = $this->get_option( 'enable_b2c' );
        $this->is_webhook = $this->get_option( 'webhook' );
    }

    public function registerHooks() {

        if ( is_admin() ) {
            add_action( 'admin_notices', array( $this, 'checks' ) );
            add_action( 'admin_notices', array( $this, 'displayFlashNotices' ), 12 );

            add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receiptPage' ) );
            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                array(
                    $this,
                    'process_admin_options',
                )
            );
        }
        add_action( 'wp_enqueue_scripts', array( $this, 'paymentScripts' ) );

        // Customer Emails.
        // add_action( 'woocommerce_email_before_order_table', array( $this, 'emailInstructions' ), 10, 3 );

        add_action( 'woocommerce_api_' . $this->CALLBACK_URL, array( $this, 'createPaymentCallbackProcess' ) );
        add_action( 'woocommerce_api_' . $this->SUCCESS_CALLBACK_URL, array( $this, 'paymentSuccess' ) );
        add_action( 'woocommerce_api_' . $this->FAILURE_CALLBACK_URL, array( $this, 'paymentFailure' ) );
        add_action( 'woocommerce_api_' . $this->EXECUTE_URL, array( $this, 'createPaymentCallbackProcess' ) );
        add_action( 'woocommerce_api_' . $this->PAYMENT_CANCEL_URL, array( $this, 'cancelPaymentProcess' ) );
        add_action( 'woocommerce_api_' . $this->AGREEMENT_CALLBACK_URL, array( $this, 'createAgreementCallbackProcess' ) );
        add_action( 'woocommerce_api_' . $this->CANCEL_AGREEMENT_URL, array( $this, 'cancelAgreementApi' ) );
        add_action( 'woocommerce_api_' . $this->REVIEW_ORDER_URL, array( $this, 'processReviewOrderPayment' ) );

        add_action( 'woocommerce_api_' . $this->WEBHOOK_URL, array( $this, 'webhook' ) );
        // reset token when setting changes
        add_action( 'update_option', array( $this, 'onUpdateResetToken' ), 10, 3 );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankYouPage' ) );
    }

    public function init_form_fields(){
        $this->form_fields = array(
            'enabled'      => array(
                'title'       => 'Enable/Disable',
                'label'       => 'Enable bKash PGW',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title'              => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'bKash Payment Gateway - Dynamic Charging.',
                'desc_tip'    => true,
            ),
            'description'        => array(
                'title'       => 'Description',
                'type'        => 'text',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => 'Pay with bKash Dynamic Charging Payment Gateway',
                'desc_tip'    => true,
            ),
            'integration_type'   => array(
                'title'       => 'Integration Type',
                'type'        => 'select',
                'description' => 'Payment will be initiated with selected bKash PGW integration type',
                'options'     => array(
                    'paymentonly'    => 'Without Agreement (Payment Only)',
                    'tokenized'      => 'Tokenized (With Agreement)',
                    'tokenized-both' => 'Tokenized (With and without Agreement)',
                ),
                'default'     => 'paymentonly',
                'desc_tip'    => true,
            ),
            'intent'             => array(
                'title'       => 'Intent',
                'type'        => 'select',
                'description' => 'Payment will be initiated with selected bKash PGW integration type',
                'options'     => array(
                    'sale'          => 'Sale',
                    'authorization' => 'Authorized',
                ),
                'default'     => 'checkout',
                'desc_tip'    => true,
            ),
            'bkash_api_version'  => array(
                'title'       => 'API Version',
                'type'        => 'text',
                'description' => 'This api version will be used for calling API to bKash',
                'default'     => 'v1',
                'desc_tip'    => true,
            ),
            'debug'              => array(
                'title'       => 'Debug Log',
                'type'        => 'checkbox',
                'label'       => 'Enable logging',
                'default'     => 'no',
                'description' => sprintf(
                    'Log bKash PGW events inside <code>%s</code>',
                    esc_html('WooCommerce >> Status >> Logs')
                ),
            ),
            'enable_b2c'         => array(
                'title'       => 'Enable B2C API',
                'type'        => 'checkbox',
                'label'       => 'Enable B2C API',
                'default'     => 'no',
                'description' => 'Enable B2C Disbursement API',
            ),
            'webhook'            => array(
                'title'       => 'Webhook',
                'type'        => 'checkbox',
                'label'       => 'Enable Webhook listener',
                'default'     => 'no',
                'description' => sprintf(
                    'Share this webhook URL to bKash team - <code>%s</code>',
                    esc_url( $this->siteUrl . BKASH_DC_WC_API . $this->WEBHOOK_URL )
                ),
            ),
            'sandbox'            => array(
                'title'       => 'Sandbox',
                'label'       => 'Enable Sandbox Mode',
                'type'        => 'checkbox',
                'description' => 'If Enabled, Sandbox mode will be applied (real payments will not be taken).',
                'default'     => 'yes',
            ),
            'username'           => array(
                'title'       => 'Username',
                'type'        => 'text',
                'description' => 'Get your Username from your bKash PGW account.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'password'           => array(
                'title'       => 'Password',
                'type'        => 'password',
                'description' => 'Get your password from your bKash PGW account.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'app_key'            => array(
                'title'       => 'Application Key',
                'type'        => 'text',
                'description' => 'Get your App Key from your bKash PGW account.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'app_secret'         => array(
                'title'       => 'Application Secret Key',
                'type'        => 'password',
                'description' => 'Get your App Secret from your bKash PGW account.',
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * You will need it if you want your custom credit card form, Step 4 is about it
     */
    public function payment_fields() {
        $description = $this->get_description();

        if ( $this->sandbox === 'yes' ) {
            $description .= ' (IN SANDBOX)';
        }

        if ( ! empty( $description ) ) {
            echo wp_kses_post( wpautop( wptexturize( trim( $description ) ) ) );
        }

        if ( is_user_logged_in() ) {
			$user_id        = get_current_user_id();
			$agreementModel = new Agreement();
			$agreements     = $agreementModel->getAgreements( $user_id );

            // If WooCommerce payment tokens are available, merge them so tokenized agreements are shown.
            if ( class_exists( 'WC_Payment_Tokens' ) ) {
                try {
                    // Prefer the public get_tokens API (newer WC); fall back to get_customer_tokens if present.
                    if ( method_exists( '\\WC_Payment_Tokens', 'get_tokens' ) ) {
                        $tokens = \WC_Payment_Tokens::get_tokens( array( 'gateway_id' => BKASH_DC_PLUGIN_SLUG, 'user_id' => $user_id ) );
                    } elseif ( method_exists( '\\WC_Payment_Tokens', 'get_customer_tokens' ) ) {
                        $tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, BKASH_DC_PLUGIN_SLUG );
                    } else {
                        $tokens = array();
                    }

                    if ( is_array( $tokens ) && ! empty( $tokens ) ) {
                        $token_agreements = array();
                        foreach ( $tokens as $t ) {
                            if ( is_object( $t ) && method_exists( $t, 'get_token' ) ) {
                                $token_agreements[] = (object) array(
                                    'agreement_token' => $t->get_token(),
                                    'phone'           => $t->get_meta( 'phone' ),
                                    'ID'              => $t->get_id(),
                                );
                            }
                        }
                        // Prefer WC tokens; append DB agreements that are not already represented
                        if ( empty( $agreements ) ) {
                            $agreements = $token_agreements;
                        } else {
                            foreach ( $token_agreements as $ta ) {
                                $found = false;
                                foreach ( $agreements as $a ) {
                                    if ( isset( $a->agreement_token ) && $a->agreement_token === $ta->agreement_token ) {
                                        $found = true;
                                        break;
                                    }
                                }
                                if ( ! $found ) {
                                    $agreements[] = $ta;
                                }
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    // ignore token read errors, fall back to DB agreements
                }
            }

			// This includes your custom payment fields.
			include_once __DIR__ . '/views/payment-fields.php';
		} elseif ( $this->integration_type === 'tokenized' ) {
			echo "<p style='color:red'>Please login to complete the payment</p>";
		}
    }

    final public function isAvailable(): bool {
        if ( $this->enabled === 'no' || ( ! is_ssl() && 'no' === $this->sandbox ) ) {
            return false;
        }

        if ( ! $this->app_key || ! $this->app_secret || 'BDT' !== get_woocommerce_currency() ) {
            return false;
        }

        return true;
    }

    /*
     * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
     */
    public function paymentScripts() {
        if ( is_checkout() ) {
            // Enqueue tokenized script
            wp_enqueue_script(
                'bkash-dc-tokenized',
                plugins_url( '../../assets/js/tokenized.js', __FILE__ ),
                array( 'jquery' ),
                BKASH_DC_PLUGIN_VERSION,
                true
            );

            // // Localize all necessary variables for both checkout and tokenized scripts
            wp_localize_script(
                'bkash-dc-checkout',
                'bKash_objects',
                array(
                    'bKash_slug'              => $this->id,
                    'submit_order'            => add_query_arg( 'orderId', get_current_user_id(), get_site_url() . BKASH_DC_WC_API . $this->REVIEW_ORDER_URL ),
                    'review_order_payment'    => add_query_arg( 'orderId', get_current_user_id(), get_site_url() . BKASH_DC_WC_API . $this->REVIEW_ORDER_URL ),
                    'bKashScriptURL'          => 'https://checkout.bkash.com/bKash/checkout.js',
                    'wcAjaxURL'               => admin_url( 'admin-ajax.php' ),
                    'wcPaymentCancelUrl'      => add_query_arg( 'orderId', get_current_user_id(), get_site_url() . BKASH_DC_WC_API . $this->PAYMENT_CANCEL_URL ),
                    'cancelAgreement'         => add_query_arg( 'orderId', get_current_user_id(), get_site_url() . BKASH_DC_WC_API . $this->CANCEL_AGREEMENT_URL ),
                )
            );
        }
    }

    /*
     * Fields validation, more in Step 5
     */
    public function validate_fields() {


    }

    final public function cancelPaymentProcess() {

        $order_id = Sanitizer::safePostValue( 'orderId' );
        if ( ! $order_id ) {
            $order_id = Sanitizer::safeGetValue( 'orderId' );
        }

        $process = new Payments( $this->integration_type );
        $resp    = $process->cancelPayment( $order_id );
        wp_send_json( $resp );
    }

    final public function onUpdateResetToken( string $option_name, $old_value, $value ) {
        if ( $option_name === 'woocommerce_' . BKASH_DC_PLUGIN_SLUG . '_settings' ) {
            $bkashApi = new BkashApi();
            $bkashApi->resetToken();
        }
    }

    final public function processReviewOrderPayment() {
        $order_id = Sanitizer::safePostValue( 'order_id' );
        if ( ! $order_id ) {
            $order_id = Sanitizer::safeGetValue( 'order_id' );
        }

        if ( $order_id ) {
            wp_send_json( $this->process_payment( $order_id ) );
        }

        wp_send_json(
            array(
                'result'  => 'failure',
                'message' => 'Order ID is missing',
            )
        );
    }

    public function process_payment( $order_id ) {
        $cbURL = add_query_arg( 'orderId', $order_id, get_site_url() . BKASH_DC_WC_API . $this->CALLBACK_URL );
        $agreementCbURL = add_query_arg( 'orderId', $order_id, get_site_url() . BKASH_DC_WC_API . $this->AGREEMENT_CALLBACK_URL );

        return ( new Payments( $this->integration_type ) )->createPayment( $order_id, $this->intent, $cbURL, null, $agreementCbURL );
    }

    final public function paymentSuccess() {
        // for later use
    }

    final public function paymentFailure() {
        // for later use
    }


    /**
 * Check if SSL is enabled and notify the user.
 *
 * @access public
 */
    final public function checks() {
        if ( $this->enabled === 'no' ) {
            return;
        }

        // PHP Version.
        if ( PHP_VERSION_ID < 50300 ) {
            echo wp_kses_post(
                '<div class="error version-error"><p>bKash PGW Error: ' . sprintf(
                    'bKash PGW Error: bKash PGW requires PHP 5.3 and above. You are using version %s.',
                    esc_html( PHP_VERSION )
                ) . '</p></div>'
            );
        } elseif ( ! $this->app_key || ! $this->app_secret ) { // Check required fields.
            echo '<div class="error app-key-error"><p>bKash PGW Error: Please enter your app keys and secrets</p></div>';
        } elseif ( 'BDT' !== get_woocommerce_currency() ) {
            echo '<div class="error currency-error"><p>bKash PGW Error: Only supports BDT as Currency</p></div>';
        } elseif ( ! class_exists( 'WordPressHTTPS' ) && 'no' === get_option( 'woocommerce_force_ssl_checkout' ) && ! is_ssl()
        ) {
            // Show message if enabled and FORCE SSL is disabled and WordPress HTTPS plugin is not detected.
            $admin_checkout_setting_url = admin_url( 'admin.php?page=wc-settings&tab=checkout' ); ?>
            <div class="error ssl-error"><p>bKash PGW is enabled, but the <a href="<?php echo esc_url( $admin_checkout_setting_url ); ?>">Force SSL option</a> is
                    disabled;
                    your payment may not be secure! Please enable SSL and ensure your server has a valid SSL
                    certificate -
                    bKash PGW will only work in sandbox mode.</p></div>
            <?php
        }

        // APP KEY APP SECRET CHECK
        if ( empty( $this->app_key ) || empty( $this->app_secret ) || empty( $this->username ) || empty( $this->password ) ) {
            $this->appKeyMissingNotice();
        }
    }

    /**
     * WooCommerce Payment Gateway App key missing Notice.
     *
     * @access public
     */
    final public function appKeyMissingNotice() {
        $notice = '<div class="error woocommerce-message wc-connect">
                <p>Please set bKash PGW credentials for accepting payments!</p></div>';
        add_action( 'admin_notices', $notice );
    }

    final public function createPaymentCallbackProcess() {
        $order_id = Sanitizer::safePostValue( 'orderId' );
        if ( ! $order_id ) {
            $order_id = Sanitizer::safeGetValue( 'orderId' );
        }

        $agreement_id = Utils::safePostValue( 'agreementId' );
		if ( ! $agreement_id ) {
			$agreement_id = Utils::safeGetValue( 'agreementId' );
		}

        // Temporary request diagnostics: write incoming request to plugin log to investigate blank pages.
        try {
            $debug = array(
                'time' => date( 'c' ),
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'get' => $_GET ?? array(),
                'post' => $_POST ?? array(),
                'order_id' => $order_id,
            );
            $logfile = BKASH_DC_BASE_PATH . 'bkash-callback-debug.log';
            @file_put_contents( $logfile, json_encode( $debug ) . "\n", FILE_APPEND | LOCK_EX );
        } catch ( \Throwable $e ) {
            // ignore logging failures
        }

        // To receive order id
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $cbURL = add_query_arg( 'orderId', $order_id, get_site_url() . BKASH_DC_WC_API . $this->CALLBACK_URL );

            $process = new Payments( $this->integration_type );
            // Debug output is handled by WooCommerce logger when enabled.
            $process->executePayment( $this->get_return_url( $order ), $cbURL, $agreement_id );
        } else {
            echo wp_json_encode(
                array(
                    'result'  => 'failure',
                    'message' => 'Order not found',
                )
            );
        }
        die();
    }

    /**
	 * Agreement Cancellation API - handles removal of saved agreements on checkout
	 */
	final public function cancelAgreementApi() {
		check_ajax_referer( 'bkash-ajax-nonce' );
		$agreement_id = Utils::safePostValue( 'id' );

		if ( empty( $agreement_id ) ) {
			wp_send_json( array(
				'result'  => 'error',
				'message' => 'Agreement ID is required',
			) );
		}

		$api       = new BkashApi();
		$agreement = $api->agreementCancel( $agreement_id );

		if ( isset( $agreement['status_code'] ) && $agreement['status_code'] === 200 ) {
			if ( isset( $agreement['response'] ) && is_string( $agreement['response'] ) ) {
				$response = json_decode( $agreement['response'], true );
				if ( isset( $response['agreementStatus'] ) && $response['agreementStatus'] === 'Cancelled' ) {
					$agreementObj = new Agreement();
					$agreementObj->delete( $agreement_id );
					wp_send_json( array(
						'result'  => 'success',
						'message' => 'Agreement cancelled successfully',
					) );
				}
			}
		}

		wp_send_json( array(
			'result'  => 'error',
			'message' => 'Failed to cancel agreement',
		) );
	}

    /**
	 * Agreement Callback Handler
	 *
	 * Called when bKash redirects the user back after agreement approval/failure/cancel.
	 * bKash sends: orderId, agreementId, status, signature (no paymentID or invoiceID).
	 * Looks up the transaction by order_id, then calls handleAgreementCallback.
	 */
	final public function createAgreementCallbackProcess() {
		$order_id = Utils::safeGetValue( 'orderId' );
		$status   = Utils::safeGetValue( 'status' );
		$agreement_id = Utils::safeGetValue( 'agreementId' );

		if ( empty( $order_id ) ) {
			wc_add_notice( 'Order ID is missing.', 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( 'Order not found.', 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die();
		}

		$trx         = new Models\Transaction();
		$transaction = $trx->getTransactionByOrderId( $order_id );

		if ( ! $transaction || $transaction->getMode() !== '1000' ) {
			wc_add_notice( 'Agreement transaction not found for this order.', 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die();
		}

		$process = new Payments( $this->integration_type );
		if ( $status === 'success' ) {
			$transaction->update( array( 'status' => 'CALLBACK_REACHED' ) );

			$agreementResult = $process->handleAgreementCallback(
				$agreement_id,
				$order->get_user_id()
			);

			if ( $agreementResult['success'] ) {
				$transaction->update( array( 'status' => 'AgreementCompleted', 'mode' => '1001' ) );
				$order->update_meta_data( '_bkmode', '1001' );
				if ( method_exists( $order, 'save' ) ) {
					$order->save();
				}

				// Agreement completed — now create the actual payment using the new agreement
				$paymentCbURL = get_site_url() . BKASH_FW_WC_API . $this->CALLBACK_URL . '?orderId=' . $order_id;
				$paymentResult = $process->createPayment(
					$order_id,
					$this->intent,
					$paymentCbURL,
					null,
					'',
					$agreementResult['agreementID']
				);

				if ( isset( $paymentResult['result'] ) && $paymentResult['result'] === 'success' && ! empty( $paymentResult['redirect'] ) ) {
					wp_redirect( $paymentResult['redirect'] );
					die();
				}

				// Payment creation failed after agreement success
				$errorMsg = $paymentResult['message'] ?? 'Payment creation failed after agreement.';
				$order->add_order_note( 'bKash Agreement succeeded but payment creation failed: ' . $errorMsg );
				wc_add_notice( $errorMsg, 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				die();
			} else {
				$transaction->update( array( 'status' => 'AgreementFailed' ) );
				$order->add_order_note( 'bKash Agreement failed: ' . ( $agreementResult['message'] ?? '' ) );

				wc_add_notice( $agreementResult['message'] ?? 'Agreement execution failed.', 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				die();
			}
		}

		// Agreement was cancelled or failed at bKash
		$statusLabel = str_replace( array( 'cancel', 'failure' ), array( 'Cancelled', 'Failed' ), $status );
		$transaction->update( array( 'status' => $statusLabel ) );
		$order->add_order_note( 'bKash Agreement ' . $statusLabel );

		wc_add_notice( 'bKash Agreement ' . $statusLabel, 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		die();
	}

    final public function webhook() {
        $method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
        if ( 'POST' !== $method ) {
            status_header( 405 );
            header( 'Allow: POST' );
            wp_send_json_error(
                array( 'message' => 'Method Not Allowed' ),
                405
            );
            return;
        }

        $raw_payload = file_get_contents( 'php://input' );
        if ( ! is_string( $raw_payload ) || '' === trim( $raw_payload ) ) {
            status_header( 400 );
            wp_send_json_error(
                array( 'message' => 'Webhook payload is empty' ),
                400
            );
            return;
        }

        json_decode( $raw_payload, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            status_header( 400 );
            wp_send_json_error(
                array( 'message' => 'Invalid JSON payload' ),
                400
            );
            return;
        }

        if ( isset( $this->is_webhook ) && $this->is_webhook === 'yes' ) {
            $webhook = new WebhookProcessor( true, $raw_payload );
            $webhook->processRequest();
        } else {
            Log::info( 'WebhookModule is not enabled in settings' );
        }

        Log::debug( 'Webhook payload received and processed' );

        wp_send_json_success();
    }

    /**
     * Output for the order received page.
     *
     * @access public
     * @return void
     */
    final public function receiptPage() {
        echo wp_kses_post( '<p>Thank you - your order is now pending payment.</p>' );
    }

    /**
     * Output for the order received page.
     *
     * @access public
     *
     * @param $order_id
     */
    final public function thankYouPage( $order_id ) {
        $this->extraDetails( $order_id );
    }

    private function extraDetails( $order_id = '' ) {
        $order = wc_get_order( $order_id );
        $id    = $order->get_transaction_id();

        echo wp_kses_post( '<h2> Payment Details </h2>' ) . PHP_EOL;

        $trxObj = new Transaction();
        $trx    = $trxObj->getTransaction( '', $id );
        if ( $trx ) {
            include_once 'Admin/pages/extra_details.php';
        }
    }

    final public function emailInstructions( WC_Order $order, bool $sent_to_admin, bool $plain_text = false ) {
        if ( ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
            $this->extraDetails( $order->get_id() );
        }
    }


    final public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        $id    = $order->get_transaction_id();
        $response = '';

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $trxObject   = new Transaction();
        $transaction = $trxObject->getTransaction( '', $id );

        if ( $transaction ) {
            $refundAmount  = $amount !== null ? floatval( $amount ) : floatval( $transaction->getAmount() );
            $totalAmount   = floatval( $transaction->getAmount() );
            $refundedSoFar = floatval( $transaction->getRefundAmount() ?? 0 );

            if ( $refundAmount <= 0 ) {
                $trx = 'Refund amount must be greater than zero.';
            } elseif ( $refundedSoFar >= $totalAmount ) {
                $trx = 'This transaction has already been fully refunded.';
            } elseif ( $refundedSoFar + $refundAmount > $totalAmount ) {
                $trx = 'Refund amount exceeds the original transaction amount.';
            } else {
                $agreement_id = $order->get_meta( '_bkash_agreement_id', true );
                $bkashApi     = new BkashApi();
                $call = $bkashApi->refund(
                    $refundAmount,
                    $transaction->getPaymentID(),
                    $transaction->getTrxID(),
                    $transaction->getOrderID(),
                    $reason ?? 'Refund Purpose',
                    $agreement_id ?? ''
                );
                if ( isset( $call['status_code'] ) && $call['status_code'] === 200 ) {

                    $trx = array();
                    if ( isset( $call['response'] ) && is_string( $call['response'] ) ) {
                        $trx = json_decode( $call['response'], true );
                    }

                    // If any error for tokenized
                    if ( isset( $trx['statusMessage'] ) && $trx['statusMessage'] !== 'Successful' ) {
                        $trx = $trx['statusMessage'];
                    } elseif ( isset( $trx['errorCode'] ) ) { // If any error for checkout
                        $trx = $trx['errorMessage'] ?? '';
                    } elseif ( isset( $trx['transactionStatus'] ) && $trx['transactionStatus'] === 'Completed' ) {
                        if ( isset( $trx['refundTrxId'] ) && ! empty( $trx['refundTrxId'] ) ) {
                            $this->refundObj = $trx; // so that another class can get the information

                            wc_create_refund(
                                array(
                                    'amount'         => $refundAmount,
                                    'reason'         => $reason,
                                    'order_id_wcdc'  => $order_id,
                                    'refund_payment' => false,
                                )
                            );

                            $order->add_order_note(
                                sprintf(
                                    'bKash PGW: Refunded %s - Refund ID: %s',
                                    $refundAmount,
                                    $trx['refundTrxId']
                                )
                            );

                            $currentRefundAmount = isset( $trx['amount'] ) ? floatval( $trx['amount'] ) : $refundAmount;
                            $newRefundTotal = $refundedSoFar + $currentRefundAmount;
                            if ( $newRefundTotal > $totalAmount ) {
                                $newRefundTotal = $totalAmount;
                            }

                            $transaction->update(
                                array(
                                    'refund_id'     => $trx['refundTrxId'],
                                    'refund_amount' => $newRefundTotal,
                                ),
                                array( 'invoice_id' => $transaction->getInvoiceID() )
                            );

                            if ( $this->debug === 'yes' ) {
                                Log::debug( 'bKash PGW order #' . $order_id . ' refunded successfully!' );
                            }

                            return true;
                        }

                        $trx = 'Refund was not successful, no refund id found, try again';
                    } else {
                        $trx = 'Refund was not successful, transaction is not in completed state, try again';
                    }
                } else {
                    $trx = 'Cannot refund the transaction using bKash server right now, try again';
                }
            }
        } else {
            $trx = 'Cannot find the transaction to refund in your database, try again';
        }

        if ( is_string( $trx ) ) {
            $this->refundError = $trx;
            $order->add_order_note( 'Error in refunding the order. ' . esc_html( $trx ) );

            if ( $this->debug === 'yes' ) {
                Log::debug( 'Error in refunding the order #' . $order_id . '. Refund request failed.' );
            }
        }

        return false;
    }

    final public function queryRefund( int $order_id ) {
        $order = wc_get_order( $order_id );
        $id    = $order->get_transaction_id();

        $response = '';

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $trxObject   = new Transaction();
        $transaction = $trxObject->getTransaction( '', $id );
        if ( $transaction ) {
            if ( ! empty( $transaction->getRefundID() ) ) {
                $agreement_id = $order->get_meta( '_bkash_agreement_id', true );
                $comm = new BkashApi();
                $call = $comm->refundStatus(
                    $transaction->getPaymentID(),
                    $transaction->getTrxID(),
                    $agreement_id ?? ''
                );
                if ( isset( $call['status_code'] ) && $call['status_code'] === 200 ) {
                    return isset( $call['response'] ) && is_string( $call['response'] )
                        ? json_decode( $call['response'], true ) : array();
                }

                $trx = 'Cannot check refund status using bKash server right now, try again';
            } else {
                $trx = 'This transaction is not refunded yet, try again';
            }
        } else {
            $trx = 'Cannot find the transaction to query in your database, try again';
        }

        return $trx;
    }

    public static function addFlashNotice( $notice = '', $type = 'warning', $dismissible = true ) {
        // Here we return the notices saved on our option, if there are not notices, then an empty array is returned
        $notices = get_option( 'dc_bKash_flash_notices', array() );

        $dismissible_text = ( $dismissible ) ? 'is-dismissible' : '';

        // We add our new notice.
        $notices[] = array(
            'notice'      => $notice,
            'type'        => $type,
            'dismissible' => $dismissible_text,
        );

        // Then we update the option with our notices array
        update_option( 'dc_bKash_flash_notices', $notices );
    }

    final public function displayFlashNotices() {
        $notices = get_option( 'dc_bKash_flash_notices', array() );

        // Iterate through our notices to be displayed and print them.
        foreach ( $notices as $notice ) {
            printf(
                '<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>',
                esc_attr( $notice['type'] ),
                $notice['dismissible'],
                esc_html( $notice['notice'] )
            );
        }

        // Now we reset our options to prevent notices being displayed forever.
        if ( ! empty( $notices ) ) {
            delete_option( 'dc_bKash_flash_notices' );
        }
    }

    final public function getTransactionUrl( WC_Order $order ): string {
        return $this->get_transaction_url( $order );
    }
}