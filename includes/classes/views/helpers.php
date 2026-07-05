<?php
/**
 * Page Helper
 *
 * @category    Utility
 * @package     bkash-woocommerce-dynamic-charging
 * @author      bKash Developer <developer@bkash.com>
 * @copyright   Copyright 2026 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

if ( ! function_exists( 'bkDcShowLoginAction' ) ) {
	function bkDcShowLoginAction() {
		?>
		<table id="tokenized-login-table" aria-describedby="tokenized login table">
			<tr>
				<th scope="col">Login Required</th>
			</tr>
			<tr>
				<td>Please login to complete the payment</td>
			</tr>
		</table>
		<?php
	}
}

if ( ! function_exists( 'bkDcHasTokenizedWordInIntegrationType' ) ) {
	function bkDcHasTokenizedWordInIntegrationType( $integrationType ): bool {
		return strpos( $integrationType, 'tokenized' ) !== false;
	}
}

if ( ! function_exists( 'bkDcShowAgreementTable' ) ) {
	function bkDcShowAgreementTable( $agreements, $isLoggedIn = false, $showWithoutRemembering = false ) {
		?>
		<table id='dc-payment-fields-table'>
			<?php
			foreach ( $agreements as $i => $agreement ) {
				$agreementToken = $agreement->agreement_token ?? '';
				?>
				<tr>
					<td>
						<label for="<?php esc_attr_e( $agreementToken, 'bkash-woocommerce-dynamic-charging' ); ?>">
							<input
								id="<?php esc_attr_e( $agreementToken, 'bkash-woocommerce-dynamic-charging' ); ?>"
								type="radio"
								name="dc_agreement_id"
								value="<?php esc_attr_e( $agreementToken, 'bkash-woocommerce-dynamic-charging' ); ?>"
								<?php echo $i === 0 ? 'checked' : ''; ?>
							/>
							<?php esc_html_e( $agreement->phone ?? '', 'bkash-woocommerce-dynamic-charging' ); ?>
						</label>
					</td>
					<td>
						<a
							class="dcCancelAgreementButton"
							href="javascript:void(0)"
							data-agreement="<?php esc_attr_e( $agreementToken, 'bkash-woocommerce-dynamic-charging' ); ?>"
						>Remove</a>
					</td>
				</tr>
				<?php
			}

			if ( $isLoggedIn && empty( $agreements ) ) {
				?>
				<tr>
					<td colspan="2">
						<label for="new-agreement">
							<input id="new-agreement" type="radio" name="dc_agreement_id" value="new"/>
							Pay and remember a new bKash account
						</label>
					</td>
				</tr>
				<?php
			}

			if ( $showWithoutRemembering ) {
				?>
				<tr>
					<td colspan="2">
						<label for="non-agreement">
							<input id="non-agreement" type="radio" name="dc_agreement_id" value="no"/>
							Pay without remembering
						</label>
					</td>
				</tr>

				<?php
			}
			?>
		</table>
		<?php
	}
}