<?php
/**
 * Agreement Search
 *
 * @category    Page
 * @package     bkash-dynamic-charging
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2023 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */
?>
<style>
	.wocommerce-message.error {
		border-left-color: #e23e3e !important;
	}
</style>
<h1>
<?php
	esc_attr_e( get_admin_page_title(), 'bkash-dynamic-charging' );
?>
</h1>
<br>
<form action="#" method="post">
	<label for="agreement_id" class="form-label">Agreement ID</label>
	<input name="agreement_id" type="text" id="agreement_id" placeholder="Agreement ID" class="form-text-input" value="<?php esc_attr_e( $agreement_id ?? '', 'bkash-dynamic-charging' ); ?>">
	<button class="button button-primary" type="submit">Search</button>
</form>
<br>

<?php
if ( isset( $agreement ) && is_string( $agreement ) ) {
	// ERROR
	?>
	<div id="message" class="bKash-hero-div woocommerce-message bKash-error">
		<p><?php esc_html_e( $agreement, 'bkash-dynamic-charging' ); ?></p>
	</div>
	<?php
} elseif ( isset( $agreement ) && is_array( $agreement ) && ! empty( $agreement['agreementId'] ?? $agreement['agreementID'] ?? '' ) ) {
	$agreementIdVal = $agreement['agreementId'] ?? $agreement['agreementID'] ?? '';
	$status         = $agreement['agreementStatus'] ?? 'Unknown';
	$statusClass    = $status === 'Completed' ? 'bKash-success' : 'bKash-error';
	?>
	<div class="gateway-banner bKash-hero-div <?php echo esc_attr( $statusClass ); ?>">
		<img style="max-width: 90px; margin: 10px 5px" alt="bKash logo agreement search" src="<?php echo esc_url( WooCommerceBkashDC()->pluginUrl() . '/assets/images/logo.png' ); ?>"/>
		<p class="main">
			<strong>Agreement ID: <?php esc_html_e( $agreementIdVal, 'bkash-dynamic-charging' ); ?></strong>
		</p>
		<hr>
		<p>Payer Account: <b><?php esc_html_e( $agreement['customerMsisdn'] ?? '', 'bkash-dynamic-charging' ); ?></b></p>
		<hr>
		<ul>
			<?php if ( ! empty( $agreement['paymentId'] ?? $agreement['paymentID'] ?? '' ) ) { ?>
			<li>
				Payment ID:
				<strong><?php esc_html_e( $agreement['paymentId'] ?? $agreement['paymentID'] ?? '', 'bkash-dynamic-charging' ); ?></strong>
			</li>
			<?php } ?>
			<?php if ( ! empty( $agreement['payerReference'] ) ) { ?>
			<li>
				Payer Reference:
				<strong><?php esc_html_e( $agreement['payerReference'], 'bkash-dynamic-charging' ); ?></strong>
			</li>
			<?php } ?>
			<?php if ( ! empty( $agreement['payerType'] ) ) { ?>
			<li>
				Payer Type:
				<strong><?php esc_html_e( $agreement['payerType'], 'bkash-dynamic-charging' ); ?></strong>
			</li>
			<?php } ?>
			<?php if ( ! empty( $agreement['verificationStatus'] ) ) { ?>
			<li>
				Verification Status:
				<strong><?php esc_html_e( $agreement['verificationStatus'], 'bkash-dynamic-charging' ); ?></strong>
			</li>
			<?php } ?>
			<li>
				Agreement Created At:
				<strong><?php esc_html_e( $agreement['agreementCreationTime'] ?? '', 'bkash-dynamic-charging' ); ?></strong>
			</li>
			<li>
				Agreement Executed At:
				<strong><?php esc_html_e( $agreement['agreementExecuteTime'] ?? '', 'bkash-dynamic-charging' ); ?></strong>
			</li>
		</ul>
		<p>
			<?php
			$btn_class = $status === 'Completed' ? 'button-primary' : 'button';
			?>
			<button class="button button-small <?php esc_attr_e( $btn_class, 'bkash-dynamic-charging' ); ?>">
				Agreement Status - <?php esc_html_e( $status, 'bkash-dynamic-charging' ); ?>
			</button>
		</p>
	</div>
	<?php
}
?>
