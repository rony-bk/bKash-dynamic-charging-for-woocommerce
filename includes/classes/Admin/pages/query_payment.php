<?php

use function bKash\PGW\DC\WooCommerceBkashDC;

?>
<style>
	.wocommerce-message.error {
		border-left-color: #e23e3e !important;
	}
</style>
<h1>
<?php
	esc_attr_e( get_admin_page_title(), 'bkash-woocommerce-dynamic-charging' );
?>
	</h1>
<br>
<form action="#" method="post">
	<?php wp_nonce_field( 'bkash_query_payment', 'bkash_query_payment_nonce' ); ?>
	<label for="payment_id" class="form-label">Payment ID</label>
	<input name="payment_id" type="text" id="payment_id" placeholder="Payment ID" class="form-text-input" autocomplete="off" value="<?php esc_attr_e( $payment_id ?? '', 'bkash-woocommerce-dynamic-charging' ); ?>">

	<button class="button button-primary" type="submit">Search</button>
</form>

<br>

<?php

if ( isset( $trx ) && is_string( $trx ) ) {
	// FAILED TO GET BALANCES
	?>
	<div id="message" class="bKash-hero-div woocommerce-message bKash-error">
		<p>
		<?php
			esc_html_e( $trx ?? '', 'bkash-woocommerce-dynamic-charging' );
		?>
			</p>
	</div>
	<?php
} elseif ( isset( $trx['paymentId'] ) && is_array( $trx ) ) {
	// GOT TRANSACTION
	?>
	<div class="gateway-banner bKash-hero-div bKash-success">
		<img style="max-width: 90px; margin: 10px 5px" alt="bKash logo" src="<?php echo esc_url( \bKash\PGW\DC\WooCommerceBkashDC::getInstance()->pluginUrl() . '/assets/images/logo.png' ); ?>"/>
		<p class="main">
			<strong>Payment ID: 
			<?php
				esc_html_e( $trx['paymentId'] ?? '', 'bkash-woocommerce-dynamic-charging' );
			?>
				</strong>
		</p>
		<hr>
		<p>Sender: <b>
		<?php
				esc_html_e( $trx['customerMsisdn'] ?? '', 'bkash-woocommerce-dynamic-charging' );
		?>
				</b></p>
		<p>
			Amount:
			<b>
				<?php
				esc_html_e(
					( $trx['amount'] ?? '' ) . ' ' . ( $trx['currency'] ?? '' ),
					'bkash-woocommerce-dynamic-charging'
				);
				?>
			</b>
		</p>
		<hr>
		<ul>
			<li>
				Invoice Number:
				<strong>
				<?php
					esc_html_e( $trx['merchantInvoiceNumber'] ?? '', 'bkash-woocommerce-dynamic-charging' );
				?>
					</strong>
			</li>
			<li>
				Intent:
				<strong>
				<?php
					esc_html_e( $trx['intent'] ?? '', 'bkash-woocommerce-dynamic-charging' );
				?>
					</strong>
			</li>
            <?php 
                if( isset($trx['agreementId']) && !empty($trx['agreementId']) ){
            ?>
			<li>
				Agreement Id:
				<strong>
				<?php
					esc_html_e( $trx['agreementId'] ?? '', 'bkash-woocommerce-dynamic-charging' );
				?>
					</strong>
			</li>
            <?php
                }
            ?>
			<li>
				Service Fee:
				<strong>
				<?php
                    esc_html_e(
                        ( $trx['serviceFee'] ?? '' ) . ' ' . ( $trx['currency'] ?? '' ),
                        'bkash-woocommerce-dynamic-charging'
                    );
				?>
					</strong>
			</li>
			<li>
				Initiated At:
				<strong>
				<?php
					esc_html_e( $trx['paymentCreateTime'] ?? '', 'bkash-woocommerce-dynamic-charging' );
				?>
					</strong>
			</li>
		</ul>
		<p>
			<?php
			$btn_class = isset( $trx['transactionStatus'] ) && $trx['transactionStatus'] === 'Completed' ? 'button-primary' : 'button';
			?>
			<button class="button button-small 
			<?php
			esc_attr_e( $btn_class, 'bkash-woocommerce-dynamic-charging' );
			?>
			">
				Transaction Status -
				<?php
				esc_html_e( $trx['transactionStatus'] ?? '', 'bkash-woocommerce-dynamic-charging' );
				?>
			</button>
		</p>
	</div>
	<?php
}
?>
