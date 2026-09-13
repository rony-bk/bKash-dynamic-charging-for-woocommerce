<?php
/**
 * Api Communicator
 *
 * @category    Api
 * @package     bkash-woocommerce-dynamic-charging
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2026 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

namespace bKash\PGW\DC\Admin;

use bKash\PGW\DC\BkashApi;
use bKash\PGW\DC\Log;
use bKash\PGW\DC\Models\Transaction;
use bKash\PGW\DC\Operations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OrderActions {

    public function __construct() {

        add_action( 'wp_ajax_bkash_capture', array( $this, 'ajax_capture' ) );
        add_action( 'wp_ajax_bkash_void', array( $this, 'ajax_void' ) );
        add_action( 'wp_ajax_bkash_query', array( $this, 'ajax_query' ) );

        add_action( 'admin_footer', array( $this, 'processOrders' ) );
    }

    public function processOrders() {
        if (
            empty( $_GET['page'] ) ||
            $_GET['page'] !== 'wc-orders' ||
            empty( $_GET['action'] ) ||
            $_GET['action'] !== 'edit'
        ) {
            return;
        }

        $ajax_url = admin_url( 'admin-ajax.php' );
        $order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->get_payment_method() !== BKASH_DC_PLUGIN_SLUG ) {
            return;
        }

        if ( $order->get_status() !== 'on-hold' ) {
            return;
        }

        $transactionModel = new Transaction();

        $transaction = $transactionModel->getPaymentTransactionByOrderId( $order_id );

        if ( ! $transaction ) {
            return;
        }

        if (
            strtolower( $transaction->getIntent() ) !== 'authorization'
        ) {
            return;
        }
        ?>

        <script>
            document.addEventListener('DOMContentLoaded', function () {

                let notesCard = document.querySelector('#woocommerce-order-notes .inside');

                if (!notesCard) {
                    return;
                }

                if (document.getElementById('bkash-quick-actions')) {
                    return;
                }

                const ajaxUrl = "<?php echo esc_js( $ajax_url ); ?>";
                const orderId = <?php echo (int) $order_id; ?>;

                let overlay = document.createElement('div');
                overlay.id = "bkash-overlay";
                overlay.style.cssText = `
                    display:none;
                    position:fixed;
                    inset:0;
                    background:rgba(255,255,255,.55);
                    z-index:999999;
                    cursor:wait;
                `;
                document.body.appendChild(overlay);

                function blockUI() {
                    overlay.style.display = "block";
                }

                function unblockUI() {
                    overlay.style.display = "none";
                }

                function showNotice(message, type = "success") {

                    let notice = document.createElement("div");

                    notice.className =
                        "notice notice-" + type + " is-dismissible";

                    notice.innerHTML =
                        "<p>" + message + "</p>";

                    let header =
                        document.querySelector(".wp-header-end");

                    if (header) {
                        header.after(notice);
                    } else {
                        document.body.prepend(notice);
                    }

                    window.scrollTo({
                        top:0,
                        behavior:"smooth"
                    });
                }

                function sendAction(action) {
                    let message = (action === "bkash_capture")
                            ? "Are you sure you want to CAPTURE this payment?"
                            : "Are you sure you want to VOID this payment?";

                    if (!confirm(message)) {
                        return;
                    }

                    blockUI();

                    fetch(ajaxUrl,{
                        method:"POST",
                        credentials:"same-origin",
                        headers:{
                            "Content-Type":"application/x-www-form-urlencoded"
                        },
                        body:new URLSearchParams({
                            action:action,
                            order_id:orderId
                        })
                    })
                    .then(r=>r.json())
                    .then(function(data){
                        unblockUI();

                        if (data.success) {
                            const box = document.getElementById("bkash-quick-actions");

                            if (box) {
                                box.remove();
                            }

                            showNotice(data.data.message, "success");

                            let seconds = 5;

                            const notices = document.querySelectorAll(".notice-success p");
                            const notice = notices[notices.length - 1];

                            notice.innerHTML =
                                data.data.message +
                                "<br><strong>Refreshing in " + seconds + " seconds...</strong>";

                            const timer = setInterval(function () 
                            {
                                seconds--;

                                if (seconds > 0) {
                                    notice.innerHTML =
                                        data.data.message +
                                        "<br><strong>Refreshing in " + seconds + " seconds...</strong>";

                                } else {
                                    clearInterval(timer);
                                    notice.innerHTML =
                                        data.data.message +
                                        "<br><strong>Refreshing...</strong>";

                                    location.reload();
                                }
                            }, 1000);
                        } else {
                            showNotice(data.data.message, "error");
                        }
                    })
                    .catch(function(){
                        unblockUI();
                        showNotice("Something went wrong.", "error");
                    });
                }

                let wrapper=document.createElement("div");

                wrapper.id="bkash-quick-actions";

                wrapper.style.padding = "0 0 15px 12px";
                wrapper.style.marginBottom = "15px";
                wrapper.style.borderBottom = "1px solid #dcdcde";

                wrapper.innerHTML=`
                    <h4 style="margin-bottom:10px;">
                        bKash Quick Actions
                    </h4>

                    <button
                        id="bkash-capture-btn"
                        class="button button-primary"
                        type="button">
                        Capture
                    </button>

                    <button
                        id="bkash-void-btn"
                        class="button"
                        style="margin-left:6px;"
                        type="button">
                        Void
                    </button>
                `;

                notesCard.prepend(wrapper);

                document.getElementById("bkash-capture-btn")
                    .addEventListener("click",function(){
                        sendAction("bkash_capture");
                    });

                document.getElementById("bkash-void-btn")
                    .addEventListener("click",function(){
                        sendAction("bkash_void");
                    });
            });

            </script>

        <?php
    }

    public function ajax_capture() {

        if ( empty( $_POST['order_id'] ) ) {

            wp_send_json_error( array(
                'message' => 'Missing Order ID'
            ) );

        }

        $order_id = absint( $_POST['order_id'] );
        $order = wc_get_order( $order_id );
        $agreement_id = $order->get_meta( '_bkash_agreement_id', true );

        if ( ! $order ) {

            wp_send_json_error( array(
                'message' => 'Order not found'
            ) );

        }

        $transactionModel = new Transaction();
        $transaction = $transactionModel->getPaymentTransactionByOrderId( $order_id );
        if ( ! $transaction ) {
            wp_send_json_error( array(
                'message' => 'No bKash transaction found for this order.'
            ) );
        }

        if ( $order->get_payment_method() !== BKASH_DC_PLUGIN_SLUG ) {
            wp_send_json_error( array(
                'message' => 'This is not a bKash Dynamic Charging order.'
            ) );
        }

        if ( $order->get_status() !== 'on-hold' ) {
            wp_send_json_error( array(
                'message' => 'Only on-hold orders can be captured or voided.'
            ) );
        }

        if ( strtolower( $transaction->getIntent() ) !== 'authorization' ) {
            wp_send_json_error( array(
                'message' => 'This payment is not an authorization transaction.'
            ) );
        }

        $bkashApi = new BkashApi();
        $response = $bkashApi->capturePayment(
            $transaction->getPaymentID(),
            $transaction->getMode(),
            $agreement_id ?? ''
        );

        $body = json_decode( $response['response'] ?? '', true );
        if (
            isset( $response['status_code'] ) &&
            $response['status_code'] == 200 &&
            isset( $body['transactionStatus'] ) &&
            $body['transactionStatus'] === 'Completed'
        ) {

            $order->update_status(
                'processing',
                'bKash payment captured successfully.'
            );

            $transaction->update([
                'status' => 'Completed'
            ]);

            wp_send_json_success(array(
                'message' => 'bKash payment captured successfully.',
            ));
        }

        wp_send_json_error(array(
            'message' => 'Capture failed.',
            'response' => $response
        ));
    }

    public function ajax_void() {

        if ( empty( $_POST['order_id'] ) ) {

            wp_send_json_error( array(
                'message' => 'Missing Order ID'
            ) );

        }

        $order_id = absint( $_POST['order_id'] );
        $order = wc_get_order( $order_id );
        $agreement_id = $order->get_meta( '_bkash_agreement_id', true );

        if ( ! $order ) {

            wp_send_json_error( array(
                'message' => 'Order not found'
            ) );

        }

        $transactionModel = new Transaction();
        $transaction = $transactionModel->getPaymentTransactionByOrderId( $order_id );
        if ( ! $transaction ) {
            wp_send_json_error( array(
                'message' => 'No bKash transaction found for this order.'
            ) );
        }

        if ( $order->get_payment_method() !== BKASH_DC_PLUGIN_SLUG ) {
            wp_send_json_error( array(
                'message' => 'This is not a bKash Dynamic Charging order.'
            ) );
        }

        if ( $order->get_status() !== 'on-hold' ) {
            wp_send_json_error( array(
                'message' => 'Only on-hold orders can be captured or voided.'
            ) );
        }

        if ( strtolower( $transaction->getIntent() ) !== 'authorization' ) {
            wp_send_json_error( array(
                'message' => 'This payment is not an authorization transaction.'
            ) );
        }

        $bkashApi = new BkashApi();
        $response = $bkashApi->voidPayment(
            $transaction->getPaymentID(),
            $transaction->getMode(),
            $agreement_id ?? ''
        );

        $body = json_decode( $response['response'] ?? '', true );
        if (
            isset( $response['status_code'] ) &&
            $response['status_code'] == 200 &&
            isset( $body['transactionStatus'] ) &&
            $body['transactionStatus'] === 'Cancelled'
        ) {

            $order->update_status(
                'cancelled',
                'bKash payment voided successfully.'
            );

            $transaction->update([
                'status' => 'Voided'
            ]);

            wp_send_json_success(array(
                'message' => 'bKash payment voided successfully.',
            ));
        }

        wp_send_json_error(array(
            'message' => 'Void failed.',
            'response' => $response
        ));
    }

    public function ajax_query() {

        if ( empty( $_POST['payment_id'] ) ) {
            wp_send_json_error( array(
                'message' => 'Missing Payment ID'
            ) );
        }

        $payment_id = sanitize_text_field( wp_unslash( $_POST['payment_id'] ) );

        $transactionModel = new Transaction();
        $transaction = $transactionModel->getTransactionByPaymentId( $payment_id );
        if ( ! $transaction ) {
            wp_send_json_error( array(
                'message' => 'No bKash transaction found for this payment ID.'
            ) );
        }

        $agreement_id = $transaction->getAgreementID() ?? '';

        $bkashApi = new BkashApi();
        // call query API
        $response = $bkashApi->queryPayment(
            $payment_id,
            $agreement_id
        );

        $body = json_decode( $response['response'] ?? '', true );
        if ( isset( $response['status_code'] ) && $response['status_code'] == 200 && is_array( $body ) ) {

            $transStatus = isset( $body['transactionStatus'] ) ? strtoupper( $body['transactionStatus'] ) : '';
            $verification = isset( $body['verificationStatus'] ) ? strtoupper( $body['verificationStatus'] ) : '';
            $intent = isset( $body['intent'] ) ? strtolower( $body['intent'] ) : '';

            $current_status = $transaction->getStatus();
            $order_id = $transaction->getOrderID();

            // Case 1: Initiated + Verification Incomplete -> Failed
            if ( $transStatus === 'INITIATED' && $verification !== 'COMPLETE' ) {
                $transaction->update( [ 'status' => 'Failed' ] );

                wp_send_json_success( [
                    'message' => 'Transaction marked as Failed (verification incomplete).',
                    'api_response' => $body,
                    'updated_status' => 'Failed',
                ] );
            }

            // Case 2: Completed + Verification Complete
            if ( ($transStatus === 'COMPLETED' || $transStatus === 'AUTHORIZED' ) && $verification === 'COMPLETE' ) {
                if ( in_array( $current_status, [ 'Initiated', 'CALLBACK_REACHED' ], true ) ) {
                    $transaction_id = $execBody['trxID'] ?? $body['trxID'] ?? '';
                    $transaction->update( [ 
                        'trx_id' => $transaction_id,
                        'serviceFee' => $execBody['serviceFee'] ?? $body['serviceFee'] ?? '', 
                        ] );

                    if ( $intent === 'authorization' ) {
                        $transaction->update( [ 'status' => 'Authorized' ] );
                        if ( ! empty( $order_id ) ) {
                            $order = wc_get_order( $order_id );
                            if ( $order ) {
                                $order->update_status( 'on-hold', 'bKash payment completed via query.' );
                            }
                        }

                        wp_send_json_success( [
                            'message' => 'Transaction updated to Authorized.',
                            'api_response' => $body,
                            'updated_status' => 'Authorized',
                        ] );
                    } else {
                        $transaction->update( [ 'status' => 'Completed' ] );
                        if ( ! empty( $order_id ) ) {
                            $order = wc_get_order( $order_id );
                            if ( $order ) {
                                $order->update_status( 'processing', 'bKash payment completed via query.' );
                            }
                        }

                        wp_send_json_success( [
                            'message' => 'Transaction updated to Completed.',
                            'api_response' => $body,
                            'updated_status' => 'Completed',
                        ] );
                    }
                } else {
                    wp_send_json_success( [
                        'message' => 'Transaction already completed.',
                        'api_response' => $body,
                        'updated_status' => $current_status,
                    ] );
                }
            }

            // Case 3: Initiated + Verification Complete -> attempt execute
            if ( $transStatus === 'INITIATED' && $verification === 'COMPLETE' ) {
                if ( in_array( $current_status, [ 'Initiated', 'CALLBACK_REACHED' ], true ) ) {
                    // call execute API
                    $execResp = $bkashApi->executePayment( $payment_id, $agreement_id );
                    $execBody = json_decode( $execResp['response'] ?? '', true );

                    if ( isset( $execResp['status_code'] ) && $execResp['status_code'] == 200 && is_array( $execBody ) && isset( $execBody['transactionStatus'] ) ) {
                        $exec_status = $execBody['transactionStatus'] ?? 'NO_STATUS_EXECUTE';
                        $transaction_id = $execBody['trxID'] ?? $body['trxID'] ?? '';
                        $updated = $transaction->update(
                            array(
                                'status' => $exec_status === 'Authorized' ? 'Authorized' : ( $exec_status === 'Completed' ? 'Completed' : 'Failed' ),
                                'trx_id' => $transaction_id,
                                'serviceFee' => $execBody['serviceFee'] ?? $body['serviceFee'] ?? '',
                            )
                        );

                        if ( $updated && ! empty( $transaction_id ) ) {
                            if ( $exec_status === 'Authorized' ) {
                                $order = wc_get_order( $order_id );
                                if ( $order ) {
                                    $order->update_status( 'on-hold' );
                                    $order->set_transaction_id( $transaction_id );
                                    $order->save();
                                }
                            } elseif ( $exec_status === 'Completed' ) {
                                $order = wc_get_order( $order_id );
                                if ( $order ) {
                                    $order->payment_complete();
                                    $order->set_transaction_id( $transaction_id );
                                    $order->save();
                                }
                            } else {
                                $order = wc_get_order( $order_id );
                                if ( $order ) {
                                    $order->update_status( 'pending' );
                                }
                            }

                            if ( ! empty( $order_id ) ) {
                                $order = wc_get_order( $order_id );
                                if ( $order ) {
                                    $order->add_order_note(
                                        sprintf( 'bKash PGW payment approved (ID: %s)', $transaction_id )
                                    );
                                    wc_reduce_stock_levels( $order_id );
                                }
                            }

                            Log::debug( 'bKash PGW payment approved via query (ID: ' . $transaction_id . ')' );

                            wp_send_json_success( [
                                'message' => 'Execute succeeded; transaction updated and order finalized.',
                                'api_response' => $execBody,
                                'updated_status' => $exec_status === 'Authorized' ? 'Authorized' : ( $exec_status === 'Completed' ? 'Completed' : 'Failed' ),
                            ] );
                        }

                        if ( $updated && isset( $execBody['paymentId'] ) && ! empty( $execBody['paymentId'] ) ) {
                            wp_send_json_error( [
                                'message' => 'Transaction was not successful, last transaction status: ' . $exec_status,
                                'query_response' => $body,
                                'execute_response' => $execBody,
                            ] );
                        }

                        wp_send_json_error( [
                            'message' => 'Execute failed or did not complete.',
                            'query_response' => $body,
                            'execute_response' => $execBody,
                        ] );
                    }

                    // execute failed or did not complete
                    $transaction->update( [ 'status' => 'Failed' ] );
                    wp_send_json_error( [
                        'message' => 'Execute failed or did not complete.',
                        'query_response' => $body,
                        'execute_response' => $execBody ?? $execResp,
                    ] );
                }
            }

            // Default: if none of the above matched, update some fields and return
            $update_data = [ 'status' => $transaction->getStatus() ];
            if ( isset( $body['trxID'] ) && ! empty( $body['trxID'] ) ) {
                $update_data['trx_id'] = sanitize_text_field( $body['trxID'] );
            }
            if ( isset( $body['mode'] ) && ! empty( $body['mode'] ) ) {
                $update_data['mode'] = sanitize_text_field( (string) $body['mode'] );
            }
            if ( isset( $body['serviceFee'] ) ) {
                $update_data['serviceFee'] = sanitize_text_field( (string) $body['serviceFee'] );
            }
            if ( isset( $body['paymentUpdateTime'] ) && ! empty( $body['paymentUpdateTime'] ) ) {
                $update_data['datetime'] = sanitize_text_field( $body['paymentUpdateTime'] );
            }

            $transaction->update( $update_data );

            wp_send_json_success([
                'message' => 'Query processed.',
                'api_response' => $body,
                'updated_status' => $transaction->getStatus(),
            ]);
        }

        wp_send_json_error( array(
            'message' => 'Query failed.',
            'response' => $response
        ) );
    }
}

add_action( 'plugins_loaded', function () {
    new OrderActions();
} );